<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Renderer;

/**
 * Default UI-framework-agnostic block collection renderer.
 * Renders each block sequentially without imposing any container or wrapper markup.
 */
final class DefaultBlockCollectionRenderer implements BlockCollectionRendererInterface
{
    public function __construct(
        private readonly BlockRenderer $blockRenderer,
    ) {
    }

    public function renderCollection(array $blocks, array $context = []): string
    {
        $output = '';
        foreach ($blocks as $block) {
            $output .= $this->blockRenderer->renderBlock($block, $context);
        }

        return $output;
    }
}
