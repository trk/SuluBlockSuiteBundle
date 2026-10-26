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
            'blocks/fixture_hero.html.twig' => 'APP:{{ block.title }}',
            '@ThemeChildBlocks/blocks/fixture_hero.html.twig' => 'CHILD:{{ block.title }}',
            '@ThemeChildBlocks/blocks/fixture_card.html.twig' => 'CHILD_CARD:{{ block.title }}',
            '@ThemeBaseBlocks/blocks/fixture_card.html.twig' => 'BASE_CARD:{{ block.title }}',
            '@ThemeBaseBlocks/blocks/fixture_quote.html.twig' => 'BASE_QUOTE:{{ block.title }}',
            '@SuluBlockSuite/blocks/reusable.html.twig' => 'ENGINE_REUSABLE',
        ]);
        $twig = new Environment($loader);
        $pipeline = new BlockTransformPipeline([new DefaultAttributesTransformer()]);

        // Pass unordered priority map: BlockRenderer sorts descending (1000 -> 200 -> 100 -> 0)
        $renderer = new BlockRenderer(
            $twig,
            $pipeline,
            [
                '@ThemeBaseBlocks/blocks' => 100,
                '@ThemeChildBlocks/blocks' => 200,
            ],
        );

        // App override (1000) wins over child (200) and base (100)
        $this->assertSame('blocks/fixture_hero.html.twig', $renderer->resolveBlockTemplate('fixture_hero'));
        // Child theme (200) wins over base theme (100) when app override does not exist
        $this->assertSame('@ThemeChildBlocks/blocks/fixture_card.html.twig', $renderer->resolveBlockTemplate('fixture_card'));
        // Base theme (100) wins when neither app nor child theme defines it
        $this->assertSame('@ThemeBaseBlocks/blocks/fixture_quote.html.twig', $renderer->resolveBlockTemplate('fixture_quote'));
        // Engine fallback (0) wins when neither app nor UI framework defines it
        $this->assertSame('@SuluBlockSuite/blocks/reusable.html.twig', $renderer->resolveBlockTemplate('reusable'));
        // Missing template returns null gracefully
        $this->assertNull($renderer->resolveBlockTemplate('non_existent_block'));
    }

    public function testRenderBlockProcessesPipelineAndSkipsHiddenOrMissingBlocks(): void
    {
        $loader = new ArrayLoader([
            '@SuluBlockSuite/blocks/fixture_text.html.twig' => '<div id="{{ block._id|default(\'\') }}">{{ block.text }}</div>',
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
            'type' => 'fixture_text',
            'text' => 'Hello Engine',
            'props' => '{"id":"intro-text"}',
        ]);
        $this->assertSame('<div id="intro-text">Hello Engine</div>', $html);

        // Disabled block is skipped
        $disabledHtml = $renderer->renderBlock([
            'type' => 'fixture_text',
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

    public function testRenderBlockInjectsRootAttributesAndScopedCssAutomatically(): void
    {
        $loader = new ArrayLoader([
            '@SuluBlockSuite/blocks/box.html.twig' => '<div class="box">{{ block.title }}</div>',
        ]);
        $twig = new Environment($loader);
        $pipeline = new BlockTransformPipeline([new DefaultAttributesTransformer()]);

        $renderer = new BlockRenderer(
            $twig,
            $pipeline,
            ['@SuluBlockSuite/blocks'],
        );

        $html = $renderer->renderBlock([
            'type' => 'box',
            'title' => 'Scoped Box',
            'props' => [
                'id' => 'promo-box',
                'attributes' => "data-campaign=\"autumn\"\naria-label=\"Promo\"",
                'css' => '.el-element { border: 1px solid blue; }',
            ],
        ]);

        $this->assertStringStartsWith('<style>#promo-box { border: 1px solid blue; }</style>', $html);
        $this->assertStringContainsString('<div class="box" id="promo-box" data-campaign="autumn" aria-label="Promo">Scoped Box</div>', $html);
    }

    public function testRenderBlockPreventsInfiniteReusableBlockCycle(): void
    {
        /** @var array{renderer: ?BlockRenderer} $box */
        $box = ['renderer' => null];
        $loader = new ArrayLoader([
            '@SuluBlockSuite/blocks/reusable.html.twig' => 'REUSABLE',
        ]);
        $twig = $this->createStub(Environment::class);
        $twig->method('getLoader')->willReturn($loader);
        $twig->method('render')->willReturnCallback(static function (string $name, array $context) use (&$box): string {
            if (null !== $box['renderer']) {
                /** @var array<string, mixed> $block */
                $block = $context['block'] ?? [];

                return 'START:' . $box['renderer']->renderBlock($block) . ':END';
            }

            return '';
        });

        $renderer = new BlockRenderer($twig);
        $box['renderer'] = $renderer;
        $output = $renderer->renderBlock([
            'type' => 'reusable',
            'snippet' => ['uuid' => 'reusable-self-cycle-1'],
        ]);

        $this->assertSame('START::END', $output);
    }

    public function testRenderBlockRendersSummaryAndEmptyStateTemplates(): void
    {
        $loader = new ArrayLoader([
            '@SuluBlockSuite/blocks/grid.html.twig' => '<div class="grid">{{ block.items|length }} items</div>',
            '@SuluBlockSuite/blocks/_summary.html.twig' => '<div class="summary-{{ summary_position }}">{{ summary.formatted_summary }}</div>',
            '@SuluBlockSuite/blocks/_empty_state.html.twig' => '<div class="empty-state">{{ empty_state.message }}</div>',
        ]);
        $twig = new Environment($loader);
        $renderer = new BlockRenderer($twig, null, ['@SuluBlockSuite/blocks']);

        $withSummary = $renderer->renderBlock([
            'type' => 'grid',
            'items' => [['title' => 'A'], ['title' => 'B']],
            '_summary' => [
                'formatted_summary' => 'Showing 2 of 200 yachts',
                'position' => 'both',
                'total_items' => 200,
                'unfiltered_total' => 500,
                'showing_count' => 2,
                'is_filtered' => true,
            ],
        ]);

        $this->assertSame(
            '<div class="summary-top">Showing 2 of 200 yachts</div><div class="grid">2 items</div><div class="summary-bottom">Showing 2 of 200 yachts</div>',
            $withSummary,
        );

        $withEmptyState = $renderer->renderBlock([
            'type' => 'grid',
            'items' => [],
            '_empty_state' => [
                'message' => 'No yachts found matching your criteria.',
                'is_filtered' => true,
            ],
        ]);

        $this->assertSame(
            '<div class="grid">0 items</div><div class="empty-state">No yachts found matching your criteria.</div>',
            $withEmptyState,
        );
    }

    public function testRenderBlocksInPreviewModeInjectsDataBlockIdAndPreviewFocusScriptOnce(): void
    {
        $loader = new ArrayLoader([
            '@SuluBlockSuite/blocks/hero.html.twig' => '<section class="hero">{{ block.title }}</section>',
            '@SuluBlockSuite/blocks/text.html.twig' => '<div class="text">{{ block.title }}</div>',
        ]);
        $twig = new Environment($loader);
        $pipeline = new BlockTransformPipeline([new DefaultAttributesTransformer()]);
        $renderer = new BlockRenderer($twig, $pipeline, ['@SuluBlockSuite/blocks']);

        $blocks = [
            ['type' => 'hero', '_id' => 'blk11111', 'title' => 'Hero'],
            ['type' => 'text', '_id' => 'blk22222', 'title' => 'Text', 'props' => ['id' => 'custom-text-id']],
        ];

        // 1. Public website render: no data-block-id and no preview script
        $publicHtml = $renderer->renderBlocks($blocks, []);
        $this->assertStringNotContainsString('data-block-id', $publicHtml);
        $this->assertStringNotContainsString('sulu-preview-block-click', $publicHtml);

        // 2. Sulu Preview render: both blocks get data-block-id, and preview focus script is appended exactly once
        $renderer->reset();
        $previewHtml = $renderer->renderBlocks($blocks, ['preview' => true]);
        $this->assertStringContainsString('<section class="hero" data-block-id="blk11111">Hero</section>', $previewHtml);
        $this->assertStringContainsString('<div class="text" id="custom-text-id" data-block-id="blk22222">Text</div>', $previewHtml);
        $this->assertSame(1, \substr_count($previewHtml, 'data-sulu-preview-block-focus="1"'));
        $this->assertStringContainsString('sulu-preview-block-click', $previewHtml);
    }
}
