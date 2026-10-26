// @flow
import React from 'react';
import {action, computed, isArrayLike, observable, toJS} from 'mobx';
import {observer} from 'mobx-react';
import classNames from 'classnames';
import jsonpointer from 'json-pointer';
import ArrowMenu from 'sulu-admin-bundle/components/ArrowMenu';
import Icon from 'sulu-admin-bundle/components/Icon';
import buttonStyles from 'sulu-admin-bundle/components/SingleItemSelection/button.scss';
import styles from './blockFieldOptions.scss';
import type {ComponentType} from 'react';

type OptionItem = {|
    label: string,
    value: string,
|};

type OptionGroupConfig = {|
    defaultValue: ?string,
    icon: string,
    items: Array<OptionItem>,
    key: string,
    storageKey: string,
    title: string,
|};

const RESERVED_SCHEMA_OPTION_KEYS: {[string]: boolean} = {
    attributes: true,
    auto_default: true,
    default_snippets: true,
    default_values: true,
    displayOptions: true,
    exclude: true,
    excluded_templates: true,
    excluded_types: true,
    group: true,
    groups: true,
    include_global: true,
    permission_filtered: true,
    properties: true,
    request_parameters: true,
    resource_key: true,
    resource_store_properties_to_request: true,
    snippets: true,
    template_type: true,
    types: true,
    values: true,
};

const META_CHILD_KEYS: {[string]: boolean} = {
    _default: true,
    _icon: true,
    _storage_key: true,
    _target: true,
    _title: true,
    default: true,
    default_value: true,
    icon: true,
    target: true,
};

const EXCLUDED_FIELD_TYPES: {[string]: boolean} = {
    block: true,
    hotspot_picker: true,
    preset_selection: true,
    single_reusable_block_selection: true,
};

