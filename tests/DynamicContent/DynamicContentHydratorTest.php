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
            'type' => 'card',
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
            'type' => 'headline',
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
            'type' => 'card',
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
            'type' => 'card',
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
            'type' => 'image',
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
            'type' => 'image',
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
            'type' => 'headline',
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
            'type' => 'card',
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
            'type' => 'headline',
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
            'type' => 'card',
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
            ['type' => 'headline', 'title' => 'Top Static'],
            [
                'type' => 'card',
                'props' => [
                    '_dynamic' => [
                        'enabled' => true,
                        'source_type' => 'page_children',
                        'parent_id' => $parentId,
                        'mapping' => ['title' => 'title'],
                    ],
                ],
            ],
            ['type' => 'divider'],
        ];

        $flattened = $hydrator->hydrateBlocks($blocks);

        self::assertCount(4, $flattened);
        self::assertSame('headline', $flattened[0]['type']);
        self::assertSame('Top Static', $flattened[0]['title']);
        self::assertSame('card', $flattened[1]['type']);
        self::assertSame('Child 1', $flattened[1]['title']);
        self::assertSame('card', $flattened[2]['type']);
        self::assertSame('Child 2', $flattened[2]['title']);
        self::assertSame('divider', $flattened[3]['type']);
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
            'type' => 'card',
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
            'type' => 'headline',
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
            'type' => 'headline',
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
            'type' => 'card',
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
            'type' => 'grid',
            'items' => [
                ['type' => 'grid_item', 'title' => 'Placeholder', 'button_text' => 'Read More'],
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
        self::assertSame('grid', $result[0]['type']);
        self::assertIsArray($result[0]['items']);
        /** @var list<array<string, mixed>> $items */
        $items = $result[0]['items'];
        self::assertCount(2, $items);

        self::assertSame('grid_item', $items[0]['type']);
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
            'type' => 'accordion',
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
        self::assertSame('accordion', $result[0]['type']);
        self::assertIsArray($result[0]['items']);
        /** @var list<array<string, mixed>> $items */
        $items = $result[0]['items'];
        self::assertCount(2, $items);

        self::assertSame('accordion_item', $items[0]['type']);
        self::assertSame('What is Sulu 3?', $items[0]['title']);
        self::assertSame('Modern Symfony CMS', $items[0]['content']);

        self::assertSame('accordion_item', $items[1]['type']);
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
            'type' => 'card',
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
            'type' => 'card',
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
            'type' => 'card',
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
            'type' => 'card',
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

        $breadcrumbsForm = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata();
        $breadcrumbsForm->setKey('breadcrumbs');

        $childItemForm = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata();
        $childItemForm->setKey('breadcrumb_item');

        $itemsBlockField = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata('items');
        $itemsBlockField->setType('block');
        $itemsBlockField->setDefaultType('breadcrumb_item');
        $itemsBlockField->addType($childItemForm);
        $breadcrumbsForm->addItem($itemsBlockField);

        $typedFormMetadata = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata();
        $typedFormMetadata->addForm('breadcrumbs', $breadcrumbsForm);

        $provider = $this->createStub(\Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface::class);
        $provider->method('getMetadata')->willReturn($typedFormMetadata);

        $registry = new \Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry(
            new \Symfony\Component\DependencyInjection\ServiceLocator([
                'form' => static fn () => $provider,
            ]),
        );

        $targetFieldResolver = new \Totoglu\SuluBlockSuiteBundle\Metadata\BlockTargetFieldMetadataResolver($registry);
        $hydrator = new DynamicContentHydrator($sourceResolver, [], $targetFieldResolver);

        $breadcrumbsBlock = [
            'type' => 'breadcrumbs',
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

        $result = $hydrator->hydrateBlock($breadcrumbsBlock);

        self::assertCount(1, $result);
        self::assertSame('breadcrumbs', $result[0]['type']);
        self::assertIsArray($result[0]['items']);
        /** @var list<array<string, mixed>> $items */
        $items = $result[0]['items'];
        self::assertCount(2, $items);
        self::assertSame('breadcrumb_item', $items[0]['type']);
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

        // 1. Single product mode on a single block (card)
        $singleCard = [
            'type' => 'card',
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

        // 2. Product collection mode on a multiple block (grid)
        $gridBlock = [
            'type' => 'grid',
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
            'type' => 'headline',
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
            'type' => 'headline',
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
            'type' => 'text',
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
            'type' => 'text',
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
            'type' => 'headline',
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
            'type' => 'card',
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
        $hydrator = new DynamicContentHydrator();

        $longHtml = '<p><strong>Sulu 3 Enterprise CMS</strong> delivers structured content management with Preline UI 5 and Tailwind CSS 4 for high-performance digital experiences across every channel and device worldwide.</p>';

        $dataWithEmptyHeadlineAndSummary = [
            'title' => 'Page Navigation Title',
            'headline' => '',
            'summary' => '   ',
            'content' => $longHtml,
        ];

        self::assertSame(
            'Page Navigation Title',
            $hydrator->extractValue($dataWithEmptyHeadlineAndSummary, 'headline|title'),
        );

        $truncated = $hydrator->extractValue($dataWithEmptyHeadlineAndSummary, 'summary|content:length=160');
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
            $hydrator->extractValue($dataWithExplicitHeadlineAndSummary, 'headline|title'),
        );
        self::assertSame(
            'Concise custom summary.',
            $hydrator->extractValue($dataWithExplicitHeadlineAndSummary, 'summary|content:length=160'),
        );
    }

    public function testAutoMappingHydratesCardOverlayAndSlideshowContentFromSummaryOrTruncatedBody(): void
    {
        $longBody = '<p>' . \str_repeat('Modern Sulu 3 architecture with single source of truth content fields. ', 4) . '</p>';

        $sourceResolver = $this->createStub(PageSourceResolverInterface::class);
        $sourceResolver->method('resolvePage')->willReturn([
            'title' => 'Card Page Title',
            'headline' => 'Card Display Headline',
            'summary' => '',
            'content' => $longBody,
            'image' => ['id' => 99, 'url' => '/uploads/card.jpg'],
            'url' => '/solutions/card-page',
        ]);

        $hydrator = new DynamicContentHydrator($sourceResolver);

        foreach (['card', 'overlay', 'slideshow_item'] as $teaserBlockType) {
            $block = [
                'type' => $teaserBlockType,
                'title' => '',
                'content' => '',
                'image' => null,
                'link' => '',
                'props' => [
                    'dynamic_source' => 'specific_page',
                    'dynamic_page_id' => 'page-123',
                    'dynamic_mapping' => [],
                ],
            ];

            $hydratedList = $hydrator->hydrateBlock($block);
            self::assertCount(1, $hydratedList);
            $hydrated = $hydratedList[0];

            self::assertSame('Card Page Title', $hydrated['title']);
            self::assertIsString($hydrated['content']);
            self::assertLessThanOrEqual(160, \mb_strlen($hydrated['content'], 'UTF-8'));
            self::assertStringNotContainsString('<p>', $hydrated['content']);
            self::assertStringEndsWith('...', $hydrated['content']);
            self::assertSame(['id' => 99, 'url' => '/uploads/card.jpg'], $hydrated['image']);
            self::assertSame('/solutions/card-page', $hydrated['link']);
        }
    }
}
