// @flow
import {action, observable, runInAction} from 'mobx';
import {Requester} from 'sulu-admin-bundle/services';
import userStore from 'sulu-admin-bundle/stores/userStore';

class ReusableBlockStore {
    @observable cache: Map<string, Object> = new Map();
    loadingPromises: Map<string, Promise<*>> = new Map();

    get currentLocale(): string {
        const rawLoc = userStore.contentLocale || (userStore.user ? userStore.user.locale : null) || 'en';
        return String(rawLoc).toLowerCase().split('_')[0];
    }

    getCacheKey(id: string, locale?: string): string {
        const loc = locale || this.currentLocale;
        return `${id}_${loc}`;
    }

    get(id: string, locale?: string): ?Object {
        return this.cache.get(this.getCacheKey(id, locale));
    }

    set(id: string, data: Object, locale?: string) {
        const key = this.getCacheKey(id, locale);
        action(() => {
            this.cache.set(key, data);
        })();
    }

    load(id: string, locale?: string): Promise<?Object> {
        if (!id) {
            return Promise.resolve(null);
        }

        const loc = locale || this.currentLocale;
        const key = this.getCacheKey(id, loc);

        if (this.cache.has(key)) {
            return Promise.resolve(this.cache.get(key));
        }

        if (this.loadingPromises.has(key)) {
            return this.loadingPromises.get(key);
        }

        const promise = Requester.get(`/admin/api/reusable-blocks/${id}?locale=${loc}`)
            .then((data) => runInAction(() => {
                this.loadingPromises.delete(key);
                if (data && !data.error) {
                    this.cache.set(key, data);
                    return data;
                }
                const notFound = {id, notFound: true};
                this.cache.set(key, notFound);
                return notFound;
            }))
            .catch(() => runInAction(() => {
                this.loadingPromises.delete(key);
                const notFound = {id, notFound: true};
                this.cache.set(key, notFound);
                return notFound;
            }));

        this.loadingPromises.set(key, promise);
        return promise;
    }
}

export default new ReusableBlockStore();
