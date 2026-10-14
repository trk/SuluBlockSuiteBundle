<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\DynamicContent\Provider;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Sulu\Article\Domain\Model\ArticleDimensionContentInterface;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\CategoryBundle\Api\Category;
use Sulu\Bundle\CategoryBundle\Category\CategoryManagerInterface;
use Sulu\Bundle\CategoryBundle\Entity\CategoryInterface;
use Sulu\Bundle\MediaBundle\Api\Media;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Route\Domain\Model\Route;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider\SuluArticleSourceProvider;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider\SuluCategorySourceProvider;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider\SuluMediaCollectionSourceProvider;

final class BuiltInSourceProvidersTest extends TestCase
{
    public function testSuluArticleSourceProviderNormalizesArticles(): void
    {
        $article = $this->createStub(ArticleInterface::class);
        $article->method('getUuid')->willReturn('article-uuid-1');

        $dimensionContent = $this->createStub(ArticleDimensionContentInterface::class);
        $dimensionContent->method('getLocale')->willReturn('en');
        $dimensionContent->method('getStage')->willReturn('live');
        $dimensionContent->method('getTitle')->willReturn('Sulu 3 Release Notes');
        $dimensionContent->method('getTemplateData')->willReturn([
            'subtitle' => 'Modern Content Management',
            'description' => '<p>Full Doctrine ORM architecture.</p>',
        ]);
        $dimensionContent->method('getExcerptTagNames')->willReturn(['News']);
        $dimensionContent->method('getExcerptCategoryIds')->willReturn([10]);
        $route = new Route('articles', 'article-uuid-1', 'en', '/blog/sulu-3');
        $dimensionContent->method('getRoute')->willReturn($route);

        $article->method('getDimensionContents')->willReturn(new ArrayCollection([$dimensionContent]));

        $articleRepository = $this->createStub(ArticleRepositoryInterface::class);
        $articleRepository->method('findBy')->willReturn([$article]);

        $provider = new SuluArticleSourceProvider($articleRepository);

        $this->assertSame('sulu_articles', $provider->getKey());
        $this->assertTrue($provider->isMultiItem());
        $this->assertNotEmpty($provider->getSourceFields('en'));

        $items = $provider->resolveCollection(['limit' => 5], null, 'en');
        $this->assertCount(1, $items);
        $this->assertSame('article-uuid-1', $items[0]['id']);
        $this->assertSame('Sulu 3 Release Notes', $items[0]['title']);
        $this->assertSame('/blog/sulu-3', $items[0]['url']);
        $this->assertSame('News', $items[0]['badge']);
    }

    public function testSuluCategorySourceProviderNormalizesCategories(): void
    {
        $categoryEntity = $this->createStub(CategoryInterface::class);
        $apiCategory = $this->createStub(Category::class);
        $apiCategory->method('getId')->willReturn(42);
        $apiCategory->method('getKey')->willReturn('tech');
        $apiCategory->method('getName')->willReturn('Technology');
        $apiCategory->method('getMedias')->willReturn([]);

        $categoryManager = $this->createStub(CategoryManagerInterface::class);
        $categoryManager->method('findChildrenByParentId')->willReturn([$categoryEntity]);
        $categoryManager->method('getApiObjects')->willReturn([$apiCategory]);
        $categoryManager->method('findById')->willReturn($categoryEntity);
        $categoryManager->method('getApiObject')->willReturn($apiCategory);

        $provider = new SuluCategorySourceProvider($categoryManager);

        $this->assertSame('sulu_categories', $provider->getKey());
        $this->assertTrue($provider->isMultiItem());
        $this->assertNotEmpty($provider->getSourceFields('en'));

        $items = $provider->resolveCollection(['limit' => 10], null, 'en');
        $this->assertCount(1, $items);
        $this->assertSame('42', $items[0]['id']);
        $this->assertSame('Technology', $items[0]['title']);
        $this->assertSame('tech', $items[0]['key']);
    }

    public function testSuluMediaCollectionSourceProviderNormalizesMediaAssets(): void
    {
        $media = $this->createStub(Media::class);
        $media->method('getId')->willReturn(99);
        $media->method('getTitle')->willReturn('Architecture Diagram');
        $media->method('getDescription')->willReturn('High-res diagram');
        $media->method('getName')->willReturn('arch.png');
        $media->method('getUrl')->willReturn('/media/99/download/arch.png');
        $media->method('getThumbnails')->willReturn(['sulu-400x400' => '/uploads/media/sulu-400x400/01/99-arch.png']);
        $media->method('getMimeType')->willReturn('image/png');

        $mediaManager = $this->createStub(MediaManagerInterface::class);
        $mediaManager->method('get')->willReturn([$media]);
        $mediaManager->method('getById')->willReturn($media);

        $provider = new SuluMediaCollectionSourceProvider($mediaManager);

        $this->assertSame('sulu_media_collection', $provider->getKey());
        $this->assertTrue($provider->isMultiItem());
        $this->assertNotEmpty($provider->getSourceFields('en'));

        $items = $provider->resolveCollection(['collection_id' => 5, 'limit' => 6], null, 'en');
        $this->assertCount(1, $items);
        $this->assertSame('99', $items[0]['id']);
        $this->assertSame('Architecture Diagram', $items[0]['title']);
        $this->assertSame('High-res diagram', $items[0]['description']);
        $this->assertIsArray($items[0]['image']);
        $this->assertSame('/media/99/download/arch.png', $items[0]['image']['url']);
    }
}

