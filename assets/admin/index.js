// @flow
import fieldRegistry from 'sulu-admin-bundle/containers/Form/registries/fieldRegistry';
import {blockPreviewTransformerRegistry} from 'sulu-admin-bundle/containers/FieldBlocks';
import BlockProps from './components/BlockProps';
import SingleReusableBlockSelection, {
    SingleReusableBlockSelectionBlockPreviewTransformer,
} from './components/SingleReusableBlockSelection';

if (!fieldRegistry.has('block_props')) {
    fieldRegistry.add('block_props', BlockProps);
}
if (!fieldRegistry.has('single_reusable_block_selection')) {
    fieldRegistry.add('single_reusable_block_selection', SingleReusableBlockSelection);
}
if (!blockPreviewTransformerRegistry.has('single_reusable_block_selection')) {
    blockPreviewTransformerRegistry.add(
        'single_reusable_block_selection',
        new SingleReusableBlockSelectionBlockPreviewTransformer(),
        2048
    );
}

export {BlockProps, SingleReusableBlockSelection, SingleReusableBlockSelectionBlockPreviewTransformer};
export default BlockProps;
