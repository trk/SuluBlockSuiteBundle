// @flow
import React from 'react';
import {action, observable, toJS} from 'mobx';
import {observer} from 'mobx-react';
import {Button, Icon} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import {Requester} from 'sulu-admin-bundle/services';
import {userStore} from 'sulu-admin-bundle/stores';
import type {FieldTypeProps} from 'sulu-admin-bundle/types';
import reusableBlockStore from '../../stores/reusableBlockStore';
import Preset from './Preset';
import Reusable from './Reusable';
import styles from './presetSelection.scss';

type Props = FieldTypeProps<?string>;

@observer
export default class PresetSelection extends React.Component<Props> {
    static presetsCache: Map<string, Array<Object>> = new Map();
    static presetsPromises: Map<string, Promise<any>> = new Map();

    @observable savePresetOpen: boolean = false;
    @observable saveReusableOpen: boolean = false;
    @observable presetGalleryOpen: boolean = false;

    @observable.ref presets: Array<Object> = [];
    @observable presetsLoading: boolean = false;

    lastLoadedPresetType: ?string = null;
    lastLoadedPresetLocale: ?string = null;
    lastLoadedPresetWebspace: ?string = null;

    constructor(props: Props) {
        super(props);

        const initialPresets = this.parseInitialPresetsFromSchema();
        if (initialPresets.length > 0) {
            this.presets = initialPresets;
        }
    }

    componentDidMount() {
        this.loadPresets();
    }

    componentDidUpdate() {
        if (
            this.componentType !== this.lastLoadedPresetType
            || this.currentLocale !== this.lastLoadedPresetLocale
            || this.currentWebspace !== this.lastLoadedPresetWebspace
        ) {
            this.loadPresets();
        }
    }

