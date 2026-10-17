<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Transformer;

use Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\ResetInterface;
use Totoglu\SuluBlockSuiteBundle\Layout\PageLayoutProviderInterface;
use Totoglu\SuluBlockSuiteBundle\Normalizer\SuluDataNormalizer;

/**
 * Expands `reusable` blocks into their inner block list at the start of the collection pipeline
 * so merged page and reusable blocks undergo dynamic content hydration, attribute normalization,
 * and auto-grouping uniformly without producing nested layout wrappers.
 *
 * Priority: 110 (Runs before DynamicContentTransformer at 100).
 */
final class ReusableBlockTransformer implements BlockTransformerInterface, ResetInterface
{
    private const MAX_EXPANSION_DEPTH = 8;

    private int $groupSequence = 0;

    /**
     * @var array<string, bool>
     */
    private array $activeSnippets = [];

    /**
     * @var list<NestedBlockFlattenerInterface>
     */
    private readonly array $flatteners;

    /**
     * @param iterable<NestedBlockFlattenerInterface> $flatteners
     */
    public function __construct(
        private readonly ?PageLayoutProviderInterface $layoutProvider = null,
        private readonly ?ReferenceStoreInterface $referenceStore = null,
        #[AutowireIterator('sulu_block_suite.nested_block_flattener')]
        iterable $flatteners = [],
    ) {
        $list = [];
        foreach ($flatteners as $flattener) {
            $list[] = $flattener;
        }
        $this->flatteners = $list;
    }

    public static function getDefaultPriority(): int
    {
        return 110;
    }

    public function reset(): void
    {
        $this->groupSequence = 0;
        $this->activeSnippets = [];
    }

    public function supports(array $block, array $context): bool
    {
        if (empty($context['_in_collection'])) {
            return false;
        }

        if ('reusable' === ($block['type'] ?? null)) {
            return true;
        }

        return $this->hasNestedReusable($block);
    }

