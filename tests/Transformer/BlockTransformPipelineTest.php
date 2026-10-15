<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Transformer;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Transformer\BlockTransformerInterface;
use Totoglu\SuluBlockSuiteBundle\Transformer\BlockTransformPipeline;

final class BlockTransformPipelineTest extends TestCase
{
    public function testPipelineExecutesInDescendingPriorityOrder(): void
    {
        $log = [];

        $transformerLow = new class($log) implements BlockTransformerInterface {
            /** @param list<string> $log */
            public function __construct(public array &$log)
            {
            }

            public static function getDefaultPriority(): int
            {
                return 10;
            }

            public function supports(array $block, array $context): bool
            {
                return true;
            }

            public function transform(array $block, array $context): array
            {
                $this->log[] = 'low';
                /** @var list<string> $trail */
                $trail = isset($block['trail']) && \is_array($block['trail']) ? $block['trail'] : [];
                $trail[] = 'low';
                $block['trail'] = $trail;

                return $block;
            }
        };

        $transformerHigh = new class($log) implements BlockTransformerInterface {
            /** @param list<string> $log */
            public function __construct(public array &$log)
            {
            }

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
                $this->log[] = 'high';
                /** @var list<string> $trail */
                $trail = isset($block['trail']) && \is_array($block['trail']) ? $block['trail'] : [];
                $trail[] = 'high';
                $block['trail'] = $trail;

                return $block;
            }
        };

        $transformerMid = new class($log) implements BlockTransformerInterface {
            /** @param list<string> $log */
            public function __construct(public array &$log)
            {
            }

            public static function getDefaultPriority(): int
            {
                return 50;
            }

            public function supports(array $block, array $context): bool
            {
                return true;
            }

            public function transform(array $block, array $context): array
            {
                $this->log[] = 'mid';
                /** @var list<string> $trail */
                $trail = isset($block['trail']) && \is_array($block['trail']) ? $block['trail'] : [];
                $trail[] = 'mid';
                $block['trail'] = $trail;

                return $block;
            }
        };

        $pipeline = new BlockTransformPipeline([$transformerLow, $transformerHigh, $transformerMid]);

        $result = $pipeline->process(['type' => 'card', 'trail' => []]);

        $this->assertSame(['high', 'mid', 'low'], $log);
        $this->assertSame(['high', 'mid', 'low'], $result['trail']);
    }

    public function testPipelineSkipsUnsupportedTransformers(): void
    {
        $transformerUnsupported = new class implements BlockTransformerInterface {
            public static function getDefaultPriority(): int
            {
                return 100;
            }

            public function supports(array $block, array $context): bool
            {
                return 'hero' === ($block['type'] ?? '');
            }

            public function transform(array $block, array $context): array
            {
                $block['hero_applied'] = true;

                return $block;
            }
        };

        $pipeline = new BlockTransformPipeline([$transformerUnsupported]);

        $result = $pipeline->process(['type' => 'card']);
        $this->assertArrayNotHasKey('hero_applied', $result);

        $resultHero = $pipeline->process(['type' => 'hero']);
        $this->assertTrue($resultHero['hero_applied']);
    }

    public function testProcessCollection(): void
    {
        $transformer = new class implements BlockTransformerInterface {
            public static function getDefaultPriority(): int
            {
                return 50;
            }

            public function supports(array $block, array $context): bool
            {
                return true;
            }

            public function transform(array $block, array $context): array
            {
                $block['processed'] = true;

                return $block;
            }
        };

        $pipeline = new BlockTransformPipeline([$transformer]);
        $results = $pipeline->processCollection([
            ['type' => 'card'],
            ['type' => 'headline'],
        ]);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]['processed']);
        $this->assertTrue($results[1]['processed']);
        $this->assertTrue($results[0]['_pipeline_processed']);
        $this->assertTrue($results[1]['_pipeline_processed']);
    }

    public function testProcessAndProcessCollectionSkipAlreadyProcessedBlocks(): void
    {
        $transformer = new class implements BlockTransformerInterface {
            public int $calls = 0;

            public static function getDefaultPriority(): int
            {
                return 50;
            }

            public function supports(array $block, array $context): bool
            {
                return true;
            }

            public function transform(array $block, array $context): array
            {
                ++$this->calls;

                return $block;
            }
        };

        $pipeline = new BlockTransformPipeline([$transformer]);

        $first = $pipeline->process(['type' => 'card']);
        $this->assertTrue($first['_pipeline_processed']);
        $this->assertSame(1, $transformer->calls);

        $second = $pipeline->process($first);
        $this->assertSame(1, $transformer->calls);
        $this->assertSame($first, $second);

        $collection = $pipeline->processCollection([$first, ['type' => 'headline']]);
        $this->assertCount(2, $collection);
        $this->assertSame(2, $transformer->calls);
    }
}
