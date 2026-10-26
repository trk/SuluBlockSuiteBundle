# Sulu Block Suite (`trk/sulu-block-suite-bundle`) Agent Instructions

## Compile-Time & Runtime Conventions

1. **Compile-Time Block Schema Resolution (`BlockSuiteFormMetadataVisitor`)**
   - Resolve block schemas via Sulu's `FormMetadataProvider` or the `sulu_block_suite_blocks` MCP tool rather than reading raw XML files on disk.
   - `BlockSuiteFormMetadataVisitor` enhances every global block (`<tag name="sulu.global_block"/>`) and inline sub-block (`<tag name="sulu_block_suite.tabs"/>`) at compile time by wrapping sections into `<section name="__tabs">`, injecting shared `advanced` and `dynamic_content` tabs, and auto-populating `target_block`, `navigation_context`, `single_template_selection`, and `template_selection` options.
   - `single_template_selection` and `template_selection` render via Sulu's native `SingleSelect` and `Select` (`MultiSelect`) components and accept optional `<param name="template_type" value="page|article|snippet|block"/>` (or `types`, `resource_key`), `<param name="groups" value="..."/>`, and `<param name="excluded_templates" value="..."/>`.
   - `sulu_block_suite.multi_webspace.enabled` auto-detects `Totoglu\SuluMultiWebspaceBundle\SuluMultiWebspaceBundle` (`null` by default). When `false`, `FormSelectFieldPopulator` hides `single_webspace_select`/`webspace_select` fields (`visibleCondition: 'false'`, `default_value: '_global'`), expands adjacent `colSpan=6` fields to `12`, and `SnippetScopeResolver` + `PageLayoutUniquenessDataMapper` treat all Page Layouts, Reusables, and Block Presets as globally scoped.

2. **`props/*` Namespacing Across Block Tabs**
   - Block XML templates organize fields into `content` (root content properties) and non-content tabs (`settings`, `general`, `advanced`, `dynamic_content`).
   - `BlockSuiteFormMetadataVisitor` prefixes every field outside `content`/`media` with `props/` (e.g. `<property name="background">` in `settings` becomes `props/background` in `FormMetadata`).
   - At runtime, `BlockSuiteMetadataResolver` resolves both flat `props/*` keys and nested `props: {...}` maps into `$block['props']` for Twig components (`AbstractBlockComponent`).
   - Compose block payloads via `sulu_block_suite_compose_block` (or `BlockSuitePayloadComposer::compose()`) before calling `sulu_block_add`, `sulu_block_update`, or `sulu_page_create` so both `props` and `props/*` keys remain synchronized.

3. **Inline Field Option Groups (Native Addon Popovers)**
   - Content fields declare inline option groups as `<param name="{group_key}" type="collection">` inside `<params>` (with optional `_icon`, `_default`, `_target`).
   - Write selected option values to `props/{group_key}` (or `props/{_target}`).

4. **Visual Presets (`config/blocks/presets/<block_type>.yaml`)**
   - Presets define `title`, `description`, `props` (merged as default props when `props/preset` is selected), and `values` (default content values applied in Sulu Admin).
   - Because `props/preset` uses field type `preset_selection`, query `sulu_block_suite_presets` (or `BlockSuiteSchemaInspector::listPresets()`) to discover available preset IDs and their default `props`/`values`.

5. **AI Full-Content Translation Safety (`SuluAiPlatformBundle`)**
   - Translate only human-readable content fields and `props/dynamic_empty_message`, `props/dynamic_summary_text`, `props/dynamic_filtered_summary_text`, `props/aria_label`, `props/alt` (`BlockSuiteSchemaInspector::isTranslatableField()`).
   - Preserve technical props (`props/id`, `props/class`, `props/css`, `props/attributes`, `props/status`, `props/preset`, inline option enum values, and `props/dynamic_*` query parameters) verbatim via `BlockSuitePayloadComposer::preserveTechnicalProps()`.

## MCP Tools (Progressive Disclosure)

- `sulu_block_suite_blocks`: Call with `blockType: null` to list the compact block catalog; call with `blockType: "<type>"` to inspect `content_fields`, inline `option_groups`, `settings_props`, and `presets` (pass `includeAdvanced: true` or `includeDynamic: true` only when configuring HTML attributes/status or dynamic content sources).
- `sulu_block_suite_presets`: List or search visual presets (`id`, `title`, `description`, `props`, `values`) for a block type.
- `sulu_block_suite_compose_block`: Merge a `blockType`, optional `preset`, `content`, and `props` into a validated Sulu block payload ready for `sulu_block_add`, `sulu_block_update`, or `sulu_page_create`.
