# Sulu Block Suite Bundle

[![Latest Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://github.com/trk/SuluBlockSuiteBundle)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.2%20%7C%208.5-8892BF.svg)](https://php.net)
[![Sulu CMS](https://img.shields.io/badge/Sulu%20CMS-%5E3.0-orange.svg)](https://sulu.io)
[![Symfony](https://img.shields.io/badge/Symfony-%5E7.4-black.svg)](https://symfony.com)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](https://phpstan.org)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![i18n](https://img.shields.io/badge/i18n-en%20%7C%20tr%20%7C%20de%20%7C%20ru%20%7C%20es%20%7C%20fr-lightgrey.svg)](#internationalization-i18n)

An enterprise-grade, native **Sulu CMS 3.x** block architecture bundle that delivers:
1. **Compile-Time Native XML Block Tabs (`BlockSuiteFormMetadataVisitor` + `<TabSection>`)**: Define block fields using standard Sulu XML `<section>` elements (`content`, `settings`, `style`, `layout`, or any custom tab) and automatically inherit shared `General`, `Dynamic Content`, and `Advanced` tabs at form metadata compilation time—with zero runtime XHR schema requests or modal drawers.
2. **Automatic `props/*` Namespacing**: Fields inside `content` stay at the block root (`block.title`, `block.content`), while fields in all other tabs (`settings`, `general`, `dynamic_content`, `advanced`, or custom tabs) are automatically namespaced under `props/*` (`block.props.tag`, `block.props.preset`, `block.props.margin_top`).
3. **Preset & Reusable Block Workflow in the `Advanced` Tab (`preset_selection`)**: Select project YAML or CMS-managed presets, preview in a visual gallery, apply content & styles atomically across all tabs, or save any configured block as a **Preset** (`block_preset`) or **Reusable Block** (`reusable_block`) directly from the `Advanced` tab.
4. **Native Collapsed Block Header Badges (`sulu.block_preview`)**: Displays active preset (`★ preset`), custom block name (`props.name`), dynamic source (`⚡ source`), and visibility status (`∅ hidden`) directly on collapsed Sulu block cards via `PresetSelectionBlockPreviewTransformer`.
5. **Dynamic Content / Smart Content Engine**: Bind any block or repeater block (`grid`, `accordion`, `gallery`, `slideshow`, `table`, etc.) to live CMS data sources (`pages`, `subpages`, `articles`, `categories`, `media_collection`, or custom providers) with visual field mapping, filtering, sorting, and pagination/offset.
6. **Zero-Config Automatic Block Type Discovery (`sulu_block_suite.auto_blocks`)**: Eliminate repetitive `<type ref="..."/>` lists in page and container XMLs via `<tag name="sulu_block_suite.auto_blocks" default="headline" exclude="row,column"/>`.
7. **Reusable Global Blocks & Central Page Layouts**: Render shared sections (`reusable`) and full page layouts (`layout_render()`) with automatic Sulu HTTP cache tag and reference invalidation.
8. **Full Internationalization (i18n)**: 6 languages supported out of the box (`en`, `tr`, `de`, `ru`, `es`, `fr`).

---

## Table of Contents

- [Key Highlights](#key-highlights)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration Reference](#configuration-reference)
- [Feature Guides](#feature-guides)
  - [1. Native Sulu XML Block Tabs & Flexible Custom Sections](#1-native-sulu-xml-block-tabs--flexible-custom-sections)
  - [2. Selective Tab Exclusion (`sulu_block_suite.tabs`)](#2-selective-tab-exclusion-sulu_block_suitetabs)
  - [3. Preset & Reusable Block Actions in the `Advanced` Tab](#3-preset--reusable-block-actions-in-the-advanced-tab)
  - [4. Scoped `.el-element` CSS & Custom Attributes](#4-scoped-el-element-css--custom-attributes)
  - [5. Dynamic Content / Smart Content Engine](#5-dynamic-content--smart-content-engine)
  - [6. Automatic Block Type Injection (`sulu_block_suite.auto_blocks`)](#6-automatic-block-type-injection-sulu_block_suiteauto_blocks)
  - [7. Reusable Global Blocks & HTTP Cache Invalidation](#7-reusable-global-blocks--http-cache-invalidation)
- [Internationalization (i18n)](#internationalization-i18n)
- [Verification & Quality Assurance](#verification--quality-assurance)
- [License](#license)

---

## Key Highlights

- **Native Sulu Admin Integration**: Adds a dedicated **Block Suite** navigation section (`su-brush`) with submenus:
  - **Reusable Blocks** (`sulu_block_suite.reusable_blocks.list`)
  - **Presets** (`sulu_block_suite.presets.list`)
  - **Layouts** (`sulu_block_suite.layouts.list`)
- **Inline WAI-ARIA `<TabSection>`**:
  - Replaces `Form.Section` for `<section name="__tabs">` to render an accessible horizontal tab bar (`role="tablist"`, `ArrowLeft`/`ArrowRight`/`Home`/`End` keyboard navigation) directly inside Sulu's `FieldBlocks` card.
  - Preserves Sulu's native `Renderer` and `SchemaForm` lifecycle (`visible_condition`, `disabled_condition`, validation error badges per tab, and field types).
- **Hierarchical `blockConditionDataProvider`**:
  - Exposes the active block's properties at the evaluation root, `__parent`, and `__block` (including `__block.parent` and `__block.root`) so `visible_condition` expressions work seamlessly across tabs and nested block items.

---

## Requirements

- **PHP**: `^8.2` or `^8.5` (strictly typed PHP 8.5 supported)
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
       "sulu-block-suite-bundle": "file:../../packages/sulu-block-suite-bundle/assets/admin"
     }
   }
   ```

2. Import the bundle in `assets/admin/index.js`:
   ```javascript
   import 'sulu-block-suite-bundle';
   ```

3. Build the admin assets:
   ```bash
   npm --prefix assets/admin install
   npm --prefix assets/admin run build
   ```

---

## Configuration Reference

```yaml
# config/packages/sulu_block_suite.yaml
sulu_block_suite:
    presets:
        enabled: true
        yaml_dir: '%kernel.project_dir%/config/blocks/presets'
        enable_snippet_presets: true
    reusable_blocks:
        enabled: true
    layout:
        enabled: true
        yaml_dir: '%kernel.project_dir%/config/blocks/layouts'
        mappings: {}
    rendering:
        template_namespaces:
            - 'blocks'
            - '@SuluBlockSuite/blocks'
```

---

## Feature Guides

### 1. Native Sulu XML Block Tabs & Flexible Custom Sections

Define blocks using standard Sulu XML `<section>` elements in `config/templates/blocks/{block}.xml`.
- Properties in `<section name="content">` (or top-level properties outside a section) remain at the root of the block payload.
- Any other `<section>` you define (`settings`, `style`, `layout`, `animation`, etc.) becomes its own tab in the exact order defined, and its fields are automatically namespaced under `props/*`.
- Shared tabs (`general` from `block_suite_general.xml`, `dynamic_content` from `block_suite_dynamic.xml`, and `advanced` from `block_suite_advanced.xml`) are automatically appended at compile time by `BlockSuiteFormMetadataVisitor`.

```xml
<?xml version="1.0" ?>
<template xmlns="http://schemas.sulu.io/template/template"
          xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
          xsi:schemaLocation="http://schemas.sulu.io/template/template http://schemas.sulu.io/template/template-1.0.xsd">
    <key>headline</key>
    <meta>
        <title lang="en">Headline</title>
    </meta>
    <properties>
        <section name="content">
            <meta>
                <title lang="en">Content</title>
            </meta>
            <properties>
                <property name="title" type="text_line" mandatory="true">
                    <tag name="sulu.block_preview" priority="100"/>
                </property>
            </properties>
        </section>

        <section name="settings">
            <meta>
                <title lang="en">Settings</title>
            </meta>
            <properties>
                <property name="tag" type="single_select" colspan="6">
                    <params>
                        <param name="default_value" value="h2"/>
                        <param name="values" type="collection">
                            <param name="h1"><meta><title lang="en">H1</title></meta></param>
                            <param name="h2"><meta><title lang="en">H2</title></meta></param>
                        </param>
                    </params>
                </property>
            </properties>
        </section>
    </properties>
</template>
```

---

### 2. Selective Tab Exclusion (`sulu_block_suite.tabs`)

You can exclude one or more shared tabs (`general`, `dynamic_content`, `advanced`) directly in a block XML template using `<tag name="sulu_block_suite.tabs" exclude="..."/>`:

```xml
<template xmlns="http://schemas.sulu.io/template/template"
          xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
          xsi:schemaLocation="http://schemas.sulu.io/template/template http://schemas.sulu.io/template/template-1.0.xsd">
    <key>divider</key>
    <tag name="sulu_block_suite.tabs" exclude="dynamic_content"/>
    <!-- ... -->
</template>
```

---

### 3. Preset & Reusable Block Actions in the `Advanced` Tab

The `block_suite_advanced.xml` form includes `<property name="preset" type="preset_selection">`, which renders inside the **Advanced** tab and provides:
- **Active Preset Status Badge & Clear Action**: Displays the currently active preset name and source badge (`[CMS]` or `[YAML]`) with a one-click clear button.
- **Visual Preset Gallery (`Browse Presets`)**: Opens a searchable modal gallery of all YAML and CMS presets for the block type; selecting a preset immediately applies all `props/*` defaults across tabs via atomic `formStore.changeMultiple()`.
- **Apply Content & Styles**: Populates both content fields (`title`, `content`, nested items) and `props/*` from the active preset.
- **Save as Preset**: Captures the current block's live form state and saves/publishes a `block_preset` snippet in Sulu CMS.
- **Save as Reusable Block**: Saves and publishes the current block as a shared `reusable_block` snippet and optionally replaces the current block in-place with a `{ type: 'reusable', snippet: '<uuid>' }` reference.

---

### 4. Scoped `.el-element` CSS & Custom Attributes

In the **Advanced** tab:
- **Custom Attributes (`props.attributes`)**: Enter one `key="value"` attribute per line (`data-hs-overlay="#modal"`, `aria-label="Hero Banner"`). Inline event handlers (`onclick`, `onload`, etc.) are automatically stripped for XSS safety.
- **Scoped CSS (`props.css`)**: Write rules using `.el-element`. `DefaultAttributesTransformer` assigns or reuses the block's `#id`, rewrites `.el-element` to `#id`, strips any `<script>`/`<style>` tags, and `BlockRenderer` prepends `<style>...</style>` to the rendered block output.

---

### 5. Dynamic Content / Smart Content Engine

Blocks can dynamically pull single items or collections from Sulu CMS sources without custom controllers (including live resolution during Sulu Admin Live Preview):

| Source Key | Mode | Provider Class | Description |
| --- | --- | --- | --- |
| `current_page` / `parent_page` / `specific_page` | Single | `SuluPageSourceProvider` | Current page, parent page, or a selected Sulu 3 page with content, SEO, and excerpt fields. |
| `page_selection` / `page_children` / `sibling_pages` / `custom_query` | Multiple | `SuluPageSourceProvider` | Published Sulu 3 page collections with parent filtering, sorting, limit, offset, and tag/category filters. |
| `single_article` / `sulu_articles` | Single / Multiple | `SuluArticleSourceProvider` | Published Sulu 3 articles (`SuluArticleBundle`) with template data, route URL, excerpt, and media fields. |
| `single_category` / `sulu_categories` | Single / Multiple | `SuluCategorySourceProvider` | Sulu categories (`CategoryManagerInterface`) with optional parent category filtering and localized translations. |
| `single_media` / `sulu_media_collection` | Single / Multiple | `SuluMediaCollectionSourceProvider` | Media assets from Sulu collections (`MediaManagerInterface`) with resolved image/thumbnail structures. |
| `sulu_snippets` / `sulu_contacts` / `sulu_accounts` / `sulu_*` | Single / Multiple | `SmartContentSourceProviderAdapter` | Automatic bridge for any Sulu 3 `SmartContentProviderInterface` (`sulu_content.smart_content_provider` — snippets, contacts, accounts, or custom bundle SmartContent providers) with cached `ResourceLoaderProvider` loading and `ReferenceStoreInterface` tracking. |

#### Empty Values: Smart Fallback vs. "Hide When Empty"

- By default, a mapped field only overrides the block when the resolved dynamic value is non-empty; otherwise the static block content stays as fallback.
- Enabling **Hide When Empty** (`props.dynamic_hide_if_empty`) removes the block when the source cannot be resolved or none of its mapped fields has a value. For collections, empty items are dropped and the block disappears when no item remains.
- Container templates can hydrate their nested leaf blocks once with `hydrate_block_tree` and skip rendering when every leaf block was hidden:

```twig
{% set block = block|block_transform(_context)|hydrate_block_tree(_context, ['rows', 'columns', 'blocks']) %}
{% if block %}
    {# render the container #}
{% endif %}
```

This makes page layout snippets data-driven: bind headline, summary, image, and body blocks to the `current_page` source with **Hide When Empty**, and pages only show the parts they actually fill in.

---

### 6. Automatic Block Type Injection (`sulu_block_suite.auto_blocks`)

Instead of maintaining hardcoded `<type ref="..."/>` lists across page templates and column containers, add `<tag name="sulu_block_suite.auto_blocks"/>`:

```xml
<property name="blocks" type="block" default-type="headline">
    <tag name="sulu_block_suite.auto_blocks" default="headline" exclude="row,column"/>
    <types>
        <type name="default">
            <properties/>
        </type>
    </types>
</property>
```

---

### 7. Reusable Global Blocks, Page Layouts & Fallback Chains (`|`)

- **`ReusableBlockTransformer`**: Expands `reusable` blocks at the start of the block collection pipeline (priority `110`, before `DynamicContentTransformer` at `100`) so expanded blocks undergo dynamic content hydration, attribute normalization, and auto-section grouping uniformly. When a `reusable` block is placed inside a column, any inner `section` or nested `reusable` blocks are recursively unwrapped into leaf blocks to prevent nested `<section>` tags.
- **HTTP Cache Reference Tracking & Invalidation**: When a `reusable_block` or `page_layout` snippet is resolved or expanded, `SingleReusableBlockSelectionPropertyResolver`, `ReusableBlockTransformer`, and `PageLayoutProvider` automatically register the snippet UUID in Sulu's `ReferenceStoreInterface` (`snippets`). `ReusableBlockCacheInvalidationSubscriber` listens to `SnippetModifiedEvent`, `SnippetWorkflowTransitionAppliedEvent`, and `SnippetRemovedEvent`, automatically invalidating Sulu HTTP cache tags and references.
- **Pipe-Separated Layout Fallback Chains**: `layout_render()` and `layout_load()` support `|`-delimited fallback chains (e.g. `homepage|default`, `article|default`, `search|default`, `error|default`). The first matching published `page_layout` snippet (or YAML layout) in the chain is rendered; if none of the candidates exist, `layout_render()` returns an empty string `''`:
  ```twig
  {# Page / Search / Error template layout with fallback to 'default' #}
  {{ layout_render(content.layout|default('search|default'), _context) }}
  ```

---

### 8. Universal General Controls & `AbstractBlockComponent` Base Class

All blocks automatically inherit universal controls in the `General` tab (`padding`, `margin`, `max_width`, `block_align`, `text_align`, `position`, `z_index`, `animation`, `blend`, `parallax`, `visibility`) and can extend `AbstractBlockComponent`, which provides:
- Universal class/attribute resolvers: `resolveWrapperClasses()`, `resolvePaddingClasses()`, `resolveBlendModeClasses()`, `resolveParallaxAttributes()`, `getRootAttributes()`.
- Safe string/array/class helpers: `$this->fn($value, 'trim', 'strtolower')`, `$this->classes(...)`, `$this->itemString()`, `$this->itemInt()`, `$this->itemBool()`, `$this->itemArray()`, `$this->itemProp()`.

---

## Internationalization (i18n)

Complete translations are bundled for **6 languages**: `en`, `tr`, `de`, `ru`, `es`, and `fr`.

---

## Verification & Quality Assurance

```bash
# Run PHPUnit 12 test suite
../../vendor/bin/phpunit

# Run PHPStan static analysis at level max (from project root)
../../vendor/bin/phpstan analyse packages/sulu-block-suite-bundle --debug --memory-limit=1G
```

---

## License

This bundle is open-source software licensed under the [MIT License](LICENSE).
