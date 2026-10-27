# Sulu Block Suite Bundle

[![Latest Version](https://img.shields.io/badge/version-0.2.2-blue.svg)](https://github.com/trk/SuluBlockSuiteBundle)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.3%20%7C%208.5-8892BF.svg)](https://php.net)
[![Sulu CMS](https://img.shields.io/badge/Sulu%20CMS-%5E3.0-orange.svg)](https://sulu.io)
[![Symfony](https://img.shields.io/badge/Symfony-%5E7.4-black.svg)](https://symfony.com)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](https://phpstan.org)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![i18n](https://img.shields.io/badge/i18n-en%20%7C%20tr%20%7C%20de%20%7C%20ru%20%7C%20es%20%7C%20fr-lightgrey.svg)](#internationalization-i18n)

A **100% UI-framework-agnostic block infrastructure engine** for **Sulu CMS 3.x** that cleanly separates block infrastructure (Core), UI component frameworks (such as Preline UI, Bootstrap, UIkit, or custom design systems), and project-level block composition:

1. **Compile-Time Native XML Block Tabs (`BlockSuiteFormMetadataVisitor` + `<TabSection>`)**: Define block fields using standard Sulu XML `<section>` elements (`content`, `settings`, `general`, or custom sections) and automatically inherit shared `Dynamic Content` and `Advanced` tabs at form metadata compilation time—with zero runtime XHR schema requests.
2. **Automatic `props/*` Namespacing & Default Value Resolution (`BlockSuiteMetadataResolver`)**: Fields inside `content` stay at the block root (`block.title`, `block.items`), while fields in non-`content` tabs (`settings`, `general`, `dynamic_content`, `advanced`) are automatically namespaced under `props/*` (`block.props.tag`, `block.props.preset`), with XML `<param name="default_value">` defaults applied automatically during website rendering.
3. **Inline Field Option Groups (`withBlockFieldOptions` + `<params><param type="collection">`)**: Attach native Sulu `ArrowMenu` + `ArrowMenu.SingleItemSection` icon option menus directly to standard Sulu fields (`text_line`, `link`, `single_icon_selection`, `single_media_selection`, `text_area`, `text_editor`, `color`, `number`, `email`, `phone`, `url`) on the `Content` tab—storing selected values in `props/<target>` (or `<target>` on sub-items), resolving defaults via hidden backing fields, and automatically pruning duplicate fields from `settings`.
4. **Dynamic Content / Smart Content Engine & Mixed Static/Dynamic Sub-Items**: Bind any block or repeater block to live CMS data sources (`pages`, `articles`, `categories`, `media_collection`, `search`, SmartContent providers, or custom `ConfigurableDynamicContentSourceProviderInterface` services) with canonical field-type auto-mapping, `{{field}}` inline token interpolation, custom/computed outputs, mixed static & dynamic sub-items (`is_dynamic_template`), and collection summary/empty-state/pagination rendering.
5. **Two-Way Live Preview Block Focus & Sibling Auto-Collapse (`initPreviewBlockFocus`)**: Click any rendered block (including deeply nested `section > rows > columns > blocks > items` hierarchies and `reusable` blocks) in the Sulu Live Preview iframe to expand its ancestor tabs/accordions, scroll to and highlight the block in the Admin form, or click a block in the Admin form to scroll and pulse-highlight it in the Live Preview iframe.
6. **Preset Gallery, Wireframe Registry & Reusable Global Blocks**: Browse YAML and CMS-managed presets in a visual gallery (`preset_selection`), register custom React wireframe previews via `presetWireframeRegistry`, save blocks as presets or reusable snippets directly from the `Advanced` tab, and render central page layouts (`layout_render()`) with automatic Sulu HTTP cache reference invalidation.
7. **Full Internationalization (i18n)**: 6 admin languages supported out of the box (`en`, `tr`, `de`, `ru`, `es`, `fr`).

---

## Table of Contents

- [Architecture & Responsibility Boundaries](#architecture--responsibility-boundaries)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration Reference](#configuration-reference)
- [Core Data Contract](#core-data-contract)
- [Twig Functions & Filters Reference](#twig-functions--filters-reference)
- [CLI Commands](#cli-commands)
- [Extension Points](#extension-points)
- [Feature Guides](#feature-guides)
  - [1. Native Sulu XML Block Tabs & `sulu_block_suite.tabs` Tag](#1-native-sulu-xml-block-tabs--sulu_block_suitetabs-tag)
  - [2. Inline Field Option Groups (`ArrowMenu` via `<params>`)](#2-inline-field-option-groups-arrowmenu-via-params)
  - [3. Dynamic Content Engine, Mixed Sub-Items & Token Interpolation](#3-dynamic-content-engine-mixed-sub-items--token-interpolation)
  - [4. Two-Way Live Preview Block Focus & Auto-Collapse](#4-two-way-live-preview-block-focus--auto-collapse)
  - [5. Preset Gallery, Wireframe Registry & Reusable Block Actions](#5-preset-gallery-wireframe-registry--reusable-block-actions)
  - [6. Scoped `.el-element` CSS & Sanitized Custom Attributes](#6-scoped-el-element-css--sanitized-custom-attributes)
  - [7. Reusable Global Blocks, Page Layouts & Fallback Chains (`|`)](#7-reusable-global-blocks-page-layouts--fallback-chains-)
- [Writing a Framework Package](#writing-a-framework-package)
- [Composing Blocks in a Project](#composing-blocks-in-a-project)
- [Internationalization (i18n)](#internationalization-i18n)
- [Verification & Quality Assurance](#verification--quality-assurance)
- [License](#license)

---

## Architecture & Responsibility Boundaries

| Layer | Responsibility | Does NOT Contain |
| --- | --- | --- |
| **`sulu-block-suite-bundle` (Core)** | Transform pipeline, metadata-driven media/link/search/dynamic resolution, inline field option enhancer (`withBlockFieldOptions`), two-way Live Preview block focus (`initPreviewBlockFocus`), preset/reusable/layout engines, admin field types (`preset_selection`, `single_reusable_block_selection`, `hotspot_picker`), security utilities (`AttributeSanitizer`, `CssScoper`), tab/props `BlockSuiteFormMetadataVisitor` & `BlockSuiteMetadataResolver` | UI block definitions (except `reusable`), framework styling fields (`margin_*`, `padding_*`), CSS framework class maps, framework translations, or snippet template auto-registration |
| **Framework Package** (e.g. `sulu-preline-blocks-bundle`, Bootstrap, UIkit) | Block XML definitions, styling tabs (`general` via native `xi:include`), Twig components extending `AbstractBlockComponent`, CSS class maps, `NestedBlockFlattenerInterface` implementations, framework translations | Workflow, metadata resolution, or persistence logic already provided by Core |
| **Project** (`./config`, `./templates`) | Sulu-native `<types><type ref="..."/></types>` block composition, project snippet XMLs (`page_layout`, `reusable_block`, `block_preset`), project-specific blocks (`header`, `footer`), responsive image `srcset_formats`, layout defaults, YAML presets | Core or framework infrastructure |

---

## Requirements

- **PHP**: `>= 8.3` (strictly typed PHP 8.5 supported)
- **Sulu CMS**: `~3.0.10`
- **Symfony**: `^7.4`
- **Doctrine ORM**: `^3.0`

---

## Installation

### 1. Composer Package

```bash
composer require trk/sulu-block-suite-bundle
```

### 2. Enable the Bundle

```php
// config/bundles.php
return [
    // ...
    Totoglu\SuluBlockSuiteBundle\SuluBlockSuiteBundle::class => ['all' => true],
];
```

### 3. Register Admin Frontend Assets

1. Add the package dependency in `assets/admin/package.json`:
   ```json
   {
     "dependencies": {
       "sulu-block-suite-bundle": "file:../../vendor/trk/sulu-block-suite-bundle/assets/admin"
     }
   }
   ```

2. Import the bundle in `assets/admin/app.js` (or `assets/admin/index.js`):
   ```javascript
   import 'sulu-block-suite-bundle';
   ```

3. Build the admin assets:
   ```bash
   npm --prefix assets/admin install
   npm --prefix assets/admin run build
   ```

### 4. Initialize Project Snippet Templates (`sulu:block-suite:init`)

Core does not auto-register snippet templates so that each project retains full Sulu-native control over which blocks are allowed in `page_layout`, `reusable_block`, and `block_preset`. Run the initialization command to scaffold the starter templates into your project without overwriting existing files:

```bash
php bin/console sulu:block-suite:init
```

---

## Configuration Reference

```yaml
# config/packages/sulu_block_suite.yaml
sulu_block_suite:
    multi_webspace:
        # null (default): auto-enabled when Totoglu\SuluMultiWebspaceBundle\SuluMultiWebspaceBundle is registered;
        # when false, hides webspace selector fields in snippets and enforces global template uniqueness.
        enabled: null
    presets:
        enabled: true
        enable_snippet_presets: true
        # String or list of directories containing <block_type>.yaml preset definitions
        yaml_dir:
            - '%kernel.project_dir%/config/blocks/presets'
    reusable_blocks:
        enabled: true
    layout:
        enabled: true
        # String or list of directories containing <layout_key>.yaml layout definitions
        yaml_dir:
            - '%kernel.project_dir%/config/blocks/layouts'
        defaults:
            sidebar: 'none'
            header_variant: 'solid'
            container_width: 'boxed'
            content_padding: 'normal'
        mappings: {}
    rendering:
        # Priority-keyed map of Twig template namespaces (higher integer = higher priority).
        # Multiple framework bundles can prepend their own namespace without overwriting each other.
        template_namespaces:
            blocks: 1000
            '@SuluBlockSuite/blocks': 0
    media:
        # Map of Sulu image format keys to pixel widths for responsive <field>_srcset generation.
        srcset_formats:
            col-sm: 400
            col-md: 640
            col-lg: 960
            col-xl: 1280
            col-2xl: 1600
    preview_tags:
        # Additional field names excluded from collapsed block preview summaries in Sulu Admin
        excluded_fields: []
        # Additional text_line field names given primary title priority (1024) in collapsed block previews
        primary_text_fields: []
```

---

## Core Data Contract

When a block passes through `BlockTransformPipeline` (`ReusableBlockTransformer` at priority `110` → `DynamicContentTransformer` at `100` → `DefaultAttributesTransformer` at `90` → `FormBlockTransformer` at `85` → `PresetBlockVisitor` at `80`), Core enriches the normalized block array with a deterministic, framework-agnostic contract:

| Key | Type | Source & Behavior |
| --- | --- | --- |
| `_id` | `string` | Explicit `props.id` sanitized via `AttributeSanitizer::sanitizeId()`, or an auto-generated deterministic `el-<8-hex>` ID when `props.css` contains `.el-element` rules (or bare CSS declarations). |
| `_sulu_block_id` | `string` | Native Sulu block identifier (`_id` generated by `BlockIdGenerator`) preserved across transformation and propagated down nested container hierarchies (`rows`, `columns`, `blocks`, `items`) for Live Preview focus. |
| `_reusable_parent_block_id` | `string` | Set by `ReusableBlockTransformer` when expanding a `reusable` block so clicking any expanded inner block in Live Preview focuses the parent `reusable` block in Sulu Admin. |
| `_name` | `string` | Trimmed human-readable block label from `props.name` (when configured in the `Advanced` tab). |
| `_class` / `_classes` | `string` | Trimmed custom CSS class string from `props.class` / `props.custom_class`. |
| `_attributes` | `array<string, string>` | Parsed `key="value"` pairs from `props.attributes`, sanitized by `AttributeSanitizer::parse()` (blocks `on*` inline event handlers, `style`, `id`, `class`, and `javascript:`/`vbscript:`/`data:` URIs). In Sulu Live Preview mode, automatically includes `data-block-id="<_sulu_block_id>"` for page/article blocks (and omits it when `_in_page_layout` is `true`). |
| `_attributes_string` | `string` | Escaped HTML attribute string assembled from `_attributes` (ready for raw insertion on a wrapper element when not using `AbstractBlockComponent`). |
| `_scoped_css` | `string` | Sanitized CSS from `props.css` processed by `CssScoper::scope()`, rewriting `.el-element` (or wrapping bare declarations) to `#<_id>` and stripping `<script>`/`<style>` tags, `expression()`, `@import`, and `javascript:` URLs. `BlockRenderer` automatically prepends `<style>...</style>` when non-empty. |
| `_props` | `array<string, mixed>` | Normalized mirror of `props` with root-level `props/*` keys merged cleanly. |
| `<field>_url` | `string` | Automatically derived for every **media-type** (`single_media_selection`, `media_selection`) and **link-type** (`link`) field in the block's Sulu form metadata (e.g. `image` → `image_url`, `link` → `link_url`). |
| `<field>_srcset` | `string` | Automatically derived responsive `srcset` string for every **media-type** field using `sulu_block_suite.media.srcset_formats` (e.g. `image_srcset`). |
| `<field>_focus_style` | `string` | Automatically derived CSS `object-position: X% Y%;` focal point string from Sulu media focus coordinates for every **media-type** field (e.g. `image_focus_style`). |
| `_summary` | `array<string, mixed>` | Present when `props.dynamic_show_summary` is enabled on a Dynamic Content collection block (`total`, `filtered_total`, `showing`, `from`, `to`, `page`, `pages`, `is_filtered`, `text`). |
| `_empty_state` | `array<string, mixed>` | Present when a Dynamic Content collection query returns zero items (`is_empty: true`, `message: string`). |
| `_pagination` | `array<string, mixed>` | Present when `props.dynamic_pagination` is enabled on a Dynamic Content collection block (`current_page`, `per_page`, `total_items`, `total_pages`, `has_previous`, `has_next`, `previous_page`, `next_page`, `page_param`). |

---

## Twig Functions & Filters Reference

### Block Rendering & Transformation (`DynamicContentTwigExtension`)

| Function / Filter | Kind | Description |
| --- | --- | --- |
| `block_render(block, context = [])` | Function | Runs the block through `BlockTransformPipeline`, resolves the highest-priority template from `rendering.template_namespaces` (`{namespace}/{type}.html.twig`), injects `data-block-id` on the root HTML element in Live Preview, and prepends `_scoped_css` + optional `_summary` / `_empty_state` output. |
| `blocks_render(blocks, context = [])` | Function | Renders a list of blocks sequentially through `BlockCollectionRendererInterface` (`BlockRenderer::renderBlocks()`). |
| `block_transform(block, context = [])` | Function & Filter | Runs a single block array through `BlockTransformPipeline` and returns the normalized array (`?array`). |
| `hydrate_blocks(blocks, context = [])` | Function & Filter | Hydrates a flat list of blocks through `BlockTransformPipeline` (skipping suppressed `null` blocks). |
| `hydrate_block_tree(blocks, context = [])` | Function & Filter | Recursively transforms a block tree and its nested `blocks` children through `BlockTransformPipeline`. |
| `sulu_user_loggedin_and_preview()` | Function | Returns `true` when rendering inside Sulu Admin Live Preview (`attributes.preview == true` or request preview flag). |
| `sulu_preview_block_focus_script()` | Function | Returns the idempotent `<script>` snippet for Live Preview click-to-focus and highlight indicators (automatically appended once at depth 1 by `BlockRenderer`). |

### Layouts & Reusable Blocks (`LayoutTwigExtension`)

| Function | Description |
| --- | --- |
| `layout_render(layoutKey = 'default', context = [])` | Resolves a `page_layout` CMS snippet or YAML layout definition (supporting pipe-delimited fallback chains like `'article|default'`) and renders its `blocks`. Automatically marks layout blocks with `_in_page_layout: true` so they do not emit page-level `data-block-id` attributes. |
| `layout_load(layoutKey = 'default', locale = null)` | Loads the raw block array for the first matching layout in a pipe-delimited fallback chain (`list<array<string, mixed>>`). |
| `layout_exists(layoutKey = 'default', locale = null)` | Returns `true` if any layout in the fallback chain exists and contains at least one block. |
| `layout_has_block(blockType, layoutKey = 'default', locale = null)` | Recursively checks whether the resolved layout contains a block of the given `blockType` (inspecting nested `rows`, `columns`, `blocks`, `items`, and referenced `reusable` snippets). |
| `layout_classes(settings = [], groups = null)` | Resolves layout settings through `LayoutResolverInterface::resolveClasses()` into a space-separated CSS class string. |
| `layout_class(group, value = null)` | Resolves a single layout setting group/value through `LayoutResolverInterface::resolveClass()`. |
| `layout_attributes(settings = [])` | Resolves `data-layout-*` HTML attributes (`data-layout-sidebar`, `data-layout-header-variant`, etc.) from layout settings. |
| `reusable_block_load(uuid, locale = null)` | Loads the blocks from a published `reusable_block` snippet by UUID and registers its reference in Sulu's HTTP cache `ReferenceStoreInterface`. |

---

## CLI Commands

| Command | Description |
| --- | --- |
| `php bin/console sulu:block-suite:init` | Scaffolds starter snippet templates (`page_layout.xml`, `reusable_block.xml`, `block_preset.xml`), shared block type includes, and example YAML presets into the project without overwriting existing files (`--dry-run`, `--no-presets`, `--force`, `--source`). |
| `php bin/console sulu:block-suite:export-presets [block-type]` | Exports CMS-managed `block_preset` snippets to YAML files in `sulu_block_suite.presets.yaml_dir` (`--locale=en`, `--overwrite`). |

---

## Extension Points

1. **`BlockTransformerInterface` (`Totoglu\SuluBlockSuiteBundle\Transformer\BlockTransformerInterface`)**:
   - Autoconfigured via DI tag `sulu_block_suite.block_transformer`.
   - Implement `transform(array $block, array $context = []): ?array` and `getPriority(): int` to inspect, enrich, or suppress (`return null`) blocks during rendering.
2. **`NestedBlockFlattenerInterface` (`Totoglu\SuluBlockSuiteBundle\Transformer\NestedBlockFlattenerInterface`)**:
   - Autoconfigured via DI tag `sulu_block_suite.nested_block_flattener`.
   - Implement `supports(array $block): bool` and `flatten(array $block, callable $expandChild): array` so framework-specific container blocks (such as `section > rows > columns > blocks`) are cleanly unwrapped into leaf blocks when a `reusable` block is rendered inside an already nested column/slot.
3. **`DynamicContentSourceProviderInterface` & `ConfigurableDynamicContentSourceProviderInterface`**:
   - Autoconfigured via DI tag `sulu_block_suite.dynamic_source_provider`.
   - Extend `AbstractDynamicContentSourceProvider` to expose custom single or collection data sources, dynamic admin config fields (`getConfigFields()`, `getSingleConfigFields()`), custom/computed outputs (`registerCustomOutput()`), and collection metadata (`resolveCollectionMeta()`).
4. **`AbstractBlockComponent` (`Totoglu\SuluBlockSuiteBundle\Twig\Component\AbstractBlockComponent`)**:
   - Base class for Symfony UX Twig Components consuming block arrays via `BlockComponentPreRenderSubscriber`.
   - Override `protected function defaults(): array`, `protected function dataKeys(): array`, and `protected function capabilities(): array`.
   - Automatically forwards Live Preview `data-block-id`, `_id`, `_class`, `_attributes`, and `_scoped_css` to the component's root `ComponentAttributes` (`{{ attributes }}`).
   - Provides type-safe helpers (`ClassList`, `PropReader`, `AttributeSanitizer`, `CssScoper`) for prop reading and HTML attribute/class assembly.
5. **`presetWireframeRegistry` (Admin JS)**:
   - Exported from `sulu-block-suite-bundle` (`assets/admin/registries/presetWireframeRegistry`).
   - Register custom React wireframe preview components per block type (`presetWireframeRegistry.add('my_block', MyWireframeComponent)`); blocks without a custom wireframe automatically fall back to Core's generic wireframe preview.

---

## Feature Guides

### 1. Native Sulu XML Block Tabs & `sulu_block_suite.tabs` Tag

- Properties in `<section name="content">` (or `<section name="media">` and top-level properties outside a section) remain at the root of the block payload.
- Any other `<section>` (`settings`, `general`, `style`, `layout`, etc.) becomes its own horizontal tab in the exact order defined, and its fields are automatically namespaced under `props/*`.
- Core appends `dynamic_content` (`config/forms/block_suite_dynamic.xml`) and `advanced` (`config/forms/block_suite_advanced.xml`) at compile time unless customized via `<tag name="sulu_block_suite.tabs" .../>`:
  - `exclude="dynamic_content,advanced"`: Opt out of specific Core-injected tabs.
  - `enabled="false"`: Disable tab wrapping entirely for a block type (e.g. `reusable.xml`).
  - `shared="false"`: Wrap the block's own `<section>` elements into tabs without injecting Core's shared `dynamic_content` and `advanced` tabs.
  - `namespace_props="false"`: Keep fields in non-`content` sections at the root level instead of prefixing them with `props/`.
- **Automatic Collapsed Block Preview Tags**: `BlockSuiteFormMetadataVisitor::enhanceBlockPreviewTags()` automatically assigns `sulu.block_preview` priorities when a block does not declare explicit preview tags: Media (`2048`) → Primary Text (`1024..768`) → Rich Text (`512`) → Child Blocks (`256`).

---

### 2. Inline Field Option Groups (`ArrowMenu` via `<params>`)

Standard Sulu fields (`text_line`, `link`, `single_icon_selection`, `single_media_selection`, `text_area`, `text_editor`, `color`, `number`, `email`, `phone`, `url`) can attach one or more **Icon + `ArrowMenu.SingleItemSection` Popover** option groups directly via `<params><param name="..." type="collection">`:

```xml
<section name="content">
    <property name="title" type="text_line">
        <meta>
            <title lang="en">Title</title>
        </meta>
        <params>
            <param name="headline" value="true"/>

            <param name="tag" type="collection">
                <param name="_default" value="h2"/>
                <param name="_icon" value="heading"/>
                <param name="h1"><meta><title lang="en">H1</title></meta></param>
                <param name="h2"><meta><title lang="en">H2</title></meta></param>
                <param name="h3"><meta><title lang="en">H3</title></meta></param>
                <param name="h4"><meta><title lang="en">H4</title></meta></param>
                <param name="div"><meta><title lang="en">div</title></meta></param>
            </param>

            <param name="title_color" type="collection">
                <param name="_default" value="default"/>
                <param name="_icon" value="brush"/>
                <param name="default" value="Default"/>
                <param name="primary" value="Primary"/>
                <param name="muted" value="Muted"/>
            </param>
        </params>
    </property>
</section>
```

#### How Inline Field Option Groups Work

1. **Storage Path**:
   - On a tabbed block (`namespaceProps = true`), selecting an option writes to `props/<param_name>` (e.g. `props.tag = "h2"`, `props.title_color = "primary"`), while the field itself (`title`) keeps its native Sulu value (`string`, link object, media object, etc.).
   - On a flat sub-item inside `<block name="items">` (`namespaceProps = false`), selecting an option writes directly to `<param_name>` on the sub-item (e.g. `item.button_style = "solid"`).
2. **Meta Parameters inside `<param type="collection">`**:
   - `_default` (or `default_value`): Default option value (falls back to the first selectable option if omitted).
   - `_icon` (or `icon`): Trigger button icon (`heading`, `brush`, `size`, `style`, `align` shorthands, or any `su-*` / `fa-*` icon name). If omitted, inferred automatically from the option group name.
   - `_target` (or `target`): Override the target property name if it differs from the `<param name="...">` attribute.
   - `_title`: Custom popover section header title.
3. **Automatic `Settings` Tab Deduplication & Hidden Backing Fields**:
   - `BlockSuiteFormMetadataVisitor` automatically removes any duplicate `<property name="tag">` from non-`content` sections (such as `settings`). If `settings` becomes empty as a result, the `Settings` tab is automatically omitted.
   - A hidden (`visibleCondition="false"`) `single_select` backing `FieldMetadata` is registered on the form so Sulu's JSON schema validation and `BlockSuiteMetadataResolver` resolve the default value (`"h2"`) on the backend even before the block is re-saved.

---

### 3. Dynamic Content Engine, Mixed Sub-Items & Token Interpolation

Blocks can dynamically pull single items or collections from Sulu CMS sources without custom controllers (including live resolution during Sulu Admin Live Preview):

| Source Key | Mode | Provider Class | Description |
| --- | --- | --- | --- |
| `current_page` / `parent_page` / `specific_page` | Single | `SuluPageSourceProvider` | Current page, parent page, or a selected Sulu 3 page with content, SEO, and excerpt fields. |
| `page_selection` / `page_children` / `sibling_pages` / `custom_query` | Multiple | `SuluPageSourceProvider` | Published Sulu 3 page collections with parent filtering, sorting, limit, offset, and tag/category filters. |
| `single_article` / `sulu_articles` | Single / Multiple | `SuluArticleSourceProvider` | Published Sulu 3 articles (`SuluArticleBundle`) with template data, route URL, excerpt, and media fields. |
| `single_category` / `sulu_categories` | Single / Multiple | `SuluCategorySourceProvider` | Sulu categories (`CategoryManagerInterface`) with optional parent category filtering and localized translations. |
| `single_media` / `sulu_media_collection` | Single / Multiple | `SuluMediaCollectionSourceProvider` | Media assets from Sulu collections (`MediaManagerInterface`) with resolved image/thumbnail structures. |
| `search_context` / `search_results` | Single / Multiple | `SuluSearchSourceProvider` | Live search query metadata (`query`, `total`) and search result items (`title`, `url`, `description`, `image`). |
| `sulu_snippets` / `sulu_contacts` / `sulu_accounts` / `sulu_*` | Single / Multiple | `SmartContentSourceProviderAdapter` | Automatic bridge for any Sulu 3 `SmartContentProviderInterface` (`sulu_content.smart_content_provider`) with cached `ResourceLoaderProvider` loading and `ReferenceStoreInterface` tracking. |

#### Mixed Static & Dynamic Sub-Items (`is_dynamic_template`)

When a block defines a `<block name="items">` repeater, `BlockSuiteFormMetadataVisitor` automatically injects a **"Use as Dynamic Content Template" (`is_dynamic_template`)** checkbox at the top of each sub-item type (visible whenever `props.dynamic_source` is active):
- Sub-items with `is_dynamic_template = true` act as visual blueprints and are expanded in place for each fetched dynamic record.
- Unchecked sibling sub-items before or after the template item are preserved in their exact position as static items.
- If no sub-item has `is_dynamic_template` checked, the first sub-item (`items[0]`) is used as the template when `items` has at most one entry.

#### Inline `{{field}}` Token Interpolation & Sub-Block Mapping

- **Token Interpolation**: Any string field in a dynamically hydrated block or template sub-item can embed `{{field}}` tokens with pipe fallbacks and modifiers:
  - Fallback chain: `{{excerpt_title|title}}`
  - Length / truncation: `{{description:length=120}}` or `{{content:plain:truncate=160}}`
  - Header summary tokens (on parent block `title` / `subtitle` / `description`): `%total%`, `%count%`, `%showing%`, `%from%`, `%to%`, `%page%`, `%pages%`.
- **Canonical Field Types & Sub-Block Targets**: `BlockTargetFieldMetadataResolver` normalizes Sulu property types to canonical types (`string`, `text`, `media`, `link`, `icon`, `form`, `boolean`, `select`, `date`, `number`, `collection`) and supports mapping multi-value source fields (`categories`, `tags`, `taxonomies`) directly into sub-block repeaters (`elements`, `badges`, `buttons`, `specs`, `features`, `tags`) as well as virtual `buttons` and `badges` targets.
- **Custom / Computed Outputs**: Providers extending `AbstractDynamicContentSourceProvider` can register computed fields via `$this->registerCustomOutput($name, $label, $type, $resolver)`.

---

### 4. Two-Way Live Preview Block Focus & Auto-Collapse

Core integrates two-way **Preview ↔ Admin** block focus and automatic sibling block collapsing out of the box:

1. **Preview → Admin (`sulu-preview-block-click`)**:
   - During Sulu Live Preview, `DefaultAttributesTransformer` and `BlockRenderer` automatically attach `data-block-id="<_sulu_block_id>"` to rendered blocks and nested sub-blocks (`section > rows > columns > blocks > items`).
   - Blocks rendered via `PageLayoutProvider` (`layout_render()`) are marked with `_in_page_layout: true` so global header/footer layout blocks do not hijack page block focus.
   - Blocks expanded by `ReusableBlockTransformer` inherit `_reusable_parent_block_id` so clicking any element inside a reusable block focuses the parent `reusable` block in the Admin form.
   - Clicking a block in the preview iframe automatically switches to the matching ancestor tab (`Content`), expands every ancestor Sulu block card along the hierarchy, collapses sibling blocks at each level, scrolls the target block into view, and applies a focus highlight ring.
2. **Admin → Preview (`sulu-admin-block-focus`)**:
   - Clicking or expanding a block card in the Sulu Admin form dispatches `sulu-admin-block-focus` to the Live Preview iframe, which smoothly scrolls the matching `[data-block-id]` element into view and plays a pulse highlight outline.

---

### 5. Preset Gallery, Wireframe Registry & Reusable Block Actions

The `block_suite_advanced.xml` form includes `<property name="preset" type="preset_selection">`, which renders inside the **Advanced** tab and provides:
- **Active Preset Status Badge & Clear Action**: Displays the currently active preset name and source badge (`[CMS]` or `[YAML]`) with a one-click clear button.
- **Visual Preset Gallery (`Browse Presets`)**: Opens a searchable modal gallery of all YAML and CMS presets for the block type; selecting a preset immediately applies all `props/*` defaults across tabs via atomic `formStore.changeMultiple()`.
- **Apply Content & Styles**: Populates both content fields (`title`, `content`, nested items) and `props/*` from the active preset.
- **Save as Preset**: Captures the current block's live form state and saves/publishes a `block_preset` snippet in Sulu CMS.
- **Save as Reusable Block**: Saves and publishes the current block as a shared `reusable_block` snippet and optionally replaces the current block in-place with a `{ type: 'reusable', snippet: '<uuid>' }` reference.

---

### 6. Scoped `.el-element` CSS & Sanitized Custom Attributes

In the **Advanced** tab:
- **Custom Attributes (`props.attributes`)**: Enter one `key="value"` attribute per line (`data-hs-overlay="#modal"`, `aria-label="Hero Banner"`). Inline event handlers (`onclick`, `onload`, etc.) and dangerous URI schemes are stripped by `AttributeSanitizer`.
- **Scoped CSS (`props.css`)**: Write rules using `.el-element` (or bare declarations). `DefaultAttributesTransformer` assigns or reuses `_id`, rewrites `.el-element` to `#<_id>` via `CssScoper`, strips any `<script>`/`<style>` tags or dangerous CSS expressions, and `BlockRenderer` prepends `<style>...</style>` to the rendered block output.

---

### 7. Reusable Global Blocks, Page Layouts & Fallback Chains (`|`)

- **`ReusableBlockTransformer`**: Expands `reusable` blocks at priority `110` (before `DynamicContentTransformer` at `100` and `DefaultAttributesTransformer` at `90`) so expanded blocks undergo dynamic content hydration and attribute normalization uniformly. When a `reusable` block is expanded inside a nested slot/column, registered `NestedBlockFlattenerInterface` services unwrap container blocks into leaf blocks.
- **HTTP Cache Reference Tracking & Invalidation**: When a `reusable_block` or `page_layout` snippet is resolved or expanded, `SingleReusableBlockSelectionPropertyResolver`, `ReusableBlockTransformer`, and `PageLayoutProvider` register the snippet UUID in Sulu's `ReferenceStoreInterface` (`snippets`). `ReusableBlockCacheInvalidationSubscriber` listens to snippet modification, workflow transition, and removal events to invalidate Sulu HTTP cache tags and references.
- **Pipe-Separated Layout Fallback Chains**: `layout_render()` and `layout_load()` support `|`-delimited fallback chains (e.g. `homepage|default`, `article|default`, `search|default`, `error|default`):
  ```twig
  {{ layout_render(content.layout|default('search|default'), _context) }}
  ```

---

## Writing a Framework Package

You can build a standalone UI framework bundle (e.g., **Preline UI**, **Bootstrap**, **UIkit**) or a **base + theme inheritance hierarchy** on top of `sulu-block-suite-bundle` **without modifying Core**.

### 1. Prepend Block Directory & Prioritized Template Namespace

In your framework bundle's DI `Extension::prepend(ContainerBuilder $container)`:

```php
public function prepend(ContainerBuilder $container): void
{
    if ($container->hasExtension('sulu_admin')) {
        $container->prependExtensionConfig('sulu_admin', [
            'templates' => [
                'block' => [
                    'directories' => [
                        'my_uikit_blocks' => \dirname(__DIR__, 2) . '/config/templates/blocks',
                    ],
                ],
            ],
        ]);
    }

    if ($container->hasExtension('twig')) {
        $container->prependExtensionConfig('twig', [
            'paths' => [
                \dirname(__DIR__, 2) . '/templates' => 'MyUikitBlocks',
            ],
        ]);
    }

    if ($container->hasExtension('sulu_block_suite')) {
        $container->prependExtensionConfig('sulu_block_suite', [
            'rendering' => [
                'template_namespaces' => [
                    '@MyUikitBlocks/blocks' => 200,
                ],
            ],
        ]);
    }
}
```

### 2. Include Framework Styling Tabs via Native `xi:include`

Core does not inject opinionated styling fields. Instead, your framework bundle owns its styling tab (`<section name="general">`) and includes it in each block XML via standard `xi:include`:

```xml
<?xml version="1.0"?>
<template xmlns="http://schemas.sulu.io/template/template"
          xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
          xmlns:xi="http://www.w3.org/2001/XInclude"
          xsi:schemaLocation="http://schemas.sulu.io/template/template http://schemas.sulu.io/template/template-1.0.xsd">
    <key>hero_banner</key>
    <meta>
        <title lang="en">Hero Banner</title>
    </meta>
    <properties>
        <section name="content">
            <!-- Root-level content fields with optional inline <param type="collection"> option groups -->
        </section>
        <section name="settings">
            <!-- Block-specific settings (automatically namespaced under props/*) -->
        </section>
        <xi:include href="../../forms/general/general_justify.xml"
                    xpointer="xmlns(sulu=http://schemas.sulu.io/template/template)xpointer(/sulu:form/sulu:properties/sulu:*)"/>
    </properties>
</template>
```

---

## Composing Blocks in a Project

Projects control which blocks are available in each template using **Sulu-native `<types><type ref="..."/></types>`** lists and standard `xi:include` fragments:

```xml
<!-- config/templates/includes/content_block_types.xml -->
<?xml version="1.0"?>
<types xmlns="http://schemas.sulu.io/template/template">
    <type ref="hero_banner"/>
    <type ref="text"/>
    <type ref="image"/>
    <type ref="card"/>
    <type ref="grid"/>
    <type ref="reusable"/>
</types>
```

Include this list in your page templates, article templates, `reusable_block.xml`, and `block_preset.xml`:

```xml
<!-- config/templates/snippets/reusable_block.xml -->
<property name="blocks" type="block" default-type="text">
    <types>
        <type ref="section"/>
        <xi:include href="../includes/content_block_types.xml"
                    xpointer="xmlns(sulu=http://schemas.sulu.io/template/template)xpointer(/sulu:types/sulu:*)"/>
    </types>
</property>
```

---

## Sulu MCP & AI Integration

`SuluBlockSuiteBundle` includes zero-dependency AI/MCP services and auto-registers MCP tools when `mcp/sdk` (`sulu/mcp-bundle` or `symfony/ai-mate`) is present:

- **`Totoglu\SuluBlockSuiteBundle\Ai\BlockSuiteSchemaInspector`**:
  - `listBlocks(string $locale = 'en')`: Compact catalog of registered global blocks (`content_fields`, `field_option_keys`, `settings_props`, `preset_count`, `presets`).
  - `inspectBlock(string $blockType, string $locale = 'en', bool $includeAdvanced = false, bool $includeDynamic = false)`: Progressive-disclosure block schema returning `content_fields` (with inline `<params>` option groups), `settings_props`, and `presets` while omitting the ~26 `advanced`/`dynamic_content` fields unless requested.
  - `listPresets(string $blockType, string $locale = 'en', ?string $query = null)`: Lists or searches YAML and Snippet visual presets (`id`, `title`, `description`, `props`, `values`).
  - `isTranslatableField(string $fieldName)`: Distinguishes translatable content fields from technical `props/*` (`props/id`, `props/class`, `props/custom_css`, `props/attributes`, inline enum options).
- **`Totoglu\SuluBlockSuiteBundle\Ai\BlockSuitePayloadComposer`**:
  - `compose(string $blockType, array $content = [], array $props = [], ?string $preset = null, string $locale = 'en')`: Applies preset `values`/`props`, validates inline option group and select values, and synchronizes nested `props` and flat `props/*` keys for `sulu_block_add`, `sulu_block_update`, and `sulu_page_create`.
  - `preserveTechnicalProps(array $sourceBlockData, array $translatedBlockData)`: Restores non-translatable technical `props` and `props/*` keys after full-content AI translation.
- **`Totoglu\SuluBlockSuiteBundle\Mcp\BlockSuiteMcpTools`** (auto-tagged `mcp.tool` when `Mcp\Capability\Attribute\McpTool` exists):
  - `sulu_block_suite_blocks`
  - `sulu_block_suite_presets`
  - `sulu_block_suite_compose_block`
- **Symfony AI Mate (`extra.ai-mate`)**: Bundles [`INSTRUCTIONS.md`](INSTRUCTIONS.md) and `src/Mcp` scan directory in `composer.json`.

---

## Internationalization (i18n)

Complete admin (`translations/admin.<locale>.json`) and backend (`translations/sulu_block_suite.<locale>.yaml`) translations (`sulu_block_suite.*`) are bundled for **6 languages**: `en`, `tr`, `de`, `ru`, `es`, and `fr`.

---

## Verification & Quality Assurance

```bash
# Run PHPUnit 12 test suite (including Architecture/FrameworkAgnosticTest)
vendor/bin/phpunit -c packages/sulu-block-suite-bundle/phpunit.xml.dist

# Run PHPStan static analysis at level max
vendor/bin/phpstan analyse --configuration=packages/sulu-block-suite-bundle/phpstan.neon.dist

# Check PHP-CS-Fixer formatting
vendor/bin/php-cs-fixer fix --config=packages/sulu-block-suite-bundle/.php-cs-fixer.dist.php --dry-run --diff
```

---

## License

This bundle is open-source software licensed under the [MIT License](LICENSE).
