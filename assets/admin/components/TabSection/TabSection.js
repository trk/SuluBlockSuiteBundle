// @flow
import React from 'react';
import {action, observable} from 'mobx';
import {observer} from 'mobx-react';
import jexl from 'jexl';
import Grid from 'sulu-admin-bundle/components/Grid';
import Icon from 'sulu-admin-bundle/components/Icon';
import OriginalSection from 'sulu-admin-bundle/components/Form/Section';
import gridStyles from 'sulu-admin-bundle/components/Form/grid.scss';
import conditionDataProviderRegistry from 'sulu-admin-bundle/containers/Form/registries/conditionDataProviderRegistry';
import styles from './tabSection.scss';

const TAB_ICONS: {[string]: string} = {
    content: 'su-document',
    settings: 'su-brush',
    style: 'su-paint',
    layout: 'su-columns',
    general: 'su-cog',
    dynamic_content: 'su-sync',
    advanced: 'su-code',
};

let instanceCounter = 0;

function countElementErrors(nodes: any): number {
    let count = 0;
    React.Children.forEach(nodes, (child) => {
        if (!child || typeof child !== 'object') {
            return;
        }
        if (child.props) {
            if (child.props.error) {
                count += 1;
            }
            if (child.props.children) {
                count += countElementErrors(child.props.children);
            }
        }
    });
    return count;
}

function evalTabVisibleCondition(child: any): boolean {
    if (!child || !child.props || !child.props.schema) {
        return true;
    }
    const {data, formInspector, schema} = child.props;
    if (!schema.visibleCondition) {
        return true;
    }
    try {
        const conditionData = conditionDataProviderRegistry.getAll().reduce(
            (acc, provider) => ({...acc, ...provider(acc, undefined, formInspector)}),
            {...(data || {})}
        );
        return Boolean(jexl.evalSync(schema.visibleCondition, conditionData));
    } catch (e) {
        return true;
    }
}

function resolveTabIcon(tabKey: string, tabSchema: Object): string {
    if (tabSchema && tabSchema.options && tabSchema.options.icon) {
        const rawIcon = typeof tabSchema.options.icon === 'string'
            ? tabSchema.options.icon
            : tabSchema.options.icon.value;
        if (typeof rawIcon === 'string' && rawIcon.trim() !== '') {
            return rawIcon.trim();
        }
    }

    return TAB_ICONS[tabKey] || 'su-cog';
}

type Props = {
    children?: any,
    colSpan?: number,
    label?: string,
    skin?: 'light' | 'dark',
};

@observer
export default class TabSection extends React.Component<Props> {
    static defaultProps = {
        colSpan: 12,
    };

    @observable activeTab: ?string = null;
    instanceId: string;
    previousTotalErrors: number = 0;
    tabButtonRefs: {[string]: ?HTMLButtonElement} = {};

    constructor(props: Props) {
        super(props);
        instanceCounter += 1;
        this.instanceId = `bs-tabs-${instanceCounter}`;
    }

    componentDidUpdate() {
        if (this.props.label !== '__tabs') {
            return;
        }

        const tabs = this.buildTabs();
        const totalErrors = tabs.reduce((sum, tab) => sum + tab.errorCount, 0);

        if (totalErrors > this.previousTotalErrors && tabs.length > 0) {
            const currentActiveKey = (this.activeTab && tabs.some((t) => t.key === this.activeTab))
                ? this.activeTab
                : tabs[0].key;
            const activeTabObj = tabs.find((t) => t.key === currentActiveKey);
            if (!activeTabObj || activeTabObj.errorCount === 0) {
                const firstErrorTab = tabs.find((t) => t.errorCount > 0);
                if (firstErrorTab) {
                    this.handleSelectTab(firstErrorTab.key);
                }
            }
        }

        this.previousTotalErrors = totalErrors;
    }

    @action handleSelectTab = (tabKey: string) => {
        this.activeTab = tabKey;
    };

    buildTabs(): Array<Object> {
        const rawChildren = React.Children.toArray(this.props.children);
        const tabs = [];

        rawChildren.forEach((child) => {
            if (!child || typeof child !== 'object' || !child.props) {
                return;
            }

            const tabKey = child.props.name || String(child.key || '');
            if (!tabKey) {
                return;
            }

            if (!evalTabVisibleCondition(child)) {
                return;
            }

            const tabSchema = child.props.schema || {};
            const tabLabel = tabSchema.label || tabKey;
            const tabChildren = child.props.children;
            const errorCount = countElementErrors(tabChildren);
            const icon = resolveTabIcon(tabKey, tabSchema);

            tabs.push({
                key: tabKey,
                label: tabLabel,
                icon,
                errorCount,
                children: tabChildren,
            });
        });

        return tabs;
    }

