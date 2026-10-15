<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TagMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Totoglu\SuluBlockSuiteBundle\Metadata\BlockSuiteFormMetadataVisitor;

final class BlockSuiteFormMetadataVisitorTest extends TestCase
{
    public function testVisitTypedFormMetadataWrapsBlockIntoTabsAndNamespacesNonContentFields(): void
    {
        $visitor = new BlockSuiteFormMetadataVisitor();

        $headlineForm = new FormMetadata();
        $headlineForm->setKey('headline');

        $contentSection = new SectionMetadata('content');
        $contentSection->setLabel('Content', 'en');
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');
        $contentSection->addItem($titleField);
        $headlineForm->addItem($contentSection);

        $settingsSection = new SectionMetadata('settings');
        $settingsSection->setLabel('Settings', 'en');
        $tagField = new FieldMetadata('tag');
        $tagField->setType('single_select');
        $settingsSection->addItem($tagField);
        $headlineForm->addItem($settingsSection);

        $typedForm = new TypedFormMetadata();
        $typedForm->addForm('headline', $headlineForm);

        $visitor->visitTypedFormMetadata($typedForm, 'block', 'en');

        $items = $headlineForm->getItems();
        $this->assertArrayHasKey('__tabs', $items);

        $tabsSection = $items['__tabs'];
        $this->assertInstanceOf(SectionMetadata::class, $tabsSection);
        $this->assertSame('__tabs', $tabsSection->getLabel('en'));

        $tabItems = $tabsSection->getItems();
        $this->assertArrayHasKey('content', $tabItems);
        $this->assertArrayHasKey('settings', $tabItems);
        $this->assertArrayHasKey('general', $tabItems);
        $this->assertArrayHasKey('dynamic_content', $tabItems);
        $this->assertArrayHasKey('advanced', $tabItems);

        /** @var SectionMetadata $resolvedContent */
        $resolvedContent = $tabItems['content'];
        $this->assertArrayHasKey('title', $resolvedContent->getItems());

        /** @var SectionMetadata $resolvedSettings */
        $resolvedSettings = $tabItems['settings'];
        $this->assertArrayHasKey('props/tag', $resolvedSettings->getItems());

        /** @var SectionMetadata $resolvedAdvanced */
        $resolvedAdvanced = $tabItems['advanced'];
        $this->assertArrayHasKey('props/preset', $resolvedAdvanced->getItems());
        $this->assertArrayHasKey('props/id', $resolvedAdvanced->getItems());
        $this->assertArrayHasKey('props/class', $resolvedAdvanced->getItems());
    }

    public function testVisitTypedFormMetadataRespectsTabsExcludeTagAndCustomTabOrder(): void
    {
        $visitor = new BlockSuiteFormMetadataVisitor();

        $customBlock = new FormMetadata();
        $customBlock->setKey('custom_hero');

        $excludeTag = new TagMetadata();
        $excludeTag->setName('sulu_block_suite.tabs');
        $excludeTag->setAttributes(['exclude' => 'dynamic_content,general']);
        $customBlock->addTag($excludeTag);

        $contentSection = new SectionMetadata('content');
        $contentSection->setLabel('Content', 'en');
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');
        $contentSection->addItem($titleField);
        $customBlock->addItem($contentSection);

        $styleSection = new SectionMetadata('style');
        $styleSection->setLabel('Style', 'en');
        $themeField = new FieldMetadata('theme');
        $themeField->setType('single_select');
        $styleSection->addItem($themeField);
        $customBlock->addItem($styleSection);

        $typedForm = new TypedFormMetadata();
        $typedForm->addForm('custom_hero', $customBlock);

        $visitor->visitTypedFormMetadata($typedForm, 'block', 'en');

        /** @var SectionMetadata $tabsSection */
        $tabsSection = $customBlock->getItems()['__tabs'];
        $tabItems = $tabsSection->getItems();

        $this->assertSame(['content', 'style', 'advanced'], \array_keys($tabItems));
        $this->assertArrayNotHasKey('general', $tabItems);
        $this->assertArrayNotHasKey('dynamic_content', $tabItems);

        /** @var SectionMetadata $resolvedStyle */
        $resolvedStyle = $tabItems['style'];
        $this->assertArrayHasKey('props/theme', $resolvedStyle->getItems());
    }

