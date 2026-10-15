// @flow
import React from 'react';
import {action, computed, observable} from 'mobx';
import {observer} from 'mobx-react';
import {Button, Icon, Input, Overlay} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
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
    onSelect: (presetId: string, preset: PresetItem) => void,
    open: boolean,
    presets: Array<PresetItem>,
};

const HeadlineWireframe = ({align, size, tag}: {align: string, size: string, tag: string}) => {
    const isCenter = align === 'center' || align === 'centered';
    const isRight = align === 'right';
    const isHero = size === '2xl' || size === 'xl' || tag === 'h1';

    const barWidth = isHero ? 160 : 120;
    const barHeight = isHero ? 16 : 12;
    const barX = isCenter ? (240 - barWidth) / 2 : (isRight ? 240 - barWidth - 20 : 20);

    const subWidth = isHero ? 110 : 80;
    const subX = isCenter ? (240 - subWidth) / 2 : (isRight ? 240 - subWidth - 20 : 20);

    return (
        <svg className={styles.wireframeSvg} viewBox="0 0 240 85">
            <rect fill="#e0e7ff" height={barHeight} rx="3" width={barWidth} x={barX} y="22" />
            <rect fill="#4f46e5" height={barHeight} rx="3" width={barWidth * 0.75} x={barX} y="22" />
            <rect fill="#94a3b8" height="7" rx="2" width={subWidth} x={subX} y="46" />
            <rect fill="#cbd5e1" height="5" rx="2" width={subWidth * 0.6} x={subX} y="58" />
        </svg>
    );
};

const CardWireframe = ({variant}: {variant?: string}) => {
    return (
        <svg className={styles.wireframeSvg} viewBox="0 0 240 85">
            <rect fill="#ffffff" height="72" rx="5" stroke="#cbd5e1" strokeWidth="1.5" width="200" x="20" y="6" />
            <rect fill="#e0e7ff" height="30" rx="3" width="184" x="28" y="12" />
            <rect fill="#334155" height="8" rx="2" width="90" x="28" y="48" />
            <rect fill="#94a3b8" height="5" rx="1.5" width="140" x="28" y="60" />
            <rect fill="#3b82f6" height="8" rx="2" width="36" x="176" y="66" />
        </svg>
    );
};

const ButtonWireframe = ({variant}: {variant?: string}) => {
    const isOutline = variant === 'outline';
    const isGhost = variant === 'ghost';

    return (
        <svg className={styles.wireframeSvg} viewBox="0 0 240 85">
            <rect
                fill={isGhost ? 'none' : (isOutline ? '#ffffff' : '#3b82f6')}
                height="34"
                rx="6"
                stroke={isOutline ? '#3b82f6' : (isGhost ? '#94a3b8' : 'none')}
                strokeDasharray={isGhost ? '3 3' : 'none'}
                strokeWidth={isOutline || isGhost ? '2' : '0'}
                width="140"
                x="50"
                y="26"
            />
            <circle cx="72" cy="43" fill={isOutline || isGhost ? '#3b82f6' : '#ffffff'} r="5" />
            <rect
                fill={isOutline || isGhost ? '#3b82f6' : '#ffffff'}
                height="6"
                rx="2"
                width="64"
                x="84"
                y="40"
            />
        </svg>
    );
};

const GenericWireframe = () => {
    return (
        <svg className={styles.wireframeSvg} viewBox="0 0 240 85">
            <rect fill="#f1f5f9" height="66" rx="4" stroke="#e2e8f0" strokeWidth="1" width="200" x="20" y="10" />
            <rect fill="#6366f1" height="8" rx="2" width="70" x="32" y="22" />
            <rect fill="#94a3b8" height="5" rx="1.5" width="140" x="32" y="36" />
            <rect fill="#cbd5e1" height="5" rx="1.5" width="110" x="32" y="46" />
            <circle cx="196" cy="26" fill="#3b82f6" r="6" />
        </svg>
    );
};

const PresetWireframe = ({componentType, preset}: {componentType: string, preset: PresetItem}) => {
    const props = preset.props || {};

    if (componentType === 'headline') {
        const align = String(props.text_align || '').toLowerCase();
        const size = String(props.size || '').toLowerCase();
        const tag = String(props.tag || '').toLowerCase();
        return <HeadlineWireframe align={align} size={size} tag={tag} />;
    }

    if (componentType === 'card' || componentType === 'panel') {
        const variant = String(props.variant || '').toLowerCase();
        return <CardWireframe variant={variant} />;
    }

    if (componentType === 'button') {
        const variant = String(props.variant || '').toLowerCase();
        return <ButtonWireframe variant={variant} />;
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
        if (props.text_align) {
            const alignKey = String(props.text_align).toLowerCase();
            const alignLabel = alignKey === 'center'
                ? (translate('sulu_block_suite.opt_center') || 'Center')
                : (alignKey === 'right'
                    ? (translate('sulu_block_suite.opt_right') || 'Right')
                    : (translate('sulu_block_suite.opt_left') || 'Left'));
            badges.push({id: 'align', label: alignLabel});
        }
        if (props.variant && badges.length < 3) {
            badges.push({id: 'variant', label: String(props.variant)});
        }
        if (preset.values && Object.keys(preset.values).length > 0) {
            badges.push({
                id: 'content',
                isContent: true,
                label: translate('sulu_block_suite.includes_content') || 'Includes Content',
            });
        }

        return badges;
    }

    render() {
        const {componentType, currentPresetId, onSelect, open} = this.props;
        if (!open) {
            return null;
        }

        const modalTitle = translate('sulu_block_suite.preset_gallery_title') || 'Preset Style Gallery';
        const searchPlaceholder = translate('sulu_block_suite.search_presets') || 'Search presets...';
        const applyText = translate('sulu_block_suite.apply_preset') || 'Apply Preset';
        const reapplyText = translate('sulu_block_suite.reapply_button') || 'Re-apply';
        const activeText = translate('sulu_block_suite.current_preset') || 'Active Preset';
        const noResultsText = translate('sulu_block_suite.no_presets_found') || 'No presets found matching your query.';
        const listedText = translate('sulu_block_suite.presets_listed') || 'presets listed';

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
