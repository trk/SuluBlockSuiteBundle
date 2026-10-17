# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-10-18

### Added
- **Compile-Time Native XML Block Tabs (`BlockSuiteFormMetadataVisitor` & `<TabSection>`)**: Horizontal WAI-ARIA tab bar (`__tabs`) inside Sulu `FieldBlocks` with automatic `props/*` namespacing for non-`content` sections (`settings`, `general`, `dynamic_content`, `advanced`, and custom tabs) and live Sulu property resolution via `BlockSuiteMetadataResolver`.
- **Automatic Shared Tab Injection**: Shared `dynamic_content` and `advanced` tabs with configurable opt-out via `<tag name="sulu_block_suite.tabs" exclude="..." enabled="..." shared="..." namespace_props="..."/>`.
- **Core Utilities & Security Services**: Framework-agnostic `Util\ClassList`, `Util\PropReader`, `Security\AttributeSanitizer`, and `Security\CssScoper` (`.el-element` scoped CSS rewriting and XSS-safe HTML attribute parsing).
- **Metadata-Driven Media, Link, Search & Dynamic Content Resolution**: `DefaultAttributesTransformer`, `SuluDataNormalizer`, `DynamicContentHydrator`, `BlockTargetFieldMetadataResolver`, and `BlockSuitePageReindexEnhancer` inspect Sulu field metadata types (`single_media_selection`, `media_selection`, `link`, `text_line`, `text_area`, `text_editor`, `block`) to produce `<field>_url`, `<field>_srcset`, and `<field>_focus_style` and resolve search/dynamic fields without hardcoded block or field lists.
- **Configurable Responsive `srcset` Formats**: `sulu_block_suite.media.srcset_formats` (`array<string, int>`) consumed by `SuluDataNormalizer::resolveSrcSet()`.
- **Dynamic Content / Smart Content Engine**: `DynamicContentHydrator` and source providers for Sulu Pages, Articles, Categories, Media Collections, and SmartContent providers (`TranslatorInterface`-backed `sulu_block_suite.dynamic_*` labels) with full Sulu Admin Live Preview support.
- **Preset & Reusable Block Workflow**: `PresetSelectionField`, searchable `PresetGallery` modal with extensible `presetWireframeRegistry`, one-click preset clear, Save as Preset (`block_preset`) drawer, Save as Reusable Block (`reusable_block`) drawer, and collapsed block header badges (`PresetSelectionBlockPreviewTransformer`).
- **Reusable Global Blocks & Central Page Layouts**: Built-in `reusable` block (`config/templates/blocks/reusable.xml`), `ReusableBlockTransformer` with `NestedBlockFlattenerInterface` extension point (`sulu_block_suite.nested_block_flattener`), `LayoutResolver` (`sulu_block_suite.layout.defaults`), starter snippet templates under `docs/examples/snippets/` (`Template\TemplateKeys`), and automatic Sulu HTTP cache invalidation (`ReusableBlockCacheInvalidationSubscriber`).
- **Prioritized `rendering.template_namespaces` Map**: Priority-keyed map (`namespace => priority`) sorted descending by `BlockRenderer` for multi-bundle base + theme inheritance.
- **Schema-Driven `HotspotPicker`**: `HotspotPickerPropertyResolver` and `HotspotPicker` read `colors`, `types`, and `triggers` from XML `<param>` definitions.
- **Abstract Block Component Base (`AbstractBlockComponent`)**: Framework-agnostic base component with overridable `dataKeys()` and `capabilityMap()` hooks.
- **Architecture & Translation Guard Suite**: `Tests\Architecture\FrameworkAgnosticTest` and `Tests\Translations\TranslationCatalogIntegrityTest` across all 6 supported locales (`en`, `tr`, `de`, `ru`, `es`, `fr`).
