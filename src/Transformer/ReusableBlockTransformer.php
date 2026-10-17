<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Transformer;

use Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Symfony\Contracts\Service\ResetInterface;
use Totoglu\SuluBlockSuiteBundle\Layout\PageLayoutProviderInterface;
use Totoglu\SuluBlockSuiteBundle\Normalizer\SuluDataNormalizer;

/**
 * Expands `reusable` blocks into their inner block list at the start of the collection pipeline
 * so merged page and reusable blocks undergo dynamic content hydration, attribute normalization,
 * and auto-section grouping uniformly without producing nested `<section>` wrappers.
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

    public function __construct(
        private readonly ?PageLayoutProviderInterface $layoutProvider = null,
        private readonly ?ReferenceStoreInterface $referenceStore = null,
    ) {
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
        return 'reusable' === ($block['type'] ?? null) && !empty($context['_in_collection']);
    }

    public function transform(array $block, array $context): array
    {
        $props = SuluDataNormalizer::extractProps($block);
        $status = $props['status'] ?? ($block['status'] ?? null);
        if ('disabled' === $status) {
            $block['_hidden'] = true;

            return $block;
        }

        $groupId = 'reusable_' . (++$this->groupSequence);
        $isInColumn = !empty($context['_in_column']) || isset($context['_slot']);
        $locale = $this->extractLocale($context);

        $expanded = $this->expandReusableBlock($block, $locale, $groupId, $isInColumn, 0);
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
        bool $isInColumn,
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
                    foreach ($this->expandReusableBlock($normalized, $locale, $nestedGroupId, $isInColumn, $depth + 1) as $nestedItem) {
                        $result[] = $nestedItem;
                    }

                    continue;
                }

                if ($isInColumn && 'section' === $innerType) {
                    foreach ($this->unwrapSectionBlocks($normalized, $locale, $groupId, $depth) as $leafBlock) {
                        $leafBlock['_section_group'] ??= $groupId;
                        $result[] = $leafBlock;
                    }

                    continue;
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
     * Unwraps a `section > rows > columns > blocks` hierarchy into flat leaf blocks when a reusable block
     * is placed inside an already active column, preventing nested `<section>` elements.
     *
     * @param array<string, mixed> $sectionBlock
     *
     * @return list<array<string, mixed>>
     */
    private function unwrapSectionBlocks(
        array $sectionBlock,
        ?string $locale = null,
        string $groupId = '',
        int $depth = 0,
    ): array {
        if ($depth >= self::MAX_EXPANSION_DEPTH) {
            return [];
        }

        $rows = $sectionBlock['rows'] ?? null;
        if (!\is_iterable($rows)) {
            return [];
        }

        /** @var list<array<string, mixed>> $leafBlocks */
        $leafBlocks = [];
        foreach ($rows as $row) {
            $normalizedRow = $this->normalizeBlock($row);
            if (null === $normalizedRow) {
                continue;
            }
            $columns = $normalizedRow['columns'] ?? null;
            if (!\is_iterable($columns)) {
                continue;
            }
            foreach ($columns as $column) {
                $normalizedCol = $this->normalizeBlock($column);
                if (null === $normalizedCol) {
                    continue;
                }
                $blocks = $normalizedCol['blocks'] ?? null;
                if (!\is_iterable($blocks)) {
                    continue;
                }
                foreach ($blocks as $leaf) {
                    $normalizedLeaf = $this->normalizeBlock($leaf);
                    if (null === $normalizedLeaf) {
                        continue;
                    }

                    $leafType = isset($normalizedLeaf['type']) && \is_scalar($normalizedLeaf['type'])
                        ? (string) $normalizedLeaf['type']
                        : '';

                    if ('reusable' === $leafType) {
                        $nestedGroupId = 'reusable_' . (++$this->groupSequence);
                        foreach ($this->expandReusableBlock($normalizedLeaf, $locale, $nestedGroupId, true, $depth + 1) as $nestedItem) {
                            $leafBlocks[] = $nestedItem;
                        }

                        continue;
                    }

                    if ('section' === $leafType) {
                        foreach ($this->unwrapSectionBlocks($normalizedLeaf, $locale, $groupId, $depth + 1) as $nestedLeaf) {
                            $leafBlocks[] = $nestedLeaf;
                        }

                        continue;
                    }

                    $leafBlocks[] = $normalizedLeaf;
                }
            }
        }

        return $leafBlocks;
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
