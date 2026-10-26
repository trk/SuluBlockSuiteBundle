// @flow
import Form from 'sulu-admin-bundle/components/Form';
import Select from 'sulu-admin-bundle/containers/Form/fields/Select';
import SingleSelect from 'sulu-admin-bundle/containers/Form/fields/SingleSelect';
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
import {
    enhanceFieldRegistryWithBlockOptions,
    withBlockFieldOptions,
} from './fieldEnhancers/withBlockFieldOptions';
import initPreviewBlockFocus from './previewBlockFocus/initPreviewBlockFocus';
import presetWireframeRegistry from './registries/presetWireframeRegistry';

// 1. Enable inline horizontal `<TabSection>` for `<section name="__tabs">`
Form.Section = TabSection;

// 2. Register `__block` hierarchy & `props/*` condition data provider
conditionDataProviderRegistry.add(blockConditionDataProvider);

// 3. Initialize Sulu Live Preview block click-to-focus & auto-collapse listener
initPreviewBlockFocus();

// 4. Register native template and webspace selection field types (delegating to Sulu's native SingleSelect & Select)
if (!fieldRegistry.has('single_template_selection')) {
    fieldRegistry.add('single_template_selection', SingleSelect);
}
if (!fieldRegistry.has('template_selection')) {
    fieldRegistry.add('template_selection', Select);
}
if (!fieldRegistry.has('single_webspace_select')) {
    fieldRegistry.add('single_webspace_select', SingleSelect);
}
if (!fieldRegistry.has('webspace_select')) {
    fieldRegistry.add('webspace_select', Select);
}

// 5. Enhance standard Sulu field types with inline ArrowMenu option groups via `<params>`
enhanceFieldRegistryWithBlockOptions(fieldRegistry);

// 6. Register native Block Suite field types
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
    enhanceFieldRegistryWithBlockOptions,
    initPreviewBlockFocus,
    presetWireframeRegistry,
    withBlockFieldOptions,
};
export default PresetSelection;
