// @flow
import React from 'react';
import {action, observable, toJS} from 'mobx';
import {observer} from 'mobx-react';
import {
    Button,
    Checkbox,
    Dialog,
    Form,
    Input,
} from 'sulu-admin-bundle/components';
import {translate} from 'sulu-admin-bundle/utils';
import {Requester} from 'sulu-admin-bundle/services';
import snackbarStore from 'sulu-admin-bundle/stores/snackbarStore';
import blockPropsStyles from './presetSelection.scss';

const t = (key: string, fallback: string): string => {
    const translated = translate(key);
    return translated && translated !== key ? translated : fallback;
};

type ReusableSnippet = {
    description?: ?string,
    id: string,
    preview_image?: ?string,
    title: string,
};

type ReusableCardProps = {
    locale?: string,
    onChangeSnippet?: () => void,
    snippet: ReusableSnippet,
};

@observer
export class ReusableCard extends React.Component<ReusableCardProps> {
    handleOpenInNewTab = () => {
        const {locale, snippet} = this.props;
        const normalizedLocale = encodeURIComponent(locale || 'en');
        const id = encodeURIComponent(snippet.id);
        const adminBase = window.location.pathname.replace(/\/$/, '');
        window.open(`${adminBase}/#/block-suite/reusable/${normalizedLocale}/${id}/content`, '_blank');
    };

    render() {
        const {onChangeSnippet, snippet} = this.props;
        const openInNewTabLabel = t('sulu_block_suite.open_in_new_tab', 'Edit in New Tab');
        const changeLabel = t('sulu_block_suite.change_reusable_block', 'Change');

        return (
            <div className={blockPropsStyles.reusableCard}>
                {snippet.preview_image && (
                    <img
                        alt={snippet.title}
                        className={blockPropsStyles.reusablePreviewImage}
                        src={snippet.preview_image}
                    />
                )}
                <div className={blockPropsStyles.reusableInfo}>
                    <span className={blockPropsStyles.reusableTitle}>{snippet.title}</span>
                    {snippet.description && (
                        <span className={blockPropsStyles.reusableDesc}>{snippet.description}</span>
                    )}
                </div>
                <div className={blockPropsStyles.actionGroup} style={{marginLeft: 'auto'}}>
                    <Button icon="su-pen" onClick={this.handleOpenInNewTab} skin="link">
                        {openInNewTabLabel}
                    </Button>
                    {onChangeSnippet && (
                        <Button icon="su-sync" onClick={onChangeSnippet} skin="link">
                            {changeLabel}
                        </Button>
                    )}
                </div>
            </div>
        );
    }
}

type SaveReusableDialogProps = {
    blockData: Object,
    componentType: string,
    locale: string,
    onCancel: () => void,
    onSuccess: (createdSnippet: Object, convertBlock: boolean) => void,
    open: boolean,
    propsValues: Object,
    webspace?: ?string,
};

@observer
export class SaveReusableDialog extends React.Component<SaveReusableDialogProps> {
    @observable title: string = '';
    @observable description: string = '';
    @observable convertBlock: boolean = true;
    @observable saving: boolean = false;
    @observable error: ?string = null;

    componentDidUpdate(prevProps: SaveReusableDialogProps) {
        if (this.props.open && !prevProps.open) {
            this.resetForm();
        }
    }

    @action resetForm() {
        this.title = '';
        this.description = '';
        this.convertBlock = true;
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

    @action handleConvertBlockChange = (checked: boolean) => {
        this.convertBlock = checked;
    };

    @action handleConfirm = () => {
        const title = this.title.trim();
        if (!title) {
            return;
        }

        const {blockData, componentType, locale, onSuccess, propsValues, webspace} = this.props;
        if (!componentType || componentType === 'general') {
            return;
        }

        this.saving = true;
        this.error = null;

        const currentProps = {...toJS(propsValues)};
        delete currentProps._preset;

        const payloadBlockData = {
            ...(blockData ? toJS(blockData) : {}),
            type: componentType,
            props: currentProps,
        };

        const payload = {
            title,
            description: this.description.trim() || null,
            locale,
            webspace: webspace || '_global',
            block: payloadBlockData,
        };

        Requester.post('/admin/api/reusable-blocks', payload)
            .then(action((createdSnippet) => {
                this.saving = false;
                snackbarStore.add({
                    text: t('sulu_block_suite.save_reusable_success', `Reusable block "${title}" created successfully.`),
                    type: 'success',
                }, 3000);
                onSuccess(createdSnippet, this.convertBlock);
            }))
            .catch(action((errorResponse) => {
                this.saving = false;
                const defaultErr = t('sulu_block_suite.save_reusable_error', 'Failed to save reusable block');
                if (errorResponse && errorResponse.json) {
                    errorResponse.json().then(action((err) => {
                        this.error = err.error || defaultErr;
                    })).catch(action(() => {
                        this.error = defaultErr;
                    }));
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

        const titleText = t('sulu_block_suite.save_reusable_title', 'Save as Reusable Block');
        const titleLabel = t('sulu_block_suite.reusable_title_label', 'Reusable Block Title');
        const descLabel = t('sulu_block_suite.reusable_desc_label', 'Description / Note (Optional)');
        const convertBlockLabel = t('sulu_block_suite.convert_to_reusable', 'Replace current block with this Reusable Block');
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
                                checked={this.convertBlock}
                                onChange={this.handleConvertBlockChange}
                            >
                                {convertBlockLabel}
                            </Checkbox>
                        </Form.Field>
                    </Form>
                </div>
            </Dialog>
        );
    }
}

export default {
    Card: ReusableCard,
    SaveDialog: SaveReusableDialog,
};
