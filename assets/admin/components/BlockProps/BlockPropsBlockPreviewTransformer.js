// @flow
import React from 'react';
import Icon from 'sulu-admin-bundle/components/Icon';
import {translate} from 'sulu-admin-bundle/utils';
import type {Node} from 'react';
import type {BlockPreviewTransformer} from 'sulu-admin-bundle/types';
import styles from './blockPreviewTransformers.scss';

export default class BlockPropsBlockPreviewTransformer implements BlockPreviewTransformer {
    resolveDynamicSourceLabel(sourceType: string): string {
        const translationKey = `sulu_block_suite.dynamic_source_${sourceType}`;
        const translated = translate(translationKey);
        if (translated && translated !== translationKey) {
            // Strip parenthetical explanations like "(Repeater / Multi-Item)" for compact badge display
            return translated.replace(/\s*\([^)]*\)\s*$/, '').trim();
        }

        return sourceType
            .split('_')
            .map((part) => (part.length > 0 ? part.charAt(0).toUpperCase() + part.slice(1) : ''))
            .join(' ');
    }

    transform(value: *): Node {
        if (!value || typeof value !== 'object') {
            return null;
        }

        const blockName = typeof value.name === 'string' ? value.name.trim() : '';
        const dyn = value._dynamic && typeof value._dynamic === 'object' ? value._dynamic : null;
        const rawSource = dyn ? (dyn.source_type || dyn.source || '') : '';
        const sourceType = typeof rawSource === 'string' ? rawSource.trim() : '';
        const isDynamicActive = Boolean(
            dyn &&
            dyn.enabled !== false &&
            sourceType !== '' &&
            sourceType !== 'none'
        );

        if (!isDynamicActive && blockName === '') {
            return null;
        }

        const dynamicTitle = translate('sulu_block_suite.dynamic_content') || 'Dynamic Content';
        const sourceLabel = isDynamicActive ? this.resolveDynamicSourceLabel(sourceType) : '';
        const limitSuffix = isDynamicActive && dyn && dyn.limit ? ` (${String(dyn.limit)})` : '';

        return (
            <span className={styles.previewBadgeGroup}>
                {isDynamicActive && (
                    <span
                        className={styles.dynamicBadge}
                        title={`${dynamicTitle}: ${sourceLabel}${limitSuffix}`}
                    >
                        <Icon name="su-sync" />
                        <span>{dynamicTitle}: {sourceLabel}{limitSuffix}</span>
                    </span>
                )}
                {blockName !== '' && (
                    <span className={styles.nameBadge} title={blockName}>
                        <Icon name="su-tag" />
                        <span>{blockName}</span>
                    </span>
                )}
            </span>
        );
    }
}
