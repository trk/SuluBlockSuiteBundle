<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\DynamicContent;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentHydrator;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider\SuluSearchSourceProvider;
use Totoglu\SuluBlockSuiteBundle\Renderer\BlockRenderer;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class SearchAndPaginationTest extends TestCase
{
    public function testSearchResultsAndSearchContextResolution(): void
    {
        $provider = new SuluSearchSourceProvider();

        self::assertSame('search_results', $provider->getKey());
        self::assertSame('search_context', $provider->getSingleKey());
        self::assertTrue($provider->isMultiItem());

        $contextPage = [
            'query' => 'Preline',
            'hits' => [
                [
                    'title' => 'Hero Blocks',
                    'content' => ['Preline hero components'],
                    'url' => '/en/hero',
                    'resourceKey' => 'pages',
                ],
            ],
        ];

        $single = $provider->resolveSingle([], $contextPage);
        self::assertSame('Preline', $single['query']);
        self::assertSame(1, $single['total']);
        self::assertSame('1 results', $single['badge']);

        $collection = $provider->resolveCollection([], $contextPage);
        self::assertCount(1, $collection);
        self::assertSame('Hero Blocks', $collection[0]['title']);
        self::assertSame('Preline hero components', $collection[0]['summary']);
        self::assertSame('/en/hero', $collection[0]['url']);
        self::assertSame('PAGES', $collection[0]['badge']);
    }

    public function testPaginationWithLimit25AndPerPage20SplitsInto20And5(): void
    {
        $hits = [];
        for ($i = 1; $i <= 30; ++$i) {
            $hits[] = [
                'title' => 'Result ' . $i,
                'content' => ['Summary for item ' . $i],
                'url' => '/en/result-' . $i,
                'resourceKey' => 'pages',
            ];
        }

        $contextPage = [
            'query' => 'Result',
            'hits' => $hits,
        ];

        $gridBlock = [
            'type' => 'grid',
            'items' => [
                ['type' => 'grid_item'],
            ],
            'props' => [
                'dynamic_source' => 'search_results',
                'dynamic_limit' => 25,
                'dynamic_pagination' => 'numbers',
                'dynamic_per_page' => 20,
                'dynamic_mapping' => [
                    ['type' => 'field_map', 'target_field' => 'title', 'source_field' => 'title'],
                    ['type' => 'field_map', 'target_field' => 'summary', 'source_field' => 'summary'],
                    ['type' => 'field_map', 'target_field' => 'link', 'source_field' => 'url'],
                ],
            ],
        ];

        // Page 1 (?p=1) -> 20 items
        $requestStack = new RequestStack();
        $requestStack->push(new Request(['p' => '1']));
        $hydrator = new DynamicContentHydrator(requestStack: $requestStack);

        $hydratedPage1 = $hydrator->hydrateBlock($gridBlock, $contextPage);
        self::assertCount(1, $hydratedPage1);
        self::assertIsArray($hydratedPage1[0]['items']);
        /** @var list<array<string, mixed>> $page1Items */
        $page1Items = $hydratedPage1[0]['items'];
        self::assertCount(20, $page1Items);
        self::assertSame('Result 1', $page1Items[0]['title']);
        self::assertSame('Result 20', $page1Items[19]['title']);
        self::assertIsArray($hydratedPage1[0]['_pagination'] ?? null);
        self::assertSame(25, $hydratedPage1[0]['_pagination']['total_items']);
        self::assertSame(20, $hydratedPage1[0]['_pagination']['per_page']);
        self::assertSame(2, $hydratedPage1[0]['_pagination']['total_pages']);
        self::assertSame(1, $hydratedPage1[0]['_pagination']['current_page']);
        self::assertSame('numbers', $hydratedPage1[0]['_pagination']['style']);
        self::assertSame('p', $hydratedPage1[0]['_pagination']['param']);

        // Page 2 (?p=2) -> remaining 5 items (items 21..25)
        $requestStackPage2 = new RequestStack();
        $requestStackPage2->push(new Request(['p' => '2']));
        $hydratorPage2 = new DynamicContentHydrator(requestStack: $requestStackPage2);

        $hydratedPage2 = $hydratorPage2->hydrateBlock($gridBlock, $contextPage);
        self::assertCount(1, $hydratedPage2);
        self::assertIsArray($hydratedPage2[0]['items']);
        /** @var list<array<string, mixed>> $page2Items */
        $page2Items = $hydratedPage2[0]['items'];
        self::assertCount(5, $page2Items);
        self::assertSame('Result 21', $page2Items[0]['title']);
        self::assertSame('Result 25', $page2Items[4]['title']);
        self::assertIsArray($hydratedPage2[0]['_pagination'] ?? null);
        self::assertSame(2, $hydratedPage2[0]['_pagination']['current_page']);

        // Out-of-bounds page (?p=99) -> clamped to total_pages (2)
        $requestStackPage99 = new RequestStack();
        $requestStackPage99->push(new Request(['p' => '99']));
        $hydratorPage99 = new DynamicContentHydrator(requestStack: $requestStackPage99);

        $hydratedPage99 = $hydratorPage99->hydrateBlock($gridBlock, $contextPage);
        self::assertCount(1, $hydratedPage99);
        self::assertIsArray($hydratedPage99[0]['_pagination'] ?? null);
        self::assertSame(2, $hydratedPage99[0]['_pagination']['current_page']);
    }

    public function testBlockRendererAppendsPaginationMarkupWhenTotalPagesExceedsOne(): void
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/templates', 'SuluBlockSuite');
        $twig = new Environment($loader);

        $renderer = new BlockRenderer($twig);

        $reusableBlock = [
            'type' => 'reusable',
            'blocks' => [],
            '_pagination' => [
                'style' => 'numbers',
                'current_page' => 1,
                'per_page' => 20,
                'total_items' => 25,
                'total_pages' => 2,
                'param' => 'p',
            ],
        ];

        // reusable with empty blocks returns empty string, so test with an inline template loader
        $arrayLoader = new \Twig\Loader\ArrayLoader([
            '@SuluBlockSuite/blocks/fixture_grid.html.twig' => '<div class="grid-items">GRID</div>',
            '@SuluBlockSuite/blocks/_pagination.html.twig' => (string) \file_get_contents(\dirname(__DIR__, 2) . '/templates/blocks/_pagination.html.twig'),
        ]);
        $twigWithGrid = new Environment($arrayLoader);
        $rendererWithGrid = new BlockRenderer($twigWithGrid);

        $html = $rendererWithGrid->renderBlock([
            'type' => 'fixture_grid',
            '_pagination' => [
                'style' => 'numbers',
                'current_page' => 1,
                'per_page' => 20,
                'total_items' => 25,
                'total_pages' => 2,
                'param' => 'p',
            ],
        ]);

        self::assertStringContainsString('<div class="grid-items">GRID</div>', $html);
        self::assertStringContainsString('<nav aria-label="Pagination"', $html);
        self::assertStringContainsString('?p=2', $html);
    }

    public function testRepeaterPaginationAttachesMetadataToLastItemAndVisitorWiresVisibleConditions(): void
    {
        $hits = [];
        for ($i = 1; $i <= 5; ++$i) {
            $hits[] = [
                'title' => 'Card ' . $i,
                'content' => ['Summary ' . $i],
                'url' => '/en/card-' . $i,
                'resourceKey' => 'articles',
            ];
        }

        $requestStack = new RequestStack();
        $requestStack->push(new Request(['p' => '1']));
        $hydrator = new DynamicContentHydrator(requestStack: $requestStack);

        $repeaterCard = [
            'type' => 'fixture_card',
            'props' => [
                'dynamic_source' => 'search_results',
                'dynamic_pagination' => 'prev_next',
                'dynamic_per_page' => 2,
                'dynamic_mapping' => [
                    ['type' => 'field_map', 'target_field' => 'title', 'source_field' => 'title'],
                ],
            ],
        ];

        $hydrated = $hydrator->hydrateBlock($repeaterCard, ['query' => 'Card', 'hits' => $hits]);
        self::assertCount(2, $hydrated);
        self::assertArrayNotHasKey('_pagination', $hydrated[0]);
        self::assertIsArray($hydrated[1]['_pagination'] ?? null);
        self::assertSame('prev_next', $hydrated[1]['_pagination']['style']);
        self::assertSame(5, $hydrated[1]['_pagination']['total_items']);
        self::assertSame(3, $hydrated[1]['_pagination']['total_pages']);

        $visitor = new \Totoglu\SuluBlockSuiteBundle\Metadata\BlockSuiteFormMetadataVisitor(dynamicContentHydrator: $hydrator);
        $blockForm = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata();
        $blockForm->setKey('fixture_grid');
        $contentSection = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata('content');
        $itemsField = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata('items');
        $itemsField->setType('block');
        $contentSection->addItem($itemsField);
        $blockForm->addItem($contentSection);

        $typedForm = new \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata();
        $typedForm->addForm('fixture_grid', $blockForm);
        $visitor->visitTypedFormMetadata($typedForm, 'block', 'en');

        /** @var \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata $tabs */
        $tabs = $blockForm->getItems()['__tabs'];
        /** @var \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata $dynSection */
        $dynSection = $tabs->getItems()['dynamic_content'];
        $dynItems = $dynSection->getItems();

        self::assertArrayHasKey('props/dynamic_pagination', $dynItems);
        self::assertArrayHasKey('props/dynamic_per_page', $dynItems);
        /** @var \Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata $perPageMeta */
        $perPageMeta = $dynItems['props/dynamic_per_page'];
        self::assertStringContainsString('search_results', (string) $perPageMeta->getVisibleCondition());
        self::assertStringContainsString("__parent.dynamic_pagination == 'numbers'", (string) $perPageMeta->getVisibleCondition());
    }
}
