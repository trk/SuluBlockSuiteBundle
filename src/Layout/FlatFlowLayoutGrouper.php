<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Layout;

use Totoglu\SuluBlockSuiteBundle\Normalizer\SuluDataNormalizer;

/**
 * Deterministic Flat-Flow Break Block State Machine that groups a flat stream of Sulu blocks
 * (`section_break`, `row`, `column_break`, and leaf content blocks) into a hierarchical
 * `Section -> Row -> Column -> Blocks` render tree while preserving standalone top-level blocks.
 *
 * Acts as the inverse of {@see \Totoglu\SuluBlockSuiteBundle\Transformer\SectionFlattener}.
 */
final class FlatFlowLayoutGrouper
{
    /**
     * @var array<string, string>
     */
    public const DEFAULT_SECTION_PROPS = [
        'max_width' => '85rem',
        'padding' => 'default',
    ];

    /**
     * @var array<string, string>
     */
    public const DEFAULT_ROW_PROPS = [
        'layout' => '12-col',
        'gap' => 'md',
    ];

    /**
     * @var array<string, string>
     */
    public const DEFAULT_HALVES_ROW_PROPS = [
        'layout' => 'halves',
        'gap' => 'md',
    ];

    /**
     * @var array<string, string>
     */
    public const DEFAULT_COLUMN_PROPS = [
        'col_span' => '12',
    ];

    /**
     * @var list<string>
     */
    public const DEFAULT_SECTION_INHERITED_PROP_KEYS = [
        'html_tag',
        'header_sticky',
        'header_shell',
        'max_width',
        'bg_style',
        'bg_overlay',
        'bg_image_url',
        'padding',
        'padding_remove_top',
        'padding_remove_bottom',
    ];

    /**
     * @var list<string>
     */
    private const IGNORED_BREAK_META_KEYS = [
        'type',
        'props',
        '_props',
        'rows',
        'columns',
        'blocks',
        '_pipeline_processed',
        '_dynamic_hydrated',
        '_sulu_block_id',
        '_section_group',
    ];

