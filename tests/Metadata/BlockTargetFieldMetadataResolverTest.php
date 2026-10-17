<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;
use Totoglu\SuluBlockSuiteBundle\Metadata\BlockTargetFieldMetadataResolver;

final class BlockTargetFieldMetadataResolverTest extends TestCase
{
    public function testResolveTargetFieldsExtractsFieldsFromSuluFormMetadataAndSkipsPropsAndBlockFields(): void
    {
        $cardForm = new FormMetadata();
        $cardForm->setKey('fixture_card');

        $tabsSection = new SectionMetadata('__tabs');
        $contentSection = new SectionMetadata('content');

        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');
        $titleField->setLabel('Card Title', 'en');
        $contentSection->addItem($titleField);

        $mediaField = new FieldMetadata('image');
        $mediaField->setType('single_media_selection');
        $mediaField->setLabel('Featured Image', 'en');
        $contentSection->addItem($mediaField);

        $settingsSection = new SectionMetadata('settings');
        $variantField = new FieldMetadata('props/variant');
        $variantField->setType('single_select');
        $settingsSection->addItem($variantField);

        $nestedBlocksField = new FieldMetadata('custom_children');
        $nestedBlocksField->setType('block');
        $contentSection->addItem($nestedBlocksField);

        $tabsSection->addItem($contentSection);
        $tabsSection->addItem($settingsSection);
        $cardForm->addItem($tabsSection);

        $typedFormMetadata = new TypedFormMetadata();
        $typedFormMetadata->addForm('fixture_card', $cardForm);

        $provider = $this->createStub(MetadataProviderInterface::class);
        $provider->method('getMetadata')->willReturn($typedFormMetadata);

        $registry = $this->createStub(MetadataProviderRegistry::class);
        $registry->method('getMetadataProvider')->willReturn($provider);

        $resolver = new BlockTargetFieldMetadataResolver($registry);

        $fields = $resolver->resolveTargetFields('fixture_card', 'en');

        $this->assertSame([
            ['name' => 'title', 'label' => 'Card Title', 'type' => 'string'],
            ['name' => 'image', 'label' => 'Featured Image', 'type' => 'media'],
        ], $fields);
    }

    public function testResolveTargetFieldsExtractsInnerItemsBlockFields(): void
    {
        $accordionForm = new FormMetadata();
        $accordionForm->setKey('fixture_accordion');

        $childItemForm = new FormMetadata();
        $childItemForm->setKey('fixture_accordion_item');

        $childContentSection = new SectionMetadata('content');
        $itemTitleField = new FieldMetadata('title');
        $itemTitleField->setType('text_line');
        $itemTitleField->setLabel('Question Title', 'en');
        $childContentSection->addItem($itemTitleField);

        $itemContentField = new FieldMetadata('content');
        $itemContentField->setType('text_editor');
        $itemContentField->setLabel('Answer Body', 'en');
        $childContentSection->addItem($itemContentField);

        $childSettingsSection = new SectionMetadata('settings');
        $panelStyleField = new FieldMetadata('panel_style');
        $panelStyleField->setType('single_select');
        $panelStyleField->setLabel('Card Style Override', 'en');
        $childSettingsSection->addItem($panelStyleField);

        $childTabsSection = new SectionMetadata('__tabs');
        $childTabsSection->addItem($childContentSection);
        $childTabsSection->addItem($childSettingsSection);
        $childItemForm->addItem($childTabsSection);

        $itemsBlockField = new FieldMetadata('items');
        $itemsBlockField->setType('block');
        $itemsBlockField->addType($childItemForm);
        $accordionForm->addItem($itemsBlockField);

        $typedFormMetadata = new TypedFormMetadata();
        $typedFormMetadata->addForm('fixture_accordion', $accordionForm);

        $provider = $this->createStub(MetadataProviderInterface::class);
        $provider->method('getMetadata')->willReturn($typedFormMetadata);

        $registry = $this->createStub(MetadataProviderRegistry::class);
        $registry->method('getMetadataProvider')->willReturn($provider);

        $resolver = new BlockTargetFieldMetadataResolver($registry);

        $metadata = $resolver->resolveBlockMetadata('fixture_accordion', 'en');

        $this->assertTrue($metadata['multiple']);
        $this->assertSame('fixture_accordion_item', $metadata['default_item_type']);
        $this->assertSame([
            ['name' => 'title', 'label' => 'Question Title', 'type' => 'string'],
            ['name' => 'content', 'label' => 'Answer Body', 'type' => 'text'],
        ], $metadata['fields']);
    }

    public function testResolveBlockMetadataReturnsEmptyFieldsWhenMetadataIsUnavailable(): void
    {
        $resolver = new BlockTargetFieldMetadataResolver();

        $this->assertSame([
            'multiple' => false,
            'default_item_type' => null,
            'fields' => [],
        ], $resolver->resolveBlockMetadata('unknown_fixture_block', 'en'));

        $this->assertSame([
            'multiple' => false,
            'default_item_type' => null,
            'fields' => [],
        ], $resolver->resolveBlockMetadata('', 'en'));
    }
}
