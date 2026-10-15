// @flow
import React, {Fragment} from 'react';
import {action, computed, isArrayLike, observable, reaction, toJS} from 'mobx';
import {observer} from 'mobx-react';
import equals from 'fast-deep-equal';
import SingleItemSelection from 'sulu-admin-bundle/components/SingleItemSelection';
import {
    MultiAutoComplete,
    MultiSelection,
    ResourceSingleSelect,
    SingleAutoComplete,
    SingleListOverlay,
    SingleSelection,
} from 'sulu-admin-bundle/containers';
import {fieldRegistry} from 'sulu-admin-bundle/containers/Form';
import {MultiSelectionStore, SingleSelectionStore, userStore} from 'sulu-admin-bundle/stores';
import {translate} from 'sulu-admin-bundle/utils';
import SingleMediaSelection from 'sulu-media-bundle/containers/SingleMediaSelection';
import MultiMediaSelection from 'sulu-media-bundle/containers/MultiMediaSelection';
import TeaserSelection from 'sulu-page-bundle/containers/TeaserSelection';
import ContactAccountSelection from 'sulu-contact-bundle/containers/ContactAccountSelection';
import type {IObservableValue} from 'mobx/lib/mobx';

const EMPTY_OPTIONS = {};

type Props = {
    disabled?: boolean,
    fieldType: string,
    iconSet?: string,
    locale?: ?string,
    onChange: (value: any) => void,
    options?: Object,
    value: any,
};

@observer
class SuluNativeField extends React.Component<Props> {
    @observable iconOverlayOpen: boolean = false;
    localeBox: IObservableValue<string>;
    singleAutoCompleteStore: ?SingleSelectionStore<string | number>;
    multiAutoCompleteStore: ?MultiSelectionStore<string | number>;
    disposer: ?() => *;

    constructor(props: Props) {
        super(props);

        const initialLocale = props.locale || userStore.contentLocale || 'en';
        this.localeBox = observable.box(initialLocale);
        this.setupStores();
    }

    componentDidUpdate(prevProps: Props) {
        const nextLocale = this.props.locale || userStore.contentLocale || 'en';
        if (this.localeBox.get() !== nextLocale) {
            action(() => {
                this.localeBox.set(nextLocale);
            })();
        }

        if (prevProps.fieldType !== this.props.fieldType) {
            this.cleanupStores();
            this.setupStores();
            return;
        }

        if (this.singleAutoCompleteStore) {
            const loadedId = this.singleAutoCompleteStore.item ? this.singleAutoCompleteStore.item.id : undefined;
            if (loadedId !== toJS(this.props.value)) {
                this.singleAutoCompleteStore.loadItem(this.props.value);
            }
        }

        if (this.multiAutoCompleteStore) {
            const idProp = this.multiAutoCompleteIdProperty;
            const currentVal = isArrayLike(this.props.value) ? Array.from(toJS(this.props.value)) : [];
            const loadedVal = this.multiAutoCompleteStore.items.map((item) => item[idProp]);
            if (!equals(currentVal, loadedVal)) {
                this.multiAutoCompleteStore.loadItems(currentVal);
            }
        }
    }

    componentWillUnmount() {
        this.cleanupStores();
    }

    cleanupStores() {
        if (this.disposer) {
            this.disposer();
            this.disposer = null;
        }
        this.singleAutoCompleteStore = null;
        this.multiAutoCompleteStore = null;
    }

    @computed get fieldOptions(): ?Object {
        const {fieldType} = this.props;
        if (fieldRegistry.has(fieldType)) {
            try {
                return fieldRegistry.getOptions(fieldType);
            } catch (e) {
                return null;
            }
        }
        return null;
    }

    @computed get isSingleFieldType(): boolean {
        const {fieldType} = this.props;
        if (fieldType.startsWith('single_')) {
            return true;
        }
        const opts = this.fieldOptions;
        if (opts && opts.types) {
            if (opts.types.single_select) {
                return true;
            }
            if (opts.types.list_overlay && opts.types.list_overlay.empty_text && !opts.types.list_overlay.label) {
                return true;
            }
        }
        return false;
    }

    @computed get multiAutoCompleteIdProperty(): string {
        const opts = this.fieldOptions;
        if (opts && opts.types && opts.types.auto_complete && opts.types.auto_complete.id_property) {
            return opts.types.auto_complete.id_property;
        }
        return 'id';
    }