    public function testVisitFormMetadataEnhancesInlineSubItemTabsWithFlatFieldNames(): void
    {
        $visitor = new BlockSuiteFormMetadataVisitor();

        $buttonSubItem = new FormMetadata();
        $buttonSubItem->setKey('button');

        $subContent = new SectionMetadata('content');
        $subContent->setLabel('Content', 'en');
        $subTitle = new FieldMetadata('title');
        $subTitle->setType('text_line');
        $subContent->addItem($subTitle);
        $buttonSubItem->addItem($subContent);

        $subSettings = new SectionMetadata('settings');
        $subSettings->setLabel('Settings', 'en');
        $buttonStyle = new FieldMetadata('button_style');
        $buttonStyle->setType('single_select');
        $subSettings->addItem($buttonStyle);
        $buttonSubItem->addItem($subSettings);

        $itemsBlockField = new FieldMetadata('items');
        $itemsBlockField->setType('block');
        $itemsBlockField->addType($buttonSubItem);

        $parentBlock = new FormMetadata();
        $parentBlock->setKey('button');
        $parentBlock->addItem($itemsBlockField);

        $typedForm = new TypedFormMetadata();
        $typedForm->addForm('button', $parentBlock);

        $visitor->visitTypedFormMetadata($typedForm, 'block', 'en');

        $subItems = $buttonSubItem->getItems();
        $this->assertArrayHasKey('__tabs', $subItems);

        /** @var SectionMetadata $subTabsSection */
        $subTabsSection = $subItems['__tabs'];
        $subTabItems = $subTabsSection->getItems();
        $this->assertSame(['content', 'settings'], \array_keys($subTabItems));

        /** @var SectionMetadata $resolvedSubContent */
        $resolvedSubContent = $subTabItems['content'];
        $this->assertArrayHasKey('title', $resolvedSubContent->getItems());

        /** @var SectionMetadata $resolvedSubSettings */
        $resolvedSubSettings = $subTabItems['settings'];
        $this->assertArrayHasKey('button_style', $resolvedSubSettings->getItems());
        $this->assertArrayNotHasKey('props/button_style', $resolvedSubSettings->getItems());
    }

    public function testNonDynamicBlocksAutomaticallyOmitDynamicContentTab(): void
    {
        $visitor = new BlockSuiteFormMetadataVisitor();
        $nonDynamicKeys = [
            'reusable',
            'spacer',
            'section',
            'row',
            'row_item',
            'column',
            'column_item',
            'divider',
            'icon',
            'html',
            'code',
            'breadcrumbs',
            'scroll_to',
            'countdown',
            'social',
            'popover',
            'map',
            'action-block-empty',
            'action-blocks',
        ];

        $typedForm = new TypedFormMetadata();
        foreach ($nonDynamicKeys as $key) {
            $blockForm = new FormMetadata();
            $blockForm->setKey($key);

            $contentSection = new SectionMetadata('content');
            $contentSection->setLabel('Content', 'en');
            $field = new FieldMetadata('sample');
            $field->setType('text_line');
            $contentSection->addItem($field);
            $blockForm->addItem($contentSection);

            $typedForm->addForm($key, $blockForm);
        }

        $visitor->visitTypedFormMetadata($typedForm, 'block', 'en');

        foreach ($nonDynamicKeys as $key) {
            $blockForm = $typedForm->getForms()[$key];
            /** @var SectionMetadata $tabsSection */
            $tabsSection = $blockForm->getItems()['__tabs'];
            $tabKeys = \array_keys($tabsSection->getItems());

            $this->assertNotContains(
                'dynamic_content',
                $tabKeys,
                \sprintf('Block "%s" must not expose the dynamic_content tab.', $key),
            );
            $this->assertSame(['content', 'general', 'advanced'], $tabKeys);
        }
    }

