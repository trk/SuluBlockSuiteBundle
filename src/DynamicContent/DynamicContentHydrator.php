<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent;

final class DynamicContentHydrator implements DynamicContentHydratorInterface
{
    /**
     * Block types whose multi-item dynamic content populates their internal `items` list
     * rather than repeating the outer container block itself.
     *
     * @var list<string>
     */
    private const MULTI_ITEM_CONTAINER_BLOCKS = [
        'grid',
        'gallery',
        'accordion',
        'list',
        'description_list',
        'panel_slider',
        'overlay_slider',
        'slideshow',
        'subnav',
        'switcher',
        'table',
        'nav',
        'map',
        'popover',
        'social',
        'breadcrumbs',
    ];

    /**
     * Default child item block type for multi-item container blocks when `items` is initially empty.
     *
     * @var array<string, string>
     */
    private const DEFAULT_CONTAINER_ITEM_TYPES = [
        'grid' => 'card',
        'gallery' => 'overlay',
        'panel_slider' => 'card',
        'overlay_slider' => 'overlay',
        'accordion' => 'accordion_item',
        'list' => 'list_item',
        'description_list' => 'description_list_item',
        'slideshow' => 'slideshow_item',
        'subnav' => 'subnav_item',
        'switcher' => 'switcher_item',
        'table' => 'table_item',
        'nav' => 'nav_item',
        'map' => 'map_item',
        'popover' => 'popover_item',
        'social' => 'social_item',
    ];

    /**
     * @var array<string, DynamicContentSourceProviderInterface>
     */
    private array $customProviders = [];

    /**
     * @param iterable<DynamicContentSourceProviderInterface> $customProviders
     */
    public function __construct(
        private readonly ?PageSourceResolverInterface $sourceResolver = null,
        iterable $customProviders = [],
    ) {
        foreach ($customProviders as $provider) {
            $this->customProviders[$provider->getKey()] = $provider;
        }
    }

