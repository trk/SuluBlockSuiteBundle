<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Mcp;

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
use Totoglu\SuluBlockSuiteBundle\Mcp\BlockSuiteMcpTools;
use Totoglu\SuluBlockSuiteBundle\Metadata\BlockSuiteFormMetadataVisitor;
use Totoglu\SuluBlockSuiteBundle\Preset\BlockPresetProviderInterface;

final class BlockSuiteMcpToolsTest extends TestCase
{
    public function testInspectBlocksListsCatalogOrInspectsSingleBlockWithProgressiveDisclosure(): void
    {
        $tools = $this->createMcpTools();

        $catalogResponse = $tools->inspectBlocks(blockType: null, locale: 'en');
        self::assertTrue($catalogResponse['ok']);
        self::assertArrayHasKey('blocks', $catalogResponse);
        self::assertCount(1, $catalogResponse['blocks']);
        self::assertSame('hero', $catalogResponse['blocks'][0]['type']);

        $singleResponse = $tools->inspectBlocks(blockType: 'hero', locale: 'en');
        self::assertTrue($singleResponse['ok']);
        self::assertArrayHasKey('block', $singleResponse);
        self::assertSame('hero', $singleResponse['block']['type']);
        self::assertArrayNotHasKey('dynamic_props', $singleResponse['block']);

        $missingResponse = $tools->inspectBlocks(blockType: 'non_existent', locale: 'en');
        self::assertFalse($missingResponse['ok']);
        self::assertArrayHasKey('error', $missingResponse);
        self::assertStringContainsString('Unknown block type "non_existent"', $missingResponse['error']);
    }

    public function testListPresetsAndComposeBlockReturnStructuredAgentFriendlyResponses(): void
    {
        $tools = $this->createMcpTools();

        $presetsResponse = $tools->listPresets(blockType: 'hero', locale: 'en');
        self::assertTrue($presetsResponse['ok']);
        self::assertCount(1, $presetsResponse['presets']);
        self::assertSame('hero-dark', $presetsResponse['presets'][0]['id']);

        $composed = $tools->composeBlock(
            blockType: 'hero',
            content: ['title' => 'AI Composed Hero'],
            props: ['title_size' => 'lg'],
            preset: 'hero-dark',
            locale: 'en',
        );
        self::assertTrue($composed['ok']);
        self::assertArrayHasKey('block', $composed);
        self::assertSame('hero', $composed['block']['type']);
        self::assertSame('AI Composed Hero', $composed['block']['title']);
        self::assertIsArray($composed['block']['props']);
        self::assertSame('hero-dark', $composed['block']['props']['preset']);
        self::assertSame('lg', $composed['block']['props/title_size']);

        $invalid = $tools->composeBlock(
            blockType: 'hero',
            content: ['title' => 'AI Composed Hero'],
            props: ['title_size' => 'invalid-size'],
            locale: 'en',
        );
        self::assertFalse($invalid['ok']);
        self::assertArrayHasKey('error', $invalid);
        self::assertStringContainsString('Allowed values: md, lg, xl', $invalid['error']);
    }

    private function createMcpTools(): BlockSuiteMcpTools
    {
        $form = new FormMetadata();
        $form->setKey('hero');
        $form->setTitle('Hero Section', 'en');

        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');

        $sizeCollection = new OptionMetadata();
        $sizeCollection->setName('title_size');
        $sizeCollection->setType(OptionMetadata::TYPE_COLLECTION);

        $mdOpt = new OptionMetadata();
        $mdOpt->setName('md');
        $lgOpt = new OptionMetadata();
        $lgOpt->setName('lg');
        $xlOpt = new OptionMetadata();
        $xlOpt->setName('xl');

        $sizeCollection->setValue([$mdOpt, $lgOpt, $xlOpt]);
        $titleField->addOption($sizeCollection);

        $contentSection = new SectionMetadata('content');
        $contentSection->addItem($titleField);
        $form->addItem($contentSection);

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
                'props' => ['title_size' => 'xl'],
                'values' => ['title' => 'Preset Headline'],
            ],
        ]);

        $inspector = new BlockSuiteSchemaInspector($registry, $presetProvider);
        $composer = new BlockSuitePayloadComposer($inspector);

        return new BlockSuiteMcpTools($inspector, $composer);
    }
}
