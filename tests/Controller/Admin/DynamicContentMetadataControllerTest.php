<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Controller\Admin;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;
use Symfony\Component\HttpFoundation\Request;
use Totoglu\SuluBlockSuiteBundle\Controller\Admin\DynamicContentMetadataController;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\PageSourceResolverInterface;
use Totoglu\SuluBlockSuiteBundle\Metadata\BlockTargetFieldMetadataResolver;

class DynamicContentMetadataControllerTest extends TestCase
{
    public function testSourceFieldsReturnsStandardFields(): void
    {
        $controller = new DynamicContentMetadataController();
        $request = new Request();

        $response = $controller->sourceFields($request);

        $this->assertSame(200, $response->getStatusCode());
        /** @var array{fields: list<array{name: string, label: string, type: string}>} $payload */
        $payload = \json_decode((string) $response->getContent(), true);
        $data = $payload['fields'];

        $names = \array_column($data, 'name');

        $this->assertContains('title', $names);
        $this->assertContains('headline', $names);
        $this->assertContains('summary', $names);
        $this->assertContains('description', $names);
        $this->assertContains('image', $names);
        $this->assertContains('url', $names);
        $this->assertContains('link', $names);
        $this->assertContains('date', $names);
        $this->assertContains('author', $names);
    }

    public function testSourceFieldsWithPageIdEnrichesCustomFields(): void
    {
        $resolver = $this->createMock(PageSourceResolverInterface::class);
        $resolver->expects($this->once())
            ->method('resolvePage')
            ->with('page-999', 'en')
            ->willReturn([
                'title' => 'Sample Page',
                'custom_tagline' => 'Best services around',
                'featured_image' => ['id' => 45],
            ]);

        $controller = new DynamicContentMetadataController($resolver);
        $request = new Request(['page_id' => 'page-999', 'locale' => 'en']);

        $response = $controller->sourceFields($request);

        $this->assertSame(200, $response->getStatusCode());
        /** @var array{fields: list<array{name: string, label: string, type: string}>} $payload */
        $payload = \json_decode((string) $response->getContent(), true);
        $data = $payload['fields'];

        $names = \array_column($data, 'name');
        $this->assertContains('custom_tagline', $names);
        $this->assertContains('featured_image', $names);

        $taglineField = \current(\array_filter($data, static fn ($f) => 'custom_tagline' === $f['name']));
        $this->assertIsArray($taglineField);
        $this->assertSame('Custom Tagline', $taglineField['label']);
        $this->assertSame('string', $taglineField['type']);

        $imageField = \current(\array_filter($data, static fn ($f) => 'featured_image' === $f['name']));
        $this->assertIsArray($imageField);
        $this->assertSame('media', $imageField['type']);
    }

    public function testTargetFieldsDelegatesToResolver(): void
    {
        $cardForm = new FormMetadata();
        $cardForm->setKey('card');

        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');
        $titleField->setLabel('Card Title', 'en');
        $cardForm->addItem($titleField);

        $mediaField = new FieldMetadata('media');
        $mediaField->setType('single_media_selection');
        $mediaField->setLabel('Media', 'en');
        $cardForm->addItem($mediaField);

        $typedFormMetadata = new TypedFormMetadata();
        $typedFormMetadata->addForm('card', $cardForm);

        $provider = $this->createStub(MetadataProviderInterface::class);
        $provider->method('getMetadata')->willReturn($typedFormMetadata);

        $registry = $this->createStub(MetadataProviderRegistry::class);
        $registry->method('getMetadataProvider')->willReturn($provider);

        $targetResolver = new BlockTargetFieldMetadataResolver($registry, []);

        $controller = new DynamicContentMetadataController(null, $targetResolver);
        $request = new Request(['locale' => 'en']);

        $response = $controller->targetFields($request, 'card');

        $this->assertSame(200, $response->getStatusCode());
        /** @var array{multiple: bool, default_item_type: ?string, fields: list<array{name: string, label: string, type: string}>} $data */
        $data = \json_decode((string) $response->getContent(), true);

        $this->assertFalse($data['multiple']);
        $this->assertNull($data['default_item_type']);
        $this->assertCount(2, $data['fields']);
        $this->assertSame('title', $data['fields'][0]['name']);
        $this->assertSame('Card Title', $data['fields'][0]['label']);
        $this->assertSame('media', $data['fields'][1]['name']);
    }

