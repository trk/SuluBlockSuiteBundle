// @flow

const DEFAULT_TAB_ICONS: {[string]: string} = {
    content: 'su-document',
    settings: 'su-brush',
    style: 'su-paint',
    layout: 'su-columns',
    general: 'su-cog',
    dynamic_content: 'su-flash',
    advanced: 'su-code',
    overview: 'su-document',
    media: 'su-image',
};

class TabIconRegistry {
    icons: {[string]: string};

    constructor() {
        this.clear();
    }

    clear() {
        this.icons = {...DEFAULT_TAB_ICONS};
    }

    add(tabKey: string, icon: string) {
        this.icons[tabKey] = icon;
    }

    addAll(icons: {[string]: string}) {
        Object.keys(icons).forEach((tabKey) => {
            this.icons[tabKey] = icons[tabKey];
        });
    }

    get(tabKey: string, fallback: string = 'su-cog'): string {
        return this.icons[tabKey] || fallback;
    }

    has(tabKey: string): boolean {
        return tabKey in this.icons;
    }
}

export default new TabIconRegistry();
