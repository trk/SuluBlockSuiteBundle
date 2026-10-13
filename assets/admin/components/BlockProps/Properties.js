// @flow
import React from 'react';
import {action, observable, toJS} from 'mobx';
import {observer} from 'mobx-react';
import {
    Button,
    Dialog,
    Form,
    Loader,
    Tabs,
} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import {Requester} from 'sulu-admin-bundle/services';
import FieldRenderer from './FieldRenderer';
import DynamicContent from './DynamicContent';
import blockPropsStyles from './blockProps.scss';

type Props = {
    componentType: string,
    locale: string,
    onCancel: () => void,
    onConfirm: (values: Object) => void,
    open: boolean,
    translations?: Object,
    value: ?Object,
};

@observer
class Properties extends React.Component<Props> {
    static schemaCache: Map<string, Object> = new Map();
    static schemaPromises: Map<string, Promise<any>> = new Map();

    @observable.ref schema: ?Object = null;
    @observable schemaLoading: boolean = false;
    @observable activeTabIndex: number = 0;
    @observable draftValues: Object = {};

    constructor(props: Props) {
        super(props);

        const {componentType, locale} = props;
        if (componentType && componentType !== 'general') {
            const normalizedType = String(componentType).toLowerCase();
            const normalizedLocale = String(locale || 'en').toLowerCase().split('_')[0];
            const cacheKey = `${normalizedType}_${normalizedLocale}`;
            if (Properties.schemaCache.has(cacheKey)) {
                this.schema = Properties.schemaCache.get(cacheKey);
            }
        }
    }

    componentDidMount() {
        if (this.props.componentType && this.props.componentType !== 'general') {
            this.loadSchema();
        }
        if (this.props.open) {
            this.initDraftValues();
        }
    }

    componentDidUpdate(prevProps: Props) {
        const {componentType, locale, open} = this.props;

        if (open && !prevProps.open) {
            this.initDraftValues();
            if (!this.schema) {
                this.loadSchema();
            }
        } else if (componentType !== prevProps.componentType || locale !== prevProps.locale) {
            this.loadSchema(true);
        }
    }

    @action initDraftValues() {
        this.draftValues = toJS(this.props.value || {});
        this.activeTabIndex = 0;
    }

    get tabs(): Array<Object> {
        const {schema} = this;
        const list = (!schema || !schema.tabs)
            ? []
            : (Array.isArray(schema.tabs) ? [...schema.tabs] : Array.from(schema.tabs));

        list.push({
            id: 'dynamic_content',
            title: 'sulu_block_suite.dynamic_content',
            isDynamicTab: true,
        });

        return list;
    }

    @action loadSchema = (force: boolean = false) => {
        const {componentType, locale} = this.props;

        if (!componentType || componentType === 'general') {
            this.schema = null;
            this.schemaLoading = false;
            return;
        }

        const normalizedType = String(componentType).toLowerCase();
        const normalizedLocale = String(locale || 'en').toLowerCase().split('_')[0];
        const cacheKey = `${normalizedType}_${normalizedLocale}`;
        const url = `/admin/api/block-props-schema/${normalizedType}?locale=${normalizedLocale}`;

        if (!force && Properties.schemaCache.has(cacheKey)) {
            const cached = Properties.schemaCache.get(cacheKey);
            const cachedTabs = cached && cached.tabs ? (Array.isArray(cached.tabs) ? cached.tabs : Array.from(cached.tabs)) : [];
            if (cachedTabs.length > 0) {
                this.schema = cached;
                this.schemaLoading = false;
                return;
            }
        }

        if (Properties.schemaPromises.has(cacheKey)) {
            this.schemaLoading = true;
            const promise = Properties.schemaPromises.get(cacheKey);
            if (promise) {
                promise.then(action((data) => {
                    if (String(this.props.componentType).toLowerCase() === normalizedType) {
                        this.schema = data;
                        this.schemaLoading = false;
                    }
                })).catch(action(() => {
                    if (String(this.props.componentType).toLowerCase() === normalizedType) {
                        this.schemaLoading = false;
                    }
                }));
            }
            return;
        }

        this.schemaLoading = true;

        const fetchPromise = Requester.get(url)
            .then((data) => {
                const tabs = data && data.tabs ? (Array.isArray(data.tabs) ? data.tabs : Array.from(data.tabs)) : [];
                const schemaData = tabs.length > 0 ? data : null;
                if (schemaData) {
                    Properties.schemaCache.set(cacheKey, schemaData);
                }
                Properties.schemaPromises.delete(cacheKey);
                return schemaData || {tabs: []};
            })
            .catch(() => {
                return fetch(url, {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                })
                    .then((res) => (res.ok ? res.json() : null))
                    .then((data) => {
                        const tabs = data && data.tabs ? (Array.isArray(data.tabs) ? data.tabs : Array.from(data.tabs)) : [];
                        const schemaData = tabs.length > 0 ? data : null;
                        if (schemaData) {
                            Properties.schemaCache.set(cacheKey, schemaData);
                        }
                        Properties.schemaPromises.delete(cacheKey);
                        return schemaData || {tabs: []};
                    })
                    .catch(() => {
                        Properties.schemaPromises.delete(cacheKey);
                        return {tabs: []};
                    });
            });

        Properties.schemaPromises.set(cacheKey, fetchPromise);

        fetchPromise.then(action((data) => {
            if (String(this.props.componentType).toLowerCase() === normalizedType) {
                this.schema = data;
                this.schemaLoading = false;
            }
        })).catch(action(() => {
            if (String(this.props.componentType).toLowerCase() === normalizedType) {
                this.schemaLoading = false;
            }
        }));
    };

