<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Symfony\Contracts\Translation\TranslatorInterface;
use Totoglu\SuluBlockSuiteBundle\Metadata\TargetBlockOptionsFormMetadataVisitor;

final class TargetBlockOptionsFormMetadataVisitorTest extends TestCase
{
    private string $tempSchemaDir;
    private string $tempPresetDir;
    private string $tempProjectDir;

    protected function setUp(): void
    {
        $this->tempSchemaDir = \sys_get_temp_dir() . '/target_block_schema_test_' . \uniqid('', true);
        $this->tempPresetDir = \sys_get_temp_dir() . '/target_block_preset_test_' . \uniqid('', true);
        $this->tempProjectDir = \sys_get_temp_dir() . '/target_block_project_test_' . \uniqid('', true);

        \mkdir($this->tempSchemaDir, 0777, true);
        \mkdir($this->tempPresetDir, 0777, true);
        \mkdir($this->tempProjectDir . '/config/templates/blocks', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempSchemaDir);
        $this->removeDirectory($this->tempPresetDir);
        $this->removeDirectory($this->tempProjectDir);
    }

    public function testVisitFormMetadataIgnoresNonBlockPresetForms(): void
    {
        $visitor = new TargetBlockOptionsFormMetadataVisitor(
            $this->tempSchemaDir,
            $this->tempPresetDir,
            $this->tempProjectDir,
        );

        $formMetadata = new FormMetadata();
        $formMetadata->setKey('other_form');

        $field = new FieldMetadata('target_block');
        $formMetadata->addItem($field);

        $visitor->visitFormMetadata($formMetadata, 'en');

        $this->assertNull($field->findOption('values'));
    }

