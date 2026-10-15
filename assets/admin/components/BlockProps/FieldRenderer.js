// @flow
import React from 'react';
import {observer} from 'mobx-react';
import {
    Checkbox,
    Form,
    Input,
    Number as NumberField,
    SingleSelect,
} from 'sulu-admin-bundle/components';
import ColorPicker from 'sulu-admin-bundle/components/ColorPicker';
import TextArea from 'sulu-admin-bundle/components/TextArea';
import {fieldRegistry} from 'sulu-admin-bundle/containers/Form';
import {translate} from 'sulu-admin-bundle/utils';
import SuluNativeField from './SuluNativeField';

type FieldOption = {
    label: string,
    value: string,
};

type FieldSchema = {
    col_span?: number,
    default?: any,
    icon_set?: string,
    label: string,
    name: string,
    options?: Array<FieldOption>,
    placeholder?: string,
    type: string,
};

type Props = {
    contentLocale?: ?string,
    defaultOptionLabel?: string,
    field: FieldSchema,
    onChange: (name: string, value: any) => void,
    value: any,
};

const SPECIAL_NATIVE_FIELD_TYPES = [
    'single_media_selection',
    'media_selection',
    'teaser_selection',
    'contact_account_selection',
    'single_icon_selection',
];

@observer
class FieldRenderer extends React.Component<Props> {
    handleChange = (value: any) => {
        const {field, onChange} = this.props;
        onChange(field.name, value);
    };

    isNativeSelectionField(fieldType: string): boolean {
        if (SPECIAL_NATIVE_FIELD_TYPES.includes(fieldType)) {
            return true;
        }
        if (fieldRegistry.has(fieldType)) {
            try {
                const opts = fieldRegistry.getOptions(fieldType);
                return Boolean(opts && opts.resource_key);
            } catch (e) {
                return false;
            }
        }
        return false;
    }

    render() {
        const {contentLocale, defaultOptionLabel = 'Default', field, value} = this.props;
        const colSpan = typeof field.col_span === 'number' ? field.col_span : 6;
        const label = translate(field.label) || field.label;
        const placeholder = field.placeholder ? (translate(field.placeholder) || field.placeholder) : undefined;

        if (this.isNativeSelectionField(field.type)) {
            return (
                <Form.Field colSpan={colSpan} key={field.name} label={label}>
                    <SuluNativeField
                        fieldType={field.type}
                        iconSet={field.icon_set || 'default'}
                        locale={contentLocale}
                        onChange={this.handleChange}
                        value={value}
                    />
                </Form.Field>
            );
        }

        switch (field.type) {
            case 'select':
            case 'single_select': {
                const options = field.options
                    ? (Array.isArray(field.options) ? field.options : Array.from(field.options))
                    : [];
                const currentValue = value !== undefined && value !== null ? String(value) : '';

                return (
                    <Form.Field colSpan={colSpan} key={field.name} label={label}>
                        <SingleSelect onChange={this.handleChange} value={currentValue}>
                            <SingleSelect.Option value="">{defaultOptionLabel}</SingleSelect.Option>
                            {options.map((opt) => (
                                <SingleSelect.Option key={opt.value} value={opt.value}>
                                    {translate(opt.label) || opt.label}
                                </SingleSelect.Option>
                            ))}
                        </SingleSelect>
                    </Form.Field>
                );
            }

            case 'checkbox':
            case 'bool':
            case 'toggle': {
                const currentValue = Boolean(value);

                return (
                    <Form.Field colSpan={colSpan} key={field.name}>
                        <Checkbox checked={currentValue} onChange={this.handleChange}>
                            {label}
                        </Checkbox>
                    </Form.Field>
                );
            }

            case 'color':
            case 'color_picker': {
                const currentValue = value || '';

                return (
                    <Form.Field colSpan={colSpan} key={field.name} label={label}>
                        <ColorPicker onChange={(v) => this.handleChange(v || '')} value={currentValue} />
                    </Form.Field>
                );
            }

            case 'number': {
                const rawVal = value;
                const currentValue = typeof rawVal === 'number'
                    ? rawVal
                    : (rawVal !== undefined && rawVal !== null && rawVal !== '' && !isNaN(Number(rawVal))
                        ? parseFloat(String(rawVal))
                        : undefined);

                return (
                    <Form.Field colSpan={colSpan} key={field.name} label={label}>
                        <NumberField
                            alignment="left"
                            onChange={this.handleChange}
                            placeholder={placeholder}
                            value={currentValue}
                        />
                    </Form.Field>
                );
            }

            case 'textarea': {
                const currentValue = value || '';

                return (
                    <Form.Field colSpan={colSpan} key={field.name} label={label}>
                        <TextArea
                            onChange={(v) => this.handleChange(v || '')}
                            placeholder={placeholder}
                            rows={3}
                            value={currentValue}
                        />
                    </Form.Field>
                );
            }

            case 'text':
            case 'input':
            default: {
                const currentValue = value !== undefined && value !== null ? String(value) : '';

                return (
                    <Form.Field colSpan={colSpan} key={field.name} label={label}>
                        <Input
                            onChange={this.handleChange}
                            placeholder={placeholder}
                            value={currentValue}
                        />
                    </Form.Field>
                );
            }
        }
    }
}

export default FieldRenderer;