    @action handleSelectTab = (tabIndex: number) => {
        this.activeTabIndex = tabIndex;
    };

    @action handleDynamicChange = (dynamicConfig: ?Object) => {
        const next = {...toJS(this.draftValues)};
        if (!dynamicConfig) {
            delete next._dynamic;
        } else {
            next._dynamic = dynamicConfig;
        }
        this.draftValues = next;
    };

    @action handleFieldChange = (name: string, value: any) => {
        const next = {...toJS(this.draftValues)};
        if (value === undefined || value === null || value === '') {
            delete next[name];
        } else {
            next[name] = value;
        }
        this.draftValues = next;
    };

    @action handleReset = () => {
        const currentPreset = this.draftValues._preset;
        this.draftValues = currentPreset ? {_preset: currentPreset} : {};
    };

    handleCancel = () => {
        this.props.onCancel();
    };

    handleConfirm = () => {
        const cleaned: Object = {};
        const raw = toJS(this.draftValues);

        Object.keys(raw).forEach((key) => {
            const val = raw[key];
            if (val !== undefined && val !== null && val !== '') {
                cleaned[key] = val;
            }
        });

        this.props.onConfirm(cleaned);
    };

    renderTabs() {
        const tabs = this.tabs;
        if (tabs.length <= 1) {
            return null;
        }

        return (
            <div className={blockPropsStyles.tabsWrapper}>
                <Tabs onSelect={this.handleSelectTab} selectedIndex={this.activeTabIndex} type="inline">
                    {tabs.map((tab) => (
                        <Tabs.Tab key={tab.id}>{translate(tab.title) || tab.title}</Tabs.Tab>
                    ))}
                </Tabs>
            </div>
        );
    }

    renderActiveTabFields() {
        const tabs = this.tabs;
        const defaultOptionLabel = translate('sulu_block_suite.opt_default') || 'Default';

        if (tabs.length === 0) {
            return (
                <div className={blockPropsStyles.emptyNotice}>
                    {translate('sulu_block_suite.no_props_available') || 'No configurable properties available for this block.'}
                </div>
            );
        }

        const activeTab = tabs[this.activeTabIndex] || tabs[0];
        if (activeTab && activeTab.isDynamicTab) {
            return (
                <DynamicContent
                    componentType={this.props.componentType}
                    locale={this.props.locale}
                    onChange={this.handleDynamicChange}
                    value={this.draftValues._dynamic}
                />
            );
        }
        const fields = activeTab && activeTab.fields
            ? (Array.isArray(activeTab.fields) ? activeTab.fields : Array.from(activeTab.fields))
            : [];

        if (fields.length === 0) {
            return (
                <div className={blockPropsStyles.emptyNotice}>
                    {translate('sulu_block_suite.no_fields_in_tab') || 'No properties found in this tab.'}
                </div>
            );
        }

        return (
            <Form>
                {fields.map((field) => (
                    <FieldRenderer
                        defaultOptionLabel={defaultOptionLabel}
                        field={field}
                        key={field.name}
                        onChange={this.handleFieldChange}
                        value={this.draftValues[field.name]}
                    />
                ))}
            </Form>
        );
    }

    render() {
        const {open} = this.props;
        if (!open) {
            return null;
        }

        const title = translate('sulu_block_suite.configure_props') || 'Configure Properties';
        const cancelButton = translate('sulu_admin.cancel') || 'Cancel';
        const applyButton = translate('sulu_admin.apply') || 'Apply';
        const resetButton = translate('sulu_admin.reset') || 'Reset';

        const isSupported = Boolean(
            this.props.componentType &&
            this.props.componentType !== 'general'
        );
        const isLoading = this.schemaLoading || (!this.schema && isSupported);

        const hasValues = Object.keys(this.draftValues).some(
            (k) => k !== '_preset' && this.draftValues[k] !== undefined && this.draftValues[k] !== null && this.draftValues[k] !== ''
        );

        return (
            <Dialog
                align="left"
                cancelText={cancelButton}
                confirmText={applyButton}
                onCancel={this.handleCancel}
                onConfirm={this.handleConfirm}
                open={open}
                size="small"
                title={title}
            >
                <div className={blockPropsStyles.propsDialogContent}>
                    {isLoading && (
                        <div className={blockPropsStyles.loadingContainer}>
                            <Loader />
                        </div>
                    )}
                    {!isLoading && this.renderTabs()}
                    {!isLoading && this.renderActiveTabFields()}

                    {hasValues && (
                        <div className={blockPropsStyles.modalFooterActions}>
                            <Button icon="su-trash-alt" onClick={this.handleReset} skin="link">
                                {resetButton}
                            </Button>
                        </div>
                    )}
                </div>
            </Dialog>
        );
    }
}

export default Properties;
