<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\DynamicContent\Bridge;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\SmartContent\Configuration\Builder;
use Sulu\Bundle\AdminBundle\SmartContent\Configuration\ProviderConfigurationInterface;
use Sulu\Bundle\AdminBundle\SmartContent\SmartContentProviderInterface;
use Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface;
use Sulu\Bundle\TagBundle\Tag\TagManagerInterface;
use Sulu\Content\Application\ResourceLoader\Loader\ResourceLoaderInterface;
use Sulu\Content\Application\ResourceLoader\ResourceLoaderProvider;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\Bridge\SuluSmartContentQueryBridge;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentHydrator;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentSourceRegistry;

final class SuluSmartContentQueryBridgeTest extends TestCase
{
    public function testQueryFlatIdsNormalizesFiltersAndSortBys(): void
    {
        $provider = new class implements SmartContentProviderInterface {
            /** @var array<string, mixed>|null */
            public ?array $capturedFilters = null;

            /** @var array<string, string>|null */
            public ?array $capturedSortBys = null;

            public function getConfiguration(): ProviderConfigurationInterface
            {
                return Builder::create()
                    ->enableTags()
                    ->enableCategories()
                    ->enableTypes([['type' => 'default', 'title' => 'Default']])
                    ->enableLimit()
                    ->enableSorting([
                        ['column' => 'published', 'title' => 'sulu_admin.published'],
                        ['column' => 'title', 'title' => 'sulu_admin.title'],
                    ])
                    ->getConfiguration();
            }

            public function countBy(array $filters, array $params = []): int
            {
                return 2;
            }

            public function findFlatBy(array $filters, array $sortBys, array $params = []): array
            {
                $this->capturedFilters = $filters;
                $this->capturedSortBys = $sortBys;

                return [
                    ['id' => 'uuid-1', 'title' => 'First'],
                    ['id' => 'uuid-2', 'title' => 'Second'],
                ];
            }

            public function getType(): string
            {
                return 'articles';
            }

            public function getResourceLoaderKey(): string
            {
                return 'article';
            }
        };

        $tagManager = $this->createMock(TagManagerInterface::class);
        $tagManager->method('findByName')->with('featured')->willReturn(new class {
            public function getId(): int
            {
                return 42;
            }
        });

        $bridge = new SuluSmartContentQueryBridge(
            smartContentProviders: ['articles' => $provider],
            tagManager: $tagManager,
        );

        $ids = $bridge->queryFlatIds('articles', [
            'category_ids' => [10, 20],
            'tag_names' => ['featured'],
            'template_keys' => 'default, news',
            'sort_by' => 'published',
            'sort_order' => 'desc',
            'limit' => 5,
            'offset' => 2,
        ], 'tr');

        self::assertSame(['uuid-1', 'uuid-2'], $ids);
        self::assertIsArray($provider->capturedFilters);
        self::assertSame('tr', $provider->capturedFilters['locale']);
        self::assertSame([10, 20], $provider->capturedFilters['categories']);
        self::assertSame([42], $provider->capturedFilters['tags']);
        self::assertSame(['default', 'news'], $provider->capturedFilters['types']);
        self::assertSame(5, $provider->capturedFilters['limit']);
        self::assertSame(2, $provider->capturedFilters['offset']);
        self::assertSame(['published' => 'desc'], $provider->capturedSortBys);
    }

    public function testLoadOrderedResourcesPreservesOrderAndRegistersCacheReferences(): void
    {
        $provider = new class implements SmartContentProviderInterface {
            public function getConfiguration(): ProviderConfigurationInterface
            {
                return Builder::create()->enableLimit()->getConfiguration();
            }

            public function countBy(array $filters, array $params = []): int
            {
                return 2;
            }

            public function findFlatBy(array $filters, array $sortBys, array $params = []): array
            {
                return [
                    ['id' => 'b-id', 'title' => 'Snippet B'],
                    ['id' => 'a-id', 'title' => 'Snippet A'],
                ];
            }

            public function getType(): string
            {
                return 'snippets';
            }

            public function getResourceLoaderKey(): string
            {
                return 'snippet';
            }
        };

        $loader = new class implements ResourceLoaderInterface {
            public function load(array $ids, ?string $locale, array $params = []): array
            {
                return [
                    'a-id' => [
                        'id' => 'a-id',
                        'title' => 'Snippet A',
                        'description' => 'First snippet',
                    ],
                    'b-id' => [
                        'id' => 'b-id',
                        'title' => 'Snippet B',
                        'description' => 'Second snippet',
                    ],
                ];
            }

            public static function getKey(): string
            {
                return 'snippet';
            }
        };

        $resourceLoaderProvider = new ResourceLoaderProvider(['snippet' => $loader]);
        $referenceStore = $this->createMock(ReferenceStoreInterface::class);
        $referenceStore->expects(self::exactly(2))->method('add');

        $bridge = new SuluSmartContentQueryBridge(
            smartContentProviders: ['snippets' => $provider],
            resourceLoaderProvider: $resourceLoaderProvider,
            referenceStore: $referenceStore,
        );

        $ordered = $bridge->loadOrderedResources('snippets', ['b-id', 'a-id'], 'en');
        self::assertCount(2, $ordered);
        self::assertIsArray($ordered[0]);
        self::assertSame('Snippet B', $ordered[0]['title']);
        self::assertIsArray($ordered[1]);
        self::assertSame('Snippet A', $ordered[1]['title']);
    }

    public function testDynamicContentSourceRegistryAutoRegistersSuluSmartContentProviders(): void
    {
        $snippetSmartProvider = new class implements SmartContentProviderInterface {
            public function getConfiguration(): ProviderConfigurationInterface
            {
                return Builder::create()
                    ->enableTags()
                    ->enableTypes([['type' => 'card_snippet', 'title' => 'Card Snippet']])
                    ->enableLimit()
                    ->getConfiguration();
            }

            public function countBy(array $filters, array $params = []): int
            {
                return 1;
            }

            public function findFlatBy(array $filters, array $sortBys, array $params = []): array
            {
                return [['id' => 'snip-1', 'title' => 'Dynamic Snippet Title']];
            }

            public function getType(): string
            {
                return 'snippets';
            }

            public function getResourceLoaderKey(): string
            {
                return 'snippet';
            }
        };

        $loader = new class implements ResourceLoaderInterface {
            public function load(array $ids, ?string $locale, array $params = []): array
            {
                return [
                    'snip-1' => [
                        'id' => 'snip-1',
                        'title' => 'Dynamic Snippet Title',
                        'summary' => 'Loaded via SmartContentProviderInterface bridge',
                        'url' => '/snippets/1',
                    ],
                ];
            }

            public static function getKey(): string
            {
                return 'snippet';
            }
        };

        $bridge = new SuluSmartContentQueryBridge(
            smartContentProviders: ['snippets' => $snippetSmartProvider],
            resourceLoaderProvider: new ResourceLoaderProvider(['snippet' => $loader]),
        );

        $registry = new DynamicContentSourceRegistry(smartContentBridge: $bridge);
        $entry = $registry->getEntry('sulu_snippets');

        self::assertNotNull($entry);
        self::assertTrue($entry['multi_item']);

        $hydrator = new DynamicContentHydrator(smartContentBridge: $bridge);
        $hydrated = $hydrator->hydrateBlock([
            'type' => 'card',
            'title' => 'Fallback',
            'props' => [
                'dynamic_source' => 'sulu_snippets',
                'dynamic_limit' => 3,
            ],
        ]);

        self::assertCount(1, $hydrated);
        self::assertSame('Dynamic Snippet Title', $hydrated[0]['title']);
    }
}
