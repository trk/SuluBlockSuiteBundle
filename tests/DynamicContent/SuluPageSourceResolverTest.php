<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\DynamicContent;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Sulu\Page\Domain\Model\PageDimensionContentInterface;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\SuluPageSourceResolver;

class SuluPageSourceResolverTest extends TestCase
{
    public function testResolvePageByUuidQueriesRepository(): void
    {
        $pageStub = $this->createStub(PageInterface::class);
        $pageStub->method('getUuid')->willReturn('page-target-123');

        $dimensionContentStub = $this->createStub(PageDimensionContentInterface::class);
        $dimensionContentStub->method('getLocale')->willReturn('en');
        $dimensionContentStub->method('getStage')->willReturn('live');
        $dimensionContentStub->method('getTitle')->willReturn('Target Page Title');
        $dimensionContentStub->method('getExcerptDescription')->willReturn('Target excerpt description');
        $dimensionContentStub->method('getExcerptImage')->willReturn(['id' => 12, 'url' => '/media/12.jpg']);
        $dimensionContentStub->method('getTemplateData')->willReturn([
            'title' => 'Target Page Title',
            'custom_attr' => 'custom-val',
        ]);

        $pageStub->method('getDimensionContents')->willReturn(new ArrayCollection([$dimensionContentStub]));

        $pageRepositoryMock = $this->createMock(PageRepositoryInterface::class);
        $pageRepositoryMock->expects($this->once())
            ->method('findOneBy')
            ->with([
                'uuid' => 'page-target-123',
                'locale' => 'en',
                'stage' => 'live',
            ])
            ->willReturn($pageStub);

        $resolver = new SuluPageSourceResolver($pageRepositoryMock);

        $result = $resolver->resolvePage('page-target-123', 'en');

        $this->assertNotNull($result);
        $this->assertSame('page-target-123', $result['id']);
        $this->assertSame('Target Page Title', $result['title']);
        $this->assertSame('Target Page Title', $result['headline']);
        $this->assertSame('Target excerpt description', $result['summary']);
        $this->assertSame('custom-val', $result['custom_attr']);
    }

    public function testResolvePageByObjectNormalizesPageEntity(): void
    {
        $pageStub = $this->createStub(PageInterface::class);
        $pageStub->method('getUuid')->willReturn('page-direct-456');

        $dimensionContentStub = $this->createStub(PageDimensionContentInterface::class);
        $dimensionContentStub->method('getLocale')->willReturn('en');
        $dimensionContentStub->method('getStage')->willReturn('live');
        $dimensionContentStub->method('getTitle')->willReturn('Direct Page');
        $dimensionContentStub->method('getTemplateData')->willReturn(['title' => 'Direct Page']);

        $pageStub->method('getDimensionContents')->willReturn(new ArrayCollection([$dimensionContentStub]));

        $resolver = new SuluPageSourceResolver();

        $result = $resolver->resolvePage($pageStub, 'en');

        $this->assertNotNull($result);
        $this->assertSame('page-direct-456', $result['id']);
        $this->assertSame('Direct Page', $result['title']);
    }

    public function testResolvePageReturnsNullWhenNotFound(): void
    {
        $pageRepositoryStub = $this->createStub(PageRepositoryInterface::class);
        $pageRepositoryStub->method('findOneBy')->willReturn(null);

        $resolver = new SuluPageSourceResolver($pageRepositoryStub);

        $result = $resolver->resolvePage('non-existent', 'en');

        $this->assertNull($result);
    }

    public function testResolvePageFallsBackToPreviewRequestStack(): void
    {
        $pageStub = $this->createStub(PageInterface::class);
        $pageStub->method('getUuid')->willReturn('preview-uuid-789');

        $dimensionContentStub = $this->createStub(PageDimensionContentInterface::class);
        $dimensionContentStub->method('getLocale')->willReturn('en');
        $dimensionContentStub->method('getStage')->willReturn('live');
        $dimensionContentStub->method('getTitle')->willReturn('Preview Page Title');
        $dimensionContentStub->method('getTemplateData')->willReturn(['title' => 'Preview Page Title']);
        $pageStub->method('getDimensionContents')->willReturn(new ArrayCollection([$dimensionContentStub]));

        $pageRepositoryMock = $this->createMock(PageRepositoryInterface::class);
        $pageRepositoryMock->expects($this->once())
            ->method('findOneBy')
            ->with([
                'uuid' => 'preview-uuid-789',
                'locale' => 'en',
                'stage' => 'live',
            ])
            ->willReturn($pageStub);

        $request = new Request(query: ['provider' => 'pages', 'id' => 'preview-uuid-789', 'locale' => 'en']);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $resolver = new SuluPageSourceResolver($pageRepositoryMock, null, $requestStack);

        $result = $resolver->resolvePage(null, 'en');

        $this->assertNotNull($result);
        $this->assertSame('preview-uuid-789', $result['id']);
        $this->assertSame('Preview Page Title', $result['title']);
    }

