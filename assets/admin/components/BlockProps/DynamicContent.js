// @flow
import React from 'react';
import {action, computed, isArrayLike, observable, toJS} from 'mobx';
import {observer} from 'mobx-react';
import {
    Checkbox,
    Form,
    Icon,
    Input,
    SingleSelect,
} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import {Requester} from 'sulu-admin-bundle/services';
import FieldRenderer from './FieldRenderer';
import type {FieldSchema} from './FieldRenderer';
import blockPropsStyles from './blockProps.scss';

type SourceFieldItem = {
    label: string,
    name: string,
    type: string,
};

type SourceOptionItem = {
    config_fields?: Array<FieldSchema>,
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
    'gt',
    'gte',
    'lt',
    'lte',
];

const RESERVED_DYNAMIC_KEYS = ['enabled', 'source_type', 'mapping', 'condition', 'query'];

@observer
class DynamicContent extends React.Component<Props> {
    @observable sourceType: string = 'none';
    @observable.ref configValues: {[string]: any} = {};
    @observable.ref mapping: {[string]: string} = {};
    @observable conditionField: string = 'title';
    @observable conditionOperator: string = 'not_empty';
    @observable conditionValue: string = '';
    @observable hasCondition: boolean = false;
    @observable.ref sourceFields: Array<SourceFieldItem> = [...BASE_SOURCE_FIELDS];
    @observable.ref availableSources: Array<SourceOptionItem> = [];
    @observable isMultipleBlock: boolean = false;
    @observable.ref targetFields: Array<TargetFieldDefinition> = [...DEFAULT_FALLBACK_FIELDS];
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

        if (prevProps.locale !== this.props.locale || prevProps.contentLocale !== this.props.contentLocale) {
            this.loadAvailableSources();
            this.loadSourceFields();
        }

