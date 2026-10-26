<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\DynamicContent;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Sulu\Article\Domain\Model\ArticleDimensionContentInterface;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Route\Domain\Model\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\SuluContentDimensionExtractor;

final class SuluContentDimensionExtractorTest extends TestCase
{
    public function testExtractsAndNormalizesDimensionContentWithExcerptTaxonomyAndRoute(): void
    {
        $route = new Route('articles', 'article-1', 'en', '/blog/deep-modules');

        $dim = $this->createStub(ArticleDimensionContentInterface::class);
        $dim->method('getVersion')->willReturn(DimensionContentInterface::CURRENT_VERSION);
        $dim->method('getStage')->willReturn(DimensionContentInterface::STAGE_LIVE);
        $dim->method('getLocale')->willReturn('en');
        $dim->method('getTemplateKey')->willReturn('blog_post');
        $dim->method('getTitle')->willReturn('Deep Modules in Sulu');
        $dim->method('getTemplateData')->willReturn([
            'article' => '<p>Design deep modules behind small interfaces.</p>',
        ]);
        $dim->method('getExcerptDescription')->willReturn(null);
        $dim->method('getExcerptImage')->willReturn(['id' => 42]);
        $dim->method('getExcerptTagNames')->willReturn(['Architecture', 'Sulu']);
        $dim->method('getExcerptCategoryIds')->willReturn([7, 9]);
        $dim->method('getRoute')->willReturn($route);
        $dim->method('getAuthored')->willReturn(new \DateTimeImmutable('2026-10-26T12:00:00+00:00'));

        $article = $this->createStub(ArticleInterface::class);
        $article->method('getUuid')->willReturn('article-1');
        $article->method('getDimensionContents')->willReturn(new ArrayCollection([$dim]));

        $extractedDim = SuluContentDimensionExtractor::extractDimensionContent($article, 'en');
        self::assertSame($dim, $extractedDim);

        $normalized = SuluContentDimensionExtractor::normalizeDimensionContent('article-1', $extractedDim);
        self::assertSame('article-1', $normalized['id']);
        self::assertSame('Deep Modules in Sulu', $normalized['title']);
        self::assertSame('Deep Modules in Sulu', $normalized['headline']);
        self::assertSame('Design deep modules behind small interfaces.', $normalized['summary']);
        self::assertSame('/blog/deep-modules', $normalized['url']);
        self::assertSame(['id' => 42], $normalized['image']);
        self::assertSame('blog_post', $normalized['template']);
        self::assertSame(['Architecture', 'Sulu'], $normalized['tags']);
        self::assertSame('Architecture', $normalized['badge']);
        self::assertSame([7, 9], $normalized['categories']);
    }

    public function testIsPreviewContextDetectsPreviewRequestAttributes(): void
    {
        $request = new Request();
        $request->attributes->set('preview', true);
        $stack = new RequestStack();
        $stack->push($request);

        self::assertTrue(SuluContentDimensionExtractor::isPreviewContext($stack));
        self::assertFalse(SuluContentDimensionExtractor::isPreviewContext(new RequestStack()));
    }
}
