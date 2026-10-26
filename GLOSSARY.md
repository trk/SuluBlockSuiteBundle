# Sulu Block Suite

A framework-agnostic Sulu CMS 3 block authoring, layout composition, preset management, and dynamic content hydration context.

## Language

### Block Composition & Reuse

**Page Layout**:
A named block composition stored as a snippet (`page_layout`) that wraps or replaces a page's layout regions (`header`, `before_content`, `after_content`, `footer`)—either bound as the default layout for a page template (`target_template`) or assigned per page (`layout`).
_Avoid_: Layout template, master page, global layout

**Reusable**:
A shared snippet (`reusable_block`) containing one or more blocks that can be referenced across multiple pages and updated in one place.
_Avoid_: Reusable blocks, global block, shared widget, synced block

**Block Preset**:
A named, pre-configured set of block properties and default content values (loaded from YAML files or `block_preset` snippets) that editors can apply when inserting or styling a block.
_Avoid_: Block template, style preset, starter block

**Container Block**:
A structural block (`section` or `row`) that organizes child blocks into rows and columns and can automatically supply derived child presets.
_Avoid_: Wrapper block, grid container, layout block

### Properties & Admin Metadata

**Block Props**:
The non-content settings, styling, layout, dynamic content, and advanced configuration of a block, stored under the `props` key rather than at the block's root content level.
_Avoid_: Block options, block settings, block attributes

**Popover Option Group**:
A compact dropdown menu (`ArrowMenu`) defined via `<param type="collection">` on a content field in Sulu XML that writes its selected option into a target property under Block Props.
_Avoid_: Inline select, field toolbar

**Snippet Scope**:
The visibility boundary of a Page Layout, Reusable, or Block Preset snippet across webspaces—either restricted to a single webspace, shared across all webspaces when left empty, or globally unscoped when multi-webspace support is inactive.
_Avoid_: Webspace filter, tenant filter, snippet visibility

### Dynamic Content

**Dynamic Source**:
A registered data provider (such as current page, child pages, sibling pages, articles, categories, media collections, or custom queries) that feeds live CMS items into a block at render time.
_Avoid_: Data provider, content feed, smart content adapter

**Field Mapping**:
A declarative rule pairing a target field on a block with a source field from a Dynamic Source so the block's content is hydrated automatically at render time.
_Avoid_: Property binding, data binding, field link

**Dynamic Template Item**:
A sub-item inside a multi-item block marked with `is_dynamic_template` that serves as the repeatable visual blueprint for each record returned by a multi-item Dynamic Source, while unmarked sibling sub-items remain static.
_Avoid_: Repeater item, loop template, prototype item
