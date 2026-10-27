<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Ai;

use Totoglu\SuluBlockSuiteBundle\Preset\BlockPresetProviderInterface;
use Totoglu\SuluBlockSuiteBundle\Preset\YamlBlockPresetProvider;

/**
 * Base 3-tier progressive-disclosure catalog, XInclude-aware XML schema inspector,
 * and validated block payload composer for Sulu Block Suite bundles.
 */
abstract class AbstractXmlBlockAiCatalog
{
    protected readonly BlockXmlSchemaExtractor $schemaExtractor;
    protected ?BlockPresetProviderInterface $resolvedPresetProvider;

    public function __construct(
        ?BlockPresetProviderInterface $presetProvider = null,
        ?string $blocksXmlDir = null,
    ) {
        $resolvedBlocksXmlDir = $blocksXmlDir ?? $this->getDefaultBlocksXmlDir();
        $this->schemaExtractor = new BlockXmlSchemaExtractor($resolvedBlocksXmlDir);
        $this->resolvedPresetProvider = $presetProvider ?? $this->createFallbackYamlPresetProvider($resolvedBlocksXmlDir);
    }

    /**
     * Semantic categories and one-line agent-facing summaries keyed by block type.
     *
     * @return array<string, array{category: string, summary: string}>
     */
    abstract protected function getCatalogMeta(): array;

    /**
     * Default filesystem directory containing the bundle's block XML templates.
     */
    abstract protected function getDefaultBlocksXmlDir(): string;

    /**
     * Fallback summary format when a block type is not listed in `getCatalogMeta()`.
     */
    protected function getFallbackBlockSummary(string $blockType): string
    {
        return \sprintf('Block Suite `%s` content block.', $blockType);
    }

    /**
     * Tier 1 (Progressive Disclosure): Returns a lightweight index of all block types.
     *
     * @return list<array{
     *     type: string,
     *     title: string,
     *     category: string,
     *     summary: string,
     *     has_items: bool,
     *     has_elements: bool,
     *     is_layout_container: bool,
     *     preset_count: int
     * }>
     */
    public function listBlockTypes(?string $category = null, string $locale = 'en'): array
    {
        $normalizedCategory = null !== $category && '' !== \trim($category) ? \strtolower(\trim($category)) : null;
        $catalogMeta = $this->getCatalogMeta();
        $result = [];

        foreach ($this->schemaExtractor->listBlockSummaries($locale) as $summary) {
            $type = $summary['type'];
            $meta = $catalogMeta[$type] ?? [
                'category' => 'content',
                'summary' => $this->getFallbackBlockSummary($type),
            ];

            if (null !== $normalizedCategory && $meta['category'] !== $normalizedCategory) {
                continue;
            }

            $result[] = [
                'type' => $type,
                'title' => $summary['title'],
                'category' => $meta['category'],
                'summary' => $meta['summary'],
                'has_items' => $summary['has_items'],
                'has_elements' => $summary['has_elements'],
                'is_layout_container' => $summary['is_layout_container'],
                'preset_count' => \count($this->resolveRawPresetsForType($type, $locale)),
            ];
        }

        return $result;
    }