    setupStores() {
        const { value} = this.props;
        const opts = this.fieldOptions;
        if (!opts || !opts.resource_key) {
            return;
        }

        const defaultType = opts.default_type;
        if (defaultType !== 'auto_complete' || !opts.types || !opts.types.auto_complete) {
            return;
        }

        if (this.isSingleFieldType) {
            this.singleAutoCompleteStore = new SingleSelectionStore(
                opts.resource_key,
                value || undefined,
                this.localeBox
            );
            this.disposer = reaction(
                () => (this.singleAutoCompleteStore ? this.singleAutoCompleteStore.item : undefined),
                (selectedItem) => {
                    if (!this.singleAutoCompleteStore || this.singleAutoCompleteStore.loading) {
                        return;
                    }
                    const nextId = selectedItem ? selectedItem.id : undefined;
                    if (this.props.value !== nextId) {
                        this.props.onChange(nextId);
                    }
                }
            );
        } else {
            const autoCompleteOpts = opts.types.auto_complete;
            const idProp = autoCompleteOpts.id_property || 'id';
            const filterParam = autoCompleteOpts.filter_parameter || 'ids';
            const initialIds = isArrayLike(value)
                ? Array.from(toJS(value))
                : (typeof value === 'string' && value.trim() !== ''
                    ? value.split(',').map((s) => s.trim()).filter(Boolean)
                    : []);

            this.multiAutoCompleteStore = new MultiSelectionStore(
                opts.resource_key,
                initialIds,
                this.localeBox,
                filterParam
            );
            this.disposer = reaction(
                () => (this.multiAutoCompleteStore
                    ? this.multiAutoCompleteStore.items.map((item) => item[idProp])
                    : []),
                (selectedIds) => {
                    if (!this.multiAutoCompleteStore || this.multiAutoCompleteStore.loading) {
                        return;
                    }
                    const currentVal = isArrayLike(this.props.value) ? Array.from(toJS(this.props.value)) : [];
                    if (!equals(currentVal, toJS(selectedIds))) {
                        this.props.onChange(selectedIds);
                    }
                }
            );
        }
    }

    @action handleOpenIconOverlay = () => {
        this.iconOverlayOpen = true;
    };

    @action handleCloseIconOverlay = () => {
        this.iconOverlayOpen = false;
    };

    @action handleConfirmIconOverlay = (selected: {id: string}) => {
        this.props.onChange(selected.id);
        this.iconOverlayOpen = false;
    };

    handleRemoveIcon = () => {
        this.props.onChange(undefined);
    };

