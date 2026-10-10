// @flow
import React from 'react';
import {observer} from 'mobx-react';
import Icon from 'sulu-admin-bundle/components/Icon';
import type {Node} from 'react';
import type {BlockPreviewTransformer} from 'sulu-admin-bundle/types';
import reusableBlockStore from '../../stores/reusableBlockStore';
import styles from './singleReusableBlockSelectionBlockPreviewTransformer.scss';

type PreviewItemProps = {
    id: string,
};

const PreviewItem = observer(({id}: PreviewItemProps) => {
    const snippet = reusableBlockStore.get(id);

    if (!snippet) {
        reusableBlockStore.load(id);
        return (
            <span className={styles.previewContainer}>
                <span className={styles.badge}>
                    <Icon name="su-clone" />
                    <span>Ortak Blok</span>
                </span>
                <span className={styles.loading}>Yükleniyor...</span>
            </span>
        );
    }

    const blockCount = (snippet.blocks && Array.isArray(snippet.blocks)) ? snippet.blocks.length : null;
    const tooltip = snippet.description
        ? `${snippet.title} — ${snippet.description}`
        : snippet.title;

    return (
        <span className={styles.previewContainer} title={tooltip}>
            <span className={styles.badge}>
                <Icon name="su-clone" />
                <span>Ortak Blok</span>
            </span>
            <strong className={styles.title}>{snippet.title}</strong>
            {blockCount !== null && (
                <span className={styles.description}>({blockCount} blok)</span>
            )}
            {snippet.description && (
                <span className={styles.description}>— {snippet.description}</span>
            )}
        </span>
    );
});

export default class SingleReusableBlockSelectionBlockPreviewTransformer implements BlockPreviewTransformer {
    transform(value: *): Node {
        const id = typeof value === 'object' && value ? value.id : value;

        if (!id || typeof id !== 'string') {
            return null;
        }

        return <PreviewItem id={id} key={id} />;
    }
}
