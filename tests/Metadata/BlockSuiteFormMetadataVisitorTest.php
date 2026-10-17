<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TagMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Symfony\Contracts\Translation\TranslatorInterface;
use Totoglu\SuluBlockSuiteBundle\Metadata\BlockSuiteFormMetadataVisitor;
use Totoglu\SuluBlockSuiteBundle\Preset\BlockPresetProviderInterface;

final class BlockSuiteFormMetadataVisitorTest extends TestCase
{
    public function testPopulatePresetSectionOptionsForwardsActiveLocaleToPresetProvider(): void
    {
        $presetProvider = $this->createMock(BlockPresetProviderInterface::class);
        $presetProvider->expects($this->once())
            ->method('getPresets')
            ->with('fixture_headline', 'tr')
            ->willReturn([
                [
                    'id' => 'hero_center_title',
                    'title' => 'Ortalanmış Hero Başlığı',
                    'description' => 'Ana başlık stili',
                    'props' => ['tag' => 'h1'],
                ],
            ]);

        $visitor = new BlockSuiteFormMetadataVisitor(presetProvider: $presetProvider);

        $headlineForm = new FormMetadata();
        $headlineForm->setKey('fixture_headline');

        $contentSection = new SectionMetadata('content');
        $contentSection->setLabel('İçerik', 'tr');
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');
        $contentSection->addItem($titleField);
        $headlineForm->addItem($contentSection);

        $typedForm = new TypedFormMetadata();
        $typedForm->addForm('fixture_headline', $headlineForm);

        $visitor->visitTypedFormMetadata($typedForm, 'block', 'tr');

        /** @var SectionMetadata $tabsSection */
        $tabsSection = $headlineForm->getItems()['__tabs'];
        /** @var SectionMetadata $advancedSection */
        $advancedSection = $tabsSection->getItems()['advanced'];
        /** @var FieldMetadata $presetField */
        $presetField = $advancedSection->getItems()['props/preset'];

        $presetsJson = $presetField->findOption('presets_json')?->getValue();
        $this->assertIsString($presetsJson);
        $decodedPresets = \json_decode($presetsJson, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame([
            [
                'id' => 'hero_center_title',
                'title' => 'Ortalanmış Hero Başlığı',
                'description' => 'Ana başlık stili',
                'props' => ['tag' => 'h1'],
            ],
        ], $decodedPresets);
    }

    public function testVisitTypedFormMetadataWrapsBlockIntoTabsAndNamespacesNonContentFields(): void
    {
        $visitor = new BlockSuiteFormMetadataVisitor();

        $headlineForm = new FormMetadata();
        $headlineForm->setKey('fixture_headline');

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

        $generalSection = new SectionMetadata('general');
        $generalSection->setLabel('General', 'en');
        $positionField = new FieldMetadata('position');
        $positionField->setType('single_select');
        $generalSection->addItem($positionField);
        $positionLeftField = new FieldMetadata('position_left');
        $positionLeftField->setType('text_line');
        $positionLeftField->setVisibleCondition('position == "relative" || position == "absolute"');
        $generalSection->addItem($positionLeftField);
        $headlineForm->addItem($generalSection);

        $typedForm = new TypedFormMetadata();
        $typedForm->addForm('fixture_headline', $headlineForm);

        $visitor->visitTypedFormMetadata($typedForm, 'block', 'en');

        $items = $headlineForm->getItems();
        $this->assertArrayHasKey('__tabs', $items);

        $tabsSection = $items['__tabs'];
        $this->assertInstanceOf(SectionMetadata::class, $tabsSection);
        $this->assertSame(['en' => '__tabs'], $tabsSection->getLabels());

        $tabItems = $tabsSection->getItems();
        $this->assertSame(['content', 'settings', 'general', 'dynamic_content', 'advanced'], \array_keys($tabItems));

        /** @var SectionMetadata $resolvedContent */
        $resolvedContent = $tabItems['content'];
        $this->assertArrayHasKey('title', $resolvedContent->getItems());

        /** @var SectionMetadata $resolvedSettings */
        $resolvedSettings = $tabItems['settings'];
        $this->assertArrayHasKey('props/tag', $resolvedSettings->getItems());

        /** @var SectionMetadata $resolvedGeneral */
        $resolvedGeneral = $tabItems['general'];
        $this->assertArrayHasKey('props/position', $resolvedGeneral->getItems());
        $this->assertArrayHasKey('props/position_left', $resolvedGeneral->getItems());
        /** @var FieldMetadata $resolvedPositionLeft */
        $resolvedPositionLeft = $resolvedGeneral->getItems()['props/position_left'];
        $this->assertSame(
            'position == "relative" || position == "absolute"',
            $resolvedPositionLeft->getVisibleCondition(),
        );

        /** @var SectionMetadata $resolvedAdvanced */
        $resolvedAdvanced = $tabItems['advanced'];
        $this->assertArrayHasKey('props/preset', $resolvedAdvanced->getItems());
        $this->assertArrayHasKey('props/id', $resolvedAdvanced->getItems());
        $this->assertArrayHasKey('props/class', $resolvedAdvanced->getItems());
    }

    public function testVisitTypedFormMetadataDoesNotInjectGeneralTabWhenBlockDoesNotDefineOne(): void
    {
        $visitor = new BlockSuiteFormMetadataVisitor();

        $reusableForm = new FormMetadata();
        $reusableForm->setKey('reusable');

        $contentSection = new SectionMetadata('content');
        $contentSection->setLabel('Content', 'en');
        $snippetField = new FieldMetadata('snippets');
        $snippetField->setType('snippet_selection');
        $contentSection->addItem($snippetField);
        $reusableForm->addItem($contentSection);

        $typedForm = new TypedFormMetadata();
        $typedForm->addForm('reusable', $reusableForm);

        $visitor->visitTypedFormMetadata($typedForm, 'block', 'en');

        /** @var SectionMetadata $tabsSection */
        $tabsSection = $reusableForm->getItems()['__tabs'];
        $this->assertSame(['content', 'dynamic_content', 'advanced'], \array_keys($tabsSection->getItems()));
    }

    public function testVisitTypedFormMetadataRespectsTabsExcludeTagAndCustomTabOrder(): void
    {
        $visitor = new BlockSuiteFormMetadataVisitor();

        $customBlock = new FormMetadata();
        $customBlock->setKey('custom_hero');

        $excludeTag = new TagMetadata();
        $excludeTag->setName('sulu_block_suite.tabs');
        $excludeTag->setAttributes(['exclude' => 'dynamic_content']);
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
        $buttonSubItem->setKey('fixture_button');

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
        $parentBlock->setKey('fixture_button');
        $parentBlock->addItem($itemsBlockField);

        $typedForm = new TypedFormMetadata();
        $typedForm->addForm('fixture_button', $parentBlock);

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
        $blockTagConfigs = [
            'reusable' => ['exclude' => 'dynamic_content'],
            'fixture_spacer' => ['exclude' => 'dynamic_content'],
            'fixture_section' => ['exclude' => 'dynamic_content'],
            'fixture_divider' => ['exclude' => 'dynamic_content'],
        ];

        $typedForm = new TypedFormMetadata();
        foreach ($blockTagConfigs as $key => $attributes) {
            $blockForm = new FormMetadata();
            $blockForm->setKey($key);

            $tabsTag = new TagMetadata();
            $tabsTag->setName('sulu_block_suite.tabs');
            $tabsTag->setAttributes($attributes);
            $blockForm->addTag($tabsTag);

            $contentSection = new SectionMetadata('content');
            $contentSection->setLabel('Content', 'en');
            $field = new FieldMetadata('sample');
            $field->setType('text_line');
            $contentSection->addItem($field);
            $blockForm->addItem($contentSection);

            $typedForm->addForm($key, $blockForm);
        }

        $visitor->visitTypedFormMetadata($typedForm, 'block', 'en');

        foreach (\array_keys($blockTagConfigs) as $key) {
            $blockForm = $typedForm->getForms()[$key];
            /** @var SectionMetadata $tabsSection */
            $tabsSection = $blockForm->getItems()['__tabs'];
            $tabKeys = \array_keys($tabsSection->getItems());

            $this->assertNotContains(
                'dynamic_content',
                $tabKeys,
                \sprintf('Block "%s" must not expose the dynamic_content tab.', $key),
            );
            $this->assertSame(['content', 'advanced'], $tabKeys);
        }
    }

    public function testDynamicMappingFieldMapOptionsAreIsolatedPerBlockAndSingleLocale(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string => match ([$id, $domain, $locale]) {
                ['sulu_block_suite.dynamic_field_title', 'admin', 'tr'] => 'Başlık',
                ['sulu_block_suite.dynamic_field_title', 'admin', 'en'] => 'Title',
                ['sulu_block_suite.tab_content', 'admin', 'tr'] => 'İçerik',
                ['sulu_block_suite.tab_content', 'admin', 'en'] => 'Content',
                default => $id,
            },
        );

        $visitor = new BlockSuiteFormMetadataVisitor(translator: $translator);

        $accordionForm = new FormMetadata();
        $accordionForm->setKey('fixture_accordion');
        $accContent = new SectionMetadata('content');
        $accItems = new FieldMetadata('items');
        $accItems->setType('block');
        $accItemType = new FormMetadata();
        $accItemType->setKey('fixture_accordion_item');
        $accItemHeading = new FieldMetadata('accordion_heading');
        $accItemHeading->setType('text_line');
        $accItemType->addItem($accItemHeading);
        $accItems->addType($accItemType);
        $accContent->addItem($accItems);
        $accordionForm->addItem($accContent);

        $headlineForm = new FormMetadata();
        $headlineForm->setKey('fixture_headline');
        $hlContent = new SectionMetadata('content');
        $hlTitle = new FieldMetadata('title');
        $hlTitle->setType('text_line');
        $hlContent->addItem($hlTitle);
        $headlineForm->addItem($hlContent);

        $typedForm = new TypedFormMetadata();
        $typedForm->addForm('fixture_accordion', $accordionForm);
        $typedForm->addForm('fixture_headline', $headlineForm);

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

        $hlTargetValues = $hlTarget->findOption('values')?->getValue();
        $this->assertIsArray($hlTargetValues);
        $this->assertNotEmpty($hlTargetValues);
        /** @var OptionMetadata $firstTargetOption */
        $firstTargetOption = $hlTargetValues[0];
        $this->assertSame(['en' => 'Title'], $firstTargetOption->getTitles());

        $trHeadlineForm = new FormMetadata();
        $trHeadlineForm->setKey('fixture_headline');
        $trHlContent = new SectionMetadata('content');
        $trHlTitle = new FieldMetadata('title');
        $trHlTitle->setType('text_line');
        $trHlContent->addItem($trHlTitle);
        $trHeadlineForm->addItem($trHlContent);

        $trTypedForm = new TypedFormMetadata();
        $trTypedForm->addForm('fixture_headline', $trHeadlineForm);
        $visitor->visitTypedFormMetadata($trTypedForm, 'block', 'tr');

        /** @var SectionMetadata $trTabs */
        $trTabs = $trHeadlineForm->getItems()['__tabs'];
        /** @var SectionMetadata $trContentSection */
        $trContentSection = $trTabs->getItems()['content'];
        $this->assertSame('İçerik', $trContentSection->getLabel('tr'));
        /** @var SectionMetadata $trDyn */
        $trDyn = $trTabs->getItems()['dynamic_content'];
        /** @var FieldMetadata $trMapping */
        $trMapping = $trDyn->getItems()['props/dynamic_mapping'];
        /** @var FieldMetadata $trTarget */
        $trTarget = $trMapping->getTypes()['field_map']->getItems()['target_field'];
        /** @var list<OptionMetadata> $trTargetValues */
        $trTargetValues = (array) $trTarget->findOption('values')?->getValue();
        $this->assertSame(['tr' => 'Başlık'], $trTargetValues[0]->getTitles());
    }

