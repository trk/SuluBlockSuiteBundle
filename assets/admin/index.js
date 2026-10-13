// @flow
import fieldRegistry from 'sulu-admin-bundle/containers/Form/registries/fieldRegistry';
import {blockPreviewTransformerRegistry} from 'sulu-admin-bundle/containers/FieldBlocks';
import BlockProps, {
    BlockCollectionBlockPreviewTransformer,
    BlockPropsBlockPreviewTransformer,
} from './components/BlockProps';
import HotspotPicker from './components/HotspotPicker';
import SingleReusableBlockSelection, {
    SingleReusableBlockSelectionBlockPreviewTransformer,
} from './components/SingleReusableBlockSelection';

if (!fieldRegistry.has('block_props')) {
    fieldRegistry.add('block_props', BlockProps);
}
if (!fieldRegistry.has('single_reusable_block_selection')) {
    fieldRegistry.add('single_reusable_block_selection', SingleReusableBlockSelection);
}
if (!fieldRegistry.has('hotspot_picker')) {
    fieldRegistry.add('hotspot_picker', HotspotPicker);
}
if (!blockPreviewTransformerRegistry.has('single_reusable_block_selection')) {
    blockPreviewTransformerRegistry.add(
        'single_reusable_block_selection',
        new SingleReusableBlockSelectionBlockPreviewTransformer(),
        2048
    );
}
if (!blockPreviewTransformerRegistry.has('block')) {
    blockPreviewTransformerRegistry.add(
        'block',
        new BlockCollectionBlockPreviewTransformer(),
        256
    );
}
if (!blockPreviewTransformerRegistry.has('block_props')) {
    blockPreviewTransformerRegistry.add(
        'block_props',
        new BlockPropsBlockPreviewTransformer(),
        128
    );
}

export {
    BlockProps,
    BlockCollectionBlockPreviewTransformer,
    BlockPropsBlockPreviewTransformer,
    HotspotPicker,
    SingleReusableBlockSelection,
    SingleReusableBlockSelectionBlockPreviewTransformer,
};
export default BlockProps;