    public function testResolveCurrentPageFromContextWithContent(): void
    {
        $resolver = new SuluPageSourceResolver();

        $context = [
            'content' => [
                'id' => 'draft-uuid-1',
                'title' => 'Draft Page Title',
                'headline' => 'Custom Headline',
                'summary' => 'Draft summary',
            ],
            'view' => [
                'url' => '/preview/draft-1',
            ],
        ];

        $result = $resolver->resolvePage($context, 'en');

        $this->assertNotNull($result);
        $this->assertSame('draft-uuid-1', $result['id']);
        $this->assertSame('Draft Page Title', $result['title']);
        $this->assertSame('Custom Headline', $result['headline']);
        $this->assertSame('Draft summary', $result['summary']);
        $this->assertSame('/preview/draft-1', $result['url']);
    }

    public function testResolveChildrenQueriesRepositoryWithParentId(): void
    {
        $child1 = $this->createStub(PageInterface::class);
        $child1->method('getUuid')->willReturn('child-1');
        $child1Content = $this->createStub(PageDimensionContentInterface::class);
        $child1Content->method('getLocale')->willReturn('en');
        $child1Content->method('getTitle')->willReturn('Child 1 Title');
        $child1Content->method('getTemplateData')->willReturn(['title' => 'Child 1 Title']);
        $child1->method('getDimensionContents')->willReturn(new ArrayCollection([$child1Content]));

        $child2 = $this->createStub(PageInterface::class);
        $child2->method('getUuid')->willReturn('child-2');
        $child2Content = $this->createStub(PageDimensionContentInterface::class);
        $child2Content->method('getLocale')->willReturn('en');
        $child2Content->method('getTitle')->willReturn('Child 2 Title');
        $child2Content->method('getTemplateData')->willReturn(['title' => 'Child 2 Title']);
        $child2->method('getDimensionContents')->willReturn(new ArrayCollection([$child2Content]));

        $pageRepositoryMock = $this->createMock(PageRepositoryInterface::class);
        $pageRepositoryMock->expects($this->once())
            ->method('findBy')
            ->with($this->callback(static function (array $filters): bool {
                return ($filters['parentId'] ?? null) === 'parent-uuid'
                    && ($filters['limit'] ?? null) === 5;
            }))
            ->willReturn(new \ArrayIterator([$child1, $child2]));

        $resolver = new SuluPageSourceResolver($pageRepositoryMock);

        $children = $resolver->resolveChildren('parent-uuid', 'en', 5);

        $this->assertCount(2, $children);
        $this->assertSame('child-1', $children[0]['id']);
        $this->assertSame('Child 1 Title', $children[0]['title']);
        $this->assertSame('child-2', $children[1]['id']);
        $this->assertSame('Child 2 Title', $children[1]['title']);
    }

    public function testResolveQueryRespectsLimitAndSort(): void
    {
        $pageRepositoryMock = $this->createMock(PageRepositoryInterface::class);
        $pageRepositoryMock->expects($this->once())
            ->method('findBy')
            ->with(
                $this->callback(static function (array $filters): bool {
                    return ($filters['parentId'] ?? null) === 'blog-root'
                        && ($filters['webspaceKey'] ?? null) === 'totoglu'
                        && ($filters['limit'] ?? null) === 3;
                }),
                $this->callback(static function (array $sortBy): bool {
                    return ($sortBy['created'] ?? null) === 'desc';
                }),
            )
            ->willReturn(new \ArrayIterator([]));

        $resolver = new SuluPageSourceResolver($pageRepositoryMock);

        $result = $resolver->resolveQuery(
            [
                'parent_id' => 'blog-root',
                'webspace_key' => 'totoglu',
                'limit' => 3,
                'sort_by' => 'created',
                'sort_order' => 'desc',
            ],
            'en',
        );

        $this->assertSame([], $result);
    }

