<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Renderer;

/**
 * Contract for rendering a processed collection of Sulu CMS blocks.
 * UI framework bundles can implement this interface to apply framework-specific
 * collection strategies (such as auto-sectioning or grid wrapping).
 */
interface BlockCollectionRendererInterface
{
    /**
     * @param list<array<string, mixed>> $blocks Processed, visible blocks from BlockTransformPipeline
     * @param array<string, mixed> $context Render context
     */
    public function renderCollection(array $blocks, array $context = []): string;
}
