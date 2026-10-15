<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Content\Application\PropertyResolver\PropertyResolverProviderInterface;
use Sulu\Content\Application\PropertyResolver\Resolver\DefaultPropertyResolver;
use Totoglu\SuluBlockSuiteBundle\Metadata\BlockSuiteMetadataResolver;

final class BlockSuiteMetadataResolverTest extends TestCase
{
    public function testResolveItemsFlattensSiblingSectionsAndAppliesDefaultValuesWithoutOverwritingProps(): void
    {
        $propertyResolverProvider = $this->createStub(PropertyResolverProviderInterface::class);
        $propertyResolverProvider->method('getPropertyResolver')->willReturn(new DefaultPropertyResolver());

        $resolver = new BlockSuiteMetadataResolver($propertyResolverProvider);

        $tabsSection = new SectionMetadata('__tabs');

        $contentSection = new SectionMetadata('content');
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');
        $contentSection->addItem($titleField);

        $settingsSection = new SectionMetadata('settings');
        $variantField = new FieldMetadata('props/variant');
        $variantField->setType('single_select');
        $defaultVariant = new OptionMetadata();
        $defaultVariant->setName('default_value');
        $defaultVariant->setValue('solid');
        $variantField->addOption($defaultVariant);
        $settingsSection->addItem($variantField);

        $generalSection = new SectionMetadata('general');
        $spacingField = new FieldMetadata('props/spacing_y');
        $spacingField->setType('single_select');
        $defaultSpacing = new OptionMetadata();
        $defaultSpacing->setName('default_value');
        $defaultSpacing->setValue('md');
        $spacingField->addOption($defaultSpacing);
        $generalSection->addItem($spacingField);

        $advancedSection = new SectionMetadata('advanced');
        $anchorField = new FieldMetadata('props/anchor_id');
        $anchorField->setType('text_line');
        $advancedSection->addItem($anchorField);

        $tabsSection->addItem($contentSection);
        $tabsSection->addItem($settingsSection);
        $tabsSection->addItem($generalSection);
        $tabsSection->addItem($advancedSection);

        $resolved = $resolver->resolveItems(
            ['__tabs' => $tabsSection],
            [
                'title' => 'Hero Heading',
                'props' => [
                    'anchor_id' => 'hero-1',
                    'extra_preset_prop' => 'keep-me',
                ],
            ],
            'en',
        );

        $this->assertArrayHasKey('title', $resolved);
        $this->assertSame('Hero Heading', $resolved['title']->getContent());

        $this->assertArrayHasKey('props', $resolved);
        $propsContent = $resolved['props']->getContent();
        $this->assertIsArray($propsContent);
        $this->assertSame('solid', $propsContent['variant'] ?? null);
        $this->assertSame('md', $propsContent['spacing_y'] ?? null);
        $this->assertSame('hero-1', $propsContent['anchor_id'] ?? null);
        $this->assertSame('keep-me', $propsContent['extra_preset_prop'] ?? null);
    }

    public function testResolveItemsPreservesAliasedPropertyKeysForNavigation(): void
    {
        $propertyResolverProvider = $this->createStub(PropertyResolverProviderInterface::class);
        $propertyResolverProvider->method('getPropertyResolver')->willReturn(new DefaultPropertyResolver());

        $resolver = new BlockSuiteMetadataResolver($propertyResolverProvider);

        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');

        $urlField = new FieldMetadata('url');
        $urlField->setType('route');

        $resolved = $resolver->resolveItems(
            [
                'nav.title' => $titleField,
                'nav.url' => $urlField,
            ],
            [
                'nav.title' => 'Enterprise Solutions',
                'nav.url' => '/solutions',
            ],
            'en',
        );

        $this->assertArrayHasKey('nav.title', $resolved);
        $this->assertArrayHasKey('nav.url', $resolved);
        $this->assertSame('Enterprise Solutions', $resolved['nav.title']->getContent());
        $this->assertSame('/solutions', $resolved['nav.url']->getContent());
    }
}
