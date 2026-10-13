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
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('sulu_block_suite_hydrate_blocks', [$this, 'hydrateBlocks']),
            new TwigFilter('hydrate_blocks', [$this, 'hydrateBlocks']),
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
