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

    public function testPreservesSulu3TwigContextWithResource(): void
    {
        $hydrator = $this->createMock(DynamicContentHydratorInterface::class);
        $transformer = new DynamicContentTransformer($hydrator);

        $block = ['type' => 'headline', 'title' => 'Static'];
        $resourceStub = new \stdClass();
        $context = [
            'content' => ['title' => 'About us'],
            'resource' => $resourceStub,
            'view' => ['url' => '/about-us'],
        ];

        $hydrator->expects($this->once())
            ->method('hydrateBlock')
            ->with($block, $context)
            ->willReturn([['type' => 'headline', 'title' => 'Website']]);

        $result = $transformer->transform($block, $context);

        $this->assertSame('Website', $result['title']);
        $this->assertTrue($result['_dynamic_content_resolved']);
        $this->assertFalse($transformer->supports($result, $context));
    }
}
