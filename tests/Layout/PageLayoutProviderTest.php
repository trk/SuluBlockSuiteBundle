<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Layout;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Totoglu\SuluBlockSuiteBundle\Layout\PageLayoutProvider;
use Twig\Environment;
use Twig\Loader\LoaderInterface;

final class PageLayoutProviderTest extends TestCase
{
    public function testGetLayoutReturnsNullOnEmptyIdentifier(): void
    {
        $provider = new PageLayoutProvider();
        self::assertNull($provider->getLayout(''));
        self::assertNull($provider->getLayout('   '));
    }

    public function testGetLayoutMatchesByKey(): void
    {
        $snippet = $this->createStub(SnippetInterface::class);
        $snippet->method('getUuid')->willReturn('snippet-uuid-1');

        $content = $this->createStub(SnippetDimensionContentInterface::class);
        $content->method('getTemplateKey')->willReturn('page_layout');
        $content->method('getTitle')->willReturn('Default Layout');
        $content->method('getTemplateData')->willReturn([
            'key' => 'default',
            'blocks' => [
                ['type' => 'headline', 'title' => 'Hello World'],
            ],
            'layout_width' => 'boxed',
        ]);

        $snippet->method('getDimensionContents')->willReturn(new ArrayCollection([$content]));

        $repo = $this->createMock(SnippetRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('findBy')
            ->willReturn([$snippet]);

        $provider = new PageLayoutProvider($repo);
        $layout = $provider->getLayout('default', 'en');

        self::assertNotNull($layout);
        self::assertSame('snippet-uuid-1', $layout['id']);
        self::assertSame('default', $layout['key']);
        self::assertSame('Default Layout', $layout['title']);
        self::assertIsArray($layout['blocks']);
        self::assertCount(1, $layout['blocks']);

        // In-memory caching: second call with same identifier should NOT query repo again
        $cached = $provider->getLayout('default', 'en');
        self::assertSame($layout, $cached);
    }

    public function testGetLayoutMatchesBySlugifiedTitle(): void
    {
        $snippet = $this->createStub(SnippetInterface::class);
        $snippet->method('getUuid')->willReturn('snippet-uuid-2');

        $content = $this->createStub(SnippetDimensionContentInterface::class);
        $content->method('getTemplateKey')->willReturn('page_layout');
        $content->method('getTitle')->willReturn('Article Footer Section');
        $content->method('getTemplateData')->willReturn([
            'blocks' => [
                ['type' => 'card', 'title' => 'Author Bio'],
            ],
        ]);

        $snippet->method('getDimensionContents')->willReturn(new ArrayCollection([$content]));

        $repo = $this->createStub(SnippetRepositoryInterface::class);
        $repo->method('findBy')->willReturn([$snippet]);

        $provider = new PageLayoutProvider($repo);
        $layout = $provider->getLayout('article_footer_section', 'en');

        self::assertNotNull($layout);
        self::assertSame('Article Footer Section', $layout['title']);
        self::assertIsArray($layout['blocks']);
        self::assertCount(1, $layout['blocks']);
    }

    public function testGetLayoutMatchesByUuid(): void
    {
        $snippet = $this->createStub(SnippetInterface::class);
        $snippet->method('getUuid')->willReturn('target-uuid-123');

        $content = $this->createStub(SnippetDimensionContentInterface::class);
        $content->method('getTemplateKey')->willReturn('page_layout');
        $content->method('getTitle')->willReturn('Custom Layout');
        $content->method('getTemplateData')->willReturn(['blocks' => []]);

        $snippet->method('getDimensionContents')->willReturn(new ArrayCollection([$content]));

        $repo = $this->createStub(SnippetRepositoryInterface::class);
        $repo->method('findBy')->willReturn([$snippet]);

        $provider = new PageLayoutProvider($repo);
        $layout = $provider->getLayout('target-uuid-123', 'en');

        self::assertNotNull($layout);
        self::assertSame('target-uuid-123', $layout['uuid']);
    }

    public function testRenderLayoutReturnsEmptyStringWhenLayoutEmptyOrTwigMissing(): void
    {
        $provider = new PageLayoutProvider();
        self::assertSame('', $provider->renderLayout(null));
        self::assertSame('', $provider->renderLayout(''));
        self::assertSame('', $provider->renderLayout('default'));
    }

    public function testRenderLayoutWithPreloadedArrayDelegatesToTwig(): void
    {
        $twig = $this->createMock(Environment::class);
        $loader = $this->createStub(LoaderInterface::class);
        $loader->method('exists')->willReturn(true);
        $twig->method('getLoader')->willReturn($loader);

        $twig->expects(self::once())
            ->method('render')
            ->with(
                'snippets/page_layout.html.twig',
                self::callback(static function (array $params): bool {
                    return isset($params['content'])
                        && isset($params['blocks'])
                        && \is_array($params['blocks'])
                        && 1 === \count($params['blocks'])
                        && ($params['custom_var'] ?? null) === 'test-value';
                }),
            )
            ->willReturn('<div class="rendered-layout">Mock Output</div>');

        $provider = new PageLayoutProvider(null, $twig);
        $output = $provider->renderLayout([
            'blocks' => [['type' => 'headline', 'title' => 'Test']],
        ], ['custom_var' => 'test-value']);

        self::assertSame('<div class="rendered-layout">Mock Output</div>', $output);
    }

    public function testRenderLayoutWithStringKeyResolvesAndRenders(): void
    {
        $snippet = $this->createStub(SnippetInterface::class);
        $snippet->method('getUuid')->willReturn('uuid-default');

        $content = $this->createStub(SnippetDimensionContentInterface::class);
        $content->method('getTemplateKey')->willReturn('page_layout');
        $content->method('getTitle')->willReturn('Default');
        $content->method('getTemplateData')->willReturn([
            'key' => 'default',
            'blocks' => [['type' => 'text', 'content' => '<p>Hello</p>']],
        ]);

        $snippet->method('getDimensionContents')->willReturn(new ArrayCollection([$content]));

        $repo = $this->createStub(SnippetRepositoryInterface::class);
        $repo->method('findBy')->willReturn([$snippet]);

        $twig = $this->createMock(Environment::class);
        $loader = $this->createStub(LoaderInterface::class);
        $loader->method('exists')->willReturn(false); // falls back to bundle template
        $twig->method('getLoader')->willReturn($loader);

        $twig->expects(self::once())
            ->method('render')
            ->with('@SuluBlockSuite/snippets/page_layout.html.twig')
            ->willReturn('<div class="bundle-rendered">Rendered</div>');

        $requestStack = new RequestStack();
        $request = new Request();
        $request->setLocale('tr');
        $requestStack->push($request);

        $provider = new PageLayoutProvider($repo, $twig, $requestStack);
        $output = $provider->renderLayout('default');

        self::assertSame('<div class="bundle-rendered">Rendered</div>', $output);
    }

    public function testCascadesMultipleLayoutsDirectories(): void
    {
        $dir1 = \sys_get_temp_dir() . '/sulu_layouts_dir1_' . \uniqid('', true);
        $dir2 = \sys_get_temp_dir() . '/sulu_layouts_dir2_' . \uniqid('', true);
        \mkdir($dir1, 0777, true);
        \mkdir($dir2, 0777, true);

        try {
            \file_put_contents($dir1 . '/layout_one.yaml', "title: 'Layout One'\nblocks: []");
            \file_put_contents($dir2 . '/layout_two.yaml', "title: 'Layout Two'\nblocks: []");

            $provider = new PageLayoutProvider(null, null, null, [$dir1, $dir2]);

            $l1 = $provider->getLayout('layout_one');
            self::assertNotNull($l1);
            self::assertSame('Layout One', $l1['title']);

            $l2 = $provider->getLayout('layout_two');
            self::assertNotNull($l2);
            self::assertSame('Layout Two', $l2['title']);
        } finally {
            if (\is_dir($dir1)) {
                @\unlink($dir1 . '/layout_one.yaml');
                @\rmdir($dir1);
            }
            if (\is_dir($dir2)) {
                @\unlink($dir2 . '/layout_two.yaml');
                @\rmdir($dir2);
            }
        }
    }

    public function testRenderLayoutStopsOnSelfReferencingCycle(): void
    {
        /** @var array{provider: ?PageLayoutProvider} $box */
        $box = ['provider' => null];
        $twig = $this->createStub(Environment::class);
        $loader = $this->createStub(LoaderInterface::class);
        $loader->method('exists')->willReturn(true);
        $twig->method('getLoader')->willReturn($loader);

        $renderCalls = 0;
        $twig->method('render')->willReturnCallback(static function () use (&$box, &$renderCalls): string {
            ++$renderCalls;
            if (null !== $box['provider']) {
                return 'OUTER[' . $box['provider']->renderLayout(['id' => 'cycle-layout', 'blocks' => []]) . ']';
            }

            return '';
        });

        $provider = new PageLayoutProvider(null, $twig);
        $box['provider'] = $provider;
        $result = $provider->renderLayout(['id' => 'cycle-layout', 'blocks' => []]);

        self::assertSame('OUTER[]', $result);
        self::assertSame(1, $renderCalls);
    }

    public function testGetLayoutSkipsDraftOnlySnippet(): void
    {
        $snippet = $this->createStub(SnippetInterface::class);
        $snippet->method('getUuid')->willReturn('draft-layout-uuid');

        $draftContent = $this->createStub(SnippetDimensionContentInterface::class);
        $draftContent->method('getTemplateKey')->willReturn('page_layout');
        $draftContent->method('getLocale')->willReturn('en');
        $draftContent->method('getStage')->willReturn('draft');
        $draftContent->method('getTitle')->willReturn('Draft Layout');
        $draftContent->method('getTemplateData')->willReturn([
            'key' => 'draft_only',
            'blocks' => [['type' => 'headline', 'title' => 'Draft']],
        ]);

        $snippet->method('getDimensionContents')->willReturn(new ArrayCollection([$draftContent]));

        $repo = $this->createStub(SnippetRepositoryInterface::class);
        $repo->method('findBy')->willReturn([$snippet]);

        $provider = new PageLayoutProvider($repo);
        self::assertNull($provider->getLayout('draft_only', 'en'));
        self::assertNull($provider->getLayout('draft-layout-uuid', 'en'));
    }

    public function testGetLayoutResolvesPipeSeparatedFallbackChain(): void
    {
        $defaultSnippet = $this->createStub(SnippetInterface::class);
        $defaultSnippet->method('getUuid')->willReturn('default-uuid');

        $defaultContent = $this->createStub(SnippetDimensionContentInterface::class);
        $defaultContent->method('getTemplateKey')->willReturn('page_layout');
        $defaultContent->method('getTitle')->willReturn('Default');
        $defaultContent->method('getTemplateData')->willReturn([
            'key' => 'default',
            'blocks' => [['type' => 'headline', 'title' => 'Default Header']],
        ]);
        $defaultSnippet->method('getDimensionContents')->willReturn(new ArrayCollection([$defaultContent]));

        $searchSnippet = $this->createStub(SnippetInterface::class);
        $searchSnippet->method('getUuid')->willReturn('search-uuid');

        $searchContent = $this->createStub(SnippetDimensionContentInterface::class);
        $searchContent->method('getTemplateKey')->willReturn('page_layout');
        $searchContent->method('getTitle')->willReturn('Search');
        $searchContent->method('getTemplateData')->willReturn([
            'key' => 'search',
            'blocks' => [['type' => 'headline', 'title' => 'Search Header']],
        ]);
        $searchSnippet->method('getDimensionContents')->willReturn(new ArrayCollection([$searchContent]));

        $repo = $this->createMock(SnippetRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('findBy')
            ->willReturn([$defaultSnippet, $searchSnippet]);

        $provider = new PageLayoutProvider($repo);

        // 1. "search|default" picks "search" when "search" layout exists
        $searchLayout = $provider->getLayout('search|default', 'en');
        self::assertNotNull($searchLayout);
        self::assertSame('search-uuid', $searchLayout['uuid']);

        // 2. "homepage|default" falls back to "default" when "homepage" layout does not exist
        $homepageFallback = $provider->getLayout('homepage|default', 'en');
        self::assertNotNull($homepageFallback);
        self::assertSame('default-uuid', $homepageFallback['uuid']);

        // 3. "error|nonexistent" returns null when neither exists
        self::assertNull($provider->getLayout('error|nonexistent', 'en'));
    }

    public function testRenderLayoutReturnsEmptyStringWhenPipeFallbackChainFindsNothing(): void
    {
        $repo = $this->createStub(SnippetRepositoryInterface::class);
        $repo->method('findBy')->willReturn([]);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::never())->method('render');

        $provider = new PageLayoutProvider($repo, $twig);
        self::assertSame('', $provider->renderLayout('search|default', [], 'en'));
        self::assertSame('', $provider->renderLayout('error|default', [], 'en'));
        self::assertSame('', $provider->renderLayout('homepage|default', [], 'en'));
    }

    public function testGetLayoutAndReusableBlockTagReferenceStoreOnCacheHitAndUseContentResolver(): void
    {
        $snippet = $this->createStub(SnippetInterface::class);
        $snippet->method('getUuid')->willReturn('reusable-uuid-1');

        $historicalDim = $this->createStub(SnippetDimensionContentInterface::class);
        $historicalDim->method('getTemplateKey')->willReturn('reusable_block');
        $historicalDim->method('getStage')->willReturn('live');
        $historicalDim->method('getLocale')->willReturn('en');
        $historicalDim->method('getVersion')->willReturn(1);
        $historicalDim->method('getTitle')->willReturn('Old Historical Title');

        $liveDim = $this->createStub(SnippetDimensionContentInterface::class);
        $liveDim->method('getTemplateKey')->willReturn('reusable_block');
        $liveDim->method('getStage')->willReturn('live');
        $liveDim->method('getLocale')->willReturn('en');
        $liveDim->method('getVersion')->willReturn(0);
        $liveDim->method('getTitle')->willReturn('Current Live Title');
        $liveDim->method('getTemplateData')->willReturn([
            'blocks' => [['type' => 'headline', 'title' => 'Raw Title']],
        ]);

        $snippet->method('getDimensionContents')->willReturn(new ArrayCollection([$historicalDim, $liveDim]));

        $repo = $this->createMock(SnippetRepositoryInterface::class);
        $repo->expects(self::once())->method('getOneBy')->willReturn($snippet);

        $referenceStore = $this->createMock(\Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface::class);
        $referenceStore->expects(self::exactly(2))
            ->method('add')
            ->with('reusable-uuid-1', SnippetInterface::RESOURCE_KEY);

        $contentResolver = $this->createMock(\Sulu\Content\Application\ContentResolver\ContentResolverInterface::class);
        $contentResolver->expects(self::once())
            ->method('resolve')
            ->with($liveDim)
            ->willReturn([
                'content' => [
                    'blocks' => [['type' => 'headline', 'title' => 'Resolved Title']],
                ],
            ]);

        $provider = new PageLayoutProvider($repo, null, null, [], $referenceStore, $contentResolver);

        $first = $provider->getReusableBlock('reusable-uuid-1', 'en');
        $second = $provider->getReusableBlock('reusable-uuid-1', 'en');

        self::assertNotNull($first);
        self::assertSame($first, $second);
        self::assertSame('Current Live Title', $first['title']);
        self::assertIsArray($first['blocks']);
        self::assertIsArray($first['blocks'][0]);
        self::assertSame('Resolved Title', $first['blocks'][0]['title'] ?? null);
    }
}
