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
use Totoglu\SuluBlockSuiteBundle\Metadata\BlockSuiteFormMetadataVisitor;
use Totoglu\SuluBlockSuiteBundle\Preset\BlockPresetProviderInterface;

final class BlockSuiteFormMetadataVisitorTest extends TestCase
{
    public function testPopulatePresetSectionOptionsForwardsActiveLocaleToPresetProvider(): void
    {
        $presetProvider = $this->createMock(BlockPresetProviderInterface::class);
        $presetProvider->expects($this->once())
            ->method('getPresets')
            ->with('headline', 'tr')
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
        $headlineForm->setKey('headline');

        $contentSection = new SectionMetadata('content');
        $contentSection->setLabel('İçerik', 'tr');
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');
        $contentSection->addItem($titleField);
        $headlineForm->addItem($contentSection);

        $typedForm = new TypedFormMetadata();
        $typedForm->addForm('headline', $headlineForm);

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

        /** @var SectionMetadata $resolvedGeneral */
        $resolvedGeneral = $tabItems['general'];
        $this->assertArrayHasKey('props/position', $resolvedGeneral->getItems());
        $this->assertArrayHasKey('props/position_left', $resolvedGeneral->getItems());
        $this->assertArrayHasKey('props/position_right', $resolvedGeneral->getItems());
        $this->assertArrayHasKey('props/position_top', $resolvedGeneral->getItems());
        $this->assertArrayHasKey('props/position_bottom', $resolvedGeneral->getItems());
        $this->assertArrayHasKey('props/position_z_index', $resolvedGeneral->getItems());
        $this->assertArrayHasKey('props/blend', $resolvedGeneral->getItems());
        $this->assertArrayHasKey('props/margin_top', $resolvedGeneral->getItems());
        $this->assertArrayHasKey('props/margin_bottom', $resolvedGeneral->getItems());
        $this->assertArrayHasKey('props/max_width', $resolvedGeneral->getItems());
        $this->assertArrayHasKey('props/max_width_breakpoint', $resolvedGeneral->getItems());
        $this->assertArrayHasKey('props/block_align', $resolvedGeneral->getItems());
        $this->assertArrayHasKey('props/block_align_breakpoint', $resolvedGeneral->getItems());
        $this->assertArrayHasKey('props/block_align_fallback', $resolvedGeneral->getItems());
        $this->assertArrayHasKey('props/text_align', $resolvedGeneral->getItems());
        $this->assertArrayHasKey('props/text_align_breakpoint', $resolvedGeneral->getItems());
        $this->assertArrayHasKey('props/text_align_fallback', $resolvedGeneral->getItems());
        $this->assertArrayHasKey('props/animation', $resolvedGeneral->getItems());
        $this->assertArrayHasKey('props/parallax', $resolvedGeneral->getItems());
        $this->assertArrayHasKey('props/visibility', $resolvedGeneral->getItems());
        $this->assertArrayNotHasKey('props/item_animation', $resolvedGeneral->getItems());
        $this->assertArrayNotHasKey('props/container_padding_remove', $resolvedGeneral->getItems());

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
        $blockTagConfigs = [
            'reusable' => ['exclude' => 'dynamic_content'],
            'spacer' => ['exclude' => 'dynamic_content'],
            'section' => ['exclude' => 'dynamic_content,general'],
            'divider' => ['exclude' => 'dynamic_content'],
            'icon' => ['exclude' => 'dynamic_content'],
            'html' => ['exclude' => 'dynamic_content'],
            'code' => ['exclude' => 'dynamic_content'],
            'breadcrumbs' => ['exclude' => 'dynamic_content'],
            'scroll_to' => ['exclude' => 'dynamic_content'],
            'countdown' => ['exclude' => 'dynamic_content'],
            'popover' => ['exclude' => 'dynamic_content'],
            'map' => ['exclude' => 'dynamic_content'],
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

        foreach ($blockTagConfigs as $key => $attributes) {
            $blockForm = $typedForm->getForms()[$key];
            /** @var SectionMetadata $tabsSection */
            $tabsSection = $blockForm->getItems()['__tabs'];
            $tabKeys = \array_keys($tabsSection->getItems());

            $this->assertNotContains(
                'dynamic_content',
                $tabKeys,
                \sprintf('Block "%s" must not expose the dynamic_content tab.', $key),
            );
            if (\str_contains($attributes['exclude'], 'general')) {
                $this->assertSame(['content', 'advanced'], $tabKeys);
            } else {
                $this->assertSame(['content', 'general', 'advanced'], $tabKeys);
            }
        }
    }

    public function testYoothemeGeneralTabFieldMatrixPerBlock(): void
    {
        $visitor = new BlockSuiteFormMetadataVisitor();
        $blockTagConfigs = [
            'code' => [
                'exclude' => 'dynamic_content',
                'exclude_fields' => 'max_width,max_width_breakpoint,block_align,block_align_breakpoint,block_align_fallback,text_align,text_align_breakpoint,text_align_fallback',
            ],
            'countdown' => [
                'exclude' => 'dynamic_content',
                'exclude_fields' => 'max_width,max_width_breakpoint,block_align,block_align_breakpoint,block_align_fallback',
                'text_align' => 'standard',
            ],
            'reusable' => [
                'exclude' => 'dynamic_content',
                'exclude_fields' => 'max_width,max_width_breakpoint,block_align,block_align_breakpoint,block_align_fallback,text_align,text_align_breakpoint,text_align_fallback',
            ],
            'divider' => [
                'exclude' => 'dynamic_content',
                'exclude_fields' => 'text_align,text_align_breakpoint,text_align_fallback',
            ],
            'map' => [
                'exclude' => 'dynamic_content',
                'exclude_fields' => 'text_align,text_align_breakpoint,text_align_fallback',
                'include_fields' => 'container_padding_remove',
            ],
            'button' => [
                'text_align' => 'standard',
            ],
            'headline' => [],
            'gallery' => [
                'include_fields' => 'item_animation,container_padding_remove',
            ],
            'grid' => [
                'include_fields' => 'item_animation,container_padding_remove',
            ],
            'video' => [
                'include_fields' => 'container_padding_remove',
                'text_align' => 'standard',
            ],
        ];

        $typedForm = new TypedFormMetadata();
        foreach ($blockTagConfigs as $key => $attributes) {
            $blockForm = new FormMetadata();
            $blockForm->setKey($key);
            if ([] !== $attributes) {
                $tabsTag = new TagMetadata();
                $tabsTag->setName('sulu_block_suite.tabs');
                $tabsTag->setAttributes($attributes);
                $blockForm->addTag($tabsTag);
            }
            $contentSection = new SectionMetadata('content');
            $field = new FieldMetadata('sample');
            $field->setType('text_line');
            $contentSection->addItem($field);
            $blockForm->addItem($contentSection);
            $typedForm->addForm($key, $blockForm);
        }

        $visitor->visitTypedFormMetadata($typedForm, 'block', 'en');

        $getGeneralItems = static function (string $key) use ($typedForm): array {
            /** @var SectionMetadata $tabs */
            $tabs = $typedForm->getForms()[$key]->getItems()['__tabs'];
            /** @var SectionMetadata $general */
            $general = $tabs->getItems()['general'];

            return $general->getItems();
        };

        // 1. max_width & block_align excluded on code, countdown, reusable
        foreach (['code', 'countdown', 'reusable'] as $noMaxWidthKey) {
            $items = $getGeneralItems($noMaxWidthKey);
            $this->assertArrayNotHasKey('props/max_width', $items);
            $this->assertArrayNotHasKey('props/max_width_breakpoint', $items);
            $this->assertArrayNotHasKey('props/block_align', $items);
            $this->assertArrayNotHasKey('props/block_align_breakpoint', $items);
            $this->assertArrayNotHasKey('props/block_align_fallback', $items);
        }
        $this->assertArrayHasKey('props/max_width', $getGeneralItems('button'));

        // 2. text_align excluded on code, divider, reusable, map
        foreach (['code', 'divider', 'reusable', 'map'] as $noTextAlignKey) {
            $items = $getGeneralItems($noTextAlignKey);
            $this->assertArrayNotHasKey('props/text_align', $items);
            $this->assertArrayNotHasKey('props/text_align_breakpoint', $items);
            $this->assertArrayNotHasKey('props/text_align_fallback', $items);
        }

        // 3. button has standard text_align (without justify), headline has text_align_justify (with justify)
        /** @var FieldMetadata $buttonTextAlign */
        $buttonTextAlign = $getGeneralItems('button')['props/text_align'];
        /** @var list<OptionMetadata> $buttonValues */
        $buttonValues = (array) $buttonTextAlign->findOption('values')?->getValue();
        $buttonOptionNames = \array_map(
            static fn (OptionMetadata $opt): string => (string) $opt->getName(),
            $buttonValues,
        );
        $this->assertSame(['', 'left', 'center', 'right'], $buttonOptionNames);

        /** @var FieldMetadata $headlineTextAlign */
        $headlineTextAlign = $getGeneralItems('headline')['props/text_align'];
        /** @var list<OptionMetadata> $headlineValues */
        $headlineValues = (array) $headlineTextAlign->findOption('values')?->getValue();
        $headlineOptionNames = \array_map(
            static fn (OptionMetadata $opt): string => (string) $opt->getName(),
            $headlineValues,
        );
        $this->assertSame(['', 'left', 'center', 'right', 'justify'], $headlineOptionNames);

        // 4. item_animation only on gallery and grid
        $this->assertArrayHasKey('props/item_animation', $getGeneralItems('gallery'));
        $this->assertArrayHasKey('props/item_animation', $getGeneralItems('grid'));
        $this->assertArrayNotHasKey('props/item_animation', $getGeneralItems('headline'));
        $this->assertArrayNotHasKey('props/item_animation', $getGeneralItems('video'));

        // 5. container_padding_remove on gallery, grid, map, video (not on headline or button)
        $this->assertArrayHasKey('props/container_padding_remove', $getGeneralItems('gallery'));
        $this->assertArrayHasKey('props/container_padding_remove', $getGeneralItems('map'));
        $this->assertArrayHasKey('props/container_padding_remove', $getGeneralItems('video'));
        $this->assertArrayNotHasKey('props/container_padding_remove', $getGeneralItems('headline'));
        $this->assertArrayNotHasKey('props/container_padding_remove', $getGeneralItems('button'));
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

        $hlTargetValues = $hlTarget->findOption('values')?->getValue();
        $this->assertIsArray($hlTargetValues);
        $this->assertNotEmpty($hlTargetValues);
        $firstTargetOption = $hlTargetValues[0];
        foreach (['en', 'tr', 'de', 'ru', 'es', 'fr'] as $lang) {
            $title = $firstTargetOption->getTitle($lang);
            $this->assertIsString($title);
            $this->assertNotSame('', $title);
            $this->assertStringNotContainsString('(title)', $title);
        }
        $this->assertSame('Başlık', $firstTargetOption->getTitle('tr'));
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

    public function testBlocksAreSortedAlphabeticallyByTitle(): void
    {
        $visitor = new BlockSuiteFormMetadataVisitor();

        $typedBlockForms = new TypedFormMetadata();
        foreach (['video' => 'Video', 'accordion' => 'Accordion', 'headline' => 'Headline', 'button' => 'Button'] as $key => $title) {
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

        $this->assertSame(['accordion', 'button', 'headline', 'video'], \array_keys($typedBlockForms->getForms()));

        $pageForm = new FormMetadata();
        $pageForm->setKey('default');
        $blocksField = new FieldMetadata('blocks');
        $blocksField->setType('block');
        $autoBlocksTag = new TagMetadata();
        $autoBlocksTag->setName('sulu_block_suite.auto_blocks');
        $blocksField->addTag($autoBlocksTag);
        $pageForm->addItem($blocksField);

        $visitor->visitFormMetadata($pageForm, 'en');

        $this->assertSame(['accordion', 'button', 'headline', 'video'], \array_keys($blocksField->getTypes()));
    }

    public function testAutoBlocksTagSupportsIncludeExcludeAndDefaultAttributes(): void
    {
        $visitor = new BlockSuiteFormMetadataVisitor();

        $typedBlockForms = new TypedFormMetadata();
        foreach (['section' => 'Section', 'headline' => 'Headline', 'text' => 'Text', 'reusable' => 'Reusable Block'] as $key => $title) {
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

        $layoutForm = new FormMetadata();
        $layoutForm->setKey('custom_layout');
        $blocksField = new FieldMetadata('blocks');
        $blocksField->setType('block');
        $autoBlocksTag = new TagMetadata();
        $autoBlocksTag->setName('sulu_block_suite.auto_blocks');
        $autoBlocksTag->setAttributes([
            'include' => 'section,headline,reusable',
            'exclude' => 'reusable',
            'default' => 'section',
        ]);
        $blocksField->addTag($autoBlocksTag);
        $layoutForm->addItem($blocksField);

        $visitor->visitFormMetadata($layoutForm, 'en');

        $this->assertSame(['headline', 'section'], \array_keys($blocksField->getTypes()));
        $this->assertSame('section', $blocksField->getDefaultType());
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
            'exclude' => 'dynamic_content,general',
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
