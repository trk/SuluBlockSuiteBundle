<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Totoglu\SuluBlockSuiteBundle\Metadata\DynamicSectionFormBuilder;
use Totoglu\SuluBlockSuiteBundle\Metadata\SharedXmlSectionLoader;

final class DynamicSectionFormBuilderTest extends TestCase
{
    public function testPopulatesDynamicSourceAndMappingOptionsForSingleAndMultiItemBlocks(): void
    {
        $loader = new SharedXmlSectionLoader();
        $builder = new DynamicSectionFormBuilder();

        $dynamicSection = $loader->loadSharedSection('block_suite_dynamic', 'dynamic_content');
        self::assertInstanceOf(SectionMetadata::class, $dynamicSection);

        $contentSection = new SectionMetadata('content');
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');
        $titleField->setLabel('Title', 'en');
        $contentSection->addItem($titleField);

        $builder->populateDynamicSectionOptions($dynamicSection, $contentSection, 'en');

        $sourceField = $dynamicSection->getItems()['dynamic_source'] ?? null;
        self::assertInstanceOf(FieldMetadata::class, $sourceField);

        $valuesOption = $sourceField->findOption('values');
        self::assertInstanceOf(OptionMetadata::class, $valuesOption);
        $sourceOpts = $valuesOption->getValue();
        self::assertIsArray($sourceOpts);

        $sourceKeys = [];
        foreach ($sourceOpts as $opt) {
            $sourceKeys[] = $opt->getName();
        }

        self::assertContains('none', $sourceKeys);
        self::assertContains('current_page', $sourceKeys);
        self::assertNotContains('page_children', $sourceKeys);
    }

    public function testInjectsIsDynamicTemplateCheckboxIntoItemsSubBlockTypes(): void
    {
        $builder = new DynamicSectionFormBuilder();

        $contentSection = new SectionMetadata('content');
        $itemsBlock = new FieldMetadata('items');
        $itemsBlock->setType('block');

        $subType = new FormMetadata();
        $subType->setKey('card_item');
        $headingField = new FieldMetadata('heading');
        $headingField->setType('text_line');
        $subType->addItem($headingField);
        $itemsBlock->addType($subType);
        $contentSection->addItem($itemsBlock);

        $builder->injectDynamicTemplateFieldIntoSubItems($contentSection, 'en');

        self::assertArrayHasKey('is_dynamic_template', $subType->getItems());
        $injected = $subType->getItems()['is_dynamic_template'];
        self::assertInstanceOf(FieldMetadata::class, $injected);
        self::assertSame('checkbox', $injected->getType());
    }
}
