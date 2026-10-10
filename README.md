# Sulu Block Suite Bundle

[![Latest Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://github.com/trk/SuluBlockSuiteBundle)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.2%20%7C%208.5-8892BF.svg)](https://php.net)
[![Sulu CMS](https://img.shields.io/badge/Sulu%20CMS-%5E2.5%20%7C%20%5E3.0-orange.svg)](https://sulu.io)
[![Symfony](https://img.shields.io/badge/Symfony-%5E6.4%20%7C%20%5E7.0-black.svg)](https://symfony.com)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](https://phpstan.org)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![i18n](https://img.shields.io/badge/i18n-en%20%7C%20tr%20%7C%20de%20%7C%20ru%20%7C%20es%20%7C%20fr-lightgrey.svg)](#internationalization-i18n)

An enterprise-grade, modular, and developer-friendly **Sulu CMS 3.x / 2.5+** bundle that delivers:
1. **Reusable Global Blocks**: Centrally managed shared sections (headers, call-to-actions, testimonials, contact strips) embedded anywhere with single-source updates.
2. **Dual-Source Style & Prop Presets**: Developer-defined YAML presets combined seamlessly with editor-created CMS snippets, applied instantly in the block editor via a visual popup dialog.
3. **Template & Page Layout Engine**: Central layout block rendering and page container decoupling (`layout_render()`, `sulu_layout_render()`) to update thousands of articles or pages from a single layout snippet.
4. **100% Decoupled & Zero Bundled Block Coupling**: Zero hardcoded block definitions, presets, or props inside the package; all configurations reside strictly within the host project.
5. **Zero 3rd-Party Dependencies**: Pure native Sulu 3 / Symfony 7 architecture without external snippet manager wrappers or heavy libraries.
6. **Full Internationalization (i18n)**: 6 languages supported out-of-the-box (`en`, `tr`, `de`, `ru`, `es`, `fr`).

---

## Table of Contents

- [Key Highlights](#key-highlights)
- [Architecture Overview](#architecture-overview)
- [Requirements](#requirements)
- [Installation](#installation)
  - [1. Composer Package](#1-composer-package)
  - [2. Enable the Bundle](#2-enable-the-bundle)
  - [3. Register Admin Frontend Assets](#3-register-admin-frontend-assets)
- [Configuration Reference](#configuration-reference)
- [Architecture Principles (Zero Hardcoding)](#architecture-principles-zero-hardcoding)
- [Feature Guides](#feature-guides)
  - [1. Reusable Global Blocks](#1-reusable-global-blocks)
  - [2. Style & Prop Presets](#2-style--prop-presets)
  - [3. Page Layout Engine & Central Rendering](#3-page-layout-engine--central-rendering)
  - [4. Schema-Driven Block Props Engine](#4-schema-driven-block-props-engine)
- [Internationalization (i18n)](#internationalization-i18n)
- [Extending & Custom Providers](#extending--custom-providers)
- [Verification & Quality Assurance](#verification--quality-assurance)
- [Contributing & License](#contributing--license)

---

## Key Highlights

- **Native Sulu Admin Integration**: Automatically adds a dedicated **Block Suite** navigation section (`su-brush`) with submenus:
  - 🧩 **Reusable Blocks** (`sulu_block_suite.reusable_blocks.list`)
  - 🎨 **Presets** (`sulu_block_suite.presets.list`)
  - 📐 **Layouts** (`sulu_block_suite.layouts.list`)
- **Single-Click Snippet Filtering**: Creating a new item from any submenu automatically preselects and locks the corresponding snippet template (`reusable_block`, `block_preset`, or `page_layout`).
- **SingleReusableBlockSelection Field Type**: A modern, visual React component (`single_reusable_block_selection`) for picking shared blocks, complete with modal search, thumbnail preview card, inner block counter, real-time usage statistics ("Used in X resources"), and a direct link to edit the master block in a new tab.
- **Unified Block Preset Pipeline**: Merges file-based YAML presets (`config/blocks/presets/*.yaml`) and live database presets entered by CMS editors, with fallback resolution and thumbnail preview images.
- **Visual BlockProps React Component**: In the Sulu Admin page editor, each block component gets an inline preset selector and "Configure Props" trigger. Editors can pick a preset with thumbnail preview, customize typography, colors, borders, spacing, alignment, and custom CSS classes in an accessible modal dialog.
- **Dynamic Component Type Resolution**: When defining presets in the CMS panel, `BlockProps` dynamically adapts to the selected target block type without requiring duplicate blocks or hardcoded schemas.
- **Conditional UI Rendering**: If a block has no props defined in the host project, the **Configure Props** button is hidden. If a block has no presets, the **Preset Selector** is hidden.
- **Central Layout Engine**: `layout_render('key')` and `sulu_layout_render('key')` render layout blocks centrally across page templates.

---

## Architecture Overview

```
                      +----------------------------------+
                      |       Sulu Admin Panel           |
                      |  (React 17 + MobX + BlockProps)  |
                      +-----------------+----------------+
                                        |
                 +----------------------+----------------------+
                 |                                             |
                 v                                             v
     +-----------------------+                     +-----------------------+
     |   Reusable Blocks     |                     |    Style Presets      |
     |  (reusable_block.xml) |                     |  (block_preset.xml)   |
     +-----------+-----------+                     +-----------+-----------+
                 |                                             |
                 +----------------------+----------------------+
                                        |
                                        v
                      +----------------------------------+
                      |   BlockSuiteAdmin & Controllers  |
                      |  - /admin/api/block-presets      |
                      |  - /admin/api/block-props-schema |
                      |  - /admin/api/block-types        |
                      +-----------------+----------------+
                                        |
                                        v
                      +----------------------------------+
                      |    YamlBlockPresetProvider       |
                      |  YamlBlockPropSchemaProvider     |
                      |  (Host YAML + CMS Snippets)      |
                      +-----------------+----------------+
                                        |
                                        v
                      +----------------------------------+
                      |   PageLayoutProvider & Twig      |
                      |  layout_render('key')            |
                      |  sulu_layout_classes()           |
                      +-----------------+----------------+
                                        |
                                        v
                      +----------------------------------+
                      |    Website Frontend (Twig/HTML)  |
                      +----------------------------------+
```

---

## Requirements

- **PHP**: `^8.2` or `^8.5` (PHP 8.5 strictly typed supported)
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

Register the bundle in `config/bundles.php`:

```php
// config/bundles.php

return [
    // ...
    Totoglu\SuluBlockSuiteBundle\SuluBlockSuiteBundle::class => ['all' => true],
];
```

### 3. Register Admin Frontend Assets

The bundle provides a compiled-ready React 17 + MobX frontend component for the Sulu Admin.

1. Add the package dependency in `assets/admin/package.json`:
   ```json
   {
     "dependencies": {
       "sulu-block-suite-bundle": "file:../../vendor/trk/sulu-block-suite-bundle/assets/admin"
     }
   }
   ```

2. Import the component in `assets/admin/app.js`:
   ```javascript
   // assets/admin/app.js
   import 'sulu-block-suite-bundle';
   ```

3. Compile the admin assets:
   ```bash
   npm --prefix assets/admin install
   npm --prefix assets/admin run build
   ```

4. Validate the admin build:
   ```bash
   php bin/adminconsole sulu:admin:validate-build
   ```

---

## Configuration Reference

Create `config/packages/sulu_block_suite.yaml` to customize paths and defaults:

```yaml
# config/packages/sulu_block_suite.yaml
sulu_block_suite:
    props:
        # Project-specific directory for block prop schemas
        schema_dir: '%kernel.project_dir%/config/blocks/props'
    presets:
        enabled: true
        # Project-specific directory for file-based block presets
        yaml_dir: '%kernel.project_dir%/config/blocks/presets'
        enable_snippet_presets: true
    reusable_blocks:
        enabled: true
    layout:
        enabled: true
        # Project-specific directory for file-based page layouts
        yaml_dir: '%kernel.project_dir%/config/blocks/layouts'
        # Extensible mappings: define arbitrary layout groups (container_width, content_padding, sidebar, background, etc.)
        mappings:
            container_width:
                full: 'w-full'
                boxed: 'max-w-7xl mx-auto px-4 sm:px-6 lg:px-8'
                narrow: 'max-w-4xl mx-auto px-4 sm:px-6'
            content_padding:
                none: 'py-0'
                compact: 'py-6 md:py-8'
                normal: 'py-12 md:py-16'
                spacious: 'py-16 md:py-24'
```

---

## Architecture Principles (Zero Hardcoding)

- **Zero Bundled Block Coupling**: The bundle is 100% agnostic of specific blocks (`headline`, `button`, `text`, `image`, `card`, `video`, etc.). No block schemas, presets, or block types are hardcoded inside the bundle.
- **Host Project Ownership**:
  - Block Props schemas reside in `config/blocks/props/*.yaml`.
  - Block Presets reside in `config/blocks/presets/*.yaml`.
  - Snippet templates (`reusable_block.xml`, `page_layout.xml`) are decoupled in the bundle and overridden in the host project (`config/templates/snippets/`) to specify project-allowed block types.
- **Conditional UI Rendering**:
  - If a block has no props defined in `./config/blocks/props/{blockType}.yaml`, the **Configure Props** button is automatically hidden.
  - If a block has no presets defined in `./config/blocks/presets/{blockType}.yaml`, the **Preset Selector** is automatically hidden.
  - If a block defines neither, the `BlockProps` field renders nothing (`null`), leaving the form uncluttered.
- **Full i18n Translation Support in YAML**:
  - Schema tabs, field labels, placeholders, option labels, preset titles, and descriptions support both Symfony translation keys (`sulu_block_suite.*`, `app.*`) and multilingual translation maps (`{ en: '...', tr: '...', de: '...' }`).
- **Responsive Admin Styling**:
  - The preset selector and Re-apply button automatically adapt to narrow/mobile viewports (`@media (max-width: 640px)`) by stacking cleanly without overflow.

---

## Feature Guides

### 1. Reusable Global Blocks

Reusable blocks allow content managers to create a section once (e.g. Call to Action, Partner Logos, Hero Section) and reference it across hundreds of pages.

#### Step 1: Automatic Dynamic Block Discovery
The bundle automatically discovers all registered block types across your application at runtime using Sulu's `MetadataProviderRegistry` and enhances `reusable_block` and `block_preset` forms automatically. No manual copying or overriding of XML snippet templates is required.

If you wish to provide custom metadata, fields, or restrict allowed block types, you can optionally override `config/templates/snippets/reusable_block.xml` in your host project:

```xml
<?xml version="1.0" ?>
<!-- config/templates/snippets/reusable_block.xml -->
<template xmlns="http://schemas.sulu.io/template/template"
          xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
          xsi:schemaLocation="http://schemas.sulu.io/template/template http://schemas.sulu.io/template/template-1.0.xsd">
    <key>reusable_block</key>
    <meta>
        <title lang="en">Reusable Block Section</title>
        <title lang="tr">Ortak Paylaşılan Blok</title>
    </meta>
    <properties>
        <property name="title" type="text_line" mandatory="true">
            <meta><title lang="en">Internal Title</title></meta>
            <params><param name="headline" value="true"/></params>
        </property>
        <property name="description" type="text_area">
            <meta><title lang="en">Description</title></meta>
        </property>
        <property name="preview_image" type="single_media_selection">
            <meta><title lang="en">Preview Image</title></meta>
            <params><param name="types" value="image"/></params>
        </property>
        <property name="blocks" type="block" default-type="headline">
            <meta><title lang="en">Section Content Blocks</title></meta>
            <types>
                <!-- Project-specific allowed block types -->
                <type ref="headline"/>
                <type ref="button"/>
                <type ref="text"/>
                <type ref="image"/>
                <type ref="card"/>
                <type ref="video"/>
            </types>
        </property>
    </properties>
</template>
```

#### Step 2: Add Reusable Block Type to Your Page XML
In your page XML or block include file (e.g. `config/templates/includes/blocks.xml`):

```xml
<types>
    <type ref="headline"/>
    <type ref="text"/>
    <type ref="card"/>
    <!-- Add Reusable Block reference -->
    <type ref="reusable"/>
</types>
```

#### Step 3: Manage in Sulu Admin
1. Navigate to **Block Suite > Reusable Blocks** in the admin sidebar.
2. Click **Add**. The `reusable_block` template is automatically selected.
3. Provide an **Internal Title**, optional **Preview Image**, and add content blocks.
4. Click **Save & Publish**.
5. In any page, add a **Reusable Block**, pick the section, and optionally click **Configure Props** to customize spacing or alignment.

---

### 2. Style & Prop Presets

Presets allow designers to establish design tokens and consistent styles while giving editors flexibility without breaking the design.

#### Source A: Developer YAML Presets (`config/blocks/presets/`)
Place preset YAML files directly in your project's `config/blocks/presets/{blockType}.yaml`.

Example `config/blocks/presets/card.yaml`:
```yaml
card:
    presets:
        featured_dark:
            title:
                en: "Featured Dark Card"
                tr: "Öne Çıkan Koyu Kart"
            description:
                en: "Dark background with elevated shadow"
                tr: "Koyu arka plan ve yüksek gölge"
            preview_image: "/images/presets/card-dark.png"
            props:
                variant: "solid"
                color: "dark"
                rounded: "2xl"
                hover_effect: "shadow"
                margin_bottom: "lg"
                custom_class: "border border-neutral-700 shadow-xl"
```

#### Source B: CMS Snippet-based Presets
Content editors can create presets directly in the Sulu Admin without writing code:
1. Navigate to **Block Suite > Presets**.
2. Click **Add**.
3. Choose the **Target Block Component**: The dropdown is populated dynamically at runtime by `TargetBlockOptionsFormMetadataVisitor` from your project's `./config/blocks/props/`, `./config/blocks/presets/`, and `config/templates/blocks/`.
4. Enter a Title, optional Preview Image, and configure styling.
5. Save & Publish. The preset is immediately available in the block editor!

---

### 3. Page Layout Engine & Central Rendering

The Page Layout Engine enables rendering layouts centrally across all page templates. If you have 1,000 articles or pages, updating a single layout snippet instantly updates all 1,000 outputs.

#### Step 1: Override `page_layout.xml` in Host Project
Define project-specific layout fields and allowed block types in `config/templates/snippets/page_layout.xml`:

```xml
<?xml version="1.0" ?>
<!-- config/templates/snippets/page_layout.xml -->
<template xmlns="http://schemas.sulu.io/template/template"
          xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
          xsi:schemaLocation="http://schemas.sulu.io/template/template http://schemas.sulu.io/template/template-1.0.xsd">
    <key>page_layout</key>
    <meta><title lang="en">Page Layout</title></meta>
    <properties>
        <property name="title" type="text_line" mandatory="true">
            <params><param name="headline" value="true"/></params>
        </property>
        <property name="key" type="text_line">
            <meta>
                <title lang="en">Layout Key</title>
                <info_text lang="en">Unique identifier used in Twig (e.g. 'default', 'article_footer').</info_text>
            </meta>
        </property>
        <property name="container_width" type="single_select" colspan="6">
            <params>
                <param name="values" type="collection">
                    <param name="full"><meta><title lang="en">Full Width</title></meta></param>
                    <param name="boxed"><meta><title lang="en">Boxed (Max 7XL)</title></meta></param>
                    <param name="narrow"><meta><title lang="en">Narrow (Max 4XL)</title></meta></param>
                </param>
            </params>
        </property>
        <property name="blocks" type="block" default-type="headline">
            <types>
                <type ref="headline"/>
                <type ref="button"/>
                <type ref="text"/>
                <type ref="image"/>
                <type ref="card"/>
                <type ref="video"/>
                <type ref="reusable"/>
            </types>
        </property>
    </properties>
</template>
```

#### Step 2: Render in Twig Page Templates
In `templates/pages/default.html.twig`:

```twig
{% extends 'base.html.twig' %}

{% block content %}
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 space-y-12">
        {% if content.title|default(false) %}
            <h1 class="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl dark:text-white">{{ content.title }}</h1>
        {% endif %}

        {% for block in content.blocks|default([]) %}
            {{ include('blocks/' ~ block.type ~ '.html.twig', {block: block}, with_context: false) }}
        {% endfor %}

        {# Centrally render layout blocks defined in Block Suite > Layouts #}
        {{ layout_render(content.layout|default('default')) }}
    </div>
{% endblock %}
```

#### Available Twig Functions

| Function | Signature | Description |
| --- | --- | --- |
| `layout_render()` / `sulu_layout_render()` | `(string\|array\|null $layout, array $context = [], ?string $locale = null): string` | Resolves the layout snippet by key, title, slug, or uuid and renders its template and blocks. Returns `''` safely if not found. |
| `layout_load()` / `sulu_layout_load()` | `(string $identifier, ?string $locale = null): ?array` | Loads raw layout snippet data for custom Twig handling. |
| `layout_classes()` / `sulu_layout_classes()` | `(?array $layoutData): array<string, string>` | Resolves and returns all configured layout CSS classes as an associative map (e.g. `container`, `padding`, `sidebar`, `header_variant`, etc.). |
| `layout_class()` / `sulu_layout_class()` | `(string $group, ?string $value, string $default = ''): string` | Resolves a single class string for any configured mapping group with automatic fallback. |
| `layout_attributes()` / `sulu_layout_attributes()` | `(?array $layoutData): string` | Dynamically converts scalar layout properties into safe HTML data attributes (`data-layout-*`). |

---

### 4. Schema-Driven Block Props Engine

The React modal (`BlockProps.js`) contains **zero hardcoded block names or property definitions**.

#### How It Works
1. When opening the props dialog for a block (e.g. `headline`, `card`, `hero`), the modal requests the schema:
   `GET /admin/api/block-props-schema/{blockType}?locale={currentLocale}`
2. The `YamlBlockPropSchemaProvider` loads `%kernel.project_dir%/config/blocks/props/{blockType}.yaml`.
3. If `%kernel.project_dir%/config/blocks/props/common.yaml` exists, its tabs (`spacing`, `advanced`) are merged in automatically.
4. Labels, options, and placeholders are localized on-the-fly via Symfony's translation contracts or inline translation maps.

#### Example `config/blocks/props/button.yaml`:
```yaml
tabs:
    appearance:
        title:
            en: "Appearance"
            tr: "Görünüm"
        fields:
            variant:
                type: select
                label:
                    en: "Button Variant"
                    tr: "Buton Varyantı"
                col_span: 6
                options:
                    - value: 'solid'
                      label: { en: "Solid Filled", tr: "Dolu Dolgulu" }
                    - value: 'outline'
                      label: { en: "Outline Bordered", tr: "Çizgisel Kenarlık" }
```

#### Supported Schema Field Types
- `select` / `single_select`: Single select dropdown with options.
- `text` / `input`: Single-line text input with placeholder support.
- `checkbox` / `bool` / `toggle`: Boolean checkbox toggle for flags.
- `color` / `color_picker`: Accessible native color picker dialog.
- `number`: Numeric input with alignment and step controls.
- `textarea`: Multi-line text area for custom styles or raw CSS.

#### REST API Endpoints
- `GET /admin/api/block-types?locale={locale}`: Returns all available block types in the project with human-readable titles, props availability, and preset counts.
- `GET /admin/api/block-props-schema/{blockType}?locale={locale}`: Returns tabs and field definitions for a specific block type.
- `GET /admin/api/block-presets/{blockType}?locale={locale}`: Returns file-based and snippet-based presets for a block type.
- `POST /admin/api/block-presets`: Creates and publishes a new `block_preset` snippet from current block styling and optional content values directly from the editor.
- `GET /admin/api/reusable-blocks?locale={locale}&page={page}&limit={limit}`: Paginated search and listing of reusable blocks for the modal selector.
- `GET /admin/api/reusable-blocks/{id}?locale={locale}`: Returns reusable block metadata, preview image, and inner block count.
- `GET /admin/api/reusable-blocks/{id}/usage`: Returns reverse-lookup references showing which pages, snippets, or articles embed this reusable block.
- `GET /admin/api/reusable-blocks/stats?ids={id1,id2,...}`: Returns batch usage counts for multiple reusable blocks in a single request.

---

## Internationalization (i18n)

Sulu Block Suite Bundle provides complete multilingual translation support across the backend UI, XML metadata, and React modal components for **6 major languages**:

| Code | Language | Native Name | Status |
| :---: | :--- | :--- | :---: |
| `en` | **English** | English | 100% Complete |
| `tr` | **Turkish** | Türkçe | 100% Complete |
| `de` | **German** | Deutsch | 100% Complete |
| `ru` | **Russian** | Русский | 100% Complete |
| `es` | **Spanish** | Español | 100% Complete |
| `fr` | **French** | Français | 100% Complete |

---

## Extending & Custom Providers

All key components in Sulu Block Suite Bundle are built against interfaces and registered in the Symfony service container with autowiring:

- `BlockPresetProviderInterface`: Fetch presets from external APIs, CMS snippets, or custom databases.
- `BlockPropSchemaProviderInterface`: Supply dynamic schemas from JSON Schemas, PHP attributes, or custom storages.
- `PageLayoutProviderInterface`: Implement custom layout caching or alternative layout resolvers.

---

## Verification & Quality Assurance

The bundle adheres to the highest Symfony and Sulu CMS engineering standards:

- **PHP 8.5 Strict Typing**: Every PHP file enforces `declare(strict_types=1);`.
- **PHPStan Level Max**: 100% clean static analysis with zero errors.
- **PHPUnit 12 Tested**: 75 tests, 275 assertions passing with 0 notices.
- **Strict Typing**: Enforces `declare(strict_types=1);` and PHPStan Level Max with zero errors.

```bash
# Run PHPUnit test suite
composer test

# Run PHPStan static analysis at level max
composer phpstan

# Run coding standards check
composer cs-check
```

---

## Contributing & License

Contributions, issues, and feature requests are welcome!

This bundle is open-source software licensed under the [MIT License](LICENSE).

Copyright (c) 2026 **TOTOGLU**. Developed with modern Symfony & Sulu CMS best practices.
