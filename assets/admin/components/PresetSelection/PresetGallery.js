// @flow
import React from 'react';
import {action, computed, observable} from 'mobx';
import {observer} from 'mobx-react';
import {Button, Icon, Input, Overlay} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import presetWireframeRegistry from '../../registries/presetWireframeRegistry';
import styles from './presetGallery.scss';

type PresetItem = {
    description?: ?string,
    id: string,
    preview_image?: ?string,
    props: Object,
    title: string,
    values?: ?Object,
};

type Props = {
    componentType: string,
    currentPresetId: ?string,
    onClose: () => void,
    onSelect: (presetId: ?string, preset: ?PresetItem) => void,
    open: boolean,
    presets: Array<PresetItem>,
};

const GenericWireframe = () => {
    return (
        <svg className={styles.wireframeSvg} viewBox="0 0 240 85">
            <rect className={styles.wireframeBg} height="66" rx="4" strokeWidth="1" width="200" x="20" y="10" />
            <rect className={styles.wireframeTitle} height="8" rx="2" width="70" x="32" y="22" />
            <rect className={styles.wireframeLinePrimary} height="5" rx="1.5" width="140" x="32" y="36" />
            <rect className={styles.wireframeLineSecondary} height="5" rx="1.5" width="110" x="32" y="46" />
            <circle className={styles.wireframeAccent} cx="196" cy="26" r="6" />
        </svg>
    );
};

const PresetWireframe = ({componentType, preset}: {componentType: string, preset: PresetItem}) => {
    if (presetWireframeRegistry.has(componentType)) {
        const RegisteredWireframe = presetWireframeRegistry.get(componentType);
        return <RegisteredWireframe componentType={componentType} preset={preset} />;
    }

    return <GenericWireframe />;
};

@observer
export default class PresetGallery extends React.Component<Props> {
    @observable searchTerm: string = '';

    @action handleSearchChange = (val: ?string) => {
        this.searchTerm = val || '';
    };

    @action handleClose = () => {
        this.searchTerm = '';
        this.props.onClose();
    };

    @computed get filteredPresets(): Array<PresetItem> {
        const {presets} = this.props;
        const list = presets ? (Array.isArray(presets) ? presets : Array.from(presets)) : [];
        if (!this.searchTerm.trim()) {
            return list;
        }

        const term = this.searchTerm.toLowerCase();
        return list.filter((p) => {
            const title = (p.title || '').toLowerCase();
            const desc = (p.description || '').toLowerCase();
            return title.includes(term) || desc.includes(term);
        });
    }

    extractBadges(preset: PresetItem): Array<{id: string, isContent?: boolean, label: string}> {
        const badges = [];
        const props = preset.props || {};

        if (props.tag) {
            badges.push({id: 'tag', label: String(props.tag).toUpperCase()});
        }
        if (props.size) {
            badges.push({id: 'size', label: String(props.size).toUpperCase()});
        }
        if (props.variant && badges.length < 3) {
            badges.push({id: 'variant', label: String(props.variant)});
        }
        if (preset.values && Object.keys(preset.values).length > 0) {
            badges.push({
                id: 'content',
                isContent: true,
                label: this.t('sulu_block_suite.includes_content', 'Includes Content'),
            });
        }

        return badges;
    }

    t(key: string, fallback: string): string {
        const translated = translate(key);
        return translated && translated !== key ? translated : fallback;
    }

    render() {
        const {componentType, currentPresetId, onSelect, open} = this.props;
        if (!open) {
            return null;
        }

        const modalTitle = this.t('sulu_block_suite.preset_gallery_title', 'Preset Style Gallery');
        const searchPlaceholder = this.t('sulu_block_suite.search_presets', 'Search presets...');
        const applyText = this.t('sulu_block_suite.apply_preset', 'Apply Preset');
        const reapplyText = this.t('sulu_block_suite.reapply_button', 'Re-apply');
        const clearPresetText = this.t('sulu_block_suite.clear_preset', 'Clear Preset');
        const activeText = this.t('sulu_block_suite.current_preset', 'Active Preset');
        const noResultsText = this.t('sulu_block_suite.no_presets_found', 'No presets found matching your query.');
        const listedText = this.t('sulu_block_suite.presets_listed', 'presets listed');

        return (
            <Overlay
                confirmDisabled={true}
                onClose={this.handleClose}
                open={open}
                size="large"
                title={`${modalTitle} (${this.filteredPresets.length})`}
            >
                <div className={styles.galleryOverlay}>
                    <div className={styles.galleryHeader}>
                        <div className={styles.searchWrapper}>
                            <Input
                                icon="su-search"
                                onChange={this.handleSearchChange}
                                placeholder={searchPlaceholder}
                                value={this.searchTerm}
                            />
                        </div>
                        <div className={styles.statsText}>
                            {this.filteredPresets.length} {listedText}
                        </div>
                    </div>

                    {this.filteredPresets.length === 0 ? (
                        <div className={styles.emptyState}>
                            <Icon name="su-search" />
                            <p>{noResultsText}</p>
                        </div>
                    ) : (
                        <div className={styles.grid}>
                            {this.filteredPresets.map((preset) => {
                                const isActive = preset.id === currentPresetId;
                                const badges = this.extractBadges(preset);

                                return (
                                    <div
                                        className={`${styles.card} ${isActive ? styles.activeCard : ''}`}
                                        key={preset.id}
                                        onClick={() => {
                                            onSelect(preset.id, preset);
                                            this.handleClose();
                                        }}
                                    >
                                        <div className={styles.previewBox}>
                                            {preset.preview_image ? (
                                                <img
                                                    alt={preset.title}
                                                    className={styles.previewImage}
                                                    src={preset.preview_image}
                                                />
                                            ) : (
                                                <PresetWireframe
                                                    componentType={componentType}
                                                    preset={preset}
                                                />
                                            )}
                                            {isActive && (
                                                <span className={styles.activeBadge}>
                                                    <Icon name="su-check" />
                                                    <span>{activeText}</span>
                                                </span>
                                            )}
                                        </div>

                                        <div className={styles.cardBody}>
                                            <h4 className={styles.cardTitle}>{preset.title}</h4>
                                            {preset.description && (
                                                <p className={styles.cardDesc}>{preset.description}</p>
                                            )}
                                            {badges.length > 0 && (
                                                <div className={styles.tagsRow}>
                                                    {badges.map((b) => (
                                                        <span
                                                            className={`${styles.tag} ${b.isContent ? styles.tagContent : ''}`}
                                                            key={b.id}
                                                        >
                                                            {b.label}
                                                        </span>
                                                    ))}
                                                </div>
                                            )}
                                        </div>

                                        <div className={styles.cardFooter}>
                                            {isActive && (
                                                <Button
                                                    icon="su-trash-alt"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        onSelect(null, null);
                                                        this.handleClose();
                                                    }}
                                                    skin="link"
                                                >
                                                    {clearPresetText}
                                                </Button>
                                            )}
                                            <Button
                                                icon={isActive ? 'su-sync' : 'su-check'}
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    onSelect(preset.id, preset);
                                                    this.handleClose();
                                                }}
                                                skin={isActive ? 'secondary' : 'primary'}
                                            >
                                                {isActive ? reapplyText : applyText}
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            </Overlay>
        );
    }
}