    public function testResolveCurrentPagePrioritizesPageOverContentWhenBothExist(): void
    {
        $resolver = new SuluPageSourceResolver();

        // In layout snippet context, "content" is snippet, but "page" is real page
        $context = [
            'content' => [
                'id' => 'snippet-uuid',
                'title' => 'Snippet Title',
            ],
            'page' => [
                'id' => 'actual-page-uuid',
                'title' => 'Actual Page Title',
                'headline' => 'Actual Page Headline',
                'summary' => 'Actual Page Summary',
            ],
        ];

        $result = $resolver->resolvePage($context, 'en');

        $this->assertNotNull($result);
        $this->assertSame('actual-page-uuid', $result['id']);
        $this->assertSame('Actual Page Title', $result['title']);
        $this->assertSame('Actual Page Headline', $result['headline']);
    }

    public function testResolveParentAndSiblings(): void
    {
        $parentPage = $this->createStub(PageInterface::class);
        $parentPage->method('getUuid')->willReturn('parent-uuid-99');
        $parentDim = $this->createStub(PageDimensionContentInterface::class);
        $parentDim->method('getLocale')->willReturn('en');
        $parentDim->method('getTitle')->willReturn('Parent Hub');
        $parentDim->method('getTemplateData')->willReturn(['title' => 'Parent Hub']);
        $parentPage->method('getDimensionContents')->willReturn(new ArrayCollection([$parentDim]));

        $currentPage = $this->createStub(PageInterface::class);
        $currentPage->method('getUuid')->willReturn('current-uuid-1');
        $currentPage->method('getParent')->willReturn($parentPage);
        $currentDim = $this->createStub(PageDimensionContentInterface::class);
        $currentDim->method('getLocale')->willReturn('en');
        $currentDim->method('getTitle')->willReturn('Current Page');
        $currentDim->method('getTemplateData')->willReturn(['title' => 'Current Page']);
        $currentPage->method('getDimensionContents')->willReturn(new ArrayCollection([$currentDim]));

        $siblingPage = $this->createStub(PageInterface::class);
        $siblingPage->method('getUuid')->willReturn('sibling-uuid-2');
        $siblingDim = $this->createStub(PageDimensionContentInterface::class);
        $siblingDim->method('getLocale')->willReturn('en');
        $siblingDim->method('getTitle')->willReturn('Sibling Page');
        $siblingDim->method('getTemplateData')->willReturn(['title' => 'Sibling Page']);
        $siblingPage->method('getDimensionContents')->willReturn(new ArrayCollection([$siblingDim]));

        $pageRepositoryMock = $this->createMock(PageRepositoryInterface::class);
        $pageRepositoryMock->method('findOneBy')->willReturnCallback(
            static fn (array $filters): ?PageInterface => match ($filters['uuid'] ?? null) {
                'current-uuid-1' => $currentPage,
                'parent-uuid-99' => $parentPage,
                default => null,
            },
        );
        $pageRepositoryMock->expects($this->once())
            ->method('findBy')
            ->willReturn(new \ArrayIterator([$currentPage, $siblingPage]));

        $resolver = new SuluPageSourceResolver($pageRepositoryMock);

        $parent = $resolver->resolveParent('current-uuid-1', 'en');
        $this->assertNotNull($parent);
        $this->assertSame('parent-uuid-99', $parent['id']);
        $this->assertSame('Parent Hub', $parent['title']);

        $siblings = $resolver->resolveSiblings('current-uuid-1', 'en', 5);
        // Should exclude 'current-uuid-1' and only return 'sibling-uuid-2'
        $this->assertCount(1, $siblings);
        $this->assertSame('sibling-uuid-2', $siblings[0]['id']);
        $this->assertSame('Sibling Page', $siblings[0]['title']);
    }

