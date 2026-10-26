<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
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

    public function testResolveTargetFieldsExtractsMappableSubBlockCollectionsAndVirtualBadgeButtonTargets(): void
    {
        $gridForm = new FormMetadata();
        $gridForm->setKey('fixture_grid');

        $cardItemForm = new FormMetadata();
        $cardItemForm->setKey('card');

        $cardContentSection = new SectionMetadata('content');
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');
        $titleField->setLabel('Title', 'en');
        $cardContentSection->addItem($titleField);

        $elementsBlockField = new FieldMetadata('elements');
        $elementsBlockField->setType('block');
        $elementsBlockField->setLabel('Buttons & Badges', 'en');

        $buttonTypeForm = new FormMetadata();
        $buttonTypeForm->setKey('button');
        $buttonTypeForm->setTitle('Button', 'en');
        $elementsBlockField->addType($buttonTypeForm);

        $badgeTypeForm = new FormMetadata();
        $badgeTypeForm->setKey('badge');
        $badgeTypeForm->setTitle('Badge', 'en');
        $elementsBlockField->addType($badgeTypeForm);

        $cardContentSection->addItem($elementsBlockField);
        $cardItemForm->addItem($cardContentSection);

        $itemsBlockField = new FieldMetadata('items');
        $itemsBlockField->setType('block');
        $itemsBlockField->addType($cardItemForm);
        $gridForm->addItem($itemsBlockField);

        $typedFormMetadata = new TypedFormMetadata();
        $typedFormMetadata->addForm('fixture_grid', $gridForm);

        $provider = $this->createStub(MetadataProviderInterface::class);
        $provider->method('getMetadata')->willReturn($typedFormMetadata);

        $registry = $this->createStub(MetadataProviderRegistry::class);
        $registry->method('getMetadataProvider')->willReturn($provider);

        $resolver = new BlockTargetFieldMetadataResolver($registry);
        $fields = $resolver->resolveTargetFields('fixture_grid', 'en');

        $this->assertSame([
            ['name' => 'title', 'label' => 'Title', 'type' => 'string'],
            ['name' => 'elements', 'label' => 'Buttons & Badges', 'type' => 'collection'],
            ['name' => 'buttons', 'label' => 'Button', 'type' => 'collection'],
            ['name' => 'badges', 'label' => 'Badge', 'type' => 'collection'],
        ], $fields);
    }

    public function testNormalizeFieldTypeMapsSuluAndPhpDataTypesToCanonicalTypes(): void
    {
        $this->assertSame('string', BlockTargetFieldMetadataResolver::normalizeFieldType('text_line'));
        $this->assertSame('string', BlockTargetFieldMetadataResolver::normalizeFieldType('string'));
        $this->assertSame('text', BlockTargetFieldMetadataResolver::normalizeFieldType('text_editor'));
        $this->assertSame('media', BlockTargetFieldMetadataResolver::normalizeFieldType('single_media_selection'));
        $this->assertSame('number', BlockTargetFieldMetadataResolver::normalizeFieldType('int'));
        $this->assertSame('number', BlockTargetFieldMetadataResolver::normalizeFieldType('float'));
        $this->assertSame('number', BlockTargetFieldMetadataResolver::normalizeFieldType('double'));
        $this->assertSame('boolean', BlockTargetFieldMetadataResolver::normalizeFieldType('bool'));
        $this->assertSame('collection', BlockTargetFieldMetadataResolver::normalizeFieldType('array'));
        $this->assertSame('collection', BlockTargetFieldMetadataResolver::normalizeFieldType('block'));
        $this->assertSame('link', BlockTargetFieldMetadataResolver::normalizeFieldType('url'));
    }

    public function testResolveFieldTypeMapAndExtractFieldOptionGroupsAndPartitionSections(): void
    {
        $form = new FormMetadata();
        $form->setKey('hero');

        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');

        $sizeCollection = new OptionMetadata();
        $sizeCollection->setName('title_size');
        $sizeCollection->setType(OptionMetadata::TYPE_COLLECTION);
        $defaultOpt = new OptionMetadata();
        $defaultOpt->setName('_default');
        $defaultOpt->setValue('lg');
        $mdOpt = new OptionMetadata();
        $mdOpt->setName('md');
        $mdOpt->setTitle('Medium', 'en');
        $lgOpt = new OptionMetadata();
        $lgOpt->setName('lg');
        $lgOpt->setTitle('Large', 'en');
        $sizeCollection->setValue([$defaultOpt, $mdOpt, $lgOpt]);
        $titleField->addOption($sizeCollection);

        $imageField = new FieldMetadata('image');
        $imageField->setType('single_media_selection');

        $contentSection = new SectionMetadata('content');
        $contentSection->addItem($titleField);
        $contentSection->addItem($imageField);

        $settingsSection = new SectionMetadata('settings');
        $bgField = new FieldMetadata('props/background');
        $bgField->setType('single_select');
        $settingsSection->addItem($bgField);

        $tabsSection = new SectionMetadata('__tabs');
        $tabsSection->addItem($contentSection);
        $tabsSection->addItem($settingsSection);
        $form->addItem($tabsSection);

        $typedFormMetadata = new TypedFormMetadata();
        $typedFormMetadata->addForm('hero', $form);

        $provider = $this->createStub(MetadataProviderInterface::class);
        $provider->method('getMetadata')->willReturn($typedFormMetadata);
        $registry = $this->createStub(MetadataProviderRegistry::class);
        $registry->method('getMetadataProvider')->willReturn($provider);

        $resolver = new BlockTargetFieldMetadataResolver($registry);

        $this->assertSame(['title' => 'string', 'image' => 'media'], $resolver->resolveFieldTypeMap('hero', 'en'));

        $groups = BlockTargetFieldMetadataResolver::extractFieldOptionGroups($titleField, 'en');
        $this->assertSame([
            [
                'name' => 'title_size',
                'storage_key' => 'props/title_size',
                'default' => 'lg',
                'values' => [
                    ['value' => 'md', 'label' => 'Medium'],
                    ['value' => 'lg', 'label' => 'Large'],
                ],
            ],
        ], $groups);

        $partitioned = BlockTargetFieldMetadataResolver::partitionBlockSections($form);
        $this->assertCount(2, $partitioned['content']);
        $this->assertCount(1, $partitioned['settings']);
        $this->assertNull($partitioned['advanced']);
        $this->assertNull($partitioned['dynamic_content']);
    }
}