        if (prevProps.componentType !== this.props.componentType || prevProps.locale !== this.props.locale) {
            this.loadTargetFields();
        }
    }

    @action initConfigFromProps(val: ?Object) {
        if (!val) {
            this.sourceType = 'none';
            this.configValues = {};
            this.mapping = {};
            this.conditionField = 'title';
            this.conditionOperator = 'not_empty';
            this.conditionValue = '';
            this.hasCondition = false;
            return;
        }

        const raw = toJS(val);
        this.sourceType = raw.source_type || 'none';

        const extractedConfig: {[string]: any} = {};
        if (raw.query && typeof raw.query === 'object' && !isArrayLike(raw.query)) {
            Object.keys(raw.query).forEach((k) => {
                extractedConfig[k] = raw.query[k];
            });
        }

        Object.keys(raw).forEach((key) => {
            if (!RESERVED_DYNAMIC_KEYS.includes(key)) {
                extractedConfig[key] = raw[key];
            }
        });

        this.configValues = extractedConfig;
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
            .then(action((response) => {
                const rawSources = response && isArrayLike(response.sources)
                    ? Array.from(response.sources)
                    : (isArrayLike(response) ? Array.from(response) : []);
                if (rawSources.length > 0) {
                    this.availableSources = rawSources.filter((s) => s && s.key);
                }
            }))
            .catch(() => {});
    };

    @action loadSourceFields = () => {
        const sourceType = this.sourceType || 'none';
        const locale = this.props.contentLocale || this.props.locale || 'en';

        let url = `/admin/api/block-suite/dynamic/source-fields?locale=${encodeURIComponent(locale)}&source_type=${encodeURIComponent(sourceType)}`;
        const currentConfig = toJS(this.configValues);
        Object.keys(currentConfig).forEach((key) => {
            const val = currentConfig[key];
            if ((typeof val === 'string' || typeof val === 'number') && String(val).trim() !== '') {
                url += `&${encodeURIComponent(key)}=${encodeURIComponent(String(val).trim())}`;
            }
        });

        this.loadingFields = true;
        Requester.get(url)
            .then(action((response) => {
                const rawFields = response && isArrayLike(response.fields)
                    ? Array.from(response.fields)
                    : (isArrayLike(response) ? Array.from(response) : []);
                if (rawFields.length > 0) {
                    this.sourceFields = rawFields;
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
                const rawFields = response && isArrayLike(response.fields)
                    ? Array.from(response.fields)
                    : [];
                if (response && typeof response === 'object') {
                    this.isMultipleBlock = Boolean(response.multiple);
                    this.targetFields = rawFields.length > 0
                        ? rawFields
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

    @computed get activeSource(): ?SourceOptionItem {
        return this.availableSources.find((s) => s.key === this.sourceType) || null;
    }

    @computed get activeConfigFields(): Array<FieldSchema> {
        const source = this.activeSource;
        if (source && isArrayLike(source.config_fields)) {
            return Array.from(source.config_fields);
        }
        return [];
    }

    isConfigValuePresent(val: any): boolean {
        if (val === undefined || val === null || val === '') {
            return false;
        }
        if (isArrayLike(val) && val.length === 0) {
            return false;
        }
        return true;
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

        const configFields = this.activeConfigFields;
        if (configFields.length > 0) {
            configFields.forEach((field) => {
                const rawVal = this.configValues[field.name] !== undefined
                    ? toJS(this.configValues[field.name])
                    : field.default;

                if (this.isConfigValuePresent(rawVal)) {
                    if (field.name === 'offset' && Number(rawVal) === 0) {
                        return;
                    }
                    clean[field.name] = rawVal;
                }
            });
        } else {
            const rawValues = toJS(this.configValues);
            Object.keys(rawValues).forEach((key) => {
                if (this.isConfigValuePresent(rawValues[key])) {
                    clean[key] = rawValues[key];
                }
            });
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
            this.configValues = {};
        }
        this.sourceType = cleanType;
        this.syncToParent();
        this.loadSourceFields();
    };

    @action handleConfigFieldChange = (fieldName: string, value: any) => {
        const next = {...toJS(this.configValues)};
        if (value === undefined || value === null || value === '') {
            delete next[fieldName];
        } else {
            next[fieldName] = value;
        }
        this.configValues = next;
        this.syncToParent();

        const fieldDef = this.activeConfigFields.find((f) => f.name === fieldName);
        if (fieldDef && fieldDef.trigger_source_fields_reload) {
            this.loadSourceFields();
        }
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
        const mapping = this.mapping || {};
        const contentLocale = this.props.contentLocale || this.props.locale || 'en';

        const noneLabel = translate('sulu_block_suite.dynamic_source_none') || 'None';
        const mapNoneLabel = translate('sulu_block_suite.dynamic_map_none') || 'Static';
        const configFields = this.activeConfigFields;
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

                    {sourceType !== 'none' && configFields.map((field) => (
                        <FieldRenderer
                            contentLocale={contentLocale}
                            field={field}
                            key={`${sourceType}_${field.name}`}
                            onChange={this.handleConfigFieldChange}
                            value={this.configValues[field.name]}
                        />
                    ))}
                </Form>

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
                                                        {field.label}
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
                                    'Enable Display Condition'}
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
                                                    {field.label}
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
                                                {translate('sulu_block_suite.dynamic_op_in') || 'In List'}
                                            </SingleSelect.Option>
                                            <SingleSelect.Option value="gt">
                                                {translate('sulu_block_suite.dynamic_op_gt') || 'Greater Than'}
                                            </SingleSelect.Option>
                                            <SingleSelect.Option value="gte">
                                                {translate('sulu_block_suite.dynamic_op_gte') || 'Greater Than or Equal'}
                                            </SingleSelect.Option>
                                            <SingleSelect.Option value="lt">
                                                {translate('sulu_block_suite.dynamic_op_lt') || 'Less Than'}
                                            </SingleSelect.Option>
                                            <SingleSelect.Option value="lte">
                                                {translate('sulu_block_suite.dynamic_op_lte') || 'Less Than or Equal'}
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
            </div>
        );
    }
}

export default DynamicContent;
