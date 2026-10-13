<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Twig;

use PHPUnit\Framework\TestCase;
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
}
