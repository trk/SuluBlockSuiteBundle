// @flow
import React from 'react';
import {action, computed, observable, toJS} from 'mobx';
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
import SuluNativeField from './SuluNativeField';
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
    contentLocale?: ?string,
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
    @observable pageId: ?(string | number) = null;
    @observable selectedIds: Array<string | number> = [];
    @observable categoryIds: Array<number> = [];
    @observable tagIds: Array<number> = [];
    @observable limit: number = 10;
    @observable offset: number = 0;
    @observable sortBy: string = 'created';
    @observable sortOrder: string = 'desc';
    @observable templateFilter: string = '';
    @observable excludeCurrent: boolean = true;
    @observable mapping: {[string]: string} = {};
    @observable conditionField: string = 'title';
    @observable conditionOperator: string = 'not_empty';
    @observable conditionValue: string = '';
    @observable hasCondition: boolean = false;
    @observable sourceFields: Array<SourceFieldItem> = [...BASE_SOURCE_FIELDS];
    @observable availableSources: Array<SourceOptionItem> = [];
    @observable isMultipleBlock: boolean = false;
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
        if (prevPageId !== curPageId || prevProps.locale !== this.props.locale || prevProps.contentLocale !== this.props.contentLocale) {
            this.loadSourceFields();
        }

        if (prevProps.componentType !== this.props.componentType || prevProps.locale !== this.props.locale) {
            this.loadTargetFields();
        }
    }

    @action initConfigFromProps(val: ?Object) {
        if (!val) {
            this.sourceType = 'none';
            this.pageId = null;
            this.selectedIds = [];
            this.categoryIds = [];
            this.tagIds = [];
            this.limit = 10;
            this.offset = 0;
            this.sortBy = 'created';
            this.sortOrder = 'desc';
            this.templateFilter = '';
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

        const rawTargetId = raw.page_id ?? raw.article_id ?? raw.category_id ?? raw.media_id ?? raw.parent_id ?? raw.collection_id ?? null;
        this.pageId = rawTargetId !== '' && rawTargetId !== undefined ? rawTargetId : null;

        const rawUuids = raw.uuids || raw.ids || raw.page_ids || raw.article_ids || [];
        this.selectedIds = Array.isArray(rawUuids) ? rawUuids : [];

        const rawCategories = raw.category_ids || raw.categories || (raw.query && (raw.query.category_ids || raw.query.categories)) || [];
        this.categoryIds = Array.isArray(rawCategories)
            ? rawCategories.map((id) => Number(id)).filter((id) => !isNaN(id))
            : [];

        const rawTags = raw.tag_ids || raw.tags || (raw.query && (raw.query.tag_ids || raw.query.tags)) || [];
        this.tagIds = Array.isArray(rawTags)
            ? rawTags.map((id) => Number(id)).filter((id) => !isNaN(id))
            : [];

        this.limit = raw.limit !== undefined ? Number(raw.limit) : 10;
        this.offset = raw.offset !== undefined ? Number(raw.offset) : 0;
        this.sortBy = raw.sort_by || (raw.query && raw.query.sort_by) || 'created';
        this.sortOrder = raw.sort_order || (raw.query && raw.query.sort_order) || 'desc';
        this.templateFilter = raw.template || (raw.query && raw.query.template) || '';
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
                    this.availableSources = sources.filter((s) => s && s.key);
                }
            }))
            .catch(() => {});
    };

    @action loadSourceFields = () => {
        const pageId = this.pageId;
        const sourceType = this.sourceType || 'none';
        const locale = this.props.contentLocale || this.props.locale || 'en';

        let url = `/admin/api/block-suite/dynamic/source-fields?locale=${encodeURIComponent(locale)}&source_type=${encodeURIComponent(sourceType)}`;
        if (pageId !== null && pageId !== undefined && String(pageId).trim() !== '') {
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
            this.isMultipleBlock = false;
            this.targetFields = [...DEFAULT_FALLBACK_FIELDS];
            return;
        }

        const locale = this.props.locale || 'en';
        const url = `/admin/api/block-suite/dynamic/target-fields/${encodeURIComponent(type)}?locale=${encodeURIComponent(locale)}`;

        Requester.get(url)
            .then(action((response) => {
                if (response && typeof response === 'object' && Array.isArray(response.fields)) {
                    this.isMultipleBlock = Boolean(response.multiple);
                    this.targetFields = response.fields.length > 0
                        ? response.fields
                        : [...DEFAULT_FALLBACK_FIELDS];
                } else {
                    this.isMultipleBlock = false;
                    this.targetFields = [...DEFAULT_FALLBACK_FIELDS];
                }
            }))
            .catch(action(() => {
                this.isMultipleBlock = false;
                this.targetFields = [...DEFAULT_FALLBACK_FIELDS];
            }));
    };

    @computed get filteredSourceOptions(): Array<SourceOptionItem> {
        if (this.availableSources.length === 0) {
            return [];
        }

        return this.availableSources.filter((source) => {
            if (source.key === 'none') {
                return true;
            }
            if (source.key === this.sourceType) {
                return true;
            }
            return Boolean(source.multi_item) === this.isMultipleBlock;
        });
    }

    isMultiItemSource(sourceType: string): boolean {
        const matched = this.availableSources.find((s) => s.key === sourceType);
        if (matched) {
            return Boolean(matched.multi_item);
        }
        return this.isMultipleBlock;
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

        if (this.pageId !== null && this.pageId !== undefined && String(this.pageId).trim() !== '') {
            clean.page_id = this.pageId;
            if (sourceType === 'single_article') {
                clean.article_id = String(this.pageId);
            } else if (sourceType === 'single_category') {
                clean.category_id = Number(this.pageId);
            } else if (sourceType === 'sulu_categories') {
                clean.parent_id = Number(this.pageId);
            } else if (sourceType === 'single_media') {
                clean.media_id = Number(this.pageId);
            } else if (sourceType === 'sulu_media_collection') {
                clean.collection_id = Number(this.pageId);
            }
        }

        if (this.selectedIds.length > 0 && (sourceType === 'page_selection' || sourceType === 'sulu_articles')) {
            clean.uuids = toJS(this.selectedIds);
            if (sourceType === 'sulu_articles') {
                clean.article_ids = toJS(this.selectedIds);
            }
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
            if (this.tagIds.length > 0) {
                clean.tag_ids = toJS(this.tagIds);
            }
            if (this.categoryIds.length > 0) {
                clean.category_ids = toJS(this.categoryIds);
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
        if (this.sourceType !== cleanType) {
            this.pageId = null;
            this.selectedIds = [];
        }
        this.sourceType = cleanType;
        this.syncToParent();
        this.loadSourceFields();
    };

    @action handlePageIdChange = (pageId: any) => {
        this.pageId = pageId !== undefined && pageId !== '' ? pageId : null;
        this.syncToParent();
        if (this.pageId !== null) {
            this.loadSourceFields();
        }
    };

    @action handleSelectedIdsChange = (ids: Array<string | number>) => {
        this.selectedIds = Array.isArray(ids) ? ids : [];
        this.syncToParent();
    };

    @action handleCategoryIdsChange = (ids: Array<number>) => {
        this.categoryIds = Array.isArray(ids)
            ? ids.map((id) => Number(id)).filter((id) => !isNaN(id))
            : [];
        this.syncToParent();
    };

    @action handleTagIdsChange = (ids: Array<number>) => {
        this.tagIds = Array.isArray(ids)
            ? ids.map((id) => Number(id)).filter((id) => !isNaN(id))
            : [];
        this.syncToParent();
    };

    @action handleLimitChange = (limit: ?number) => {
        this.limit = limit ? Number(limit) : 10;
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
        const limit = this.limit !== undefined ? this.limit : 10;
        const offset = this.offset !== undefined ? this.offset : 0;
        const mapping = this.mapping || {};
        const contentLocale = this.props.contentLocale || this.props.locale || 'en';

        const noneLabel = translate('sulu_block_suite.dynamic_source_none') || 'None (Static Manual Content)';
        const mapNoneLabel = translate('sulu_block_suite.dynamic_map_none') || '-- Static (No Dynamic Mapping) --';

        const isMultiItem = this.isMultiItemSource(sourceType);
        const needsSinglePageSelection = sourceType === 'specific_page' || sourceType === 'page_children';
        const needsMultiPageSelection = sourceType === 'page_selection';
        const needsSingleArticleSelection = sourceType === 'single_article';
        const needsMultiArticleSelection = sourceType === 'sulu_articles';
        const needsSingleCategorySelection = sourceType === 'single_category' || sourceType === 'sulu_categories';
        const needsSingleMediaSelection = sourceType === 'single_media';
        const needsSingleCollectionSelection = sourceType === 'sulu_media_collection';
        const supportsCategoryAndTagFilters =
            sourceType === 'page_children' ||
            sourceType === 'sibling_pages' ||
            sourceType === 'custom_query' ||
            sourceType === 'sulu_articles';
        const targetFields = this.targetFields;

        return (
            <div className={blockPropsStyles.dynamicContainer}>
                <div className={blockPropsStyles.dynamicNotice}>
                    <Icon name="su-magic" />
                    <span>
                        {translate('sulu_block_suite.dynamic_mapping_desc') ||
                            'Map block properties dynamically to source fields. If empty, the manual static block value is preserved.'}
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
                            {this.filteredSourceOptions.length === 0 && (
                                <SingleSelect.Option value="none">{noneLabel}</SingleSelect.Option>
                            )}
                            {this.filteredSourceOptions.map((source) => (
                                <SingleSelect.Option key={source.key} value={source.key}>
                                    {source.label}
                                </SingleSelect.Option>
                            ))}
                        </SingleSelect>
                    </Form.Field>

                    {needsSinglePageSelection && (
                        <Form.Field
                            description={
                                sourceType === 'page_children'
                                    ? (translate('sulu_block_suite.dynamic_parent_page_id_desc') ||
                                        'Optional parent page. Leave empty to use the current page as parent.')
                                    : (translate('sulu_block_suite.dynamic_page_id_desc') || 'Select the target page.')
                            }
                            label={translate('sulu_block_suite.dynamic_page_id') || 'Source Page'}
                            required={sourceType === 'specific_page'}
                        >
                            <SuluNativeField
                                fieldType="single_page_selection"
                                locale={contentLocale}
                                onChange={this.handlePageIdChange}
                                value={this.pageId}
                            />
                        </Form.Field>
                    )}

                    {needsMultiPageSelection && (
                        <Form.Field
                            description={
                                translate('sulu_block_suite.dynamic_page_selection_desc') ||
                                'Select the pages to populate this collection block.'
                            }
                            label={translate('sulu_block_suite.dynamic_page_selection') || 'Selected Pages'}
                            required={true}
                        >
                            <SuluNativeField
                                fieldType="page_selection"
                                locale={contentLocale}
                                onChange={this.handleSelectedIdsChange}
                                value={this.selectedIds}
                            />
                        </Form.Field>
                    )}

                    {needsSingleArticleSelection && (
                        <Form.Field
                            description={
                                translate('sulu_block_suite.dynamic_single_article_desc') ||
                                'Select the target Sulu article.'
                            }
                            label={translate('sulu_block_suite.dynamic_single_article') || 'Source Article'}
                            required={true}
                        >
                            <SuluNativeField
                                fieldType="single_article_selection"
                                locale={contentLocale}
                                onChange={this.handlePageIdChange}
                                value={this.pageId}
                            />
                        </Form.Field>
                    )}

                    {needsMultiArticleSelection && (
                        <Form.Field
                            description={
                                translate('sulu_block_suite.dynamic_article_selection_desc') ||
                                'Optional specific articles. Leave empty to query latest articles dynamically.'
                            }
                            label={translate('sulu_block_suite.dynamic_article_selection') || 'Specific Articles (Optional)'}
                        >
                            <SuluNativeField
                                fieldType="article_selection"
                                locale={contentLocale}
                                onChange={this.handleSelectedIdsChange}
                                value={this.selectedIds}
                            />
                        </Form.Field>
                    )}

                    {needsSingleCategorySelection && (
                        <Form.Field
                            description={
                                sourceType === 'sulu_categories'
                                    ? (translate('sulu_block_suite.dynamic_parent_category_desc') ||
                                        'Optional parent category to fetch child categories from.')
                                    : (translate('sulu_block_suite.dynamic_single_category_desc') || 'Select the target category.')
                            }
                            label={
                                sourceType === 'sulu_categories'
                                    ? (translate('sulu_block_suite.dynamic_parent_category') || 'Parent Category')
                                    : (translate('sulu_block_suite.dynamic_single_category') || 'Source Category')
                            }
                            required={sourceType === 'single_category'}
                        >
                            <SuluNativeField
                                fieldType="single_category_selection"
                                locale={contentLocale}
                                onChange={this.handlePageIdChange}
                                value={this.pageId}
                            />
                        </Form.Field>
                    )}

                    {needsSingleMediaSelection && (
                        <Form.Field
                            description={
                                translate('sulu_block_suite.dynamic_single_media_desc') ||
                                'Select the target Sulu media asset.'
                            }
                            label={translate('sulu_block_suite.dynamic_single_media') || 'Source Media'}
                            required={true}
                        >
                            <SuluNativeField
                                fieldType="single_media_selection"
                                locale={contentLocale}
                                onChange={this.handlePageIdChange}
                                value={this.pageId}
                            />
                        </Form.Field>
                    )}

                    {needsSingleCollectionSelection && (
                        <Form.Field
                            description={
                                translate('sulu_block_suite.dynamic_collection_id_desc') ||
                                'Optional Sulu Media Collection. Leave empty to fetch latest media assets.'
                            }
                            label={translate('sulu_block_suite.dynamic_collection_id') || 'Media Collection'}
                        >
                            <SuluNativeField
                                fieldType="single_collection_selection"
                                locale={contentLocale}
                                onChange={this.handlePageIdChange}
                                value={this.pageId}
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
                                description={
                                    translate('sulu_block_suite.dynamic_offset_desc') ||
                                    'Number of initial items to skip (pagination offset).'
                                }
                                label={translate('sulu_block_suite.dynamic_offset') || 'Offset (Skip Items)'}
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
                                label={translate('sulu_block_suite.dynamic_sort_by') || 'Sort By'}
                            >
                                <SingleSelect
                                    onChange={this.handleSortByChange}
                                    value={this.sortBy || 'created'}
                                >
                                    <SingleSelect.Option value="created">
                                        {translate('sulu_block_suite.dynamic_sort_created') || 'Created Date'}
                                    </SingleSelect.Option>
                                    <SingleSelect.Option value="changed">
                                        {translate('sulu_block_suite.dynamic_sort_changed') || 'Last Modified Date'}
                                    </SingleSelect.Option>
                                    <SingleSelect.Option value="authored">
                                        {translate('sulu_block_suite.dynamic_sort_authored') || 'Authored / Published Date'}
                                    </SingleSelect.Option>
                                    <SingleSelect.Option value="title">
                                        {translate('sulu_block_suite.dynamic_sort_title') || 'Title (Alphabetical)'}
                                    </SingleSelect.Option>
                                </SingleSelect>
                            </Form.Field>

                            <Form.Field
                                colSpan={6}
                                label={translate('sulu_block_suite.dynamic_sort_order') || 'Sort Direction'}
                            >
                                <SingleSelect
                                    onChange={this.handleSortOrderChange}
                                    value={this.sortOrder || 'desc'}
                                >
                                    <SingleSelect.Option value="desc">
                                        {translate('sulu_block_suite.dynamic_sort_desc') || 'Descending (Newest / Z-A)'}
                                    </SingleSelect.Option>
                                    <SingleSelect.Option value="asc">
                                        {translate('sulu_block_suite.dynamic_sort_asc') || 'Ascending (Oldest / A-Z)'}
                                    </SingleSelect.Option>
                                </SingleSelect>
                            </Form.Field>

                            {supportsCategoryAndTagFilters && (
                                <>
                                    <Form.Field
                                        colSpan={4}
                                        description={
                                            translate('sulu_block_suite.dynamic_filter_template_desc') ||
                                            'Optional comma-separated template keys (e.g. default,article).'
                                        }
                                        label={translate('sulu_block_suite.dynamic_filter_template') || 'Filter by Template Key'}
                                    >
                                        <Input
                                            onChange={this.handleTemplateFilterChange}
                                            placeholder="e.g. default"
                                            value={this.templateFilter}
                                        />
                                    </Form.Field>

                                    <Form.Field
                                        colSpan={4}
                                        description={
                                            translate('sulu_block_suite.dynamic_filter_tags_desc') ||
                                            'Optional tags to filter items.'
                                        }
                                        label={translate('sulu_block_suite.dynamic_filter_tags') || 'Filter by Tags'}
                                    >
                                        <SuluNativeField
                                            fieldType="tag_selection"
                                            locale={contentLocale}
                                            onChange={this.handleTagIdsChange}
                                            value={this.tagIds}
                                        />
                                    </Form.Field>

                                    <Form.Field
                                        colSpan={4}
                                        description={
                                            translate('sulu_block_suite.dynamic_filter_categories_desc') ||
                                            'Optional categories to filter items.'
                                        }
                                        label={translate('sulu_block_suite.dynamic_filter_categories') || 'Filter by Categories'}
                                    >
                                        <SuluNativeField
                                            fieldType="category_selection"
                                            locale={contentLocale}
                                            onChange={this.handleCategoryIdsChange}
                                            value={this.categoryIds}
                                        />
                                    </Form.Field>
                                </>
                            )}

                            {sourceType === 'sibling_pages' && (
                                <Form.Field colSpan={12}>
                                    <Checkbox
                                        checked={this.excludeCurrent}
                                        onChange={this.handleExcludeCurrentChange}
                                    >
                                        {translate('sulu_block_suite.dynamic_exclude_current') ||
                                            'Exclude current page from sibling results'}
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
                                    'Select which source field should populate each block property on the left.'}
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
                                                    {translate('sulu_block_suite.dynamic_op_contains') || 'Contains'}
                                                </SingleSelect.Option>
                                                <SingleSelect.Option value="not_contains">
                                                    {translate('sulu_block_suite.dynamic_op_not_contains') || 'Does Not Contain'}
                                                </SingleSelect.Option>
                                                <SingleSelect.Option value="starts_with">
                                                    {translate('sulu_block_suite.dynamic_op_starts_with') || 'Starts With'}
                                                </SingleSelect.Option>
                                                <SingleSelect.Option value="ends_with">
                                                    {translate('sulu_block_suite.dynamic_op_ends_with') || 'Ends With'}
                                                </SingleSelect.Option>
                                                <SingleSelect.Option value="in">
                                                    {translate('sulu_block_suite.dynamic_op_in') || 'In List (Comma-Separated)'}
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
