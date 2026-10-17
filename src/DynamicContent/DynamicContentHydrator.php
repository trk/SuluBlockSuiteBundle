<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent;

use Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface;
use Sulu\Content\Application\ContentAggregator\ContentAggregatorInterface;
use Sulu\Content\Application\ContentResolver\ContentResolverInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\Bridge\SuluSmartContentQueryBridge;
use Totoglu\SuluBlockSuiteBundle\Metadata\BlockTargetFieldMetadataResolver;

final class DynamicContentHydrator implements DynamicContentHydratorInterface
{
    private readonly DynamicContentSourceRegistry $registry;

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
        ?RequestStack $requestStack = null,
        ?ReferenceStoreInterface $referenceStore = null,
    ) {
        $this->registry = new DynamicContentSourceRegistry(
            $customProviders,
            $sourceResolver,
            $smartContentBridge,
            $contentResolver,
            $contentAggregator,
            $requestStack,
            $referenceStore,
        );
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
            $items = $provider->resolveCollection($dynamicConfig, $contextPage);

            return $this->hydrateCollection($block, $items, $mapping, $condition, $dynamicConfig, $hideIfEmpty);
        }

        $itemData = $provider->resolveSingle($dynamicConfig, $contextPage);
        if (null === $itemData) {
            return $hideIfEmpty ? [] : [$block];
        }

        if (null !== $condition && !$this->evaluateCondition($itemData, $condition)) {
            return [];
        }

        $appliedCount = 0;
        $hydrated = $this->applyMapping($block, $itemData, $mapping, $appliedCount);
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
    ): array {
        if ([] === $items) {
            return [];
        }

        $filteredItems = [];
        foreach ($items as $itemData) {
            if (null !== $condition && !$this->evaluateCondition($itemData, $condition)) {
                continue;
            }
            $filteredItems[] = $itemData;
        }

        if ([] === $filteredItems) {
            return [];
        }

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

        if ($shouldHydrateInnerItems) {
            /** @var array<string, mixed> $templateItem */
            $templateItem = (isset($block['items']) && \is_array($block['items']) && isset($block['items'][0]) && \is_array($block['items'][0]))
                ? $block['items'][0]
                : [];

            if (!isset($templateItem['type']) && null !== $defaultItemType && '' !== $defaultItemType) {
                $templateItem['type'] = $defaultItemType;
            }

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
            foreach ($filteredItems as $itemData) {
                $appliedCount = 0;
                $hydratedItem = $this->applyMapping($templateItem, $itemData, $itemMapping, $appliedCount);
                if ($hideIfEmpty && 0 === $appliedCount) {
                    continue;
                }
                $hydratedInnerItems[] = $hydratedItem;
            }

            if ([] === $hydratedInnerItems) {
                return [];
            }

            $hydratedBlock = $block;
            $hydratedBlock['items'] = $hydratedInnerItems;

            return [$hydratedBlock];
        }

        $hydratedList = [];
        foreach ($filteredItems as $itemData) {
            $appliedCount = 0;
            $hydratedItem = $this->applyMapping($block, $itemData, $mapping, $appliedCount);
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
            } else {
                $props = (array) $props;
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

        $props = $this->unwrapContentViews($props);
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

        return [
            'enabled' => 'none' !== $sourceType && '' !== $sourceType,
            'source_type' => $sourceType,
            'hydrate_mode' => isset($props['dynamic_hydrate_mode']) && \is_string($props['dynamic_hydrate_mode'])
                ? \trim($props['dynamic_hydrate_mode'])
                : 'auto',
            'hide_if_empty' => $this->isTruthy($props['dynamic_hide_if_empty'] ?? null),
            'page_id' => $props['dynamic_page_id'] ?? ($props['dynamic_page'] ?? null),
            'parent_id' => 'sulu_categories' === $sourceType
                ? ($props['dynamic_parent_id'] ?? null)
                : ($props['dynamic_parent_page'] ?? ($props['dynamic_page_id'] ?? ($props['dynamic_parent_id'] ?? null))),
            'uuids' => $props['dynamic_uuids'] ?? null,
            'article_id' => $props['dynamic_article_id'] ?? null,
            'article_ids' => $props['dynamic_article_ids'] ?? null,
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
            'limit' => isset($props['dynamic_limit']) && \is_numeric($props['dynamic_limit']) ? (int) $props['dynamic_limit'] : 6,
            'offset' => isset($props['dynamic_offset']) && \is_numeric($props['dynamic_offset']) ? (int) $props['dynamic_offset'] : 0,
            'mapping' => $mapping,
            'condition' => $condition,
        ];
    }

    private function unwrapContentViews(mixed $value): mixed
    {
        if (\is_object($value) && \method_exists($value, 'getContent')) {
            return $this->unwrapContentViews($value->getContent());
        }

        if (\is_array($value)) {
            $unwrapped = [];
            foreach ($value as $k => $v) {
                $unwrapped[$k] = $this->unwrapContentViews($v);
            }

            return $unwrapped;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $pageData
     * @param array<string, string> $mapping
     * @param int $appliedCount incremented for every mapped field that received a non-empty dynamic value
     *
     * @return array<string, mixed>
     */
    private function applyMapping(array $block, array $pageData, array $mapping, int &$appliedCount = 0): array
    {
        $hydrated = $block;

        if ([] === $mapping) {
            $mapping = $this->buildAutoMapping($block, $pageData);
        }

        foreach ($mapping as $targetField => $sourcePath) {
            if ('' === $targetField || '' === $sourcePath) {
                continue;
            }

            $value = $this->extractValue($pageData, $sourcePath);

            // Smart Fallback: only override if the resolved dynamic value is non-empty
            if ($this->isValuePresent($value)) {
                $hydrated[$targetField] = $value;
                ++$appliedCount;
            }
        }

        return $hydrated;
    }

    /**
     * Builds an automatic target-to-source field mapping using:
     * 1. Equal field name matching against non-empty source data keys.
     * 2. Field type compatibility when block field metadata (`BlockTargetFieldMetadataResolver`) is available.
     *
     * @param array<string, mixed> $block
     * @param array<string, mixed> $pageData
     *
     * @return array<string, string>
     */
    private function buildAutoMapping(array $block, array $pageData): array
    {
        /** @var array<string, string|null> $targetFields */
        $targetFields = [];
        $blockType = isset($block['type']) && \is_string($block['type']) ? $block['type'] : '';

        if (null !== $this->targetFieldResolver && '' !== $blockType) {
            foreach ($this->targetFieldResolver->resolveTargetFields($blockType) as $fieldMeta) {
                $targetFields[$fieldMeta['name']] = $fieldMeta['type'];
            }
        }

        foreach (\array_keys($block) as $key) {
            if (\in_array($key, ['type', 'props', 'id', 'items', 'settings'], true) || \str_starts_with($key, '_')) {
                continue;
            }
            if (!\array_key_exists($key, $targetFields)) {
                $targetFields[$key] = null;
            }
        }

        /** @var array<string, string> $declaredSourceTypes */
        $declaredSourceTypes = [];
        foreach ($this->registry->getProviders() as $provider) {
            foreach ($provider->getSourceFields() as $sourceField) {
                $declaredSourceTypes[$sourceField['name']] ??= $sourceField['type'];
            }
        }

        /** @var array<string, string> $availableSourceTypes */
        $availableSourceTypes = [];
        foreach ($pageData as $sourceKey => $sourceValue) {
            if (\str_starts_with($sourceKey, '_') || \in_array($sourceKey, ['id', 'uuid', 'template'], true)) {
                continue;
            }
            if (!$this->isValuePresent($sourceValue)) {
                continue;
            }
            $availableSourceTypes[$sourceKey] = $this->inferSourceValueType(
                $sourceValue,
                $declaredSourceTypes[$sourceKey] ?? null,
            );
        }

        /** @var array<string, string> $mapping */
        $mapping = [];
        /** @var array<string, true> $usedSources */
        $usedSources = [];

        // 1. Equal field name match
        foreach ($targetFields as $targetField => $targetType) {
            if (\array_key_exists($targetField, $pageData) && $this->isValuePresent($pageData[$targetField])) {
                $mapping[$targetField] = $targetField;
                $usedSources[$targetField] = true;
            }
        }

        // 2. Field type compatibility match via resolved block field metadata
        foreach ($targetFields as $targetField => $targetType) {
            if (isset($mapping[$targetField]) || null === $targetType) {
                continue;
            }

            $matchedSource = null;
            foreach ($availableSourceTypes as $sourceKey => $sourceType) {
                if ($sourceType === $targetType && !isset($usedSources[$sourceKey])) {
                    $matchedSource = $sourceKey;
                    break;
                }
            }

            if (null === $matchedSource) {
                foreach ($availableSourceTypes as $sourceKey => $sourceType) {
                    if ($sourceType === $targetType) {
                        $matchedSource = $sourceKey;
                        break;
                    }
                }
            }

            if (null !== $matchedSource) {
                $mapping[$targetField] = $matchedSource;
                $usedSources[$matchedSource] = true;
            }
        }

        return $mapping;
    }

    private function inferSourceValueType(mixed $value, ?string $declaredType): string
    {
        if (\is_array($value)) {
            if (isset($value['id']) || isset($value['url']) || isset($value['thumbnails'])) {
                return 'media';
            }

            return 'array';
        }

        if (\is_bool($value)) {
            return 'boolean';
        }

        if (\is_int($value) || \is_float($value)) {
            return 'number';
        }

        if (null !== $declaredType && '' !== $declaredType) {
            return $declaredType;
        }

        if (\is_string($value)) {
            if (\str_starts_with($value, '/') || \str_starts_with($value, 'http://') || \str_starts_with($value, 'https://')) {
                return 'link';
            }

            if ($value !== \strip_tags($value) || \mb_strlen($value, 'UTF-8') > 120) {
                return 'text';
            }
        }

        return 'string';
    }

    /**
     * Extracts a value from source data supporting dot-notation (`a.b`),
     * pipe-separated fallback chains (`headline|title`, `summary|content:length=160`),
     * and inline text modifiers (`:length=160`, `:limit=160`, `:truncate=160`, `:plain`).
     *
     * @param array<string, mixed> $data
     */
    public function extractValue(array $data, string $path): mixed
    {
        $alternatives = \explode('|', $path);
        $lastValue = null;

        foreach ($alternatives as $rawCandidate) {
            $candidate = \trim($rawCandidate);
            if ('' === $candidate) {
                continue;
            }

            $fieldPath = $candidate;
            $maxLength = 0;
            $stripHtml = false;

            if (1 === \preg_match('/^([^:]+):(?:length|limit|truncate)=(\d+)$/i', $candidate, $matches)) {
                $fieldPath = \trim($matches[1]);
                $maxLength = (int) $matches[2];
                $stripHtml = true;
            } elseif (1 === \preg_match('/^([^:]+):plain$/i', $candidate, $matches)) {
                $fieldPath = \trim($matches[1]);
                $maxLength = 0;
                $stripHtml = true;
            }

            $val = $this->extractSinglePathValue($data, $fieldPath);

            if ($stripHtml) {
                $val = \is_scalar($val) ? self::truncatePlainText((string) $val, $maxLength) : null;
            }

            $lastValue = $val;
            if ($this->isValuePresent($val)) {
                return $val;
            }
        }

        return $lastValue;
    }

    public static function truncatePlainText(string $value, int $maxLength = 160): string
    {
        if ('' === \trim($value)) {
            return '';
        }

        $spaced = \preg_replace('/<\/(p|div|li|h[1-6]|blockquote|tr|section|article)>|<br\s*\/?>/i', ' ', $value) ?? $value;
        $stripped = \strip_tags($spaced);
        $decoded = \html_entity_decode($stripped, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $decoded = \str_replace(['<', '>'], '', $decoded);
        $normalized = \trim(\preg_replace('/\s+/u', ' ', $decoded) ?? $decoded);

        if ('' === $normalized || $maxLength <= 0) {
            return $normalized;
        }

        if (\mb_strlen($normalized, 'UTF-8') <= $maxLength) {
            return $normalized;
        }

        if ($maxLength <= 3) {
            return \mb_substr($normalized, 0, $maxLength, 'UTF-8');
        }

        $cut = \mb_substr($normalized, 0, $maxLength - 3, 'UTF-8');

        return \rtrim($cut, " \t\n\r\0\x0B.,;:-") . '...';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractSinglePathValue(array $data, string $path): mixed
    {
        $segments = \explode('.', $path);
        $current = $data;

        foreach ($segments as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $condition
     */
    public function evaluateCondition(array $data, array $condition): bool
    {
        $field = isset($condition['field']) && \is_string($condition['field']) ? $condition['field'] : '';
        if ('' === $field) {
            return true;
        }

        $operator = isset($condition['operator']) && \is_string($condition['operator']) ? $condition['operator'] : 'not_empty';
        $val = $this->extractValue($data, $field);
        $hasValue = $this->isValuePresent($val);

        $condVal = isset($condition['value']) && \is_scalar($condition['value']) ? (string) $condition['value'] : '';
        $strVal = \is_scalar($val) ? (string) $val : '';

        return match ($operator) {
            'is_empty', 'empty' => !$hasValue,
            'equals', 'eq' => $strVal === $condVal,
            'not_equals', 'neq' => $strVal !== $condVal,
            'contains' => $this->evaluateContains($val, $strVal, $condVal),
            'not_contains' => !$this->evaluateContains($val, $strVal, $condVal),
            'starts_with' => '' !== $condVal && \str_starts_with(\strtolower($strVal), \strtolower($condVal)),
            'ends_with' => '' !== $condVal && \str_ends_with(\strtolower($strVal), \strtolower($condVal)),
            'in' => \in_array($strVal, \array_map('trim', \explode(',', $condVal)), true),
            'gt' => \is_numeric($strVal) && \is_numeric($condVal) ? (float) $strVal > (float) $condVal : $strVal > $condVal,
            'gte' => \is_numeric($strVal) && \is_numeric($condVal) ? (float) $strVal >= (float) $condVal : $strVal >= $condVal,
            'lt' => \is_numeric($strVal) && \is_numeric($condVal) ? (float) $strVal < (float) $condVal : $strVal < $condVal,
            'lte' => \is_numeric($strVal) && \is_numeric($condVal) ? (float) $strVal <= (float) $condVal : $strVal <= $condVal,
            default => $hasValue, // 'not_empty'
        };
    }

    private function evaluateContains(mixed $val, string $strVal, string $condVal): bool
    {
        if ('' === $condVal) {
            return false;
        }

        if (\is_array($val)) {
            foreach ($val as $item) {
                if (\is_scalar($item) && \strtolower((string) $item) === \strtolower($condVal)) {
                    return true;
                }
            }

            return false;
        }

        return \str_contains(\strtolower($strVal), \strtolower($condVal));
    }

    private function isValuePresent(mixed $value): bool
    {
        if (null === $value) {
            return false;
        }
        if (\is_string($value) && '' === \trim($value)) {
            return false;
        }
        if (\is_array($value) && [] === $value) {
            return false;
        }

        return true;
    }

    private function isTruthy(mixed $value): bool
    {
        return true === $value || 1 === $value || 'true' === $value || '1' === $value;
    }
}