    handleKeyDown = (event: SyntheticKeyboardEvent<HTMLButtonElement>, tabs: Array<Object>, currentIndex: number) => {
        if (tabs.length <= 1) {
            return;
        }

        let nextIndex = -1;
        if (event.key === 'ArrowRight') {
            nextIndex = (currentIndex + 1) % tabs.length;
        } else if (event.key === 'ArrowLeft') {
            nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
        } else if (event.key === 'Home') {
            nextIndex = 0;
        } else if (event.key === 'End') {
            nextIndex = tabs.length - 1;
        }

        if (nextIndex !== -1) {
            event.preventDefault();
            const targetTab = tabs[nextIndex];
            if (targetTab) {
                this.handleSelectTab(targetTab.key);
                const btn = this.tabButtonRefs[targetTab.key];
                if (btn && typeof btn.focus === 'function') {
                    btn.focus();
                }
            }
        }
    };

    render() {
        const {children, colSpan, label, skin} = this.props;

        // Delegate all regular sections (non-`__tabs`) directly to Sulu's original Form.Section
        if (label !== '__tabs') {
            return (
                <OriginalSection colSpan={colSpan} label={label} skin={skin}>
                    {children}
                </OriginalSection>
            );
        }

        const tabs = this.buildTabs();

        if (tabs.length === 0) {
            return null;
        }

        const currentActiveKey = (this.activeTab && tabs.some((t) => t.key === this.activeTab))
            ? this.activeTab
            : tabs[0].key;

        return (
            <Grid.Section className={gridStyles.gridSection} colSpan={colSpan || 12}>
                <Grid.Item className={styles.tabSectionItem} colSpan={12}>
                    <div className={styles.tabBar} role="tablist">
                        {tabs.map((tab, index) => {
                            const isActive = tab.key === currentActiveKey;
                            const hasError = tab.errorCount > 0;
                            const tabId = `${this.instanceId}-tab-${tab.key}`;
                            const panelId = `${this.instanceId}-panel-${tab.key}`;
                            const btnClasses = [
                                styles.tabButton,
                                isActive ? styles.tabButtonActive : '',
                                hasError ? styles.tabButtonError : '',
                            ]
                                .filter(Boolean)
                                .join(' ');

                            return (
                                <button
                                    aria-controls={panelId}
                                    aria-selected={isActive}
                                    className={btnClasses}
                                    id={tabId}
                                    key={tab.key}
                                    onClick={() => this.handleSelectTab(tab.key)}
                                    onKeyDown={(e) => this.handleKeyDown(e, tabs, index)}
                                    ref={(el) => {
                                        this.tabButtonRefs[tab.key] = el;
                                    }}
                                    role="tab"
                                    tabIndex={isActive ? 0 : -1}
                                    type="button"
                                >
                                    <span className={styles.tabIcon}>
                                        <Icon name={tab.icon} />
                                    </span>
                                    <span>{tab.label}</span>
                                    {hasError && (
                                        <span className={styles.errorBadge}>
                                            {tab.errorCount}
                                        </span>
                                    )}
                                </button>
                            );
                        })}
                    </div>

                    {tabs.map((tab) => {
                        const isActive = tab.key === currentActiveKey;
                        const tabId = `${this.instanceId}-tab-${tab.key}`;
                        const panelId = `${this.instanceId}-panel-${tab.key}`;
                        const panelClass = isActive
                            ? styles.tabPanel
                            : `${styles.tabPanel} ${styles.tabPanelHidden}`;

                        return (
                            <div
                                aria-labelledby={tabId}
                                className={panelClass}
                                id={panelId}
                                key={tab.key}
                                role="tabpanel"
                            >
                                <Grid>
                                    {React.Children.map(tab.children, (fieldChild) => {
                                        if (!fieldChild) {
                                            return null;
                                        }
                                        return React.cloneElement(fieldChild, {skin});
                                    })}
                                </Grid>
                            </div>
                        );
                    })}
                </Grid.Item>
            </Grid.Section>
        );
    }
}
