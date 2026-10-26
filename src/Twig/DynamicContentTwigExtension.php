<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Twig;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Totoglu\SuluBlockSuiteBundle\Normalizer\SuluDataNormalizer;
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
        private readonly ?RequestStack $requestStack = null,
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
            new TwigFunction('sulu_user_loggedin_and_preview', [$this, 'isUserLoggedInAndPreview']),
            new TwigFunction('sulu_preview_block_focus_script', [$this, 'renderPreviewBlockFocusScript'], ['is_safe' => ['html']]),
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
        $normalizedContext = $this->normalizeContext($context);

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
     * Hydrates the leaf block lists of a nested container block exactly once along `$path`
     * (defaulting to `['blocks']`). Dynamic blocks hidden by their display rules (status,
     * display condition, "Hide When Empty") are removed.
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
    public function hydrateBlockTree(?array $block, array|object|null $context = null, array $path = ['blocks']): ?array
    {
        if (null === $block || [] === $path) {
            return $block;
        }

        $normalizedContext = $this->normalizeContext($context);

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

            $hydratedBlocks = $this->hydrateBlocks($leafBlocks, \array_merge($context, ['_nested_block' => true]));
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

        $normalizedContext = $this->normalizeContext($context);

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

        $normalizedContext = $this->normalizeContext($context);

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

        $normalizedContext = $this->normalizeContext($context);

        return $this->blockRenderer->renderBlocks($blocks, $normalizedContext);
    }

    /**
     * Checks whether the current request is rendered inside the Sulu Admin Live Preview iframe.
     * Drop-in compatible with `alengodev/SuluPreviewBlockFocusBundle`'s `sulu_user_loggedin_and_preview()`.
     */
    public function isUserLoggedInAndPreview(?Request $request = null): bool
    {
        $activeRequest = $request ?? $this->requestStack?->getCurrentRequest();
        if (null === $activeRequest) {
            return false;
        }

        $isPreview = (bool) ($activeRequest->attributes->get('preview') ?? false);
        if (!$isPreview) {
            return false;
        }

        if ($activeRequest->headers->has('referer')) {
            $refererHeader = (string) $activeRequest->headers->get('referer', '');
            $refererPath = '' !== $refererHeader ? (string) (\parse_url($refererHeader, \PHP_URL_PATH) ?? '') : '';

            return '/admin' === $refererPath || \str_starts_with($refererPath, '/admin/');
        }

        return true;
    }

    /**
     * Returns the Sulu Live Preview Block Focus `<script>` tag.
     */
    public function renderPreviewBlockFocusScript(): string
    {
        return BlockRenderer::getPreviewBlockFocusScript();
    }

    /**
     * @param array<string, mixed>|object|null $context
     *
     * @return array<string, mixed>
     */
    private function normalizeContext(array|object|null $context): array
    {
        if (\is_array($context)) {
            return $context;
        }

        if ($context instanceof \stdClass) {
            /** @var array<string, mixed> $cast */
            $cast = (array) $context;

            return $cast;
        }

        if ($context instanceof \Traversable) {
            /** @var array<string, mixed> $iterated */
            $iterated = \iterator_to_array($context);

            return $iterated;
        }

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeBlock(mixed $block): ?array
    {
        return SuluDataNormalizer::normalizeBlock($block);
    }
}
