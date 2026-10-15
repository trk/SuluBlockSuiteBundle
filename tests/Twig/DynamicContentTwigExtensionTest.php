<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Transformer\BlockTransformerInterface;
use Totoglu\SuluBlockSuiteBundle\Transformer\BlockTransformPipeline;
use Totoglu\SuluBlockSuiteBundle\Transformer\BlockTransformPipelineInterface;
use Totoglu\SuluBlockSuiteBundle\Twig\DynamicContentTwigExtension;

class DynamicContentTwigExtensionTest extends TestCase
{
    public function testGetFunctionsAndFilters(): void
    {
        $pipeline = $this->createStub(BlockTransformPipelineInterface::class);
        $extension = new DynamicContentTwigExtension($pipeline);

        $functionNames = \array_map(static fn ($f) => $f->getName(), $extension->getFunctions());
        $this->assertContains('sulu_block_suite_hydrate_blocks', $functionNames);
        $this->assertContains('hydrate_blocks', $functionNames);
        $this->assertContains('sulu_block_suite_transform_block', $functionNames);
        $this->assertContains('block_transform', $functionNames);

        $filterNames = \array_map(static fn ($f) => $f->getName(), $extension->getFilters());
        $this->assertContains('sulu_block_suite_hydrate_blocks', $filterNames);
        $this->assertContains('hydrate_blocks', $filterNames);
        $this->assertContains('sulu_block_suite_transform_block', $filterNames);
        $this->assertContains('block_transform', $filterNames);
    }

    public function testHydrateBlocksDelegatesToPipeline(): void
    {
        $pipeline = $this->createMock(BlockTransformPipelineInterface::class);
        $extension = new DynamicContentTwigExtension($pipeline);

        $rawBlocks = [
            ['type' => 'card', 'title' => 'Static Card'],
            ['type' => 'headline', 'title' => 'Dynamic Headline'],
        ];

        $context = [
            'content' => ['title' => 'Page Title'],
            'locale' => 'en',
        ];

        $expectedHydrated = [
            ['type' => 'card', 'title' => 'Static Card'],
            ['type' => 'headline', 'title' => 'Page Title'],
        ];

        $pipeline->expects($this->once())
            ->method('processCollection')
            ->with($rawBlocks, $context)
            ->willReturn($expectedHydrated);

        $result = $extension->hydrateBlocks($rawBlocks, $context);

        $this->assertSame($expectedHydrated, $result);
    }

    public function testHydrateBlocksFiltersOutNonArrayElements(): void
    {
        $pipeline = $this->createMock(BlockTransformPipelineInterface::class);
        $extension = new DynamicContentTwigExtension($pipeline);

        $rawBlocks = [
            ['type' => 'card', 'title' => 'Valid Card'],
            'invalid-string-block',
            null,
        ];

        $pipeline->expects($this->once())
            ->method('processCollection')
            ->with([['type' => 'card', 'title' => 'Valid Card']], [])
            ->willReturn([['type' => 'card', 'title' => 'Valid Card']]);

        $result = $extension->hydrateBlocks($rawBlocks);

        $this->assertSame([['type' => 'card', 'title' => 'Valid Card']], $result);
    }

    public function testTransformBlockHydratesSingleBlock(): void
    {
        $pipeline = $this->createMock(BlockTransformPipelineInterface::class);
        $extension = new DynamicContentTwigExtension($pipeline);

        $block = ['type' => 'headline', 'title' => 'Raw'];
        $context = ['content' => ['title' => 'Dynamic Title']];

        $pipeline->expects($this->once())
            ->method('process')
            ->with($block, $context)
            ->willReturn(['type' => 'headline', 'title' => 'Dynamic Title']);

        $transformed = $extension->transformBlock($block, $context);

        $this->assertSame(['type' => 'headline', 'title' => 'Dynamic Title'], $transformed);
    }

