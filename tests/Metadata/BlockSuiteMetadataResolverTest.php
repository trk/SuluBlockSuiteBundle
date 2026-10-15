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

    public function testResolveItemsPreservesDynamicMappingPlainArraysAndAppliesNestedDefaults(): void
    {
        $propertyResolverProvider = $this->createStub(PropertyResolverProviderInterface::class);
        $propertyResolverProvider->method('getPropertyResolver')->willReturn(new DefaultPropertyResolver());

        $resolver = new BlockSuiteMetadataResolver($propertyResolverProvider);

        $tabsSection = new SectionMetadata('__tabs');

        $contentSection = new SectionMetadata('content');
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');
        $contentSection->addItem($titleField);

        $dynamicSection = new SectionMetadata('dynamic_content');
        $sourceField = new FieldMetadata('props/dynamic_source');
        $sourceField->setType('single_select');
        $dynamicSection->addItem($sourceField);

        $mappingBlock = new FieldMetadata('props/dynamic_mapping');
        $mappingBlock->setType('block');
        $mappingBlock->setDefaultType('field_map');

        $fieldMapForm = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata();
        $fieldMapForm->setKey('field_map');

        $targetField = new FieldMetadata('target_field');
        $targetField->setType('single_select');
        $targetDefault = new OptionMetadata();
        $targetDefault->setName('default_value');
        $targetDefault->setValue('title');
        $targetField->addOption($targetDefault);
        $fieldMapForm->addItem($targetField);

        $srcField = new FieldMetadata('source_field');
        $srcField->setType('single_select');
        $srcDefault = new OptionMetadata();
        $srcDefault->setName('default_value');
        $srcDefault->setValue('title');
        $srcField->addOption($srcDefault);
        $fieldMapForm->addItem($srcField);

        $mappingBlock->addType($fieldMapForm);
        $dynamicSection->addItem($mappingBlock);

        $tabsSection->addItem($contentSection);
        $tabsSection->addItem($dynamicSection);

        $resolved = $resolver->resolveItems(
            ['__tabs' => $tabsSection],
            [
                'title' => '',
                'props' => [
                    'dynamic_source' => 'current_page',
                    'dynamic_mapping' => [
                        [
                            'type' => 'field_map',
                        ],
                    ],
                ],
            ],
            'en',
        );

        $this->assertArrayHasKey('props', $resolved);
        $propsContent = $resolved['props']->getContent();
        $this->assertIsArray($propsContent);
        $this->assertSame('current_page', $propsContent['dynamic_source'] ?? null);
        $this->assertIsArray($propsContent['dynamic_mapping'] ?? null);
        $this->assertSame([
            [
                'type' => 'field_map',
                'target_field' => 'title',
                'source_field' => 'title',
            ],
        ], $propsContent['dynamic_mapping']);
    }

    public function testResolveItemsDelegatesPropsFieldsToPropertyResolvers(): void
    {
        $mediaResolver = new class implements \Sulu\Content\Application\PropertyResolver\Resolver\PropertyResolverInterface {
            public function resolve(mixed $data, string $locale, array $params = []): \Sulu\Content\Application\ContentResolver\Value\ContentView
            {
                if (\is_array($data) && isset($data['id']) && \is_scalar($data['id'])) {
                    $idStr = (string) $data['id'];

                    return \Sulu\Content\Application\ContentResolver\Value\ContentView::create(
                        ['id' => $data['id'], 'url' => '/media/' . $idStr . '/bg.jpg'],
                        ['id' => $data['id']],
                    );
                }

                return \Sulu\Content\Application\ContentResolver\Value\ContentView::create($data, []);
            }

            public static function getType(): string
            {
                return 'single_media_selection';
            }
        };

        $defaultResolver = new DefaultPropertyResolver();
        $propertyResolverProvider = $this->createStub(PropertyResolverProviderInterface::class);
        $propertyResolverProvider->method('getPropertyResolver')
            ->willReturnCallback(static fn (string $type) => 'single_media_selection' === $type ? $mediaResolver : $defaultResolver);

        $resolver = new BlockSuiteMetadataResolver($propertyResolverProvider);

        $tabsSection = new SectionMetadata('__tabs');
        $settingsSection = new SectionMetadata('settings');
        $bgImageField = new FieldMetadata('props/bg_image');
        $bgImageField->setType('single_media_selection');
        $settingsSection->addItem($bgImageField);
        $tabsSection->addItem($settingsSection);

        $resolved = $resolver->resolveItems(
            ['__tabs' => $tabsSection],
            [
                'props' => [
                    'bg_image' => ['id' => 42],
                ],
            ],
            'en',
        );

        $this->assertArrayHasKey('props', $resolved);
        $propsContent = $resolved['props']->getContent();
        $this->assertIsArray($propsContent);
        $this->assertSame(['id' => 42, 'url' => '/media/42/bg.jpg'], $propsContent['bg_image'] ?? null);
        $propsView = $resolved['props']->getView();
        $this->assertSame(['id' => 42], $propsView['bg_image'] ?? null);
    }
}
