# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-15

### Added
- Compile-time native Sulu XML block tabs via `BlockSuiteFormMetadataVisitor` and `<TabSection>` (`__tabs` horizontal WAI-ARIA tab bar inside Sulu `FieldBlocks`).
- Automatic `props/*` namespacing for all non-content block sections (`settings`, `general`, `dynamic_content`, `advanced`, and custom tabs) with live Sulu property resolution via `BlockSuiteMetadataResolver`.
- Automatic shared tab injection (`general`, `dynamic_content`, `advanced`) with configurable exclusion via `sulu_block_suite.exclude_dynamic_blocks` and `<tag name="sulu_block_suite.tabs" exclude="..."/>`.
- Dynamic Content / Smart Content Engine (`DynamicContentHydrator`, `DynamicFieldMappingField`, and source providers for Sulu Pages, Articles, Categories, and Media Collections) with full Sulu Admin Live Preview support.
- Preset & Reusable Block workflow inside the `Advanced` tab (`PresetSelectionField`, searchable preset gallery modal, one-click preset clear, Save as Preset drawer, Save as Reusable Block drawer).
- Native collapsed block header preview badges (`PresetSelectionBlockPreviewTransformer`) displaying active preset, custom block name, dynamic source, and hidden status.
- Automatic block type injection (`BlockSuiteAutoBlocksXmlVisitor`) via `<tag name="sulu_block_suite.auto_blocks"/>`.
- Reusable Global Blocks (`reusable_block`) and Central Page Layouts (`page_layout`) with automatic Sulu HTTP cache invalidation (`ReusableBlockCacheInvalidationSubscriber`).
- Scoped `.el-element` CSS rewriting and XSS-safe custom HTML attribute transformation (`DefaultAttributesTransformer`, `BlockRenderer`).
- Full 6-language internationalization (`en`, `tr`, `de`, `ru`, `es`, `fr`).