    public function testDynamicMappingFieldMapOptionsAreIsolatedPerBlock(): void
    {
        $visitor = new BlockSuiteFormMetadataVisitor();

        $accordionForm = new FormMetadata();
        $accordionForm->setKey('accordion');
        $accContent = new SectionMetadata('content');
        $accItems = new FieldMetadata('items');
        $accItems->setType('block');
        $accItemType = new FormMetadata();
        $accItemType->setKey('accordion_item');
        $accItemHeading = new FieldMetadata('accordion_heading');
        $accItemHeading->setType('text_line');
        $accItemType->addItem($accItemHeading);
        $accItems->addType($accItemType);
        $accContent->addItem($accItems);
        $accordionForm->addItem($accContent);

        $headlineForm = new FormMetadata();
        $headlineForm->setKey('headline');
        $hlContent = new SectionMetadata('content');
        $hlTitle = new FieldMetadata('title');
        $hlTitle->setType('text_line');
        $hlContent->addItem($hlTitle);
        $headlineForm->addItem($hlContent);

        $typedForm = new TypedFormMetadata();
        $typedForm->addForm('accordion', $accordionForm);
        $typedForm->addForm('headline', $headlineForm);

        $visitor->visitTypedFormMetadata($typedForm, 'block', 'en');

        /** @var SectionMetadata $accTabs */
        $accTabs = $accordionForm->getItems()['__tabs'];
        /** @var SectionMetadata $accDyn */
        $accDyn = $accTabs->getItems()['dynamic_content'];
        /** @var FieldMetadata $accMapping */
        $accMapping = $accDyn->getItems()['props/dynamic_mapping'];
        $accTarget = $accMapping->getTypes()['field_map']->getItems()['target_field'];
        $this->assertInstanceOf(FieldMetadata::class, $accTarget);
        $this->assertSame('accordion_heading', $accTarget->findOption('default_value')?->getValue());

        /** @var SectionMetadata $hlTabs */
        $hlTabs = $headlineForm->getItems()['__tabs'];
        /** @var SectionMetadata $hlDyn */
        $hlDyn = $hlTabs->getItems()['dynamic_content'];
        /** @var FieldMetadata $hlMapping */
        $hlMapping = $hlDyn->getItems()['props/dynamic_mapping'];
        $hlTarget = $hlMapping->getTypes()['field_map']->getItems()['target_field'];
        $this->assertInstanceOf(FieldMetadata::class, $hlTarget);
        $this->assertSame('title', $hlTarget->findOption('default_value')?->getValue());
    }

    public function testExcludedTopLevelBlockStillRegistersSchemaDefinitionForNestedReferences(): void
    {
        $visitor = new BlockSuiteFormMetadataVisitor();

        $reusableBlockForm = new FormMetadata();
        $reusableBlockForm->setKey('reusable');
        $reusableContent = new SectionMetadata('content');
        $snippetField = new FieldMetadata('snippet');
        $snippetField->setType('single_reusable_block_selection');
        $reusableContent->addItem($snippetField);
        $reusableBlockForm->addItem($reusableContent);

        $sectionBlockForm = new FormMetadata();
        $sectionBlockForm->setKey('section');
        $sectionContent = new SectionMetadata('content');
        $rowsField = new FieldMetadata('rows');
        $rowsField->setType('block');
        $sectionContent->addItem($rowsField);
        $sectionBlockForm->addItem($sectionContent);

        $typedBlockForms = new TypedFormMetadata();
        $typedBlockForms->addForm('reusable', $reusableBlockForm);
        $typedBlockForms->addForm('section', $sectionBlockForm);

        $visitor->visitTypedFormMetadata($typedBlockForms, 'block', 'en');

        $snippetForm = new FormMetadata();
        $snippetForm->setKey('reusable_block');
        $blocksField = new FieldMetadata('blocks');
        $blocksField->setType('block');
        $autoBlocksTag = new TagMetadata();
        $autoBlocksTag->setName('sulu_block_suite.auto_blocks');
        $autoBlocksTag->setAttributes(['exclude' => 'reusable']);
        $blocksField->addTag($autoBlocksTag);
        $snippetForm->addItem($blocksField);

        $visitor->visitFormMetadata($snippetForm, 'en');

        $this->assertArrayNotHasKey('reusable', $blocksField->getTypes());
        $this->assertArrayHasKey('section', $blocksField->getTypes());

        $jsonSchema = $snippetForm->getSchema()->toJsonSchema();
        $this->assertArrayHasKey('definitions', $jsonSchema);
        $this->assertIsArray($jsonSchema['definitions']);
        $this->assertArrayHasKey('section', $jsonSchema['definitions']);
        $this->assertArrayHasKey('reusable', $jsonSchema['definitions']);
    }
}
