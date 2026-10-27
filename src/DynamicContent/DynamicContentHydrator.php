<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent;

use Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface;
use Sulu\Content\Application\ContentAggregator\ContentAggregatorInterface;
use Sulu\Content\Application\ContentResolver\ContentResolverInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\Bridge\SuluSmartContentQueryBridge;
use Totoglu\SuluBlockSuiteBundle\Metadata\BlockTargetFieldMetadataResolver;
use Totoglu\SuluBlockSuiteBundle\Normalizer\SuluDataNormalizer;

final class DynamicContentHydrator implements DynamicContentHydratorInterface
{
    private readonly DynamicContentSourceRegistry $registry;

    private readonly DynamicContentMappingApplier $mappingApplier;

    /**
     * @param iterable<DynamicContentSourceProviderInterface> $customProviders
     */
    public function __construct(
        ?PageSourceResolverInterface $sourceResolver = null,
        iterable $customProviders = [],
        private readonly ?BlockTargetFieldMetadataResolver $targetFieldResolver = null,
        ?SuluSmartContentQueryBridge $smartContentBridge = null,
        ?ContentResolverInterface $contentResolver = null,
        ?ContentAggregatorInterface $contentAggregator = null,
        private readonly ?RequestStack $requestStack = null,
        ?ReferenceStoreInterface $referenceStore = null,
        ?TranslatorInterface $translator = null,
        ?DynamicContentMappingApplier $mappingApplier = null,
    ) {
        $this->registry = new DynamicContentSourceRegistry(
            $customProviders,
            $sourceResolver,
            $smartContentBridge,
            $contentResolver,
            $contentAggregator,
            $requestStack,
            $referenceStore,
            $translator,
        );
        $this->mappingApplier = $mappingApplier ?? new DynamicContentMappingApplier($this->targetFieldResolver, $this->registry);
    }

    /**
     * @return array<string, DynamicContentSourceProviderInterface>
     */
    public function getCustomProviders(): array
    {
        return $this->registry->getProviders();
    }

    public function getRegistry(): DynamicContentSourceRegistry
    {
        return $this->registry;
    }

    public function hydrateBlock(array $block, string|object|array|null $contextPage = null): array
    {
        $dynamicConfig = $this->extractDynamicConfig($block);
        if (null === $dynamicConfig || empty($dynamicConfig['enabled'])) {
            return [$block];
        }

        $sourceType = isset($dynamicConfig['source_type']) && \is_string($dynamicConfig['source_type'])
            ? $dynamicConfig['source_type']
            : 'none';

        if ('none' === $sourceType || '' === $sourceType) {
            return [$block];
        }

        /** @var array<string, string> $mapping */
        $mapping = isset($dynamicConfig['mapping']) && \is_array($dynamicConfig['mapping'])
            ? $dynamicConfig['mapping']
            : [];
        /** @var array<string, mixed>|null $condition */
        $condition = isset($dynamicConfig['condition']) && \is_array($dynamicConfig['condition'])
            ? $dynamicConfig['condition']
            : null;

        $hideIfEmpty = $this->isTruthy($dynamicConfig['hide_if_empty'] ?? false);

        $entry = $this->registry->getEntry($sourceType);
        if (null === $entry) {
            return $hideIfEmpty ? [] : [$block];
        }

        $provider = $entry['provider'];
        if ($entry['multi_item']) {
            $providerConfig = $dynamicConfig;
            $rawPagination = $dynamicConfig['pagination'] ?? ($dynamicConfig['dynamic_pagination'] ?? 'none');
            $paginationStyle = \is_string($rawPagination) ? \trim($rawPagination) : 'none';
            $configuredLimit = isset($providerConfig['limit']) && \is_numeric($providerConfig['limit'])
                ? (int) $providerConfig['limit']
                : 0;
            if (\in_array($paginationStyle, ['numbers', 'prev_next'], true) && $configuredLimit <= 0) {
                $providerConfig['limit'] = 500;
            }

            $items = $provider->resolveCollection($providerConfig, $contextPage);
            if ($provider instanceof AbstractDynamicContentSourceProvider) {
                $items = $provider->applyCustomOutputsToCollection($items);
            }
            $collectionMeta = $provider instanceof ConfigurableDynamicContentSourceProviderInterface
                ? $provider->resolveCollectionMeta($providerConfig, $contextPage)
                : [];

            return $this->hydrateCollection($block, $items, $mapping, $condition, $dynamicConfig, $hideIfEmpty, $collectionMeta);
        }

        $itemData = $provider->resolveSingle($dynamicConfig, $contextPage);
        if (null === $itemData) {
            return $hideIfEmpty ? [] : [$block];
        }
        if ($provider instanceof AbstractDynamicContentSourceProvider) {
            $itemData = $provider->applyCustomOutputs($itemData);
        }

        if (null !== $condition && !$this->mappingApplier->evaluateCondition($itemData, $condition)) {
            return [];
        }

        $appliedCount = 0;
        $hydrated = $this->mappingApplier->applyMapping($block, $itemData, $mapping, $appliedCount);
        if ($hideIfEmpty && 0 === $appliedCount) {
            return [];
        }

        return [$hydrated];
    }

