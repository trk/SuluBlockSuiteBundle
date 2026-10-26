<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Ai;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;
use Totoglu\SuluBlockSuiteBundle\Ai\BlockSuiteSchemaInspector;
use Totoglu\SuluBlockSuiteBundle\Metadata\BlockSuiteFormMetadataVisitor;
use Totoglu\SuluBlockSuiteBundle\Preset\BlockPresetProviderInterface;

final class BlockSuiteSchemaInspectorTest extends TestCase
{
    public function testListBlocksReturnsCompactBlockCatalogWithPresetAndFieldOptionSummary(): void
    {
        $heroForm = $this->createSampleHeroBlockForm('en');
        $typedMetadata = new TypedFormMetadata();
        $typedMetadata->addForm('hero', $heroForm);

        $registry = $this->createMetadataRegistry($typedMetadata);
        $presetProvider = $this->createStub(BlockPresetProviderInterface::class);
        $presetProvider->method('getPresets')->willReturnCallback(
            static fn (string $blockType): array => 'hero' === $blockType
                ? [
                    [
                        'id' => 'hero-dark',
                        'title' => 'Dark Hero',
                        'description' => 'Centered dark hero with gradient background',
                        'props' => ['background' => 'dark', 'title_size' => 'xl'],
                        'values' => ['title' => 'Welcome'],
                    ],
                ]
                : [],
        );

        $inspector = new BlockSuiteSchemaInspector($registry, $presetProvider);
        $blocks = $inspector->listBlocks('en');

        self::assertCount(1, $blocks);
        self::assertSame([
            'type' => 'hero',
            'title' => 'Hero Section',
            'content_fields' => ['title', 'description'],
            'field_option_keys' => ['props/title_size'],
            'settings_props' => ['props/background'],
            'preset_count' => 1,
            'presets' => ['hero-dark'],
        ], $blocks[0]);
    }

    public function testInspectBlockUsesProgressiveDisclosureToHideAdvancedAndDynamicTabsByDefault(): void
    {
        $heroForm = $this->createSampleHeroBlockForm('en');
        $typedMetadata = new TypedFormMetadata();
        $typedMetadata->addForm('hero', $heroForm);

        $registry = $this->createMetadataRegistry($typedMetadata);
        $presetProvider = $this->createStub(BlockPresetProviderInterface::class);
        $presetProvider->method('getPresets')->willReturn([
            [
                'id' => 'hero-dark',
                'title' => 'Dark Hero',
                'description' => 'Centered dark hero with gradient background',
                'props' => ['background' => 'dark', 'title_size' => 'xl'],
                'values' => ['title' => 'Welcome'],
            ],
        ]);

        $inspector = new BlockSuiteSchemaInspector($registry, $presetProvider);

        $defaultSchema = $inspector->inspectBlock('hero', 'en');
        self::assertNotNull($defaultSchema);
        self::assertSame('hero', $defaultSchema['type']);
        self::assertSame('Hero Section', $defaultSchema['title']);
        self::assertCount(2, $defaultSchema['content_fields']);
        self::assertSame('title', $defaultSchema['content_fields'][0]['name']);
        self::assertSame('text_line', $defaultSchema['content_fields'][0]['type']);
        self::assertTrue($defaultSchema['content_fields'][0]['translatable']);
        self::assertSame([
            [
                'name' => 'title_size',
                'storage_key' => 'props/title_size',
                'default' => 'lg',
                'values' => [
                    ['value' => 'md', 'label' => 'Medium'],
                    ['value' => 'lg', 'label' => 'Large'],
                    ['value' => 'xl', 'label' => 'Extra Large'],
                ],
            ],
        ], $defaultSchema['content_fields'][0]['option_groups']);
        self::assertCount(1, $defaultSchema['settings_props']);
        self::assertSame('props/background', $defaultSchema['settings_props'][0]['name']);
        self::assertArrayNotHasKey('advanced_props', $defaultSchema);
        self::assertArrayNotHasKey('dynamic_props', $defaultSchema);

        $expandedSchema = $inspector->inspectBlock('hero', 'en', includeAdvanced: true, includeDynamic: true);
        self::assertNotNull($expandedSchema);
        self::assertArrayHasKey('advanced_props', $expandedSchema);
        self::assertArrayHasKey('dynamic_props', $expandedSchema);
        self::assertNotEmpty($expandedSchema['advanced_props']);
        self::assertNotEmpty($expandedSchema['dynamic_props']);
    }

    public function testListPresetsAndIsTranslatableFieldFiltering(): void
    {
        $registry = $this->createMetadataRegistry(new TypedFormMetadata());
        $presetProvider = $this->createStub(BlockPresetProviderInterface::class);
        $presetProvider->method('getPresets')->willReturn([
            [
                'id' => 'hero-dark',
                'title' => 'Dark Hero',
                'description' => 'Centered dark hero with gradient background',
                'props' => ['background' => 'dark'],
                'values' => ['title' => 'Welcome'],
            ],
            [
                'id' => 'hero-split',
                'title' => 'Split Hero',
                'description' => 'Two-column split layout with image',
                'props' => ['background' => 'light'],
                'values' => ['title' => 'Discover'],
            ],
        ]);

        $inspector = new BlockSuiteSchemaInspector($registry, $presetProvider);

        $filtered = $inspector->listPresets('hero', 'en', 'split');
        self::assertCount(1, $filtered);
        self::assertSame('hero-split', $filtered[0]['id']);

        self::assertTrue($inspector->isTranslatableField('title'));
        self::assertTrue($inspector->isTranslatableField('props/dynamic_empty_message'));
        self::assertTrue($inspector->isTranslatableField('props/dynamic_summary_text'));
        self::assertTrue($inspector->isTranslatableField('props/dynamic_filtered_summary_text'));
        self::assertFalse($inspector->isTranslatableField('props/id'));
        self::assertFalse($inspector->isTranslatableField('props/class'));
        self::assertFalse($inspector->isTranslatableField('props/css'));
        self::assertFalse($inspector->isTranslatableField('props/attributes'));
        self::assertFalse($inspector->isTranslatableField('props/title_size'));
        self::assertFalse($inspector->isTranslatableField('props/dynamic_template_keys'));
    }

