<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Transformer;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Totoglu\SuluBlockSuiteBundle\Layout\PageLayoutProviderInterface;
use Totoglu\SuluBlockSuiteBundle\Transformer\BlockTransformPipeline;
use Totoglu\SuluBlockSuiteBundle\Transformer\DefaultAttributesTransformer;
use Totoglu\SuluBlockSuiteBundle\Transformer\ReusableBlockTransformer;

final class ReusableBlockTransformerTest extends TestCase
{
    public function testExpandsReusableBlocksAndAssignsDistinctSectionGroups(): void
    {
        $pipeline = new BlockTransformPipeline([
            new ReusableBlockTransformer(),
            new DefaultAttributesTransformer(),
        ]);

        $blocks = [
            ['type' => 'headline', 'title' => 'H1'],
            ['type' => 'text', 'content' => 'T1'],
            ['type' => 'image', 'image_url' => '/img1.jpg'],
            [
                'type' => 'section',
                'rows' => [
                    [
                        'columns' => [
                            [
                                'blocks' => [
                                    ['type' => 'image', 'image_url' => '/sec1.jpg'],
                                    ['type' => 'headline', 'title' => 'Sec1 H'],
                                    ['type' => 'text', 'content' => 'Sec1 T'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'reusable',
                'snippet' => [
                    'uuid' => 'reusable-uuid-1',
                    'blocks' => [
                        ['type' => 'headline', 'title' => 'R1 H'],
                        ['type' => 'text', 'content' => 'R1 T'],
                    ],
                ],
            ],
            ['type' => 'image', 'image_url' => '/img2.jpg'],
            ['type' => 'text', 'content' => 'T2'],
            [
                'type' => 'reusable',
                'snippet' => [
                    'uuid' => 'reusable-uuid-2',
                    'blocks' => [
                        [
                            'type' => 'section',
                            'rows' => [
                                [
                                    'columns' => [
                                        [
                                            'blocks' => [
                                                ['type' => 'image', 'image_url' => '/r2.jpg'],
                                                ['type' => 'text', 'content' => 'R2 T'],
                                                ['type' => 'button', 'title' => 'R2 Btn'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $processed = $pipeline->processCollection($blocks);

        self::assertCount(9, $processed);

        // 1..3: Page loose blocks before section (no _section_group)
        self::assertSame('headline', $processed[0]['type']);
        self::assertNull($processed[0]['_section_group'] ?? null);
        self::assertSame('text', $processed[1]['type']);
        self::assertNull($processed[1]['_section_group'] ?? null);
        self::assertSame('image', $processed[2]['type']);
        self::assertNull($processed[2]['_section_group'] ?? null);

        // 4: Page section block
        self::assertSame('section', $processed[3]['type']);
        self::assertNull($processed[3]['_section_group'] ?? null);

        // 5..6: Reusable #1 loose blocks share reusable_1 group
        self::assertSame('headline', $processed[4]['type']);
        self::assertSame('R1 H', $processed[4]['title']);
        self::assertSame('reusable_1', $processed[4]['_section_group'] ?? null);
        self::assertSame('text', $processed[5]['type']);
        self::assertSame('R1 T', $processed[5]['content']);
        self::assertSame('reusable_1', $processed[5]['_section_group'] ?? null);

        // 7..8: Page loose blocks after reusable #1 (no _section_group)
        self::assertSame('image', $processed[6]['type']);
        self::assertNull($processed[6]['_section_group'] ?? null);
        self::assertSame('text', $processed[7]['type']);
        self::assertNull($processed[7]['_section_group'] ?? null);

        // 9: Reusable #2 section block
        self::assertSame('section', $processed[8]['type']);
        self::assertSame('reusable_2', $processed[8]['_section_group'] ?? null);
    }

    public function testLoadsReusableBlockByUuidViaPageLayoutProvider(): void
    {
        $layoutProvider = $this->createMock(PageLayoutProviderInterface::class);
        $layoutProvider->expects(self::once())
            ->method('getReusableBlock')
            ->with('snippet-uuid-99', 'tr')
            ->willReturn([
                'uuid' => 'snippet-uuid-99',
                'blocks' => [
                    ['type' => 'headline', 'title' => 'Loaded From Provider'],
                ],
            ]);

        $pipeline = new BlockTransformPipeline([
            new ReusableBlockTransformer($layoutProvider),
            new DefaultAttributesTransformer(),
        ]);

        $processed = $pipeline->processCollection([
            ['type' => 'reusable', 'snippet' => 'snippet-uuid-99'],
        ], ['locale' => 'tr']);

        self::assertCount(1, $processed);
        self::assertSame('headline', $processed[0]['type']);
        self::assertSame('Loaded From Provider', $processed[0]['title']);
    }

    public function testUnwrapsSectionInsideReusableWhenRenderedInsideColumn(): void
    {
        $pipeline = new BlockTransformPipeline([
            new ReusableBlockTransformer(),
            new DefaultAttributesTransformer(),
        ]);

        $processed = $pipeline->processCollection([
            [
                'type' => 'reusable',
                'snippet' => [
                    'blocks' => [
                        [
                            'type' => 'section',
                            'rows' => [
                                [
                                    'columns' => [
                                        [
                                            'blocks' => [
                                                ['type' => 'image', 'image_url' => '/col.jpg'],
                                                ['type' => 'button', 'title' => 'CTA'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], ['_in_column' => true]);

        self::assertCount(2, $processed);
        self::assertSame('image', $processed[0]['type']);
        self::assertSame('button', $processed[1]['type']);
    }

    public function testUnwrapsNestedReusableInsideSectionAndRecordsSnippetReferences(): void
    {
        $recordedSnippets = [];
        $referenceStore = $this->createMock(ReferenceStoreInterface::class);
        $referenceStore->expects(self::exactly(2))
            ->method('add')
            ->willReturnCallback(static function (string $id, string $resourceKey) use (&$recordedSnippets): void {
                self::assertSame(SnippetInterface::RESOURCE_KEY, $resourceKey);
                $recordedSnippets[] = $id;
            });

        $pipeline = new BlockTransformPipeline([
            new ReusableBlockTransformer(null, $referenceStore),
            new DefaultAttributesTransformer(),
        ]);

        $processed = $pipeline->processCollection([
            [
                'type' => 'reusable',
                'snippet' => [
                    'uuid' => 'outer-snippet-uuid',
                    'blocks' => [
                        [
                            'type' => 'section',
                            'rows' => [
                                [
                                    'columns' => [
                                        [
                                            'blocks' => [
                                                ['type' => 'headline', 'title' => 'Outer Section Headline'],
                                                [
                                                    'type' => 'reusable',
                                                    'snippet' => [
                                                        'uuid' => 'inner-snippet-uuid',
                                                        'blocks' => [
                                                            [
                                                                'type' => 'section',
                                                                'rows' => [
                                                                    [
                                                                        'columns' => [
                                                                            [
                                                                                'blocks' => [
                                                                                    ['type' => 'text', 'content' => 'Inner Nested Text'],
                                                                                    ['type' => 'button', 'title' => 'Inner CTA'],
                                                                                ],
                                                                            ],
                                                                        ],
                                                                    ],
                                                                ],
                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], ['_in_column' => true]);

        self::assertCount(3, $processed);
        self::assertSame('headline', $processed[0]['type']);
        self::assertSame('Outer Section Headline', $processed[0]['title']);
        self::assertSame('reusable_1', $processed[0]['_section_group'] ?? null);

        self::assertSame('text', $processed[1]['type']);
        self::assertSame('Inner Nested Text', $processed[1]['content']);
        self::assertSame('reusable_2', $processed[1]['_section_group'] ?? null);

        self::assertSame('button', $processed[2]['type']);
        self::assertSame('Inner CTA', $processed[2]['title']);
        self::assertSame('reusable_2', $processed[2]['_section_group'] ?? null);

        self::assertSame(['outer-snippet-uuid', 'inner-snippet-uuid'], $recordedSnippets);
    }
}