    public function transform(array $block, array $context): array
    {
        $locale = $this->extractLocale($context);

        if ('reusable' !== ($block['type'] ?? null)) {
            return $this->expandNestedReusablesInContainer($block, $locale, 0);
        }

        $props = SuluDataNormalizer::extractProps($block);
        $status = $props['status'] ?? ($block['status'] ?? null);
        if ('disabled' === $status) {
            $block['_hidden'] = true;

            return $block;
        }

        $groupId = 'reusable_' . (++$this->groupSequence);
        $isNested = !empty($context['_nested_block']);

        $expanded = $this->expandReusableBlock($block, $locale, $groupId, $isNested, 0);
        if ([] === $expanded) {
            $block['_hidden'] = true;

            return $block;
        }

        $block['_expanded_blocks'] = $expanded;

        return $block;
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return list<array<string, mixed>>
     */
    private function expandReusableBlock(
        array $block,
        ?string $locale,
        string $groupId,
        bool $isNested,
        int $depth,
    ): array {
        if ($depth >= self::MAX_EXPANSION_DEPTH) {
            return [];
        }

        $props = SuluDataNormalizer::extractProps($block);
        $status = $props['status'] ?? ($block['status'] ?? null);
        if ('disabled' === $status) {
            return [];
        }

        $snippetId = $this->extractSnippetId($block);
        if (null !== $snippetId && isset($this->activeSnippets[$snippetId])) {
            return [];
        }

        if (null !== $snippetId) {
            $this->activeSnippets[$snippetId] = true;
        }

        try {
            $innerBlocks = $this->resolveInnerBlocks($block, $snippetId, $locale);
            if ([] === $innerBlocks) {
                return [];
            }

            /** @var list<array<string, mixed>> $result */
            $result = [];
            foreach ($innerBlocks as $inner) {
                $normalized = $this->normalizeBlock($inner);
                if (null === $normalized) {
                    continue;
                }

                $innerType = isset($normalized['type']) && \is_scalar($normalized['type'])
                    ? (string) $normalized['type']
                    : '';

                if ('reusable' === $innerType) {
                    $nestedGroupId = 'reusable_' . (++$this->groupSequence);
                    foreach ($this->expandReusableBlock($normalized, $locale, $nestedGroupId, $isNested, $depth + 1) as $nestedItem) {
                        $result[] = $nestedItem;
                    }

                    continue;
                }

                if ($isNested) {
                    $flattener = $this->findFlattener($normalized);
                    if (null !== $flattener) {
                        foreach ($this->flattenBlock($flattener, $normalized, $locale, $groupId, $depth) as $leafBlock) {
                            $leafBlock['_section_group'] ??= $groupId;
                            $result[] = $leafBlock;
                        }

                        continue;
                    }
                } elseif ($this->hasNestedReusable($normalized)) {
                    $normalized = $this->expandNestedReusablesInContainer($normalized, $locale, $depth + 1);
                }

                $normalized['_section_group'] ??= $groupId;
                $result[] = $normalized;
            }

            return $result;
        } finally {
            if (null !== $snippetId) {
                unset($this->activeSnippets[$snippetId]);
            }
        }
    }

    /**
     * @param array<string, mixed> $block
     */
    private function findFlattener(array $block): ?NestedBlockFlattenerInterface
    {
        foreach ($this->flatteners as $flattener) {
            if ($flattener->supports($block)) {
                return $flattener;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return list<array<string, mixed>>
     */
    private function flattenBlock(
        NestedBlockFlattenerInterface $flattener,
        array $block,
        ?string $locale,
        string $groupId,
        int $depth,
    ): array {
        if ($depth >= self::MAX_EXPANSION_DEPTH) {
            return [];
        }

        return $flattener->flatten(
            $block,
            function (array $child) use ($locale, $groupId, $depth): array {
                $normalizedChild = $this->normalizeBlock($child);
                if (null === $normalizedChild) {
                    return [];
                }

                $childType = isset($normalizedChild['type']) && \is_scalar($normalizedChild['type'])
                    ? (string) $normalizedChild['type']
                    : '';

                if ('reusable' === $childType) {
                    $nestedGroupId = 'reusable_' . (++$this->groupSequence);

                    return $this->expandReusableBlock($normalizedChild, $locale, $nestedGroupId, true, $depth + 1);
                }

                $nestedFlattener = $this->findFlattener($normalizedChild);
                if (null !== $nestedFlattener) {
                    $flattened = [];
                    foreach ($this->flattenBlock($nestedFlattener, $normalizedChild, $locale, $groupId, $depth + 1) as $leafBlock) {
                        $leafBlock['_section_group'] ??= $groupId;
                        $flattened[] = $leafBlock;
                    }

                    return $flattened;
                }

                if ($this->hasNestedReusable($normalizedChild)) {
                    $normalizedChild = $this->expandNestedReusablesInContainer($normalizedChild, $locale, $depth + 1);
                }

                $normalizedChild['_section_group'] ??= $groupId;

                return [$normalizedChild];
            },
        );
    }

    /**
     * @param array<string, mixed> $block
     */
    private function hasNestedReusable(array $block): bool
    {
        foreach ($block as $key => $value) {
            if (\str_starts_with($key, '_')) {
                continue;
            }
            if (\is_array($value) && $this->arrayContainsReusable($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $data
     */
    private function arrayContainsReusable(array $data): bool
    {
        if ('reusable' === ($data['type'] ?? null)) {
            return true;
        }

        foreach ($data as $key => $value) {
            if (\is_string($key) && \str_starts_with($key, '_')) {
                continue;
            }
            $normalized = \is_object($value) ? $this->normalizeBlock($value) : (\is_array($value) ? $value : null);
            if (\is_array($normalized) && $this->arrayContainsReusable($normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $container
     *
     * @return array<string, mixed>
     */
    private function expandNestedReusablesInContainer(array $container, ?string $locale, int $depth): array
    {
        if ($depth >= self::MAX_EXPANSION_DEPTH) {
            return $container;
        }

        foreach ($container as $key => $value) {
            if (\str_starts_with($key, '_') || !\is_array($value)) {
                continue;
            }

            if (\array_is_list($value)) {
                $expandedList = [];
                foreach ($value as $item) {
                    $normalizedItem = $this->normalizeBlock($item);
                    if (null === $normalizedItem) {
                        $expandedList[] = $item;

                        continue;
                    }

                    $itemType = isset($normalizedItem['type']) && \is_scalar($normalizedItem['type'])
                        ? (string) $normalizedItem['type']
                        : '';

                    if ('reusable' === $itemType) {
                        $nestedGroupId = 'reusable_' . (++$this->groupSequence);
                        foreach ($this->expandReusableBlock($normalizedItem, $locale, $nestedGroupId, true, $depth) as $nestedItem) {
                            $expandedList[] = $nestedItem;
                        }

                        continue;
                    }

                    if ($this->hasNestedReusable($normalizedItem)) {
                        $normalizedItem = $this->expandNestedReusablesInContainer($normalizedItem, $locale, $depth);
                    }

                    $expandedList[] = $normalizedItem;
                }

                $container[$key] = $expandedList;

                continue;
            }

            /** @var array<string, mixed> $value */
            if ($this->hasNestedReusable($value)) {
                $container[$key] = $this->expandNestedReusablesInContainer($value, $locale, $depth);
            }
        }

        return $container;
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return list<mixed>
     */
    private function resolveInnerBlocks(array $block, ?string $snippetId, ?string $locale): array
    {
        if (null !== $snippetId && '' !== $snippetId) {
            $this->referenceStore?->add($snippetId, SnippetInterface::RESOURCE_KEY);
        }

        if (isset($block['blocks']) && \is_array($block['blocks']) && [] !== $block['blocks']) {
            return \array_values($block['blocks']);
        }

        foreach (['snippet', 'reusable_snippet'] as $key) {
            $snippet = $block[$key] ?? null;
            if (\is_array($snippet)) {
                if (isset($snippet['blocks']) && \is_array($snippet['blocks'])) {
                    return \array_values($snippet['blocks']);
                }
                if (
                    isset($snippet['content'])
                    && \is_array($snippet['content'])
                    && isset($snippet['content']['blocks'])
                    && \is_array($snippet['content']['blocks'])
                ) {
                    return \array_values($snippet['content']['blocks']);
                }
            }
        }

        if (null !== $snippetId && null !== $this->layoutProvider) {
            $loaded = $this->layoutProvider->getReusableBlock($snippetId, $locale);
            if (\is_array($loaded)) {
                if (isset($loaded['blocks']) && \is_array($loaded['blocks'])) {
                    return \array_values($loaded['blocks']);
                }
                if (
                    isset($loaded['content'])
                    && \is_array($loaded['content'])
                    && isset($loaded['content']['blocks'])
                    && \is_array($loaded['content']['blocks'])
                ) {
                    return \array_values($loaded['content']['blocks']);
                }
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $block
     */
    private function extractSnippetId(array $block): ?string
    {
        foreach (['snippet', 'reusable_snippet'] as $key) {
            $snippet = $block[$key] ?? null;
            if (\is_string($snippet) && '' !== \trim($snippet)) {
                return \trim($snippet);
            }
            if (\is_array($snippet)) {
                $innerContent = isset($snippet['content']) && \is_array($snippet['content']) ? $snippet['content'] : [];
                $candidate = $snippet['uuid'] ?? ($snippet['id'] ?? ($innerContent['uuid'] ?? ($innerContent['id'] ?? null)));
                if (\is_string($candidate) && '' !== \trim($candidate)) {
                    return \trim($candidate);
                }
            }
        }

        if (
            isset($block['view'])
            && \is_array($block['view'])
            && isset($block['view']['snippet'])
            && \is_array($block['view']['snippet'])
        ) {
            $viewSnippet = $block['view']['snippet'];
            $candidate = $viewSnippet['id'] ?? ($viewSnippet['uuid'] ?? null);
            if (\is_string($candidate) && '' !== \trim($candidate)) {
                return \trim($candidate);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function extractLocale(array $context): ?string
    {
        if (isset($context['locale']) && \is_string($context['locale']) && '' !== \trim($context['locale'])) {
            return \trim($context['locale']);
        }

        if (
            isset($context['request'])
            && \is_array($context['request'])
            && isset($context['request']['locale'])
            && \is_string($context['request']['locale'])
            && '' !== \trim($context['request']['locale'])
        ) {
            return \trim($context['request']['locale']);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeBlock(mixed $block): ?array
    {
        if (!\is_array($block) && !\is_object($block)) {
            return null;
        }

        if (\is_object($block)) {
            if (\method_exists($block, 'getContent')) {
                /** @var mixed $block */
                $block = $block->getContent();
            } elseif (\method_exists($block, 'toArray')) {
                /** @var mixed $block */
                $block = $block->toArray();
            } else {
                $block = (array) $block;
            }
        }

        if (!\is_array($block)) {
            return null;
        }

        /** @var array<string, mixed> $block */
        return $block;
    }
}
