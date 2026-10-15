<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Search;

use PHPUnit\Framework\TestCase;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Totoglu\SuluBlockSuiteBundle\Search\BlockSuitePageReindexEnhancer;

final class BlockSuitePageReindexEnhancerTest extends TestCase
{
    public function testEnhanceDocumentExtractsNestedSectionRowColumnItemsAndReusableBlockTexts(): void
    {
        $snippetDimension = $this->createStub(SnippetDimensionContentInterface::class);
        $snippetDimension->method('getLocale')->willReturn('en');
        $snippetDimension->method('getStage')->willReturn(DimensionContentInterface::STAGE_LIVE);
        $snippetDimension->method('getVersion')->willReturn(DimensionContentInterface::CURRENT_VERSION);
        $snippetDimension->method('getTemplateKey')->willReturn('reusable_block');
        $snippetDimension->method('getTemplateData')->willReturn([
            'title' => 'Global Banner Reusable Title',
            'blocks' => [
                [
                    'type' => 'headline',
                    'title' => 'Reusable CTA Headline Text',
                    'content' => '<p>Reusable rich text description</p>',
                ],
            ],
        ]);

        $snippet = $this->createStub(SnippetInterface::class);
        $snippet->method('getDimensionContents')->willReturn(
            new \Doctrine\Common\Collections\ArrayCollection([$snippetDimension]),
        );

        $snippetRepository = $this->createMock(SnippetRepositoryInterface::class);
        $snippetRepository->expects(self::once())
            ->method('findOneBy')
            ->with(
                [
                    'uuid' => 'reusable-uuid-001',
                    'locale' => 'en',
                    'stage' => DimensionContentInterface::STAGE_LIVE,
                ],
                [SnippetRepositoryInterface::SELECT_SNIPPET_CONTENT => []],
            )
            ->willReturn($snippet);

        $enhancer = new BlockSuitePageReindexEnhancer($snippetRepository);

        $pageData = [
            'locale' => 'en',
            'templateData' => [
                'blocks' => [
                    [
                        'type' => 'section',
                        'rows' => [
                            [
                                'type' => 'row_item',
                                'columns' => [
                                    [
                                        'type' => 'column_item',
                                        'blocks' => [
                                            [
                                                'type' => 'card',
                                                'title' => 'Enterprise AI Solutions',
                                                'meta' => 'Cloud & Architecture',
                                                'content' => '<p>High performance <strong>scalable</strong> architecture.</p>',
                                                'props' => [
                                                    'card_style' => 'card-default',
                                                    'name' => 'Internal Card Name Should Not Be Indexed',
                                                    'css' => '.el-element { color: red; }',
                                                ],
                                                'buttons' => [
                                                    [
                                                        'type' => 'button',
                                                        'content' => 'Explore Platform',
                                                    ],
                                                ],
                                            ],
                                            [
                                                'type' => 'accordion',
                                                'items' => [
                                                    [
                                                        'type' => 'accordion_item',
                                                        'title' => 'How does Seal indexing work?',
                                                        'content' => '<p>It indexes nested layout blocks seamlessly.</p>',
                                                    ],
                                                ],
                                            ],
                                            [
                                                'type' => 'reusable',
                                                'snippet' => 'reusable-uuid-001',
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

        $document = [
            'id' => 'page-1',
            'title' => 'Homepage',
            'content' => ['Existing root text'],
        ];

        $enhanced = $enhancer->enhanceDocument($pageData, $document);

        self::assertIsArray($enhanced['content']);
        /** @var list<string> $content */
        $content = $enhanced['content'];

        self::assertContains('Existing root text', $content);
        self::assertContains('Enterprise AI Solutions', $content);
        self::assertContains('Cloud & Architecture', $content);
        self::assertContains('High performance scalable architecture.', $content);
        self::assertContains('Explore Platform', $content);
        self::assertContains('How does Seal indexing work?', $content);
        self::assertContains('It indexes nested layout blocks seamlessly.', $content);
        self::assertContains('Global Banner Reusable Title', $content);
        self::assertContains('Reusable CTA Headline Text', $content);
        self::assertContains('Reusable rich text description', $content);

        // Ensure props (technical classes, internal names, CSS) are never leaked into search index
        self::assertNotContains('card-default', $content);
        self::assertNotContains('Internal Card Name Should Not Be Indexed', $content);
        self::assertNotContains('.el-element { color: red; }', $content);
    }

    public function testEnhanceDocumentIndexesTopLevelHeadlineSummaryContentAndImageMediaId(): void
    {
        $enhancer = new BlockSuitePageReindexEnhancer();

        $queryResult = [
            'locale' => 'en',
            'templateData' => [
                'headline' => 'Top Level Display Headline',
                'summary' => 'Top level plain summary text.',
                'content' => '<p>Top level <strong>rich body</strong> content.</p>',
                'image' => ['id' => 42],
                'blocks' => [],
            ],
        ];

        $document = [
            'id' => 'page-top-1',
            'title' => 'Page Title',
            'content' => [],
        ];

        $enhanced = $enhancer->enhanceDocument($queryResult, $document);

        self::assertSame('42', $enhanced['mediaId'] ?? null);
        self::assertIsArray($enhanced['content']);
        /** @var list<string> $content */
        $content = $enhanced['content'];
        self::assertContains('Top Level Display Headline', $content);
        self::assertContains('Top level plain summary text.', $content);
        self::assertContains('Top level rich body content.', $content);
    }
}
