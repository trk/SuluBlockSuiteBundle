# Changelog

All notable changes to `totoglu/sulu-block-suite-bundle` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-10

### Added
- **Reusable Global Blocks**: Centrally managed shared sections (`reusable_block.xml`) with dynamic block type discovery, single-source updates, and zero hardcoded block structures.
- **SingleReusableBlockSelection Field Type**: Custom React admin UI with modal search, thumbnail previews, block count badges, and live usage statistics ("Used in X resources").
- **Style & Prop Presets**: Dual-source presets combining project YAML files (`config/blocks/presets/*.yaml`) and live CMS snippets (`block_preset.xml`).
- **Dynamic Component Type Resolution**: `TargetBlockOptionsFormMetadataVisitor` discovers project blocks dynamically from schemas and templates.
- **Schema-Driven Block Props Engine**: Decoupled React modal (`BlockProps.js`) rendering form fields dynamically from project YAML schemas (`config/blocks/props/*.yaml`).
- **Template Layout Engine**: Central layout block rendering and page container decoupling via `layout_render()` and `layout_classes()` Twig functions.
- **Full Internationalization (i18n)**: Multilingual support in 6 languages (`en`, `tr`, `de`, `ru`, `es`, `fr`) across admin navigation, field schemas, and error messages.
- **Zero Bundled Block Coupling**: Fully agnostic architecture with no hardcoded blocks, presets, or schemas inside the bundle.
- **Strict Quality Baseline**: PHP 8.2 - 8.5 compatibility, PHPStan Level Max, PHPUnit test suite with 75 unit tests and 275 assertions, PSR-12 coding standards.
