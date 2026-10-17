<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Twig;

use Totoglu\SuluBlockSuiteBundle\Renderer\BlockRenderer;
use Totoglu\SuluBlockSuiteBundle\Transformer\BlockTransformPipelineInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class DynamicContentTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly BlockTransformPipelineInterface $pipeline,
        private readonly ?BlockRenderer $blockRenderer = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sulu_block_suite_hydrate_blocks', [$this, 'hydrateBlocks']),
            new TwigFunction('hydrate_blocks', [$this, 'hydrateBlocks']),
            new TwigFunction('sulu_block_suite_transform_block', [$this, 'transformBlock']),
            new TwigFunction('block_transform', [$this, 'transformBlock']),
            new TwigFunction('sulu_block_render', [$this, 'renderBlock'], ['is_safe' => ['html']]),
            new TwigFunction('block_render', [$this, 'renderBlock'], ['is_safe' => ['html']]),
            new TwigFunction('sulu_blocks_render', [$this, 'renderBlocks'], ['is_safe' => ['html']]),
            new TwigFunction('blocks_render', [$this, 'renderBlocks'], ['is_safe' => ['html']]),
            new TwigFunction('hydrate_block_tree', [$this, 'hydrateBlockTree']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('sulu_block_suite_hydrate_blocks', [$this, 'hydrateBlocks']),
            new TwigFilter('hydrate_blocks', [$this, 'hydrateBlocks']),
            new TwigFilter('hydrate_block_tree', [$this, 'hydrateBlockTree']),
            new TwigFilter('sulu_block_suite_transform_block', [$this, 'transformBlock']),
            new TwigFilter('block_transform', [$this, 'transformBlock']),
        ];
    }

    /**
     * @param iterable<mixed> $blocks
     * @param array<string, mixed>|object|null $context
     *
     * @return list<array<string, mixed>>
     */
    public function hydrateBlocks(iterable $blocks, array|object|null $context = null): array
    {
        /** @var array<string, mixed> $normalizedContext */
        $normalizedContext = \is_array($context) ? $context : (\is_object($context) ? (array) $context : []);

        /** @var list<array<string, mixed>> $rawBlocks */
        $rawBlocks = [];
        foreach ($blocks as $block) {
            $normalizedBlock = $this->normalizeBlock($block);
            if (null !== $normalizedBlock) {
                $rawBlocks[] = $normalizedBlock;
            }
        }

        $processed = $this->pipeline->processCollection($rawBlocks, $normalizedContext);

        $results = [];
        foreach ($processed as $item) {
            if (empty($item['_hidden'])) {
                $results[] = $item;
            }
        }

        return $results;
    }

    /**
     * Hydrates the leaf block lists of a nested container block exactly once, e.g. with the path
     * `['rows', 'columns', 'blocks']` for a `section > rows > columns > blocks` tree. Dynamic blocks
     * hidden by their display rules (status, display condition, "Hide When Empty") are removed.
     *
     * Returns null when the container held at least one leaf block and none of them remains
     * visible, so templates can skip rendering empty containers. Containers without any leaf
     * block (pure spacers/backgrounds) are returned unchanged.
     *
     * @param array<string, mixed>|null $block
     * @param array<string, mixed>|object|null $context
     * @param list<string> $path
     *
     * @return array<string, mixed>|null
     */
    public function hydrateBlockTree(?array $block, array|object|null $context = null, array $path = []): ?array
    {
        if (null === $block || [] === $path) {
            return $block;
        }

        /** @var array<string, mixed> $normalizedContext */
        $normalizedContext = \is_array($context) ? $context : (\is_object($context) ? (array) $context : []);

        $sourceCount = 0;
        $visibleCount = 0;
        $hydrated = $this->hydrateTreeLevel($block, $path, $normalizedContext, $sourceCount, $visibleCount);

        if ($sourceCount > 0 && 0 === $visibleCount) {
            return null;
        }

        return $hydrated;
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $path
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function hydrateTreeLevel(array $node, array $path, array $context, int &$sourceCount, int &$visibleCount): array
    {
        $key = $path[0];
        $remainingPath = \array_slice($path, 1);
        $children = $node[$key] ?? null;

        if (!\is_iterable($children)) {
            return $node;
        }

        if ([] === $remainingPath) {
            $leafBlocks = [];
            foreach ($children as $child) {
                $normalizedChild = $this->normalizeBlock($child);
                if (null !== $normalizedChild) {
                    $leafBlocks[] = $normalizedChild;
                }
            }

            $hydratedBlocks = $this->hydrateBlocks($leafBlocks, \array_merge($context, ['_in_column' => true]));
            $sourceCount += \count($leafBlocks);
            $visibleCount += \count($hydratedBlocks);
            $node[$key] = $hydratedBlocks;

            return $node;
        }

        $hydratedChildren = [];
        foreach ($children as $child) {
            $normalizedChild = $this->normalizeBlock($child);
            if (null !== $normalizedChild) {
                $hydratedChildren[] = $this->hydrateTreeLevel($normalizedChild, $remainingPath, $context, $sourceCount, $visibleCount);
            }
        }
        $node[$key] = $hydratedChildren;

        return $node;
    }

    /**
     * Transforms a single block before rendering through the BlockTransformPipeline.
     * Returns null if display condition or status evaluates to hidden (omits rendering).
     *
     * @param array<string, mixed>|object|null $context
     *
     * @return array<string, mixed>|null
     */
    public function transformBlock(mixed $block, array|object|null $context = null): ?array
    {
        $normalizedBlock = $this->normalizeBlock($block);
        if (null === $normalizedBlock) {
            return null;
        }

        /** @var array<string, mixed> $normalizedContext */
        $normalizedContext = \is_array($context) ? $context : (\is_object($context) ? (array) $context : []);

        $processed = $this->pipeline->process($normalizedBlock, $normalizedContext);

        if (!empty($processed['_hidden'])) {
            return null;
        }

        return $processed;
    }

    /**
     * @param array<string, mixed>|object|null $context
     */
    public function renderBlock(mixed $block, array|object|null $context = null): string
    {
        if (null === $this->blockRenderer) {
            return '';
        }

        $normalizedBlock = $this->normalizeBlock($block);
        if (null === $normalizedBlock) {
            return '';
        }

        /** @var array<string, mixed> $normalizedContext */
        $normalizedContext = \is_array($context) ? $context : (\is_object($context) ? (array) $context : []);

        return $this->blockRenderer->renderBlock($normalizedBlock, $normalizedContext);
    }

    /**
     * @param iterable<mixed> $blocks
     * @param array<string, mixed>|object|null $context
     */
    public function renderBlocks(iterable $blocks, array|object|null $context = null): string
    {
        if (null === $this->blockRenderer) {
            return '';
        }

        /** @var array<string, mixed> $normalizedContext */
        $normalizedContext = \is_array($context) ? $context : (\is_object($context) ? (array) $context : []);

        return $this->blockRenderer->renderBlocks($blocks, $normalizedContext);
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