    render() {
        const {disabled = false, fieldType, iconSet = 'default', onChange, options = EMPTY_OPTIONS, value} = this.props;

        if (fieldType === 'single_media_selection') {
            const mediaValue = (value && typeof value === 'object' && 'id' in value)
                ? toJS(value)
                : {id: typeof value === 'number' || (typeof value === 'string' && /^\d+$/.test(value)) ? Number(value) : undefined};

            return (
                <SingleMediaSelection
                    disabled={disabled}
                    locale={this.localeBox}
                    onChange={(nextVal) => onChange(nextVal && nextVal.id ? nextVal.id : null)}
                    value={mediaValue}
                />
            );
        }

        if (fieldType === 'media_selection') {
            const ids = isArrayLike(value)
                ? Array.from(toJS(value))
                : (value && typeof value === 'object' && isArrayLike(value.ids) ? Array.from(toJS(value.ids)) : []);

            return (
                <MultiMediaSelection
                    disabled={disabled}
                    locale={this.localeBox}
                    onChange={(nextVal) => onChange(nextVal && isArrayLike(nextVal.ids) ? Array.from(nextVal.ids) : [])}
                    value={{ids}}
                />
            );
        }

        if (fieldType === 'teaser_selection') {
            const teaserVal = (value && typeof value === 'object' && isArrayLike(value.items))
                ? toJS(value)
                : {presentAs: undefined, items: []};

            return (
                <TeaserSelection
                    disabled={disabled}
                    locale={this.localeBox}
                    onChange={onChange}
                    value={teaserVal}
                />
            );
        }

        if (fieldType === 'contact_account_selection') {
            const contactAccountVal = isArrayLike(value) ? Array.from(toJS(value)) : [];

            return (
                <ContactAccountSelection
                    disabled={disabled}
                    onChange={onChange}
                    value={contactAccountVal}
                />
            );
        }

        if (fieldType === 'single_icon_selection') {
            const currentIcon = typeof value === 'string' && value !== '' ? value : undefined;

            return (
                <Fragment>
                    <SingleItemSelection
                        disabled={disabled}
                        emptyText={translate('sulu_admin.single_icon_selection.select') || 'Select icon'}
                        id={currentIcon}
                        leftButton={{
                            icon: 'su-magic',
                            onClick: this.handleOpenIconOverlay,
                        }}
                        onItemClick={this.handleOpenIconOverlay}
                        onRemove={currentIcon ? this.handleRemoveIcon : undefined}
                        value={currentIcon}
                    >
                        {currentIcon && <div>{currentIcon}</div>}
                    </SingleItemSelection>
                    <SingleListOverlay
                        adapter="icon"
                        listKey="icons"
                        locale={this.localeBox}
                        onClose={this.handleCloseIconOverlay}
                        onConfirm={this.handleConfirmIconOverlay}
                        open={this.iconOverlayOpen}
                        options={{icon_set: iconSet}}
                        preSelectedItem={{id: currentIcon}}
                        resourceKey="icons"
                        title={translate('sulu_admin.single_icon_selection.select') || 'Select Icon'}
                    />
                </Fragment>
            );
        }

        const opts = this.fieldOptions;
        if (!opts || !opts.resource_key) {
            return null;
        }

        const resourceKey = opts.resource_key;
        const isSingle = this.isSingleFieldType;
        const types = opts.types || {};

        if (isSingle) {
            if (opts.default_type === 'auto_complete' && types.auto_complete && this.singleAutoCompleteStore) {
                const searchProperties = isArrayLike(types.auto_complete.search_properties)
                    ? Array.from(types.auto_complete.search_properties)
                    : [];

                return (
                    <SingleAutoComplete
                        disabled={disabled}
                        displayProperty={types.auto_complete.display_property}
                        options={options}
                        searchProperties={searchProperties}
                        selectionStore={this.singleAutoCompleteStore}
                    />
                );
            }

            if (opts.default_type === 'single_select' && types.single_select) {
                return (
                    <ResourceSingleSelect
                        disabled={disabled}
                        displayProperty={types.single_select.display_property}
                        editable={false}
                        idProperty={types.single_select.id_property || 'id'}
                        onChange={onChange}
                        overlayTitle={translate(types.single_select.overlay_title || '')}
                        resourceKey={resourceKey}
                        value={value || undefined}
                    />
                );
            }

            const overlayOpts = types.list_overlay;
            if (overlayOpts) {
                let normalizedSingleVal = value !== '' && value !== null && value !== undefined ? toJS(value) : undefined;
                if (typeof normalizedSingleVal === 'string' && /^\d+$/.test(normalizedSingleVal)) {
                    normalizedSingleVal = Number(normalizedSingleVal);
                }
                const displayProperties = isArrayLike(overlayOpts.display_properties)
                    ? Array.from(overlayOpts.display_properties)
                    : ['title'];
                const detailOptions = overlayOpts.detail_options && typeof overlayOpts.detail_options === 'object'
                    ? {...toJS(overlayOpts.detail_options)}
                    : EMPTY_OPTIONS;

                return (
                    <SingleSelection
                        adapter={overlayOpts.adapter}
                        detailOptions={detailOptions}
                        disabled={disabled}
                        displayProperties={displayProperties}
                        emptyText={translate(overlayOpts.empty_text || '')}
                        icon={overlayOpts.icon || 'su-plus'}
                        listKey={overlayOpts.list_key || resourceKey}
                        listOptions={options}
                        locale={this.localeBox}
                        onChange={(selectedId) => onChange(selectedId)}
                        overlayTitle={translate(overlayOpts.overlay_title || '')}
                        resourceKey={resourceKey}
                        value={normalizedSingleVal}
                    />
                );
            }
        } else {
            if (opts.default_type === 'auto_complete' && types.auto_complete && this.multiAutoCompleteStore) {
                const searchProperties = isArrayLike(types.auto_complete.search_properties)
                    ? Array.from(types.auto_complete.search_properties)
                    : [];

                return (
                    <MultiAutoComplete
                        allowAdd={Boolean(types.auto_complete.allow_add)}
                        disabled={disabled}
                        displayProperty={types.auto_complete.display_property}
                        idProperty={types.auto_complete.id_property || 'id'}
                        options={options}
                        searchProperties={searchProperties}
                        selectionStore={this.multiAutoCompleteStore}
                    />
                );
            }

            const overlayOpts = types.list_overlay || types.list;
            if (overlayOpts) {
                const rawArray = isArrayLike(value)
                    ? Array.from(toJS(value))
                    : (typeof value === 'string' && value.trim() !== ''
                        ? value.split(',').map((s) => s.trim()).filter(Boolean)
                        : []);
                const normalizedArray = rawArray.map((item) =>
                    typeof item === 'string' && /^\d+$/.test(item) ? Number(item) : item
                );
                const displayProperties = isArrayLike(overlayOpts.display_properties)
                    ? Array.from(overlayOpts.display_properties)
                    : ['title', 'name'];

                return (
                    <MultiSelection
                        adapter={overlayOpts.adapter}
                        disabled={disabled}
                        displayProperties={displayProperties}
                        icon={overlayOpts.icon || 'su-plus'}
                        label={translate(overlayOpts.label || '', {count: normalizedArray.length})}
                        listKey={overlayOpts.list_key || resourceKey}
                        locale={this.localeBox}
                        onChange={onChange}
                        options={options}
                        overlayTitle={translate(overlayOpts.overlay_title || '')}
                        resourceKey={resourceKey}
                        sortable={true}
                        value={normalizedArray}
                    />
                );
            }
        }

        return null;
    }
}

export default SuluNativeField;
