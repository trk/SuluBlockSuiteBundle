// @flow
import React from 'react';
import {action, observable, toJS} from 'mobx';
import {observer} from 'mobx-react';
import {
    Checkbox,
    Form,
    Icon,
    Input,
    Number as NumberField,
    SingleSelect,
} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import {Requester} from 'sulu-admin-bundle/services';
import blockPropsStyles from './blockProps.scss';

type SourceFieldItem = {
    label: string,
    name: string,
    type: string,
};

type SourceOptionItem = {
    key: string,
    label: string,
    multi_item: boolean,
};

type TargetFieldDefinition = {
    label: string,
    name: string,
    type?: string,
};

type Props = {
    componentType: ?string,
    locale: ?string,
    onChange: (dynamicConfig: ?Object) => void,
    value: ?Object,
};

const DEFAULT_FALLBACK_FIELDS: Array<TargetFieldDefinition> = [
    {name: 'title', label: 'Title'},
    {name: 'subtitle', label: 'Subtitle'},
    {name: 'content', label: 'Content'},
    {name: 'image', label: 'Image'},
    {name: 'link', label: 'Link'},
    {name: 'icon', label: 'Icon'},
];

const BASE_SOURCE_FIELDS: Array<SourceFieldItem> = [
    {name: 'title', label: 'Page Title', type: 'string'},
    {name: 'headline', label: 'Headline', type: 'string'},
    {name: 'subtitle', label: 'Subtitle', type: 'string'},
    {name: 'summary', label: 'Summary / Excerpt', type: 'text'},
    {name: 'description', label: 'Description', type: 'text'},
    {name: 'image', label: 'Featured Image', type: 'media'},
    {name: 'url', label: 'Page URL', type: 'link'},
    {name: 'link', label: 'Page Link', type: 'link'},
    {name: 'badge', label: 'Primary Tag / Badge', type: 'string'},
    {name: 'tags', label: 'Tags', type: 'array'},
    {name: 'template', label: 'Template Key', type: 'string'},
    {name: 'date', label: 'Publication Date', type: 'date'},
    {name: 'author', label: 'Author', type: 'string'},
];

const VALUE_CONDITION_OPERATORS = [
    'equals',
    'not_equals',
    'contains',
    'not_contains',
    'starts_with',
    'ends_with',
    'in',
];

@observer
class DynamicContent extends React.Component<Props> {
    @observable sourceType: string = 'none';
    @observable pageId: string = '';
    @observable limit: number = 10;
    @observable offset: number = 0;
    @observable sortBy: string = 'created';
    @observable sortOrder: string = 'desc';
    @observable templateFilter: string = '';
    @observable tagFilter: string = '';
    @observable excludeCurrent: boolean = true;
    @observable mapping: {[string]: string} = {};
    @observable conditionField: string = 'title';
    @observable conditionOperator: string = 'not_empty';
    @observable conditionValue: string = '';
    @observable hasCondition: boolean = false;
    @observable sourceFields: Array<SourceFieldItem> = [...BASE_SOURCE_FIELDS];
    @observable customSources: Array<SourceOptionItem> = [];
    @observable targetFields: Array<TargetFieldDefinition> = [...DEFAULT_FALLBACK_FIELDS];
    @observable loadingFields: boolean = false;

    constructor(props: Props) {
        super(props);
        this.initConfigFromProps(props.value);
    }

    componentDidMount() {
        this.loadAvailableSources();
        this.loadSourceFields();
        this.loadTargetFields();
    }

    componentDidUpdate(prevProps: Props) {
        const prevVal = prevProps.value;
        const curVal = this.props.value;

        if (prevVal !== curVal) {
            this.initConfigFromProps(curVal);
        }

        const prevPageId = prevVal ? prevVal.page_id : null;
        const curPageId = curVal ? curVal.page_id : null;
        if (prevPageId !== curPageId || prevProps.locale !== this.props.locale) {
            this.loadSourceFields();
        }

        if (prevProps.componentType !== this.props.componentType || prevProps.locale !== this.props.locale) {
            this.loadTargetFields();
        }
    }

