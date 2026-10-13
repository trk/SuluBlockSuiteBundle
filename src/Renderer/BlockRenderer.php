<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Renderer;

use Totoglu\SuluBlockSuiteBundle\Transformer\BlockTransformPipelineInterface;
use Twig\Environment;

/**
 * Core block rendering engine that resolves block templates across registered
 * namespaces and delegates block collection rendering to the active UI framework strategy.
 */
final class BlockRenderer
{
    /**
     * @var list<string>
     */
    private array $templateNamespaces;

    private ?BlockCollectionRendererInterface $collectionRenderer = null;

    /**
     * @var array<string, string|null>
     */
    private array $templateCache = [];

    /**
     * @param list<string> $templateNamespaces
     */
    public function __construct(
        private readonly ?Environment $twig = null,
        private readonly ?BlockTransformPipelineInterface $pipeline = null,
        array $templateNamespaces = ['blocks', '@SuluBlockSuite/blocks'],
        ?BlockCollectionRendererInterface $collectionRenderer = null,
    ) {
        $normalized = [];
        foreach ($templateNamespaces as $ns) {
            $trimmed = \trim($ns, '/ ');
            if ('' !== $trimmed && !\in_array($trimmed, $normalized, true)) {
                $normalized[] = $trimmed;
            }
        }
        if (!\in_array('blocks', $normalized, true)) {
            \array_unshift($normalized, 'blocks');
        }
        if (!\in_array('@SuluBlockSuite/blocks', $normalized, true)) {
            $normalized[] = '@SuluBlockSuite/blocks';
        }

        $this->templateNamespaces = $normalized;
        $this->collectionRenderer = $collectionRenderer;
    }

    public function setCollectionRenderer(?BlockCollectionRendererInterface $collectionRenderer): void
    {
        $this->collectionRenderer = $collectionRenderer;
    }

    /**
     * Resolves the highest-priority existing Twig template for a given block type.
     */
    public function resolveBlockTemplate(string $blockType): ?string
    {
        $cleanType = \trim($blockType);
        if ('' === $cleanType || null === $this->twig) {
            return null;
        }

        if (\array_key_exists($cleanType, $this->templateCache)) {
            return $this->templateCache[$cleanType];
        }

        $loader = $this->twig->getLoader();
        foreach ($this->templateNamespaces as $namespace) {
            $candidate = $namespace . '/' . $cleanType . '.html.twig';
            if ($loader->exists($candidate)) {
                return $this->templateCache[$cleanType] = $candidate;
            }
        }

        return $this->templateCache[$cleanType] = null;
    }

    /**
     * Processes a single block through the pipeline (if not already processed) and renders its Twig template.
     *
     * @param array<string, mixed> $block
     * @param array<string, mixed> $context
     */
    public function renderBlock(array $block, array $context = []): string
    {
        if (null === $this->twig) {
            return '';
        }

        if (empty($block['_pipeline_processed']) && null !== $this->pipeline) {
            $block = $this->pipeline->process($block, $context);
            $block['_pipeline_processed'] = true;
        }

        if (!empty($block['_hidden'])) {
            return '';
        }

        $type = isset($block['type']) && \is_scalar($block['type']) ? (string) $block['type'] : '';
        $template = $this->resolveBlockTemplate($type);
        if (null === $template) {
            return '';
        }

        return $this->twig->render($template, \array_merge($context, [
            'block' => $block,
        ]));
    }

    /**
     * Processes and renders a collection of blocks via the configured BlockCollectionRendererInterface.
     *
     * @param iterable<mixed> $blocks
     * @param array<string, mixed> $context
     */
    public function renderBlocks(iterable $blocks, array $context = []): string
    {
        /** @var list<array<string, mixed>> $normalizedBlocks */
        $normalizedBlocks = [];
        foreach ($blocks as $block) {
            $normalized = $this->normalizeBlock($block);
            if (null !== $normalized) {
                $normalizedBlocks[] = $normalized;
            }
        }

        if ([] === $normalizedBlocks) {
            return '';
        }

        /** @var list<array<string, mixed>> $visibleBlocks */
        $visibleBlocks = [];
        foreach ($normalizedBlocks as $item) {
            if (empty($item['_pipeline_processed']) && null !== $this->pipeline) {
                $item = $this->pipeline->process($item, $context);
                $item['_pipeline_processed'] = true;
            }

            if (empty($item['_hidden'])) {
                $visibleBlocks[] = $item;
            }
        }

        if ([] === $visibleBlocks) {
            return '';
        }

        if (null !== $this->collectionRenderer && empty($context['disable_auto_section'])) {
            return $this->collectionRenderer->renderCollection($visibleBlocks, $context);
        }

        $output = '';
        foreach ($visibleBlocks as $block) {
            $output .= $this->renderBlock($block, $context);
        }

        return $output;
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
