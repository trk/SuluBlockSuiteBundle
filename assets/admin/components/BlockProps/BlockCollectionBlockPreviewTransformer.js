// @flow
import React from 'react';
import Icon from 'sulu-admin-bundle/components/Icon';
import {translate} from 'sulu-admin-bundle/utils';
import type {Node} from 'react';
import type {BlockPreviewTransformer} from 'sulu-admin-bundle/types';
import styles from './blockPreviewTransformers.scss';

export default class BlockCollectionBlockPreviewTransformer implements BlockPreviewTransformer {
    transform(value: *, schema?: Object): Node {
        if (!Array.isArray(value) || value.length === 0) {
            return null;
        }

        const count = value.length;
        const unitLabel = translate('sulu_block_suite.preview_child_blocks') || 'sub-blocks';
        const fieldLabel = schema && typeof schema.label === 'string' && schema.label.trim() !== ''
            ? schema.label.trim()
            : '';

        const childTitles = value
            .map((item) => {
                if (!item || typeof item !== 'object') {
                    return null;
                }
                const title = item.title || item.headline || item.label || item.term || item.author || (item.props && item.props.name);
                return typeof title === 'string' && title.trim() !== '' ? title.trim() : null;
            })
            .filter(Boolean);

        const baseTooltip = fieldLabel ? `${fieldLabel}: ${count} ${unitLabel}` : `${count} ${unitLabel}`;
        const tooltip = childTitles.length > 0
            ? `${baseTooltip} (${childTitles.slice(0, 5).join(', ')}${childTitles.length > 5 ? ', ...' : ''})`
            : baseTooltip;

        return (
            <span className={styles.childBlocksBadge} title={tooltip}>
                <Icon name="su-layer" />
                <span>{count} {unitLabel}</span>
            </span>
        );
    }
}