    public function testInspectBlockIgnoresBooleanFlagCollectionsAndHiddenFields(): void
    {
        $form = new FormMetadata();
        $form->setKey('rich_text');
        $form->setTitle('Rich Text', 'en');

        $editorField = new FieldMetadata('body');
        $editorField->setType('text_editor');

        $booleanFlags = new OptionMetadata();
        $booleanFlags->setName('toolbar_features');
        $booleanFlags->setType(OptionMetadata::TYPE_COLLECTION);
        $boldFlag = new OptionMetadata();
        $boldFlag->setName('bold');
        $boldFlag->setValue(true);
        $italicFlag = new OptionMetadata();
        $italicFlag->setName('italic');
        $italicFlag->setValue('false');
        $booleanFlags->setValue([$boldFlag, $italicFlag]);
        $editorField->addOption($booleanFlags);

        $hiddenBackingField = new FieldMetadata('hidden_backing');
        $hiddenBackingField->setType('single_select');
        $hiddenBackingField->setVisibleCondition('false');

        $contentSection = new SectionMetadata('content');
        $contentSection->addItem($editorField);
        $contentSection->addItem($hiddenBackingField);
        $form->addItem($contentSection);

        $typedMetadata = new TypedFormMetadata();
        $typedMetadata->addForm('rich_text', $form);

        $inspector = new BlockSuiteSchemaInspector($this->createMetadataRegistry($typedMetadata));
        $schema = $inspector->inspectBlock('rich_text', 'en');

        self::assertNotNull($schema);
        self::assertCount(1, $schema['content_fields']);
        self::assertSame('body', $schema['content_fields'][0]['name']);
        self::assertArrayNotHasKey('option_groups', $schema['content_fields'][0]);
    }

    private function createSampleHeroBlockForm(string $locale): FormMetadata
    {
        $form = new FormMetadata();
        $form->setKey('hero');
        $form->setTitle('Hero Section', $locale);

        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');
        $titleField->setLabel('Title', $locale);

        $sizeCollection = new OptionMetadata();
        $sizeCollection->setName('title_size');
        $sizeCollection->setType(OptionMetadata::TYPE_COLLECTION);

        $iconOpt = new OptionMetadata();
        $iconOpt->setName('_icon');
        $iconOpt->setValue('su-expand');

        $defaultOpt = new OptionMetadata();
        $defaultOpt->setName('_default');
        $defaultOpt->setValue('lg');

        $mdOpt = new OptionMetadata();
        $mdOpt->setName('md');
        $mdOpt->setTitle('Medium', $locale);

        $lgOpt = new OptionMetadata();
        $lgOpt->setName('lg');
        $lgOpt->setTitle('Large', $locale);

        $xlOpt = new OptionMetadata();
        $xlOpt->setName('xl');
        $xlOpt->setTitle('Extra Large', $locale);

        $sizeCollection->setValue([$iconOpt, $defaultOpt, $mdOpt, $lgOpt, $xlOpt]);
        $titleField->addOption($sizeCollection);

        $descField = new FieldMetadata('description');
        $descField->setType('text_editor');
        $descField->setLabel('Description', $locale);

        $contentSection = new SectionMetadata('content');
        $contentSection->addItem($titleField);
        $contentSection->addItem($descField);

        $bgField = new FieldMetadata('background');
        $bgField->setType('single_select');
        $bgField->setLabel('Background', $locale);

        $bgValuesOpt = new OptionMetadata();
        $bgValuesOpt->setName('values');
        $bgValuesOpt->setType(OptionMetadata::TYPE_COLLECTION);
        $lightOpt = new OptionMetadata();
        $lightOpt->setName('light');
        $lightOpt->setTitle('Light', $locale);
        $darkOpt = new OptionMetadata();
        $darkOpt->setName('dark');
        $darkOpt->setTitle('Dark', $locale);
        $bgValuesOpt->setValue([$lightOpt, $darkOpt]);
        $bgField->addOption($bgValuesOpt);

        $settingsSection = new SectionMetadata('settings');
        $settingsSection->addItem($bgField);

        $form->addItem($contentSection);
        $form->addItem($settingsSection);

        $visitor = new BlockSuiteFormMetadataVisitor();
        $visitor->enhanceBlockTabs($form, $locale, includeSharedTabs: true, namespaceProps: true, updateSchema: false);

        return $form;
    }

    private function createMetadataRegistry(TypedFormMetadata $typedFormMetadata): MetadataProviderRegistry
    {
        $provider = $this->createStub(MetadataProviderInterface::class);
        $provider->method('getMetadata')->willReturn($typedFormMetadata);

        $registry = $this->createStub(MetadataProviderRegistry::class);
        $registry->method('getMetadataProvider')->willReturn($provider);

        return $registry;
    }
}
