// @flow
import {action, observable} from 'mobx';
import {Requester} from 'sulu-admin-bundle/services';
import userStore from 'sulu-admin-bundle/stores/userStore';

class ReusableBlockStore {
    @observable cache: Map<string, Object> = new Map();
    loadingPromises: Map<string, Promise<*>> = new Map();

    get currentLocale(): string {
        const rawLoc = userStore.contentLocale || (userStore.user ? userStore.user.locale : null) || 'en';
        return String(rawLoc).toLowerCase().split('_')[0];
    }

    get(id: string): ?Object {
        return this.cache.get(id);
    }

    set(id: string, data: Object) {
        action(() => {
            this.cache.set(id, data);
        })();
    }

    load(id: string, locale?: string): Promise<?Object> {
        if (!id) {
            return Promise.resolve(null);
        }

        if (this.cache.has(id)) {
            return Promise.resolve(this.cache.get(id));
        }

        if (this.loadingPromises.has(id)) {
            return this.loadingPromises.get(id);
        }

        const loc = locale || this.currentLocale;
        const promise = Requester.get(`/admin/api/reusable-blocks/${id}?locale=${loc}`)
            .then(action((data) => {
                this.loadingPromises.delete(id);
                if (data && !data.error) {
                    this.cache.set(id, data);
                    return data;
                }
                return null;
            }))
            .catch(action(() => {
                this.loadingPromises.delete(id);
                return null;
            }));

        this.loadingPromises.set(id, promise);
        return promise;
    }
}

export default new ReusableBlockStore();