    public function testResolveQuerySupportsTemplateTagsOffsetAndExcludeId(): void
    {
        $page1 = $this->createStub(PageInterface::class);
        $page1->method('getUuid')->willReturn('page-1');
        $dim1 = $this->createStub(PageDimensionContentInterface::class);
        $dim1->method('getLocale')->willReturn('en');
        $dim1->method('getTitle')->willReturn('Page 1');
        $dim1->method('getTemplateData')->willReturn(['title' => 'Page 1']);
        $page1->method('getDimensionContents')->willReturn(new ArrayCollection([$dim1]));

        $page2 = $this->createStub(PageInterface::class);
        $page2->method('getUuid')->willReturn('page-2');
        $dim2 = $this->createStub(PageDimensionContentInterface::class);
        $dim2->method('getLocale')->willReturn('en');
        $dim2->method('getTitle')->willReturn('Page 2');
        $dim2->method('getTemplateData')->willReturn(['title' => 'Page 2']);
        $page2->method('getDimensionContents')->willReturn(new ArrayCollection([$dim2]));

        $pageRepositoryMock = $this->createMock(PageRepositoryInterface::class);
        $pageRepositoryMock->expects($this->once())
            ->method('findBy')
            ->with(
                $this->callback(static function (array $filters): bool {
                    return ($filters['templateKeys'] ?? null) === ['article', 'service']
                        && ($filters['tagNames'] ?? null) === ['featured', 'cloud']
                        && ($filters['categoryKeys'] ?? null) === ['tech'];
                }),
            )
            ->willReturn(new \ArrayIterator([$page1, $page2]));

        $resolver = new SuluPageSourceResolver($pageRepositoryMock);

        $results = $resolver->resolveQuery([
            'template' => 'article, service',
            'tags' => 'featured, cloud',
            'categories' => 'tech',
            'exclude_id' => 'page-1',
            'limit' => 5,
        ], 'en');

        $this->assertCount(1, $results);
        $this->assertSame('page-2', $results[0]['id']);
    }

    public function testResolveParentFromSulu3TwigContextReloadsUninitializedParentDimensionContent(): void
    {
        $unlocalizedDim = $this->createStub(PageDimensionContentInterface::class);
        $unlocalizedDim->method('getLocale')->willReturn(null);
        $unlocalizedDim->method('getStage')->willReturn('live');
        $unlocalizedDim->method('getTitle')->willReturn(null);
        $unlocalizedDim->method('getTemplateData')->willReturn([]);

        // Proxy parent with only unlocalized dimension content initially loaded
        $parentProxy = $this->createStub(PageInterface::class);
        $parentProxy->method('getUuid')->willReturn('01a11348-6256-7b4e-adc2-3008ba037f03');
        $parentProxy->method('getDimensionContents')->willReturn(new ArrayCollection([$unlocalizedDim]));

        // Reloaded parent with localized 'en' live dimension content
        $localizedDim = $this->createStub(PageDimensionContentInterface::class);
        $localizedDim->method('getLocale')->willReturn('en');
        $localizedDim->method('getStage')->willReturn('live');
        $localizedDim->method('getTitle')->willReturn('Website');
        $localizedDim->method('getTemplateData')->willReturn(['title' => 'Website']);

        $reloadedParent = $this->createStub(PageInterface::class);
        $reloadedParent->method('getUuid')->willReturn('01a11348-6256-7b4e-adc2-3008ba037f03');
        $reloadedParent->method('getDimensionContents')->willReturn(new ArrayCollection([$unlocalizedDim, $localizedDim]));

        $aboutUsPage = $this->createStub(PageInterface::class);
        $aboutUsPage->method('getUuid')->willReturn('01a12d46-1e27-7310-860e-6fc8595d76c6');
        $aboutUsPage->method('getParent')->willReturn($parentProxy);

        $pageRepositoryMock = $this->createMock(PageRepositoryInterface::class);
        $pageRepositoryMock->expects($this->once())
            ->method('findOneBy')
            ->with([
                'uuid' => '01a11348-6256-7b4e-adc2-3008ba037f03',
                'locale' => 'en',
                'stage' => 'live',
            ])
            ->willReturn($reloadedParent);

        $resolver = new SuluPageSourceResolver($pageRepositoryMock);

        $sulu3TwigContext = [
            'content' => [
                'title' => 'About us',
            ],
            'resource' => $aboutUsPage,
        ];

        $parentData = $resolver->resolveParent($sulu3TwigContext, 'en');

        $this->assertNotNull($parentData);
        $this->assertSame('01a11348-6256-7b4e-adc2-3008ba037f03', $parentData['id']);
        $this->assertSame('Website', $parentData['title']);
        $this->assertSame('Website', $parentData['headline']);
    }