    @action initConfigFromProps(val: ?Object) {
        if (!val) {
            this.sourceType = 'none';
            this.pageId = '';
            this.limit = 10;
            this.offset = 0;
            this.sortBy = 'created';
            this.sortOrder = 'desc';
            this.templateFilter = '';
            this.tagFilter = '';
            this.excludeCurrent = true;
            this.mapping = {};
            this.conditionField = 'title';
            this.conditionOperator = 'not_empty';
            this.conditionValue = '';
            this.hasCondition = false;
            return;
        }

        const raw = toJS(val);
        this.sourceType = raw.source_type || 'none';
        this.pageId = raw.page_id || raw.parent_id || '';
        this.limit = raw.limit !== undefined ? Number(raw.limit) : 10;
        this.offset = raw.offset !== undefined ? Number(raw.offset) : 0;
        this.sortBy = raw.sort_by || (raw.query && raw.query.sort_by) || 'created';
        this.sortOrder = raw.sort_order || (raw.query && raw.query.sort_order) || 'desc';
        this.templateFilter = raw.template || (raw.query && raw.query.template) || '';
        this.tagFilter = raw.tags || (raw.query && raw.query.tags) || '';
        this.excludeCurrent = raw.exclude_current !== undefined ? Boolean(raw.exclude_current) : true;
        this.mapping = raw.mapping ? {...raw.mapping} : {};

        const cond = raw.condition;
        if (cond && (cond.field || cond.operator)) {
            this.hasCondition = true;
            this.conditionField = cond.field || 'title';
            this.conditionOperator = cond.operator || 'not_empty';
            this.conditionValue = cond.value || '';
        } else {
            this.hasCondition = false;
            this.conditionField = 'title';
            this.conditionOperator = 'not_empty';
            this.conditionValue = '';
        }
    }

    @action loadAvailableSources = () => {
        const locale = this.props.locale || 'en';
        Requester.get(`/admin/api/block-suite/dynamic/sources?locale=${encodeURIComponent(locale)}`)
            .then(action((sources) => {
                if (Array.isArray(sources)) {
                    const builtinKeys = [
                        'none',
                        'current_page',
                        'parent_page',
                        'specific_page',
                        'page_children',
                        'sibling_pages',
                        'custom_query',
                    ];
                    this.customSources = sources.filter((s) => s && s.key && !builtinKeys.includes(s.key));
                }
            }))
            .catch(() => {});
    };

    @action loadSourceFields = () => {
        const pageId = this.pageId;
        const sourceType = this.sourceType || 'none';
        const locale = this.props.locale || 'en';

        let url = `/admin/api/block-suite/dynamic/source-fields?locale=${encodeURIComponent(locale)}&source_type=${encodeURIComponent(sourceType)}`;
        if (pageId && String(pageId).trim() !== '') {
            url += `&page_id=${encodeURIComponent(String(pageId).trim())}`;
        }

        this.loadingFields = true;
        Requester.get(url)
            .then(action((fields) => {
                if (Array.isArray(fields) && fields.length > 0) {
                    this.sourceFields = fields;
                } else {
                    this.sourceFields = [...BASE_SOURCE_FIELDS];
                }
                this.loadingFields = false;
            }))
            .catch(action(() => {
                this.sourceFields = [...BASE_SOURCE_FIELDS];
                this.loadingFields = false;
            }));
    };

    @action loadTargetFields = () => {
        const type = String(this.props.componentType || '').toLowerCase().trim();
        if (!type || type === 'general') {
            this.targetFields = [...DEFAULT_FALLBACK_FIELDS];
            return;
        }

        const locale = this.props.locale || 'en';
        const url = `/admin/api/block-suite/dynamic/target-fields/${encodeURIComponent(type)}?locale=${encodeURIComponent(locale)}`;

        Requester.get(url)
            .then(action((fields) => {
                if (Array.isArray(fields) && fields.length > 0) {
                    this.targetFields = fields;
                } else {
                    this.targetFields = [...DEFAULT_FALLBACK_FIELDS];
                }
            }))
            .catch(action(() => {
                this.targetFields = [...DEFAULT_FALLBACK_FIELDS];
            }));
    };