    public function hydrateBlocks(iterable $blocks, string|object|array|null $contextPage = null): array
    {
        $hydrated = [];
        foreach ($blocks as $block) {
            $results = $this->hydrateBlock($block, $contextPage);
            foreach ($results as $item) {
                $hydrated[] = $item;
            }
        }

        return $hydrated;
    }

    /**
     * @param array<string, mixed> $block
     * @param list<array<string, mixed>> $items
     * @param array<string, string> $mapping
     * @param array<string, mixed>|null $condition
     * @param array<string, mixed> $dynamicConfig
     * @param bool $hideIfEmpty drop items (and the whole block) when no mapped field resolves to a value
     * @param array{unfiltered_total?: int|null, filtered_total?: int|null, is_filtered?: bool} $collectionMeta
     *
     * @return list<array<string, mixed>>
     */
    private function hydrateCollection(
        array $block,
        array $items,
        array $mapping,
        ?array $condition,
        array $dynamicConfig = [],
        bool $hideIfEmpty = false,
        array $collectionMeta = [],
    ): array {
        $hydrateMode = isset($dynamicConfig['hydrate_mode']) && \is_string($dynamicConfig['hydrate_mode'])
            ? $dynamicConfig['hydrate_mode']
            : 'auto';
        $blockType = isset($block['type']) && \is_string($block['type']) ? $block['type'] : '';

        $blockMetadata = (null !== $this->targetFieldResolver && '' !== $blockType)
            ? $this->targetFieldResolver->resolveBlockMetadata($blockType)
            : null;
        $isMultipleBlock = (null !== $blockMetadata && $blockMetadata['multiple'])
            || (\array_key_exists('items', $block) && \is_array($block['items']));
        $defaultItemType = null !== $blockMetadata
            ? $blockMetadata['default_item_type']
            : ('' !== $blockType ? $blockType . '_item' : null);

        $shouldHydrateInnerItems = 'items' === $hydrateMode
            || ('repeater' !== $hydrateMode && $isMultipleBlock);

        /** @var list<array<string, mixed>> $rawSubItems */
        $rawSubItems = [];
        $hasExplicitTemplate = false;
        if ($shouldHydrateInnerItems && isset($block['items']) && \is_array($block['items'])) {
            foreach ($block['items'] as $subItem) {
                if (\is_array($subItem)) {
                    /** @var array<string, mixed> $subItem */
                    $rawSubItems[] = $subItem;
                    if ($this->isTemplateSubItem($subItem)) {
                        $hasExplicitTemplate = true;
                    }
                }
            }
        }

        $filteredItems = [];
        foreach ($items as $itemData) {
            if (null !== $condition && !$this->mappingApplier->evaluateCondition($itemData, $condition)) {
                continue;
            }
            $filteredItems[] = $itemData;
        }

        $totalFilteredCount = isset($collectionMeta['filtered_total'])
            ? \max(0, $collectionMeta['filtered_total'])
            : \count($filteredItems);
        $unfilteredTotal = isset($collectionMeta['unfiltered_total'])
            ? \max(0, $collectionMeta['unfiltered_total'])
            : $totalFilteredCount;
        $isFiltered = isset($collectionMeta['is_filtered'])
            ? (bool) $collectionMeta['is_filtered']
            : ($unfilteredTotal !== $totalFilteredCount);

        $rawShowSummary = $dynamicConfig['show_summary'] ?? ($dynamicConfig['dynamic_show_summary'] ?? 'none');
        $showSummary = \is_string($rawShowSummary) && \in_array(\trim($rawShowSummary), ['none', 'top', 'bottom', 'both'], true)
            ? \trim($rawShowSummary)
            : 'none';
        $rawEmptyMessage = $dynamicConfig['empty_message'] ?? ($dynamicConfig['dynamic_empty_message'] ?? '');
        $emptyMessage = \is_string($rawEmptyMessage) ? \trim($rawEmptyMessage) : '';
        $hasEmptyStateSupport = !$hideIfEmpty && ('' !== $emptyMessage || $isFiltered || 'none' !== $showSummary);

        if ([] === $filteredItems && !$hasEmptyStateSupport && (!$shouldHydrateInnerItems || !$hasExplicitTemplate || $hideIfEmpty)) {
            return [];
        }

        $totalLimit = isset($dynamicConfig['limit']) && \is_numeric($dynamicConfig['limit'])
            ? (int) $dynamicConfig['limit']
            : 0;
        if ($totalLimit > 0 && \count($filteredItems) > $totalLimit) {
            $filteredItems = \array_slice($filteredItems, 0, $totalLimit);
        }

        $rawPagination = $dynamicConfig['pagination'] ?? ($dynamicConfig['dynamic_pagination'] ?? 'none');
        $paginationStyle = \is_string($rawPagination) ? \trim($rawPagination) : 'none';
        $paginationMeta = null;
        $currentPage = 1;
        $perPage = \max(1, \count($filteredItems));
        $totalPages = 1;

        if ([] !== $filteredItems && \in_array($paginationStyle, ['numbers', 'prev_next'], true)) {
            $rawPerPage = $dynamicConfig['per_page'] ?? ($dynamicConfig['dynamic_per_page'] ?? 20);
            $perPage = \is_numeric($rawPerPage) ? \max(1, (int) $rawPerPage) : 20;
            $totalItems = \count($filteredItems);
            $totalPages = \max(1, (int) \ceil($totalItems / $perPage));

            $rawFallbackPage = $dynamicConfig['current_page'] ?? 1;
            $fallbackPage = \is_numeric($rawFallbackPage) ? (int) $rawFallbackPage : 1;
            $rawPage = $this->requestStack?->getCurrentRequest()?->query->get('p', $fallbackPage) ?? $fallbackPage;
            $currentPage = \is_numeric($rawPage) ? \max(1, \min((int) $rawPage, $totalPages)) : 1;

            $filteredItems = \array_slice($filteredItems, ($currentPage - 1) * $perPage, $perPage);

            $paginationMeta = [
                'style' => $paginationStyle,
                'current_page' => $currentPage,
                'per_page' => $perPage,
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
                'param' => 'p',
            ];
        }

        if ($shouldHydrateInnerItems) {
            /** @var array<string, string> $itemMapping */
            $itemMapping = [];
            foreach ($mapping as $targetKey => $sourcePath) {
                $normalizedTarget = \str_starts_with($targetKey, 'items.')
                    ? \substr($targetKey, 6)
                    : $targetKey;
                if ('' !== $normalizedTarget) {
                    $itemMapping[$normalizedTarget] = $sourcePath;
                }
            }

            $hydratedInnerItems = [];
            $dynamicHydratedCount = 0;

            if ($hasExplicitTemplate) {
                foreach ($rawSubItems as $subItem) {
                    if (!$this->isTemplateSubItem($subItem)) {
                        if (!isset($subItem['type']) && null !== $defaultItemType && '' !== $defaultItemType) {
                            $subItem['type'] = $defaultItemType;
                        }
                        $hydratedInnerItems[] = $subItem;

                        continue;
                    }

                    $templateItem = $this->stripTemplateItemFlags($subItem);
                    if (!isset($templateItem['type']) && null !== $defaultItemType && '' !== $defaultItemType) {
                        $templateItem['type'] = $defaultItemType;
                    }

                    foreach ($filteredItems as $itemData) {
                        $appliedCount = 0;
                        $hydratedItem = $this->mappingApplier->applyMapping($templateItem, $itemData, $itemMapping, $appliedCount);
                        if ($hideIfEmpty && 0 === $appliedCount) {
                            continue;
                        }
                        $hydratedInnerItems[] = $hydratedItem;
                        ++$dynamicHydratedCount;
                    }
                }

                if ($hideIfEmpty && 0 === $dynamicHydratedCount) {
                    return [];
                }
            } else {
                /** @var array<string, mixed> $templateItem */
                $templateItem = $rawSubItems[0] ?? [];
                if (!isset($templateItem['type']) && null !== $defaultItemType && '' !== $defaultItemType) {
                    $templateItem['type'] = $defaultItemType;
                }

                foreach ($filteredItems as $itemData) {
                    $appliedCount = 0;
                    $hydratedItem = $this->mappingApplier->applyMapping($templateItem, $itemData, $itemMapping, $appliedCount);
                    if ($hideIfEmpty && 0 === $appliedCount) {
                        continue;
                    }
                    $hydratedInnerItems[] = $hydratedItem;
                    ++$dynamicHydratedCount;
                }
            }

            if ([] === $hydratedInnerItems && !$hasEmptyStateSupport) {
                return [];
            }

            $hydratedBlock = $this->enrichBlockWithSummaryAndEmptyState(
                $block,
                $dynamicConfig,
                $unfilteredTotal,
                $totalFilteredCount,
                $dynamicHydratedCount,
                $currentPage,
                $perPage,
                $totalPages,
                $isFiltered,
                $showSummary,
                $emptyMessage,
            );
            $hydratedBlock['items'] = $hydratedInnerItems;
            if (null !== $paginationMeta && $dynamicHydratedCount > 0) {
                $hydratedBlock['_pagination'] = $paginationMeta;
            }

            return [$hydratedBlock];
        }

        $hydratedList = [];
        foreach ($filteredItems as $itemData) {
            $appliedCount = 0;
            $hydratedItem = $this->mappingApplier->applyMapping($block, $itemData, $mapping, $appliedCount);
            if ($hideIfEmpty && 0 === $appliedCount) {
                continue;
            }

            $cloneIndex = \count($hydratedList);
            if ($cloneIndex > 0) {
                $suffix = '-' . ($cloneIndex + 1);
                if (isset($hydratedItem['props']) && \is_array($hydratedItem['props']) && isset($hydratedItem['props']['id']) && \is_string($hydratedItem['props']['id'])) {
                    $trimmedId = \trim($hydratedItem['props']['id']);
                    if ('' !== $trimmedId) {
                        $hydratedItem['props']['id'] = $trimmedId . $suffix;
                    }
                }
                if (isset($hydratedItem['id']) && \is_string($hydratedItem['id'])) {
                    $trimmedRootId = \trim($hydratedItem['id']);
                    if ('' !== $trimmedRootId) {
                        $hydratedItem['id'] = $trimmedRootId . $suffix;
                    }
                }
            }

            $hydratedList[] = $hydratedItem;
        }

        if (null !== $paginationMeta && [] !== $hydratedList) {
            $lastIdx = \count($hydratedList) - 1;
            $hydratedList[$lastIdx]['_pagination'] = $paginationMeta;
        }

        return $hydratedList;
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>|null
     */
    private function extractDynamicConfig(array $block): ?array
    {
        $props = $block['props'] ?? null;
        if (\is_object($props)) {
            if (\method_exists($props, 'getContent')) {
                /** @var mixed $props */
                $props = $props->getContent();
            } elseif (\method_exists($props, 'toArray')) {
                /** @var mixed $props */
                $props = $props->toArray();
            } elseif ($props instanceof \stdClass) {
                $props = (array) $props;
            } elseif ($props instanceof \Traversable) {
                $props = \iterator_to_array($props);
            } else {
                $props = [];
            }
        } elseif (\is_string($props) && '' !== \trim($props)) {
            $decoded = \json_decode($props, true);
            if (\is_array($decoded)) {
                $props = $decoded;
            }
        }

        if (\is_array($props)) {
            if (isset($props['_dynamic'])) {
                $dyn = $props['_dynamic'];
                if (\is_object($dyn) && \method_exists($dyn, 'getContent')) {
                    /** @var mixed $dyn */
                    $dyn = $dyn->getContent();
                }
                if (\is_array($dyn)) {
                    /** @var array<string, mixed> $dyn */
                    return $dyn;
                }
            }

            $sourceType = $props['dynamic_source'] ?? null;
            if (\is_string($sourceType) && '' !== \trim($sourceType) && 'none' !== \trim($sourceType)) {
                /** @var array<string, mixed> $props */
                return $this->buildDynamicConfigFromFlatProps($props);
            }
        }

        if (isset($block['_dynamic'])) {
            $dyn = $block['_dynamic'];
            if (\is_object($dyn) && \method_exists($dyn, 'getContent')) {
                /** @var mixed $dyn */
                $dyn = $dyn->getContent();
            }
            if (\is_array($dyn)) {
                /** @var array<string, mixed> $dyn */
                return $dyn;
            }
        }

        $rootSourceType = $block['dynamic_source'] ?? null;
        if (\is_string($rootSourceType) && '' !== \trim($rootSourceType) && 'none' !== \trim($rootSourceType)) {
            return $this->buildDynamicConfigFromFlatProps($block);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $props
     *
     * @return array<string, mixed>
     */
    private function buildDynamicConfigFromFlatProps(array $props): array
    {
        $resolvedTagIds = null;
        $rawTagSelection = $props['dynamic_tag_names'] ?? null;
        if (\is_object($rawTagSelection) && \method_exists($rawTagSelection, 'getView')) {
            /** @var mixed $view */
            $view = $rawTagSelection->getView();
            if (\is_array($view) && isset($view['ids']) && \is_array($view['ids'])) {
                $ids = [];
                foreach ($view['ids'] as $id) {
                    if (\is_numeric($id)) {
                        $ids[] = (int) $id;
                    }
                }
                if ([] !== $ids) {
                    $resolvedTagIds = $ids;
                }
            }
        }

        $props = SuluDataNormalizer::unwrapContentViews($props);
        if (!\is_array($props)) {
            $props = [];
        }

        $sourceType = isset($props['dynamic_source']) && \is_string($props['dynamic_source'])
            ? \trim($props['dynamic_source'])
            : 'none';

        /** @var array<string, string> $mapping */
        $mapping = [];
        $rawMapping = $props['dynamic_mapping'] ?? null;
        if (\is_string($rawMapping) && '' !== \trim($rawMapping)) {
            foreach (\explode("\n", $rawMapping) as $line) {
                $line = \trim($line);
                if ('' === $line || !\str_contains($line, '=')) {
                    continue;
                }
                [$targetField, $sourcePath] = \explode('=', $line, 2);
                $targetField = \trim($targetField);
                $sourcePath = \trim($sourcePath);
                if ('' !== $targetField && '' !== $sourcePath) {
                    $mapping[$targetField] = $sourcePath;
                }
            }
        } elseif (\is_array($rawMapping)) {
            foreach ($rawMapping as $key => $entry) {
                if (\is_array($entry)) {
                    $targetField = isset($entry['target_field']) && \is_string($entry['target_field']) ? \trim($entry['target_field']) : '';
                    $sourceField = isset($entry['source_field']) && \is_string($entry['source_field']) ? \trim($entry['source_field']) : '';
                    if ('' !== $targetField && '' !== $sourceField) {
                        $mapping[$targetField] = $sourceField;
                    }
                } elseif (\is_string($key) && \is_string($entry) && '' !== \trim($key) && '' !== \trim($entry)) {
                    $mapping[\trim($key)] = \trim($entry);
                }
            }
        }

        $conditionEnabled = $this->isTruthy($props['dynamic_condition_enabled'] ?? null);
        $conditionField = isset($props['dynamic_condition_field']) && \is_string($props['dynamic_condition_field'])
            ? \trim($props['dynamic_condition_field'])
            : '';
        $condition = null;
        if ($conditionEnabled && '' !== $conditionField) {
            $condition = [
                'field' => $conditionField,
                'operator' => isset($props['dynamic_condition_operator']) && \is_string($props['dynamic_condition_operator'])
                    ? \trim($props['dynamic_condition_operator'])
                    : 'not_empty',
                'value' => isset($props['dynamic_condition_value']) && \is_scalar($props['dynamic_condition_value'])
                    ? (string) $props['dynamic_condition_value']
                    : '',
            ];
        }

        $rawPagination = isset($props['dynamic_pagination']) && \is_string($props['dynamic_pagination'])
            ? \trim($props['dynamic_pagination'])
            : 'none';
        $pagination = \in_array($rawPagination, ['none', 'numbers', 'prev_next'], true)
            ? $rawPagination
            : 'none';
        $perPage = isset($props['dynamic_per_page']) && \is_numeric($props['dynamic_per_page']) && (int) $props['dynamic_per_page'] > 0
            ? (int) $props['dynamic_per_page']
            : 20;
        $limit = isset($props['dynamic_limit']) && \is_numeric($props['dynamic_limit'])
            ? (int) $props['dynamic_limit']
            : ('none' !== $pagination ? 0 : 6);

        return [
            'enabled' => 'none' !== $sourceType && '' !== $sourceType,
            'source_type' => $sourceType,
            'hydrate_mode' => isset($props['dynamic_hydrate_mode']) && \is_string($props['dynamic_hydrate_mode'])
                ? \trim($props['dynamic_hydrate_mode'])
                : 'auto',
            'hide_if_empty' => $this->isTruthy($props['dynamic_hide_if_empty'] ?? null),
            'page_id' => $props['dynamic_page_id'] ?? null,
            'parent_id' => 'sulu_categories' === $sourceType
                ? ($props['dynamic_parent_id'] ?? null)
                : ($props['dynamic_page_id'] ?? ($props['dynamic_parent_id'] ?? null)),
            'uuids' => 'sulu_articles' === $sourceType
                ? ($props['dynamic_article_ids'] ?? ($props['dynamic_uuids'] ?? null))
                : ($props['dynamic_uuids'] ?? null),
            'article_id' => $props['dynamic_article_id'] ?? null,
            'category_id' => $props['dynamic_category_id'] ?? null,
            'category_ids' => $props['dynamic_category_ids'] ?? null,
            'media_id' => $props['dynamic_media_id'] ?? null,
            'media_ids' => $props['dynamic_media_ids'] ?? null,
            'collection_id' => $props['dynamic_collection_id'] ?? null,
            'template_keys' => $props['dynamic_template_keys'] ?? null,
            'tag_names' => $props['dynamic_tag_names'] ?? null,
            'tag_ids' => $resolvedTagIds ?? ($props['dynamic_tag_ids'] ?? null),
            'category_keys' => $props['dynamic_category_keys'] ?? null,
            'exclude_current' => (bool) ($props['dynamic_exclude_current'] ?? true),
            'sort_by' => $props['dynamic_sort_by'] ?? 'published',
            'sort_order' => $props['dynamic_sort_order'] ?? 'desc',
            'limit' => $limit,
            'offset' => isset($props['dynamic_offset']) && \is_numeric($props['dynamic_offset']) ? (int) $props['dynamic_offset'] : 0,
            'pagination' => $pagination,
            'per_page' => $perPage,
            'show_summary' => isset($props['dynamic_show_summary']) && \is_string($props['dynamic_show_summary'])
                ? \trim($props['dynamic_show_summary'])
                : 'none',
            'summary_text' => isset($props['dynamic_summary_text']) && \is_string($props['dynamic_summary_text'])
                ? \trim($props['dynamic_summary_text'])
                : '',
            'filtered_summary_text' => isset($props['dynamic_filtered_summary_text']) && \is_string($props['dynamic_filtered_summary_text'])
                ? \trim($props['dynamic_filtered_summary_text'])
                : '',
            'empty_message' => isset($props['dynamic_empty_message']) && \is_string($props['dynamic_empty_message'])
                ? \trim($props['dynamic_empty_message'])
                : '',
            'mapping' => $mapping,
            'condition' => $condition,
        ];
    }

    /**
     * Enriches a hydrated collection block with `_summary`, `_empty_state`, and `%total%`/`%count%`/`%showing%`
     * token interpolation in parent block header fields (`title`, `headline`, `subtitle`, `description`, `summary`).
     *
     * @param array<string, mixed> $block
     * @param array<string, mixed> $dynamicConfig
     *
     * @return array<string, mixed>
     */
    private function enrichBlockWithSummaryAndEmptyState(
        array $block,
        array $dynamicConfig,
        int $unfilteredTotal,
        int $totalFilteredCount,
        int $showingCount,
        int $currentPage,
        int $perPage,
        int $totalPages,
        bool $isFiltered,
        string $showSummary,
        string $emptyMessage,
    ): array {
        $fromItem = $showingCount > 0 ? (($currentPage - 1) * $perPage) + 1 : 0;
        $toItem = $showingCount > 0 ? $fromItem + $showingCount - 1 : 0;

        $summaryTokens = [
            '%total%' => (string) $unfilteredTotal,
            '%count%' => (string) $totalFilteredCount,
            '%showing%' => (string) $showingCount,
            '%from%' => (string) $fromItem,
            '%to%' => (string) $toItem,
            '%page%' => (string) $currentPage,
            '%pages%' => (string) $totalPages,
        ];

        $enriched = $block;
        foreach (['title', 'headline', 'subtitle', 'description', 'summary'] as $headerField) {
            if (isset($enriched[$headerField]) && \is_string($enriched[$headerField]) && \str_contains($enriched[$headerField], '%')) {
                $enriched[$headerField] = \strtr($enriched[$headerField], $summaryTokens);
            }
        }

        if ('none' !== $showSummary) {
            $rawSummaryText = $dynamicConfig['summary_text'] ?? ($dynamicConfig['dynamic_summary_text'] ?? '');
            $summaryText = \is_string($rawSummaryText) ? \trim($rawSummaryText) : '';
            $rawFilteredText = $dynamicConfig['filtered_summary_text'] ?? ($dynamicConfig['dynamic_filtered_summary_text'] ?? '');
            $filteredSummaryText = \is_string($rawFilteredText) ? \trim($rawFilteredText) : '';

            $chosenTemplate = ($isFiltered && '' !== $filteredSummaryText)
                ? $filteredSummaryText
                : ('' !== $summaryText
                    ? $summaryText
                    : ($isFiltered
                        ? 'Found %count% results (showing %showing% of %total%)'
                        : 'Showing %showing% of %total% items'));

            $enriched['_summary'] = [
                'unfiltered_total' => $unfilteredTotal,
                'total_items' => $totalFilteredCount,
                'showing_count' => $showingCount,
                'from_item' => $fromItem,
                'to_item' => $toItem,
                'current_page' => $currentPage,
                'total_pages' => $totalPages,
                'is_filtered' => $isFiltered,
                'formatted_summary' => \strtr($chosenTemplate, $summaryTokens),
                'position' => $showSummary,
            ];
        }

        if (0 === $showingCount && '' !== $emptyMessage) {
            $enriched['_empty_state'] = [
                'message' => \strtr($emptyMessage, $summaryTokens),
                'is_filtered' => $isFiltered,
            ];
        }

        return $enriched;
    }

    private function isTruthy(mixed $value): bool
    {
        return true === $value || 1 === $value || 'true' === $value || '1' === $value;
    }

    /**
     * @param array<string, mixed> $subItem
     */
    private function isTemplateSubItem(array $subItem): bool
    {
        if ($this->isTruthy($subItem['is_dynamic_template'] ?? false)) {
            return true;
        }

        $props = $subItem['props'] ?? null;
        if (\is_array($props)) {
            return $this->isTruthy($props['is_dynamic_template'] ?? false);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $subItem
     *
     * @return array<string, mixed>
     */
    private function stripTemplateItemFlags(array $subItem): array
    {
        $cleaned = $subItem;
        if (\array_key_exists('is_dynamic_template', $cleaned)) {
            $cleaned['is_dynamic_template'] = false;
        }
        if (isset($cleaned['props']) && \is_array($cleaned['props'])) {
            if (\array_key_exists('is_dynamic_template', $cleaned['props'])) {
                $cleaned['props']['is_dynamic_template'] = false;
            }
        }

        return $cleaned;
    }
}
