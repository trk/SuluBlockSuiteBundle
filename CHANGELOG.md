# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.2] - 2026-10-26

### Added
- **Conditional Multi-Webspace Support (`sulu_block_suite.multi_webspace.enabled`)**: Automatic detection of `Totoglu\SuluMultiWebspaceBundle\SuluMultiWebspaceBundle` with graceful single-webspace degradation (hiding `webspace` fields, expanding sibling `colSpan=6` fields to `12`, and enforcing global template uniqueness).
- **Page Layout Uniqueness & Optional Default Template (`PageLayoutUniquenessDataMapper`)**: Enforces 1-to-1 `(webspace, target_template)` uniqueness for default template layouts while allowing optional `target_template` (`— None (Custom Layout) —`) and explicit `title` (`Layout Name`) for custom per-page layouts.
- **Snippet Live Preview (`SnippetPreviewProvider`, `SnippetPreviewController`, `PageLayoutPreviewResolver`)**: Real-time Sulu Admin live preview for `page_layout` and `reusable_block` snippets with automatic layout-usage page discovery (`target_template` and explicit `layout` assignment).
- **AI & MCP Integration (`BlockSuiteMcpTools`, `BlockSuiteSchemaInspector`, `BlockSuitePayloadComposer`)**: Schema inspection, block/layout discovery, and structured payload composition tools for AI/MCP workflows.
- **Inline Field Option Groups & Preview Block Focus (`withBlockFieldOptions`, `initPreviewBlockFocus`)**: Native Sulu `ArrowMenu` popover option groups on content fields and click-to-focus block synchronization in Sulu Live Preview.
- **Deep Module Architecture**: Extracted 10 cohesive deep modules (`SnippetScopeResolver`, `PageLayoutPreviewResolver`, `FormSelectFieldPopulator`, `SharedXmlSectionLoader`, `PopoverOptionGroupNormalizer`, `DynamicSectionFormBuilder`, `PresetPayloadNormalizer`, `SuluContentDimensionExtractor`, `DynamicContentQueryFilterNormalizer`, `DynamicContentMappingApplier`).

## [0.2.1] - 2026-10-23

### Added
- **Search Dynamic Content Sources (`SuluSearchSourceProvider`)**: Added `search_results` (multi-item) and `search_context` (single-item) Dynamic Content sources to populate multi-item blocks (`grid`, `list`, `table`, `accordion`, `description_list`, and repeated `card` blocks) and header blocks from active search queries (`?q=`).
- **Multi-Item Dynamic Content Pagination (`dynamic_pagination` & `dynamic_per_page`)**: Added configurable pagination styles (`none`, `numbers`, `prev_next`) and items-per-page slicing (`dynamic_per_page`, default `20`) independent of the total item cap (`dynamic_limit`), with automatic `_pagination` template rendering in `BlockRenderer`.

## [0.2.0] - 2026-10-22

### Added
- **`FormBlockTransformer` & Snippet Title Bridge for `sulu/form-bundle`**: Added `FormBlockTransformer` and `FormTitleProvider` bridge to seamlessly resolve and hydrate Sulu dynamic forms within block suite pipelines.

### Fixed
- **Security Hardening**: Hardened URL protocol validation (`javascript:`, `vbscript:`, `data:`, `blob:`, `file:` rejection) in `SuluDataNormalizer` and `DefaultAttributesTransformer`, tightened path traversal validation regexes (`/^[a-zA-Z0-9_-]+$/`) in `YamlBlockPresetProvider`, `PageLayoutProvider`, and `ExportBlockPresetsCommand`, and added bounded recursion guards (`MAX_RECURSION_DEPTH = 10` and visited snippet tracking) in `LayoutTwigExtension`.

## [0.1.0] - 2026-10-18

### Added
- **Compile-Time Native XML Block Tabs (`BlockSuiteFormMetadataVisitor` & `<TabSection>`)**: Horizontal WAI-ARIA tab bar (`__tabs`) inside Sulu `FieldBlocks` with automatic `props/*` namespacing for non-`content` sections (`settings`, `general`, `dynamic_content`, `advanced`, and custom tabs) and live Sulu property resolution via `BlockSuiteMetadataResolver`.
- **Automatic Shared Tab Injection**: Shared `dynamic_content` and `advanced` tabs with configurable opt-out via `<tag name="sulu_block_suite.tabs" exclude="..." enabled="..." shared="..." namespace_props="..."/>`.
- **Core Utilities & Security Services**: Framework-agnostic `Util\ClassList`, `Util\PropReader`, `Security\AttributeSanitizer`, and `Security\CssScoper` (`.el-element` scoped CSS rewriting and XSS-safe HTML attribute parsing).
- **Metadata-Driven Media, Link, Search & Dynamic Content Resolution**: `DefaultAttributesTransformer`, `SuluDataNormalizer`, `DynamicContentHydrator`, `BlockTargetFieldMetadataResolver`, and `BlockSuitePageReindexEnhancer` inspect Sulu field metadata types (`single_media_selection`, `media_selection`, `link`, `text_line`, `text_area`, `text_editor`, `block`) to produce `<field>_url`, `<field>_srcset`, and `<field>_focus_style` and resolve search/dynamic fields without hardcoded block or field lists.
- **Configurable Responsive `srcset` Formats**: `sulu_block_suite.media.srcset_formats` (`array<string, int>`) consumed by `SuluDataNormalizer::resolveSrcSet()`.
- **Dynamic Content / Smart Content Engine**: `DynamicContentHydrator` and source providers for Sulu Pages, Articles, Categories, Media Collections, and SmartContent providers (`TranslatorInterface`-backed `sulu_block_suite.dynamic_*` labels) with full Sulu Admin Live Preview support.
- **Preset & Reusable Block Workflow**: `PresetSelectionField`, searchable `PresetGallery` modal with extensible `presetWireframeRegistry`, one-click preset clear, Save as Preset (`block_preset`) drawer, Save as Reusable Block (`reusable_block`) drawer, and collapsed block header badges (`PresetSelectionBlockPreviewTransformer`).
- **Reusable Global Blocks & Central Page Layouts**: Built-in `reusable` block (`config/templates/blocks/reusable.xml`), `ReusableBlockTransformer` with `NestedBlockFlattenerInterface` extension point (`sulu_block_suite.nested_block_flattener`), `LayoutResolver` (`sulu_block_suite.layout.defaults`), starter snippet templates under `docs/examples/snippets/` (`Template\TemplateKeys`), `sulu:block-suite:init` scaffolding command (`InitBlockSuiteCommand`), and automatic Sulu HTTP cache invalidation (`ReusableBlockCacheInvalidationSubscriber`).
- **Prioritized `rendering.template_namespaces` Map**: Priority-keyed map (`namespace => priority`) sorted descending by `BlockRenderer` for multi-bundle base + theme inheritance.
- **Schema-Driven `HotspotPicker`**: `HotspotPickerPropertyResolver` and `HotspotPicker` read `colors`, `types`, and `triggers` from XML `<param>` definitions.
- **Abstract Block Component Base (`AbstractBlockComponent`)**: Framework-agnostic base component with overridable `dataKeys()` and `capabilityMap()` hooks.
- **Architecture & Translation Guard Suite**: `Tests\Architecture\FrameworkAgnosticTest` and `Tests\Translations\TranslationCatalogIntegrityTest` across all 6 supported locales (`en`, `tr`, `de`, `ru`, `es`, `fr`).
