// @flow
import React from 'react';
import {action, observable, toJS} from 'mobx';
import {observer} from 'mobx-react';
import {
    Checkbox,
    Dialog,
    Form,
    Input,
    SingleSelect,
} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import {Requester} from 'sulu-admin-bundle/services';
import PresetGallery from './PresetGallery';
import blockPropsStyles from './blockProps.scss';

type PresetItem = {
    description?: ?string,
    id: string,
    preview_image?: ?string,
    props: Object,
    title: string,
    values?: ?Object,
};

type PresetSelectProps = {
    disabled?: boolean,
    onChange: (presetId: ?string, preset: ?PresetItem) => void,
    presets: Array<PresetItem>,
    value: ?string,
};

@observer
export class PresetSelect extends React.Component<PresetSelectProps> {
    handleChange = (presetId: ?string) => {
        const {onChange, presets} = this.props;
        const presetList = presets ? (Array.isArray(presets) ? presets : Array.from(presets)) : [];
        const selected = presetList.find((p) => p.id === presetId) || null;
        onChange(presetId || null, selected);
    };

    render() {
        const {disabled, presets, value} = this.props;
        const noPresetLabel = translate('sulu_block_suite.no_preset') || 'Custom Configuration (No Preset)';
        const presetList = presets ? (Array.isArray(presets) ? presets : Array.from(presets)) : [];

        return (
            <SingleSelect disabled={disabled} onChange={this.handleChange} value={value || ''}>
                <SingleSelect.Option value="">{noPresetLabel}</SingleSelect.Option>
                {presetList.map((preset) => (
                    <SingleSelect.Option key={preset.id} value={preset.id}>
                        {preset.title}
                    </SingleSelect.Option>
                ))}
            </SingleSelect>
        );
    }
}

type SavePresetDialogProps = {
    componentType: string,
    contentValues?: ?Object,
    locale: string,
    onCancel: () => void,
    onSuccess: (createdPreset: PresetItem) => void,
    open: boolean,
    propsValues: Object,
};

@observer
export class SavePresetDialog extends React.Component<SavePresetDialogProps> {
    @observable title: string = '';
    @observable description: string = '';
    @observable includeValues: boolean = false;
    @observable saving: boolean = false;
    @observable error: ?string = null;

    componentDidUpdate(prevProps: SavePresetDialogProps) {
        if (this.props.open && !prevProps.open) {
            this.resetForm();
        }
    }

    @action resetForm() {
        this.title = '';
        this.description = '';
        this.includeValues = false;
        this.saving = false;
        this.error = null;
    }

    @action handleTitleChange = (val: ?string) => {
        this.title = val || '';
        this.error = null;
    };

    @action handleDescriptionChange = (val: ?string) => {
        this.description = val || '';
    };

    @action handleIncludeValuesChange = (checked: boolean) => {
        this.includeValues = checked;
    };

    @action handleConfirm = () => {
        const title = this.title.trim();
        if (!title) {
            return;
        }

        const {componentType, contentValues, locale, onSuccess, propsValues} = this.props;
        if (!componentType || componentType === 'general') {
            return;
        }

        this.saving = true;
        this.error = null;

        const currentProps = {...toJS(propsValues)};
        delete currentProps._preset;

        const valuesPayload = this.includeValues && contentValues ? toJS(contentValues) : {};
        delete valuesPayload.props;
        delete valuesPayload.type;

        const payload = {
            title,
            description: this.description.trim() || null,
            block_type: componentType,
            locale,
            props: currentProps,
            values: valuesPayload,
        };

        fetch('/admin/api/block-presets', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
        })
            .then((res) => {
                if (!res.ok) {
                    return res.json().then((errData) => {
                        throw new Error(errData.error || translate('sulu_block_suite.save_preset_error') || 'Failed to save preset');
                    });
                }
                return res.json();
            })
            .then(action((createdPreset: PresetItem) => {
                this.saving = false;
                onSuccess(createdPreset);
            }))
            .catch(action((err) => {
                this.saving = false;
                this.error = err.message || translate('sulu_block_suite.save_preset_error') || 'Failed to save preset';
            }));
    };

    render() {
        const {onCancel, open} = this.props;
        if (!open) {
            return null;
        }

        const titleText = translate('sulu_block_suite.save_preset_title') || 'Save as Style Preset';
        const titleLabel = translate('sulu_block_suite.preset_title_label') || 'Preset Name';
        const descLabel = translate('sulu_block_suite.preset_desc_label') || 'Description (Optional)';
        const includeValuesLabel = translate('sulu_block_suite.include_content_values') || 'Include block content values (text, fields)';
        const cancelButton = translate('sulu_admin.cancel') || 'Cancel';
        const saveButton = translate('sulu_admin.save') || 'Save';

        return (
            <Dialog
                align="left"
                cancelText={cancelButton}
                confirmDisabled={!this.title.trim() || this.saving}
                confirmLoading={this.saving}
                confirmText={saveButton}
                onCancel={onCancel}
                onConfirm={this.handleConfirm}
                onSnackbarCloseClick={action(() => {
                    this.error = null;
                })}
                open={open}
                size="small"
                snackbarMessage={this.error || undefined}
                snackbarType="error"
                title={titleText}
            >
                <div className={blockPropsStyles.savePresetDialogForm}>
                    <Form>
                        <Form.Field colSpan={12} label={titleLabel} required={true}>
                            <Input
                                onChange={this.handleTitleChange}
                                value={this.title}
                            />
                        </Form.Field>
                        <Form.Field colSpan={12} label={descLabel}>
                            <Input
                                onChange={this.handleDescriptionChange}
                                value={this.description}
                            />
                        </Form.Field>
                        <Form.Field colSpan={12}>
                            <Checkbox
                                checked={this.includeValues}
                                onChange={this.handleIncludeValuesChange}
                            >
                                {includeValuesLabel}
                            </Checkbox>
                        </Form.Field>
                    </Form>
                </div>
            </Dialog>
        );
    }
}

export default {
    Select: PresetSelect,
    SaveDialog: SavePresetDialog,
    Gallery: PresetGallery,
};
