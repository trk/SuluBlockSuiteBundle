<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Preset;

/**
 * Deep module encapsulating preset payload normalization (`props` and `values` schema alignment,
 * `section -> row -> column -> child block` layout normalization, shorthand element conversion)
 * and automatic child preset derivation/hydration from container (`section`, `row`) presets.
 */
final class PresetPayloadNormalizer
{
    public static function isExtractableChildBlockType(string $blockType): bool
    {
        return '' !== $blockType
            && 'section' !== $blockType
            && 'row' !== $blockType
            && 'column_break' !== $blockType
            && 'section_break' !== $blockType
            && 'reusable' !== $blockType;
    }

    /**
     * Normalizes block-level `props` and `values` so shorthand preset fields align with Sulu XML form schemas.
     *
     * @param array<string, mixed> $props
     * @param array<string, mixed> $values
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function normalizeBlockPropsAndValues(string $blockType, array $props, array $values): array
    {
        if ('section' === $blockType && isset($values['rows']) && \is_array($values['rows'])) {
            $values['rows'] = self::normalizeSectionRows($values['rows']);

            return [$props, $values];
        }

        if ('row' === $blockType && isset($values['blocks']) && \is_array($values['blocks'])) {
            $normalizedBlocks = [];
            foreach ($values['blocks'] as $rawBlk) {
                if (!\is_array($rawBlk)) {
                    continue;
                }
                /** @var array<string, mixed> $typedBlk */
                $typedBlk = [];
                foreach ($rawBlk as $bk => $bv) {
                    $typedBlk[(string) $bk] = $bv;
                }
                if ('column_break' === ($typedBlk['type'] ?? null)) {
                    /** @var array<string, mixed> $cbProps */
                    $cbProps = [];
                    if (isset($typedBlk['props']) && \is_array($typedBlk['props'])) {
                        foreach ($typedBlk['props'] as $cpk => $cpv) {
                            $cbProps[(string) $cpk] = $cpv;
                        }
                    }
                    foreach (\array_keys($typedBlk) as $cKey) {
                        if (\in_array($cKey, ['type', 'props'], true)) {
                            continue;
                        }
                        if (!\array_key_exists($cKey, $cbProps)) {
                            $cbProps[$cKey] = $typedBlk[$cKey];
                        }
                        unset($typedBlk[$cKey]);
                    }
                    $typedBlk['props'] = $cbProps;
                }
                $normalizedBlocks[] = $typedBlk;
            }
            $values['blocks'] = $normalizedBlocks;

            return [$props, $values];
        }

        if ('row' === $blockType && isset($values['columns']) && \is_array($values['columns'])) {
            $normalizedRows = self::normalizeSectionRows([
                [
                    'type' => 'row',
                    'props' => $props,
                    'columns' => $values['columns'],
                ],
            ]);
            if (isset($normalizedRows[0])) {
                $rowProps = isset($normalizedRows[0]['props']) && \is_array($normalizedRows[0]['props']) ? $normalizedRows[0]['props'] : $props;
                $rowCols = isset($normalizedRows[0]['columns']) && \is_array($normalizedRows[0]['columns']) ? $normalizedRows[0]['columns'] : $values['columns'];
                /** @var array<string, mixed> $typedRowProps */
                $typedRowProps = [];
                foreach ($rowProps as $pk => $pv) {
                    $typedRowProps[(string) $pk] = $pv;
                }
                $props = $typedRowProps;
                $values['columns'] = $rowCols;
            }

            return [$props, $values];
        }

        if (isset($values['link_style']) && !isset($props['button_style'])) {
            $props['button_style'] = $values['link_style'];
        }
        unset($values['link_style'], $values['button_color']);
        if ('grid' === $blockType) {
            unset($values['rounded']);
        }

        if ('list' === $blockType && isset($values['items']) && \is_array($values['items'])) {
            $normalizedItems = [];
            foreach ($values['items'] as $item) {
                if (\is_array($item)) {
                    /** @var array<string, mixed> $typedItem */
                    $typedItem = [];
                    foreach ($item as $ik => $iv) {
                        $typedItem[(string) $ik] = $iv;
                    }
                    if (isset($typedItem['link_text']) && !isset($typedItem['link_title'])) {
                        $typedItem['link_title'] = $typedItem['link_text'];
                    }
                    unset($typedItem['link_text'], $typedItem['link_style']);
                    $normalizedItems[] = $typedItem;
                }
            }
            $values['items'] = $normalizedItems;

            return [$props, $values];
        }

        if (isset($values['items']) && \is_array($values['items'])) {
            $normalizedItems = [];
            foreach ($values['items'] as $item) {
                if (\is_array($item)) {
                    /** @var array<string, mixed> $typedItem */
                    $typedItem = [];
                    foreach ($item as $ik => $iv) {
                        $typedItem[(string) $ik] = $iv;
                    }
                    $itemType = isset($typedItem['type']) && \is_string($typedItem['type']) ? $typedItem['type'] : '';
                    if (\str_ends_with($itemType, '_item')) {
                        unset($typedItem['media_source'], $typedItem['link_style']);
                        if (isset($typedItem['link_text']) && !isset($typedItem['link_title'])) {
                            $typedItem['link_title'] = $typedItem['link_text'];
                        }
                        unset($typedItem['link_text']);
                    } elseif (!\in_array($itemType, ['button', 'badge'], true)) {
                        $typedItem = self::normalizeElementPayload($typedItem);
                        if (
                            ('card' === $itemType || '' === $itemType)
                            && !empty($typedItem['icon'])
                            && empty($typedItem['media_type'])
                            && empty($typedItem['image'])
                            && empty($typedItem['image_url'])
                            && empty($typedItem['video'])
                            && empty($typedItem['video_url'])
                        ) {
                            $typedItem['media_type'] = 'icon';
                        }
                    }
                    $normalizedItems[] = $typedItem;
                }
            }
            $values['items'] = $normalizedItems;
        } elseif ('card' === $blockType || (!\in_array($blockType, ['form', 'cta', 'hero'], true) && (isset($values['badge']) || isset($values['link_text'])))) {
            $values = self::normalizeElementPayload($values);
            if (
                'card' === $blockType
                && !empty($values['icon'])
                && empty($values['media_type'])
                && empty($props['media_type'])
                && empty($values['image'])
                && empty($values['image_url'])
                && empty($values['video'])
                && empty($values['video_url'])
            ) {
                $values['media_type'] = 'icon';
            }
        }

        return [$props, $values];
    }

    /**
     * @param array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>} $sectionPreset
     * @param callable(string, string): array<string, array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}> $directPresetsLoader
     *
     * @return array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}
     */
    public static function hydrateSectionChildPresets(
        string $sectionPresetId,
        array $sectionPreset,
        string $locale,
        callable $directPresetsLoader,
    ): array {
        if (!isset($sectionPreset['values'])) {
            return $sectionPreset;
        }

        if (!isset($sectionPreset['values']['rows']) || !\is_array($sectionPreset['values']['rows'])) {
            return $sectionPreset;
        }

        $rows = $sectionPreset['values']['rows'];
        $typeCounts = self::countSectionChildBlocksByType($rows);
        /** @var array<string, int> $typeOrdinals */
        $typeOrdinals = [];

        foreach ($rows as $rowIndex => $row) {
            if (!\is_array($row) || !isset($row['columns']) || !\is_array($row['columns'])) {
                continue;
            }

            foreach ($row['columns'] as $colIndex => $column) {
                if (!\is_array($column) || !isset($column['blocks']) || !\is_array($column['blocks'])) {
                    continue;
                }

                foreach ($column['blocks'] as $blockIndex => $childBlock) {
                    if (!\is_array($childBlock)) {
                        continue;
                    }

                    $childType = isset($childBlock['type']) && \is_string($childBlock['type']) ? \trim($childBlock['type']) : '';
                    if ('' === $childType) {
                        continue;
                    }

                    /** @var array<string, mixed> $childProps */
                    $childProps = [];
                    if (isset($childBlock['props']) && \is_array($childBlock['props'])) {
                        foreach ($childBlock['props'] as $pk => $pv) {
                            $childProps[(string) $pk] = $pv;
                        }
                    }

                    $rawChildPresetId = $childProps['preset'] ?? ($childBlock['preset'] ?? null);
                    $childPresetId = \is_string($rawChildPresetId) && '' !== \trim($rawChildPresetId)
                        ? \trim($rawChildPresetId)
                        : null;

                    if (self::isExtractableChildBlockType($childType)) {
                        $typeOrdinals[$childType] = ($typeOrdinals[$childType] ?? 0) + 1;
                        $ordinal = $typeOrdinals[$childType];
                        $totalOfType = $typeCounts[$childType] ?? 1;
                        $derivedPresetId = $totalOfType > 1
                            ? $sectionPresetId . '_' . $childType . '_' . $ordinal
                            : $sectionPresetId;

                        if (null === $childPresetId) {
                            $directChildPresets = $directPresetsLoader($childType, $locale);
                            [$extractedProps, $extractedValues] = self::extractChildBlockParts($childBlock, $childProps);

                            if (
                                isset($directChildPresets[$derivedPresetId])
                                || [] !== $extractedProps
                                || [] !== $extractedValues
                            ) {
                                $childPresetId = $derivedPresetId;
                                $childProps['preset'] = $childPresetId;
                                $childBlock['props'] = $childProps;
                            }
                        }
                    }

                    if (null !== $childPresetId) {
                        $childProps['preset'] = $childPresetId;
                        $childBlock['props'] = $childProps;

                        $directChildPresets = $directPresetsLoader($childType, $locale);
                        if (isset($directChildPresets[$childPresetId])) {
                            $targetChildPreset = $directChildPresets[$childPresetId];

                            foreach ($targetChildPreset['props'] as $propKey => $propVal) {
                                if (!\array_key_exists($propKey, $childProps) || null === $childProps[$propKey] || '' === $childProps[$propKey]) {
                                    $childProps[$propKey] = $propVal;
                                }
                            }
                            $childProps['preset'] = $childPresetId;
                            $childBlock['props'] = $childProps;

                            $presetValues = $targetChildPreset['values'] ?? [];
                            foreach ($presetValues as $valKey => $val) {
                                if ('type' === $valKey || 'props' === $valKey) {
                                    continue;
                                }
                                if (
                                    !\array_key_exists($valKey, $childBlock)
                                    || null === $childBlock[$valKey]
                                    || '' === $childBlock[$valKey]
                                    || ([] === $childBlock[$valKey] && \is_array($val) && [] !== $val)
                                ) {
                                    $childBlock[$valKey] = $val;
                                }
                            }
                        }
                    }

                    [$normalizedChildProps, $normalizedChildValues] = self::extractChildBlockParts($childBlock, $childProps);
                    if (null !== $childPresetId) {
                        $normalizedChildProps['preset'] = $childPresetId;
                    }
                    $childBlock = [
                        'type' => $childType,
                        'props' => $normalizedChildProps,
                        ...$normalizedChildValues,
                    ];

                    $column['blocks'][$blockIndex] = $childBlock;
                }

                $row['columns'][$colIndex] = $column;
            }

            $rows[$rowIndex] = $row;
        }

        $sectionPreset['values']['rows'] = $rows;

        return $sectionPreset;
    }

    /**
     * @param array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>} $rowPreset
     * @param callable(string, string): array<string, array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}> $directPresetsLoader
     *
     * @return array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}
     */
    public static function hydrateRowChildPresets(
        string $rowPresetId,
        array $rowPreset,
        string $locale,
        callable $directPresetsLoader,
    ): array {
        if (!isset($rowPreset['values'])) {
            return $rowPreset;
        }

        if (isset($rowPreset['values']['blocks']) && \is_array($rowPreset['values']['blocks'])) {
            $virtualSection = $rowPreset;
            $virtualSection['values'] = [
                'rows' => [
                    [
                        'type' => 'row',
                        'props' => $rowPreset['props'],
                        'columns' => [
                            [
                                'blocks' => $rowPreset['values']['blocks'],
                            ],
                        ],
                    ],
                ],
            ];

            $hydrated = self::hydrateSectionChildPresets($rowPresetId, $virtualSection, $locale, $directPresetsLoader);
            $hydratedRows = isset($hydrated['values']['rows']) && \is_array($hydrated['values']['rows']) ? $hydrated['values']['rows'] : [];
            if (
                isset($hydratedRows[0])
                && \is_array($hydratedRows[0])
                && isset($hydratedRows[0]['columns'])
                && \is_array($hydratedRows[0]['columns'])
                && isset($hydratedRows[0]['columns'][0])
                && \is_array($hydratedRows[0]['columns'][0])
                && isset($hydratedRows[0]['columns'][0]['blocks'])
                && \is_array($hydratedRows[0]['columns'][0]['blocks'])
            ) {
                $rowPreset['values']['blocks'] = $hydratedRows[0]['columns'][0]['blocks'];
            }

            return $rowPreset;
        }

        if (!isset($rowPreset['values']['columns']) || !\is_array($rowPreset['values']['columns'])) {
            return $rowPreset;
        }

        $virtualSection = $rowPreset;
        $virtualSection['values'] = [
            'rows' => [
                [
                    'type' => 'row',
                    'props' => $rowPreset['props'],
                    'columns' => $rowPreset['values']['columns'],
                ],
            ],
        ];

        $hydrated = self::hydrateSectionChildPresets($rowPresetId, $virtualSection, $locale, $directPresetsLoader);
        $hydratedRows = isset($hydrated['values']['rows']) && \is_array($hydrated['values']['rows']) ? $hydrated['values']['rows'] : [];
        if (isset($hydratedRows[0]) && \is_array($hydratedRows[0]) && isset($hydratedRows[0]['columns']) && \is_array($hydratedRows[0]['columns'])) {
            $rowPreset['values']['columns'] = $hydratedRows[0]['columns'];
        }

        return $rowPreset;
    }

    /**
     * @param array<string, array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}> $presetsMap
     * @param callable(string, string): array<string, array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}> $directPresetsLoader
     *
     * @return array<string, array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}>
     */
    public static function appendAutoExtractedChildPresets(
        string $blockType,
        string $locale,
        array $presetsMap,
        callable $directPresetsLoader,
    ): array {
        $containerPresets = $directPresetsLoader('section', $locale);
        foreach ($directPresetsLoader('row', $locale) as $rowPresetId => $rowPreset) {
            if (isset($rowPreset['values']['blocks']) && \is_array($rowPreset['values']['blocks'])) {
                $virtual = $rowPreset;
                $virtual['values'] = [
                    'rows' => [
                        [
                            'type' => 'row',
                            'columns' => [
                                [
                                    'blocks' => $rowPreset['values']['blocks'],
                                ],
                            ],
                        ],
                    ],
                ];
                $containerPresets[$rowPresetId] = $virtual;
            } elseif (isset($rowPreset['values']['columns']) && \is_array($rowPreset['values']['columns'])) {
                $virtual = $rowPreset;
                $virtual['values'] = [
                    'rows' => [
                        [
                            'type' => 'row',
                            'columns' => $rowPreset['values']['columns'],
                        ],
                    ],
                ];
                $containerPresets[$rowPresetId] = $virtual;
            }
        }

        foreach ($containerPresets as $sectionPresetId => $sectionPreset) {
            if (!isset($sectionPreset['values'])) {
                continue;
            }

            if (!isset($sectionPreset['values']['rows']) || !\is_array($sectionPreset['values']['rows'])) {
                continue;
            }

            $rows = $sectionPreset['values']['rows'];
            $typeCounts = self::countSectionChildBlocksByType($rows);
            $totalOfType = $typeCounts[$blockType] ?? 0;
            if (0 === $totalOfType) {
                continue;
            }

            $ordinal = 0;

            foreach ($rows as $row) {
                if (!\is_array($row) || !isset($row['columns']) || !\is_array($row['columns'])) {
                    continue;
                }

                foreach ($row['columns'] as $column) {
                    if (!\is_array($column) || !isset($column['blocks']) || !\is_array($column['blocks'])) {
                        continue;
                    }

                    foreach ($column['blocks'] as $childBlock) {
                        if (!\is_array($childBlock)) {
                            continue;
                        }

                        $childType = isset($childBlock['type']) && \is_string($childBlock['type']) ? \trim($childBlock['type']) : '';
                        if ($childType !== $blockType) {
                            continue;
                        }

                        ++$ordinal;

                        /** @var array<string, mixed> $childProps */
                        $childProps = [];
                        if (isset($childBlock['props']) && \is_array($childBlock['props'])) {
                            foreach ($childBlock['props'] as $pk => $pv) {
                                $childProps[(string) $pk] = $pv;
                            }
                        }

                        $rawChildPresetId = $childProps['preset'] ?? ($childBlock['preset'] ?? null);
                        $targetPresetId = \is_string($rawChildPresetId) && '' !== \trim($rawChildPresetId)
                            ? \trim($rawChildPresetId)
                            : ($totalOfType > 1 ? $sectionPresetId . '_' . $blockType . '_' . $ordinal : $sectionPresetId);

                        if (isset($presetsMap[$targetPresetId])) {
                            continue;
                        }

                        [$extractedProps, $extractedValues] = self::extractChildBlockParts($childBlock, $childProps);
                        if ([] === $extractedProps && [] === $extractedValues) {
                            continue;
                        }

                        $derivedTitle = $totalOfType > 1
                            ? $sectionPreset['title'] . ' #' . $ordinal
                            : $sectionPreset['title'];

                        $presetsMap[$targetPresetId] = [
                            'id' => $targetPresetId,
                            'title' => $derivedTitle,
                            'description' => $sectionPreset['description'],
                            'preview_image' => $sectionPreset['preview_image'],
                            'props' => $extractedProps,
                            'values' => $extractedValues,
                        ];
                    }
                }
            }
        }

        return $presetsMap;
    }

    /**
     * @param array<array-key, mixed> $rows
     *
     * @return array<string, int>
     */
    private static function countSectionChildBlocksByType(array $rows): array
    {
        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($rows as $row) {
            if (!\is_array($row) || !isset($row['columns']) || !\is_array($row['columns'])) {
                continue;
            }

            foreach ($row['columns'] as $column) {
                if (!\is_array($column) || !isset($column['blocks']) || !\is_array($column['blocks'])) {
                    continue;
                }

                foreach ($column['blocks'] as $childBlock) {
                    if (!\is_array($childBlock)) {
                        continue;
                    }

                    $childType = isset($childBlock['type']) && \is_string($childBlock['type']) ? \trim($childBlock['type']) : '';
                    if ('' !== $childType) {
                        $counts[$childType] = ($counts[$childType] ?? 0) + 1;
                    }
                }
            }
        }

        return $counts;
    }

    /**
     * @param array<array-key, mixed> $childBlock
     * @param array<string, mixed> $childProps
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private static function extractChildBlockParts(array $childBlock, array $childProps): array
    {
        $extractedProps = $childProps;
        unset($extractedProps['preset']);

        /** @var array<string, mixed> $extractedValues */
        $extractedValues = [];
        foreach ($childBlock as $k => $v) {
            $key = (string) $k;
            if ('type' === $key || 'props' === $key || 'preset' === $key) {
                continue;
            }
            $extractedValues[$key] = $v;
        }

        $childType = isset($childBlock['type']) && \is_string($childBlock['type']) ? \trim($childBlock['type']) : '';
        if ('' !== $childType) {
            [$extractedProps, $extractedValues] = self::normalizeBlockPropsAndValues($childType, $extractedProps, $extractedValues);
        }

        return [$extractedProps, $extractedValues];
    }

    /**
     * Normalizes shared layout row and column settings into `props` so Sulu's Symfony Form (`shared="true"`) persists them.
     *
     * @param array<array-key, mixed> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function normalizeSectionRows(array $rows): array
    {
        $normalizedRows = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $typedRow */
            $typedRow = [];
            foreach ($row as $rk => $rv) {
                $typedRow[(string) $rk] = $rv;
            }

            /** @var array<string, mixed> $rowProps */
            $rowProps = [];
            if (isset($typedRow['props']) && \is_array($typedRow['props'])) {
                foreach ($typedRow['props'] as $pk => $pv) {
                    $rowProps[(string) $pk] = $pv;
                }
            }

            foreach (\array_keys($typedRow) as $sKey) {
                if (\in_array($sKey, ['type', 'props', 'columns'], true)) {
                    continue;
                }
                if (!\array_key_exists($sKey, $rowProps)) {
                    $rowProps[$sKey] = $typedRow[$sKey];
                }
                unset($typedRow[$sKey]);
            }

            $rawCols = isset($typedRow['columns']) && \is_array($typedRow['columns']) ? $typedRow['columns'] : [];
            $colCount = \count($rawCols);
            $hasExplicitColSpan = false;
            $normalizedCols = [];

            foreach ($rawCols as $col) {
                if (!\is_array($col)) {
                    continue;
                }

                /** @var array<string, mixed> $typedCol */
                $typedCol = [];
                foreach ($col as $ck => $cv) {
                    $typedCol[(string) $ck] = $cv;
                }

                /** @var array<string, mixed> $colProps */
                $colProps = [];
                if (isset($typedCol['props']) && \is_array($typedCol['props'])) {
                    foreach ($typedCol['props'] as $cpk => $cpv) {
                        $colProps[(string) $cpk] = $cpv;
                    }
                }

                foreach (\array_keys($typedCol) as $cKey) {
                    if (\in_array($cKey, ['type', 'props', 'blocks'], true)) {
                        continue;
                    }
                    if (!\array_key_exists($cKey, $colProps)) {
                        $colProps[$cKey] = $typedCol[$cKey];
                    }
                    unset($typedCol[$cKey]);
                }

                if (1 === $colCount && (!isset($colProps['col_span']) || '' === $colProps['col_span'] || 'auto' === $colProps['col_span'])) {
                    $colProps['col_span'] = '12';
                }

                if (isset($colProps['col_span']) && 'auto' !== $colProps['col_span'] && '' !== $colProps['col_span']) {
                    $hasExplicitColSpan = true;
                }

                $typedCol['props'] = $colProps;
                $normalizedCols[] = $typedCol;
            }

            if (!isset($rowProps['layout']) || '' === $rowProps['layout']) {
                if (1 === $colCount || $hasExplicitColSpan) {
                    $rowProps['layout'] = '12-col';
                }
            }

            $typedRow['props'] = $rowProps;
            $typedRow['columns'] = $normalizedCols;
            $normalizedRows[] = $typedRow;
        }

        return $normalizedRows;
    }

    /**
     * Converts shorthand `badge`, `link`, `link_text`, and `link_title` fields on card/panel payloads
     * into `<block name="elements">` items (`badge` and `button`).
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function normalizeElementPayload(array $payload): array
    {
        /** @var list<array<string, mixed>> $elements */
        $elements = [];
        if (isset($payload['elements']) && \is_array($payload['elements'])) {
            foreach ($payload['elements'] as $el) {
                if (\is_array($el)) {
                    /** @var array<string, mixed> $typedEl */
                    $typedEl = [];
                    foreach ($el as $ek => $ev) {
                        $typedEl[(string) $ek] = $ev;
                    }
                    $elements[] = $typedEl;
                }
            }
        }

        $hasBadgeElement = false;
        $hasButtonElement = false;
        foreach ($elements as $el) {
            $elType = isset($el['type']) && \is_string($el['type']) ? $el['type'] : 'button';
            if ('badge' === $elType) {
                $hasBadgeElement = true;
            } else {
                $hasButtonElement = true;
            }
        }

        $badgeText = isset($payload['badge']) && \is_string($payload['badge']) ? \trim($payload['badge']) : '';
        if ('' !== $badgeText && !$hasBadgeElement) {
            \array_unshift($elements, [
                'type' => 'badge',
                'title' => $badgeText,
                'badge_style' => 'soft',
                'badge_color' => 'primary',
            ]);
        }
        unset($payload['badge']);

        $linkText = isset($payload['link_text']) && \is_string($payload['link_text']) && '' !== \trim($payload['link_text'])
            ? \trim($payload['link_text'])
            : (isset($payload['link_title']) && \is_string($payload['link_title']) && '' !== \trim($payload['link_title'])
                ? \trim($payload['link_title'])
                : null);
        $rawLink = $payload['link'] ?? null;

        if ((null !== $linkText || null !== $rawLink) && !$hasButtonElement) {
            $buttonStyle = isset($payload['link_style']) && \is_string($payload['link_style']) && 'inherit' !== $payload['link_style']
                ? $payload['link_style']
                : 'solid';
            if ('primary' === $buttonStyle || 'secondary' === $buttonStyle) {
                $buttonColor = $buttonStyle;
                $buttonStyle = 'solid';
            } else {
                $buttonColor = 'primary';
            }

            $elements[] = [
                'type' => 'button',
                'title' => $linkText ?? 'Learn more',
                'link' => $rawLink ?? '#',
                'button_style' => $buttonStyle,
                'button_color' => $buttonColor,
            ];
        }
        unset($payload['link'], $payload['link_text'], $payload['link_title']);

        if ([] !== $elements) {
            $payload['elements'] = $elements;
        }

        return $payload;
    }
}
