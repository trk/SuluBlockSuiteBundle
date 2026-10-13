<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Renderer\BlockCollectionRendererInterface;
use Totoglu\SuluBlockSuiteBundle\Renderer\BlockRenderer;
use Totoglu\SuluBlockSuiteBundle\Renderer\DefaultBlockCollectionRenderer;
use Totoglu\SuluBlockSuiteBundle\Transformer\BlockTransformPipeline;
use Totoglu\SuluBlockSuiteBundle\Transformer\DefaultAttributesTransformer;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class BlockRendererTest extends TestCase
{
    public function testResolveBlockTemplateFollowsNamespacePriority(): void
    {
        $loader = new ArrayLoader([
            'blocks/headline.html.twig' => 'APP:{{ block.title }}',
            '@SuluPrelineBlocks/blocks/headline.html.twig' => 'PRELINE:{{ block.title }}',
            '@SuluPrelineBlocks/blocks/card.html.twig' => 'PRELINE_CARD:{{ block.title }}',
            '@SuluBlockSuite/blocks/reusable.html.twig' => 'ENGINE_REUSABLE',
        ]);
        $twig = new Environment($loader);
        $pipeline = new BlockTransformPipeline([new DefaultAttributesTransformer()]);

        $renderer = new BlockRenderer(
            $twig,
            $pipeline,
            ['blocks', '@SuluPrelineBlocks/blocks', '@SuluBlockSuite/blocks'],
        );

        // App override wins over bundle namespace
        $this->assertSame('blocks/headline.html.twig', $renderer->resolveBlockTemplate('headline'));
        // UI framework namespace wins when app override does not exist
        $this->assertSame('@SuluPrelineBlocks/blocks/card.html.twig', $renderer->resolveBlockTemplate('card'));
        // Engine fallback wins when neither app nor UI framework defines it
        $this->assertSame('@SuluBlockSuite/blocks/reusable.html.twig', $renderer->resolveBlockTemplate('reusable'));
        // Missing template returns null gracefully
        $this->assertNull($renderer->resolveBlockTemplate('non_existent_block'));
    }

    public function testRenderBlockProcessesPipelineAndSkipsHiddenOrMissingBlocks(): void
    {
        $loader = new ArrayLoader([
            '@SuluBlockSuite/blocks/text.html.twig' => '<div id="{{ block._id|default(\'\') }}">{{ block.text }}</div>',
        ]);
        $twig = new Environment($loader);
        $pipeline = new BlockTransformPipeline([new DefaultAttributesTransformer()]);

        $renderer = new BlockRenderer(
            $twig,
            $pipeline,
            ['blocks', '@SuluBlockSuite/blocks'],
        );

        // Active block with props JSON
        $html = $renderer->renderBlock([
            'type' => 'text',
            'text' => 'Hello Engine',
            'props' => '{"id":"intro-text"}',
        ]);
        $this->assertSame('<div id="intro-text">Hello Engine</div>', $html);

        // Disabled block is skipped
        $disabledHtml = $renderer->renderBlock([
            'type' => 'text',
            'text' => 'Hidden',
            'props' => '{"status":"disabled"}',
        ]);
        $this->assertSame('', $disabledHtml);

        // Unregistered block type returns empty string without throwing
        $missingHtml = $renderer->renderBlock([
            'type' => 'unknown_widget',
        ]);
        $this->assertSame('', $missingHtml);
    }

    public function testRenderBlocksUsesCollectionRendererOrDefaultSequentialRenderer(): void
    {
        $loader = new ArrayLoader([
            '@SuluBlockSuite/blocks/item.html.twig' => '[{{ block.label }}]',
        ]);
        $twig = new Environment($loader);
        $pipeline = new BlockTransformPipeline([new DefaultAttributesTransformer()]);

        $renderer = new BlockRenderer(
            $twig,
            $pipeline,
            ['@SuluBlockSuite/blocks'],
        );
        $renderer->setCollectionRenderer(new DefaultBlockCollectionRenderer($renderer));

        $blocks = [
            ['type' => 'item', 'label' => 'First'],
            ['type' => 'item', 'label' => 'Hidden', 'props' => '{"status":"disabled"}'],
            ['type' => 'item', 'label' => 'Second'],
        ];

        $this->assertSame('[First][Second]', $renderer->renderBlocks($blocks));

        // Custom collection renderer (simulating a UI framework wrapper)
        $customCollectionRenderer = new class($renderer) implements BlockCollectionRendererInterface {
            public function __construct(private readonly BlockRenderer $blockRenderer)
            {
            }

            public function renderCollection(array $blocks, array $context = []): string
            {
                $inner = '';
                foreach ($blocks as $block) {
                    $inner .= $this->blockRenderer->renderBlock($block, $context);
                }

                return '<section>' . $inner . '</section>';
            }
        };

        $renderer->setCollectionRenderer($customCollectionRenderer);
        $this->assertSame('<section>[First][Second]</section>', $renderer->renderBlocks($blocks));
    }
}