    public function testVisitFormMetadataPopulatesDynamicBlockValues(): void
    {
        // 1. Schema files
        \file_put_contents($this->tempSchemaDir . '/card.yaml', 'tabs: []');
        \file_put_contents($this->tempSchemaDir . '/headline.yaml', 'tabs: []');
        \file_put_contents($this->tempSchemaDir . '/common.yaml', 'tabs: []'); // should be ignored

        // 2. Preset files
        \file_put_contents($this->tempPresetDir . '/button.yaml', 'presets: {}');

        // 3. Project block XML files
        \file_put_contents($this->tempProjectDir . '/config/templates/blocks/hero.xml', '<block/>');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $parameters, ?string $domain, ?string $locale): string {
            if ('sulu_block_suite.block_card' === $id && 'tr' === $locale) {
                return 'Kart';
            }

            return $id;
        });

        $visitor = new TargetBlockOptionsFormMetadataVisitor(
            $this->tempSchemaDir,
            $this->tempPresetDir,
            $this->tempProjectDir,
            $translator,
        );

        $formMetadata = new FormMetadata();
        $formMetadata->setKey('block_preset');

        $field = new FieldMetadata('target_block');
        $formMetadata->addItem($field);

        $visitor->visitFormMetadata($formMetadata, 'tr');

        $valuesOption = $field->findOption('values');
        $this->assertNotNull($valuesOption);
        $this->assertSame(OptionMetadata::TYPE_COLLECTION, $valuesOption->getType());

        /** @var list<OptionMetadata> $options */
        $options = $valuesOption->getValue();
        $this->assertCount(4, $options);

        $optionNames = \array_map(static fn (OptionMetadata $opt) => $opt->getName(), $options);
        $this->assertSame(['button', 'card', 'headline', 'hero'], $optionNames);

        // Check card title translated to Turkish
        $cardOption = $options[1];
        $this->assertSame('Kart', $cardOption->getTitle('tr'));

        // Check button humanized fallback
        $buttonOption = $options[0];
        $this->assertSame('Button', $buttonOption->getTitle('tr'));

        // Check default_value option set to first block
        $defaultOption = $field->findOption('default_value');
        $this->assertNotNull($defaultOption);
        $this->assertSame('button', $defaultOption->getValue());
    }

    public function testVisitFormMetadataUsesMetadataProviderRegistry(): void
    {
        $blockMetadata = new FormMetadata();
        $blockMetadata->setKey('accordion');
        $blockMetadata->setTitle('Accordion Block', 'en');

        $typedFormMetadata = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata();
        $typedFormMetadata->addForm('accordion', $blockMetadata);

        $metadataProvider = $this->createStub(\Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface::class);
        $metadataProvider->method('getMetadata')->willReturn($typedFormMetadata);

        $registry = $this->createStub(\Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry::class);
        $registry->method('getMetadataProvider')->willReturn($metadataProvider);

        $visitor = new TargetBlockOptionsFormMetadataVisitor(
            $this->tempSchemaDir,
            $this->tempPresetDir,
            null,
            null,
            $registry,
        );

        $formMetadata = new FormMetadata();
        $formMetadata->setKey('block_preset');

        $field = new FieldMetadata('target_block');
        $formMetadata->addItem($field);

        $visitor->visitFormMetadata($formMetadata, 'en');

        $valuesOption = $field->findOption('values');
        $this->assertNotNull($valuesOption);

        /** @var list<OptionMetadata> $options */
        $options = $valuesOption->getValue();
        $this->assertCount(1, $options);
        $this->assertSame('accordion', $options[0]->getName());
        $this->assertSame('Accordion Block', $options[0]->getTitle('en'));
    }

    public function testVisitFormMetadataEnhancesReusableBlock(): void
    {
        $galleryBlock = new FormMetadata();
        $galleryBlock->setKey('gallery');
        $galleryBlock->setTitle('Gallery', 'en');

        $typedFormMetadata = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata();
        $typedFormMetadata->addForm('gallery', $galleryBlock);

        $metadataProvider = $this->createStub(\Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface::class);
        $metadataProvider->method('getMetadata')->willReturn($typedFormMetadata);

        $registry = $this->createStub(\Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry::class);
        $registry->method('getMetadataProvider')->willReturn($metadataProvider);

        $visitor = new TargetBlockOptionsFormMetadataVisitor(
            $this->tempSchemaDir,
            $this->tempPresetDir,
            null,
            null,
            $registry,
        );

        $reusableForm = new FormMetadata();
        $reusableForm->setKey('reusable_block');

        $blocksField = new FieldMetadata('blocks');
        $reusableForm->addItem($blocksField);

        $visitor->visitFormMetadata($reusableForm, 'en');

        $types = $blocksField->getTypes();
        $this->assertArrayHasKey('gallery', $types);
        $this->assertSame('gallery', $blocksField->getDefaultType());
    }

    public function testVisitFormMetadataEnhancesPageLayout(): void
    {
        $heroBlock = new FormMetadata();
        $heroBlock->setKey('hero');
        $heroBlock->setTitle('Hero', 'en');

        $typedFormMetadata = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata();
        $typedFormMetadata->addForm('hero', $heroBlock);

        $metadataProvider = $this->createStub(\Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface::class);
        $metadataProvider->method('getMetadata')->willReturn($typedFormMetadata);

        $registry = $this->createStub(\Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry::class);
        $registry->method('getMetadataProvider')->willReturn($metadataProvider);

        $visitor = new TargetBlockOptionsFormMetadataVisitor(
            $this->tempSchemaDir,
            $this->tempPresetDir,
            null,
            null,
            $registry,
        );

        $layoutForm = new FormMetadata();
        $layoutForm->setKey('page_layout');

        $blocksField = new FieldMetadata('blocks');
        $layoutForm->addItem($blocksField);

        $visitor->visitFormMetadata($layoutForm, 'en');

        $types = $blocksField->getTypes();
        $this->assertArrayHasKey('hero', $types);
        $this->assertSame('hero', $blocksField->getDefaultType());
    }

    public function testMainContentSlotIsExcludedFromPresetAndReusableBlockForms(): void
    {
        $mainContentBlock = new FormMetadata();
        $mainContentBlock->setKey('main_content');
        $mainContentBlock->setTitle('Page Content Slot', 'en');

        $cardBlock = new FormMetadata();
        $cardBlock->setKey('card');
        $cardBlock->setTitle('Card', 'en');

        $typedFormMetadata = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata();
        $typedFormMetadata->addForm('main_content', $mainContentBlock);
        $typedFormMetadata->addForm('card', $cardBlock);

        $metadataProvider = $this->createStub(\Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface::class);
        $metadataProvider->method('getMetadata')->willReturn($typedFormMetadata);

        $registry = $this->createStub(\Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry::class);
        $registry->method('getMetadataProvider')->willReturn($metadataProvider);

        $visitor = new TargetBlockOptionsFormMetadataVisitor(
            $this->tempSchemaDir,
            $this->tempPresetDir,
            null,
            null,
            $registry,
        );

        // 1. discoverAvailableBlockTypes excludes main_content
        $this->assertSame(['card'], $visitor->discoverAvailableBlockTypes('en'));

        // 2. reusable_block excludes main_content
        $reusableForm = new FormMetadata();
        $reusableForm->setKey('reusable_block');
        $reusableBlocksField = new FieldMetadata('blocks');
        $reusableForm->addItem($reusableBlocksField);
        $visitor->visitFormMetadata($reusableForm, 'en');
        $this->assertArrayNotHasKey('main_content', $reusableBlocksField->getTypes());
        $this->assertArrayHasKey('card', $reusableBlocksField->getTypes());

        // 3. page_layout includes main_content
        $layoutForm = new FormMetadata();
        $layoutForm->setKey('page_layout');
        $layoutBlocksField = new FieldMetadata('blocks');
        $layoutForm->addItem($layoutBlocksField);
        $visitor->visitFormMetadata($layoutForm, 'en');
        $this->assertArrayHasKey('main_content', $layoutBlocksField->getTypes());
        $this->assertArrayHasKey('card', $layoutBlocksField->getTypes());
    }

    public function testVisitTypedFormMetadataEnhancesSnippetForms(): void
    {
        $headlineBlock = new FormMetadata();
        $headlineBlock->setKey('headline');
        $headlineBlock->setTitle('Headline', 'en');

        $mainContentBlock = new FormMetadata();
        $mainContentBlock->setKey('main_content');
        $mainContentBlock->setTitle('Page Content Slot', 'en');

        $blocksMetadata = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata();
        $blocksMetadata->addForm('headline', $headlineBlock);
        $blocksMetadata->addForm('main_content', $mainContentBlock);

        $metadataProvider = $this->createStub(\Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface::class);
        $metadataProvider->method('getMetadata')->willReturn($blocksMetadata);

        $registry = $this->createStub(\Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry::class);
        $registry->method('getMetadataProvider')->willReturn($metadataProvider);

        $visitor = new TargetBlockOptionsFormMetadataVisitor(
            $this->tempSchemaDir,
            $this->tempPresetDir,
            null,
            null,
            $registry,
        );

        $defaultType = new FormMetadata();
        $defaultType->setKey('default');

        $pageLayoutForm = new FormMetadata();
        $pageLayoutForm->setKey('page_layout');
        $pageLayoutBlocksField = new FieldMetadata('blocks');
        $pageLayoutBlocksField->addType($defaultType);
        $pageLayoutBlocksField->setDefaultType('default');
        $pageLayoutForm->addItem($pageLayoutBlocksField);

        $reusableForm = new FormMetadata();
        $reusableForm->setKey('reusable_block');
        $reusableBlocksField = new FieldMetadata('blocks');
        $reusableBlocksField->addType($defaultType);
        $reusableBlocksField->setDefaultType('default');
        $reusableForm->addItem($reusableBlocksField);

        $snippetTypedMetadata = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata();
        $snippetTypedMetadata->addForm('page_layout', $pageLayoutForm);
        $snippetTypedMetadata->addForm('reusable_block', $reusableForm);

        $visitor->visitTypedFormMetadata($snippetTypedMetadata, 'snippet', 'en');

        $this->assertArrayHasKey('headline', $pageLayoutBlocksField->getTypes());
        $this->assertArrayHasKey('main_content', $pageLayoutBlocksField->getTypes());
        $this->assertArrayNotHasKey('default', $pageLayoutBlocksField->getTypes());

        $this->assertArrayHasKey('headline', $reusableBlocksField->getTypes());
        $this->assertArrayNotHasKey('main_content', $reusableBlocksField->getTypes());
        $this->assertArrayNotHasKey('default', $reusableBlocksField->getTypes());
    }

    public function testDiscoverAvailableBlockTypesCascadesMultipleDirectories(): void
    {
        $secondSchemaDir = \sys_get_temp_dir() . '/target_block_schema2_' . \uniqid('', true);
        $secondPresetDir = \sys_get_temp_dir() . '/target_block_preset2_' . \uniqid('', true);
        \mkdir($secondSchemaDir, 0777, true);
        \mkdir($secondPresetDir, 0777, true);

        try {
            \file_put_contents($this->tempSchemaDir . '/card.yaml', 'tabs: []');
            \file_put_contents($secondSchemaDir . '/accordion.yaml', 'tabs: []');
            \file_put_contents($this->tempPresetDir . '/button.yaml', 'presets: {}');
            \file_put_contents($secondPresetDir . '/teaser.yaml', 'presets: {}');

            $visitor = new TargetBlockOptionsFormMetadataVisitor(
                [$this->tempSchemaDir, $secondSchemaDir],
                [$this->tempPresetDir, $secondPresetDir],
            );

            $types = $visitor->discoverAvailableBlockTypes('en');
            $this->assertSame(['accordion', 'button', 'card', 'teaser'], $types);
        } finally {
            $this->removeDirectory($secondSchemaDir);
            $this->removeDirectory($secondPresetDir);
        }
    }

    public function testAutoBlocksTagPopulatesPageAndNestedColumnBlockFields(): void
    {
        $headlineBlock = new FormMetadata();
        $headlineBlock->setKey('headline');
        $headlineBlock->setTitle('Headline', 'en');

        $textBlock = new FormMetadata();
        $textBlock->setKey('text');
        $textBlock->setTitle('Text', 'en');

        $mainContentBlock = new FormMetadata();
        $mainContentBlock->setKey('main_content');
        $mainContentBlock->setTitle('Main Content', 'en');

        // Build nested section -> rows -> row_item -> columns -> column_item -> blocks
        $defaultType = new FormMetadata();
        $defaultType->setKey('default');

        $columnBlocksField = new FieldMetadata('blocks');
        $columnBlocksField->setType('block');
        $columnBlocksField->setDefaultType('default');
        $columnBlocksField->addType($defaultType);

        $columnAutoTag = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TagMetadata();
        $columnAutoTag->setName(TargetBlockOptionsFormMetadataVisitor::AUTO_BLOCKS_TAG);
        $columnAutoTag->setAttributes([
            'default' => 'text',
            'exclude' => 'main_content,section',
        ]);
        $columnBlocksField->addTag($columnAutoTag);

        $columnItemType = new FormMetadata();
        $columnItemType->setKey('column_item');
        $columnItemType->addItem($columnBlocksField);

        $columnsField = new FieldMetadata('columns');
        $columnsField->setType('block');
        $columnsField->addType($columnItemType);

        $rowItemType = new FormMetadata();
        $rowItemType->setKey('row_item');
        $rowItemType->addItem($columnsField);

        $rowsField = new FieldMetadata('rows');
        $rowsField->setType('block');
        $rowsField->addType($rowItemType);

        $sectionBlock = new FormMetadata();
        $sectionBlock->setKey('section');
        $sectionBlock->setTitle('Section', 'en');
        $sectionBlock->addItem($rowsField);

        $blocksMetadata = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata();
        $blocksMetadata->addForm('headline', $headlineBlock);
        $blocksMetadata->addForm('text', $textBlock);
        $blocksMetadata->addForm('main_content', $mainContentBlock);
        $blocksMetadata->addForm('section', $sectionBlock);

        $metadataProvider = $this->createStub(\Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface::class);
        $metadataProvider->method('getMetadata')->willReturn($blocksMetadata);

        $registry = $this->createStub(\Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry::class);
        $registry->method('getMetadataProvider')->willReturn($metadataProvider);

        $visitor = new TargetBlockOptionsFormMetadataVisitor(
            $this->tempSchemaDir,
            $this->tempPresetDir,
            null,
            null,
            $registry,
        );

        // 1. Visit block TypedFormMetadata (enhances section's nested column_item.blocks)
        $visitor->visitTypedFormMetadata($blocksMetadata, 'block', 'en');

        $nestedTypes = $columnBlocksField->getTypes();
        $this->assertArrayHasKey('headline', $nestedTypes);
        $this->assertArrayHasKey('text', $nestedTypes);
        $this->assertArrayNotHasKey('section', $nestedTypes);
        $this->assertArrayNotHasKey('main_content', $nestedTypes);
        $this->assertArrayNotHasKey('default', $nestedTypes);
        $this->assertSame('text', $columnBlocksField->getDefaultType());

        // 2. Visit page FormMetadata with SectionMetadata containing auto_blocks field
        $pageBlocksField = new FieldMetadata('blocks');
        $pageBlocksField->setType('block');
        $pageBlocksField->setDefaultType('default');
        $pageBlocksField->addType($defaultType);

        $pageAutoTag = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TagMetadata();
        $pageAutoTag->setName(TargetBlockOptionsFormMetadataVisitor::AUTO_BLOCKS_TAG);
        $pageAutoTag->setAttributes([
            'default' => 'headline',
            'exclude' => 'main_content',
        ]);
        $pageBlocksField->addTag($pageAutoTag);

        $contentSection = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata('content');
        $contentSection->addItem($pageBlocksField);

        $pageForm = new FormMetadata();
        $pageForm->setKey('default');
        $pageForm->addItem($contentSection);

        $visitor->visitFormMetadata($pageForm, 'en');

        $pageTypes = $pageBlocksField->getTypes();
        $this->assertArrayHasKey('headline', $pageTypes);
        $this->assertArrayHasKey('text', $pageTypes);
        $this->assertArrayHasKey('section', $pageTypes);
        $this->assertArrayNotHasKey('main_content', $pageTypes);
        $this->assertArrayNotHasKey('default', $pageTypes);
        $this->assertSame('headline', $pageBlocksField->getDefaultType());
    }

    public function testEnhancesBlockPreviewTagsWithOrderedPriorities(): void
    {
        $visitor = new TargetBlockOptionsFormMetadataVisitor(
            $this->tempSchemaDir,
            $this->tempPresetDir,
        );

        $accordionForm = new FormMetadata();
        $accordionForm->setKey('accordion');

        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');
        $accordionForm->addItem($titleField);

        $itemsField = new FieldMetadata('items');
        $itemsField->setType('block');
        $accordionForm->addItem($itemsField);

        $propsField = new FieldMetadata('props');
        $propsField->setType('block_props');
        $accordionForm->addItem($propsField);

        $visitor->visitFormMetadata($accordionForm, 'en');

        $this->assertTrue($titleField->hasTag('sulu.block_preview'));
        $this->assertSame(1024, $titleField->getTags()[0]->getPriority());

        $this->assertTrue($itemsField->hasTag('sulu.block_preview'));
        $this->assertSame(256, $itemsField->getTags()[0]->getPriority());

        $this->assertTrue($propsField->hasTag('sulu.block_preview'));
        $this->assertSame(128, $propsField->getTags()[0]->getPriority());
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $files = \scandir($dir);
        if (false === $files) {
            return;
        }

        foreach ($files as $file) {
            if ('.' === $file || '..' === $file) {
                continue;
            }

            $path = $dir . '/' . $file;
            if (\is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                \unlink($path);
            }
        }

        \rmdir($dir);
    }
}
