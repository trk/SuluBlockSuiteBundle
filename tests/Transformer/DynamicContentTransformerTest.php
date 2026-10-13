<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Transformer;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentHydratorInterface;
use Totoglu\SuluBlockSuiteBundle\Transformer\DynamicContentTransformer;

final class DynamicContentTransformerTest extends TestCase
{
    public function testTransformHydratesBlockSuccessfully(): void
    {
        $hydrator = $this->createMock(DynamicContentHydratorInterface::class);
        $transformer = new DynamicContentTransformer($hydrator);

        $block = ['type' => 'card', 'title' => 'Static'];
        $context = ['page' => ['title' => 'Dynamic Page']];

        $hydrator->expects($this->once())
            ->method('hydrateBlock')
            ->with($block, ['title' => 'Dynamic Page'])
            ->willReturn([['type' => 'card', 'title' => 'Dynamic Page']]);

        $result = $transformer->transform($block, $context);

        $this->assertSame('Dynamic Page', $result['title']);
        $this->assertTrue($result['_dynamic_content_resolved']);
    }

    public function testTransformMarksHiddenWhenHydratorReturnsEmpty(): void
    {
        $hydrator = $this->createMock(DynamicContentHydratorInterface::class);
        $transformer = new DynamicContentTransformer($hydrator);

        $block = ['type' => 'card', 'title' => 'Static'];
        $context = ['page' => ['status' => 'draft']];

        $hydrator->expects($this->once())
            ->method('hydrateBlock')
            ->willReturn([]);

        $result = $transformer->transform($block, $context);

        $this->assertTrue($result['_hidden']);
    }
}