    parseInitialPresetsFromSchema(): Array<Object> {
        const {schemaOptions} = this.props;
        if (!schemaOptions || !schemaOptions.presets_json) {
            return [];
        }
        const rawJson = typeof schemaOptions.presets_json === 'string'
            ? schemaOptions.presets_json
            : schemaOptions.presets_json.value;
        if (!rawJson || typeof rawJson !== 'string') {
            return [];
        }
        try {
            const parsed = JSON.parse(rawJson);
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    get currentLocale(): string {
        const {formInspector} = this.props;
        if (formInspector && formInspector.locale && formInspector.locale.get()) {
            return String(formInspector.locale.get()).toLowerCase().split('_')[0];
        }

        const rawLoc = userStore.contentLocale || (userStore.user ? userStore.user.locale : null) || 'en';
        return String(rawLoc).toLowerCase().split('_')[0];
    }

    get currentWebspace(): ?string {
        const {formInspector} = this.props;
        if (!formInspector) {
            return null;
        }

        if (formInspector.options && typeof formInspector.options.webspace === 'string' && formInspector.options.webspace.trim() !== '') {
            return formInspector.options.webspace.trim();
        }

        try {
            const fieldWebspace = formInspector.getValueByPath('/webspace');
            if (typeof fieldWebspace === 'string' && fieldWebspace.trim() !== '') {
                return fieldWebspace.trim();
            }
        } catch (e) {
            // ignore if path does not exist
        }

        return null;
    }

    get componentType(): string {
        const {data, formInspector, schemaOptions, schemaPath} = this.props;

        if (formInspector) {
            try {
                const targetBlock = formInspector.getValueByPath('/target_block');
                if (targetBlock && typeof targetBlock === 'string') {
                    return targetBlock;
                }
            } catch (e) {
                // ignore
            }
        }

        if (schemaOptions) {
            const option = schemaOptions.component_type || schemaOptions.block_type || schemaOptions.target_block;
            if (typeof option === 'string' && option !== '') {
                return option;
            }
            if (option && typeof option === 'object' && option.value) {
                return String(option.value);
            }
        }

        if (schemaPath) {
            const matches = [...schemaPath.matchAll(/types\/([a-zA-Z0-9_-]+)/g)];
            if (matches.length > 0) {
                const lastMatch = matches[matches.length - 1];
                if (lastMatch && lastMatch[1]) {
                    return lastMatch[1];
                }
            }
        }

        const blockObj = this.blockData;
        if (blockObj && typeof blockObj.type === 'string') {
            return blockObj.type;
        }

        if (data && typeof data === 'object' && typeof data.type === 'string') {
            return data.type;
        }

        return 'general';
    }

    get blockPath(): ?string {
        const {dataPath} = this.props;
        if (!dataPath) {
            return null;
        }
        const normalized = dataPath.startsWith('/') ? dataPath : '/' + dataPath;
        const propsIndex = normalized.lastIndexOf('/props/');
        if (propsIndex !== -1) {
            return normalized.substring(0, propsIndex);
        }
        return normalized.substring(0, normalized.lastIndexOf('/'));
    }

    get blockData(): ?Object {
        const {formInspector} = this.props;
        const blockPath = this.blockPath;
        if (!formInspector || !blockPath) {
            return null;
        }
        try {
            const blockObj = formInspector.getValueByPath(blockPath);
            if (blockObj && typeof blockObj === 'object') {
                return toJS(blockObj);
            }
        } catch (e) {
            // ignore
        }
        return null;
    }

    get blockPropsValues(): Object {
        const blockObj = this.blockData;
        if (blockObj && blockObj.props && typeof blockObj.props === 'object') {
            return toJS(blockObj.props);
        }
        return {};
    }

    @action loadPresets = (force: boolean = false) => {
        const type = this.componentType;
        const locale = this.currentLocale;
        const webspace = this.currentWebspace;

        if (!type || type === 'general') {
            this.presetsLoading = false;
            this.lastLoadedPresetType = type;
            this.lastLoadedPresetLocale = locale;
            this.lastLoadedPresetWebspace = webspace;
            return;
        }

        const cacheKey = `${type}_${locale}_${webspace || ''}`;

        if (!force && PresetSelection.presetsCache.has(cacheKey)) {
            this.presets = PresetSelection.presetsCache.get(cacheKey) || [];
            this.presetsLoading = false;
            this.lastLoadedPresetType = type;
            this.lastLoadedPresetLocale = locale;
            this.lastLoadedPresetWebspace = webspace;
            return;
        }

        if (PresetSelection.presetsPromises.has(cacheKey)) {
            this.presetsLoading = true;
            const promise = PresetSelection.presetsPromises.get(cacheKey);
            if (promise) {
                promise.then(action((presets) => {
                    if (this.componentType === type && this.currentLocale === locale && this.currentWebspace === webspace) {
                        this.lastLoadedPresetType = type;
                        this.lastLoadedPresetLocale = locale;
                        this.lastLoadedPresetWebspace = webspace;
                        this.presets = presets;
                        this.presetsLoading = false;
                    }
                })).catch(action(() => {
                    this.presetsLoading = false;
                }));
            }
            return;
        }

        this.presetsLoading = true;

        const webspaceQuery = webspace ? `&webspace=${encodeURIComponent(webspace)}` : '';
        const url = `/admin/api/block-presets/${encodeURIComponent(type)}?locale=${encodeURIComponent(locale)}${webspaceQuery}`;
        const fetchPromise = Requester.get(url)
            .then((data) => {
                const list = (data && Array.isArray(data.presets)) ? data.presets : [];
                PresetSelection.presetsCache.set(cacheKey, list);
                PresetSelection.presetsPromises.delete(cacheKey);
                return list;
            })
            .catch(() => {
                PresetSelection.presetsPromises.delete(cacheKey);
                return this.presets;
            });

        PresetSelection.presetsPromises.set(cacheKey, fetchPromise);

        fetchPromise.then(action((presets) => {
            this.presetsLoading = false;
            if (this.componentType === type && this.currentLocale === locale && this.currentWebspace === webspace) {
                this.lastLoadedPresetType = type;
                this.lastLoadedPresetLocale = locale;
                this.lastLoadedPresetWebspace = webspace;
                this.presets = presets;
            }
        }));
    };

    applyPresetToForm(presetId: ?string, preset: ?Object, includeContentValues: boolean = false) {
        const {formInspector, onChange, value} = this.props;
        const blockPath = this.blockPath;
        const formStore = formInspector ? formInspector.formStore : null;

        if (!formStore || typeof formStore.changeMultiple !== 'function' || !blockPath) {
            if (onChange) {
                onChange(presetId);
            }
            return;
        }

        const changes = {
            [`${blockPath}/props/preset`]: presetId,
        };

        const previousPresetId = value ? String(value).trim() : '';
        if (previousPresetId && previousPresetId !== (presetId || '')) {
            const previousPreset = this.presets.find((p) => p.id === previousPresetId);
            if (previousPreset && previousPreset.props && typeof previousPreset.props === 'object') {
                Object.keys(previousPreset.props).forEach((prevPropKey) => {
                    if (prevPropKey !== '_preset' && prevPropKey !== 'preset') {
                        changes[`${blockPath}/props/${prevPropKey}`] = undefined;
                    }
                });
            }
        }

        if (preset && preset.props && typeof preset.props === 'object') {
            Object.keys(preset.props).forEach((propKey) => {
                if (propKey !== '_preset' && propKey !== 'preset') {
                    changes[`${blockPath}/props/${propKey}`] = preset.props[propKey];
                }
            });
        }

        if (preset && includeContentValues && preset.values && typeof preset.values === 'object') {
            Object.keys(preset.values).forEach((fieldKey) => {
                if (fieldKey !== 'type' && fieldKey !== 'props' && fieldKey !== '_preset' && fieldKey !== 'preset') {
                    changes[`${blockPath}/${fieldKey}`] = preset.values[fieldKey];
                }
            });
        }

        formStore.changeMultiple(changes);
    }

    @action handleSelectPreset = (presetId: ?string, preset: ?Object) => {
        const {onFinish} = this.props;
        const normalizedId = presetId && presetId.trim() !== '' ? presetId.trim() : null;

        this.applyPresetToForm(normalizedId, preset, false);

        if (onFinish) {
            onFinish();
        }
    };

    @action handleApplyFullPreset = () => {
        const currentPresetId = this.props.value;
        if (!currentPresetId) {
            return;
        }

        const preset = this.presets.find((p) => p.id === currentPresetId);
        if (!preset) {
            return;
        }

        this.applyPresetToForm(currentPresetId, preset, true);
        if (this.props.onFinish) {
            this.props.onFinish();
        }
    };

    @action handleClearPreset = () => {
        this.handleSelectPreset(null, null);
    };

    @action handleOpenPresetGallery = () => {
        this.presetGalleryOpen = true;
    };

    @action handleClosePresetGallery = () => {
        this.presetGalleryOpen = false;
    };

    @action handleOpenSavePreset = () => {
        this.savePresetOpen = true;
    };

    @action handleCloseSavePreset = () => {
        this.savePresetOpen = false;
    };

    @action handleSavePresetSuccess = (createdPreset: Object) => {
        this.savePresetOpen = false;
        const exists = this.presets.some((p) => p.id === createdPreset.id);
        if (!exists) {
            this.presets = [...this.presets, createdPreset];
        }
        const cacheKey = `${this.componentType}_${this.currentLocale}_${this.currentWebspace || ''}`;
        PresetSelection.presetsCache.set(cacheKey, this.presets);
        this.handleSelectPreset(createdPreset.id, createdPreset);
    };

    @action handleOpenSaveReusable = () => {
        this.saveReusableOpen = true;
    };

    @action handleCloseSaveReusable = () => {
        this.saveReusableOpen = false;
    };

    @action handleSaveReusableSuccess = (createdSnippet: ?Object, convertBlock: boolean = true) => {
        this.saveReusableOpen = false;

        if (createdSnippet && createdSnippet.id) {
            reusableBlockStore.set(createdSnippet.id, createdSnippet, this.currentLocale);
        }

        if (!convertBlock || !createdSnippet || !createdSnippet.id) {
            return;
        }

        const {formInspector, onFinish} = this.props;
        const blockPath = this.blockPath;
        const formStore = formInspector ? formInspector.formStore : null;

        if (formStore && typeof formStore.change === 'function' && blockPath) {
            formStore.change(blockPath, {
                type: 'reusable',
                snippet: createdSnippet.id,
                props: {},
            });
            if (onFinish) {
                onFinish();
            }
        }
    };

    t(key: string, fallback: string): string {
        const translated = translate(key);
        return translated && translated !== key ? translated : fallback;
    }

    render() {
        const {disabled, value} = this.props;
        const currentPresetId = value ? String(value) : '';
        const activePreset = this.presets.find((p) => p.id === currentPresetId) || null;

        const browseLabel = this.t('sulu_block_suite.browse_presets', 'Browse Presets');
        const applyFullPreset = this.t('sulu_block_suite.apply_full_preset', 'Apply Content & Styles');
        const saveAsPresetLabel = this.t('sulu_block_suite.save_as_preset', 'Save as Preset');
        const saveAsReusableLabel = this.t('sulu_block_suite.save_as_reusable', 'Save as Reusable Block');
        const clearPresetLabel = this.t('sulu_block_suite.clear_preset', 'Clear Preset');

        return (
            <div className={styles.presetContainer}>
                <div className={styles.presetRow}>
                    <div className={styles.actionGroup}>
                        {this.presets.length > 0 && (
                            <Button
                                disabled={disabled}
                                icon="su-eye"
                                onClick={this.handleOpenPresetGallery}
                                skin="secondary"
                                title={browseLabel}
                            >
                                {browseLabel}
                            </Button>
                        )}
                        {activePreset && activePreset.values && Object.keys(activePreset.values).some((k) => k !== 'type' && k !== 'props') && (
                            <Button
                                disabled={disabled}
                                icon="su-brush"
                                onClick={this.handleApplyFullPreset}
                                skin="primary"
                            >
                                {applyFullPreset}
                            </Button>
                        )}
                        <Button
                            disabled={disabled}
                            icon="su-magic"
                            onClick={this.handleOpenSavePreset}
                            skin="secondary"
                        >
                            {saveAsPresetLabel}
                        </Button>
                        {this.componentType !== 'reusable' && (
                            <Button
                                disabled={disabled}
                                icon="su-snippet"
                                onClick={this.handleOpenSaveReusable}
                                skin="secondary"
                            >
                                {saveAsReusableLabel}
                            </Button>
                        )}
                    </div>
                </div>

                {activePreset && (
                    <div className={styles.presetDetails}>
                        <span className={styles.activePresetBadge}>
                            <Icon name="su-check" />
                            <span>{activePreset.title}</span>
                        </span>
                        {activePreset.preview_image && (
                            <img
                                alt={activePreset.title}
                                className={styles.inlinePreviewImage}
                                src={activePreset.preview_image}
                            />
                        )}
                        {activePreset.description && (
                            <div className={styles.presetDescription}>
                                {activePreset.description}
                            </div>
                        )}
                        <button
                            aria-label={clearPresetLabel}
                            className={styles.clearPresetButton}
                            onClick={this.handleClearPreset}
                            title={clearPresetLabel}
                            type="button"
                        >
                            <Icon name="su-trash-alt" />
                        </button>
                    </div>
                )}

                <Preset.SaveDialog
                    componentType={this.componentType}
                    contentValues={this.blockData}
                    locale={this.currentLocale}
                    onCancel={this.handleCloseSavePreset}
                    onSuccess={this.handleSavePresetSuccess}
                    open={this.savePresetOpen}
                    propsValues={this.blockPropsValues}
                    webspace={this.currentWebspace}
                />

                <Preset.Gallery
                    componentType={this.componentType}
                    currentPresetId={currentPresetId}
                    onClose={this.handleClosePresetGallery}
                    onSelect={this.handleSelectPreset}
                    open={this.presetGalleryOpen}
                    presets={this.presets}
                />

                <Reusable.SaveDialog
                    blockData={this.blockData || {}}
                    componentType={this.componentType}
                    locale={this.currentLocale}
                    onCancel={this.handleCloseSaveReusable}
                    onSuccess={this.handleSaveReusableSuccess}
                    open={this.saveReusableOpen}
                    propsValues={this.blockPropsValues}
                    webspace={this.currentWebspace}
                />
            </div>
        );
    }
}
