<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent;

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
    ) {
        $this->registry = new DynamicContentSourceRegistry($customProviders, $sourceResolver);
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

        $entry = $this->registry->getEntry($sourceType);
        if (null === $entry) {
            return [$block];
        }

        $provider = $entry['provider'];
        if ($entry['multi_item']) {
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