function humanizeKey(rawKey: string): string {
    return rawKey
        .replace(/^props\//, '')
        .replace(/[_-]+/g, ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

function resolveIconName(rawIcon: ?string, groupKey: string): string {
    const trimmed = typeof rawIcon === 'string' ? rawIcon.trim() : '';
    if (trimmed !== '') {
        if (
            trimmed.startsWith('su-')
            || trimmed.startsWith('fa-')
            || trimmed.startsWith('fas ')
            || trimmed.startsWith('fab ')
        ) {
            return trimmed;
        }

        if (trimmed === 'brush' || trimmed === 'color' || trimmed === 'palette') {
            return 'su-brush';
        }
        if (trimmed === 'size' || trimmed === 'expand') {
            return 'su-expand';
        }
        if (trimmed === 'style' || trimmed === 'variant' || trimmed === 'magic') {
            return 'su-magic';
        }
        if (trimmed === 'align' || trimmed === 'position') {
            return 'su-align-left';
        }
        if (trimmed === 'ratio' || trimmed === 'aspect' || trimmed === 'crop') {
            return 'su-crop-pen';
        }
        if (trimmed === 'fit' || trimmed === 'object_fit' || trimmed === 'compress') {
            return 'su-display-default';
        }
        if (trimmed === 'heading' || trimmed === 'header' || trimmed === 'tag') {
            return 'fa-heading';
        }
        if (trimmed === 'cog' || trimmed === 'settings') {
            return 'su-cog';
        }

        return `fa-${trimmed}`;
    }

    const lower = groupKey.toLowerCase();
    if (lower.includes('element') || lower.includes('tag') || lower.includes('heading')) {
        return 'fa-heading';
    }
    if (lower.includes('color')) {
        return 'su-brush';
    }
    if (lower.includes('size')) {
        return 'su-expand';
    }
    if (lower.includes('style') || lower.includes('variant')) {
        return 'su-magic';
    }
    if (lower.includes('align') || lower.includes('position')) {
        return 'su-align-left';
    }
    if (lower.includes('ratio') || lower.includes('aspect') || lower.includes('crop')) {
        return 'su-crop-pen';
    }
    if (lower.includes('fit')) {
        return 'su-display-default';
    }

    return 'su-cog';
}

function setPointerSafe(rootObj: Object, pointer: string, value: mixed) {
    const tokens = jsonpointer.parse(pointer);
    if (tokens.length === 0) {
        return;
    }

    let curr = rootObj;
    for (let i = 0; i < tokens.length - 1; i++) {
        const token = tokens[i];
        const nextToken = tokens[i + 1];
        const nextIsNumeric = /^\d+$/.test(nextToken);
        let child = curr[token];
        if (child === undefined || child === null || typeof child !== 'object') {
            child = nextIsNumeric ? [] : {};
            curr[token] = child;
        } else if (!nextIsNumeric && (Array.isArray(child) || isArrayLike(child))) {
            const plainObj = {};
            Object.keys(child).forEach((k) => {
                if (!/^\d+$/.test(k)) {
                    plainObj[k] = child[k];
                }
            });
            curr[token] = plainObj;
            child = plainObj;
        }
        curr = child;
    }

    const lastToken = tokens[tokens.length - 1];
    if (value === undefined) {
        delete curr[lastToken];
    } else {
        curr[lastToken] = value;
    }
}

function applyPathChangeToStore(
    formInspector: ?Object,
    targetPath: string,
    nextValue: mixed
) {
    const formStore = formInspector ? formInspector.formStore : null;
    if (!formStore) {
        return;
    }

    const cleanPath = targetPath.replace(/^\/+/, '');
    const segments = cleanPath.split('/').filter(Boolean);

    if (segments.length > 1 && typeof formStore.change === 'function') {
        const rootKey = segments[0];
        const subPointer = '/' + segments.slice(1).join('/');
        let currentRoot;
        try {
            currentRoot = typeof formInspector?.getValueByPath === 'function'
                ? formInspector.getValueByPath('/' + rootKey)
                : (formStore.data ? formStore.data[rootKey] : undefined);
        } catch (e) {
            currentRoot = formStore.data ? formStore.data[rootKey] : undefined;
        }

        if (currentRoot !== undefined && currentRoot !== null && typeof currentRoot === 'object') {
            const clonedRoot = toJS(currentRoot);
            setPointerSafe(clonedRoot, subPointer, nextValue);
            formStore.change(rootKey, clonedRoot);
            return;
        }
    }

    if (typeof formStore.change === 'function') {
        formStore.change(targetPath, nextValue);
    } else if (typeof formStore.changeMultiple === 'function') {
        formStore.changeMultiple({
            [targetPath]: nextValue,
        });
    }
}

function extractOptionGroups(schemaOptions: ?Object): Array<OptionGroupConfig> {
    if (!schemaOptions || typeof schemaOptions !== 'object') {
        return [];
    }

    const groups: Array<OptionGroupConfig> = [];

    Object.keys(schemaOptions).forEach((optionKey) => {
        if (RESERVED_SCHEMA_OPTION_KEYS[optionKey] || optionKey.startsWith('_')) {
            return;
        }

        const entry = schemaOptions[optionKey];
        if (!entry || typeof entry !== 'object' || !isArrayLike(entry.value) || entry.value.length === 0) {
            return;
        }

        const valueList: Array<any> = Array.from(entry.value);

        let targetName = optionKey;
        let storageKey: ?string = null;
        let defaultValue: ?string = null;
        let rawIcon: ?string = null;
        let customTitle: ?string = typeof entry.title === 'string' && entry.title.trim() !== ''
            ? entry.title.trim()
            : null;
        let hasMetaMarker = false;
        const items: Array<OptionItem> = [];

        valueList.forEach((child) => {
            if (!child || typeof child !== 'object') {
                return;
            }

            const childName = typeof child.name === 'string'
                ? child.name.trim()
                : (typeof child.name === 'number' ? String(child.name) : '');

            if (META_CHILD_KEYS[childName]) {
                hasMetaMarker = true;
                if ((childName === '_target' || childName === 'target') && typeof child.value === 'string' && child.value.trim() !== '') {
                    targetName = child.value.trim();
                } else if (childName === '_storage_key' && typeof child.value === 'string' && child.value.trim() !== '') {
                    storageKey = child.value.trim();
                } else if (
                    (childName === '_default' || childName === 'default_value' || childName === 'default')
                    && child.value !== undefined
                    && child.value !== null
                ) {
                    defaultValue = String(child.value);
                } else if ((childName === '_icon' || childName === 'icon') && typeof child.value === 'string' && child.value.trim() !== '') {
                    rawIcon = child.value.trim();
                } else if (childName === '_title' && typeof child.value === 'string' && child.value.trim() !== '') {
                    customTitle = child.value.trim();
                }

                return;
            }

            const itemValue = childName !== ''
                ? childName
                : (child.value !== undefined && child.value !== null ? String(child.value) : '');
            if (itemValue === '') {
                return;
            }

            const itemLabel = typeof child.title === 'string' && child.title.trim() !== ''
                ? child.title.trim()
                : (typeof child.value === 'string' && child.value.trim() !== ''
                    ? child.value.trim()
                    : String(itemValue));

            items.push({
                label: itemLabel,
                value: String(itemValue),
            });
        });

        if (items.length === 0) {
            return;
        }

        const allBooleanFlags = !hasMetaMarker && valueList.every(
            (child) => child && (
                typeof child.value === 'boolean'
                || child.value === 'true'
                || child.value === 'false'
            )
        );
        if (allBooleanFlags) {
            return;
        }

        const resolvedDefault = defaultValue !== null && defaultValue !== undefined
            ? String(defaultValue)
            : String(items[0].value);
        const resolvedStorageKey = storageKey || `props/${targetName}`;

        groups.push({
            defaultValue: resolvedDefault,
            icon: resolveIconName(rawIcon, targetName),
            items,
            key: optionKey,
            storageKey: resolvedStorageKey,
            title: customTitle || humanizeKey(targetName),
        });
    });

    return groups;
}

function withBlockFieldOptions(WrappedField: ComponentType<*>): ComponentType<*> {
    @observer
    class BlockFieldOptionsEnhancer extends React.Component<*> {
        @observable openGroupKey: ?string = null;

        @computed get optionGroups(): Array<OptionGroupConfig> {
            return extractOptionGroups(this.props.schemaOptions);
        }

        get blockPath(): string {
            const {dataPath} = this.props;
            if (!dataPath || typeof dataPath !== 'string') {
                return '';
            }
            const normalized = dataPath.startsWith('/') ? dataPath : `/${dataPath}`;
            const propsIndex = normalized.lastIndexOf('/props/');
            if (propsIndex !== -1) {
                return normalized.substring(0, propsIndex);
            }
            const lastSlash = normalized.lastIndexOf('/');
            return lastSlash > 0 ? normalized.substring(0, lastSlash) : '';
        }

        resolveTargetDataPath(group: OptionGroupConfig): string {
            const cleanStorageKey = group.storageKey.replace(/^\/+/, '');
            const base = this.blockPath;
            return base !== '' ? `${base}/${cleanStorageKey}` : `/${cleanStorageKey}`;
        }

        getParentFieldAndRenderer(): {fieldProps: ?Object, rendererValue: ?Object} {
            // $FlowFixMe
            let fiber = this._reactInternals || this._reactInternalFiber;
            let fieldProps = null;
            let rendererValue = null;
            let depth = 0;

            while (fiber && depth < 10) {
                const stateNode = fiber.stateNode;
                if (stateNode && stateNode !== this && stateNode.props) {
                    if (
                        !fieldProps
                        && stateNode.props.dataPath === this.props.dataPath
                        && typeof stateNode.props.name === 'string'
                        && typeof stateNode.props.onChange === 'function'
                        && stateNode.handleChange === this.props.onChange
                    ) {
                        fieldProps = stateNode.props;
                    } else if (
                        fieldProps
                        && !rendererValue
                        && stateNode.props.value
                        && typeof stateNode.props.value === 'object'
                        && stateNode.props.onChange === fieldProps.onChange
                    ) {
                        rendererValue = stateNode.props.value;
                        break;
                    }
                }
                fiber = fiber.return;
                depth += 1;
            }

            return {fieldProps, rendererValue};
        }

        readGroupValue(group: OptionGroupConfig): ?string {
            const cleanStorageKey = group.storageKey.replace(/^\/+/, '');
            const {rendererValue} = this.getParentFieldAndRenderer();

            if (rendererValue && typeof rendererValue === 'object') {
                try {
                    const pointer = `/${cleanStorageKey}`;
                    if (jsonpointer.has(rendererValue, pointer)) {
                        const localVal = jsonpointer.get(rendererValue, pointer);
                        if (localVal !== undefined && localVal !== null && localVal !== '') {
                            return String(localVal);
                        }
                    }
                } catch (e) {
                    // ignore
                }
            }

            const {formInspector} = this.props;
            if (formInspector && typeof formInspector.getValueByPath === 'function') {
                try {
                    const targetPath = this.resolveTargetDataPath(group);
                    const val = formInspector.getValueByPath(targetPath);
                    if (val !== undefined && val !== null && val !== '') {
                        return String(val);
                    }
                } catch (e) {
                    // ignore
                }
            }

            return group.defaultValue !== null && group.defaultValue !== undefined
                ? String(group.defaultValue)
                : null;
        }

        @action handleToggleMenu = (groupKey: string) => {
            if (this.props.disabled) {
                return;
            }
            this.openGroupKey = this.openGroupKey === groupKey ? null : groupKey;
        };

        @action handleCloseMenu = () => {
            this.openGroupKey = null;
        };

        @action handleSelectOption = (group: OptionGroupConfig, nextValue: mixed) => {
            const {formInspector, onFinish} = this.props;
            const cleanStorageKey = group.storageKey.replace(/^\/+/, '');
            const targetPath = this.resolveTargetDataPath(group);
            const {fieldProps, rendererValue} = this.getParentFieldAndRenderer();

            if (fieldProps && typeof fieldProps.onChange === 'function') {
                if (
                    cleanStorageKey.startsWith('props/')
                    && rendererValue
                    && (Array.isArray(rendererValue.props) || isArrayLike(rendererValue.props))
                ) {
                    const subKey = cleanStorageKey.substring(6);
                    const nextProps = {};
                    Object.keys(rendererValue.props).forEach((k) => {
                        if (!/^\d+$/.test(k)) {
                            nextProps[k] = rendererValue.props[k];
                        }
                    });
                    nextProps[subKey] = nextValue;
                    fieldProps.onChange('props', nextProps);
                } else {
                    fieldProps.onChange(cleanStorageKey, nextValue);
                }
            } else {
                applyPathChangeToStore(formInspector, targetPath, nextValue);
            }

            this.openGroupKey = null;

            if (typeof onFinish === 'function') {
                onFinish();
            }
        };

        render() {
            const groups = this.optionGroups;
            if (groups.length === 0) {
                return <WrappedField {...this.props} />;
            }

            const {disabled} = this.props;

            return (
                <div className={styles.container}>
                    <div className={styles.fieldControl}>
                        <WrappedField {...this.props} />
                    </div>
                    <div className={styles.optionGroupBar}>
                        {groups.map((group) => {
                            const currentVal = this.readGroupValue(group);
                            const activeItem = group.items.find(
                                (item) => String(item.value) === String(currentVal)
                            );
                            const activeLabel = activeItem
                                ? activeItem.label
                                : (currentVal !== undefined && currentVal !== null ? String(currentVal) : '');
                            const isOpen = this.openGroupKey === group.key;

                            return (
                                <ArrowMenu
                                    anchorElement={
                                        <button
                                            aria-label={`${group.title}: ${activeLabel}`}
                                            className={classNames(
                                                buttonStyles.button,
                                                buttonStyles.right,
                                                buttonStyles.hasOptions
                                            )}
                                            disabled={disabled}
                                            onClick={() => this.handleToggleMenu(group.key)}
                                            title={`${group.title}: ${activeLabel}`}
                                            type="button"
                                        >
                                            <Icon className={buttonStyles.icon} name={group.icon} />
                                            <Icon name="su-angle-down" />
                                        </button>
                                    }
                                    key={group.key}
                                    onClose={this.handleCloseMenu}
                                    open={isOpen}
                                >
                                    <ArrowMenu.SingleItemSection
                                        onChange={(val) => this.handleSelectOption(group, val)}
                                        title={group.title}
                                        value={currentVal}
                                    >
                                        {group.items.map((item) => (
                                            <ArrowMenu.Item key={String(item.value)} value={item.value}>
                                                {item.label}
                                            </ArrowMenu.Item>
                                        ))}
                                    </ArrowMenu.SingleItemSection>
                                </ArrowMenu>
                            );
                        })}
                    </div>
                </div>
            );
        }
    }

    return BlockFieldOptionsEnhancer;
}

function enhanceFieldRegistryWithBlockOptions(registry: Object) {
    if (!registry || registry.__blockSuiteOptionsEnhanced) {
        return;
    }

    const wrappedCache = new WeakMap();
    const originalGet = registry.get.bind(registry);

    registry.get = function(name: string) {
        const OriginalComponent = originalGet(name);
        if (!OriginalComponent || EXCLUDED_FIELD_TYPES[name]) {
            return OriginalComponent;
        }

        let enhanced = wrappedCache.get(OriginalComponent);
        if (!enhanced) {
            enhanced = withBlockFieldOptions(OriginalComponent);
            wrappedCache.set(OriginalComponent, enhanced);
        }

        return enhanced;
    };

    registry.__blockSuiteOptionsEnhanced = true;
}

export {
    enhanceFieldRegistryWithBlockOptions,
    extractOptionGroups,
    withBlockFieldOptions,
};
export default withBlockFieldOptions;