    /**
     * Tier 2a (Progressive Disclosure): Lists presets for a specific block type, with compact
     * summaries by default and optional full `props` / `values` disclosure on demand.
     *
     * @return array{
     *     block_type: string,
     *     count: int,
     *     presets: list<array<string, mixed>>
     * }
     */
    public function listBlockPresets(
        string $blockType,
        ?string $query = null,
        ?string $category = null,
        string $locale = 'en',
        bool $includeValues = false,
    ): array {
        $normalizedType = \trim($blockType);
        $rawPresets = $this->resolveRawPresetsForType($normalizedType, $locale);
        $needle = null !== $query && '' !== \trim($query) ? \strtolower(\trim($query)) : null;
        $catNeedle = null !== $category && '' !== \trim($category) ? \strtolower(\trim($category)) : null;

        $presets = [];
        foreach ($rawPresets as $key => $presetData) {
            $label = isset($presetData['label']) && \is_string($presetData['label'])
                ? $presetData['label']
                : (isset($presetData['title']) && \is_string($presetData['title'])
                    ? $presetData['title']
                    : \ucwords(\str_replace(['_', '-'], ' ', $key)));
            $presetCategory = isset($presetData['category']) && \is_string($presetData['category'])
                ? $presetData['category']
                : (isset($presetData['description']) && \is_string($presetData['description']) && '' !== $presetData['description']
                    ? $presetData['description']
                    : 'General');

            /** @var list<string> $tags */
            $tags = [];
            if (isset($presetData['tags']) && \is_array($presetData['tags'])) {
                foreach ($presetData['tags'] as $tag) {
                    if (\is_scalar($tag) && '' !== \trim((string) $tag)) {
                        $tags[] = \trim((string) $tag);
                    }
                }
            }

            if (null !== $catNeedle && \strtolower($presetCategory) !== $catNeedle) {
                continue;
            }

            if (null !== $needle) {
                $haystack = \strtolower($key . ' ' . $label . ' ' . $presetCategory . ' ' . \implode(' ', $tags));
                if (!\str_contains($haystack, $needle)) {
                    continue;
                }
            }

            $props = isset($presetData['props']) && \is_array($presetData['props']) ? $presetData['props'] : [];
            $values = isset($presetData['values']) && \is_array($presetData['values']) ? $presetData['values'] : [];

            /** @var list<string> $propKeys */
            $propKeys = [];
            foreach (\array_keys($props) as $propKey) {
                $propKeys[] = (string) $propKey;
            }

            $entry = [
                'key' => $key,
                'label' => $label,
                'category' => $presetCategory,
                'tags' => $tags,
                'prop_keys' => $propKeys,
            ];

            if ($includeValues) {
                $entry['props'] = $props;
                $entry['values'] = $values;
            }

            $presets[] = $entry;
        }

        return [
            'block_type' => $normalizedType,
            'count' => \count($presets),
            'presets' => $presets,
        ];
    }

    /**
     * Tier 2b & Tier 3 (Progressive Disclosure): Inspects the full content, style (`props/*`),
     * inline option group enum, and nested block schema for a block type.
     *
     * @return array{
     *     type: string,
     *     title: string,
     *     category: string,
     *     summary: string,
     *     content_fields: list<array<string, mixed>>,
     *     style_props: list<array<string, mixed>>,
     *     nested_blocks: array<string, array<string, mixed>>,
     *     positive_example: array<string, mixed>,
     *     dynamic_props?: list<array<string, mixed>>,
     *     advanced_props?: list<array<string, mixed>>
     * }
     */
    public function inspectBlockSchema(
        string $blockType,
        string $locale = 'en',
        bool $includeDynamic = false,
        bool $includeAdvanced = false,
    ): array {
        $extracted = $this->schemaExtractor->extractSchema($blockType, $locale);
        if (null === $extracted) {
            throw new \InvalidArgumentException(\sprintf('Unknown Preline block type "%s". Call preline_blocks_list_types first.', $blockType));
        }

        $resolvedType = $extracted['resolved_type'];
        $catalogMeta = $this->getCatalogMeta();
        $meta = $catalogMeta[$resolvedType] ?? [
            'category' => 'content',
            'summary' => $this->getFallbackBlockSummary($resolvedType),
        ];

        $contentFields = $extracted['content_fields'];
        $styleProps = $extracted['style_props'];
        $nestedBlocks = $extracted['nested_blocks'];
        $excludedTabs = $extracted['excluded_tabs'];

        $schema = [
            'type' => $resolvedType,
            'title' => $extracted['title'],
            'category' => $meta['category'],
            'summary' => $meta['summary'],
            'content_fields' => $contentFields,
            'style_props' => $styleProps,
            'nested_blocks' => $nestedBlocks,
            'positive_example' => $this->buildPositiveExample($resolvedType, $contentFields, $styleProps, $nestedBlocks),
        ];

        if ($includeDynamic && !\in_array('dynamic_content', $excludedTabs, true)) {
            $schema['dynamic_props'] = BlockXmlSchemaExtractor::getCoreDynamicPropsSchema();
        }

        if ($includeAdvanced && !\in_array('advanced', $excludedTabs, true)) {
            $schema['advanced_props'] = BlockXmlSchemaExtractor::getCoreAdvancedPropsSchema();
        }

        return $schema;
    }

