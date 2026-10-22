<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Preset;

use Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Totoglu\SuluBlockSuiteBundle\Template\TemplateKeys;

final class YamlBlockPresetProvider implements BlockPresetProviderInterface, ResetInterface
{
    /**
     * @var array<string, list<array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}>>
     */
    private array $cache = [];

    /**
     * @var array<string, array<string, array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}>>
     */
    private array $directYamlCache = [];

    /**
     * @var array<string, list<SnippetInterface>>
     */
    private array $liveSnippetsByLocale = [];

    /**
     * @var array<string, list<string>>
     */
    private array $snippetUuidsByCacheKey = [];

    private ?MediaPreviewUrlResolver $mediaPreviewUrlResolverInstance = null;

    /**
     * @var list<string>
     */
    private array $yamlDirectories;

    /**
     * @param string|array<array-key, string> $yamlDirectory
     */
    public function __construct(
        string|array $yamlDirectory,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?SnippetRepositoryInterface $snippetRepository = null,
        private readonly bool $enableSnippetPresets = true,
        private readonly ?MediaManagerInterface $mediaManager = null,
        ?MediaPreviewUrlResolver $mediaPreviewUrlResolver = null,
        private readonly ?ReferenceStoreInterface $referenceStore = null,
    ) {
        $this->yamlDirectories = \is_array($yamlDirectory) ? \array_values($yamlDirectory) : [$yamlDirectory];
        $this->mediaPreviewUrlResolverInstance = $mediaPreviewUrlResolver;
    }

    public function reset(): void
    {
        $this->cache = [];
        $this->directYamlCache = [];
        $this->liveSnippetsByLocale = [];
        $this->snippetUuidsByCacheKey = [];
    }

    private function getMediaPreviewUrlResolver(): MediaPreviewUrlResolver
    {
        return $this->mediaPreviewUrlResolverInstance ??= new MediaPreviewUrlResolver($this->mediaManager);
    }

    /**
     * @return list<array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}>
     */
    public function getPresets(string $blockType, string $locale = 'en'): array
    {
        if (1 !== \preg_match('/^[a-zA-Z0-9_-]+$/', $blockType)) {
            return [];
        }

        $cacheKey = $blockType . '_' . $locale;
        if (isset($this->cache[$cacheKey])) {
            foreach ($this->snippetUuidsByCacheKey[$cacheKey] ?? [] as $uuid) {
                $this->referenceStore?->add($uuid, SnippetInterface::RESOURCE_KEY);
            }

            return $this->cache[$cacheKey];
        }

        $presetsMap = $this->getDirectYamlPresets($blockType, $locale);

        if ('section' === $blockType) {
            foreach ($presetsMap as $sectionPresetId => $sectionPreset) {
                $presetsMap[$sectionPresetId] = $this->hydrateSectionChildPresets($sectionPresetId, $sectionPreset, $locale);
            }
        } elseif ($this->isExtractableChildBlockType($blockType)) {
            $presetsMap = $this->appendAutoExtractedChildPresets($blockType, $locale, $presetsMap);
        }

        /** @var list<array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}> $presets */
        $presets = \array_values($presetsMap);
        $matchedUuids = [];

        if ($this->enableSnippetPresets && null !== $this->snippetRepository) {
            try {
                if (!isset($this->liveSnippetsByLocale[$locale])) {
                    /** @var iterable<SnippetInterface> $fetched */
                    $fetched = $this->snippetRepository->findBy(
                        [
                            'templateKeys' => [TemplateKeys::BLOCK_PRESET],
                            'locale' => $locale,
                            'stage' => DimensionContentInterface::STAGE_LIVE,
                        ],
                        ['title' => 'asc'],
                        [SnippetRepositoryInterface::SELECT_SNIPPET_CONTENT => []],
                    );

                    $list = [];
                    foreach ($fetched as $item) {
                        $list[] = $item;
                    }
                    $this->liveSnippetsByLocale[$locale] = $list;
                }

                foreach ($this->liveSnippetsByLocale[$locale] as $snippet) {
                    $content = $this->findLivePresetDimensionContent($snippet, $locale);
                    if (null === $content) {
                        continue;
                    }

                    $templateData = $content->getTemplateData();

                    $blockEntry = null;
                    if (isset($templateData['block']) && \is_array($templateData['block']) && isset($templateData['block'][0]) && \is_array($templateData['block'][0])) {
                        $blockEntry = $templateData['block'][0];
                    }

                    $configuredType = null;
                    if (null !== $blockEntry && isset($blockEntry['type']) && \is_string($blockEntry['type'])) {
                        $configuredType = $blockEntry['type'];
                    } elseif (isset($templateData['target_block']) && \is_string($templateData['target_block'])) {
                        $configuredType = $templateData['target_block'];
                    }

                    if ($configuredType === $blockType) {
                        $uuid = $snippet->getUuid();
                        $title = $content->getTitle() ?? $uuid;
                        if ($this->isTestPreset($uuid, $title)) {
                            continue;
                        }

                        if ('' !== $uuid) {
                            $matchedUuids[] = $uuid;
                            $this->referenceStore?->add($uuid, SnippetInterface::RESOURCE_KEY);
                        }

                        /** @var array<string, mixed> $props */
                        $props = [];
                        $rawProps = null;
                        if (null !== $blockEntry && isset($blockEntry['props']) && \is_array($blockEntry['props'])) {
                            $rawProps = $blockEntry['props'];
                        } elseif (isset($templateData['props']) && \is_array($templateData['props'])) {
                            $rawProps = $templateData['props'];
                        }
                        if (\is_array($rawProps)) {
                            foreach ($rawProps as $k => $v) {
                                $props[(string) $k] = $v;
                            }
                        }

                        /** @var array<string, mixed> $values */
                        $values = [];
                        if (null !== $blockEntry) {
                            foreach ($blockEntry as $k => $v) {
                                $values[(string) $k] = $v;
                            }
                        }

                        $description = isset($templateData['description']) && \is_string($templateData['description']) ? $templateData['description'] : null;

                        $previewImage = $this->getMediaPreviewUrlResolver()->resolve($templateData['preview_image'] ?? null, $locale);

                        $presets[] = [
                            'id' => 'snippet_' . $uuid,
                            'title' => $title,
                            'description' => $description,
                            'preview_image' => $previewImage,
                            'props' => $props,
                            'values' => $values,
                        ];
                    }
                }
            } catch (\Throwable) {
                // Gracefully fallback if snippet tables are not initialized
            }
        }

        $this->snippetUuidsByCacheKey[$cacheKey] = $matchedUuids;
        $this->cache[$cacheKey] = $presets;

        return $presets;
    }