    isMultiItemSource(sourceType: string): boolean {
        if (sourceType === 'page_children' || sourceType === 'sibling_pages' || sourceType === 'custom_query') {
            return true;
        }
        const custom = this.customSources.find((s) => s.key === sourceType);
        return Boolean(custom && custom.multi_item);
    }

    syncToParent = () => {
        const sourceType = this.sourceType || 'none';
        const mapping = {...toJS(this.mapping)};

        if (sourceType === 'none' && !this.hasCondition && Object.keys(mapping).length === 0) {
            this.props.onChange(null);
            return;
        }

        const clean: Object = {
            enabled: sourceType !== 'none',
            source_type: sourceType,
            mapping,
        };

        if (this.pageId && String(this.pageId).trim() !== '') {
            clean.page_id = String(this.pageId).trim();
        }

        if (this.isMultiItemSource(sourceType)) {
            clean.limit = this.limit ? Number(this.limit) : 10;
            if (this.offset > 0) {
                clean.offset = Number(this.offset);
            }
            if (this.sortBy) {
                clean.sort_by = this.sortBy;
            }
            if (this.sortOrder) {
                clean.sort_order = this.sortOrder;
            }
            if (this.templateFilter && String(this.templateFilter).trim() !== '') {
                clean.template = String(this.templateFilter).trim();
            }
            if (this.tagFilter && String(this.tagFilter).trim() !== '') {
                clean.tags = String(this.tagFilter).trim();
            }
            if (sourceType === 'sibling_pages') {
                clean.exclude_current = Boolean(this.excludeCurrent);
            }
        }

        if (this.hasCondition && this.conditionField) {
            clean.condition = {
                field: this.conditionField,
                operator: this.conditionOperator,
                value: this.conditionValue,
            };
        }

        this.props.onChange(clean);
    };

    @action handleSourceTypeChange = (sourceType: ?string) => {
        const cleanType = sourceType || 'none';
        this.sourceType = cleanType;
        this.syncToParent();
        this.loadSourceFields();
    };

    @action handlePageIdChange = (pageId: ?string) => {
        this.pageId = pageId || '';
        this.syncToParent();
        if (this.pageId) {
            this.loadSourceFields();
        }
    };

    @action handleLimitChange = (limit: ?number) => {
        this.limit = limit ? Number(this.limit) : 10;
        this.syncToParent();
    };

    @action handleOffsetChange = (offset: ?number) => {
        this.offset = offset !== undefined && offset !== null ? Number(offset) : 0;
        this.syncToParent();
    };

    @action handleSortByChange = (sortBy: ?string) => {
        this.sortBy = sortBy || 'created';
        this.syncToParent();
    };

    @action handleSortOrderChange = (sortOrder: ?string) => {
        this.sortOrder = sortOrder || 'desc';
        this.syncToParent();
    };

    @action handleTemplateFilterChange = (template: ?string) => {
        this.templateFilter = template || '';
        this.syncToParent();
    };

    @action handleTagFilterChange = (tags: ?string) => {
        this.tagFilter = tags || '';
        this.syncToParent();
    };

    @action handleExcludeCurrentChange = (checked: boolean) => {
        this.excludeCurrent = checked;
        this.syncToParent();
    };

    @action handleMappingChange = (targetField: string, sourcePath: ?string) => {
        const next = {...toJS(this.mapping)};
        if (!sourcePath || sourcePath === '') {
            delete next[targetField];
        } else {
            next[targetField] = sourcePath;
        }
        this.mapping = next;
        this.syncToParent();
    };

    @action handleToggleCondition = (checked: boolean) => {
        this.hasCondition = checked;
        this.syncToParent();
    };

    @action handleConditionFieldChange = (field: ?string) => {
        this.conditionField = field || 'title';
        this.syncToParent();
    };

    @action handleConditionOperatorChange = (operator: ?string) => {
        this.conditionOperator = operator || 'not_empty';
        this.syncToParent();
    };

    @action handleConditionValueChange = (value: ?string) => {
        this.conditionValue = value || '';
        this.syncToParent();
    };

