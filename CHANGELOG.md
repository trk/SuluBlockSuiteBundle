# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-10-17

### Added
- **Core Utilities & Security Services**: Extracted `Util\ClassList`, `Util\PropReader`, `Security\AttributeSanitizer`, and `Security\CssScoper` as reusable framework-agnostic building blocks.
- **Metadata-Driven Media & Link Derivation**: `DefaultAttributesTransformer` now inspects Sulu field metadata types (`single_media_selection`, `media_selection`, `link`) to automatically produce `<field>_url`, `<field>_srcset`, and `<field>_focus_style` for every media field and `<field>_url` for every link field.
- **Configurable Responsive `srcset` Formats**: Added `sulu_block_suite.media.srcset_formats` (`array<string, int>`) consumed by `SuluDataNormalizer::resolveSrcSet()`.
- **`NestedBlockFlattenerInterface` Extension Point**: Added autoconfigured `sulu_block_suite.nested_block_flattener` service tag (`supports()`, `flatten()`) so framework packages can unwrap nested layout containers when `ReusableBlockTransformer` expands a reusable block inside a column/slot.
- **Prioritized `rendering.template_namespaces` Map**: `sulu_block_suite.rendering.template_namespaces` is now a priority-keyed map (`namespace => priority`) sorted in descending priority order by `BlockRenderer`, enabling multi-bundle base + theme inheritance without config collisions.
- **Configurable Layout Defaults**: Added `sulu_block_suite.layout.defaults` (`array<string, scalar>`).
- **Snippet Template Key Constants**: Added `Template\TemplateKeys` (`PAGE_LAYOUT`, `REUSABLE_BLOCK`, `BLOCK_PRESET`) and starter snippet XML templates under `docs/examples/snippets/`.
- **Admin JS `presetWireframeRegistry`**: Added `presetWireframeRegistry` (`add`, `get`, `has`, `clear`) in `assets/admin/registries/presetWireframeRegistry.js` with generic wireframe fallback in `PresetGallery`.
- **Schema-Driven `HotspotPicker`**: `HotspotPickerPropertyResolver` and `HotspotPicker` now read `colors`, `types`, and `triggers` from XML `<param>` definitions rather than hardcoded Preline options.
- **Architecture Guard Suite**: Added `Tests\Architecture\FrameworkAgnosticTest` and `Tests\Translations\TranslationCatalogIntegrityTest` enforcing 100% framework-agnostic boundaries and translation key integrity across all 6 locales (`en`, `tr`, `de`, `ru`, `es`, `fr`).

### Changed
- **Metadata-Driven Search, Dynamic Mapping & Target Fields**: `BlockSuitePageReindexEnhancer`, `DynamicContentHydrator`, `BlockTargetFieldMetadataResolver`, and `BlockSuiteFormMetadataVisitor` now resolve fields by Sulu metadata field types (`text_line`, `text_area`, `text_editor`, `single_media_selection`, `media_selection`, `link`, `block`) instead of hardcoded block/field name lists.
- **Dynamic Content i18n via `TranslatorInterface`**: Replaced hardcoded PHP multi-locale label arrays across all Dynamic Content source providers with `TranslatorInterface` calls using `sulu_block_suite.dynamic_*` keys.
- **`AbstractBlockComponent` Vocabulary**: Reduced `UNIVERSAL_DATA_KEYS` to a framework-agnostic minimum and added overridable `dataKeys()` and `capabilityMap()` hooks for framework subclasses.
- **`block_preset` Target Block Options**: `BlockSuiteFormMetadataVisitor` derives `target_block` select options directly from the `<types><type ref="..."/></types>` list on the `block` property of `block_preset.xml`.

### Removed
- Removed `sulu_block_suite.auto_blocks` automatic block type injection (`AUTO_BLOCKS_TAG` and `reusable_block`/`page_layout`/`block_preset` stub injection) in favor of Sulu-native `<types><type ref="..."/></types>` and `xi:include`.
- Removed `config/forms/block_suite_general.xml` and `config/forms/fields/*.xml` from Core (styling tabs are now owned by framework packages and included via native `xi:include`).
- Removed `include_fields`, `exclude_fields`, and `text_align` attributes from `sulu_block_suite.tabs` tag (retaining `exclude`, `enabled`, `shared`, and `namespace_props`).
- Removed automatic `config/templates/snippets` directory registration from `SuluBlockSuiteExtension` (snippet templates are now owned by the host project).
- Removed `LayoutResolver::resolveWrapperClasses()` and `*_wrapper_classes` Twig functions.
- Removed Preline-specific translation keys from `translations/admin.*.json`.

## [1.0.0] - 2026-10-15

### Added
- Compile-time native Sulu XML block tabs via `BlockSuiteFormMetadataVisitor` and `<TabSection>` (`__tabs` horizontal WAI-ARIA tab bar inside Sulu `FieldBlocks`).
- Automatic `props/*` namespacing for all non-content block sections (`settings`, `general`, `dynamic_content`, `advanced`, and custom tabs) with live Sulu property resolution via `BlockSuiteMetadataResolver`.
- Automatic shared tab injection (`general`, `dynamic_content`, `advanced`) with configurable exclusion via `<tag name="sulu_block_suite.tabs" exclude="..."/>`.
- Dynamic Content / Smart Content Engine (`DynamicContentHydrator` and source providers for Sulu Pages, Articles, Categories, Media Collections, and SmartContent providers) with full Sulu Admin Live Preview support.
- Preset & Reusable Block workflow inside the `Advanced` tab (`PresetSelectionField`, searchable preset gallery modal, one-click preset clear, Save as Preset drawer, Save as Reusable Block drawer).
- Native collapsed block header preview badges (`PresetSelectionBlockPreviewTransformer`) displaying active preset, custom block name, dynamic source, and hidden status.
- Reusable Global Blocks (`reusable_block`) and Central Page Layouts (`page_layout`) with automatic Sulu HTTP cache invalidation (`ReusableBlockCacheInvalidationSubscriber`).
- Scoped `.el-element` CSS rewriting and XSS-safe custom HTML attribute transformation (`DefaultAttributesTransformer`, `BlockRenderer`).
- Full 6-language internationalization (`en`, `tr`, `de`, `ru`, `es`, `fr`).