    /**
     * @return array<string, array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}>
     */
    private function getDirectYamlPresets(string $blockType, string $locale): array
    {
        if (1 !== \preg_match('/^[a-zA-Z0-9_-]+$/', $blockType)) {
            return [];
        }

        $cacheKey = $blockType . '_' . $locale;
        if (isset($this->directYamlCache[$cacheKey])) {
            return $this->directYamlCache[$cacheKey];
        }

        /** @var array<string, array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}> $presetsMap */
        $presetsMap = [];

        foreach ($this->yamlDirectories as $dir) {
            $realBase = \realpath($dir);
            if (false === $realBase || !\is_dir($realBase)) {
                continue;
            }

            $basePath = \rtrim($realBase, '/');
            $candidateSingleFiles = [
                $basePath . '/' . $blockType . '.yaml',
                $basePath . '/' . $blockType . '.yml',
                $basePath . '/presets/' . $blockType . '.yaml',
                $basePath . '/presets/' . $blockType . '.yml',
            ];

            $filesToLoad = [];
            foreach ($candidateSingleFiles as $singleFile) {
                if (\is_file($singleFile)) {
                    $realFile = \realpath($singleFile);
                    if (false !== $realFile && \str_starts_with($realFile, $realBase . \DIRECTORY_SEPARATOR)) {
                        $filesToLoad[] = $realFile;
                        break;
                    }
                }
            }

            foreach ([$basePath . '/' . $blockType, $basePath . '/presets/' . $blockType] as $subDir) {
                $realSubDir = \realpath($subDir);
                if (false !== $realSubDir && \is_dir($realSubDir) && \str_starts_with($realSubDir, $realBase . \DIRECTORY_SEPARATOR)) {
                    $subFiles = \array_merge(
                        \glob($realSubDir . '/*.yaml') ?: [],
                        \glob($realSubDir . '/*.yml') ?: [],
                    );
                    \sort($subFiles);
                    foreach ($subFiles as $subFile) {
                        if (\is_file($subFile)) {
                            $realFile = \realpath($subFile);
                            if (false !== $realFile && \str_starts_with($realFile, $realBase . \DIRECTORY_SEPARATOR)) {
                                $filesToLoad[] = $realFile;
                            }
                        }
                    }
                }
            }

            foreach ($filesToLoad as $filePath) {
                try {
                    $parsed = Yaml::parseFile($filePath);
                    if (\is_array($parsed)) {
                        /** @var mixed $rawPresets */
                        $rawPresets = null;
                        if (isset($parsed['presets']) && \is_array($parsed['presets'])) {
                            $rawPresets = $parsed['presets'];
                        } elseif (isset($parsed[$blockType]) && \is_array($parsed[$blockType]) && isset($parsed[$blockType]['presets']) && \is_array($parsed[$blockType]['presets'])) {
                            $rawPresets = $parsed[$blockType]['presets'];
                        }

                        if (\is_array($rawPresets)) {
                            foreach ($rawPresets as $presetKey => $presetConfig) {
                                if (!\is_array($presetConfig)) {
                                    continue;
                                }

                                $presetId = \is_string($presetKey)
                                    ? $presetKey
                                    : (isset($presetConfig['id']) && \is_string($presetConfig['id']) ? $presetConfig['id'] : null);

                                if (null === $presetId || '' === $presetId) {
                                    continue;
                                }

                                $rawTitle = $presetConfig['title'] ?? ($presetConfig['label'] ?? ($presetConfig['name'] ?? null));
                                $title = $this->resolveLocalizedText($rawTitle, $locale, $presetId) ?? $presetId;

                                if ($this->isTestPreset($presetId, $title)) {
                                    continue;
                                }

                                $description = $this->resolveLocalizedText($presetConfig['description'] ?? null, $locale, null);
                                $previewImage = isset($presetConfig['preview_image']) && \is_string($presetConfig['preview_image'])
                                    ? $presetConfig['preview_image']
                                    : null;

                                /** @var array<string, mixed> $props */
                                $props = [];
                                if (isset($presetConfig['props']) && \is_array($presetConfig['props'])) {
                                    foreach ($presetConfig['props'] as $k => $v) {
                                        $props[(string) $k] = $v;
                                    }
                                }

                                /** @var array<string, mixed> $values */
                                $values = [];
                                if (isset($presetConfig['values']) && \is_array($presetConfig['values'])) {
                                    foreach ($presetConfig['values'] as $k => $v) {
                                        $values[(string) $k] = $v;
                                    }
                                }

                                [$props, $values] = $this->normalizeBlockPropsAndValues($blockType, $props, $values);

                                $presetsMap[$presetId] = [
                                    'id' => $presetId,
                                    'title' => $title,
                                    'description' => $description,
                                    'preview_image' => $previewImage,
                                    'props' => $props,
                                    'values' => $values,
                                ];
                            }
                        }
                    }
                } catch (ParseException) {
                    // Fallback gracefully on YAML parse error
                }
            }
        }

        $this->directYamlCache[$cacheKey] = $presetsMap;

        return $presetsMap;
    }