    public function testAboutUsThreeHeadlineBlocksResolveEndToEnd(): void
    {
        $unlocalizedDim = $this->createStub(PageDimensionContentInterface::class);
        $unlocalizedDim->method('getLocale')->willReturn(null);
        $unlocalizedDim->method('getStage')->willReturn('live');
        $unlocalizedDim->method('getTitle')->willReturn(null);
        $unlocalizedDim->method('getTemplateData')->willReturn([]);

        $homepageLiveDim = $this->createStub(PageDimensionContentInterface::class);
        $homepageLiveDim->method('getLocale')->willReturn('en');
        $homepageLiveDim->method('getStage')->willReturn('live');
        $homepageLiveDim->method('getTitle')->willReturn('Website');
        $homepageLiveDim->method('getTemplateData')->willReturn(['title' => 'Website']);

        $homepageProxy = $this->createStub(PageInterface::class);
        $homepageProxy->method('getUuid')->willReturn('01a11348-6256-7b4e-adc2-3008ba037f03');
        $homepageProxy->method('getDimensionContents')->willReturn(new ArrayCollection([$unlocalizedDim]));

        $homepageFull = $this->createStub(PageInterface::class);
        $homepageFull->method('getUuid')->willReturn('01a11348-6256-7b4e-adc2-3008ba037f03');
        $homepageFull->method('getDimensionContents')->willReturn(new ArrayCollection([$unlocalizedDim, $homepageLiveDim]));

        $aboutUsPage = $this->createStub(PageInterface::class);
        $aboutUsPage->method('getUuid')->willReturn('01a12d46-1e27-7310-860e-6fc8595d76c6');
        $aboutUsPage->method('getParent')->willReturn($homepageProxy);

        $pageRepositoryStub = $this->createStub(PageRepositoryInterface::class);
        $pageRepositoryStub->method('findOneBy')->willReturnCallback(
            static function (array $filters) use ($homepageFull): ?PageInterface {
                if (
                    ($filters['uuid'] ?? null) === '01a11348-6256-7b4e-adc2-3008ba037f03'
                    && ($filters['locale'] ?? null) === 'en'
                    && ($filters['stage'] ?? null) === 'live'
                ) {
                    return $homepageFull;
                }

                return null;
            },
        );

        $resolver = new SuluPageSourceResolver($pageRepositoryStub);
        $hydrator = new \Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentHydrator($resolver);
        $transformer = new \Totoglu\SuluBlockSuiteBundle\Transformer\DynamicContentTransformer($hydrator);
        $pipeline = new \Totoglu\SuluBlockSuiteBundle\Transformer\BlockTransformPipeline([$transformer]);

        $blocks = [
            [
                'type' => 'headline',
                'title' => 'Static 1',
                'props' => [
                    '_dynamic' => [
                        'enabled' => true,
                        'source_type' => 'current_page',
                        'mapping' => ['title' => 'title'],
                    ],
                ],
            ],
            [
                'type' => 'headline',
                'title' => 'Static 2',
                'props' => [
                    '_dynamic' => [
                        'enabled' => true,
                        'source_type' => 'parent_page',
                        'mapping' => ['title' => 'title'],
                    ],
                ],
            ],
            [
                'type' => 'headline',
                'title' => 'Static 3',
                'props' => [
                    '_dynamic' => [
                        'enabled' => true,
                        'source_type' => 'specific_page',
                        'page_id' => '01a11348-6256-7b4e-adc2-3008ba037f03',
                        'mapping' => ['title' => 'title'],
                    ],
                ],
            ],
        ];

        $sulu3TwigContext = [
            'content' => ['title' => 'About us'],
            'resource' => $aboutUsPage,
            'view' => ['url' => '/about-us'],
            'extension' => [],
        ];

        $processed = $pipeline->processCollection($blocks, $sulu3TwigContext);

        $this->assertCount(3, $processed);
        $this->assertSame('About us', $processed[0]['title']);
        $this->assertSame('Website', $processed[1]['title']);
        $this->assertSame('Website', $processed[2]['title']);
    }
}