    /**
     * Composes and validates a Sulu block payload by merging an optional preset,
     * bidirectionally normalizing `props/*` and nested `props`, validating enum values against
     * the XML schema, flattening `row` multi-column shorthands (`columns`), and separating
     * translatable content fields from non-translatable style props.
     *
     * @param array<string, mixed> $input
     *
     * @return array{
     *     valid: bool,
     *     errors: list<string>,
     *     warnings: list<string>,
     *     block: array<string, mixed>,
     *     flat_block: array<string, mixed>,
     *     translatable_fields: list<string>,
     *     non_translatable_props: list<string>
     * }
     */
    public function composeBlockPayload(
        string $blockType,
        array $input = [],
        ?string $preset = null,
        string $locale = 'en',
        int $depth = 0,
    ): array {
        $schema = $this->inspectBlockSchema($blockType, $locale, includeDynamic: true, includeAdvanced: true);
        $resolvedType = $schema['type'];

        /** @var list<string> $errors */
        $errors = [];
        /** @var list<string> $warnings */
        $warnings = [];

        /** @var array<string, array<string, mixed>> $contentFieldMap */
        $contentFieldMap = [];
        foreach ($schema['content_fields'] as $field) {
            $fieldName = isset($field['name']) && \is_string($field['name']) ? $field['name'] : '';
            if ('' !== $fieldName) {
                $contentFieldMap[$fieldName] = $field;
            }
        }

        /** @var array<string, array<string, mixed>> $stylePropMap */
        $stylePropMap = [];
        $stylePropsList = \array_merge(
            $schema['style_props'],
            $schema['dynamic_props'] ?? [],
            $schema['advanced_props'] ?? [],
        );
        foreach ($stylePropsList as $prop) {
            $propName = isset($prop['name']) && \is_string($prop['name']) ? $prop['name'] : '';
            if ('' !== $propName) {
                $stylePropMap[$propName] = $prop;
            }
        }

        // 1. Resolve preset key & hydrate preset defaults
        $inputProps = isset($input['props']) && \is_array($input['props']) ? $input['props'] : [];
        $resolvedPreset = $preset;
        if (null === $resolvedPreset || '' === \trim($resolvedPreset)) {
            if (isset($input['preset']) && \is_string($input['preset']) && '' !== \trim($input['preset'])) {
                $resolvedPreset = \trim($input['preset']);
            } elseif (isset($input['props/preset']) && \is_string($input['props/preset']) && '' !== \trim($input['props/preset'])) {
                $resolvedPreset = \trim($input['props/preset']);
            } elseif (isset($inputProps['preset']) && \is_string($inputProps['preset']) && '' !== \trim($inputProps['preset'])) {
                $resolvedPreset = \trim($inputProps['preset']);
            }
        }

        /** @var array<string, mixed> $mergedContent */
        $mergedContent = [];
        /** @var array<string, mixed> $mergedProps */
        $mergedProps = [];

        if (null !== $resolvedPreset && '' !== $resolvedPreset) {
            $rawPresets = $this->resolveRawPresetsForType($resolvedType, $locale);
            if (isset($rawPresets[$resolvedPreset])) {
                $presetDef = $rawPresets[$resolvedPreset];
                if (isset($presetDef['values']) && \is_array($presetDef['values'])) {
                    foreach ($presetDef['values'] as $k => $v) {
                        $mergedContent[(string) $k] = $v;
                    }
                }
                if (isset($presetDef['props']) && \is_array($presetDef['props'])) {
                    foreach ($presetDef['props'] as $k => $v) {
                        $mergedProps[(string) $k] = $v;
                    }
                }
            } else {
                $warnings[] = \sprintf('Preset "%s" was not found in the registered preset provider for block type "%s"; keeping preset key as requested.', $resolvedPreset, $resolvedType);
            }
            $mergedProps['preset'] = $resolvedPreset;
        }

        // 2. Merge explicit input content & props (supporting both `props: {...}` and `"props/foo"`)
        foreach ($input as $key => $value) {
            $strKey = (string) $key;
            if (\in_array($strKey, ['type', 'preset', 'props', 'columns'], true)) {
                continue;
            }
            if (\str_starts_with($strKey, 'props/')) {
                $shortProp = \substr($strKey, 6);
                if ('' !== $shortProp) {
                    $mergedProps[$shortProp] = $value;
                }
                continue;
            }
            if (!isset($contentFieldMap[$strKey]) && isset($stylePropMap[$strKey])) {
                $mergedProps[$strKey] = $value;
                continue;
            }
            $mergedContent[$strKey] = $value;
        }

        foreach ($inputProps as $propKey => $propVal) {
            $strPropKey = (string) $propKey;
            if (\str_starts_with($strPropKey, 'props/')) {
                $strPropKey = \substr($strPropKey, 6);
            }
            if ('' !== $strPropKey) {
                $mergedProps[$strPropKey] = $propVal;
            }
        }

        // 3. Handle `row` multi-column shorthand (`columns: [[col1Blocks], [col2Blocks]]`)
        if ('row' === $resolvedType && isset($input['columns']) && \is_array($input['columns'])) {
            $flattenedRowBlocks = [];
            $colIndex = 0;
            foreach ($input['columns'] as $columnBlocks) {
                if (!\is_array($columnBlocks)) {
                    continue;
                }
                if ($colIndex > 0) {
                    $flattenedRowBlocks[] = [
                        'type' => 'column_break',
                        '_id' => \sprintf('row_col_brk_%02d', $colIndex),
                    ];
                }
                foreach ($columnBlocks as $bIdx => $childBlock) {
                    if (\is_array($childBlock)) {
                        if (!isset($childBlock['_id']) || '' === $childBlock['_id']) {
                            $childBlock['_id'] = \sprintf('row_c%02d_b%02d', $colIndex + 1, (int) $bIdx + 1);
                        }
                        $flattenedRowBlocks[] = $childBlock;
                    }
                }
                ++$colIndex;
            }
            $mergedContent['blocks'] = $flattenedRowBlocks;
        }

        // 4. Normalize and validate nested block items (`items`, `elements`, `blocks`) recursively
        foreach ($schema['nested_blocks'] as $nestedKey => $nestedDef) {
            if (!isset($mergedContent[$nestedKey]) || !\is_array($mergedContent[$nestedKey])) {
                continue;
            }
            $mergedContent[$nestedKey] = $this->normalizeAndValidateNestedBlockList(
                $mergedContent[$nestedKey],
                $nestedDef,
                $resolvedType . '_' . $nestedKey,
                $nestedKey,
                $locale,
                $errors,
                $warnings,
                $depth,
            );
        }

        // 5. Validate style_props enum values and content_fields enum values
        foreach ($mergedProps as $propKey => $propVal) {
            if ('preset' === $propKey || null === $propVal || '' === $propVal) {
                continue;
            }
            if (isset($stylePropMap[$propKey])) {
                $allowed = $this->extractStringList($stylePropMap[$propKey]['allowed_values'] ?? null);
                $this->validateAllowedValue(
                    $propVal,
                    $allowed,
                    \sprintf('style prop "%s" (`props/%s`)', $propKey, $propKey),
                    $errors,
                );
            }
        }

        foreach ($mergedContent as $fieldKey => $fieldVal) {
            if (null === $fieldVal || '' === $fieldVal) {
                continue;
            }
            if (isset($contentFieldMap[$fieldKey])) {
                $allowed = $this->extractStringList($contentFieldMap[$fieldKey]['allowed_values'] ?? null);
                $this->validateAllowedValue(
                    $fieldVal,
                    $allowed,
                    \sprintf('content field "%s"', $fieldKey),
                    $errors,
                );
            }
        }

        // 6. Build output `block` (nested props) and `flat_block` (Sulu FormMetadata `props/*` + `props`)
        $blockId = isset($input['_id']) && \is_string($input['_id']) && '' !== \trim($input['_id'])
            ? \trim($input['_id'])
            : \sprintf('blk_%s', $resolvedType);

        $block = [
            'type' => $resolvedType,
            '_id' => $blockId,
        ];
        foreach ($mergedContent as $k => $v) {
            $block[$k] = $v;
        }
        if ([] !== $mergedProps) {
            $block['props'] = $mergedProps;
        }

        $flatBlock = $block;
        foreach ($mergedProps as $propKey => $propVal) {
            $flatBlock['props/' . $propKey] = $propVal;
        }

        // 7. Compute translatable vs non-translatable keys present in payload
        $translatableFields = [];
        foreach (\array_keys($mergedContent) as $k) {
            if (!isset($contentFieldMap[$k]) || true === ($contentFieldMap[$k]['translatable'] ?? true)) {
                $translatableFields[] = $k;
            }
        }

        $nonTranslatableProps = [];
        foreach (\array_keys($mergedProps) as $propKey) {
            $nonTranslatableProps[] = $propKey;
        }

        return [
            'valid' => [] === $errors,
            'errors' => $errors,
            'warnings' => $warnings,
            'block' => $block,
            'flat_block' => $flatBlock,
            'translatable_fields' => $translatableFields,
            'non_translatable_props' => $nonTranslatableProps,
        ];
    }