    /**
     * @param array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>} $sectionPreset
     *
     * @return array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}
     */
    private function hydrateSectionChildPresets(string $sectionPresetId, array $sectionPreset, string $locale): array
    {
        if (!isset($sectionPreset['values'])) {
            return $sectionPreset;
        }

        if (!isset($sectionPreset['values']['rows']) || !\is_array($sectionPreset['values']['rows'])) {
            return $sectionPreset;
        }

        $rows = $sectionPreset['values']['rows'];
        $typeCounts = $this->countSectionChildBlocksByType($rows);
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

                    if ($this->isExtractableChildBlockType($childType)) {
                        $typeOrdinals[$childType] = ($typeOrdinals[$childType] ?? 0) + 1;
                        $ordinal = $typeOrdinals[$childType];
                        $totalOfType = $typeCounts[$childType] ?? 1;
                        $derivedPresetId = $totalOfType > 1
                            ? $sectionPresetId . '_' . $childType . '_' . $ordinal
                            : $sectionPresetId;

                        if (null === $childPresetId) {
                            $directChildPresets = $this->getDirectYamlPresets($childType, $locale);
                            [$extractedProps, $extractedValues] = $this->extractChildBlockParts($childBlock, $childProps);

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

                        $directChildPresets = $this->getDirectYamlPresets($childType, $locale);
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

                    [$normalizedChildProps, $normalizedChildValues] = $this->extractChildBlockParts($childBlock, $childProps);
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
     * @param array<string, array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}> $presetsMap
     *
     * @return array<string, array{id: string, title: string, description: ?string, preview_image: ?string, props: array<string, mixed>, values?: array<string, mixed>}>
     */
    private function appendAutoExtractedChildPresets(string $blockType, string $locale, array $presetsMap): array
    {
        $sectionPresets = $this->getDirectYamlPresets('section', $locale);

        foreach ($sectionPresets as $sectionPresetId => $sectionPreset) {
            if (!isset($sectionPreset['values'])) {
                continue;
            }

            if (!isset($sectionPreset['values']['rows']) || !\is_array($sectionPreset['values']['rows'])) {
                continue;
            }

            $rows = $sectionPreset['values']['rows'];
            $typeCounts = $this->countSectionChildBlocksByType($rows);
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

                        [$extractedProps, $extractedValues] = $this->extractChildBlockParts($childBlock, $childProps);
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
    private function countSectionChildBlocksByType(array $rows): array
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
    private function extractChildBlockParts(array $childBlock, array $childProps): array
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
            [$extractedProps, $extractedValues] = $this->normalizeBlockPropsAndValues($childType, $extractedProps, $extractedValues);
        }

        return [$extractedProps, $extractedValues];
    }

    private function isExtractableChildBlockType(string $blockType): bool
    {
        return '' !== $blockType && 'section' !== $blockType && 'reusable' !== $blockType;
    }

    /**
     * Normalizes block-level `props` and `values` so shorthand preset fields align with Sulu XML form schemas.
     *
     * @param array<string, mixed> $props
     * @param array<string, mixed> $values
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function normalizeBlockPropsAndValues(string $blockType, array $props, array $values): array
    {
        if ('section' === $blockType && isset($values['rows']) && \is_array($values['rows'])) {
            $values['rows'] = $this->normalizeSectionRows($values['rows']);

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
                        $typedItem = $this->normalizeElementPayload($typedItem);
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
            $values = $this->normalizeElementPayload($values);
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
     * Normalizes shared layout row and column settings into `props` so Sulu's Symfony Form (`shared="true"`) persists them.
     *
     * @param array<array-key, mixed> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeSectionRows(array $rows): array
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
    private function normalizeElementPayload(array $payload): array
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

    private function findLivePresetDimensionContent(
        SnippetInterface $snippet,
        string $locale,
    ): ?SnippetDimensionContentInterface {
        /** @var iterable<SnippetDimensionContentInterface> $dimensionContents */
        $dimensionContents = $snippet->getDimensionContents();

        foreach ($dimensionContents as $content) {
            if (
                TemplateKeys::BLOCK_PRESET !== $content->getTemplateKey()
                || DimensionContentInterface::CURRENT_VERSION !== $content->getVersion()
                || $content->getLocale() !== $locale
            ) {
                continue;
            }
            $stage = $content->getStage();
            if (DimensionContentInterface::STAGE_LIVE === $stage || '' === $stage) {
                return $content;
            }
        }

        foreach ($dimensionContents as $content) {
            if (
                TemplateKeys::BLOCK_PRESET !== $content->getTemplateKey()
                || DimensionContentInterface::CURRENT_VERSION !== $content->getVersion()
            ) {
                continue;
            }
            $contentLocale = $content->getLocale();
            if (null !== $contentLocale && '' !== $contentLocale) {
                continue;
            }
            $stage = $content->getStage();
            if (DimensionContentInterface::STAGE_LIVE === $stage || '' === $stage) {
                return $content;
            }
        }

        return null;
    }

    private function resolveLocalizedText(mixed $data, string $locale, ?string $fallback): ?string
    {
        if (null === $data) {
            return $fallback;
        }

        if (\is_string($data)) {
            if (null !== $this->translator) {
                $translated = $this->translator->trans($data, [], 'admin', $locale);
                if ($translated !== $data) {
                    return $translated;
                }

                $translatedDefault = $this->translator->trans($data, [], null, $locale);
                if ($translatedDefault !== $data) {
                    return $translatedDefault;
                }
            }

            return $data;
        }

        if (\is_array($data)) {
            if (isset($data[$locale]) && \is_string($data[$locale])) {
                return $data[$locale];
            }

            $shortLocale = \explode('_', $locale)[0];
            if (isset($data[$shortLocale]) && \is_string($data[$shortLocale])) {
                return $data[$shortLocale];
            }

            if (isset($data['en']) && \is_string($data['en'])) {
                return $data['en'];
            }

            $first = \reset($data);
            if (\is_string($first)) {
                return $first;
            }
        }

        return $fallback;
    }

    private function isTestPreset(string $id, string $title): bool
    {
        return 1 === \preg_match('/^test(\b|_|-|\s|$)/i', \trim($id))
            || 1 === \preg_match('/^test(\b|_|-|\s|$)/i', \trim($title));
    }

    public function clearCache(?string $blockType = null, ?string $locale = null): void
    {
        if (null !== $blockType && null !== $locale) {
            $cacheKey = $blockType . '_' . $locale;
            unset(
                $this->cache[$cacheKey],
                $this->directYamlCache[$cacheKey],
                $this->snippetUuidsByCacheKey[$cacheKey],
                $this->liveSnippetsByLocale[$locale],
            );
        } else {
            $this->reset();
        }
    }
}