    public function testTransformBlockReturnsNullWhenHidden(): void
    {
        $pipeline = $this->createMock(BlockTransformPipelineInterface::class);
        $extension = new DynamicContentTwigExtension($pipeline);

        $block = ['type' => 'headline', 'title' => 'Raw'];

        $pipeline->expects($this->once())
            ->method('process')
            ->with($block, [])
            ->willReturn(['type' => 'headline', '_hidden' => true]);

        $transformed = $extension->transformBlock($block);

        $this->assertNull($transformed);
    }

    public function testTransformBlockHandlesObjectWithGetContent(): void
    {
        $pipeline = $this->createMock(BlockTransformPipelineInterface::class);
        $extension = new DynamicContentTwigExtension($pipeline);

        $objectBlock = new class {
            /** @return array<string, mixed> */
            public function getContent(): array
            {
                return ['type' => 'button', 'title' => 'Click Me'];
            }
        };

        $pipeline->expects($this->once())
            ->method('process')
            ->with(['type' => 'button', 'title' => 'Click Me'], [])
            ->willReturn(['type' => 'button', 'title' => 'Click Me Transformed']);

        $transformed = $extension->transformBlock($objectBlock);

        $this->assertSame(['type' => 'button', 'title' => 'Click Me Transformed'], $transformed);
    }

    public function testHydrateBlockTreeHydratesLeafBlocksAndKeepsVisibleContainers(): void
    {
        $extension = new DynamicContentTwigExtension(new BlockTransformPipeline([$this->createHidingTransformer()]));

        $section = $this->createSection([
            ['type' => 'headline', 'title' => 'Visible'],
            ['type' => 'text', 'hide' => true],
        ]);

        $result = $extension->hydrateBlockTree($section, [], ['rows', 'columns', 'blocks']);

        self::assertNotNull($result);
        self::assertIsArray($result['rows']);
        self::assertIsArray($result['rows'][0]);
        self::assertIsArray($result['rows'][0]['columns']);
        self::assertIsArray($result['rows'][0]['columns'][0]);
        self::assertSame(
            [['type' => 'headline', 'title' => 'Visible', '_transformed' => true]],
            $result['rows'][0]['columns'][0]['blocks'],
        );
        self::assertSame('section', $result['type']);
    }

    public function testHydrateBlockTreeCollapsesContainerWhenEveryLeafBlockIsHidden(): void
    {
        $extension = new DynamicContentTwigExtension(new BlockTransformPipeline([$this->createHidingTransformer()]));

        $section = $this->createSection([
            ['type' => 'image', 'hide' => true],
            ['type' => 'text', 'hide' => true],
        ]);

        self::assertNull($extension->hydrateBlockTree($section, [], ['rows', 'columns', 'blocks']));
    }

    public function testHydrateBlockTreeKeepsContainersWithoutLeafBlocks(): void
    {
        $extension = new DynamicContentTwigExtension(new BlockTransformPipeline([$this->createHidingTransformer()]));

        $spacer = $this->createSection([]);

        self::assertNotNull($extension->hydrateBlockTree($spacer, [], ['rows', 'columns', 'blocks']));
        self::assertNull($extension->hydrateBlockTree(null, [], ['rows', 'columns', 'blocks']));
        self::assertSame($spacer, $extension->hydrateBlockTree($spacer, []));
    }

    /**
     * @param list<array<string, mixed>> $blocks
     *
     * @return array<string, mixed>
     */
    private function createSection(array $blocks): array
    {
        return [
            'type' => 'section',
            'rows' => [
                [
                    'type' => 'row_item',
                    'columns' => [
                        ['type' => 'column_item', 'blocks' => $blocks],
                    ],
                ],
            ],
        ];
    }

    private function createHidingTransformer(): BlockTransformerInterface
    {
        return new class implements BlockTransformerInterface {
            public static function getDefaultPriority(): int
            {
                return 100;
            }

            public function supports(array $block, array $context): bool
            {
                return true;
            }

            public function transform(array $block, array $context): array
            {
                if (true === ($block['hide'] ?? false)) {
                    $block['_hidden'] = true;

                    return $block;
                }

                $block['_transformed'] = true;

                return $block;
            }
        };
    }
}