    /**
     * @param array<int|string, mixed> $items
     * @param array<string, mixed> $nestedDef
     * @param list<string> $errors
     * @param list<string> $warnings
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeAndValidateNestedBlockList(
        array $items,
        array $nestedDef,
        string $idPrefix,
        string $pathPrefix,
        string $locale,
        array &$errors,
        array &$warnings,
        int $depth = 0,
    ): array {
        $defaultSubType = isset($nestedDef['default_type']) && \is_string($nestedDef['default_type'])
            ? $nestedDef['default_type']
            : '';

        /** @var array<string, array<string, mixed>> $typesByKey */
        $typesByKey = [];
        if (isset($nestedDef['types']) && \is_array($nestedDef['types'])) {
            foreach ($nestedDef['types'] as $typeDef) {
                if (\is_array($typeDef) && isset($typeDef['type']) && \is_string($typeDef['type'])) {
                    /** @var array<string, mixed> $typedDef */
                    $typedDef = $typeDef;
                    $typesByKey[$typeDef['type']] = $typedDef;
                }
            }
        }

        $normalizedList = [];
        foreach ($items as $idx => $rawItem) {
            if (!\is_array($rawItem)) {
                continue;
            }
            /** @var array<string, mixed> $item */
            $item = [];
            foreach ($rawItem as $k => $v) {
                $item[(string) $k] = $v;
            }

            if ((!isset($item['type']) || !\is_string($item['type']) || '' === $item['type']) && '' !== $defaultSubType) {
                $item['type'] = $defaultSubType;
            }
            $itemType = isset($item['type']) && \is_string($item['type']) ? $item['type'] : $defaultSubType;

            if (!isset($item['_id']) || !\is_string($item['_id']) || '' === $item['_id']) {
                $item['_id'] = \sprintf('%s_%02d', $idPrefix, (int) $idx + 1);
            }

            $itemPath = \sprintf('%s[%d]', $pathPrefix, (int) $idx);
            $subTypeSchema = '' !== $itemType && isset($typesByKey[$itemType]) ? $typesByKey[$itemType] : null;

            if (null === $subTypeSchema && [] !== $typesByKey && '' !== $itemType) {
                $errors[] = \sprintf(
                    'Invalid sub-block type "%s" at %s. Use one of: %s.',
                    $itemType,
                    $itemPath,
                    \implode(', ', \array_keys($typesByKey)),
                );
            }

            // Global block reference (e.g. `<type ref="..."/>` inside `row.blocks`):
            // recursively compose and validate the child block against its full global block XML schema & presets.
            if (
                $depth < 5
                && null !== $subTypeSchema
                && true === ($subTypeSchema['global_block_ref'] ?? false)
                && 'column_break' !== $itemType
                && $this->schemaExtractor->hasBlockDocument($itemType)
            ) {
                $childComposed = $this->composeBlockPayload($itemType, $item, null, $locale, $depth + 1);
                foreach ($childComposed['errors'] as $childError) {
                    $errors[] = \sprintf('%s (%s): %s', $itemPath, $itemType, $childError);
                }
                foreach ($childComposed['warnings'] as $childWarning) {
                    $warnings[] = \sprintf('%s (%s): %s', $itemPath, $itemType, $childWarning);
                }
                $normalizedList[] = $childComposed['flat_block'];

                continue;
            }

            /** @var array<string, array<string, mixed>> $subContentMap */
            $subContentMap = [];
            /** @var array<string, array<string, mixed>> $subStyleMap */
            $subStyleMap = [];
            if (null !== $subTypeSchema) {
                if (isset($subTypeSchema['content_fields']) && \is_array($subTypeSchema['content_fields'])) {
                    foreach ($subTypeSchema['content_fields'] as $cf) {
                        if (\is_array($cf) && isset($cf['name']) && \is_string($cf['name'])) {
                            /** @var array<string, mixed> $typedCf */
                            $typedCf = $cf;
                            $subContentMap[$cf['name']] = $typedCf;
                        }
                    }
                }
                if (isset($subTypeSchema['style_props']) && \is_array($subTypeSchema['style_props'])) {
                    foreach ($subTypeSchema['style_props'] as $sp) {
                        if (\is_array($sp) && isset($sp['name']) && \is_string($sp['name'])) {
                            /** @var array<string, mixed> $typedSp */
                            $typedSp = $sp;
                            $subStyleMap[$sp['name']] = $typedSp;
                        }
                    }
                }
            }

            // Bidirectional normalization of `props` and `props/*` on the nested item
            /** @var array<string, mixed> $itemProps */
            $itemProps = [];
            if (isset($item['props']) && \is_array($item['props'])) {
                foreach ($item['props'] as $pk => $pv) {
                    $strPk = (string) $pk;
                    if (\str_starts_with($strPk, 'props/')) {
                        $strPk = \substr($strPk, 6);
                    }
                    if ('' !== $strPk) {
                        $itemProps[$strPk] = $pv;
                    }
                }
            }
            foreach ($item as $ik => $iv) {
                if (\str_starts_with($ik, 'props/')) {
                    $shortIk = \substr($ik, 6);
                    if ('' !== $shortIk) {
                        $itemProps[$shortIk] = $iv;
                    }
                }
            }
            if ([] !== $itemProps) {
                $item['props'] = $itemProps;
                foreach ($itemProps as $pk => $pv) {
                    $item['props/' . $pk] = $pv;
                }
            }

            // Validate nested item style_props and content_fields against sub-type schema
            foreach ($itemProps as $pk => $pv) {
                if ('preset' === $pk || null === $pv || '' === $pv || !isset($subStyleMap[$pk])) {
                    continue;
                }
                $allowed = $this->extractStringList($subStyleMap[$pk]['allowed_values'] ?? null);
                $this->validateAllowedValue(
                    $pv,
                    $allowed,
                    \sprintf('%s style prop "%s" (`props/%s`)', $itemPath, $pk, $pk),
                    $errors,
                );
            }

            foreach ($item as $fk => $fv) {
                if (\in_array($fk, ['type', '_id', 'props'], true) || \str_starts_with($fk, 'props/') || null === $fv || '' === $fv) {
                    continue;
                }
                if (isset($subContentMap[$fk])) {
                    $allowed = $this->extractStringList($subContentMap[$fk]['allowed_values'] ?? null);
                    $this->validateAllowedValue(
                        $fv,
                        $allowed,
                        \sprintf('%s content field "%s"', $itemPath, $fk),
                        $errors,
                    );
                }
            }

            // Recurse into child nested blocks (e.g. `items[0].elements`)
            if ($depth < 5 && null !== $subTypeSchema && isset($subTypeSchema['nested_blocks']) && \is_array($subTypeSchema['nested_blocks'])) {
                foreach ($subTypeSchema['nested_blocks'] as $childNestedKey => $childNestedDef) {
                    $strChildKey = (string) $childNestedKey;
                    if (!isset($item[$strChildKey]) || !\is_array($item[$strChildKey]) || !\is_array($childNestedDef)) {
                        continue;
                    }
                    /** @var array<string, mixed> $typedChildDef */
                    $typedChildDef = $childNestedDef;
                    $item[$strChildKey] = $this->normalizeAndValidateNestedBlockList(
                        $item[$strChildKey],
                        $typedChildDef,
                        $item['_id'] . '_' . $strChildKey,
                        $itemPath . '.' . $strChildKey,
                        $locale,
                        $errors,
                        $warnings,
                        $depth + 1,
                    );
                }
            }

            $normalizedList[] = $item;
        }

