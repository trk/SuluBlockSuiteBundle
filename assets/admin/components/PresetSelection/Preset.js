// @flow
import React from 'react';
import {action, observable, toJS} from 'mobx';
import {observer} from 'mobx-react';
import {
    Checkbox,
    Dialog,
    Form,
    Input,
} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import {Requester} from 'sulu-admin-bundle/services';
import snackbarStore from 'sulu-admin-bundle/stores/snackbarStore';
import PresetGallery from './PresetGallery';
import blockPropsStyles from './presetSelection.scss';

const t = (key: string, fallback: string): string => {
    const translated = translate(key);
    return translated && translated !== key ? translated : fallback;
};

type PresetItem = {
    description?: ?string,
    id: string,
    preview_image?: ?string,
    props: Object,
    title: string,
    values?: ?Object,
};

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
        delete currentProps.preset;

        const valuesPayload = this.includeValues && contentValues ? {...toJS(contentValues)} : {};
        delete valuesPayload.props;
        delete valuesPayload.type;
        delete valuesPayload._preset;
        delete valuesPayload.preset;

        const payload = {
            title,
            description: this.description.trim() || null,
            block_type: componentType,
            locale,
            props: currentProps,
            values: valuesPayload,
        };

        Requester.post('/admin/api/block-presets', payload)
            .then(action((createdPreset: PresetItem) => {
                this.saving = false;
                snackbarStore.add({
                    text: t('sulu_block_suite.save_preset_success', `Preset "${title}" saved successfully.`),
                    type: 'success',
                }, 3000);
                onSuccess(createdPreset);
            }))
            .catch(action((errorResponse) => {
                this.saving = false;
                const defaultErr = t('sulu_block_suite.save_preset_error', 'Failed to save preset');
                if (errorResponse && typeof errorResponse.json === 'function') {
                    errorResponse.json().then(action((errData) => {
                        this.error = (errData && errData.error) || defaultErr;
                    })).catch(action(() => {
                        this.error = defaultErr;
                    }));
                } else if (errorResponse && errorResponse.message) {
                    this.error = errorResponse.message;
                } else {
                    this.error = defaultErr;
                }
            }));
    };

    render() {
        const {onCancel, open} = this.props;
        if (!open) {
            return null;
        }

        const titleText = t('sulu_block_suite.save_preset_title', 'Save as Style Preset');
        const titleLabel = t('sulu_block_suite.preset_title_label', 'Preset Name');
        const descLabel = t('sulu_block_suite.preset_desc_label', 'Description (Optional)');
        const includeValuesLabel = t('sulu_block_suite.include_content_values', 'Include block content values (text, media, items)');
        const cancelButton = t('sulu_admin.cancel', 'Cancel');
        const saveButton = t('sulu_admin.save', 'Save');

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
    SaveDialog: SavePresetDialog,
    Gallery: PresetGallery,
};
