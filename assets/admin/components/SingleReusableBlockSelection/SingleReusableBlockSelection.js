// @flow
import React, {Fragment} from 'react';
import {action, computed, observable, toJS} from 'mobx';
import {observer} from 'mobx-react';
import Button from 'sulu-admin-bundle/components/Button';
import Overlay from 'sulu-admin-bundle/components/Overlay';
import Icon from 'sulu-admin-bundle/components/Icon';
import Input from 'sulu-admin-bundle/components/Input';
import userStore from 'sulu-admin-bundle/stores/userStore';
import {Requester} from 'sulu-admin-bundle/services';
import {translate} from 'sulu-admin-bundle/utils';
import type {FieldTypeProps} from 'sulu-admin-bundle/containers/Form/types';
import reusableBlockStore from '../../stores/reusableBlockStore';
import styles from './singleReusableBlockSelection.scss';

type Props = FieldTypeProps<?string>;

@observer
export default class SingleReusableBlockSelection extends React.Component<Props> {
    @observable overlayOpen: boolean = false;
    @observable selectedSnippet: ?Object = null;
    @observable usageData: ?Object = null;
    @observable availableBlocks: Array<Object> = [];
    @observable blocksLoading: boolean = false;
    @observable searchTerm: string = '';
    @observable statsMap: {[string]: number} = {};
    lastLoadedId: ?string = null;
    lastLoadedLocale: ?string = null;

    componentDidMount() {
        if (this.props.value) {
            this.lastLoadedLocale = this.currentLocale;
            this.loadSelectedSnippet(this.props.value);
            this.loadUsage(this.props.value);
        }
    }