    /**
     * @return array<string, DynamicContentSourceProviderInterface>
     */
    public function getCustomProviders(): array
    {
        return $this->customProviders;
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

        // 1. Custom registered source provider
        if (isset($this->customProviders[$sourceType])) {
            $provider = $this->customProviders[$sourceType];
            if ($provider->isMultiItem()) {
                $items = $provider->resolveCollection($dynamicConfig, $contextPage);

                return $this->hydrateCollection($block, $items, $mapping, $condition, $dynamicConfig);
            }

            $itemData = $provider->resolveSingle($dynamicConfig, $contextPage);
            if (null === $itemData) {
                return [$block];
            }

            if (null !== $condition && !$this->evaluateCondition($itemData, $condition)) {
                return [];
            }

            return [$this->applyMapping($block, $itemData, $mapping)];
        }

        if (null === $this->sourceResolver) {
            return [$block];
        }

        // 2. Child pages (page_children)
        if ('page_children' === $sourceType) {
            $rawParentId = $this->resolveTargetOrContext(
                $dynamicConfig['parent_id'] ?? ($dynamicConfig['page_id'] ?? null),
                $contextPage,
            );
            if (!\is_string($rawParentId) && !\is_object($rawParentId) && !\is_array($rawParentId)) {
                $rawParentId = [];
            }
            /** @var string|object|array<mixed, mixed> $parentId */
            $parentId = $rawParentId;
            $limit = isset($dynamicConfig['limit']) && \is_numeric($dynamicConfig['limit'])
                ? (int) $dynamicConfig['limit']
                : 50;

            $queryOptions = $this->extractQueryOptions($dynamicConfig);
            if ([] !== $queryOptions) {
                $items = $this->sourceResolver->resolveQuery(\array_merge($queryOptions, [
                    'parent_id' => $parentId,
                    'limit' => $limit,
                ]));
            } else {
                $items = $this->sourceResolver->resolveChildren($parentId, null, $limit);
            }

            return $this->hydrateCollection($block, $items, $mapping, $condition, $dynamicConfig);
        }

        // 3. Sibling pages (sibling_pages)
        if ('sibling_pages' === $sourceType) {
            $targetPage = $this->resolveTargetOrContext($contextPage, $dynamicConfig['page_id'] ?? null);
            $limit = isset($dynamicConfig['limit']) && \is_numeric($dynamicConfig['limit'])
                ? (int) $dynamicConfig['limit']
                : 50;
            $excludeCurrent = !isset($dynamicConfig['exclude_current']) || (bool) $dynamicConfig['exclude_current'];
            $queryOptions = $this->extractQueryOptions($dynamicConfig);

            $items = $this->sourceResolver->resolveSiblings($targetPage, null, $limit, $excludeCurrent, $queryOptions);

            return $this->hydrateCollection($block, $items, $mapping, $condition, $dynamicConfig);
        }

        // 4. Custom query / Smart Content (custom_query)
        if ('custom_query' === $sourceType) {
            /** @var array<string, mixed> $query */
            $query = isset($dynamicConfig['query']) && \is_array($dynamicConfig['query'])
                ? $dynamicConfig['query']
                : [];
            $extraOptions = $this->extractQueryOptions($dynamicConfig);
            if ([] !== $extraOptions) {
                $query = \array_merge($extraOptions, $query);
            }
            if (isset($dynamicConfig['limit']) && \is_numeric($dynamicConfig['limit']) && !isset($query['limit'])) {
                $query['limit'] = (int) $dynamicConfig['limit'];
            }
            $items = $this->sourceResolver->resolveQuery($query);

            return $this->hydrateCollection($block, $items, $mapping, $condition, $dynamicConfig);
        }

        // 5. Parent page (parent_page)
        if ('parent_page' === $sourceType) {
            $targetPage = $this->resolveTargetOrContext($contextPage, $dynamicConfig['page_id'] ?? null);

            $pageData = $this->sourceResolver->resolveParent($targetPage);

            if (null === $pageData) {
                return [$block];
            }

            if (null !== $condition && !$this->evaluateCondition($pageData, $condition)) {
                return [];
            }

            return [$this->applyMapping($block, $pageData, $mapping)];
        }

        // 6. Single-page source (specific_page or current_page)
        /** @var mixed $rawTargetPage */
        $rawTargetPage = 'current_page' === $sourceType
            ? $contextPage
            : ($dynamicConfig['page_id'] ?? null);

        if (\is_string($rawTargetPage) && '' === \trim($rawTargetPage)) {
            $rawTargetPage = null;
        }

        if (!\is_string($rawTargetPage) && !\is_object($rawTargetPage) && !\is_array($rawTargetPage)) {
            if ('current_page' === $sourceType) {
                $rawTargetPage = [];
            } else {
                return [$block];
            }
        }
        /** @var string|object|array<mixed, mixed> $targetPage */
        $targetPage = $rawTargetPage;

        $pageData = $this->sourceResolver->resolvePage($targetPage);
        if (null === $pageData) {
            // Graceful fallback to static block
            return [$block];
        }

        if (null !== $condition && !$this->evaluateCondition($pageData, $condition)) {
            return []; // Omitted by condition
        }

        return [$this->applyMapping($block, $pageData, $mapping)];
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
     * @return string|object|array<mixed, mixed>|null
     */
    private function resolveTargetOrContext(
        mixed $configuredTarget,
        mixed $fallbackTarget,
    ): string|object|array|null {
        if (\is_string($configuredTarget) && '' !== \trim($configuredTarget)) {
            return \trim($configuredTarget);
        }

        if (\is_object($configuredTarget) || (\is_array($configuredTarget) && [] !== $configuredTarget)) {
            return $configuredTarget;
        }

        if (\is_string($fallbackTarget) && '' !== \trim($fallbackTarget)) {
            return \trim($fallbackTarget);
        }

        if (\is_object($fallbackTarget) || \is_array($fallbackTarget)) {
            return $fallbackTarget;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $dynamicConfig
     *
     * @return array<string, mixed>
     */
    private function extractQueryOptions(array $dynamicConfig): array
    {
        $options = [];
        $allowedKeys = [
            'sort_by',
            'sort_order',
            'offset',
            'template',
            'templates',
            'template_keys',
            'tag',
            'tags',
            'tag_names',
            'category',
            'categories',
            'category_keys',
            'category_ids',
            'webspace_key',
            'descendant_of_id',
            'exclude_id',
            'exclude_ids',
        ];

        foreach ($allowedKeys as $key) {
            if (isset($dynamicConfig[$key]) && '' !== $dynamicConfig[$key] && [] !== $dynamicConfig[$key]) {
                $options[$key] = $dynamicConfig[$key];
            }
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $block
     * @param list<array<string, mixed>> $items
     * @param array<string, string> $mapping
     * @param array<string, mixed>|null $condition
     * @param array<string, mixed> $dynamicConfig
     *
     * @return list<array<string, mixed>>
     */
    private function hydrateCollection(
        array $block,
        array $items,
        array $mapping,
        ?array $condition,
        array $dynamicConfig = [],
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

        $shouldHydrateInnerItems = 'items' === $hydrateMode
            || (
                'repeater' !== $hydrateMode
                && (
                    \in_array($blockType, self::MULTI_ITEM_CONTAINER_BLOCKS, true)
                    || (isset($block['items']) && \is_array($block['items']))
                )
            );

        if ($shouldHydrateInnerItems) {
            /** @var array<string, mixed> $templateItem */
            $templateItem = (isset($block['items']) && \is_array($block['items']) && isset($block['items'][0]) && \is_array($block['items'][0]))
                ? $block['items'][0]
                : [];

            if (!isset($templateItem['type']) && isset(self::DEFAULT_CONTAINER_ITEM_TYPES[$blockType])) {
                $templateItem['type'] = self::DEFAULT_CONTAINER_ITEM_TYPES[$blockType];
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
                $hydratedInnerItems[] = $this->applyMapping($templateItem, $itemData, $itemMapping);
            }

            $hydratedBlock = $block;
            $hydratedBlock['items'] = $hydratedInnerItems;

            return [$hydratedBlock];
        }

        $hydratedList = [];
        foreach ($filteredItems as $itemData) {
            $hydratedList[] = $this->applyMapping($block, $itemData, $mapping);
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

        if (\is_array($props) && isset($props['_dynamic'])) {
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

        return null;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $pageData
     * @param array<string, string> $mapping
     *
     * @return array<string, mixed>
     */
    private function applyMapping(array $block, array $pageData, array $mapping): array
    {
        $hydrated = $block;

        foreach ($mapping as $targetField => $sourcePath) {
            if ('' === $targetField || '' === $sourcePath) {
                continue;
            }

            $value = $this->extractValue($pageData, $sourcePath);

            // Smart Fallback: only override if the resolved dynamic value is non-empty
            if ($this->isValuePresent($value)) {
                $hydrated[$targetField] = $value;
            }
        }

        return $hydrated;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function extractValue(array $data, string $path): mixed
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
}
