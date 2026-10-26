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
use Sulu\Bundle\AdminBundle\Metadata\MetadataInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;
use Symfony\Component\DependencyInjection\Container;
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
        $this->assertSame(['content', 'settings', 'general', 'advanced', 'dynamic_content'], \array_keys($tabItems));

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
        $this->assertSame(['content', 'advanced', 'dynamic_content'], \array_keys($tabsSection->getItems()));
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

    public function testReusableBlockHasTabsDisabledAndKeepsDirectSnippetField(): void
    {
        $visitor = new BlockSuiteFormMetadataVisitor();

        $xmlPath = \dirname(__DIR__, 2) . '/config/templates/blocks/reusable.xml';
        $dom = new \DOMDocument();
        self::assertTrue($dom->load($xmlPath));
        $xml = \simplexml_import_dom($dom);
        self::assertNotNull($xml);

        $reusableForm = new FormMetadata();
        $reusableForm->setKey((string) $xml->key);

        foreach ($xml->tag as $tagNode) {
            $tag = new TagMetadata();
            $tag->setName((string) ($tagNode['name'] ?? ''));
            $attrs = [];
            foreach ($tagNode->attributes() as $attrName => $attrVal) {
                if ('name' !== $attrName) {
                    $attrs[$attrName] = (string) $attrVal;
                }
            }
            $tag->setAttributes($attrs);
            $reusableForm->addTag($tag);
        }

        $snippetField = new FieldMetadata('snippet');
        $snippetField->setType('single_reusable_block_selection');
        $reusableForm->addItem($snippetField);

        $typedForm = new TypedFormMetadata();
        $typedForm->addForm('reusable', $reusableForm);

        $visitor->visitTypedFormMetadata($typedForm, 'block', 'en');

        $items = $reusableForm->getItems();
        self::assertArrayNotHasKey('__tabs', $items);
        self::assertSame(['snippet'], \array_keys($items));
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

    public function testMultipleBlockSubItemTypesReceiveIsDynamicTemplateCheckboxFieldExcludedFromTargetMapping(): void
    {
        $visitor = new BlockSuiteFormMetadataVisitor();

        $cardSubItem = new FormMetadata();
        $cardSubItem->setKey('card');
        $subContent = new SectionMetadata('content');
        $subContent->setLabel('Content', 'en');
        $subTitle = new FieldMetadata('title');
        $subTitle->setType('text_line');
        $subContent->addItem($subTitle);
        $cardSubItem->addItem($subContent);
        $subSettings = new SectionMetadata('settings');
        $subSettings->setLabel('Settings', 'en');
        $styleField = new FieldMetadata('panel_style');
        $styleField->setType('single_select');
        $subSettings->addItem($styleField);
        $cardSubItem->addItem($subSettings);

        $itemsBlock = new FieldMetadata('items');
        $itemsBlock->setType('block');
        $itemsBlock->addType($cardSubItem);

        $gridBlock = new FormMetadata();
        $gridBlock->setKey('grid');
        $gridContent = new SectionMetadata('content');
        $gridContent->addItem($itemsBlock);
        $gridBlock->addItem($gridContent);

        $typedForm = new TypedFormMetadata();
        $typedForm->addForm('grid', $gridBlock);

        $visitor->visitTypedFormMetadata($typedForm, 'block', 'en');

        /** @var SectionMetadata $subTabsSection */
        $subTabsSection = $cardSubItem->getItems()['__tabs'];
        /** @var SectionMetadata $resolvedSubContent */
        $resolvedSubContent = $subTabsSection->getItems()['content'];
        $subContentItems = $resolvedSubContent->getItems();

        $this->assertArrayHasKey('is_dynamic_template', $subContentItems);
        /** @var FieldMetadata $templateField */
        $templateField = $subContentItems['is_dynamic_template'];
        $this->assertSame('checkbox', $templateField->getType());
        $this->assertFalse($templateField->findOption('default_value')?->getValue());
        $this->assertSame(
            "__parent.__parent.dynamic_source != null and __parent.__parent.dynamic_source != 'none' and __parent.__parent.dynamic_source != ''",
            $templateField->getVisibleCondition(),
        );

        // Must NOT be exposed as a mappable target field in dynamic_mapping
        /** @var SectionMetadata $gridTabs */
        $gridTabs = $gridBlock->getItems()['__tabs'];
        /** @var SectionMetadata $gridDyn */
        $gridDyn = $gridTabs->getItems()['dynamic_content'];
        /** @var FieldMetadata $gridMapping */
        $gridMapping = $gridDyn->getItems()['props/dynamic_mapping'];
        $targetSelect = $gridMapping->getTypes()['field_map']->getItems()['target_field'];
        $this->assertInstanceOf(FieldMetadata::class, $targetSelect);
        /** @var list<OptionMetadata> $targetValues */
        $targetValues = (array) $targetSelect->findOption('values')?->getValue();
        $targetNames = \array_map(static fn (OptionMetadata $opt): string => (string) $opt->getName(), $targetValues);
        $this->assertContains('title', $targetNames);
        $this->assertNotContains('is_dynamic_template', $targetNames);
    }

    public function testDynamicContentSectionExposesSummaryEmptyStateFieldsAndSubBlockCollectionTargets(): void
    {
        $visitor = new BlockSuiteFormMetadataVisitor();

        $cardSubItem = new FormMetadata();
        $cardSubItem->setKey('card');
        $subContent = new SectionMetadata('content');
        $subTitle = new FieldMetadata('title');
        $subTitle->setType('text_line');
        $subContent->addItem($subTitle);

        $elementsField = new FieldMetadata('elements');
        $elementsField->setType('block');
        $elementsField->setLabel('Elements', 'en');
        $buttonType = new FormMetadata();
        $buttonType->setKey('button');
        $buttonType->setTitle('Buttons', 'en');
        $elementsField->addType($buttonType);
        $badgeType = new FormMetadata();
        $badgeType->setKey('badge');
        $badgeType->setTitle('Badges', 'en');
        $elementsField->addType($badgeType);
        $subContent->addItem($elementsField);

        $cardSubItem->addItem($subContent);

        $itemsBlock = new FieldMetadata('items');
        $itemsBlock->setType('block');
        $itemsBlock->addType($cardSubItem);

        $gridBlock = new FormMetadata();
        $gridBlock->setKey('grid');
        $gridContent = new SectionMetadata('content');
        $gridContent->addItem($itemsBlock);
        $gridBlock->addItem($gridContent);

        $typedForm = new TypedFormMetadata();
        $typedForm->addForm('grid', $gridBlock);

        $visitor->visitTypedFormMetadata($typedForm, 'block', 'en');

        /** @var SectionMetadata $gridTabs */
        $gridTabs = $gridBlock->getItems()['__tabs'];
        /** @var SectionMetadata $gridDyn */
        $gridDyn = $gridTabs->getItems()['dynamic_content'];
        $dynItems = $gridDyn->getItems();

        $this->assertArrayHasKey('props/dynamic_show_summary', $dynItems);
        $this->assertArrayHasKey('props/dynamic_summary_text', $dynItems);
        $this->assertArrayHasKey('props/dynamic_filtered_summary_text', $dynItems);
        $this->assertArrayHasKey('props/dynamic_empty_message', $dynItems);

        /** @var FieldMetadata $gridMapping */
        $gridMapping = $dynItems['props/dynamic_mapping'];
        $targetSelect = $gridMapping->getTypes()['field_map']->getItems()['target_field'];
        $this->assertInstanceOf(FieldMetadata::class, $targetSelect);
        /** @var list<OptionMetadata> $targetValues */
        $targetValues = (array) $targetSelect->findOption('values')?->getValue();
        $targetNames = \array_map(static fn (OptionMetadata $opt): string => (string) $opt->getName(), $targetValues);

        $this->assertContains('title', $targetNames);
        $this->assertContains('elements', $targetNames);
        $this->assertContains('buttons', $targetNames);
        $this->assertContains('badges', $targetNames);
    }

    public function testPopoverOptionGroupsRegisterBackingFieldsInjectStorageKeyAndRemoveDuplicateSettingsFields(): void
    {
        $visitor = new BlockSuiteFormMetadataVisitor();

        $headlineBlock = new FormMetadata();
        $headlineBlock->setKey('headline');

        $contentSection = new SectionMetadata('content');
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');

        $tagCollection = new OptionMetadata();
        $tagCollection->setName('tag');
        $tagCollection->setType(OptionMetadata::TYPE_COLLECTION);

        $defaultOpt = new OptionMetadata();
        $defaultOpt->setName('_default');
        $defaultOpt->setValue('h2');
        $tagCollection->addValueOption($defaultOpt);

        $iconOpt = new OptionMetadata();
        $iconOpt->setName('_icon');
        $iconOpt->setValue('heading');
        $tagCollection->addValueOption($iconOpt);

        $h1Opt = new OptionMetadata();
        $h1Opt->setName('h1');
        $h1Opt->setValue('H1');
        $tagCollection->addValueOption($h1Opt);

        $h2Opt = new OptionMetadata();
        $h2Opt->setName('h2');
        $h2Opt->setValue('H2');
        $tagCollection->addValueOption($h2Opt);

        $titleField->addOption($tagCollection);

        $colorCollection = new OptionMetadata();
        $colorCollection->setName('title_color');
        $colorCollection->setType(OptionMetadata::TYPE_COLLECTION);

        $colorIconOpt = new OptionMetadata();
        $colorIconOpt->setName('_icon');
        $colorIconOpt->setValue('brush');
        $colorCollection->addValueOption($colorIconOpt);

        $primaryOpt = new OptionMetadata();
        $primaryOpt->setName('primary');
        $primaryOpt->setValue('Primary Blue');
        $colorCollection->addValueOption($primaryOpt);

        $darkOpt = new OptionMetadata();
        $darkOpt->setName('dark');
        $darkOpt->setValue('Dark');
        $colorCollection->addValueOption($darkOpt);

        $titleField->addOption($colorCollection);
        $contentSection->addItem($titleField);
        $headlineBlock->addItem($contentSection);

        // Settings section with a duplicate 'tag' property and no other fields -> should be pruned when empty!
        $settingsSection = new SectionMetadata('settings');
        $duplicateTagField = new FieldMetadata('tag');
        $duplicateTagField->setType('single_select');
        $settingsSection->addItem($duplicateTagField);
        $headlineBlock->addItem($settingsSection);

        $typedForm = new TypedFormMetadata();
        $typedForm->addForm('headline', $headlineBlock);

        $visitor->visitTypedFormMetadata($typedForm, 'block', 'en');

        /** @var SectionMetadata $tabs */
        $tabs = $headlineBlock->getItems()['__tabs'];
        $tabItems = $tabs->getItems();

        // Duplicate 'tag' was removed from settings, leaving settings empty so the settings tab is omitted!
        $this->assertArrayNotHasKey('settings', $tabItems);

        /** @var SectionMetadata $enhancedContent */
        $enhancedContent = $tabItems['content'];
        $contentItems = $enhancedContent->getItems();

        // Verify no redundant hidden backing fields are added to content section
        $this->assertArrayNotHasKey('props/tag', $contentItems);
        $this->assertArrayNotHasKey('props/title_color', $contentItems);

        // Verify _storage_key, _default, and localized option titles were injected into title's collections
        /** @var FieldMetadata $enhancedTitle */
        $enhancedTitle = $contentItems['title'];
        $enhancedTagCollection = $enhancedTitle->findOption('tag');
        $this->assertNotNull($enhancedTagCollection);
        /** @var list<OptionMetadata> $tagChildren */
        $tagChildren = (array) $enhancedTagCollection->getValue();
        $childByName = [];
        foreach ($tagChildren as $child) {
            $childByName[(string) $child->getName()] = $child;
        }
        $this->assertArrayHasKey('_storage_key', $childByName);
        $this->assertSame('props/tag', $childByName['_storage_key']->getValue());
        $this->assertArrayHasKey('_default', $childByName);
        $this->assertSame('h2', $childByName['_default']->getValue());
        $this->assertSame('H1', $childByName['h1']->getTitle('en'));
        $this->assertSame('H2', $childByName['h2']->getTitle('en'));

        $enhancedColorCollection = $enhancedTitle->findOption('title_color');
        $this->assertNotNull($enhancedColorCollection);
        /** @var list<OptionMetadata> $colorChildren */
        $colorChildren = (array) $enhancedColorCollection->getValue();
        $colorChildByName = [];
        foreach ($colorChildren as $child) {
            $colorChildByName[(string) $child->getName()] = $child;
        }
        $this->assertArrayHasKey('_storage_key', $colorChildByName);
        $this->assertSame('props/title_color', $colorChildByName['_storage_key']->getValue());
        // When _default is omitted in XML, defaults to first selectable option ('primary')
        $this->assertArrayHasKey('_default', $colorChildByName);
        $this->assertSame('primary', $colorChildByName['_default']->getValue());

        // Verify Dynamic Content target_field dropdown does not include hidden backing fields
        /** @var SectionMetadata $dynSection */
        $dynSection = $tabItems['dynamic_content'];
        /** @var FieldMetadata $dynMapping */
        $dynMapping = $dynSection->getItems()['props/dynamic_mapping'];
        $targetSelect = $dynMapping->getTypes()['field_map']->getItems()['target_field'];
        $this->assertInstanceOf(FieldMetadata::class, $targetSelect);
        /** @var list<OptionMetadata> $targetValues */
        $targetValues = (array) $targetSelect->findOption('values')?->getValue();
        $targetNames = \array_map(static fn (OptionMetadata $opt): string => (string) $opt->getName(), $targetValues);
        $this->assertContains('title', $targetNames);
        $this->assertNotContains('props/tag', $targetNames);
        $this->assertNotContains('tag', $targetNames);
    }

    public function testPopulatesSingleTemplateSelectionAndTemplateSelectionFields(): void
    {
        $pageForms = new TypedFormMetadata();
        $defaultPage = new FormMetadata();
        $defaultPage->setKey('default');
        $defaultPage->setTitles(['en' => 'Default Page', 'tr' => 'Varsayılan Sayfa']);
        $pageForms->addForm('default', $defaultPage);

        $landingPage = new FormMetadata();
        $landingPage->setKey('landing');
        $landingPage->setTitles(['en' => 'Landing Page', 'tr' => 'Karşılama Sayfası']);
        $pageForms->addForm('landing', $landingPage);

        $articleForms = new TypedFormMetadata();
        $blogArticle = new FormMetadata();
        $blogArticle->setKey('blog_post');
        $blogArticle->setGroup('blog');
        $blogArticle->setTitles(['en' => 'Blog Post', 'tr' => 'Blog Yazısı']);
        $articleForms->addForm('blog_post', $blogArticle);

        $newsArticle = new FormMetadata();
        $newsArticle->setKey('news_item');
        $newsArticle->setGroup('news');
        $newsArticle->setTitles(['en' => 'News Item', 'tr' => 'Haber']);
        $articleForms->addForm('news_item', $newsArticle);

        $formProvider = new class($pageForms, $articleForms) implements MetadataProviderInterface {
            public function __construct(
                private readonly TypedFormMetadata $pageForms,
                private readonly TypedFormMetadata $articleForms,
            ) {
            }

            public function getMetadata(string $key, string $locale, array $metadataOptions = []): MetadataInterface
            {
                return match ($key) {
                    'page' => $this->pageForms,
                    'article' => $this->articleForms,
                    default => new TypedFormMetadata(),
                };
            }
        };

        $container = new Container();
        $container->set('form', $formProvider);
        $registry = new MetadataProviderRegistry($container);

        $visitor = new BlockSuiteFormMetadataVisitor(
            metadataProviderRegistry: $registry,
        );

        $blockForm = new FormMetadata();
        $blockForm->setKey('content_listing');

        $contentSection = new SectionMetadata('content');

        // 1. Default single_template_selection (defaults to 'page' templates)
        $singleTemplateField = new FieldMetadata('page_template');
        $singleTemplateField->setType('single_template_selection');
        $singleTemplateField->setRequired(true);
        $contentSection->addItem($singleTemplateField);

        // 2. Filtered template_selection for 'articles' with group='blog'
        $multiTemplateField = new FieldMetadata('article_templates');
        $multiTemplateField->setType('template_selection');
        $typeOpt = new OptionMetadata();
        $typeOpt->setName('template_type');
        $typeOpt->setValue('articles');
        $multiTemplateField->addOption($typeOpt);
        $groupOpt = new OptionMetadata();
        $groupOpt->setName('groups');
        $groupOpt->setValue('blog');
        $multiTemplateField->addOption($groupOpt);
        $contentSection->addItem($multiTemplateField);

        $blockForm->addItem($contentSection);

        $typedBlockForms = new TypedFormMetadata();
        $typedBlockForms->addForm('content_listing', $blockForm);

        $visitor->visitTypedFormMetadata($typedBlockForms, 'block', 'en');

        /** @var SectionMetadata $tabs */
        $tabs = $blockForm->getItems()['__tabs'];
        /** @var SectionMetadata $enhancedContent */
        $enhancedContent = $tabs->getItems()['content'];
        $contentItems = $enhancedContent->getItems();

        /** @var FieldMetadata $enhancedSingle */
        $enhancedSingle = $contentItems['page_template'];
        $singleValuesOpt = $enhancedSingle->findOption('values');
        $this->assertNotNull($singleValuesOpt);
        /** @var list<OptionMetadata> $singleOpts */
        $singleOpts = (array) $singleValuesOpt->getValue();
        $this->assertCount(2, $singleOpts);
        $this->assertSame('default', $singleOpts[0]->getName());
        $this->assertSame('Default Page', $singleOpts[0]->getTitle('en'));
        $this->assertSame('landing', $singleOpts[1]->getName());
        $this->assertSame('Landing Page', $singleOpts[1]->getTitle('en'));
        // Mandatory single_template_selection receives default_value automatically
        $this->assertSame('default', $enhancedSingle->findOption('default_value')?->getValue());

        /** @var FieldMetadata $enhancedMulti */
        $enhancedMulti = $contentItems['article_templates'];
        $multiValuesOpt = $enhancedMulti->findOption('values');
        $this->assertNotNull($multiValuesOpt);
        /** @var list<OptionMetadata> $multiOpts */
        $multiOpts = (array) $multiValuesOpt->getValue();
        $this->assertCount(1, $multiOpts);
        $this->assertSame('blog_post', $multiOpts[0]->getName());
        $this->assertSame('Blog Post', $multiOpts[0]->getTitle('en'));
    }

    public function testPopulatesSingleWebspaceSelectAndWebspaceSelectFields(): void
    {
        $ws1 = new \Sulu\Component\Webspace\Webspace();
        $ws1->setKey('totoglu');
        $ws1->setName('Totoglu Global');

        $ws2 = new \Sulu\Component\Webspace\Webspace();
        $ws2->setKey('turkiye');
        $ws2->setName('Totoglu Turkiye');

        $collection = new \Sulu\Component\Webspace\Manager\WebspaceCollection();
        $collection->setWebspaces(['totoglu' => $ws1, 'turkiye' => $ws2]);

        $webspaceManager = $this->createStub(\Sulu\Component\Webspace\Manager\WebspaceManagerInterface::class);
        $webspaceManager->method('getWebspaceCollection')->willReturn($collection);

        $visitor = new BlockSuiteFormMetadataVisitor(
            webspaceManager: $webspaceManager,
        );

        $snippetForm = new FormMetadata();
        $snippetForm->setKey('page_layout');

        $singleWs = new FieldMetadata('webspace');
        $singleWs->setType('single_webspace_select');
        $singleWs->setRequired(true);
        $snippetForm->addItem($singleWs);

        $globalWs = new FieldMetadata('preset_webspace');
        $globalWs->setType('single_webspace_select');
        $includeGlobalOpt = new OptionMetadata();
        $includeGlobalOpt->setName('include_global');
        $includeGlobalOpt->setValue('true');
        $globalWs->addOption($includeGlobalOpt);
        $snippetForm->addItem($globalWs);

        $multiWs = new FieldMetadata('webspaces');
        $multiWs->setType('webspace_select');
        $snippetForm->addItem($multiWs);

        $visitor->visitFormMetadata($snippetForm, 'en');

        /** @var FieldMetadata $visitedSingle */
        $visitedSingle = $snippetForm->getItems()['webspace'];
        $this->assertSame('single_select', $visitedSingle->getType());
        /** @var list<OptionMetadata> $singleOpts */
        $singleOpts = (array) $visitedSingle->findOption('values')?->getValue();
        $this->assertCount(2, $singleOpts);
        $this->assertSame('totoglu', $singleOpts[0]->getName());
        $this->assertSame('Totoglu Global', $singleOpts[0]->getTitle('en'));
        $this->assertSame('turkiye', $singleOpts[1]->getName());
        $this->assertSame('totoglu', $visitedSingle->findOption('default_value')?->getValue());

        /** @var FieldMetadata $visitedGlobal */
        $visitedGlobal = $snippetForm->getItems()['preset_webspace'];
        $this->assertSame('single_select', $visitedGlobal->getType());
        /** @var list<OptionMetadata> $globalOpts */
        $globalOpts = (array) $visitedGlobal->findOption('values')?->getValue();
        $this->assertCount(3, $globalOpts);
        $this->assertSame('_global', $globalOpts[0]->getName());
        $this->assertSame('_global', $visitedGlobal->findOption('default_value')?->getValue());

        /** @var FieldMetadata $visitedMulti */
        $visitedMulti = $snippetForm->getItems()['webspaces'];
        $this->assertSame('select', $visitedMulti->getType());
        /** @var list<OptionMetadata> $multiOpts */
        $multiOpts = (array) $visitedMulti->findOption('values')?->getValue();
        $this->assertCount(2, $multiOpts);
    }

    public function testHidesWebspaceFieldAndExpandsSiblingColSpanWhenMultiWebspaceIsDisabled(): void
    {
        $visitor = new BlockSuiteFormMetadataVisitor(
            multiWebspaceEnabled: false,
        );

        $snippetForm = new FormMetadata();
        $snippetForm->setKey('page_layout');

        $singleWs = new FieldMetadata('webspace');
        $singleWs->setType('single_webspace_select');
        $singleWs->setRequired(true);
        $singleWs->setColSpan(6);
        $snippetForm->addItem($singleWs);

        $targetTemplate = new FieldMetadata('target_template');
        $targetTemplate->setType('single_template_selection');
        $targetTemplate->setColSpan(6);
        $snippetForm->addItem($targetTemplate);

        $visitor->visitFormMetadata($snippetForm, 'en');

        /** @var FieldMetadata $visitedWs */
        $visitedWs = $snippetForm->getItems()['webspace'];
        $this->assertSame('single_select', $visitedWs->getType());
        $this->assertFalse($visitedWs->isRequired());
        $this->assertSame('false', $visitedWs->getVisibleCondition());
        $this->assertSame('_global', $visitedWs->findOption('default_value')?->getValue());

        /** @var FieldMetadata $visitedTemplate */
        $visitedTemplate = $snippetForm->getItems()['target_template'];
        $this->assertSame(12, $visitedTemplate->getColSpan());
    }
}
