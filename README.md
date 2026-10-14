# Sulu Block Suite Bundle

[![Latest Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://github.com/trk/SuluBlockSuiteBundle)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.2%20%7C%208.5-8892BF.svg)](https://php.net)
[![Sulu CMS](https://img.shields.io/badge/Sulu%20CMS-%5E2.5%20%7C%20%5E3.0-orange.svg)](https://sulu.io)
[![Symfony](https://img.shields.io/badge/Symfony-%5E6.4%20%7C%20%5E7.0-black.svg)](https://symfony.com)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](https://phpstan.org)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![i18n](https://img.shields.io/badge/i18n-en%20%7C%20tr%20%7C%20de%20%7C%20ru%20%7C%20es%20%7C%20fr-lightgrey.svg)](#internationalization-i18n)

An enterprise-grade, modular, and developer-friendly **Sulu CMS 3.x / 2.5+** block engine bundle that delivers:
1. **Standardized 4-Tab Block Props Engine**: Visual React modal (`Settings`, `General`, `Advanced`, `Dynamic Content`) with automatic `common.yaml` merging, `.el-element` scoped CSS, and safe custom HTML attribute injection.
2. **Dynamic Content / Smart Content Engine**: Bind any block or repeater block (`grid`, `accordion`, `gallery`, `slideshow`, `table`, etc.) to live CMS data sources (`sulu_pages`, `sulu_articles`, `sulu_categories`, `sulu_media_collection`, or custom providers) with visual field mapping, filtering, sorting, and pagination/offset.
3. **Zero-Config Automatic Block Type Discovery**: Eliminate repetitive `<type ref="..."/>` lists in page and container XMLs via `<param name="auto_inject" value="true"/>`.
4. **Reusable Global Blocks & HTTP Cache Invalidation**: Centrally managed shared sections embedded anywhere with automatic Sulu HTTP cache tag/reference invalidation on snippet update, publish, or removal.
5. **Dual-Source Style & Prop Presets**: Project-level YAML presets combined seamlessly with editor-created CMS `block_preset` snippets.
6. **Central Page Layout Engine**: Render reusable page/article layouts (`layout_render()`, `sulu_layout_render()`) with automatic HTTP cache reference tracking.
7. **Admin Block Card Header Badges**: Real-time DOM observer (`BlockNameSummaryObserver`) enriches collapsed and expanded Sulu block headers with custom block names (`props.name`), child item counts, and a `⚡ Dynamic` indicator badge.
8. **Full Internationalization (i18n)**: 6 languages supported out of the box (`en`, `tr`, `de`, `ru`, `es`, `fr`).

---

## Table of Contents

- [Key Highlights](#key-highlights)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration Reference](#configuration-reference)
- [Feature Guides](#feature-guides)
  - [1. Standardized 4-Tab Block Props Engine](#1-standardized-4-tab-block-props-engine)
  - [2. Scoped `.el-element` CSS & Custom Attributes](#2-scoped-el-element-css--custom-attributes)
  - [3. Dynamic Content / Smart Content Engine](#3-dynamic-content--smart-content-engine)
  - [4. Automatic Block Type Injection (`auto_inject`)](#4-automatic-block-type-injection-auto_inject)
  - [5. Reusable Global Blocks & HTTP Cache Invalidation](#5-reusable-global-blocks--http-cache-invalidation)
  - [6. Project-Level Style & Prop Presets](#6-project-level-style--prop-presets)
  - [7. Central Page Layout Engine](#7-central-page-layout-engine)
- [Internationalization (i18n)](#internationalization-i18n)
- [Verification & Quality Assurance](#verification--quality-assurance)
- [License](#license)

---

## Key Highlights

- **Native Sulu Admin Integration**: Adds a dedicated **Block Suite** navigation section (`su-brush`) with submenus:
  - **Reusable Blocks** (`sulu_block_suite.reusable_blocks.list`)
  - **Presets** (`sulu_block_suite.presets.list`)
  - **Layouts** (`sulu_block_suite.layouts.list`)
- **4-Tab Modal Architecture**:
  - **Settings**: Block-specific visual options (`card_style`, `html_tag`, `columns`, `overlay_style`, etc.).
  - **General**: Shared layout utilities (`margin`, `margin_remove_top`, `margin_remove_bottom`, `maxwidth`, `maxwidth_breakpoint`, `block_align`, `text_align`, `visibility`, `position`, `z_index`, `animation`).
  - **Advanced**: Full-width `name` (displayed directly on the Sulu block header), 50%/50% `id` and `class`, custom HTML `attributes`, and `.el-element` scoped `css`.
  - **Dynamic Content**: Live data source selection, parent/category filtering, sorting, limit/offset, and field mapping.
- **Automatic Root Attribute & Scoped CSS Injection**: `BlockRenderer` and `DefaultAttributesTransformer` automatically parse `props.attributes` (rejecting `on*` inline JS event handlers), generate a deterministic `el-*` ID when needed, compile `.el-element` CSS rules into a scoped `<style>` block, and inject attributes onto the rendered root element.
- **Built-in Sulu 3 Dynamic Content Providers**:
  - `SuluPageSourceProvider` (`sulu_pages`)
  - `SuluArticleSourceProvider` (`sulu_articles`)
  - `SuluCategorySourceProvider` (`sulu_categories`)
  - `SuluMediaCollectionSourceProvider` (`sulu_media_collection`)

---

## Requirements

- **PHP**: `^8.2` or `^8.5` (strictly typed PHP 8.5 supported)
- **Sulu CMS**: `^2.5` or `^3.0`
- **Symfony**: `^6.4` or `^7.0`
- **Doctrine ORM**: `^2.14` or `^3.0`

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

2. Import the bundle in `assets/admin/app.js`:
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
    props:
        schema_dir: '%kernel.project_dir%/config/blocks/props'
    presets:
        enabled: true
        yaml_dir: '%kernel.project_dir%/config/blocks/presets'
        enable_snippet_presets: true
    reusable_blocks:
        enabled: true
    layout:
        enabled: true
        yaml_dir: '%kernel.project_dir%/config/blocks/layouts'
```

---

## Feature Guides

### 1. Standardized 4-Tab Block Props Engine

Every block with `<property name="props" type="block_props"/>` automatically receives the 4-tab configuration modal:
1. **`settings`**: Defined per block in `config/blocks/props/{blockType}.yaml` (or provided by UI bundles such as `trk/sulu-preline-blocks-bundle`).
2. **`general`**: Automatically merged from `common.yaml` (`margin`, `maxwidth`, `block_align`, `text_align`, `visibility`, `position`, `animation`).
3. **`advanced`**: Automatically merged from `common.yaml` (`name`, `id`, `class`, `attributes`, `css`).
4. **`dynamic_content`**: Automatically available for blocks with dynamic field targets.

Developers can also add custom tabs in their project's `config/blocks/props/{blockType}.yaml`; custom tabs are preserved alongside the 4 core tabs.

---

### 2. Scoped `.el-element` CSS & Custom Attributes

In the **Advanced** tab:
- **Custom Attributes (`props.attributes`)**: Enter one attribute per line (`data-hs-overlay="#modal"`, `aria-label="Hero Banner"`, `data-aos="fade-up"`). Inline event handlers (`onclick`, `onload`, etc.) are automatically stripped for XSS safety.
- **Scoped CSS (`props.css`)**: Write rules using `.el-element` (or bare CSS declarations like `border: 2px solid #3b82f6;`). `DefaultAttributesTransformer` automatically assigns or reuses the block's `#id`, rewrites `.el-element` to `#id`, strips any `<script>`/`<style>` tags, and `BlockRenderer` prepends `<style>...</style>` to the rendered block output.

```css
.el-element {
    border: 1px solid rgba(59, 130, 246, 0.3);
}
.el-element:hover {
    transform: translateY(-2px);
}
```

---

### 3. Dynamic Content / Smart Content Engine

Blocks can dynamically pull content from CMS sources without custom controllers:

| Source Key | Provider Class | Description |
| --- | --- | --- |
| `sulu_pages` | `SuluPageSourceProvider` | Published Sulu 3 pages with parent page filtering, sorting, limit, offset, and excerpt/SEO fields. |
| `sulu_articles` | `SuluArticleSourceProvider` | Published Sulu 3 articles (`SuluArticleBundle`) with template data, route URL, and media fields. |
| `sulu_categories` | `SuluCategorySourceProvider` | Sulu categories (`CategoryManagerInterface`) with optional parent category ID filtering and localized translations. |
| `sulu_media_collection` | `SuluMediaCollectionSourceProvider` | Media assets from Sulu collections (`MediaManagerInterface`) with resolved image/thumbnail structures. |

To create a custom source provider, implement `Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentSourceProviderInterface` and tag it with `sulu_block_suite.dynamic_source_provider`.

---

### 4. Automatic Block Type Injection (`auto_inject`)

Instead of maintaining hardcoded `<type ref="..."/>` lists across page templates and column containers, enable `auto_inject`:

```xml
<property name="blocks" type="block" default-type="section">
    <params>
        <param name="auto_inject" value="true"/>
        <param name="exclude_blocks" value="row_item,column_item"/>
    </params>
    <types>
        <type ref="section"/>
    </types>
</property>
```

`TargetBlockOptionsFormMetadataVisitor` automatically injects all registered block types at form metadata build time while respecting `exclude_blocks` and `include_only_blocks`.

---

### 5. Reusable Global Blocks & HTTP Cache Invalidation

- When a `reusable_block` or `page_layout` snippet is rendered on the website, `SingleReusableBlockSelectionPropertyResolver` and `PageLayoutProvider` automatically register the snippet UUID in Sulu's `ReferenceStoreInterface` (`snippet` reference pool).
- `ReusableBlockCacheInvalidationSubscriber` listens to `SnippetModifiedEvent`, `SnippetWorkflowTransitionAppliedEvent`, and `SnippetRemovedEvent`, automatically invalidating Sulu HTTP cache tags and references so pages embedding a shared block refresh immediately upon snippet changes.

---

### 6. Project-Level Style & Prop Presets

Presets are stored in the host project (`config/blocks/presets/{blockType}.yaml`) and/or created by editors as `block_preset` snippets in the Sulu Admin. Both associative maps (`preset_key: { title: ... }`) and sequence lists (`- id: ...`) are supported, and any test-prefixed preset (`test_*` / `Test ...`) is automatically excluded from production preset lists.

---

### 7. Central Page Layout Engine

Render blocks centrally using `sulu_block_render()` and `sulu_blocks_render()`, or render full layout snippets via `layout_render('default')`.

---

## Internationalization (i18n)

Complete translations are bundled for **6 languages**: `en`, `tr`, `de`, `ru`, `es`, and `fr`.

---

## Verification & Quality Assurance

```bash
# Run PHPUnit 12 test suite
php ../../bin/phpunit -c phpunit.xml.dist

# Run PHPStan static analysis at level max
../../vendor/bin/phpstan analyse --debug -c phpstan.neon
```

---

## License

This bundle is open-source software licensed under the [MIT License](LICENSE).