    public function testResolveBlockTitleDoesNotUseCatalogFallback(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->never())->method('trans');

        $visitor = new BlockSuiteFormMetadataVisitor(translator: $translator);
        $this->assertSame('Custom widget', $visitor->resolveBlockTitle('custom_widget', 'tr'));
    }

    public function testBlockWithEmptyContentSectionExposesZeroTargetFieldOptionsWithoutHardcodedFallbacks(): void
    {
        $visitor = new BlockSuiteFormMetadataVisitor();

        $emptyContentBlock = new FormMetadata();
        $emptyContentBlock->setKey('fixture_empty');
        $emptyContentBlock->addItem(new SectionMetadata('content'));

        $typedForm = new TypedFormMetadata();
        $typedForm->addForm('fixture_empty', $emptyContentBlock);

        $visitor->visitTypedFormMetadata($typedForm, 'block', 'en');

        /** @var SectionMetadata $tabs */
        $tabs = $emptyContentBlock->getItems()['__tabs'];
        /** @var SectionMetadata $dyn */
        $dyn = $tabs->getItems()['dynamic_content'];
        /** @var FieldMetadata $mapping */
        $mapping = $dyn->getItems()['props/dynamic_mapping'];
        /** @var FieldMetadata $targetField */
        $targetField = $mapping->getTypes()['field_map']->getItems()['target_field'];

        $this->assertNull($targetField->findOption('default_value')?->getValue());
        $this->assertSame([], $targetField->findOption('values')?->getValue());
    }

    public function testBlocksAreSortedAlphabeticallyByTitle(): void
    {
        $visitor = new BlockSuiteFormMetadataVisitor();

        $typedBlockForms = new TypedFormMetadata();
        foreach (['fixture_video' => 'Video', 'fixture_accordion' => 'Accordion', 'fixture_headline' => 'Headline', 'fixture_button' => 'Button'] as $key => $title) {
            $blockForm = new FormMetadata();
            $blockForm->setKey($key);
            $blockForm->setTitle($title, 'en');
            $content = new SectionMetadata('content');
            $field = new FieldMetadata('title');
            $field->setType('text_line');
            $content->addItem($field);
            $blockForm->addItem($content);
            $typedBlockForms->addForm($key, $blockForm);
        }

        $visitor->visitTypedFormMetadata($typedBlockForms, 'block', 'en');

        $this->assertSame(['fixture_accordion', 'fixture_button', 'fixture_headline', 'fixture_video'], \array_keys($typedBlockForms->getForms()));
    }

    public function testBlockPresetTargetBlockOptionsAreDerivedFromBlockFieldTypes(): void
    {
        $visitor = new BlockSuiteFormMetadataVisitor();

        $presetForm = new FormMetadata();
        $presetForm->setKey('block_preset');

        $targetBlockField = new FieldMetadata('target_block');
        $targetBlockField->setType('single_select');
        $presetForm->addItem($targetBlockField);

        $blockField = new FieldMetadata('block');
        $blockField->setType('block');

        foreach (['fixture_headline' => 'Headline', 'fixture_text' => 'Text', 'fixture_button' => 'Button'] as $key => $title) {
            $typeForm = new FormMetadata();
            $typeForm->setKey($key);
            $typeForm->setTitle($title, 'en');
            $blockField->addType($typeForm);
        }
        $presetForm->addItem($blockField);

        $visitor->visitFormMetadata($presetForm, 'en');

        $this->assertSame('fixture_headline', $targetBlockField->findOption('default_value')?->getValue());

        $valuesOption = $targetBlockField->findOption('values');
        $this->assertNotNull($valuesOption);
        $values = $valuesOption->getValue();
        $this->assertIsArray($values);
        $this->assertCount(3, $values);
        $this->assertSame('fixture_button', $values[0]->getName());
        $this->assertSame(['en' => 'Button'], $values[0]->getTitles());
        $this->assertSame('fixture_headline', $values[1]->getName());
        $this->assertSame(['en' => 'Headline'], $values[1]->getTitles());
        $this->assertSame('fixture_text', $values[2]->getName());
        $this->assertSame(['en' => 'Text'], $values[2]->getTitles());
    }

    public function testInlineSubTypeInheritsTabsTagWithSharedTabsAndNamespacedProps(): void
    {
        $visitor = new BlockSuiteFormMetadataVisitor();

        $inlineItem = new FormMetadata();
        $inlineItem->setKey('custom_row_item');

        $subContent = new SectionMetadata('content');
        $subContent->setLabel('Content', 'en');
        $subTitle = new FieldMetadata('title');
        $subTitle->setType('text_line');
        $subContent->addItem($subTitle);
        $inlineItem->addItem($subContent);

        $subSettings = new SectionMetadata('settings');
        $subSettings->setLabel('Settings', 'en');
        $layoutField = new FieldMetadata('layout');
        $layoutField->setType('single_select');
        $subSettings->addItem($layoutField);
        $inlineItem->addItem($subSettings);

        $rowsBlockField = new FieldMetadata('rows');
        $rowsBlockField->setType('block');
        $tabsTag = new TagMetadata();
        $tabsTag->setName('sulu_block_suite.tabs');
        $tabsTag->setAttributes([
            'shared' => 'true',
            'exclude' => 'dynamic_content',
        ]);
        $rowsBlockField->addTag($tabsTag);
        $rowsBlockField->addType($inlineItem);

        $parentBlock = new FormMetadata();
        $parentBlock->setKey('custom_section');
        $parentBlock->addItem($rowsBlockField);

        $typedForm = new TypedFormMetadata();
        $typedForm->addForm('custom_section', $parentBlock);

        $visitor->visitTypedFormMetadata($typedForm, 'block', 'en');

        $subItems = $inlineItem->getItems();
        $this->assertArrayHasKey('__tabs', $subItems);

        /** @var SectionMetadata $subTabsSection */
        $subTabsSection = $subItems['__tabs'];
        $subTabItems = $subTabsSection->getItems();
        $this->assertSame(['content', 'settings', 'advanced'], \array_keys($subTabItems));

        /** @var SectionMetadata $resolvedSubSettings */
        $resolvedSubSettings = $subTabItems['settings'];
        $this->assertArrayHasKey('props/layout', $resolvedSubSettings->getItems());
    }
}
