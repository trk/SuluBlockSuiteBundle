// @flow
import React from 'react';
import {action, observable, toJS} from 'mobx';
import {observer} from 'mobx-react';
import {Button, DropdownButton, Icon} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import {Requester} from 'sulu-admin-bundle/services';
import {userStore} from 'sulu-admin-bundle/stores';
import type {FieldTypeProps} from 'sulu-admin-bundle/types';
import Properties from './Properties';
import Preset from './Preset';
import Reusable from './Reusable';
import BlockPropsBlockPreviewTransformer from './BlockPropsBlockPreviewTransformer';
import blockPropsStyles from './blockProps.scss';

const blockPropsPreviewTransformer = new BlockPropsBlockPreviewTransformer();

type Props = FieldTypeProps<?Object>;

@observer
class BlockProps extends React.Component<Props> {
    static presetsCache: Map<string, Array<Object>> = new Map();
    static presetsPromises: Map<string, Promise<any>> = new Map();

    @observable propertiesOpen: boolean = false;
    @observable savePresetOpen: boolean = false;
    @observable saveReusableOpen: boolean = false;
    @observable presetGalleryOpen: boolean = false;

    @observable.ref presets: Array<Object> = [];
    @observable presetsLoading: boolean = false;
    @observable selectedPresetId: ?string = null;

    @observable.ref reusableSnippet: ?Object = null;
    @observable reusableLoading: boolean = false;

    lastLoadedPresetType: ?string = null;
    lastLoadedPresetLocale: ?string = null;
    lastLoadedSnippetId: ?string = null;

    constructor(props: Props) {
        super(props);

        const currentProps = props.value || {};
        this.selectedPresetId = currentProps._preset || null;

        const type = this.componentType;
        const locale = this.currentLocale;
        if (type && type !== 'general') {
            const presetsKey = `${type}_${locale}`;
            if (BlockProps.presetsCache.has(presetsKey)) {
                this.presets = BlockProps.presetsCache.get(presetsKey) || [];
                this.lastLoadedPresetType = type;
                this.lastLoadedPresetLocale = locale;
            }
        }
    }

    componentDidMount() {
        this.loadPresets();
        this.loadReusableSnippet();
    }

