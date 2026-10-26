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
use Totoglu\SuluBlockSuiteBundle\Ai\BlockSuitePayloadComposer;
use Totoglu\SuluBlockSuiteBundle\Ai\BlockSuiteSchemaInspector;
use Totoglu\SuluBlockSuiteBundle\Metadata\BlockSuiteFormMetadataVisitor;
use Totoglu\SuluBlockSuiteBundle\Preset\BlockPresetProviderInterface;

final class BlockSuitePayloadComposerTest extends TestCase
{
    public function testComposeMergesPresetValuesAndPropsAndEmitsCanonicalBlockPayload(): void
    {
        $composer = $this->createComposer();

        $payload = $composer->compose(
            blockType: 'hero',
            content: ['title' => 'Custom Headline'],
            props: ['title_size' => 'md'],
            preset: 'hero-dark',
            locale: 'en',
        );

        self::assertSame('hero', $payload['type']);
        self::assertSame('Custom Headline', $payload['title']);
        self::assertSame('Default preset subtitle', $payload['description']);
        self::assertIsArray($payload['props']);
        self::assertSame('hero-dark', $payload['props']['preset']);
        self::assertSame('dark', $payload['props']['background']);
        self::assertSame('md', $payload['props']['title_size']);
        self::assertSame('hero-dark', $payload['props/preset']);
        self::assertSame('dark', $payload['props/background']);
        self::assertSame('md', $payload['props/title_size']);
    }

    public function testComposeThrowsActionableExceptionOnInvalidOptionGroupValue(): void
    {
        $composer = $this->createComposer();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value "jumbo" for prop "props/title_size" on block "hero". Allowed values: md, lg, xl.');

        $composer->compose(
            blockType: 'hero',
            content: ['title' => 'Headline'],
            props: ['title_size' => 'jumbo'],
        );
    }

    public function testComposeThrowsActionableExceptionOnUnknownPreset(): void
    {
        $composer = $this->createComposer();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown preset "hero-neon" for block "hero". Available presets: hero-dark.');

        $composer->compose(
            blockType: 'hero',
            content: ['title' => 'Headline'],
            preset: 'hero-neon',
        );
    }

    public function testPreserveTechnicalPropsRestoresNonTranslatablePropsWhileKeepingTranslatedEmptyMessage(): void
    {
        $composer = $this->createComposer();

        $source = [
            'type' => 'hero',
            'title' => 'Welcome',
            'props' => [
                'id' => 'main-hero',
                'class' => 'bg-slate-900 text-white',
                'custom_css' => '& { min-height: 80vh; }',
                'title_size' => 'xl',
                'dynamic_empty_message' => 'No items found',
            ],
            'props/id' => 'main-hero',
            'props/class' => 'bg-slate-900 text-white',
            'props/custom_css' => '& { min-height: 80vh; }',
            'props/title_size' => 'xl',
            'props/dynamic_empty_message' => 'No items found',
        ];

        $translatedCorruptedByAi = [
            'type' => 'hero',
            'title' => 'Hoş Geldiniz',
            'props' => [
                'id' => 'ana-kahraman',
                'class' => 'arka-plan-koyu beyaz-yazi',
                'custom_css' => '& { minimum-yukseklik: 80vh; }',
                'title_size' => 'cok-buyuk',
                'dynamic_empty_message' => 'Öğe bulunamadı',
            ],
            'props/id' => 'ana-kahraman',
            'props/class' => 'arka-plan-koyu beyaz-yazi',
            'props/custom_css' => '& { minimum-yukseklik: 80vh; }',
            'props/title_size' => 'cok-buyuk',
            'props/dynamic_empty_message' => 'Öğe bulunamadı',
        ];

        $repaired = $composer->preserveTechnicalProps($source, $translatedCorruptedByAi);

        self::assertSame('Hoş Geldiniz', $repaired['title']);
        self::assertIsArray($repaired['props']);
        self::assertSame('main-hero', $repaired['props']['id']);
        self::assertSame('bg-slate-900 text-white', $repaired['props']['class']);
        self::assertSame('& { min-height: 80vh; }', $repaired['props']['custom_css']);
        self::assertSame('xl', $repaired['props']['title_size']);
        self::assertSame('Öğe bulunamadı', $repaired['props']['dynamic_empty_message']);
        self::assertSame('main-hero', $repaired['props/id']);
        self::assertSame('bg-slate-900 text-white', $repaired['props/class']);
        self::assertSame('Öğe bulunamadı', $repaired['props/dynamic_empty_message']);
    }

    private function createComposer(): BlockSuitePayloadComposer
    {
        $form = new FormMetadata();
        $form->setKey('hero');
        $form->setTitle('Hero Section', 'en');

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
        $xlOpt = new OptionMetadata();
        $xlOpt->setName('xl');
        $xlOpt->setTitle('Extra Large', 'en');

        $sizeCollection->setValue([$defaultOpt, $mdOpt, $lgOpt, $xlOpt]);
        $titleField->addOption($sizeCollection);

        $descField = new FieldMetadata('description');
        $descField->setType('text_editor');

        $contentSection = new SectionMetadata('content');
        $contentSection->addItem($titleField);
        $contentSection->addItem($descField);

        $bgField = new FieldMetadata('background');
        $bgField->setType('single_select');
        $bgValues = new OptionMetadata();
        $bgValues->setName('values');
        $bgValues->setType(OptionMetadata::TYPE_COLLECTION);
        $lightOpt = new OptionMetadata();
        $lightOpt->setName('light');
        $darkOpt = new OptionMetadata();
        $darkOpt->setName('dark');
        $bgValues->setValue([$lightOpt, $darkOpt]);
        $bgField->addOption($bgValues);

        $settingsSection = new SectionMetadata('settings');
        $settingsSection->addItem($bgField);

        $form->addItem($contentSection);
        $form->addItem($settingsSection);

        $visitor = new BlockSuiteFormMetadataVisitor();
        $visitor->enhanceBlockTabs($form, 'en', includeSharedTabs: true, namespaceProps: true, updateSchema: false);

        $typedMetadata = new TypedFormMetadata();
        $typedMetadata->addForm('hero', $form);

        $provider = $this->createStub(MetadataProviderInterface::class);
        $provider->method('getMetadata')->willReturn($typedMetadata);
        $registry = $this->createStub(MetadataProviderRegistry::class);
        $registry->method('getMetadataProvider')->willReturn($provider);

        $presetProvider = $this->createStub(BlockPresetProviderInterface::class);
        $presetProvider->method('getPresets')->willReturn([
            [
                'id' => 'hero-dark',
                'title' => 'Dark Hero',
                'description' => 'Centered dark hero',
                'props' => ['background' => 'dark', 'title_size' => 'xl'],
                'values' => ['title' => 'Preset Headline', 'description' => 'Default preset subtitle'],
            ],
        ]);

        $inspector = new BlockSuiteSchemaInspector($registry, $presetProvider);

        return new BlockSuitePayloadComposer($inspector);
    }
}
