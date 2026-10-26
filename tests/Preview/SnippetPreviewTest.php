<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Preview;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Sulu\Content\Application\ContentAggregator\ContentAggregatorInterface;
use Sulu\Content\Application\ContentResolver\ContentResolverInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Page\Domain\Model\PageDimensionContentInterface;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Totoglu\SuluBlockSuiteBundle\Preview\PageLayoutPreviewResolver;
use Totoglu\SuluBlockSuiteBundle\Template\TemplateKeys;

final class SnippetPreviewTest extends TestCase
{
    public function testPageLayoutPreviewResolverAutomaticallyResolvesTargetTemplatePageAndSkipsOverriddenPages(): void
    {
        $overriddenPageDim = $this->createStub(PageDimensionContentInterface::class);
        $overriddenPageDim->method('getVersion')->willReturn(DimensionContentInterface::CURRENT_VERSION);
        $overriddenPageDim->method('getStage')->willReturn(DimensionContentInterface::STAGE_LIVE);
        $overriddenPageDim->method('getLocale')->willReturn('en');
        $overriddenPageDim->method('getTemplateData')->willReturn([
            'layout' => 'other-custom-layout-uuid',
        ]);

        $overriddenPage = $this->createStub(PageInterface::class);
        $overriddenPage->method('getUuid')->willReturn('page-overridden-uuid');
        $overriddenPage->method('getWebspaceKey')->willReturn('totoglu');
        $overriddenPage->method('getDimensionContents')->willReturn(new ArrayCollection([$overriddenPageDim]));

        $matchingPageDim = $this->createStub(PageDimensionContentInterface::class);
        $matchingPageDim->method('getVersion')->willReturn(DimensionContentInterface::CURRENT_VERSION);
        $matchingPageDim->method('getStage')->willReturn(DimensionContentInterface::STAGE_LIVE);
        $matchingPageDim->method('getLocale')->willReturn('en');
        $matchingPageDim->method('getTemplateKey')->willReturn('homepage');
        $matchingPageDim->method('getTemplateData')->willReturn([]);

        $matchingPage = $this->createStub(PageInterface::class);
        $matchingPage->method('getUuid')->willReturn('page-uuid-123');
        $matchingPage->method('getWebspaceKey')->willReturn('totoglu');
        $matchingPage->method('getDimensionContents')->willReturn(new ArrayCollection([$matchingPageDim]));

        $pageRepository = $this->createMock(PageRepositoryInterface::class);
        $pageRepository->expects(self::once())
            ->method('findBy')
            ->with(
                [
                    'templateKeys' => ['homepage'],
                    'locale' => 'en',
                    'stage' => DimensionContentInterface::STAGE_LIVE,
                    'limit' => 20,
                ],
                ['id' => 'asc'],
                [PageRepositoryInterface::SELECT_PAGE_CONTENT => []],
            )
            ->willReturn([$overriddenPage, $matchingPage]);

        $contentAggregator = $this->createMock(ContentAggregatorInterface::class);
        $contentAggregator->expects(self::once())
            ->method('aggregate')
            ->with($matchingPage, [
                'locale' => 'en',
                'stage' => DimensionContentInterface::STAGE_LIVE,
            ])
            ->willReturn($matchingPageDim);

        $snippet = $this->createStub(SnippetInterface::class);
        $snippet->method('getUuid')->willReturn('layout-uuid-1');

        $unlocalizedSnippetDim = $this->createStub(SnippetDimensionContentInterface::class);
        $unlocalizedSnippetDim->method('getVersion')->willReturn(DimensionContentInterface::CURRENT_VERSION);
        $unlocalizedSnippetDim->method('getStage')->willReturn(DimensionContentInterface::STAGE_DRAFT);
        $unlocalizedSnippetDim->method('getLocale')->willReturn(null);
        $unlocalizedSnippetDim->method('getTemplateData')->willReturn([
            'webspace' => 'totoglu',
            'target_template' => 'homepage',
        ]);

        $snippetDimensionContent = $this->createStub(SnippetDimensionContentInterface::class);
        $snippetDimensionContent->method('getResource')->willReturn($snippet);
        $snippetDimensionContent->method('getLocale')->willReturn('en');
        $snippetDimensionContent->method('getTemplateKey')->willReturn(TemplateKeys::PAGE_LAYOUT);
        $snippetDimensionContent->method('getTitle')->willReturn('Homepage Default Layout');
        $snippetDimensionContent->method('getTemplateData')->willReturn([
            'blocks' => [
                ['type' => 'navbar', 'brand_title' => 'Totoglu'],
            ],
        ]);

        $snippet->method('getDimensionContents')->willReturn(new ArrayCollection([
            $unlocalizedSnippetDim,
            $snippetDimensionContent,
        ]));

        $contentResolver = $this->createMock(ContentResolverInterface::class);
        $contentResolver->expects(self::once())
            ->method('resolve')
            ->with($matchingPageDim)
            ->willReturn([
                'content' => [
                    'title' => 'Welcome to Totoglu',
                    'headline' => 'Enterprise Sulu CMS',
                    'blocks' => [
                        ['type' => 'hero', 'title' => 'Page Hero'],
                    ],
                ],
                'view' => ['title' => []],
                'extension' => ['seo' => ['title' => 'SEO Title']],
            ]);

        $params = PageLayoutPreviewResolver::enrichPreviewParameters(
            $snippetDimensionContent,
            [
                'content' => [
                    'blocks' => [
                        ['type' => 'navbar', 'brand_title' => 'Totoglu'],
                    ],
                ],
                'view' => [],
            ],
            'totoglu',
            $pageRepository,
            $contentAggregator,
            $contentResolver,
        );

        self::assertTrue($params['_is_page_layout_preview'] ?? false);
        self::assertIsArray($params['content'] ?? null);
        self::assertSame('Welcome to Totoglu', $params['content']['title'] ?? null);
        self::assertSame('Enterprise Sulu CMS', $params['content']['headline'] ?? null);
        self::assertIsArray($params['layout'] ?? null);
        self::assertSame('totoglu', $params['layout']['webspace'] ?? null);
        self::assertSame('homepage', $params['layout']['target_template'] ?? null);
        self::assertTrue($params['layout']['_preview_bypass_webspace'] ?? false);
        self::assertIsArray($params['blocks'] ?? null);
        self::assertCount(1, $params['blocks']);
    }