    public function testSourcesIncludesSingleAndMultiItemSources(): void
    {
        $controller = new DynamicContentMetadataController();
        $request = new Request(['locale' => 'en']);

        $response = $controller->sources($request);

        $this->assertSame(200, $response->getStatusCode());
        /** @var array{sources: list<array{key: string, label: string, multi_item: bool, config_fields: list<array{name: string, type: string}>}>} $payload */
        $payload = \json_decode((string) $response->getContent(), true);
        $sources = $payload['sources'];

        $byKey = \array_column($sources, null, 'key');
        $this->assertArrayHasKey('current_page', $byKey);
        $this->assertFalse($byKey['current_page']['multi_item']);

        $this->assertArrayHasKey('single_article', $byKey);
        $this->assertFalse($byKey['single_article']['multi_item']);

        $this->assertArrayHasKey('single_category', $byKey);
        $this->assertFalse($byKey['single_category']['multi_item']);

        $this->assertArrayHasKey('single_media', $byKey);
        $this->assertFalse($byKey['single_media']['multi_item']);

        $this->assertArrayHasKey('page_selection', $byKey);
        $this->assertTrue($byKey['page_selection']['multi_item']);
        $this->assertNotEmpty($byKey['page_selection']['config_fields']);
        $this->assertSame('page_selection', $byKey['page_selection']['config_fields'][0]['type']);
    }

    public function testCustomSingleAndCollectionProductProviderExposesConfigFieldsAndSourceFields(): void
    {
        $productProvider = new class extends \Totoglu\SuluBlockSuiteBundle\DynamicContent\AbstractDynamicContentSourceProvider {
            public function getKey(): string
            {
                return 'products';
            }

            public function getLabel(string $locale = 'en'): string
            {
                return 'Product Collection';
            }

            public function getSingleKey(): string
            {
                return 'single_product';
            }

            public function getSingleLabel(string $locale = 'en'): string
            {
                return 'Single Product Selection';
            }

            public function getSingleConfigFields(string $locale = 'en'): array
            {
                return [
                    $this->createSelectionField('product_id', 'single_product_selection', 'Product', 'Select a product.', true),
                ];
            }

            public function getConfigFields(string $locale = 'en'): array
            {
                return [
                    $this->createSelectionField('product_ids', 'product_selection', 'Products', 'Select multiple products.'),
                    ...$this->createPaginationAndSortFields(12),
                    ...$this->createTaxonomyFilterFields(includeTemplateFilter: false, includeTags: true, includeCategories: true),
                ];
            }

            public function getSourceFields(string $locale = 'en'): array
            {
                return [
                    ['name' => 'title', 'label' => 'Product Name', 'type' => 'string'],
                    ['name' => 'sku', 'label' => 'SKU', 'type' => 'string'],
                    ['name' => 'price', 'label' => 'Price', 'type' => 'string'],
                ];
            }
        };

        $controller = new DynamicContentMetadataController(null, null, [$productProvider]);
        $sourcesResponse = $controller->sources(new Request(['locale' => 'en']));

        /** @var array{sources: list<array{key: string, label: string, multi_item: bool, config_fields: list<array{name: string, type: string, required?: bool}>}>} $sourcesPayload */
        $sourcesPayload = \json_decode((string) $sourcesResponse->getContent(), true);
        $sources = $sourcesPayload['sources'];
        $byKey = \array_column($sources, null, 'key');

        $this->assertArrayHasKey('single_product', $byKey);
        $this->assertFalse($byKey['single_product']['multi_item']);
        $this->assertCount(1, $byKey['single_product']['config_fields']);
        $this->assertSame('product_id', $byKey['single_product']['config_fields'][0]['name']);
        $this->assertSame('single_product_selection', $byKey['single_product']['config_fields'][0]['type']);
        $this->assertTrue($byKey['single_product']['config_fields'][0]['required'] ?? false);

        $this->assertArrayHasKey('products', $byKey);
        $this->assertTrue($byKey['products']['multi_item']);
        $collectionFieldTypes = \array_column($byKey['products']['config_fields'], 'type', 'name');
        $this->assertSame('product_selection', $collectionFieldTypes['product_ids'] ?? null);
        $this->assertSame('tag_selection', $collectionFieldTypes['tag_ids'] ?? null);
        $this->assertSame('category_selection', $collectionFieldTypes['category_ids'] ?? null);
        $this->assertSame('number', $collectionFieldTypes['limit'] ?? null);

        $singleFieldsResponse = $controller->sourceFields(new Request(['source_type' => 'single_product', 'locale' => 'en']));
        /** @var array{fields: list<array{name: string, label: string, type: string}>} $singleFieldsPayload */
        $singleFieldsPayload = \json_decode((string) $singleFieldsResponse->getContent(), true);
        $this->assertSame(['title', 'sku', 'price'], \array_column($singleFieldsPayload['fields'], 'name'));

        $collectionFieldsResponse = $controller->sourceFields(new Request(['source_type' => 'products', 'locale' => 'en']));
        /** @var array{fields: list<array{name: string, label: string, type: string}>} $collectionFieldsPayload */
        $collectionFieldsPayload = \json_decode((string) $collectionFieldsResponse->getContent(), true);
        $this->assertSame(['title', 'sku', 'price'], \array_column($collectionFieldsPayload['fields'], 'name'));
    }
}