    /**
     * Transforms a flat root block stream into top-level render entries (`is_auto_section: true|false`)
     * according to the Flat-Flow Break Block State Machine rules.
     *
     * @param array<array-key, mixed> $blocks
     * @param list<string>            $topLevelTypes
     * @param array<string, string>   $defaultSectionProps
     * @param array<string, string>   $defaultRowProps
     * @param array<string, string>   $defaultHalvesRowProps
     * @param array<string, string>   $defaultColumnProps
     * @param list<string>            $sectionInheritedPropKeys
     *
     * @return list<array{
     *     is_auto_section: bool,
     *     props?: array<string, mixed>,
     *     rows?: list<array{type: string, props: array<string, mixed>, columns: list<array{type: string, props: array<string, mixed>, blocks: list<array<string, mixed>>}>}>,
     *     blocks?: list<array<string, mixed>>,
     *     block?: array<string, mixed>,
     * }>
     */
    public static function buildTopLevelEntries(
        array $blocks,
        array $topLevelTypes = ['navbar'],
        array $defaultSectionProps = self::DEFAULT_SECTION_PROPS,
        array $defaultRowProps = self::DEFAULT_ROW_PROPS,
        array $defaultHalvesRowProps = self::DEFAULT_HALVES_ROW_PROPS,
        array $defaultColumnProps = self::DEFAULT_COLUMN_PROPS,
        array $sectionInheritedPropKeys = self::DEFAULT_SECTION_INHERITED_PROP_KEYS,
    ): array {
        /**
         * @var list<array{
         *     is_auto_section: bool,
         *     props?: array<string, mixed>,
         *     rows?: list<array{type: string, props: array<string, mixed>, columns: list<array{type: string, props: array<string, mixed>, blocks: list<array<string, mixed>>}>}>,
         *     blocks?: list<array<string, mixed>>,
         *     block?: array<string, mixed>,
         * }> $entries
         */
        $entries = [];

        /**
         * @var array{
         *     props: array<string, mixed>,
         *     rows: list<array{type: string, props: array<string, mixed>, columns: list<array{type: string, props: array<string, mixed>, blocks: list<array<string, mixed>>}>}>,
         *     blocks: list<array<string, mixed>>,
         *     explicit: bool,
         *     has_leading_break: bool
         * }|null $currentSection
         */
        $currentSection = null;

        /**
         * @var array{
         *     props: array<string, mixed>,
         *     columns: list<array{type: string, props: array<string, mixed>, blocks: list<array<string, mixed>>}>
         * }|null $currentRow
         */
        $currentRow = null;

        /** @var array{props: array<string, mixed>, blocks: list<array<string, mixed>>, implicit_col_12: bool}|null $currentColumn */
        $currentColumn = null;

        /** @var mixed $currentGroup */
        $currentGroup = null;

        $flushColumn = static function () use (&$currentColumn, &$currentRow): void {
            if (null === $currentColumn || null === $currentRow) {
                $currentColumn = null;

                return;
            }

            $currentRow['columns'][] = [
                'type' => 'column',
                'props' => $currentColumn['props'],
                'blocks' => $currentColumn['blocks'],
            ];

            $currentColumn = null;
        };

        $flushRow = static function () use ($flushColumn, &$currentRow, &$currentSection): void {
            $flushColumn();

            if (null === $currentRow || null === $currentSection) {
                $currentRow = null;

                return;
            }

            if ([] !== $currentRow['columns']) {
                $currentSection['rows'][] = [
                    'type' => 'row',
                    'props' => $currentRow['props'],
                    'columns' => $currentRow['columns'],
                ];
            }

            $currentRow = null;
        };

        $flushSection = static function () use ($flushRow, &$currentSection, &$currentGroup, &$entries): void {
            $flushRow();

            if (null === $currentSection) {
                return;
            }

            if ([] !== $currentSection['rows'] || $currentSection['explicit']) {
                $entries[] = [
                    'is_auto_section' => true,
                    'props' => $currentSection['props'],
                    'rows' => $currentSection['rows'],
                    'blocks' => $currentSection['blocks'],
                ];
            }

            $currentSection = null;
            $currentGroup = null;
        };

        foreach ($blocks as $rawBlock) {
            if (!\is_array($rawBlock)) {
                continue;
            }

            /** @var array<string, mixed> $block */
            $block = $rawBlock;
            $type = isset($block['type']) && \is_scalar($block['type']) ? (string) $block['type'] : '';

            // Rule 5: Top-Level Standalone Blocks
            if (\in_array($type, $topLevelTypes, true)) {
                $flushSection();
                $entries[] = [
                    'is_auto_section' => false,
                    'block' => $block,
                ];

                continue;
            }

            // Rule 2: Section Break (closes current accumulated section when blocks precede it, or opens a leading section)
            if ('section_break' === $type) {
                $flushRow();
                $sectionBreakProps = self::extractSectionBreakProps($block, $defaultSectionProps);

                if (null !== $currentSection && [] !== $currentSection['rows'] && !$currentSection['has_leading_break']) {
                    $explicitBreakProps = self::extractBreakProps($block, []);
                    foreach ($explicitBreakProps as $bKey => $bVal) {
                        $currentSection['props'][$bKey] = $bVal;
                    }
                    foreach ($defaultSectionProps as $dKey => $dVal) {
                        if (!isset($currentSection['props'][$dKey]) || '' === $currentSection['props'][$dKey]) {
                            $currentSection['props'][$dKey] = $dVal;
                        }
                    }
                    $currentSection['explicit'] = true;
                    $flushSection();

                    continue;
                }

                $flushSection();
                $currentSection = [
                    'props' => $sectionBreakProps,
                    'rows' => [],
                    'blocks' => [],
                    'explicit' => true,
                    'has_leading_break' => true,
                ];

                continue;
            }

            // Rule 3: Explicit Row Block (`row` with flat `blocks` + `column_break` dividers)
            if ('row' === $type) {
                $flushRow();

                if (null === $currentSection) {
                    $currentSection = [
                        'props' => $defaultSectionProps,
                        'rows' => [],
                        'blocks' => [],
                        'explicit' => true,
                        'has_leading_break' => false,
                    ];
                } else {
                    $currentSection['explicit'] = true;
                }

                $rowColumns = self::extractRowColumns($block);
                $rowDefaultProps = \count($rowColumns) >= 2 ? $defaultHalvesRowProps : $defaultRowProps;
                $extractedRowProps = self::extractBreakProps($block, $rowDefaultProps);
                foreach ($sectionInheritedPropKeys as $secKey) {
                    if (isset($extractedRowProps[$secKey]) && '' !== $extractedRowProps[$secKey]) {
                        $existing = $currentSection['props'][$secKey] ?? null;
                        $defaultVal = $defaultSectionProps[$secKey] ?? null;
                        if (null === $existing || '' === $existing || $existing === $defaultVal) {
                            $currentSection['props'][$secKey] = $extractedRowProps[$secKey];
                        }
                        unset($extractedRowProps[$secKey]);
                    }
                }
                $currentSection['rows'][] = [
                    'type' => 'row',
                    'props' => $extractedRowProps,
                    'columns' => $rowColumns,
                ];
                foreach ($rowColumns as $colItem) {
                    foreach ($colItem['blocks'] as $nestedBlock) {
                        $currentSection['blocks'][] = $nestedBlock;
                    }
                }

                continue;
            }

            // Rule 4: Column Break
            if ('column_break' === $type) {
                $colProps = self::extractColumnBreakProps($block);

                if (null === $currentSection) {
                    $currentSection = [
                        'props' => $defaultSectionProps,
                        'rows' => [],
                        'blocks' => [],
                        'explicit' => true,
                        'has_leading_break' => false,
                    ];
                } else {
                    $currentSection['explicit'] = true;
                }

                if (null === $currentRow) {
                    $currentRow = [
                        'props' => $defaultHalvesRowProps,
                        'columns' => [],
                    ];
                    $currentColumn = [
                        'props' => $colProps,
                        'blocks' => [],
                        'implicit_col_12' => false,
                    ];

                    continue;
                }

                // Upgrade implicit 12-col row to 'halves' when column_break is used
                $currentRow['props']['layout'] = $defaultHalvesRowProps['layout'] ?? 'halves';
                if (null !== $currentColumn && $currentColumn['implicit_col_12']) {
                    unset($currentColumn['props']['col_span']);
                    $currentColumn['implicit_col_12'] = false;
                }

                $flushColumn();
                $currentColumn = [
                    'props' => $colProps,
                    'blocks' => [],
                    'implicit_col_12' => false,
                ];

                continue;
            }

            // Rule 1: Leaf Content Block
            $itemGroup = $block['_section_group'] ?? null;
            if (
                null !== $currentSection
                && !$currentSection['explicit']
                && [] !== $currentSection['blocks']
                && $itemGroup !== $currentGroup
            ) {
                $flushSection();
            }

            if (null === $currentSection) {
                $currentSection = [
                    'props' => $defaultSectionProps,
                    'rows' => [],
                    'blocks' => [],
                    'explicit' => false,
                    'has_leading_break' => false,
                ];
                $currentGroup = $itemGroup;
            }

            if (null === $currentRow) {
                $currentRow = [
                    'props' => $defaultRowProps,
                    'columns' => [],
                ];
            }

            if (null === $currentColumn) {
                $currentColumn = [
                    'props' => $defaultColumnProps,
                    'blocks' => [],
                    'implicit_col_12' => true,
                ];
            }

            $currentColumn['blocks'][] = $block;
            $currentSection['blocks'][] = $block;
        }

        $flushSection();

        return $entries;
    }