    render() {
        const sourceType = this.sourceType || 'none';
        const pageId = this.pageId || '';
        const limit = this.limit !== undefined ? this.limit : 10;
        const offset = this.offset !== undefined ? this.offset : 0;
        const mapping = this.mapping || {};

        const noneLabel = translate('sulu_block_suite.dynamic_source_none') || 'None (Static Manual Content)';
        const currentPageLabel = translate('sulu_block_suite.dynamic_source_current_page') || 'Current Page';
        const parentPageLabel = translate('sulu_block_suite.dynamic_source_parent_page') || 'Parent Page';
        const specificPageLabel = translate('sulu_block_suite.dynamic_source_specific_page') || 'Specific Page';
        const pageChildrenLabel = translate('sulu_block_suite.dynamic_source_page_children') || 'Child Pages (Repeater / Multi-Item)';
        const siblingPagesLabel = translate('sulu_block_suite.dynamic_source_sibling_pages') || 'Sibling Pages (Repeater / Multi-Item)';
        const customQueryLabel = translate('sulu_block_suite.dynamic_source_custom_query') || 'Smart Query (Repeater / Multi-Item)';
        const mapNoneLabel = translate('sulu_block_suite.dynamic_map_none') || '-- Static (No Dynamic Mapping) --';

        const isMultiItem = this.isMultiItemSource(sourceType);
        const needsPageId = sourceType === 'specific_page' || sourceType === 'page_children';
        const targetFields = this.targetFields;

        return (
            <div className={blockPropsStyles.dynamicContainer}>
                <div className={blockPropsStyles.dynamicNotice}>
                    <Icon name="su-magic" />
                    <span>
                        {translate('sulu_block_suite.dynamic_mapping_desc') ||
                            'Map block properties dynamically to source page fields. If empty, the manual static block value is preserved.'}
                    </span>
                </div>

                <Form>
                    <Form.Field
                        description={
                            translate('sulu_block_suite.dynamic_source_desc') ||
                            'Select where the content for this block will be pulled from.'
                        }
                        label={translate('sulu_block_suite.dynamic_source_label') || 'Dynamic Content Source'}
                        required={true}
                    >
                        <SingleSelect
                            onChange={this.handleSourceTypeChange}
                            value={sourceType}
                        >
                            <SingleSelect.Option value="none">{noneLabel}</SingleSelect.Option>
                            <SingleSelect.Option value="current_page">{currentPageLabel}</SingleSelect.Option>
                            <SingleSelect.Option value="parent_page">{parentPageLabel}</SingleSelect.Option>
                            <SingleSelect.Option value="specific_page">{specificPageLabel}</SingleSelect.Option>
                            <SingleSelect.Option value="page_children">{pageChildrenLabel}</SingleSelect.Option>
                            <SingleSelect.Option value="sibling_pages">{siblingPagesLabel}</SingleSelect.Option>
                            <SingleSelect.Option value="custom_query">{customQueryLabel}</SingleSelect.Option>
                            {this.customSources.map((source) => (
                                <SingleSelect.Option key={source.key} value={source.key}>
                                    {source.label}
                                </SingleSelect.Option>
                            ))}
                        </SingleSelect>
                    </Form.Field>

                    {needsPageId && (
                        <Form.Field
                            description={
                                sourceType === 'page_children'
                                    ? 'Optional parent page UUID. Leave empty to use the current page as parent.'
                                    : (translate('sulu_block_suite.dynamic_page_id_desc') || 'Enter the UUID or ID of the target page.')
                            }
                            label={translate('sulu_block_suite.dynamic_page_id') || 'Source Page ID / UUID'}
                            required={sourceType === 'specific_page'}
                        >
                            <Input
                                onChange={this.handlePageIdChange}
                                placeholder="e.g. 01a11348-6256-7b4e-adc2-3008ba037f03"
                                value={pageId}
                            />
                        </Form.Field>
                    )}

                    {isMultiItem && (
                        <>
                            <Form.Field
                                colSpan={6}
                                description={
                                    translate('sulu_block_suite.dynamic_limit_desc') ||
                                    'Maximum number of items to generate from the collection.'
                                }
                                label={translate('sulu_block_suite.dynamic_limit') || 'Items Limit'}
                            >
                                <NumberField
                                    max={100}
                                    min={1}
                                    onChange={this.handleLimitChange}
                                    value={limit}
                                />
                            </Form.Field>

                            <Form.Field
                                colSpan={6}
                                description="Number of initial items to skip (pagination offset)."
                                label="Offset (Skip Items)"
                            >
                                <NumberField
                                    max={100}
                                    min={0}
                                    onChange={this.handleOffsetChange}
                                    value={offset}
                                />
                            </Form.Field>

                            <Form.Field
                                colSpan={6}
                                label="Sort By"
                            >
                                <SingleSelect
                                    onChange={this.handleSortByChange}
                                    value={this.sortBy || 'created'}
                                >
                                    <SingleSelect.Option value="created">Created Date</SingleSelect.Option>
                                    <SingleSelect.Option value="changed">Last Modified Date</SingleSelect.Option>
                                    <SingleSelect.Option value="authored">Authored / Published Date</SingleSelect.Option>
                                    <SingleSelect.Option value="title">Title (Alphabetical)</SingleSelect.Option>
                                </SingleSelect>
                            </Form.Field>

                            <Form.Field
                                colSpan={6}
                                label="Sort Direction"
                            >
                                <SingleSelect
                                    onChange={this.handleSortOrderChange}
                                    value={this.sortOrder || 'desc'}
                                >
                                    <SingleSelect.Option value="desc">Descending (Newest / Z-A)</SingleSelect.Option>
                                    <SingleSelect.Option value="asc">Ascending (Oldest / A-Z)</SingleSelect.Option>
                                </SingleSelect>
                            </Form.Field>

                            <Form.Field
                                colSpan={6}
                                description="Optional comma-separated template keys (e.g. default,article)."
                                label="Filter by Template Key"
                            >
                                <Input
                                    onChange={this.handleTemplateFilterChange}
                                    placeholder="e.g. default"
                                    value={this.templateFilter}
                                />
                            </Form.Field>

                            <Form.Field
                                colSpan={6}
                                description="Optional comma-separated tag names."
                                label="Filter by Tags"
                            >
                                <Input
                                    onChange={this.handleTagFilterChange}
                                    placeholder="e.g. featured,news"
                                    value={this.tagFilter}
                                />
                            </Form.Field>

                            {sourceType === 'sibling_pages' && (
                                <Form.Field colSpan={12}>
                                    <Checkbox
                                        checked={this.excludeCurrent}
                                        onChange={this.handleExcludeCurrentChange}
                                    >
                                        Exclude current page from sibling results
                                    </Checkbox>
                                </Form.Field>
                            )}
                        </>
                    )}

                    {sourceType !== 'none' && (
                        <div className={blockPropsStyles.dynamicSection}>
                            <h4 className={blockPropsStyles.sectionTitle}>
                                <Icon name="su-exchange" />
                                <span>{translate('sulu_block_suite.dynamic_mapping_heading') || 'Field Mapping'}</span>
                            </h4>
                            <div className={blockPropsStyles.sectionHint}>
                                {translate('sulu_block_suite.dynamic_mapping_hint') ||
                                    'Select which source page field should populate each block property on the left.'}
                            </div>

                            <div className={blockPropsStyles.mappingGrid}>
                                {targetFields.map((target) => {
                                    const currentMapping = mapping[target.name] || '';
                                    return (
                                        <div className={blockPropsStyles.mappingRow} key={target.name}>
                                            <div className={blockPropsStyles.mappingTargetLabel}>
                                                <strong>{target.label}</strong>
                                                <code>block.{target.name}</code>
                                            </div>
                                            <div className={blockPropsStyles.mappingArrow}>
                                                <Icon name="su-angle-right" />
                                            </div>
                                            <div className={blockPropsStyles.mappingSelectWrapper}>
                                                <SingleSelect
                                                    onChange={(val) => this.handleMappingChange(target.name, val)}
                                                    value={currentMapping}
                                                >
                                                    <SingleSelect.Option value="">{mapNoneLabel}</SingleSelect.Option>
                                                    {this.sourceFields.map((field) => (
                                                        <SingleSelect.Option key={field.name} value={field.name}>
                                                            {field.label + ' (' + field.name + ')'}
                                                        </SingleSelect.Option>
                                                    ))}
                                                </SingleSelect>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {sourceType !== 'none' && (
                        <div className={blockPropsStyles.dynamicSection}>
                            <h4 className={blockPropsStyles.sectionTitle}>
                                <Icon name="su-filter" />
                                <span>{translate('sulu_block_suite.dynamic_conditions_heading') || 'Display Conditions'}</span>
                            </h4>

                            <div className={blockPropsStyles.conditionWrapper}>
                                <Checkbox
                                    checked={this.hasCondition}
                                    onChange={this.handleToggleCondition}
                                >
                                    {translate('sulu_block_suite.dynamic_condition_enable') ||
                                        'Enable Display Condition (Hide block when condition is not met)'}
                                </Checkbox>

                                {this.hasCondition && (
                                    <div className={blockPropsStyles.conditionFields}>
                                        <div className={blockPropsStyles.conditionCol}>
                                            <label className={blockPropsStyles.smallLabel}>
                                                {translate('sulu_block_suite.dynamic_condition_field') || 'Condition Field'}
                                            </label>
                                            <SingleSelect
                                                onChange={this.handleConditionFieldChange}
                                                value={this.conditionField || 'title'}
                                            >
                                                {this.sourceFields.map((field) => (
                                                    <SingleSelect.Option key={field.name} value={field.name}>
                                                        {field.label + ' (' + field.name + ')'}
                                                    </SingleSelect.Option>
                                                ))}
                                            </SingleSelect>
                                        </div>

                                        <div className={blockPropsStyles.conditionCol}>
                                            <label className={blockPropsStyles.smallLabel}>
                                                {translate('sulu_block_suite.dynamic_condition_operator') || 'Condition Operator'}
                                            </label>
                                            <SingleSelect
                                                onChange={this.handleConditionOperatorChange}
                                                value={this.conditionOperator || 'not_empty'}
                                            >
                                                <SingleSelect.Option value="not_empty">
                                                    {translate('sulu_block_suite.dynamic_op_not_empty') || 'Is Not Empty'}
                                                </SingleSelect.Option>
                                                <SingleSelect.Option value="is_empty">
                                                    {translate('sulu_block_suite.dynamic_op_is_empty') || 'Is Empty'}
                                                </SingleSelect.Option>
                                                <SingleSelect.Option value="equals">
                                                    {translate('sulu_block_suite.dynamic_op_equals') || 'Equals'}
                                                </SingleSelect.Option>
                                                <SingleSelect.Option value="not_equals">
                                                    {translate('sulu_block_suite.dynamic_op_not_equals') || 'Does Not Equal'}
                                                </SingleSelect.Option>
                                                <SingleSelect.Option value="contains">
                                                    Contains
                                                </SingleSelect.Option>
                                                <SingleSelect.Option value="not_contains">
                                                    Does Not Contain
                                                </SingleSelect.Option>
                                                <SingleSelect.Option value="starts_with">
                                                    Starts With
                                                </SingleSelect.Option>
                                                <SingleSelect.Option value="ends_with">
                                                    Ends With
                                                </SingleSelect.Option>
                                                <SingleSelect.Option value="in">
                                                    In List (Comma-Separated)
                                                </SingleSelect.Option>
                                            </SingleSelect>
                                        </div>

                                        {VALUE_CONDITION_OPERATORS.includes(this.conditionOperator) && (
                                            <div className={blockPropsStyles.conditionCol}>
                                                <label className={blockPropsStyles.smallLabel}>
                                                    {translate('sulu_block_suite.dynamic_condition_value') || 'Expected Value'}
                                                </label>
                                                <Input
                                                    onChange={this.handleConditionValueChange}
                                                    value={this.conditionValue || ''}
                                                />
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    )}
                </Form>
            </div>
        );
    }
}

export default DynamicContent;