        return $normalizedList;
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $errors
     */
    private function validateAllowedValue(mixed $value, array $allowed, string $fieldLabel, array &$errors): void
    {
        if ([] === $allowed) {
            return;
        }

        if (\is_scalar($value)) {
            $strVal = (string) $value;
            if (!\in_array($strVal, $allowed, true)) {
                $errors[] = \sprintf(
                    'Invalid value "%s" for %s. Use one of: %s.',
                    $strVal,
                    $fieldLabel,
                    \implode(', ', $allowed),
                );
            }

            return;
        }

        if (\is_array($value)) {
            foreach ($value as $itemVal) {
                if (\is_scalar($itemVal)) {
                    $strVal = (string) $itemVal;
                    if (!\in_array($strVal, $allowed, true)) {
                        $errors[] = \sprintf(
                            'Invalid value "%s" for %s. Use one of: %s.',
                            $strVal,
                            $fieldLabel,
                            \implode(', ', $allowed),
                        );
                    }
                }
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $contentFields
     * @param list<array<string, mixed>> $styleProps
     * @param array<string, array<string, mixed>> $nestedBlocks
     *
     * @return array<string, mixed>
     */
    private function buildPositiveExample(string $blockType, array $contentFields, array $styleProps, array $nestedBlocks): array
    {
        $example = [
            'type' => $blockType,
        ];

        foreach ($contentFields as $field) {
            $name = isset($field['name']) && \is_string($field['name']) ? $field['name'] : '';
            if (\in_array($name, ['title', 'content', 'code'], true)) {
                $example[$name] = 'content' === $name ? '<p>Example content</p>' : 'Example ' . \ucfirst($name);
                break;
            }
        }

        $sampleProps = [];
        foreach (\array_slice($styleProps, 0, 2) as $prop) {
            $propName = isset($prop['name']) && \is_string($prop['name']) ? $prop['name'] : '';
            if ('' === $propName) {
                continue;
            }
            if (isset($prop['default']) && \is_string($prop['default'])) {
                $sampleProps[$propName] = $prop['default'];
            } elseif (isset($prop['allowed_values']) && \is_array($prop['allowed_values']) && isset($prop['allowed_values'][0])) {
                $sampleProps[$propName] = $prop['allowed_values'][0];
            }
        }
        if ([] !== $sampleProps) {
            $example['props'] = $sampleProps;
        }

        if (isset($nestedBlocks['items']) && isset($nestedBlocks['items']['default_type']) && \is_string($nestedBlocks['items']['default_type'])) {
            $example['items'] = [
                [
                    'type' => $nestedBlocks['items']['default_type'],
                    'title' => 'Item 1',
                ],
            ];
        }

        return $example;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function resolveRawPresetsForType(string $blockType, string $locale): array
    {
        if (null === $this->resolvedPresetProvider) {
            return [];
        }

        return $this->normalizePresetMap($this->resolvedPresetProvider->getPresets($blockType, $locale));
    }

    private function createFallbackYamlPresetProvider(string $blocksXmlDir): ?BlockPresetProviderInterface
    {
        if (!\class_exists(YamlBlockPresetProvider::class)) {
            return null;
        }

        $candidates = [];
        $candidates[] = \dirname($blocksXmlDir, 2) . '/blocks/presets';
        $cwd = \getcwd();
        if (false !== $cwd) {
            $candidates[] = $cwd . '/config/blocks/presets';
        }
        $candidates[] = \dirname($blocksXmlDir, 5) . '/config/blocks/presets';

        $validDirs = [];
        foreach (\array_unique($candidates) as $dir) {
            if (\is_dir($dir)) {
                $validDirs[] = $dir;
            }
        }

        if ([] === $validDirs) {
            return null;
        }

        return new YamlBlockPresetProvider($validDirs, enableSnippetPresets: false);
    }

    /**
     * @param array<int|string, mixed> $raw
     *
     * @return array<string, array<string, mixed>>
     */
    private function normalizePresetMap(array $raw): array
    {
        $map = [];
        foreach ($raw as $key => $item) {
            if (!\is_array($item)) {
                continue;
            }
            $presetKey = \is_string($key) && '' !== $key
                ? $key
                : (isset($item['id']) && \is_string($item['id']) ? $item['id'] : (string) $key);
            if ('' === $presetKey) {
                continue;
            }
            /** @var array<string, mixed> $normalizedItem */
            $normalizedItem = $item;
            $map[$presetKey] = $normalizedItem;
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function extractStringList(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $list = [];
        foreach ($raw as $item) {
            if (\is_scalar($item)) {
                $list[] = (string) $item;
            }
        }

        return $list;
    }

    /**
     * Checks whether a block field name represents translatable human content rather than
     * non-translatable technical `props/*` or internal `_` metadata.
     */
    public function isTranslatableField(string $fieldName): bool
    {
        $clean = \trim($fieldName);
        if ('' === $clean || 'type' === $clean || 'props' === $clean) {
            return false;
        }

        if (\str_starts_with($clean, 'props/') || \str_starts_with($clean, '_')) {
            return false;
        }

        return true;
    }

    /**
     * Restores non-translatable technical `props` and `props/*` keys from `$sourceBlockData`
     * onto `$translatedBlockData` so AI full-content translation cannot corrupt CSS classes, IDs, or enum keys.
     *
     * @param array<string, mixed> $sourceBlockData
     * @param array<string, mixed> $translatedBlockData
     *
     * @return array<string, mixed>
     */
    public function preserveTechnicalProps(array $sourceBlockData, array $translatedBlockData): array
    {
        $result = $translatedBlockData;

        if (isset($sourceBlockData['props']) && \is_array($sourceBlockData['props'])) {
            $translatedProps = isset($result['props']) && \is_array($result['props'])
                ? $result['props']
                : [];

            foreach ($sourceBlockData['props'] as $propKey => $sourceValue) {
                if (!\is_string($propKey) || '' === $propKey) {
                    continue;
                }
                $fullKey = \str_starts_with($propKey, 'props/') ? $propKey : 'props/' . $propKey;
                if (!$this->isTranslatableField($fullKey)) {
                    $translatedProps[$propKey] = $sourceValue;
                }
            }

            $result['props'] = $translatedProps;
        }

        foreach ($sourceBlockData as $key => $sourceValue) {
            if (!\str_starts_with($key, 'props/')) {
                continue;
            }
            if (!$this->isTranslatableField($key)) {
                $result[$key] = $sourceValue;
            }
        }

        return $result;
    }
}