    componentDidUpdate(prevProps: Props) {
        const currentLocale = this.currentLocale;
        if (this.props.value !== prevProps.value || (this.props.value && currentLocale !== this.lastLoadedLocale)) {
            if (this.props.value && (this.props.value !== this.lastLoadedId || currentLocale !== this.lastLoadedLocale)) {
                this.lastLoadedLocale = currentLocale;
                this.loadSelectedSnippet(this.props.value);
                this.loadUsage(this.props.value);
            } else if (!this.props.value) {
                action(() => {
                    this.selectedSnippet = null;
                    this.usageData = null;
                    this.lastLoadedId = null;
                    this.lastLoadedLocale = null;
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

    @computed get t(): Object {
        const tr = (key: string, fallback: string): string => {
            const val = translate(key);
            return val && val !== key ? val : fallback;
        };

        return {
            selectBlock: tr('sulu_block_suite.select_reusable_block', 'Select Reusable Block'),
            changeBlock: tr('sulu_block_suite.change_reusable_block', 'Change'),
            noBlockSelected: tr('sulu_block_suite.no_reusable_block_selected', 'No Reusable Block Selected'),
            selectPrompt: tr('sulu_block_suite.reusable_block_select_prompt', 'Choose a shared reusable block to display here.'),
            innerBlocks: tr('sulu_block_suite.reusable_block_inner_blocks', 'blocks'),
            usedIn: tr('sulu_block_suite.reusable_block_used_in', 'Used in'),
            resources: tr('sulu_block_suite.reusable_block_resources', 'resources'),
            notInUse: tr('sulu_block_suite.reusable_block_not_in_use', 'Not currently in use'),
            editMaster: tr('sulu_block_suite.open_in_new_tab', 'Edit in New Tab'),
            modalTitle: tr('sulu_block_suite.reusable_block_modal_title', 'Select Reusable Block'),
            searchPlaceholder: tr('sulu_block_suite.reusable_block_search_placeholder', 'Search reusable blocks...'),
            noResults: tr('sulu_block_suite.reusable_block_no_results', 'No reusable blocks found matching your query.'),
            loading: tr('sulu_block_suite.reusable_block_loading', 'Loading reusable blocks...'),
        };
    }

    loadSelectedSnippet = (id: string) => {
        this.lastLoadedId = id;
        Requester.get(`/admin/api/reusable-blocks/${encodeURIComponent(id)}?locale=${encodeURIComponent(this.currentLocale)}`)
            .then(action((data) => {
                if (data && !data.error) {
                    this.selectedSnippet = data;
                    reusableBlockStore.set(id, data, this.currentLocale);
                } else {
                    this.selectedSnippet = null;
                }
            }))
            .catch(action(() => {
                this.selectedSnippet = null;
            }));
    };

    loadUsage = (id: string) => {
        Requester.get(`/admin/api/reusable-blocks/${encodeURIComponent(id)}/usage`)
            .then(action((data) => {
                if (data && !data.error) {
                    this.usageData = data;
                } else {
                    this.usageData = null;
                }
            }))
            .catch(action(() => {
                this.usageData = null;
            }));
    };

    loadAvailableBlocks = () => {
        action(() => {
            this.blocksLoading = true;
        })();
        const webspace = this.currentWebspace;
        const webspaceQuery = webspace ? `&webspace=${encodeURIComponent(webspace)}` : '';
        Requester.get(`/admin/api/reusable-blocks?locale=${encodeURIComponent(this.currentLocale)}${webspaceQuery}`)
            .then(action((data) => {
                const items = (data && data._embedded && data._embedded.snippets) || (data && data.snippets) || [];
                this.availableBlocks = items;
                this.blocksLoading = false;

                const ids = items.map((item) => item.id).filter(Boolean);
                if (ids.length > 0) {
                    this.loadBatchStats(ids);
                }
            }))
            .catch(action(() => {
                this.availableBlocks = [];
                this.blocksLoading = false;
            }));
    };

    @action loadBatchStats = (ids: Array<string>) => {
        Requester.get(`/admin/api/reusable-blocks/stats?ids=${encodeURIComponent(ids.join(','))}`)
            .then(action((data) => {
                if (data && data.counts) {
                    this.statsMap = data.counts;
                }
            }))
            .catch(() => {});
    };

    @action handleOpenOverlay = () => {
        this.overlayOpen = true;
        this.searchTerm = '';
        this.loadAvailableBlocks();
    };

    @action handleCloseOverlay = () => {
        this.overlayOpen = false;
        this.searchTerm = '';
    };

    @action handleSearchChange = (term: ?string) => {
        this.searchTerm = term || '';
    };

    @action handleSelectBlock = (snippetId: string) => {
        const {onChange, onFinish} = this.props;
        const block = this.availableBlocks.find((b) => b.id === snippetId);
        if (block) {
            reusableBlockStore.set(snippetId, block, this.currentLocale);
        }
        if (onChange) {
            onChange(snippetId);
        }
        if (onFinish) {
            onFinish();
        }
        this.loadSelectedSnippet(snippetId);
        this.loadUsage(snippetId);
        this.handleCloseOverlay();
    };

    @action handleRemoveBlock = () => {
        const {onChange, onFinish} = this.props;
        if (onChange) {
            onChange(undefined);
        }
        if (onFinish) {
            onFinish();
        }
        this.selectedSnippet = null;
        this.usageData = null;
        this.lastLoadedId = null;
    };

    handleOpenMaster = () => {
        const id = this.props.value;
        if (id) {
            const adminBase = window.location.pathname.replace(/\/$/, '');
            const locale = encodeURIComponent(this.currentLocale);
            const snippetId = encodeURIComponent(id);
            window.open(`${adminBase}/#/block-suite/reusable/${locale}/${snippetId}/content`, '_blank');
        }
    };

    @computed get filteredBlocks(): Array<Object> {
        if (!this.searchTerm) {
            return this.availableBlocks;
        }
        const term = this.searchTerm.toLowerCase();
        return this.availableBlocks.filter((item) => {
            const title = (item.title || '').toLowerCase();
            const desc = (item.description || '').toLowerCase();
            return title.includes(term) || desc.includes(term);
        });
    }

    renderEmptyState() {
        const {t} = this;
        const {disabled} = this.props;

        return (
            <div className={styles.emptyState}>
                <div className={styles.emptyIcon}>
                    <Icon name="su-brush" />
                </div>
                <div className={styles.emptyTitle}>{t.noBlockSelected}</div>
                <div className={styles.emptyPrompt}>{t.selectPrompt}</div>
                <Button
                    disabled={!!disabled}
                    icon="su-plus"
                    onClick={this.handleOpenOverlay}
                    skin="primary"
                >
                    {t.selectBlock}
                </Button>
            </div>
        );
    }

    renderSelectedCard() {
        const {t} = this;
        const {disabled} = this.props;
        const snippet = this.selectedSnippet;
        const usage = this.usageData;

        if (!snippet) {
            return this.renderEmptyState();
        }

        const usageCount = usage ? usage.usage_count : (this.statsMap[snippet.id] || 0);
        const hasUsage = usageCount > 0;

        return (
            <div className={styles.selectedCard}>
                <div className={styles.cardMain}>
                    <div className={styles.thumbnailWrapper}>
                        {snippet.preview_image ? (
                            <img
                                alt={snippet.title}
                                className={styles.thumbnailImage}
                                src={snippet.preview_image}
                            />
                        ) : (
                            <div className={styles.thumbnailPlaceholder}>
                                <Icon name="su-brush" />
                            </div>
                        )}
                    </div>

                    <div className={styles.cardContent}>
                        <div className={styles.cardHeader}>
                            <h4 className={styles.cardTitle} title={snippet.title}>
                                {snippet.title}
                            </h4>
                            <div className={styles.headerActions}>
                                <Button
                                    disabled={!!disabled}
                                    icon="su-pen"
                                    onClick={this.handleOpenOverlay}
                                    size="small"
                                    skin="secondary"
                                >
                                    {t.changeBlock}
                                </Button>
                                <Button
                                    className={styles.deleteButton}
                                    disabled={!!disabled}
                                    icon="su-trash-alt"
                                    onClick={this.handleRemoveBlock}
                                    size="small"
                                    skin="link"
                                />
                            </div>
                        </div>

                        {snippet.description && (
                            <p className={styles.cardDescription} title={snippet.description}>
                                {snippet.description}
                            </p>
                        )}

                        <div className={styles.cardFooter}>
                            <div className={styles.badgesGroup}>
                                {typeof snippet.block_count === 'number' && (
                                    <span className={styles.badge}>
                                        <Icon name="su-columns" />
                                        {snippet.block_count} {t.innerBlocks}
                                    </span>
                                )}
                                <span className={hasUsage ? styles.badgeInUse : styles.badgeUnused}>
                                    <Icon name={hasUsage ? 'su-check' : 'su-tag'} />
                                    {hasUsage
                                        ? `${t.usedIn} ${usageCount} ${t.resources}`
                                        : t.notInUse}
                                </span>
                            </div>

                            <div className={styles.externalLinkAction}>
                                <Button
                                    icon="su-link"
                                    onClick={this.handleOpenMaster}
                                    size="small"
                                    skin="link"
                                >
                                    {t.editMaster}
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    renderOverlay() {
        const {t} = this;
        if (!this.overlayOpen) {
            return null;
        }

        return (
            <Overlay
                confirmDisabled={true}
                onClose={this.handleCloseOverlay}
                open={this.overlayOpen}
                size="large"
                title={t.modalTitle}
            >
                <div className={styles.overlayContent}>
                    <div className={styles.searchBar}>
                        <Input
                            icon="su-search"
                            onChange={this.handleSearchChange}
                            placeholder={t.searchPlaceholder}
                            value={this.searchTerm}
                        />
                    </div>

                    {this.blocksLoading ? (
                        <div className={styles.noResultsMessage}>{t.loading}</div>
                    ) : this.filteredBlocks.length === 0 ? (
                        <div className={styles.noResultsMessage}>{t.noResults}</div>
                    ) : (
                        <div className={styles.blockListGrid}>
                            {this.filteredBlocks.map((block) => {
                                const usageCount = this.statsMap[block.id] || 0;
                                const hasUsage = usageCount > 0;

                                return (
                                    <div
                                        className={styles.blockGridItem}
                                        key={block.id}
                                        onClick={() => this.handleSelectBlock(block.id)}
                                    >
                                        <div className={styles.gridItemThumb}>
                                            {block.preview_image ? (
                                                <img alt={block.title} src={block.preview_image} />
                                            ) : (
                                                <Icon name="su-brush" />
                                            )}
                                        </div>
                                        <div className={styles.gridItemBody}>
                                            <h5 className={styles.gridItemTitle}>{block.title}</h5>
                                            {block.description && (
                                                <p className={styles.gridItemDesc}>{block.description}</p>
                                            )}
                                            <div className={styles.gridItemFooter}>
                                                <span className={hasUsage ? styles.badgeInUse : styles.badgeUnused}>
                                                    {hasUsage ? `${usageCount} ${t.resources}` : t.notInUse}
                                                </span>
                                                <Button
                                                    icon="su-check"
                                                    onClick={() => this.handleSelectBlock(block.id)}
                                                    skin="primary"
                                                >
                                                    {t.selectBlock}
                                                </Button>
                                            </div>
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

    render() {
        const hasValue = Boolean(this.props.value);

        return (
            <div className={styles.selectionContainer}>
                {hasValue ? this.renderSelectedCard() : this.renderEmptyState()}
                {this.renderOverlay()}
            </div>
        );
    }
}