    public function testPageLayoutPreviewResolverResolvesPageExplicitlyAssignedToCustomLayout(): void
    {
        $assignedPageDim = $this->createStub(PageDimensionContentInterface::class);
        $assignedPageDim->method('getVersion')->willReturn(DimensionContentInterface::CURRENT_VERSION);
        $assignedPageDim->method('getStage')->willReturn(DimensionContentInterface::STAGE_LIVE);
        $assignedPageDim->method('getLocale')->willReturn('en');
        $assignedPageDim->method('getTemplateKey')->willReturn('default');
        $assignedPageDim->method('getTemplateData')->willReturn([
            'layout' => 'custom-layout-uuid',
        ]);

        $assignedPage = $this->createStub(PageInterface::class);
        $assignedPage->method('getUuid')->willReturn('campaign-page-uuid');
        $assignedPage->method('getWebspaceKey')->willReturn('totoglu');
        $assignedPage->method('getDimensionContents')->willReturn(new ArrayCollection([$assignedPageDim]));

        $pageRepository = $this->createMock(PageRepositoryInterface::class);
        $pageRepository->expects(self::once())
            ->method('findBy')
            ->with(
                [
                    'locale' => 'en',
                    'stage' => DimensionContentInterface::STAGE_LIVE,
                    'limit' => 50,
                ],
                ['id' => 'asc'],
                [PageRepositoryInterface::SELECT_PAGE_CONTENT => []],
            )
            ->willReturn([$assignedPage]);

        $contentAggregator = $this->createMock(ContentAggregatorInterface::class);
        $contentAggregator->expects(self::once())
            ->method('aggregate')
            ->with($assignedPage, [
                'locale' => 'en',
                'stage' => DimensionContentInterface::STAGE_LIVE,
            ])
            ->willReturn($assignedPageDim);

        $snippet = $this->createStub(SnippetInterface::class);
        $snippet->method('getUuid')->willReturn('custom-layout-uuid');

        $unlocalizedSnippetDim = $this->createStub(SnippetDimensionContentInterface::class);
        $unlocalizedSnippetDim->method('getVersion')->willReturn(DimensionContentInterface::CURRENT_VERSION);
        $unlocalizedSnippetDim->method('getStage')->willReturn(DimensionContentInterface::STAGE_DRAFT);
        $unlocalizedSnippetDim->method('getLocale')->willReturn(null);
        $unlocalizedSnippetDim->method('getTemplateData')->willReturn([
            'webspace' => 'totoglu',
            'target_template' => '',
        ]);

        $snippetDimensionContent = $this->createStub(SnippetDimensionContentInterface::class);
        $snippetDimensionContent->method('getResource')->willReturn($snippet);
        $snippetDimensionContent->method('getLocale')->willReturn('en');
        $snippetDimensionContent->method('getTemplateKey')->willReturn(TemplateKeys::PAGE_LAYOUT);
        $snippetDimensionContent->method('getTitle')->willReturn('Campaign Landing Layout');
        $snippetDimensionContent->method('getTemplateData')->willReturn([
            'blocks' => [
                ['type' => 'hero', 'title' => 'Campaign Banner'],
            ],
        ]);

        $snippet->method('getDimensionContents')->willReturn(new ArrayCollection([
            $unlocalizedSnippetDim,
            $snippetDimensionContent,
        ]));

        $contentResolver = $this->createMock(ContentResolverInterface::class);
        $contentResolver->expects(self::once())
            ->method('resolve')
            ->with($assignedPageDim)
            ->willReturn([
                'content' => [
                    'title' => 'Summer Campaign 2026',
                    'blocks' => [],
                ],
                'view' => [],
            ]);

        $params = PageLayoutPreviewResolver::enrichPreviewParameters(
            $snippetDimensionContent,
            [
                'content' => [
                    'blocks' => [
                        ['type' => 'hero', 'title' => 'Campaign Banner'],
                    ],
                ],
                'view' => [],
            ],
            'totoglu',
            $pageRepository,
            $contentAggregator,
            $contentResolver,
        );

        self::assertTrue($params['_is_page_layout_preview'] ?? false);
        self::assertIsArray($params['content'] ?? null);
        self::assertSame('Summer Campaign 2026', $params['content']['title'] ?? null);
    }
}
