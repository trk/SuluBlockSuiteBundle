// @flow
import Form from 'sulu-admin-bundle/components/Form';
import conditionDataProviderRegistry from 'sulu-admin-bundle/containers/Form/registries/conditionDataProviderRegistry';
import fieldRegistry from 'sulu-admin-bundle/containers/Form/registries/fieldRegistry';
import {blockPreviewTransformerRegistry} from 'sulu-admin-bundle/containers/FieldBlocks';
import TabSection from './components/TabSection';
import PresetSelection, {
    BlockCollectionBlockPreviewTransformer,
    PresetSelectionBlockPreviewTransformer,
} from './components/PresetSelection';
import HotspotPicker from './components/HotspotPicker';
import SingleReusableBlockSelection, {
    SingleReusableBlockSelectionBlockPreviewTransformer,
} from './components/SingleReusableBlockSelection';
import blockConditionDataProvider from './conditionDataProviders/blockConditionDataProvider';

// 1. Enable inline horizontal `<TabSection>` for `<section name="__tabs">`
Form.Section = TabSection;

// 2. Register `__block` hierarchy & `props/*` condition data provider
conditionDataProviderRegistry.add(blockConditionDataProvider);

// 3. Register native Block Suite field types
if (!fieldRegistry.has('preset_selection')) {
    fieldRegistry.add('preset_selection', PresetSelection);
}
if (!fieldRegistry.has('single_reusable_block_selection')) {
    fieldRegistry.add('single_reusable_block_selection', SingleReusableBlockSelection);
}
if (!fieldRegistry.has('hotspot_picker')) {
    fieldRegistry.add('hotspot_picker', HotspotPicker);
}

// 4. Register collapsed block preview transformers
if (!blockPreviewTransformerRegistry.has('preset_selection')) {
    blockPreviewTransformerRegistry.add(
        'preset_selection',
        new PresetSelectionBlockPreviewTransformer(),
        4096
    );
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

export {
    TabSection,
    PresetSelection,
    BlockCollectionBlockPreviewTransformer,
    PresetSelectionBlockPreviewTransformer,
    HotspotPicker,
    SingleReusableBlockSelection,
    SingleReusableBlockSelectionBlockPreviewTransformer,
    blockConditionDataProvider,
};
export default PresetSelection;
