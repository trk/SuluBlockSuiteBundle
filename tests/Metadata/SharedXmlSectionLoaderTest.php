<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Totoglu\SuluBlockSuiteBundle\Metadata\SharedXmlSectionLoader;

final class SharedXmlSectionLoaderTest extends TestCase
{
    public function testLoadsSharedAdvancedAndDynamicSectionsWithIndependentClones(): void
    {
        $loader = new SharedXmlSectionLoader();

        $advanced1 = $loader->loadSharedSection('block_suite_advanced', 'advanced');
        $advanced2 = $loader->loadSharedSection('block_suite_advanced', 'advanced');

        self::assertInstanceOf(SectionMetadata::class, $advanced1);
        self::assertInstanceOf(SectionMetadata::class, $advanced2);
        self::assertNotSame($advanced1, $advanced2);
        self::assertArrayHasKey('preset', $advanced1->getItems());

        $dynamic = $loader->loadSharedSection('block_suite_dynamic', 'dynamic_content');
        self::assertInstanceOf(SectionMetadata::class, $dynamic);
        self::assertArrayHasKey('dynamic_source', $dynamic->getItems());
        self::assertArrayHasKey('dynamic_mapping', $dynamic->getItems());
    }

    public function testNamespacesFieldsAndNormalizesBooleanAndNumberDefaults(): void
    {
        $loader = new SharedXmlSectionLoader();

        $section = new SectionMetadata('settings');

        $checkbox = new FieldMetadata('full_width');
        $checkbox->setType('checkbox');
        $cbDefault = new OptionMetadata();
        $cbDefault->setName('default_value');
        $cbDefault->setValue('true');
        $checkbox->addOption($cbDefault);

        $number = new FieldMetadata('columns');
        $number->setType('number');
        $numDefault = new OptionMetadata();
        $numDefault->setName('default_value');
        $numDefault->setValue('3');
        $number->addOption($numDefault);

        $hiddenProps = new FieldMetadata('props');
        $hiddenProps->setType('preset_selection');
        $hiddenProps->setVisibleCondition('false');

        $section->addItem($checkbox);
        $section->addItem($number);
        $section->addItem($hiddenProps);

        $namespaced = $loader->namespaceSectionFields($section, 'props/');
        $items = $namespaced->getItems();

        self::assertArrayHasKey('props/full_width', $items);
        self::assertArrayHasKey('props/columns', $items);
        self::assertArrayHasKey('props', $items);

        $nsCheckbox = $items['props/full_width'];
        self::assertInstanceOf(FieldMetadata::class, $nsCheckbox);
        self::assertTrue($nsCheckbox->findOption('default_value')?->getValue());

        $nsNumber = $items['props/columns'];
        self::assertInstanceOf(FieldMetadata::class, $nsNumber);
        self::assertSame(3, $nsNumber->findOption('default_value')?->getValue());
    }

    public function testMergesPrimaryAndSecondarySectionsPreservingPrimaryItems(): void
    {
        $loader = new SharedXmlSectionLoader();

        $primary = new SectionMetadata('advanced');
        $primary->setLabels(['en' => 'Custom Advanced']);
        $f1 = new FieldMetadata('custom_class');
        $f1->setType('text_line');
        $primary->addItem($f1);

        $secondary = new SectionMetadata('advanced');
        $secondary->setLabels(['en' => 'Default Advanced']);
        $f1Duplicate = new FieldMetadata('custom_class');
        $f1Duplicate->setType('text_area');
        $f2 = new FieldMetadata('preset');
        $f2->setType('preset_selection');
        $secondary->addItem($f1Duplicate);
        $secondary->addItem($f2);

        $merged = $loader->mergeSections($primary, $secondary);

        self::assertSame(['en' => 'Custom Advanced'], $merged->getLabels());
        self::assertCount(2, $merged->getItems());
        $customClassField = $merged->getItems()['custom_class'];
        self::assertInstanceOf(FieldMetadata::class, $customClassField);
        self::assertSame('text_line', $customClassField->getType());
        self::assertArrayHasKey('preset', $merged->getItems());
    }
}
