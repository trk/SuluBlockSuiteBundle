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

    public function testCategoryAndMediaSortingAndReferenceStoreTagging(): void
    {
        $cat1 = $this->createStub(Category::class);
        $cat1->method('getId')->willReturn(10);
        $cat1->method('getKey')->willReturn('zeta');
        $cat1->method('getName')->willReturn('Zeta Category');
        $cat1->method('getMedias')->willReturn([]);

        $cat2 = $this->createStub(Category::class);
        $cat2->method('getId')->willReturn(20);
        $cat2->method('getKey')->willReturn('alpha');
        $cat2->method('getName')->willReturn('Alpha Category');
        $cat2->method('getMedias')->willReturn([]);

        $categoryEntity = $this->createStub(CategoryInterface::class);
        $categoryManager = $this->createStub(CategoryManagerInterface::class);
        $categoryManager->method('findChildrenByParentId')->willReturn([$categoryEntity, $categoryEntity]);
        $categoryManager->method('getApiObjects')->willReturn([$cat1, $cat2]);

        $catRefStore = $this->createMock(\Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface::class);
        $catRefStore->expects($this->exactly(2))
            ->method('add')
            ->with($this->logicalOr('10', '20'), CategoryInterface::RESOURCE_KEY);

        $categoryProvider = new SuluCategorySourceProvider($categoryManager, null, $catRefStore);
        $sortedCategories = $categoryProvider->resolveCollection([
            'sort_by' => 'title',
            'sort_order' => 'asc',
        ], null, 'en');

        $this->assertCount(2, $sortedCategories);
        $this->assertSame('Alpha Category', $sortedCategories[0]['title']);
        $this->assertSame('Zeta Category', $sortedCategories[1]['title']);

        $media1 = $this->createStub(Media::class);
        $media1->method('getId')->willReturn(1);
        $media1->method('getTitle')->willReturn('B Asset');
        $media1->method('getFormats')->willReturn([]);

        $media2 = $this->createStub(Media::class);
        $media2->method('getId')->willReturn(2);
        $media2->method('getTitle')->willReturn('A Asset');
        $media2->method('getFormats')->willReturn([]);

        $mediaManager = $this->createStub(MediaManagerInterface::class);
        $mediaManager->method('get')->willReturn([$media1, $media2]);

        $mediaRefStore = $this->createMock(\Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface::class);
        $mediaRefStore->expects($this->exactly(2))
            ->method('add')
            ->with($this->logicalOr('1', '2'), \Sulu\Bundle\MediaBundle\Entity\MediaInterface::RESOURCE_KEY);

        $mediaProvider = new SuluMediaCollectionSourceProvider($mediaManager, null, $mediaRefStore);
        $sortedMedias = $mediaProvider->resolveCollection([
            'sort_by' => 'title',
            'sort_order' => 'asc',
        ], null, 'en');

        $this->assertCount(2, $sortedMedias);
        $this->assertSame('A Asset', $sortedMedias[0]['title']);
        $this->assertSame('B Asset', $sortedMedias[1]['title']);
    }
}