    /**
     * @param array<string, mixed>  $block
     * @param array<string, string> $defaultSectionProps
     *
     * @return array<string, mixed>
     */
    public static function extractSectionBreakProps(
        array $block,
        array $defaultSectionProps = self::DEFAULT_SECTION_PROPS,
    ): array {
        return self::extractBreakProps($block, $defaultSectionProps);
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    public static function extractColumnBreakProps(array $block): array
    {
        return self::extractBreakProps($block, []);
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return list<array{type: string, props: array<string, mixed>, blocks: list<array<string, mixed>>}>
     */
    public static function extractRowColumns(array $block): array
    {
        $rawBlocks = $block['blocks'] ?? null;
        if (!\is_array($rawBlocks)) {
            $props = SuluDataNormalizer::extractProps($block);
            $rawBlocks = $props['blocks'] ?? null;
        }

        if (!\is_array($rawBlocks)) {
            return [];
        }

        /** @var list<array{type: string, props: array<string, mixed>, blocks: list<array<string, mixed>>}> $columns */
        $columns = [];
        /** @var list<array<string, mixed>> $currentBlocks */
        $currentBlocks = [];
        /** @var array<string, mixed> $pendingProps */
        $pendingProps = [];

        foreach ($rawBlocks as $rawItem) {
            if (!\is_array($rawItem)) {
                continue;
            }

            /** @var array<string, mixed> $item */
            $item = $rawItem;
            $itemType = isset($item['type']) && \is_scalar($item['type']) ? (string) $item['type'] : '';

            if ('column_break' === $itemType) {
                $cbProps = self::extractColumnBreakProps($item);
                if ([] !== $currentBlocks) {
                    $columns[] = [
                        'type' => 'column',
                        'props' => $cbProps,
                        'blocks' => $currentBlocks,
                    ];
                    $currentBlocks = [];
                    $pendingProps = [];
                } else {
                    $pendingProps = $cbProps;
                }

                continue;
            }

            $currentBlocks[] = $item;
        }

        if ([] !== $currentBlocks) {
            $columns[] = [
                'type' => 'column',
                'props' => $pendingProps,
                'blocks' => $currentBlocks,
            ];
        }

        return $columns;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    public static function extractBreakProps(array $block, array $defaults = []): array
    {
        $props = $defaults;

        foreach ($block as $key => $value) {
            if (\in_array($key, self::IGNORED_BREAK_META_KEYS, true)) {
                continue;
            }

            if (\str_starts_with($key, 'props/')) {
                $propName = \substr($key, 6);
                if ('' !== $propName && null !== $value && '' !== $value) {
                    $props[$propName] = $value;
                }

                continue;
            }

            if (null !== $value && '' !== $value) {
                $props[$key] = $value;
            }
        }

        foreach (SuluDataNormalizer::extractProps($block) as $key => $value) {
            if (null !== $value && '' !== $value) {
                $props[$key] = $value;
            }
        }

        return $props;
    }
}
