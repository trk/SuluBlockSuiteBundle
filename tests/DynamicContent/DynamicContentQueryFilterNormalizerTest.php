<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\DynamicContent;

use PHPUnit\Framework\TestCase;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentQueryFilterNormalizer;

final class DynamicContentQueryFilterNormalizerTest extends TestCase
{
    private DynamicContentQueryFilterNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new DynamicContentQueryFilterNormalizer();
    }

    public function testNormalizeStringListAndIntList(): void
    {
        self::assertSame(['news', 'blog'], $this->normalizer->normalizeStringList(' news, , blog '));
        self::assertSame(['a', 'b'], $this->normalizer->normalizeStringList([' a ', '', 123, 'b']));
        self::assertSame([], $this->normalizer->normalizeStringList(null));

        self::assertSame([5], $this->normalizer->normalizeIntList(5));
        self::assertSame([1, 2, 3], $this->normalizer->normalizeIntList('1, 2, nope, 3'));
        self::assertSame([4, 8], $this->normalizer->normalizeIntList(['4', 8, 'invalid']));
        self::assertSame([10, 20], $this->normalizer->normalizeIntList(['ids' => [10, '20']]));
        self::assertSame([], $this->normalizer->normalizeIntList(null));
    }

    public function testHasStringCategoryKeysDistinguishesNumericIdsFromKeys(): void
    {
        self::assertTrue($this->normalizer->hasStringCategoryKeys(['category_keys' => 'tech,design']));
        self::assertTrue($this->normalizer->hasStringCategoryKeys(['category_keys' => ['tech']]));
        self::assertFalse($this->normalizer->hasStringCategoryKeys(['category_keys' => '10,20']));
        self::assertFalse($this->normalizer->hasStringCategoryKeys(['category_ids' => [10], 'category_keys' => 'tech']));
        self::assertFalse($this->normalizer->hasStringCategoryKeys([]));
    }

    public function testBuildAndSliceSmartContentQueryAppliesLimitClampingAndExclusions(): void
    {
        $criteria = [
            'limit' => 3,
            'exclude_ids' => 'id-2, id-4',
        ];

        $smartPlan = $this->normalizer->buildSmartContentQuery($criteria);

        self::assertSame(3, $smartPlan['limit']);
        self::assertSame(['id-2', 'id-4'], $smartPlan['excludedIds']);
        self::assertSame(5, $smartPlan['bridgeQuery']['limit']);

        $sliced = $this->normalizer->sliceSmartContentIds(
            ['id-1', 'id-2', 'id-3', 'id-4', 'id-5'],
            $smartPlan['excludedIds'],
            $smartPlan['limit'],
        );

        self::assertSame(['id-1', 'id-3', 'id-5'], $sliced);
    }

    public function testBuildRepositoryQueryPlanNormalizesTaxonomiesTemplatesPaginationAndSort(): void
    {
        $criteria = [
            'template_keys' => 'article_default, news',
            'tag_names' => 'featured, breaking',
            'category_keys' => '12, 15',
            'navigation_contexts' => 'main, footer',
            'depth' => '2',
            'limit' => 5,
            'offset' => 2,
            'exclude_ids' => ['uuid-skip'],
            'sort_by' => 'published',
            'sort_order' => 'ASC',
        ];

        $plan = $this->normalizer->buildRepositoryQueryPlan(
            criteria: $criteria,
            locale: 'tr',
            baseFilters: ['webspaceKey' => 'totoglu'],
        );

        self::assertSame('tr', $plan['filters']['locale']);
        self::assertSame(DimensionContentInterface::STAGE_LIVE, $plan['filters']['stage']);
        self::assertSame('totoglu', $plan['filters']['webspaceKey']);
        self::assertSame(['article_default', 'news'], $plan['filters']['templateKeys']);
        self::assertSame(['featured', 'breaking'], $plan['filters']['tagNames']);
        self::assertArrayNotHasKey('tagIds', $plan['filters']);
        self::assertSame([12, 15], $plan['filters']['categoryIds']);
        self::assertArrayNotHasKey('categoryKeys', $plan['filters']);
        self::assertSame(['main', 'footer'], $plan['filters']['navigationContexts']);
        self::assertSame(2, $plan['filters']['depth']);
        self::assertSame(8, $plan['filters']['limit']); // 5 limit + 2 offset + 1 excluded
        self::assertSame(5, $plan['limit']);
        self::assertSame(2, $plan['offset']);
        self::assertSame(['uuid-skip'], $plan['excludedIds']);
        self::assertSame(['workflowPublished' => 'asc'], $plan['sortBy']);
    }

    public function testBuildRepositoryQueryPlanFallsBackToDefaultSortAndStringCategoryKeys(): void
    {
        $plan = $this->normalizer->buildRepositoryQueryPlan(
            criteria: [
                'tag_ids' => [7, 9],
                'category_keys' => 'announcements, press',
                'sort_by' => 'unsupported_column',
                'limit' => 250,
                'offset' => -10,
            ],
            locale: 'en',
        );

        self::assertSame([7, 9], $plan['filters']['tagIds']);
        self::assertSame(['announcements', 'press'], $plan['filters']['categoryKeys']);
        self::assertSame(['created' => 'desc'], $plan['sortBy']);
        self::assertSame(100, $plan['limit']);
        self::assertSame(0, $plan['offset']);
        self::assertSame(100, $plan['filters']['limit']);
    }
}
