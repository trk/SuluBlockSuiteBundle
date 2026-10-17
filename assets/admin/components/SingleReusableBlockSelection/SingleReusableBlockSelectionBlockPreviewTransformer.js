// @flow
import React from 'react';
import {observer} from 'mobx-react';
import Icon from 'sulu-admin-bundle/components/Icon';
import {translate} from 'sulu-admin-bundle/utils';
import type {Node} from 'react';
import type {BlockPreviewTransformer} from 'sulu-admin-bundle/types';
import reusableBlockStore from '../../stores/reusableBlockStore';
import styles from './singleReusableBlockSelectionBlockPreviewTransformer.scss';

type PreviewItemProps = {
    id: string,
};

@observer
class PreviewItem extends React.Component<PreviewItemProps> {
    componentDidMount() {
        this.ensureLoaded();
    }

    componentDidUpdate(prevProps: PreviewItemProps) {
        if (prevProps.id !== this.props.id) {
            this.ensureLoaded();
        }
    }

    ensureLoaded() {
        const {id} = this.props;
        if (!reusableBlockStore.get(id)) {
            reusableBlockStore.load(id);
        }
    }

    render() {
        const {id} = this.props;
        const snippet = reusableBlockStore.get(id);
        const badgeLabel = translate('sulu_block_suite.save_as_reusable_option') || 'Reusable Block';
        const loadingLabel = translate('sulu_block_suite.reusable_block_loading') || 'Loading...';
        const innerBlocksLabel = translate('sulu_block_suite.reusable_block_inner_blocks') || 'blocks';

        if (!snippet) {
            return (
                <span className={styles.previewContainer}>
                    <span className={styles.badge}>
                        <Icon name="su-snippet" />
                        <span>{badgeLabel}</span>
                    </span>
                    <span className={styles.loading}>{loadingLabel}</span>
                </span>
            );
        }

        if (snippet.notFound) {
            return (
                <span className={styles.previewContainer}>
                    <span className={styles.badge}>
                        <Icon name="su-snippet" />
                        <span>{badgeLabel}</span>
                    </span>
                    <span className={styles.description}>{id}</span>
                </span>
            );
        }

        const blockCount = typeof snippet.block_count === 'number'
            ? snippet.block_count
            : (Array.isArray(snippet.blocks) ? snippet.blocks.length : null);
        const tooltip = snippet.description
            ? `${snippet.title} — ${snippet.description}`
            : snippet.title;

        return (
            <span className={styles.previewContainer} title={tooltip}>
                <span className={styles.badge}>
                    <Icon name="su-snippet" />
                    <span>{badgeLabel}</span>
                </span>
                <strong className={styles.title}>{snippet.title}</strong>
                {blockCount !== null && (
                    <span className={styles.description}>({blockCount} {innerBlocksLabel})</span>
                )}
                {snippet.description && (
                    <span className={styles.description}>— {snippet.description}</span>
                )}
            </span>
        );
    }
}

export default class SingleReusableBlockSelectionBlockPreviewTransformer implements BlockPreviewTransformer {
    transform(value: *): Node {
        const id = typeof value === 'object' && value ? value.id : value;

        if (!id || typeof id !== 'string') {
            return null;
        }

        return <PreviewItem id={id} key={id} />;
    }
}
