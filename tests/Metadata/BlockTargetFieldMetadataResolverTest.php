<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;
use Totoglu\SuluBlockSuiteBundle\Metadata\BlockTargetFieldMetadataResolver;

final class BlockTargetFieldMetadataResolverTest extends TestCase
{
    private string $tempSchemaDir;

    protected function setUp(): void
    {
        $this->tempSchemaDir = \sys_get_temp_dir() . '/block_target_fields_test_' . \uniqid('', true);
        \mkdir($this->tempSchemaDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->tempSchemaDir)) {
            $files = \glob($this->tempSchemaDir . '/*');
            if (\is_array($files)) {
                foreach ($files as $file) {
                    \unlink($file);
                }
            }
            \rmdir($this->tempSchemaDir);
        }
    }

    public function testResolveTargetFieldsExtractsFieldsFromSuluFormMetadataAndSkipsInternalFields(): void
    {
        $cardForm = new FormMetadata();
        $cardForm->setKey('card');

        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');
        $titleField->setLabel('Card Title', 'en');
        $cardForm->addItem($titleField);

        $mediaField = new FieldMetadata('image');
        $mediaField->setType('single_media_selection');
        $mediaField->setLabel('Featured Image', 'en');
        $cardForm->addItem($mediaField);

        $propsField = new FieldMetadata('props');
        $propsField->setType('block_props');
        $cardForm->addItem($propsField);

        $nestedBlocksField = new FieldMetadata('blocks');
        $nestedBlocksField->setType('block');
        $cardForm->addItem($nestedBlocksField);

        $typedFormMetadata = new TypedFormMetadata();
        $typedFormMetadata->addForm('card', $cardForm);

        $provider = $this->createStub(MetadataProviderInterface::class);
        $provider->method('getMetadata')->willReturn($typedFormMetadata);

        $registry = $this->createStub(MetadataProviderRegistry::class);
        $registry->method('getMetadataProvider')->willReturn($provider);

        $resolver = new BlockTargetFieldMetadataResolver(
            $registry,
            [$this->tempSchemaDir],
        );

        $fields = $resolver->resolveTargetFields('card', 'en');

        $this->assertSame([
            ['name' => 'title', 'label' => 'Card Title (title)', 'type' => 'string'],
            ['name' => 'image', 'label' => 'Featured Image (image)', 'type' => 'media'],
        ], $fields);
    }

    public function testResolveTargetFieldsMergesYamlDynamicFieldsOverride(): void
    {
        \file_put_contents(
            $this->tempSchemaDir . '/custom_widget.yaml',
            <<<'YAML'
dynamic_fields:
    - name: headline
      label: Custom Headline
      type: string
    - name: cta_url
      label: CTA URL
      type: link
YAML
        );

        $resolver = new BlockTargetFieldMetadataResolver(
            null,
            [$this->tempSchemaDir],
        );

        $fields = $resolver->resolveTargetFields('custom_widget', 'en');

        $this->assertSame([
            ['name' => 'headline', 'label' => 'Custom Headline (headline)', 'type' => 'string'],
            ['name' => 'cta_url', 'label' => 'CTA URL (cta_url)', 'type' => 'link'],
        ], $fields);
    }

    public function testResolveTargetFieldsExtractsInnerItemsBlockFields(): void
    {
        $accordionForm = new FormMetadata();
        $accordionForm->setKey('accordion');

        $childItemForm = new FormMetadata();
        $childItemForm->setKey('accordion_item');

        $itemTitleField = new FieldMetadata('title');
        $itemTitleField->setType('text_line');
        $itemTitleField->setLabel('Question Title', 'en');
        $childItemForm->addItem($itemTitleField);

        $itemContentField = new FieldMetadata('content');
        $itemContentField->setType('text_editor');
        $itemContentField->setLabel('Answer Body', 'en');
        $childItemForm->addItem($itemContentField);

        $itemsBlockField = new FieldMetadata('items');
        $itemsBlockField->setType('block');
        $itemsBlockField->addType($childItemForm);
        $accordionForm->addItem($itemsBlockField);

        $typedFormMetadata = new TypedFormMetadata();
        $typedFormMetadata->addForm('accordion', $accordionForm);

        $provider = $this->createStub(MetadataProviderInterface::class);
        $provider->method('getMetadata')->willReturn($typedFormMetadata);

        $registry = $this->createStub(MetadataProviderRegistry::class);
        $registry->method('getMetadataProvider')->willReturn($provider);

        $resolver = new BlockTargetFieldMetadataResolver(
            $registry,
            [$this->tempSchemaDir],
        );

        $metadata = $resolver->resolveBlockMetadata('accordion', 'en');

        $this->assertTrue($metadata['multiple']);
        $this->assertSame('accordion_item', $metadata['default_item_type']);
        $this->assertSame([
            ['name' => 'title', 'label' => 'Question Title (title)', 'type' => 'string'],
            ['name' => 'content', 'label' => 'Answer Body (content)', 'type' => 'text'],
        ], $metadata['fields']);
    }

    public function testResolveBlockMetadataHonorsYamlMultipleAndDefaultItemTypeOverrides(): void
    {
        \file_put_contents(
            $this->tempSchemaDir . '/breadcrumbs.yaml',
            <<<'YAML'
multiple: true
default_item_type: breadcrumb_item
dynamic_fields:
    - name: title
      label: Breadcrumb Label
      type: string
    - name: url
      label: Target URL
      type: link
YAML
        );

        $resolver = new BlockTargetFieldMetadataResolver(
            null,
            [$this->tempSchemaDir],
        );

        $metadata = $resolver->resolveBlockMetadata('breadcrumbs', 'en');

        $this->assertTrue($metadata['multiple']);
        $this->assertSame('breadcrumb_item', $metadata['default_item_type']);
        $this->assertCount(2, $metadata['fields']);
    }
}