    componentDidUpdate(prevProps: Props) {
        if (this.componentType !== this.lastLoadedPresetType || this.currentLocale !== this.lastLoadedPresetLocale) {
            this.loadPresets();
        }
        if (this.reusableSnippetId !== this.lastLoadedSnippetId) {
            this.loadReusableSnippet();
        }
        if (this.props.value !== prevProps.value) {
            const currentProps = this.props.value || {};
            const presetInValue = currentProps._preset || null;
            if (presetInValue !== this.selectedPresetId) {
                action(() => {
                    this.selectedPresetId = presetInValue;
                })();
            }
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

    get uiLocale(): string {
        const userLoc = userStore.user && userStore.user.locale ? userStore.user.locale : null;
        if (userLoc) {
            return String(userLoc).toLowerCase().split('_')[0];
        }

        return this.currentLocale;
    }

    get componentType(): string {
        const {data, dataPath, formInspector, schemaOptions, schemaPath} = this.props;
        const normalizePointer = (p: string): string => (p.startsWith('/') ? p : '/' + p);
        const normalizeType = (t: string): string => {
            if (t === 'row_item') {
                return 'row';
            }
            if (t === 'column_item') {
                return 'column';
            }
            return t;
        };

        // 1. From formInspector if target_block is selected on current form
        if (formInspector) {
            try {
                const targetBlock = formInspector.getValueByPath('/target_block');
                if (targetBlock && typeof targetBlock === 'string') {
                    return normalizeType(targetBlock);
                }
            } catch (e) {
                // ignore
            }
        }

        // 2. From schemaOptions
        if (schemaOptions && schemaOptions.component_type) {
            if (typeof schemaOptions.component_type === 'string') {
                return normalizeType(schemaOptions.component_type);
            }
            if (schemaOptions.component_type.value) {
                return normalizeType(String(schemaOptions.component_type.value));
            }
        }

        // 3. From schemaPath (use the LAST "types/<block>" segment for nested blocks inside section/row/column)
        if (schemaPath) {
            const matches = [...schemaPath.matchAll(/types\/([a-zA-Z0-9_-]+)/g)];
            if (matches.length > 0) {
                const lastMatch = matches[matches.length - 1];
                if (lastMatch && lastMatch[1]) {
                    return normalizeType(lastMatch[1]);
                }
            }
        }

        // 4. From formInspector via parent dataPath (e.g. "blocks/0/props" -> "blocks/0/type")
        if (formInspector && dataPath) {
            const parts = dataPath.split('/');
            parts.pop();
            const parentPath = parts.join('/');
            if (parentPath) {
                try {
                    const type = formInspector.getValueByPath(normalizePointer(parentPath + '/type'));
                    if (type && typeof type === 'string') {
                        return normalizeType(type);
                    }
                    const blockData = formInspector.getValueByPath(normalizePointer(parentPath));
                    if (blockData && typeof blockData === 'object' && blockData.type && typeof blockData.type === 'string') {
                        return normalizeType(blockData.type);
                    }
                } catch (e) {
                    // ignore
                }
            }
        }

        // 5. From data prop directly
        if (data && typeof data === 'object' && typeof data.type === 'string') {
            return normalizeType(data.type);
        }

        return 'general';
    }

    get isSupportedBlock(): boolean {
        const type = this.componentType;
        return Boolean(type && type !== 'general');
    }

    get reusableSnippetId(): ?string {
        const value = this.props.value;
        if (value && typeof value === 'object' && value._reusable_snippet_id) {
            return String(value._reusable_snippet_id);
        }
        return null;
    }

    get blockPath(): ?string {
        const {dataPath} = this.props;
        if (!dataPath) {
            return null;
        }
        return dataPath.endsWith('/props')
            ? dataPath.slice(0, -6)
            : dataPath.substring(0, dataPath.lastIndexOf('/'));
    }

    get blockContentValues(): ?Object {
        const {formInspector} = this.props;
        const blockPath = this.blockPath;
        if (!formInspector || !blockPath) {
            return null;
        }
        try {
            const blockData = formInspector.getValueByPath(blockPath);
            if (blockData && typeof blockData === 'object') {
                return toJS(blockData);
            }
        } catch (e) {
            // ignore
        }
        return null;
    }

    @action loadPresets = (force: boolean = false) => {
        const type = this.componentType;
        const locale = this.currentLocale;

        if (!type || type === 'general') {
            this.presets = [];
            this.presetsLoading = false;
            this.lastLoadedPresetType = type;
            this.lastLoadedPresetLocale = locale;
            return;
        }

        const cacheKey = `${type}_${locale}`;

        if (!force && BlockProps.presetsCache.has(cacheKey)) {
            this.presets = BlockProps.presetsCache.get(cacheKey) || [];
            this.presetsLoading = false;
            this.lastLoadedPresetType = type;
            this.lastLoadedPresetLocale = locale;
            return;
        }

        if (BlockProps.presetsPromises.has(cacheKey)) {
            this.presetsLoading = true;
            const promise = BlockProps.presetsPromises.get(cacheKey);
            if (promise) {
                promise.then(action((presets) => {
                    if (this.componentType === type && this.currentLocale === locale) {
                        this.lastLoadedPresetType = type;
                        this.lastLoadedPresetLocale = locale;
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

        const url = `/admin/api/block-presets/${type}?locale=${locale}`;
        const fetchPromise = Requester.get(url)
            .then((data) => {
                const list = (data && Array.isArray(data.presets)) ? data.presets : [];
                BlockProps.presetsCache.set(cacheKey, list);
                BlockProps.presetsPromises.delete(cacheKey);
                return list;
            })
            .catch(() => {
                BlockProps.presetsPromises.delete(cacheKey);
                return [];
            });

        BlockProps.presetsPromises.set(cacheKey, fetchPromise);

        fetchPromise.then(action((presets) => {
            this.presetsLoading = false;
            if (this.componentType === type && this.currentLocale === locale) {
                this.lastLoadedPresetType = type;
                this.lastLoadedPresetLocale = locale;
                this.presets = presets;
            }
        }));
    };

    @action loadReusableSnippet = () => {
        const snippetId = this.reusableSnippetId;
        if (!snippetId) {
            this.reusableSnippet = null;
            this.lastLoadedSnippetId = null;
            return;
        }

        if (snippetId === this.lastLoadedSnippetId && this.reusableSnippet) {
            return;
        }

        this.reusableLoading = true;
        this.lastLoadedSnippetId = snippetId;

        const url = `/admin/api/reusable-blocks/${snippetId}?locale=${this.currentLocale}`;
        Requester.get(url)
            .then(action((snippet) => {
                this.reusableSnippet = snippet;
                this.reusableLoading = false;
            }))
            .catch(action(() => {
                this.reusableSnippet = null;
                this.reusableLoading = false;
            }));
    };

    @action handleSelectPreset = (presetId: ?string, preset: ?Object) => {
        this.selectedPresetId = presetId || null;
        const {onChange, onFinish, value} = this.props;
        const currentValues = toJS(value || {});

        if (!presetId) {
            delete currentValues._preset;
            if (onChange) {
                onChange(currentValues);
            }
            if (onFinish) {
                onFinish();
            }
            return;
        }

        if (preset && preset.props) {
            const updated = {
                ...currentValues,
                ...preset.props,
                _preset: presetId,
            };
            if (onChange) {
                onChange(updated);
            }
            if (onFinish) {
                onFinish();
            }
        }
    };

    @action handleReapplyPreset = () => {
        const currentPresetId = this.selectedPresetId;
        if (!currentPresetId) {
            return;
        }

        const preset = this.presets.find((p) => p.id === currentPresetId);
        if (!preset || !preset.props) {
            return;
        }

        const {onChange, onFinish, value} = this.props;
        const currentValues = toJS(value || {});
        const updated = {
            ...currentValues,
            ...preset.props,
            _preset: currentPresetId,
        };

        if (onChange) {
            onChange(updated);
        }
        if (onFinish) {
            onFinish();
        }
    };

    @action handleApplyFullPreset = () => {
        const currentPresetId = this.selectedPresetId;
        if (!currentPresetId) {
            return;
        }

        const preset = this.presets.find((p) => p.id === currentPresetId);
        if (!preset) {
            return;
        }

        this.handleReapplyPreset();

        if (preset.values && this.props.formInspector && this.blockPath) {
            const formStore = this.props.formInspector.formStore;
            if (formStore && typeof formStore.changeMultiple === 'function') {
                const changes = {};
                Object.keys(preset.values).forEach((fieldKey) => {
                    if (fieldKey !== 'type' && fieldKey !== 'props') {
                        changes[`${this.blockPath}/${fieldKey}`] = preset.values[fieldKey];
                    }
                });
                if (Object.keys(changes).length > 0) {
                    formStore.changeMultiple(changes);
                }
            }
        }
    };

    @action handleOpenProperties = () => {
        this.propertiesOpen = true;
    };

    @action handleCloseProperties = () => {
        this.propertiesOpen = false;
    };

    @action handleConfirmProperties = (cleanedValues: Object) => {
        this.propertiesOpen = false;
        const {onChange, onFinish} = this.props;
        if (onChange) {
            onChange(cleanedValues);
        }
        if (onFinish) {
            onFinish();
        }
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
        const cacheKey = `${this.componentType}_${this.currentLocale}`;
        BlockProps.presetsCache.set(cacheKey, this.presets);
        this.handleSelectPreset(createdPreset.id, createdPreset);
    };

    @action handleOpenPresetGallery = () => {
        this.presetGalleryOpen = true;
    };

    @action handleClosePresetGallery = () => {
        this.presetGalleryOpen = false;
    };

    @action handleClearPreset = () => {
        this.handleSelectPreset(null, null);
    };

    @action handleOpenSaveReusable = () => {
        this.saveReusableOpen = true;
    };

    @action handleCloseSaveReusable = () => {
        this.saveReusableOpen = false;
    };

    @action handleSaveReusableSuccess = (createdSnippet: Object) => {
        this.saveReusableOpen = false;
        const {onChange, onFinish, value} = this.props;
        const updated = {
            ...(toJS(value) || {}),
            _reusable_snippet_id: createdSnippet.id,
        };
        if (onChange) {
            onChange(updated);
        }
        if (onFinish) {
            onFinish();
        }
        this.reusableSnippet = createdSnippet;
    };

    render() {
        if (!this.isSupportedBlock) {
            return null;
        }

        const value = this.props.value || {};
        const hasValues = Object.keys(value).some(
            (k) => k !== '_preset' && value[k] !== undefined && value[k] !== null && value[k] !== ''
        );
        const currentPresetId = this.selectedPresetId || value._preset || '';
        const activePreset = this.presets.find((p) => p.id === currentPresetId);

        const presetLabel = translate('sulu_block_suite.preset_label') || 'Preset:';
        const editButton = translate('sulu_block_suite.configure_props') || 'Configure Properties';
        const reapplyButton = translate('sulu_block_suite.reapply_button') || 'Re-apply';
        const applyFullPreset = translate('sulu_block_suite.apply_full_preset') || 'Apply Content & Styles';
        const saveAs = translate('sulu_block_suite.save_as') || 'Save as';
        const saveAsPresetOption = translate('sulu_block_suite.save_as_preset_option') || 'Preset';
        const saveAsReusableOption = translate('sulu_block_suite.save_as_reusable_option') || 'Reusable Block';

        return (
            <div>
                {this.reusableSnippet && (
                    <Reusable.Card locale={this.currentLocale} snippet={this.reusableSnippet} />
                )}

                <div className={blockPropsStyles.blockSuiteContainer}>
                    <div className={blockPropsStyles.blockSuiteToolbar}>
                        <div className={blockPropsStyles.actionGroup}>
                            <Button
                                icon="su-cog"
                                onClick={this.handleOpenProperties}
                                skin={hasValues ? 'primary' : 'secondary'}
                            >
                                {editButton}
                            </Button>
                            {this.presets.length > 0 && (
                                <Button
                                    icon="su-eye"
                                    onClick={this.handleOpenPresetGallery}
                                    skin="secondary"
                                    title={translate('sulu_block_suite.browse_presets') || 'Browse Presets'}
                                >
                                    {translate('sulu_block_suite.browse_presets') || 'Browse Presets'}
                                </Button>
                            )}
                            {activePreset && activePreset.values && Object.keys(activePreset.values).some((k) => k !== 'type' && k !== 'props') && (
                                <Button
                                    icon="su-brush"
                                    onClick={this.handleApplyFullPreset}
                                    skin="primary"
                                >
                                    {applyFullPreset}
                                </Button>
                            )}
                            <DropdownButton icon="su-save" label={saveAs} skin="secondary">
                                <DropdownButton.Item icon="su-sliders" onClick={this.handleOpenSavePreset}>
                                    {saveAsPresetOption}
                                </DropdownButton.Item>
                                {this.componentType !== 'reusable' && (
                                    <DropdownButton.Item icon="su-clone" onClick={this.handleOpenSaveReusable}>
                                        {saveAsReusableOption}
                                    </DropdownButton.Item>
                                )}
                            </DropdownButton>
                            {blockPropsPreviewTransformer.transform(value)}
                        </div>
                    </div>

                    {activePreset && (
                        <div className={blockPropsStyles.presetDetails}>
                            <span className={blockPropsStyles.activePresetBadge}>
                                <Icon name="su-check" />
                                <span>{activePreset.title}</span>
                            </span>
                            {activePreset.preview_image && (
                                <img
                                    alt={activePreset.title}
                                    className={blockPropsStyles.inlinePreviewImage}
                                    src={activePreset.preview_image}
                                />
                            )}
                            {activePreset.description && (
                                <div className={blockPropsStyles.presetDescription}>
                                    {activePreset.description}
                                </div>
                            )}
                            <button
                                aria-label={translate('sulu_block_suite.clear_preset') || 'Clear Preset'}
                                className={blockPropsStyles.clearPresetButton}
                                onClick={this.handleClearPreset}
                                title={translate('sulu_block_suite.clear_preset') || 'Clear Preset'}
                                type="button"
                            >
                                <Icon name="su-trash-alt" />
                            </button>
                        </div>
                    )}
                </div>

                <Properties
                    componentType={this.componentType}
                    locale={this.uiLocale}
                    onCancel={this.handleCloseProperties}
                    onConfirm={this.handleConfirmProperties}
                    open={this.propertiesOpen}
                    value={this.props.value}
                />

                <Preset.SaveDialog
                    componentType={this.componentType}
                    contentValues={this.blockContentValues}
                    locale={this.currentLocale}
                    onCancel={this.handleCloseSavePreset}
                    onSuccess={this.handleSavePresetSuccess}
                    open={this.savePresetOpen}
                    propsValues={this.props.value || {}}
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
                    blockData={this.blockContentValues || {}}
                    componentType={this.componentType}
                    locale={this.currentLocale}
                    onCancel={this.handleCloseSaveReusable}
                    onSuccess={this.handleSaveReusableSuccess}
                    open={this.saveReusableOpen}
                    propsValues={this.props.value || {}}
                />
            </div>
        );
    }
}

export default BlockProps;
