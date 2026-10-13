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
        /** @var list<array{name: string, label: string, type: string}> $data */
        $data = \json_decode((string) $response->getContent(), true);

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
        /** @var list<array{name: string, label: string, type: string}> $data */
        $data = \json_decode((string) $response->getContent(), true);

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
        /** @var list<array{name: string, label: string, type: string}> $data */
        $data = \json_decode((string) $response->getContent(), true);

        $this->assertCount(2, $data);
        $this->assertSame('title', $data[0]['name']);
        $this->assertSame('Card Title (title)', $data[0]['label']);
        $this->assertSame('media', $data[1]['name']);
    }
}

