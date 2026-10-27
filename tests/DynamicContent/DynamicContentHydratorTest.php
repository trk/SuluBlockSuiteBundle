<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\DynamicContent;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentHydrator;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentSourceProviderInterface;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\PageSourceResolverInterface;

final class DynamicContentHydratorTest extends TestCase
{
    public function testStaticBlockPassesThroughUnchanged(): void
    {
        $hydrator = new DynamicContentHydrator();

        $block = [
            'type' => 'fixture_card',
            'title' => 'Static Title',
            'summary' => 'Static Summary',
            'props' => [
                'bg_style' => 'white',
            ],
        ];

        $result = $hydrator->hydrateBlock($block);

        self::assertCount(1, $result);
        self::assertSame($block, $result[0]);
    }

    public function testDisabledDynamicContentPassesThrough(): void
    {
        $hydrator = new DynamicContentHydrator();

        $block = [
            'type' => 'fixture_headline',
            'headline' => 'Static Headline',
            'props' => [
                '_dynamic' => [
                    'enabled' => false,
                    'source_type' => 'specific_page',
                    'page_id' => '123-abc',
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($block);

        self::assertCount(1, $result);
        self::assertSame('Static Headline', $result[0]['headline']);
    }

    public function testSpecificPageFieldMapping(): void
    {
        $sourceResolver = $this->createMock(PageSourceResolverInterface::class);
        $hydrator = new DynamicContentHydrator($sourceResolver);

        $pageId = 'page-uuid-001';
        $pageData = [
            'title' => 'About Our Company',
            'content' => [
                'headline' => 'Pioneering Industry Solutions',
                'summary' => 'Established in 1985.',
                'image' => ['id' => 42, 'url' => '/uploads/hero.jpg'],
            ],
            'url' => '/about-us',
        ];

        $sourceResolver->expects(self::once())
            ->method('resolvePage')
            ->with($pageId)
            ->willReturn($pageData);

        $block = [
            'type' => 'fixture_card',
            'title' => 'Old Title',
            'summary' => 'Old Summary',
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'specific_page',
                    'page_id' => $pageId,
                    'mapping' => [
                        'title' => 'title',
                        'headline' => 'content.headline',
                        'summary' => 'content.summary',
                        'image' => 'content.image',
                        'link' => 'url',
                    ],
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($block);

        self::assertCount(1, $result);
        $hydrated = $result[0];

        self::assertSame('About Our Company', $hydrated['title']);
        self::assertSame('Pioneering Industry Solutions', $hydrated['headline']);
        self::assertSame('Established in 1985.', $hydrated['summary']);
        self::assertSame(['id' => 42, 'url' => '/uploads/hero.jpg'], $hydrated['image']);
        self::assertSame('/about-us', $hydrated['link']);
    }

    public function testSmartFallbackPreservesStaticContentWhenDynamicFieldEmpty(): void
    {
        $sourceResolver = $this->createMock(PageSourceResolverInterface::class);
        $hydrator = new DynamicContentHydrator($sourceResolver);

        $pageId = 'page-uuid-002';
        $pageData = [
            'title' => 'Services',
            'content' => [
                'summary' => null, // empty dynamic field
            ],
        ];

        $sourceResolver->expects(self::once())
            ->method('resolvePage')
            ->with($pageId)
            ->willReturn($pageData);

        $block = [
            'type' => 'fixture_card',
            'title' => 'Static Title',
            'summary' => 'Static Manual Summary Fallback',
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'specific_page',
                    'page_id' => $pageId,
                    'mapping' => [
                        'title' => 'title',
                        'summary' => 'content.summary',
                    ],
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($block);

        self::assertCount(1, $result);
        self::assertSame('Services', $result[0]['title']);
        // Summary fell back to static manual content
        self::assertSame('Static Manual Summary Fallback', $result[0]['summary']);
    }

    public function testDisplayConditionNotEmptyHidesBlockWhenFieldEmpty(): void
    {
        $sourceResolver = $this->createMock(PageSourceResolverInterface::class);
        $hydrator = new DynamicContentHydrator($sourceResolver);

        $pageId = 'page-uuid-003';
        $pageData = [
            'title' => 'No Image Page',
            'content' => [
                'image' => null,
            ],
        ];

        $sourceResolver->expects(self::once())
            ->method('resolvePage')
            ->with($pageId)
            ->willReturn($pageData);

        $block = [
            'type' => 'fixture_image',
            'image' => null,
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'specific_page',
                    'page_id' => $pageId,
                    'mapping' => [
                        'image' => 'content.image',
                    ],
                    'condition' => [
                        'field' => 'content.image',
                        'operator' => 'not_empty',
                    ],
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($block);

        self::assertCount(0, $result); // Block hidden!
    }

    public function testDisplayConditionNotEmptyAllowsBlockWhenFieldPresent(): void
    {
        $sourceResolver = $this->createMock(PageSourceResolverInterface::class);
        $hydrator = new DynamicContentHydrator($sourceResolver);

        $pageId = 'page-uuid-004';
        $pageData = [
            'title' => 'Image Page',
            'content' => [
                'image' => '/uploads/photo.jpg',
            ],
        ];

        $sourceResolver->expects(self::once())
            ->method('resolvePage')
            ->with($pageId)
            ->willReturn($pageData);

        $block = [
            'type' => 'fixture_image',
            'image' => null,
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'specific_page',
                    'page_id' => $pageId,
                    'mapping' => [
                        'image' => 'content.image',
                    ],
                    'condition' => [
                        'field' => 'content.image',
                        'operator' => 'not_empty',
                    ],
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($block);

        self::assertCount(1, $result);
        self::assertSame('/uploads/photo.jpg', $result[0]['image']);
    }

    public function testGracefulFallbackWhenPageNotFound(): void
    {
        $sourceResolver = $this->createMock(PageSourceResolverInterface::class);
        $hydrator = new DynamicContentHydrator($sourceResolver);

        $sourceResolver->expects(self::once())
            ->method('resolvePage')
            ->with('missing-uuid')
            ->willReturn(null);

        $block = [
            'type' => 'fixture_headline',
            'headline' => 'Fallback Headline',
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'specific_page',
                    'page_id' => 'missing-uuid',
                    'mapping' => [
                        'headline' => 'title',
                    ],
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($block);

        self::assertCount(1, $result);
        self::assertSame('Fallback Headline', $result[0]['headline']);
    }

    public function testPageChildrenExpandsToMultipleBlocks(): void
    {
        $sourceResolver = $this->createMock(PageSourceResolverInterface::class);
        $hydrator = new DynamicContentHydrator($sourceResolver);

        $parentId = 'parent-uuid-001';
        $children = [
            [
                'title' => 'Service 1',
                'url' => '/services/1',
                'content' => ['summary' => 'Summary 1'],
            ],
            [
                'title' => 'Service 2',
                'url' => '/services/2',
                'content' => ['summary' => 'Summary 2'],
            ],
            [
                'title' => 'Service 3',
                'url' => '/services/3',
                'content' => ['summary' => 'Summary 3'],
            ],
        ];

        $sourceResolver->expects(self::once())
            ->method('resolveChildren')
            ->with($parentId, null, 10)
            ->willReturn($children);

        $block = [
            'type' => 'fixture_card',
            'title' => 'Template Card',
            'props' => [
                'variant' => 'elevated',
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'page_children',
                    'parent_id' => $parentId,
                    'limit' => 10,
                    'mapping' => [
                        'title' => 'title',
                        'link' => 'url',
                        'summary' => 'content.summary',
                    ],
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($block);

        self::assertCount(3, $result);
        self::assertSame('Service 1', $result[0]['title']);
        self::assertSame('/services/1', $result[0]['link']);
        self::assertSame('Summary 1', $result[0]['summary']);
        self::assertIsArray($result[0]['props']);
        self::assertSame('elevated', $result[0]['props']['variant']);

        self::assertSame('Service 2', $result[1]['title']);
        self::assertSame('Service 3', $result[2]['title']);
    }

    public function testCustomQueryExpandsToMultipleBlocks(): void
    {
        $sourceResolver = $this->createMock(PageSourceResolverInterface::class);
        $hydrator = new DynamicContentHydrator($sourceResolver);

        $query = [
            'categories' => ['news'],
            'limit' => 2,
        ];

        $items = [
            ['title' => 'Article 1', 'url' => '/news/1'],
            ['title' => 'Article 2', 'url' => '/news/2'],
        ];

        $sourceResolver->expects(self::once())
            ->method('resolveQuery')
            ->with($query)
            ->willReturn($items);

        $block = [
            'type' => 'fixture_headline',
            'headline' => 'Default',
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'custom_query',
                    'query' => $query,
                    'mapping' => [
                        'headline' => 'title',
                    ],
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($block);

        self::assertCount(2, $result);
        self::assertSame('Article 1', $result[0]['headline']);
        self::assertSame('Article 2', $result[1]['headline']);
    }

    public function testDisplayConditionFiltersOutNonMatchingItemsInRepeater(): void
    {
        $sourceResolver = $this->createMock(PageSourceResolverInterface::class);
        $hydrator = new DynamicContentHydrator($sourceResolver);

        $parentId = 'parent-uuid-002';
        $children = [
            ['title' => 'Item With Image', 'content' => ['image' => '/pic.jpg']],
            ['title' => 'Item Without Image', 'content' => ['image' => null]],
            ['title' => 'Another Item With Image', 'content' => ['image' => '/pic2.jpg']],
        ];

        $sourceResolver->expects(self::once())
            ->method('resolveChildren')
            ->with($parentId, null, 50)
            ->willReturn($children);

        $block = [
            'type' => 'fixture_card',
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'page_children',
                    'parent_id' => $parentId,
                    'mapping' => [
                        'title' => 'title',
                        'image' => 'content.image',
                    ],
                    'condition' => [
                        'field' => 'content.image',
                        'operator' => 'not_empty',
                    ],
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($block);

        self::assertCount(2, $result);
        self::assertSame('Item With Image', $result[0]['title']);
        self::assertSame('Another Item With Image', $result[1]['title']);
    }

    public function testHydrateBlocksCollectionFlattensRepeatersAndStaticBlocks(): void
    {
        $sourceResolver = $this->createMock(PageSourceResolverInterface::class);
        $hydrator = new DynamicContentHydrator($sourceResolver);

        $parentId = 'parent-uuid-003';
        $sourceResolver->expects(self::once())
            ->method('resolveChildren')
            ->with($parentId, null, 50)
            ->willReturn([
                ['title' => 'Child 1'],
                ['title' => 'Child 2'],
            ]);

        $blocks = [
            ['type' => 'fixture_headline', 'title' => 'Top Static'],
            [
                'type' => 'fixture_card',
                'props' => [
                    '_dynamic' => [
                        'enabled' => true,
                        'source_type' => 'page_children',
                        'parent_id' => $parentId,
                        'mapping' => ['title' => 'title'],
                    ],
                ],
            ],
            ['type' => 'fixture_divider'],
        ];

        $flattened = $hydrator->hydrateBlocks($blocks);

        self::assertCount(4, $flattened);
        self::assertSame('fixture_headline', $flattened[0]['type']);
        self::assertSame('Top Static', $flattened[0]['title']);
        self::assertSame('fixture_card', $flattened[1]['type']);
        self::assertSame('Child 1', $flattened[1]['title']);
        self::assertSame('fixture_card', $flattened[2]['type']);
        self::assertSame('Child 2', $flattened[2]['title']);
        self::assertSame('fixture_divider', $flattened[3]['type']);
    }

    public function testExtractsDynamicConfigFromContentViewObject(): void
    {
        $sourceResolver = $this->createMock(PageSourceResolverInterface::class);
        $hydrator = new DynamicContentHydrator($sourceResolver);

        $sourceResolver->expects(self::once())
            ->method('resolvePage')
            ->willReturn(['title' => 'Hydrated From Object']);

        $propsObject = new class {
            /**
             * @return array<string, mixed>
             */
            public function getContent(): array
            {
                return [
                    '_dynamic' => [
                        'enabled' => true,
                        'source_type' => 'specific_page',
                        'page_id' => 'obj-123',
                        'mapping' => ['title' => 'title'],
                    ],
                ];
            }
        };

        $block = [
            'type' => 'fixture_card',
            'title' => 'Original',
            'props' => $propsObject,
        ];

        $result = $hydrator->hydrateBlock($block);
        self::assertCount(1, $result);
        self::assertSame('Hydrated From Object', $result[0]['title']);
    }

    public function testCurrentPageSourceResolvesWhenContextPageIsEmptyArray(): void
    {
        $sourceResolver = $this->createMock(PageSourceResolverInterface::class);
        $hydrator = new DynamicContentHydrator($sourceResolver);

        $sourceResolver->expects(self::once())
            ->method('resolvePage')
            ->with([])
            ->willReturn(['title' => 'Current Page Mapped Title']);

        $block = [
            'type' => 'fixture_headline',
            'title' => 'Static Headline',
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'current_page',
                    'mapping' => ['title' => 'title'],
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($block, []);
        self::assertCount(1, $result);
        self::assertSame('Current Page Mapped Title', $result[0]['title']);
    }

    public function testParentPageAndSiblingPagesSources(): void
    {
        $sourceResolver = $this->createMock(PageSourceResolverInterface::class);
        $hydrator = new DynamicContentHydrator($sourceResolver);

        $sourceResolver->expects(self::once())
            ->method('resolveParent')
            ->with(['id' => 'child-page'])
            ->willReturn(['title' => 'Parent Section Title', 'url' => '/parent']);

        $sourceResolver->expects(self::once())
            ->method('resolveSiblings')
            ->with(['id' => 'child-page'], null, 5)
            ->willReturn([
                ['title' => 'Sibling 1', 'url' => '/sibling-1'],
                ['title' => 'Sibling 2', 'url' => '/sibling-2'],
            ]);

        $parentBlock = [
            'type' => 'fixture_headline',
            'title' => 'Fallback',
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'parent_page',
                    'mapping' => ['title' => 'title', 'link' => 'url'],
                ],
            ],
        ];

        $parentResult = $hydrator->hydrateBlock($parentBlock, ['id' => 'child-page']);
        self::assertCount(1, $parentResult);
        self::assertSame('Parent Section Title', $parentResult[0]['title']);
        self::assertSame('/parent', $parentResult[0]['link']);

        $siblingBlock = [
            'type' => 'fixture_card',
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'sibling_pages',
                    'limit' => 5,
                    'mapping' => ['title' => 'title', 'link' => 'url'],
                ],
            ],
        ];

        $siblingResult = $hydrator->hydrateBlock($siblingBlock, ['id' => 'child-page']);
        self::assertCount(2, $siblingResult);
        self::assertSame('Sibling 1', $siblingResult[0]['title']);
        self::assertSame('Sibling 2', $siblingResult[1]['title']);
    }

    public function testMultiItemContainerBlockHydratesInnerItemsArrayInsteadOfRepeatingContainer(): void
    {
        $sourceResolver = $this->createMock(PageSourceResolverInterface::class);
        $hydrator = new DynamicContentHydrator($sourceResolver);

        $sourceResolver->expects(self::once())
            ->method('resolveChildren')
            ->with('services-root', null, 10)
            ->willReturn([
                ['title' => 'Cloud Architecture', 'summary' => 'Scalable cloud infra', 'url' => '/services/cloud'],
                ['title' => 'Security Audit', 'summary' => 'Zero-trust verification', 'url' => '/services/security'],
            ]);

        $gridBlock = [
            'type' => 'fixture_grid',
            'items' => [
                ['type' => 'fixture_grid_item', 'title' => 'Placeholder', 'button_text' => 'Read More'],
            ],
            'props' => [
                'columns' => '3',
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'page_children',
                    'parent_id' => 'services-root',
                    'limit' => 10,
                    'mapping' => [
                        'title' => 'title',
                        'summary' => 'summary',
                        'link' => 'url',
                    ],
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($gridBlock);

        // Must return 1 grid block with 2 inner items, NOT 2 grid blocks!
        self::assertCount(1, $result);
        self::assertSame('fixture_grid', $result[0]['type']);
        self::assertIsArray($result[0]['items']);
        /** @var list<array<string, mixed>> $items */
        $items = $result[0]['items'];
        self::assertCount(2, $items);

        self::assertSame('fixture_grid_item', $items[0]['type']);
        self::assertSame('Cloud Architecture', $items[0]['title']);
        self::assertSame('Scalable cloud infra', $items[0]['summary']);
        self::assertSame('/services/cloud', $items[0]['link']);
        self::assertSame('Read More', $items[0]['button_text']);

        self::assertSame('Security Audit', $items[1]['title']);
    }

    public function testEmptyContainerBlockUsesDefaultItemTypeAndNormalizesItemsPrefix(): void
    {
        $sourceResolver = $this->createMock(PageSourceResolverInterface::class);
        $hydrator = new DynamicContentHydrator($sourceResolver);

        $sourceResolver->expects(self::once())
            ->method('resolveChildren')
            ->with('faq-root', null, 50)
            ->willReturn([
                ['title' => 'What is Sulu 3?', 'summary' => 'Modern Symfony CMS'],
                ['title' => 'How do presets work?', 'summary' => 'Reusable prop sets'],
            ]);

        $accordionBlock = [
            'type' => 'fixture_accordion',
            'items' => [],
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'page_children',
                    'parent_id' => 'faq-root',
                    'mapping' => [
                        'items.title' => 'title',
                        'content' => 'summary',
                    ],
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($accordionBlock);

        self::assertCount(1, $result);
        self::assertSame('fixture_accordion', $result[0]['type']);
        self::assertIsArray($result[0]['items']);
        /** @var list<array<string, mixed>> $items */
        $items = $result[0]['items'];
        self::assertCount(2, $items);

        self::assertSame('fixture_accordion_item', $items[0]['type']);
        self::assertSame('What is Sulu 3?', $items[0]['title']);
        self::assertSame('Modern Symfony CMS', $items[0]['content']);

        self::assertSame('fixture_accordion_item', $items[1]['type']);
        self::assertSame('How do presets work?', $items[1]['title']);
        self::assertSame('Reusable prop sets', $items[1]['content']);
    }

    public function testCustomDynamicContentSourceProviderIntegration(): void
    {
        $customProvider = new class implements DynamicContentSourceProviderInterface {
            public function getKey(): string
            {
                return 'custom_products';
            }

            public function getLabel(string $locale = 'en'): string
            {
                return 'tr' === $locale ? 'Ürünler' : 'Products';
            }

            public function isMultiItem(): bool
            {
                return true;
            }

            public function getSourceFields(string $locale = 'en'): array
            {
                return [
                    ['name' => 'name', 'label' => 'Product Name', 'type' => 'string'],
                ];
            }

            public function resolveSingle(array $config, string|object|array|null $contextPage = null, ?string $locale = null): ?array
            {
                return null;
            }

            public function resolveCollection(array $config, string|object|array|null $contextPage = null, ?string $locale = null): array
            {
                return [
                    ['name' => 'Enterprise Suite', 'price' => 299],
                    ['name' => 'Starter Pack', 'price' => 49],
                ];
            }
        };

        $hydrator = new DynamicContentHydrator(null, [$customProvider]);

        $block = [
            'type' => 'fixture_card',
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'custom_products',
                    'mapping' => ['title' => 'name'],
                    'condition' => [
                        'field' => 'price',
                        'operator' => 'gte',
                        'value' => '100',
                    ],
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($block);

        self::assertCount(1, $result);
        self::assertSame('Enterprise Suite', $result[0]['title']);
    }

    public function testExtendedDisplayConditionOperators(): void
    {
        $sourceResolver = $this->createStub(PageSourceResolverInterface::class);
        $hydrator = new DynamicContentHydrator($sourceResolver);

        $sourceResolver->method('resolvePage')->willReturn([
            'title' => 'Symfony Enterprise Guide',
            'template' => 'article',
            'tags' => ['php', 'symfony', 'sulu'],
        ]);

        // Test 'contains' on array
        $blockContains = [
            'type' => 'fixture_card',
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'specific_page',
                    'page_id' => 'p1',
                    'mapping' => ['title' => 'title'],
                    'condition' => ['field' => 'tags', 'operator' => 'contains', 'value' => 'sulu'],
                ],
            ],
        ];
        self::assertCount(1, $hydrator->hydrateBlock($blockContains));

        // Test 'in' comma-separated list
        $blockIn = [
            'type' => 'fixture_card',
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'specific_page',
                    'page_id' => 'p1',
                    'mapping' => ['title' => 'title'],
                    'condition' => ['field' => 'template', 'operator' => 'in', 'value' => 'default, article, landing'],
                ],
            ],
        ];
        self::assertCount(1, $hydrator->hydrateBlock($blockIn));

        // Test 'starts_with' failing
        $blockStartsWith = [
            'type' => 'fixture_card',
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'specific_page',
                    'page_id' => 'p1',
                    'mapping' => ['title' => 'title'],
                    'condition' => ['field' => 'title', 'operator' => 'starts_with', 'value' => 'Laravel'],
                ],
            ],
        ];
        self::assertCount(0, $hydrator->hydrateBlock($blockStartsWith));
    }

    public function testPageSelectionSourceAndDynamicBlockMetadataMultiplicity(): void
    {
        $sourceResolver = $this->createMock(PageSourceResolverInterface::class);
        $sourceResolver->expects(self::once())
            ->method('resolveQuery')
            ->with([
                'uuids' => ['uuid-1', 'uuid-2'],
                'limit' => 2,
            ])
            ->willReturn([
                ['title' => 'Home', 'url' => '/'],
                ['title' => 'About', 'url' => '/about'],
            ]);

        $trailForm = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata();
        $trailForm->setKey('fixture_trail');

        $childItemForm = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata();
        $childItemForm->setKey('fixture_trail_item');

        $itemsBlockField = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata('items');
        $itemsBlockField->setType('block');
        $itemsBlockField->setDefaultType('fixture_trail_item');
        $itemsBlockField->addType($childItemForm);
        $trailForm->addItem($itemsBlockField);

        $typedFormMetadata = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata();
        $typedFormMetadata->addForm('fixture_trail', $trailForm);

        $provider = $this->createStub(\Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface::class);
        $provider->method('getMetadata')->willReturn($typedFormMetadata);

        $registry = new \Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry(
            new \Symfony\Component\DependencyInjection\ServiceLocator([
                'form' => static fn () => $provider,
            ]),
        );

        $targetFieldResolver = new \Totoglu\SuluBlockSuiteBundle\Metadata\BlockTargetFieldMetadataResolver($registry);
        $hydrator = new DynamicContentHydrator($sourceResolver, [], $targetFieldResolver);

        $trailBlock = [
            'type' => 'fixture_trail',
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'page_selection',
                    'uuids' => ['uuid-1', 'uuid-2'],
                    'limit' => 2,
                    'mapping' => [
                        'title' => 'title',
                        'url' => 'url',
                    ],
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($trailBlock);

        self::assertCount(1, $result);
        self::assertSame('fixture_trail', $result[0]['type']);
        self::assertIsArray($result[0]['items']);
        /** @var list<array<string, mixed>> $items */
        $items = $result[0]['items'];
        self::assertCount(2, $items);
        self::assertSame('fixture_trail_item', $items[0]['type']);
        self::assertSame('Home', $items[0]['title']);
        self::assertSame('/about', $items[1]['url']);
    }

    public function testDualModeCustomProductSourceProviderSingleAndCollectionHydration(): void
    {
        $productProvider = new class extends \Totoglu\SuluBlockSuiteBundle\DynamicContent\AbstractDynamicContentSourceProvider {
            public function getKey(): string
            {
                return 'products';
            }

            public function getLabel(string $locale = 'en'): string
            {
                return 'Product Collection';
            }

            public function getSingleKey(): string
            {
                return 'single_product';
            }

            public function getSingleLabel(string $locale = 'en'): string
            {
                return 'Single Product Selection';
            }

            public function getSourceFields(string $locale = 'en'): array
            {
                return [
                    ['name' => 'title', 'label' => 'Product Name', 'type' => 'string'],
                    ['name' => 'sku', 'label' => 'SKU', 'type' => 'string'],
                    ['name' => 'price', 'label' => 'Price', 'type' => 'string'],
                ];
            }

            public function resolveSingle(array $config, string|object|array|null $contextPage = null, ?string $locale = null): ?array
            {
                $productId = $config['product_id'] ?? null;
                if (42 === $productId || '42' === $productId) {
                    return [
                        'title' => 'Industrial CNC Router X500',
                        'sku' => 'CNC-X500',
                        'price' => '$14,500',
                    ];
                }

                return null;
            }

            public function resolveCollection(array $config, string|object|array|null $contextPage = null, ?string $locale = null): array
            {
                return [
                    ['title' => 'Industrial CNC Router X500', 'sku' => 'CNC-X500', 'price' => '$14,500'],
                    ['title' => 'Laser Cutter Pro L200', 'sku' => 'LSR-L200', 'price' => '$8,900'],
                ];
            }
        };

        $hydrator = new DynamicContentHydrator(null, [$productProvider]);

        // 1. Single product mode on a single block (fixture_card)
        $singleCard = [
            'type' => 'fixture_card',
            'title' => 'Static Product',
            'badge' => 'STATIC',
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'single_product',
                    'product_id' => 42,
                    'mapping' => [
                        'title' => 'title',
                        'badge' => 'sku',
                        'subtitle' => 'price',
                    ],
                ],
            ],
        ];

        $singleResult = $hydrator->hydrateBlock($singleCard);
        self::assertCount(1, $singleResult);
        self::assertSame('Industrial CNC Router X500', $singleResult[0]['title']);
        self::assertSame('CNC-X500', $singleResult[0]['badge']);
        self::assertSame('$14,500', $singleResult[0]['subtitle']);

        // 2. Product collection mode on a multiple block (fixture_grid)
        $gridBlock = [
            'type' => 'fixture_grid',
            'items' => [],
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'products',
                    'product_ids' => [42, 43],
                    'mapping' => [
                        'title' => 'title',
                        'badge' => 'sku',
                    ],
                ],
            ],
        ];

        $collectionResult = $hydrator->hydrateBlock($gridBlock);
        self::assertCount(1, $collectionResult);
        self::assertIsArray($collectionResult[0]['items']);
        /** @var list<array<string, mixed>> $items */
        $items = $collectionResult[0]['items'];
        self::assertCount(2, $items);
        self::assertSame('Industrial CNC Router X500', $items[0]['title']);
        self::assertSame('CNC-X500', $items[0]['badge']);
        self::assertSame('Laser Cutter Pro L200', $items[1]['title']);
        self::assertSame('LSR-L200', $items[1]['badge']);
    }

    public function testFlatPropsDynamicMappingUnwrapsContentViewsAndAutoMapsWhenEmpty(): void
    {
        $sourceResolver = $this->createMock(PageSourceResolverInterface::class);
        $sourceResolver->expects(self::exactly(2))
            ->method('resolvePage')
            ->willReturn([
                'title' => 'Dynamic Current Page Headline',
                'subtitle' => 'Dynamic Subtitle',
            ]);

        $hydrator = new DynamicContentHydrator($sourceResolver);

        $wrappedEntry = new class {
            /**
             * @return array<string, mixed>
             */
            public function getContent(): array
            {
                return [
                    'type' => 'field_map',
                    'target_field' => 'title',
                    'source_field' => 'title',
                ];
            }
        };

        $headlineWithWrappedMapping = [
            'type' => 'fixture_headline',
            'title' => '',
            'subtitle' => '',
            'props' => [
                'dynamic_source' => 'current_page',
                'dynamic_mapping' => [$wrappedEntry],
                'dynamic_condition_field' => 'non_existent_field',
            ],
        ];

        $result = $hydrator->hydrateBlock($headlineWithWrappedMapping, ['title' => 'Dynamic Current Page Headline']);
        self::assertCount(1, $result);
        self::assertSame('Dynamic Current Page Headline', $result[0]['title']);

        $headlineWithoutExplicitMapping = [
            'type' => 'fixture_headline',
            'title' => '',
            'subtitle' => '',
            'props' => [
                'dynamic_source' => 'current_page',
                'dynamic_mapping' => [],
            ],
        ];

        $autoResult = $hydrator->hydrateBlock($headlineWithoutExplicitMapping, ['title' => 'Dynamic Current Page Headline']);
        self::assertCount(1, $autoResult);
        self::assertSame('Dynamic Current Page Headline', $autoResult[0]['title']);
        self::assertSame('Dynamic Subtitle', $autoResult[0]['subtitle']);
    }

    public function testHideIfEmptyHidesCurrentPageBlockWhenMappedFieldsAreEmpty(): void
    {
        $sourceResolver = $this->createStub(PageSourceResolverInterface::class);
        $sourceResolver->method('resolvePage')->willReturn([
            'title' => 'Platform',
            'summary' => '',
            'image' => null,
        ]);
        $hydrator = new DynamicContentHydrator($sourceResolver);

        $block = [
            'type' => 'fixture_rich_text',
            'content' => '<p>Static placeholder</p>',
            'props' => [
                'dynamic_source' => 'current_page',
                'dynamic_hide_if_empty' => true,
                'dynamic_mapping' => [
                    ['type' => 'field_map', 'target_field' => 'content', 'source_field' => 'summary'],
                ],
            ],
        ];

        self::assertSame([], $hydrator->hydrateBlock($block, ['title' => 'Platform']));

        // Without the option the static content stays as fallback.
        $block['props']['dynamic_hide_if_empty'] = false;
        $result = $hydrator->hydrateBlock($block, ['title' => 'Platform']);
        self::assertCount(1, $result);
        self::assertSame('<p>Static placeholder</p>', $result[0]['content']);
    }

    public function testHideIfEmptyKeepsBlockWhenAnyMappedFieldResolves(): void
    {
        $sourceResolver = $this->createStub(PageSourceResolverInterface::class);
        $sourceResolver->method('resolvePage')->willReturn([
            'title' => 'Solutions',
            'summary' => 'Modular content architecture.',
            'image' => null,
        ]);
        $hydrator = new DynamicContentHydrator($sourceResolver);

        $block = [
            'type' => 'fixture_rich_text',
            'content' => '',
            'props' => [
                'dynamic_source' => 'current_page',
                'dynamic_hide_if_empty' => '1',
                'dynamic_mapping' => [
                    ['type' => 'field_map', 'target_field' => 'content', 'source_field' => 'summary'],
                    ['type' => 'field_map', 'target_field' => 'image', 'source_field' => 'image'],
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($block, ['title' => 'Solutions']);
        self::assertCount(1, $result);
        self::assertSame('Modular content architecture.', $result[0]['content']);
    }

    public function testHideIfEmptyHidesBlockWhenSourcePageIsMissing(): void
    {
        $sourceResolver = $this->createStub(PageSourceResolverInterface::class);
        $sourceResolver->method('resolvePage')->willReturn(null);
        $hydrator = new DynamicContentHydrator($sourceResolver);

        $block = [
            'type' => 'fixture_headline',
            'title' => 'Fallback',
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'specific_page',
                    'page_id' => 'missing-uuid',
                    'hide_if_empty' => true,
                    'mapping' => ['title' => 'title'],
                ],
            ],
        ];

        self::assertSame([], $hydrator->hydrateBlock($block));
    }

    public function testHideIfEmptyDropsEmptyCollectionItems(): void
    {
        $sourceResolver = $this->createStub(PageSourceResolverInterface::class);
        $sourceResolver->method('resolveChildren')->willReturn([
            ['title' => 'Child A', 'summary' => 'Has summary'],
            ['title' => 'Child B', 'summary' => ''],
        ]);
        $hydrator = new DynamicContentHydrator($sourceResolver);

        $block = [
            'type' => 'fixture_card',
            'content' => '',
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'page_children',
                    'parent_id' => 'parent-uuid',
                    'hydrate_mode' => 'repeater',
                    'hide_if_empty' => true,
                    'mapping' => ['content' => 'summary'],
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($block);
        self::assertCount(1, $result);
        self::assertSame('Has summary', $result[0]['content']);

        $sourceResolver = $this->createStub(PageSourceResolverInterface::class);
        $sourceResolver->method('resolveChildren')->willReturn([
            ['title' => 'Child B', 'summary' => ''],
        ]);
        $hydrator = new DynamicContentHydrator($sourceResolver);

        self::assertSame([], $hydrator->hydrateBlock($block));
    }

    public function testExtractValueSupportsPipeFallbacksAndLengthModifier(): void
    {
        $applier = new \Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentMappingApplier();

        $longHtml = '<p><strong>Sulu 3 Enterprise CMS</strong> delivers structured content management with Preline UI 5 and Tailwind CSS 4 for high-performance digital experiences across every channel and device worldwide.</p>';

        $dataWithEmptyHeadlineAndSummary = [
            'title' => 'Page Navigation Title',
            'headline' => '',
            'summary' => '   ',
            'content' => $longHtml,
        ];

        self::assertSame(
            'Page Navigation Title',
            $applier->extractValue($dataWithEmptyHeadlineAndSummary, 'headline|title'),
        );

        $truncated = $applier->extractValue($dataWithEmptyHeadlineAndSummary, 'summary|content:length=160');
        self::assertIsString($truncated);
        self::assertLessThanOrEqual(160, \mb_strlen($truncated, 'UTF-8'));
        self::assertStringStartsWith('Sulu 3 Enterprise CMS delivers structured content management', $truncated);
        self::assertStringEndsWith('...', $truncated);
        self::assertStringNotContainsString('<p>', $truncated);
        self::assertStringNotContainsString('<strong>', $truncated);

        $dataWithExplicitHeadlineAndSummary = [
            'title' => 'Short Title',
            'headline' => 'Custom Hero Display Headline',
            'summary' => 'Concise custom summary.',
            'content' => $longHtml,
        ];

        self::assertSame(
            'Custom Hero Display Headline',
            $applier->extractValue($dataWithExplicitHeadlineAndSummary, 'headline|title'),
        );
        self::assertSame(
            'Concise custom summary.',
            $applier->extractValue($dataWithExplicitHeadlineAndSummary, 'summary|content:length=160'),
        );
    }

    public function testAutoMappingMatchesByEqualNameAndFieldTypeCompatibility(): void
    {
        $richBody = '<p>Modern Sulu 3 architecture with metadata-driven field mapping.</p>';

        $sourceResolver = $this->createStub(PageSourceResolverInterface::class);
        $sourceResolver->method('resolvePage')->willReturn([
            'title' => 'Card Page Title',
            'content' => $richBody,
            'image' => ['id' => 99, 'url' => '/uploads/card.jpg'],
            'url' => '/solutions/card-page',
        ]);

        $fixtureTeaserForm = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata();
        $fixtureTeaserForm->setKey('fixture_teaser');

        $titleField = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata('title');
        $titleField->setType('text_line');
        $fixtureTeaserForm->addItem($titleField);

        $bodyField = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata('body');
        $bodyField->setType('text_editor');
        $fixtureTeaserForm->addItem($bodyField);

        $photoField = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata('photo');
        $photoField->setType('single_media_selection');
        $fixtureTeaserForm->addItem($photoField);

        $ctaLinkField = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata('cta_link');
        $ctaLinkField->setType('link');
        $fixtureTeaserForm->addItem($ctaLinkField);

        $typedFormMetadata = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata();
        $typedFormMetadata->addForm('fixture_teaser', $fixtureTeaserForm);

        $provider = $this->createStub(\Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface::class);
        $provider->method('getMetadata')->willReturn($typedFormMetadata);

        $registry = new \Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry(
            new \Symfony\Component\DependencyInjection\ServiceLocator([
                'form' => static fn () => $provider,
            ]),
        );

        $targetFieldResolver = new \Totoglu\SuluBlockSuiteBundle\Metadata\BlockTargetFieldMetadataResolver($registry);
        $hydrator = new DynamicContentHydrator($sourceResolver, [], $targetFieldResolver);

        $block = [
            'type' => 'fixture_teaser',
            'title' => '',
            'body' => '',
            'photo' => null,
            'cta_link' => '',
            'props' => [
                'dynamic_source' => 'specific_page',
                'dynamic_page_id' => 'page-123',
                'dynamic_mapping' => [],
            ],
        ];

        $hydratedList = $hydrator->hydrateBlock($block);
        self::assertCount(1, $hydratedList);
        $hydrated = $hydratedList[0];

        // 1. Equal field name match
        self::assertSame('Card Page Title', $hydrated['title']);
        // 2. Type compatibility matches via BlockTargetFieldMetadataResolver
        self::assertSame($richBody, $hydrated['body']);
        self::assertSame(['id' => 99, 'url' => '/uploads/card.jpg'], $hydrated['photo']);
        self::assertSame('/solutions/card-page', $hydrated['cta_link']);
    }

    public function testExtractValueWithStripHtmlIgnoresNonScalarValueAndUsesFallback(): void
    {
        $applier = new \Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentMappingApplier();

        $source = [
            'summary' => ['nested' => 'array_value'],
            'title' => '<strong>Clean Scalar Title</strong>',
        ];

        $extracted = $applier->extractValue($source, 'summary:plain|title:plain');
        self::assertSame('Clean Scalar Title', $extracted);
    }

    public function testPageChildrenIgnoresCategoryParentIdWhenPageIdSet(): void
    {
        $sourceResolver = $this->createMock(PageSourceResolverInterface::class);
        $sourceResolver->expects(self::once())
            ->method('resolveQuery')
            ->with(self::callback(static function (array $query): bool {
                return ($query['parent_id'] ?? null) === 'target-page-uuid';
            }), null)
            ->willReturn([
                ['title' => 'Child Page 1', 'url' => '/child-1'],
            ]);

        $pageProvider = new \Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider\SuluPageSourceProvider($sourceResolver);
        $hydrator = new DynamicContentHydrator($sourceResolver, [$pageProvider]);

        $block = [
            'type' => 'fixture_card',
            'title' => '',
            'props' => [
                'dynamic_source' => 'page_children',
                'dynamic_page_id' => 'target-page-uuid',
                'dynamic_parent_id' => 42, // leftover from sulu_categories
                'dynamic_mapping' => [
                    ['type' => 'field_map', 'target_field' => 'title', 'source_field' => 'title'],
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($block);
        self::assertCount(1, $result);
        self::assertSame('Child Page 1', $result[0]['title']);
    }

    public function testRepeaterBlockClonesReceiveUniqueIds(): void
    {
        $sourceResolver = $this->createStub(PageSourceResolverInterface::class);
        $sourceResolver->method('resolveChildren')->willReturn([
            ['title' => 'First Card'],
            ['title' => 'Second Card'],
            ['title' => 'Third Card'],
        ]);

        $hydrator = new DynamicContentHydrator($sourceResolver);

        $block = [
            'type' => 'fixture_card',
            'id' => 'root-card',
            'title' => 'Template Card',
            'props' => [
                'id' => 'feature-card',
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'page_children',
                    'parent_id' => 'parent-uuid',
                    'mapping' => [
                        'title' => 'title',
                    ],
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($block);

        self::assertCount(3, $result);
        self::assertIsArray($result[0]['props']);
        self::assertIsArray($result[1]['props']);
        self::assertIsArray($result[2]['props']);

        self::assertSame('feature-card', $result[0]['props']['id']);
        self::assertSame('feature-card-2', $result[1]['props']['id']);
        self::assertSame('feature-card-3', $result[2]['props']['id']);

        self::assertSame('root-card', $result[0]['id']);
        self::assertSame('root-card-2', $result[1]['id']);
        self::assertSame('root-card-3', $result[2]['id']);
    }

    public function testMultiItemBlockExpandsMarkedTemplateItemInPlaceAndPreservesStaticItemsBeforeAndAfter(): void
    {
        $sourceResolver = $this->createMock(PageSourceResolverInterface::class);
        $sourceResolver->expects(self::once())
            ->method('resolveChildren')
            ->with('services-root', null, 10)
            ->willReturn([
                ['title' => 'Dynamic Service 1', 'summary' => 'Dynamic Summary 1', 'url' => '/services/1'],
                ['title' => 'Dynamic Service 2', 'summary' => 'Dynamic Summary 2', 'url' => '/services/2'],
            ]);

        $hydrator = new DynamicContentHydrator($sourceResolver);

        $gridBlock = [
            'type' => 'fixture_grid',
            'items' => [
                [
                    'type' => 'fixture_card',
                    'title' => 'Static Intro Card 1',
                    'summary' => 'Fixed intro description 1',
                    'is_dynamic_template' => false,
                ],
                [
                    'type' => 'fixture_card',
                    'title' => 'Static Intro Card 2',
                    'summary' => 'Fixed intro description 2',
                ],
                [
                    'type' => 'fixture_card',
                    'title' => 'Template Card Placeholder',
                    'button_text' => 'Explore Service',
                    'is_dynamic_template' => true,
                    'props' => [
                        'panel_style' => 'bordered',
                    ],
                ],
                [
                    'type' => 'fixture_card',
                    'title' => 'Static Outro CTA Card',
                    'summary' => 'View all services',
                    'is_dynamic_template' => false,
                ],
            ],
            'props' => [
                'columns' => '3',
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'page_children',
                    'parent_id' => 'services-root',
                    'limit' => 10,
                    'mapping' => [
                        'title' => 'title',
                        'summary' => 'summary',
                        'link' => 'url',
                    ],
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($gridBlock);

        self::assertCount(1, $result);
        self::assertIsArray($result[0]['items']);
        /** @var list<array<string, mixed>> $items */
        $items = $result[0]['items'];
        self::assertCount(5, $items);

        // 1 & 2: Static items before template
        self::assertSame('Static Intro Card 1', $items[0]['title']);
        self::assertSame('Fixed intro description 1', $items[0]['summary']);
        self::assertSame('Static Intro Card 2', $items[1]['title']);
        self::assertSame('Fixed intro description 2', $items[1]['summary']);

        // 3 & 4: Hydrated items expanded in-place from the marked template item
        self::assertSame('Dynamic Service 1', $items[2]['title']);
        self::assertSame('Dynamic Summary 1', $items[2]['summary']);
        self::assertSame('/services/1', $items[2]['link']);
        self::assertSame('Explore Service', $items[2]['button_text']);
        self::assertFalse($items[2]['is_dynamic_template'] ?? false);
        self::assertIsArray($items[2]['props']);
        self::assertSame('bordered', $items[2]['props']['panel_style']);

        self::assertSame('Dynamic Service 2', $items[3]['title']);
        self::assertSame('Dynamic Summary 2', $items[3]['summary']);
        self::assertSame('/services/2', $items[3]['link']);
        self::assertSame('Explore Service', $items[3]['button_text']);
        self::assertFalse($items[3]['is_dynamic_template'] ?? false);
        self::assertIsArray($items[3]['props']);
        self::assertSame('bordered', $items[3]['props']['panel_style']);

        // 5: Static item after template
        self::assertSame('Static Outro CTA Card', $items[4]['title']);
        self::assertSame('View all services', $items[4]['summary']);
    }

    public function testMultiItemBlockWithStaticAndTemplateItemsPreservesStaticItemsWhenDynamicSourceReturnsEmpty(): void
    {
        $sourceResolver = $this->createMock(PageSourceResolverInterface::class);
        $sourceResolver->expects(self::once())
            ->method('resolveChildren')
            ->with('empty-root', null, 10)
            ->willReturn([]);

        $hydrator = new DynamicContentHydrator($sourceResolver);

        $gridBlock = [
            'type' => 'fixture_grid',
            'items' => [
                [
                    'type' => 'fixture_card',
                    'title' => 'StaticBefore',
                    'is_dynamic_template' => false,
                ],
                [
                    'type' => 'fixture_card',
                    'title' => 'TemplatePlaceholder',
                    'is_dynamic_template' => true,
                ],
                [
                    'type' => 'fixture_card',
                    'title' => 'StaticAfter',
                ],
            ],
            'props' => [
                '_dynamic' => [
                    'enabled' => true,
                    'source_type' => 'page_children',
                    'parent_id' => 'empty-root',
                    'limit' => 10,
                    'hide_if_empty' => false,
                    'mapping' => [
                        'title' => 'title',
                    ],
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($gridBlock);

        self::assertCount(1, $result);
        self::assertIsArray($result[0]['items']);
        /** @var list<array<string, mixed>> $items */
        $items = $result[0]['items'];
        self::assertCount(2, $items);
        self::assertSame('StaticBefore', $items[0]['title']);
        self::assertSame('StaticAfter', $items[1]['title']);
    }

    public function testProviderCustomOutputsSubBlockCollectionMappingAndTokenInterpolation(): void
    {
        $charterProvider = new class extends \Totoglu\SuluBlockSuiteBundle\DynamicContent\AbstractDynamicContentSourceProvider {
            public function getKey(): string
            {
                return 'charter_yachts';
            }

            public function getLabel(string $locale = 'en'): string
            {
                return 'Charter Yachts';
            }

            public function getSourceFields(string $locale = 'en'): array
            {
                return [
                    ['name' => 'title', 'label' => 'Yacht Name', 'type' => 'string'],
                    ['name' => 'destination', 'label' => 'Destination', 'type' => 'string'],
                    ['name' => 'formatted_length', 'label' => 'Length', 'type' => 'string'],
                    ['name' => 'built_year', 'label' => 'Built Year', 'type' => 'int'],
                    ['name' => 'guests', 'label' => 'Guests', 'type' => 'int'],
                    ['name' => 'cabins', 'label' => 'Cabins', 'type' => 'int'],
                    ['name' => 'price', 'label' => 'Weekly Rate', 'type' => 'string'],
                    ['name' => 'url', 'label' => 'Detail URL', 'type' => 'url'],
                ];
            }

            protected function getCustomOutputs(string $locale = 'en'): array
            {
                return [
                    'short_spec' => [
                        'label' => 'Short Specifications',
                        'type' => 'string',
                        'resolve' => static function (array $item): string {
                            $parts = [];
                            if (isset($item['formatted_length']) && \is_scalar($item['formatted_length']) && '' !== (string) $item['formatted_length']) {
                                $parts[] = (string) $item['formatted_length'];
                            }
                            if (isset($item['built_year']) && \is_scalar($item['built_year']) && '' !== (string) $item['built_year']) {
                                $parts[] = (string) $item['built_year'];
                            }
                            if (isset($item['guests']) && \is_scalar($item['guests']) && '' !== (string) $item['guests']) {
                                $parts[] = $item['guests'] . ' guests';
                            }
                            if (isset($item['cabins']) && \is_scalar($item['cabins']) && '' !== (string) $item['cabins']) {
                                $parts[] = $item['cabins'] . ' cabins';
                            }

                            return \implode(' | ', $parts);
                        },
                    ],
                    'overlay_badges' => [
                        'label' => 'Overlay Badges',
                        'type' => 'collection',
                        'resolve' => static fn (array $item): array => isset($item['raw_badges']) && \is_array($item['raw_badges']) ? $item['raw_badges'] : [],
                    ],
                    'spec_elements' => [
                        'label' => 'Spec Elements',
                        'type' => 'collection',
                        'resolve' => static fn (array $item): array => [
                            [
                                'type' => 'badge',
                                'title' => 'Destination: ' . (isset($item['destination']) && \is_scalar($item['destination']) ? (string) $item['destination'] : ''),
                                'icon' => 'lucide-map-pin',
                            ],
                            [
                                'type' => 'badge',
                                'title' => 'Guests: ' . (isset($item['guests']) && \is_scalar($item['guests']) ? (string) $item['guests'] : ''),
                                'icon' => 'lucide-users',
                            ],
                        ],
                    ],
                ];
            }

            public function resolveCollection(array $config, string|object|array|null $contextPage = null, ?string $locale = null): array
            {
                $rawItems = [
                    [
                        'title' => 'CORAL OCEAN',
                        'destination' => 'Italy, France, Greece',
                        'formatted_length' => '73-m',
                        'built_year' => 2022,
                        'guests' => 12,
                        'cabins' => 7,
                        'price' => 'From € 495.000 per week',
                        'url' => '/charter/coral-ocean',
                        'raw_badges' => ['LUXURY MOTOR SAILER', 'BASED IN GREECE'],
                    ],
                    [
                        'title' => 'DAIMA',
                        'destination' => 'Greece',
                        'formatted_length' => '42-m',
                        'built_year' => null,
                        'guests' => 10,
                        'cabins' => 5,
                        'price' => '€ 78,000 - € 88,000',
                        'url' => '/charter/daima',
                        'raw_badges' => [
                            ['type' => 'badge', 'title' => 'WITH WOW WATER SPORTS'],
                        ],
                    ],
                ];

                return $this->applyCustomOutputsToCollection($rawItems, $locale ?? 'en');
            }
        };

        $resolvedFields = $charterProvider->resolveSourceFields('en');
        $fieldNames = \array_column($resolvedFields, 'name');
        self::assertContains('short_spec', $fieldNames);
        self::assertContains('overlay_badges', $fieldNames);
        self::assertContains('spec_elements', $fieldNames);

        $hydrator = new DynamicContentHydrator(null, [$charterProvider]);

        $gridBlock = [
            'type' => 'fixture_grid',
            'items' => [
                [
                    'type' => 'card',
                    'is_dynamic_template' => true,
                    'title' => '{{title}}',
                    'subtitle' => '{{destination}}',
                    'meta' => '{{formatted_length}} | {{built_year}} | {{guests}} guests | {{cabins}} cabins',
                    'content' => '<p>{{price}}</p>',
                    'buttons' => [
                        ['type' => 'button', 'title' => 'Inquire {{title}}', 'url' => '{{url}}'],
                    ],
                ],
            ],
            'props' => [
                'dynamic_source' => 'charter_yachts',
                'dynamic_mapping' => [
                    ['type' => 'field_map', 'target_field' => 'badges', 'source_field' => 'overlay_badges'],
                    ['type' => 'field_map', 'target_field' => 'elements', 'source_field' => 'spec_elements'],
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($gridBlock);
        self::assertCount(1, $result);
        /** @var list<array<string, mixed>> $items */
        $items = $result[0]['items'];
        self::assertCount(2, $items);

        // First yacht: all tokens present + string badges coerced to badge objects + spec_elements mapped + button interpolated
        self::assertSame('CORAL OCEAN', $items[0]['title']);
        self::assertSame('Italy, France, Greece', $items[0]['subtitle']);
        self::assertSame('73-m | 2022 | 12 guests | 7 cabins', $items[0]['meta']);
        self::assertSame('<p>From € 495.000 per week</p>', $items[0]['content']);
        self::assertSame([
            ['type' => 'badge', 'title' => 'LUXURY MOTOR SAILER'],
            ['type' => 'badge', 'title' => 'BASED IN GREECE'],
        ], $items[0]['badges']);
        self::assertSame([
            ['type' => 'badge', 'title' => 'Destination: Italy, France, Greece', 'icon' => 'lucide-map-pin'],
            ['type' => 'badge', 'title' => 'Guests: 12', 'icon' => 'lucide-users'],
        ], $items[0]['elements']);
        /** @var list<array<string, mixed>> $buttons */
        $buttons = $items[0]['buttons'];
        self::assertSame('Inquire CORAL OCEAN', $buttons[0]['title']);
        self::assertSame('/charter/coral-ocean', $buttons[0]['url']);

        // Second yacht: built_year is null -> dangling separator around empty token is cleaned up!
        self::assertSame('DAIMA', $items[1]['title']);
        self::assertSame('42-m | 10 guests | 5 cabins', $items[1]['meta']);
    }

    public function testDynamicSummaryAndEmptyStateSupportWithCollectionMeta(): void
    {
        $filteredItems = [
            ['title' => 'Yacht 1', 'url' => '/y-1'],
            ['title' => 'Yacht 2', 'url' => '/y-2'],
        ];
        $isZeroResultMode = false;

        $searchProvider = new class($filteredItems, $isZeroResultMode) extends \Totoglu\SuluBlockSuiteBundle\DynamicContent\AbstractDynamicContentSourceProvider {
            /**
             * @param list<array<string, mixed>> $items
             */
            public function __construct(
                private array $items,
                public bool &$zeroResultMode,
            ) {
            }

            public function getKey(): string
            {
                return 'charter_search';
            }

            public function getLabel(string $locale = 'en'): string
            {
                return 'Charter Search';
            }

            public function getSourceFields(string $locale = 'en'): array
            {
                return [
                    ['name' => 'title', 'label' => 'Title', 'type' => 'string'],
                ];
            }

            public function resolveCollection(array $config, string|object|array|null $contextPage = null, ?string $locale = null): array
            {
                return $this->zeroResultMode ? [] : $this->items;
            }

            public function resolveCollectionMeta(array $config, string|object|array|null $contextPage = null, ?string $locale = null): array
            {
                if ($this->zeroResultMode) {
                    return [
                        'unfiltered_total' => 500,
                        'filtered_total' => 0,
                        'is_filtered' => true,
                    ];
                }

                return [
                    'unfiltered_total' => 500,
                    'filtered_total' => 200,
                    'is_filtered' => true,
                ];
            }
        };

        $hydrator = new DynamicContentHydrator(null, [$searchProvider]);

        $gridBlock = [
            'type' => 'fixture_grid',
            'title' => 'Available Yachts (%count% of %total%)',
            'description' => 'Showing %showing% yachts on this page',
            'items' => [
                [
                    'type' => 'card',
                    'is_dynamic_template' => true,
                    'title' => '{{title}}',
                ],
            ],
            'props' => [
                'dynamic_source' => 'charter_search',
                'dynamic_show_summary' => 'top',
                'dynamic_summary_text' => 'Showing %showing% of %total% yachts',
                'dynamic_filtered_summary_text' => 'Found %count% yachts matching your criteria (showing %showing% of %total%)',
                'dynamic_empty_message' => 'No yachts found matching your search criteria.',
                'dynamic_hide_if_empty' => false,
            ],
        ];

        $result = $hydrator->hydrateBlock($gridBlock);
        self::assertCount(1, $result);
        $hydrated = $result[0];

        self::assertSame('Available Yachts (200 of 500)', $hydrated['title']);
        self::assertSame('Showing 2 yachts on this page', $hydrated['description']);
        self::assertIsArray($hydrated['_summary'] ?? null);
        self::assertSame(500, $hydrated['_summary']['unfiltered_total']);
        self::assertSame(200, $hydrated['_summary']['total_items']);
        self::assertSame(2, $hydrated['_summary']['showing_count']);
        self::assertTrue($hydrated['_summary']['is_filtered']);
        self::assertSame('top', $hydrated['_summary']['position']);
        self::assertSame(
            'Found 200 yachts matching your criteria (showing 2 of 500)',
            $hydrated['_summary']['formatted_summary'],
        );

        // Now switch to 0 results: block must NOT disappear, must populate _empty_state
        $isZeroResultMode = true;
        $emptyResult = $hydrator->hydrateBlock($gridBlock);
        self::assertCount(1, $emptyResult);
        $emptyHydrated = $emptyResult[0];
        self::assertSame([], $emptyHydrated['items']);
        self::assertSame('Available Yachts (0 of 500)', $emptyHydrated['title']);
        self::assertIsArray($emptyHydrated['_empty_state'] ?? null);
        self::assertSame('No yachts found matching your search criteria.', $emptyHydrated['_empty_state']['message']);
        self::assertTrue($emptyHydrated['_empty_state']['is_filtered']);
    }

    public function testRegisterCustomOutputOverridesAndAddsOutputsWithoutExtendingProvider(): void
    {
        $sourceResolver = $this->createStub(PageSourceResolverInterface::class);
        $sourceResolver->method('resolveQuery')->willReturn([
            [
                'title' => 'BLUE DREAM',
                'length' => 54,
                'guests' => 12,
                'cabins' => 6,
            ],
        ]);

        $pageProvider = new \Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider\SuluPageSourceProvider($sourceResolver);
        $pageProvider
            ->registerCustomOutput(
                name: 'short_spec',
                resolve: static fn (array $item): string => \sprintf(
                    '%dm • %d Guests • %d Cabins',
                    \is_numeric($item['length'] ?? null) ? (int) $item['length'] : 0,
                    \is_numeric($item['guests'] ?? null) ? (int) $item['guests'] : 0,
                    \is_numeric($item['cabins'] ?? null) ? (int) $item['cabins'] : 0,
                ),
                label: 'Custom Spec Output',
                type: 'string',
            )
            ->registerCustomOutput(
                name: 'vip_badges',
                resolve: static fn (array $item): array => ((\is_numeric($item['length'] ?? null) ? (int) $item['length'] : 0) >= 50)
                    ? ['MEGA YACHT']
                    : [],
                label: 'VIP Badges',
                type: 'collection',
            );

        // Override `short_spec` dynamically from project configuration
        $pageProvider->registerCustomOutput(
            name: 'short_spec',
            resolve: static fn (array $item): string => \sprintf(
                'PROJECT OVERRIDE: %dm / %d Pax',
                \is_numeric($item['length'] ?? null) ? (int) $item['length'] : 0,
                \is_numeric($item['guests'] ?? null) ? (int) $item['guests'] : 0,
            ),
            label: 'Project Spec Override',
            type: 'string',
        );

        $fields = $pageProvider->resolveSourceFields('en');
        $fieldsByName = \array_column($fields, null, 'name');
        self::assertArrayHasKey('short_spec', $fieldsByName);
        self::assertSame('Project Spec Override', $fieldsByName['short_spec']['label']);
        self::assertArrayHasKey('vip_badges', $fieldsByName);
        self::assertSame('collection', $fieldsByName['vip_badges']['type']);

        $hydrator = new DynamicContentHydrator($sourceResolver, [$pageProvider]);

        $gridBlock = [
            'type' => 'fixture_grid',
            'items' => [
                [
                    'type' => 'card',
                    'is_dynamic_template' => true,
                    'title' => '{{title}}',
                    'meta' => '{{short_spec}}',
                ],
            ],
            'props' => [
                'dynamic_source' => 'page_children',
                'dynamic_page_id' => 'root-uuid',
                'dynamic_mapping' => [
                    ['type' => 'field_map', 'target_field' => 'badges', 'source_field' => 'vip_badges'],
                ],
            ],
        ];

        $result = $hydrator->hydrateBlock($gridBlock);
        self::assertCount(1, $result);
        /** @var list<array<string, mixed>> $items */
        $items = $result[0]['items'];
        self::assertCount(1, $items);
        self::assertSame('BLUE DREAM', $items[0]['title']);
        self::assertSame('PROJECT OVERRIDE: 54m / 12 Pax', $items[0]['meta']);
        self::assertSame([
            ['type' => 'badge', 'title' => 'MEGA YACHT'],
        ], $items[0]['badges']);
    }
}
