# Sulu Block Suite Bundle

[![Latest Version](https://img.shields.io/badge/version-0.2.0-blue.svg)](https://github.com/trk/SuluBlockSuiteBundle)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.3%20%7C%208.5-8892BF.svg)](https://php.net)
[![Sulu CMS](https://img.shields.io/badge/Sulu%20CMS-%5E3.0-orange.svg)](https://sulu.io)
[![Symfony](https://img.shields.io/badge/Symfony-%5E7.4-black.svg)](https://symfony.com)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](https://phpstan.org)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![i18n](https://img.shields.io/badge/i18n-en%20%7C%20tr%20%7C%20de%20%7C%20ru%20%7C%20es%20%7C%20fr-lightgrey.svg)](#internationalization-i18n)

A **100% UI-framework-agnostic block infrastructure engine** for **Sulu CMS 3.x** that separates block infrastructure (Core), UI component frameworks (such as Preline UI, Bootstrap, daisyUI, UIkit, or shadcn), and project-level block composition:

1. **Compile-Time Native XML Block Tabs (`BlockSuiteFormMetadataVisitor` + `<TabSection>`)**: Define block fields using standard Sulu XML `<section>` elements (`content`, `settings`, `general`, or any custom tab) and automatically inherit shared `Dynamic Content` and `Advanced` tabs at form metadata compilation time—with zero runtime XHR schema requests.
2. **Automatic `props/*` Namespacing**: Fields inside `content` stay at the block root (`block.title`, `block.content`), while fields in all other tabs (`settings`, `general`, `dynamic_content`, `advanced`, or custom tabs) are automatically namespaced under `props/*` (`block.props.tag`, `block.props.preset`).
3. **Metadata-Driven Block Transformation & Data Contract**: `DefaultAttributesTransformer`, `SuluDataNormalizer`, `DynamicContentHydrator`, and `BlockSuitePageReindexEnhancer` inspect **Sulu field metadata types** (`single_media_selection`, `media_selection`, `link`, `text_line`, `text_area`, `text_editor`, `block`) rather than hardcoded block or field name lists.
4. **Preset & Reusable Block Workflow in the `Advanced` Tab (`preset_selection`)**: Select project YAML or CMS-managed presets, preview in a visual gallery, apply content & styles atomically across all tabs, or save any configured block as a **Preset** (`block_preset`) or **Reusable Block** (`reusable_block`) directly from the `Advanced` tab.
5. **Dynamic Content / Smart Content Engine**: Bind any block or repeater block to live CMS data sources (`pages`, `articles`, `categories`, `media_collection`, SmartContent providers, or custom `DynamicContentSourceProviderInterface` services) with type-aware field mapping, filtering, sorting, and pagination/offset.
6. **Reusable Global Blocks & Central Page Layouts**: Render shared sections (`reusable`) and full page layouts (`layout_render()`) with pluggable `NestedBlockFlattenerInterface` services and automatic Sulu HTTP cache tag and reference invalidation.
7. **Full Internationalization (i18n)**: 6 languages supported out of the box (`en`, `tr`, `de`, `ru`, `es`, `fr`).

---

## Table of Contents

- [Architecture & Responsibility Boundaries](#architecture--responsibility-boundaries)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration Reference](#configuration-reference)
- [Core Data Contract](#core-data-contract)
- [Extension Points](#extension-points)
- [Writing a Framework Package](#writing-a-framework-package)
- [Composing Blocks in a Project](#composing-blocks-in-a-project)
- [Feature Guides](#feature-guides)
  - [1. Native Sulu XML Block Tabs & `sulu_block_suite.tabs` Tag](#1-native-sulu-xml-block-tabs--sulu_block_suitetabs-tag)
  - [2. Preset & Reusable Block Actions in the `Advanced` Tab](#2-preset--reusable-block-actions-in-the-advanced-tab)
  - [3. Scoped `.el-element` CSS & Custom Attributes](#3-scoped-el-element-css--custom-attributes)
  - [4. Dynamic Content / Smart Content Engine](#4-dynamic-content--smart-content-engine)
  - [5. Reusable Global Blocks, Page Layouts & Fallback Chains (`|`)](#5-reusable-global-blocks-page-layouts--fallback-chains-)
- [Internationalization (i18n)](#internationalization-i18n)
- [Verification & Quality Assurance](#verification--quality-assurance)
- [License](#license)

---

## Architecture & Responsibility Boundaries

| Layer | Responsibility | Does NOT Contain |
| --- | --- | --- |
| **`sulu-block-suite-bundle` (Core)** | Transform pipeline, metadata-driven media/link/search/dynamic resolution, preset/reusable/layout engines, admin field types (`preset_selection`, `single_reusable_block_selection`, `hotspot_picker`), security utilities (`AttributeSanitizer`, `CssScoper`), tab/props `FormMetadataVisitor` | UI block definitions (except `reusable`), styling fields (`margin_*`, `padding_*`), CSS class maps, framework translations, or snippet template auto-registration |
| **Framework Package** (e.g. `sulu-preline-blocks-bundle`, Bootstrap, daisyUI, UIkit) | Block XML definitions, styling tabs (`general` via native `xi:include`), Twig components, CSS class maps, `NestedBlockFlattenerInterface` implementations, framework translations | Workflow or persistence logic already provided by Core |
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

2. Import the bundle in `assets/admin/index.js`:
   ```javascript
   import 'sulu-block-suite-bundle';
   ```

3. Build the admin assets:
   ```bash
   npm --prefix assets/admin install
   npm --prefix assets/admin run build
   ```

### 4. Initialize Project Snippet Templates (`sulu:block-suite:init`)

Core does not auto-register snippet templates so that each project retains full Sulu-native control over which blocks are allowed in `page_layout`, `reusable_block`, and `block_preset`. Run the initialization command to scaffold the starter templates (and any registered framework bundle's block type includes and presets) into your project without overwriting existing files:

```bash
php bin/console sulu:block-suite:init
```

Options:
- `--dry-run`: Preview files to be created or skipped without writing to disk.
- `--no-presets`: Skip copying example `config/blocks/presets/*.yaml` files.
- `--force` (`-f`): Overwrite existing files in the project.
- `--source=<path>` (`-s`): Initialize from a custom `docs/examples` directory.

Or copy the starter snippet templates manually:

```bash
cp vendor/trk/sulu-block-suite-bundle/docs/examples/snippets/*.xml config/templates/snippets/
```

---

## Configuration Reference

```yaml
# config/packages/sulu_block_suite.yaml
sulu_block_suite:
    presets:
        enabled: true
        enable_snippet_presets: true
        yaml_dir:
            - '%kernel.project_dir%/config/blocks/presets'
    reusable_blocks:
        enabled: true
    layout:
        enabled: true
        yaml_dir: '%kernel.project_dir%/config/blocks/layouts'
        defaults:
            sidebar: 'none'
            header_variant: 'solid'
            container_width: 'boxed'
            content_padding: 'normal'
        mappings: {}
    rendering:
        # Priority-keyed map of Twig template namespaces (higher number = higher priority).
        # Multiple framework bundles can prepend their own namespace without overwriting each other.
        template_namespaces:
            blocks: 1000
            '@SuluPrelineBlocks/blocks': 200
            '@SuluBlockSuite/blocks': 0
    media:
        # Map of Sulu image format keys to pixel widths for responsive <field>_srcset generation.
        srcset_formats:
            col-sm: 400
            col-md: 640
            col-lg: 960
            col-xl: 1280
            col-2xl: 1600
```

---

## Core Data Contract

When a block passes through `BlockTransformPipeline` (specifically `DefaultAttributesTransformer` at priority `90`, after `ReusableBlockTransformer` at `110` and `DynamicContentTransformer` at `100`), Core enriches the normalized block array with a deterministic, framework-agnostic contract:

| Key | Type | Source & Behavior |
| --- | --- | --- |
| `_id` | `string` | Explicit `props.id` sanitized via `AttributeSanitizer::sanitizeId()`, or an auto-generated deterministic `el-<8-hex>` ID when `props.css` contains `.el-element` rules (or bare CSS declarations). |
| `_class` | `string` | Trimmed custom CSS class string from `props.class` / `props.custom_class`. |
| `_attributes` | `array<string, string>` | Parsed `key="value"` pairs from `props.attributes`, sanitized by `AttributeSanitizer::parse()` / `AttributeSanitizer::sanitize()` (blocks `on*` inline event handlers, `style`, `id`, `class`, and `javascript:`/`vbscript:`/`data:` URIs). |
| `_scoped_css` | `string` | Sanitized CSS from `props.css` processed by `CssScoper::scope()`, rewriting `.el-element` (or wrapping bare declarations) to `#<_id>` and stripping `<script>`/`<style>` tags, `expression()`, `@import`, and `javascript:` URLs. `BlockRenderer` automatically prepends `<style>...</style>` when non-empty. |
| `<field>_url` | `string` | Automatically derived for every **media-type** (`single_media_selection`, `media_selection`) and **link-type** (`link`) field in the block's Sulu form metadata (e.g. `image` → `image_url`, `hover_image` → `hover_image_url`, `link` → `link_url`, `card_link` → `card_link_url`). |
| `<field>_srcset` | `string` | Automatically derived responsive `srcset` string for every **media-type** field using the configured `sulu_block_suite.media.srcset_formats` map (e.g. `image_srcset`, `hover_image_srcset`). |
| `<field>_focus_style` | `string` | Automatically derived CSS `object-position: X% Y%;` focal point string from Sulu media focus point coordinates (`focusPoint: {x, y}` / `focalPoint: {x, y}` or `getFocusPoint()->getX()` / `getY()`) for every **media-type** field (e.g. `image_focus_style`). |

---

## Extension Points

1. **`BlockTransformerInterface` (`Totoglu\SuluBlockSuiteBundle\Transformer\BlockTransformerInterface`)**:
   - Autoconfigured via DI tag `sulu_block_suite.block_transformer`.
   - Implement `transform(array $block, array $context = []): ?array` and `getPriority(): int` to inspect, enrich, or suppress (`return null`) blocks during rendering.
2. **`NestedBlockFlattenerInterface` (`Totoglu\SuluBlockSuiteBundle\Transformer\NestedBlockFlattenerInterface`)**:
   - Autoconfigured via DI tag `sulu_block_suite.nested_block_flattener`.
   - Implement `supports(array $block): bool` and `flatten(array $block, callable $expandChild): array` so framework-specific container blocks (such as Preline's `section > rows > columns > blocks`) are cleanly unwrapped into leaf blocks when a `reusable` block is rendered inside an already nested column/slot.
3. **`DynamicContentSourceProviderInterface` (`Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentSourceProviderInterface`)**:
   - Autoconfigured via DI tag `sulu_block_suite.dynamic_source_provider`.
   - Expose custom single or collection data sources and field descriptors to the Dynamic Content tab.
4. **`AbstractBlockComponent` (`Totoglu\SuluBlockSuiteBundle\Twig\Component\AbstractBlockComponent`)**:
   - Base class for Symfony UX Twig Components consuming block arrays via `BlockComponentPreRenderSubscriber`.
   - Override `protected function dataKeys(): array` to register framework-specific content keys alongside Core's universal keys (`title`, `subtitle`, `summary`, `description`, `text`, `content`, `url`, `link`, `image`, `video`, `icon`, `items`, `blocks`).
   - Override `protected function capabilityMap(): array` to register named boolean capability checks for `this.is('...')` in Twig templates.
   - Use Core utilities `ClassList`, `PropReader`, `AttributeSanitizer`, and `CssScoper` for type-safe prop reading and HTML attribute/class assembly.
5. **`presetWireframeRegistry` (Admin JS)**:
   - Exported from `sulu-block-suite-bundle` (`assets/admin/registries/presetWireframeRegistry`).
   - Register custom React wireframe preview components per block type (`presetWireframeRegistry.add('my_block', MyWireframeComponent)`); blocks without a custom wireframe automatically fall back to Core's generic wireframe preview.
6. **`sulu_block_suite.tabs` XML Tag**:
   - Place `<tag name="sulu_block_suite.tabs" .../>` on a block `<template>` or `<type>` in XML:
     - `exclude="dynamic_content,advanced"`: Opt out of specific Core-injected tabs (`dynamic_content` and/or `advanced`).
     - `enabled="false"`: Disable tab wrapping entirely for a block type (e.g. `reusable.xml`).
     - `shared="false"`: Wrap the block's own `<section>` elements into tabs without injecting Core's shared `dynamic_content` and `advanced` tabs.
     - `namespace_props="false"`: Keep fields in non-`content` sections at the root level instead of prefixing them with `props/` (used for structural sub-items like `row_item` or `column_item`).

---

## Writing a Framework Package

You can build a standalone UI framework bundle (e.g., **Bootstrap**, **daisyUI**, **UIkit**) or a **base + theme inheritance hierarchy** (`tailwind-base-blocks` → `preline-blocks` / `daisyui-blocks` / `shadcn-blocks`) on top of `sulu-block-suite-bundle` **without modifying Core**.

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

### 2. Base + Theme Package Hierarchy (`tailwind-base-blocks` → Child Theme Bundles)

Because `rendering.template_namespaces` is a priority-sorted map (`namespace => int`), multiple bundles can register fallback and override layers cleanly:
- **Project override (`blocks`: `1000`)** — highest priority (`templates/blocks/{type}.html.twig`).
- **Child theme bundle (`@DaisyUiBlocks/blocks`: `200`)** — overrides specific block/component templates and provides theme class maps.
- **Base schema bundle (`@TailwindBaseBlocks/blocks`: `100`)** — defines the shared block XML schemas (`config/templates/blocks/*.xml`), base `AbstractBlockComponent` subclasses, and default adapter templates.
- **Core bundle (`@SuluBlockSuite/blocks`: `0`)** — provides `reusable.html.twig`.

Because all child themes share the same XML field and `props/*` schema from the base package, **stored CMS content is 100% portable across themes**.

> [!IMPORTANT]
> **Sulu Global Block Key Constraint:** Sulu CMS registers block `<key>` definitions in a single global form pool (`block`). If two active bundles register XML files with the same `<key>` (e.g. `headline`), Sulu merges their form metadata. Either activate only one framework bundle per project, or have child theme bundles override Twig templates/components at a higher `template_namespaces` priority while inheriting the XML block definitions from a single base bundle (or use prefixed keys such as `uikit_headline`).

### 3. Include Framework Styling Tabs via Native `xi:include`

Core does not inject opinionated styling fields. Instead, your framework bundle owns its styling tab (`<section name="general">`) and includes it in each block XML via standard `xi:include`:

```xml
<?xml version="1.0"?>
<template xmlns="http://schemas.sulu.io/template/template"
          xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
          xmlns:xi="http://www.w3.org/2001/XInclude"
          xsi:schemaLocation="http://schemas.sulu.io/template/template http://schemas.sulu.io/template/template-1.0.xsd">
    <key>headline</key>
    <meta>
        <title lang="en">Headline</title>
    </meta>
    <properties>
        <section name="content">
            <!-- Root-level content fields (e.g. title) -->
        </section>
        <section name="settings">
            <!-- Block-specific settings (automatically namespaced under props/*) -->
        </section>
        <xi:include href="../../forms/general/general_justify.xml"
                    xpointer="xmlns(sulu=http://schemas.sulu.io/template/template)xpointer(/sulu:form/sulu:properties/sulu:*)"/>
    </properties>
</template>
```

Core's `BlockSuiteFormMetadataVisitor` automatically wraps `content`, `settings`, `general`, `dynamic_content`, and `advanced` into horizontal tabs and namespaces every non-`content` field under `props/*`.

### 4. Extend `AbstractBlockComponent` & Register a `NestedBlockFlattenerInterface`

- Create a framework base component extending `AbstractBlockComponent` that reads Core's `_id`, `_class`, `_attributes`, `_scoped_css`, `<field>_url`, `<field>_srcset`, and `<field>_focus_style` and maps your framework's `props/*` values to CSS classes using `ClassList` and `PropReader`.
- If your framework provides a multi-level layout container (such as `section > rows > columns > blocks`), implement `NestedBlockFlattenerInterface` so `ReusableBlockTransformer` can unwrap nested containers when a reusable block is placed inside a column.

---

## Composing Blocks in a Project

Projects control which blocks are available in each template using **Sulu-native `<types><type ref="..."/></types>`** lists and standard `xi:include` fragments:

### 1. Shared Content Block List (`config/templates/includes/content_block_types.xml`)

Define the common set of content blocks once in your project:

```xml
<?xml version="1.0"?>
<types xmlns="http://schemas.sulu.io/template/template">
    <type ref="headline"/>
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
<property name="blocks" type="block" default-type="headline">
    <types>
        <type ref="section"/>
        <xi:include href="../includes/content_block_types.xml"
                    xpointer="xmlns(sulu=http://schemas.sulu.io/template/template)xpointer(/sulu:types/sulu:*)"/>
    </types>
</property>
```

> [!TIP]
> In `block_preset.xml`, `BlockSuiteFormMetadataVisitor` automatically derives the `target_block` dropdown options from the `<types>` list declared on the `block` property in `block_preset.xml`.

### 2. Restricting Layout-Only Blocks to `page_layout.xml`

Blocks that only belong in global page layouts (such as `breadcrumbs`, `header`, or `footer`) are simply listed in `config/templates/snippets/page_layout.xml` and omitted from `content_block_types.xml`:

```xml
<!-- config/templates/snippets/page_layout.xml -->
<property name="blocks" type="block" default-type="section">
    <types>
        <type ref="breadcrumbs"/>
        <type ref="section"/>
        <xi:include href="../includes/content_block_types.xml"
                    xpointer="xmlns(sulu=http://schemas.sulu.io/template/template)xpointer(/sulu:types/sulu:*)"/>
    </types>
</property>
```

---

## Feature Guides

### 1. Native Sulu XML Block Tabs & `sulu_block_suite.tabs` Tag

- Properties in `<section name="content">` (or top-level properties outside a section) remain at the root of the block payload.
- Any other `<section>` (`settings`, `general`, `style`, `layout`, etc.) becomes its own tab in the exact order defined, and its fields are automatically namespaced under `props/*`.
- Core appends `dynamic_content` (`config/forms/block_suite_dynamic.xml`) and `advanced` (`config/forms/block_suite_advanced.xml`) at compile time unless excluded via `<tag name="sulu_block_suite.tabs" exclude="dynamic_content"/>`.

---

### 2. Preset & Reusable Block Actions in the `Advanced` Tab

The `block_suite_advanced.xml` form includes `<property name="preset" type="preset_selection">`, which renders inside the **Advanced** tab and provides:
- **Active Preset Status Badge & Clear Action**: Displays the currently active preset name and source badge (`[CMS]` or `[YAML]`) with a one-click clear button.
- **Visual Preset Gallery (`Browse Presets`)**: Opens a searchable modal gallery of all YAML and CMS presets for the block type; selecting a preset immediately applies all `props/*` defaults across tabs via atomic `formStore.changeMultiple()`.
- **Apply Content & Styles**: Populates both content fields (`title`, `content`, nested items) and `props/*` from the active preset.
- **Save as Preset**: Captures the current block's live form state and saves/publishes a `block_preset` snippet in Sulu CMS.
- **Save as Reusable Block**: Saves and publishes the current block as a shared `reusable_block` snippet and optionally replaces the current block in-place with a `{ type: 'reusable', snippet: '<uuid>' }` reference.

---

### 3. Scoped `.el-element` CSS & Custom Attributes

In the **Advanced** tab:
- **Custom Attributes (`props.attributes`)**: Enter one `key="value"` attribute per line (`data-hs-overlay="#modal"`, `aria-label="Hero Banner"`). Inline event handlers (`onclick`, `onload`, etc.) and dangerous URI schemes are stripped by `AttributeSanitizer`.
- **Scoped CSS (`props.css`)**: Write rules using `.el-element` (or bare declarations). `DefaultAttributesTransformer` assigns or reuses `_id`, rewrites `.el-element` to `#<_id>` via `CssScoper`, strips any `<script>`/`<style>` tags or dangerous CSS expressions, and `BlockRenderer` prepends `<style>...</style>` to the rendered block output.

---

### 4. Dynamic Content / Smart Content Engine

Blocks can dynamically pull single items or collections from Sulu CMS sources without custom controllers (including live resolution during Sulu Admin Live Preview):

| Source Key | Mode | Provider Class | Description |
| --- | --- | --- | --- |
| `current_page` / `parent_page` / `specific_page` | Single | `SuluPageSourceProvider` | Current page, parent page, or a selected Sulu 3 page with content, SEO, and excerpt fields. |
| `page_selection` / `page_children` / `sibling_pages` / `custom_query` | Multiple | `SuluPageSourceProvider` | Published Sulu 3 page collections with parent filtering, sorting, limit, offset, and tag/category filters. |
| `single_article` / `sulu_articles` | Single / Multiple | `SuluArticleSourceProvider` | Published Sulu 3 articles (`SuluArticleBundle`) with template data, route URL, excerpt, and media fields. |
| `single_category` / `sulu_categories` | Single / Multiple | `SuluCategorySourceProvider` | Sulu categories (`CategoryManagerInterface`) with optional parent category filtering and localized translations. |
| `single_media` / `sulu_media_collection` | Single / Multiple | `SuluMediaCollectionSourceProvider` | Media assets from Sulu collections (`MediaManagerInterface`) with resolved image/thumbnail structures. |
| `sulu_snippets` / `sulu_contacts` / `sulu_accounts` / `sulu_*` | Single / Multiple | `SmartContentSourceProviderAdapter` | Automatic bridge for any Sulu 3 `SmartContentProviderInterface` (`sulu_content.smart_content_provider`) with cached `ResourceLoaderProvider` loading and `ReferenceStoreInterface` tracking. |

#### Metadata-Driven Target Field Discovery & Auto-Mapping

- `BlockTargetFieldMetadataResolver` inspects the block's Sulu form metadata to discover mappable target fields by their Sulu field types (`text_line`, `text_area`, `text_editor`, `single_media_selection`, `media_selection`, `link`, `date`, `datetime`, `number`, etc.) and recursively discovers child item fields on `block`-type repeater properties.
- When `DynamicContentHydrator` hydrates a block without explicit field mappings, it matches target fields to source fields by exact field name first, and then by compatible Sulu field type (`text_editor` ← rich content, media fields ← image sources).

---

### 5. Reusable Global Blocks, Page Layouts & Fallback Chains (`|`)

- **`ReusableBlockTransformer`**: Expands `reusable` blocks at priority `110` (before `DynamicContentTransformer` at `100` and `DefaultAttributesTransformer` at `90`) so expanded blocks undergo dynamic content hydration and attribute normalization uniformly. When a `reusable` block is expanded inside a nested slot/column, registered `NestedBlockFlattenerInterface` services unwrap container blocks into leaf blocks.
- **HTTP Cache Reference Tracking & Invalidation**: When a `reusable_block` or `page_layout` snippet is resolved or expanded, `SingleReusableBlockSelectionPropertyResolver`, `ReusableBlockTransformer`, and `PageLayoutProvider` register the snippet UUID in Sulu's `ReferenceStoreInterface` (`snippets`). `ReusableBlockCacheInvalidationSubscriber` listens to snippet modification, workflow transition, and removal events to invalidate Sulu HTTP cache tags and references.
- **Pipe-Separated Layout Fallback Chains**: `layout_render()` and `layout_load()` support `|`-delimited fallback chains (e.g. `homepage|default`, `article|default`, `search|default`, `error|default`):
  ```twig
  {{ layout_render(content.layout|default('search|default'), _context) }}
  ```

---

## Internationalization (i18n)

Complete admin translations (`sulu_block_suite.*`) are bundled for **6 languages**: `en`, `tr`, `de`, `ru`, `es`, and `fr`.

---

## Verification & Quality Assurance

```bash
# Run PHPUnit 12 test suite from project root (including Architecture/FrameworkAgnosticTest)
vendor/bin/phpunit packages/sulu-block-suite-bundle/tests

# Run PHPStan static analysis at level max from project root
vendor/bin/phpstan analyse --debug --memory-limit=1G packages/sulu-block-suite-bundle/src packages/sulu-block-suite-bundle/tests
```

---

## License

This bundle is open-source software licensed under the [MIT License](LICENSE).
