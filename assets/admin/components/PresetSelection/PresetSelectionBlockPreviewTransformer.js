// @flow
import React from 'react';
import Icon from 'sulu-admin-bundle/components/Icon';
import {translate} from 'sulu-admin-bundle/utils';
import type {Node} from 'react';
import type {BlockPreviewTransformer} from 'sulu-admin-bundle/types';
import PresetSelection from './PresetSelection';
import styles from './blockPreviewTransformers.scss';

function resolvePresetTitle(presetId: string, schema?: Object): string {
    if (schema && schema.options && schema.options.presets_json) {
        const rawJson = typeof schema.options.presets_json === 'string'
            ? schema.options.presets_json
            : schema.options.presets_json.value;
        if (typeof rawJson === 'string' && rawJson !== '') {
            try {
                const parsed = JSON.parse(rawJson);
                if (Array.isArray(parsed)) {
                    const match = parsed.find((p) => p && p.id === presetId);
                    if (match && typeof match.title === 'string' && match.title.trim() !== '') {
                        return match.title.trim();
                    }
                }
            } catch (e) {
                // ignore
            }
        }
    }

    for (const cachedList of PresetSelection.presetsCache.values()) {
        if (Array.isArray(cachedList)) {
            const match = cachedList.find((p) => p && p.id === presetId);
            if (match && typeof match.title === 'string' && match.title.trim() !== '') {
                return match.title.trim();
            }
        }
    }

    return presetId;
}

export default class PresetSelectionBlockPreviewTransformer implements BlockPreviewTransformer {
    transform(value: *, schema?: Object): Node {
        if (!value) {
            return null;
        }

        let presetId = null;
        let customName = null;
        let dynamicSource = null;
        let visibility = null;

        if (typeof value === 'string') {
            presetId = value.trim() !== '' ? value.trim() : null;
        } else if (typeof value === 'object') {
            if (typeof value.preset === 'string' && value.preset.trim() !== '') {
                presetId = value.preset.trim();
            }
            if (typeof value.name === 'string' && value.name.trim() !== '') {
                customName = value.name.trim();
            }
            if (typeof value.dynamic_source === 'string' && value.dynamic_source.trim() !== '' && value.dynamic_source !== 'none') {
                dynamicSource = value.dynamic_source.trim();
            }
            if (typeof value.visibility === 'string' && value.visibility.trim() !== '' && value.visibility !== 'visible') {
                visibility = value.visibility.trim();
            }
        }

        const badges = [];

        if (customName) {
            badges.push(
                <span className={styles.nameBadge} key="name" title={customName}>
                    <Icon name="su-tag" />
                    <span>{customName}</span>
                </span>
            );
        }

        if (presetId) {
            const presetTitle = resolvePresetTitle(presetId, schema);
            badges.push(
                <span className={styles.presetBadge} key="preset" title={`Preset: ${presetTitle}`}>
                    <Icon name="su-sliders" />
                    <span>{presetTitle}</span>
                </span>
            );
        }

        if (dynamicSource) {
            const dynamicLabel = translate('sulu_block_suite.preview_dynamic') || 'Dynamic';
            badges.push(
                <span className={styles.dynamicBadge} key="dynamic" title={`${dynamicLabel}: ${dynamicSource}`}>
                    <Icon name="su-sync" />
                    <span>{dynamicLabel}: {dynamicSource}</span>
                </span>
            );
        }

        if (visibility) {
            badges.push(
                <span className={styles.visibilityBadge} key="visibility" title={`Visibility: ${visibility}`}>
                    <Icon name="su-hide" />
                    <span>{visibility}</span>
                </span>
            );
        }

        if (badges.length === 0) {
            return null;
        }

        return (
            <span className={styles.previewBadgeGroup}>
                {badges}
            </span>
        );
    }
}
