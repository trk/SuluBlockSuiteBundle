<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent;

use Sulu\Content\Domain\Model\DimensionContentInterface;

/**
 * Normalizes Dynamic Content query criteria into Sulu SmartContent bridge parameters
 * and Doctrine repository filter/sort/pagination plans for both Pages and Articles.
 */
final class DynamicContentQueryFilterNormalizer
{
    private const ALLOWED_SORT_FIELDS = [
        'id',
        'uuid',
        'title',
        'authored',
        'workflowPublished',
        'created',
        'changed',
    ];

    /**
     * @return list<string>
     */
    public function normalizeStringList(mixed $raw): array
    {
        if (\is_string($raw)) {
            return \array_values(\array_filter(
                \array_map('trim', \explode(',', $raw)),
                static fn (string $v): bool => '' !== $v,
            ));
        }

        if (\is_array($raw)) {
            $list = [];
            foreach ($raw as $item) {
                if (\is_string($item) && '' !== \trim($item)) {
                    $list[] = \trim($item);
                }
            }

            return $list;
        }

        return [];
    }

    /**
     * @return list<int>
     */
    public function normalizeIntList(mixed $raw): array
    {
        if (\is_int($raw)) {
            return [$raw];
        }

        if (\is_string($raw)) {
            $raw = \explode(',', $raw);
        }

        if (\is_array($raw) && isset($raw['ids']) && \is_array($raw['ids'])) {
            $raw = $raw['ids'];
        }

        if (\is_array($raw)) {
            $list = [];
            foreach ($raw as $item) {
                if (\is_numeric($item)) {
                    $list[] = (int) $item;
                }
            }

            return $list;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function hasStringCategoryKeys(array $criteria): bool
    {
        $rawCategories = $criteria['category_keys'] ?? null;

        return [] === $this->normalizeIntList($criteria['category_ids'] ?? $rawCategories)
            && [] !== $this->normalizeStringList($rawCategories);
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return array{
     *     bridgeQuery: array<string, mixed>,
     *     excludedIds: list<string>,
     *     limit: int,
     * }
     */
    public function buildSmartContentQuery(array $criteria): array
    {
        $excludedIds = $this->normalizeStringList($criteria['exclude_ids'] ?? null);
        $rawLimit = isset($criteria['limit']) && \is_numeric($criteria['limit']) ? (int) $criteria['limit'] : 10;
        $limit = \max(1, \min(100, $rawLimit));

        $bridgeQuery = $criteria;
        if ([] !== $excludedIds) {
            $bridgeQuery['limit'] = \min(100, $limit + \count($excludedIds));
        }

        return [
            'bridgeQuery' => $bridgeQuery,
            'excludedIds' => $excludedIds,
            'limit' => $limit,
        ];
    }

    /**
     * @param list<string> $flatIds
     * @param list<string> $excludedIds
     *
     * @return list<string>
     */
    public function sliceSmartContentIds(array $flatIds, array $excludedIds, int $limit): array
    {
        return [] !== $excludedIds
            ? \array_slice(\array_values(\array_diff($flatIds, $excludedIds)), 0, $limit)
            : \array_slice($flatIds, 0, $limit);
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $baseFilters
     *
     * @return array{
     *     filters: array<string, mixed>,
     *     sortBy: array<string, 'asc'|'desc'>,
     *     limit: int,
     *     offset: int,
     *     excludedIds: list<string>,
     * }
     */
    public function buildRepositoryQueryPlan(array $criteria, string $locale, array $baseFilters = []): array
    {
        /** @var array<string, mixed> $filters */
        $filters = \array_merge([
            'locale' => $locale,
            'stage' => DimensionContentInterface::STAGE_LIVE,
        ], $baseFilters);

        if (!isset($filters['uuids'])) {
            $uuids = $this->normalizeStringList($criteria['uuids'] ?? null);
            if ([] !== $uuids) {
                $filters['uuids'] = $uuids;
            }
        }

        $templateKeys = $this->normalizeStringList($criteria['template_keys'] ?? null);
        if ([] !== $templateKeys) {
            $filters['templateKeys'] = $templateKeys;
        }

        $tagIds = $this->normalizeIntList($criteria['tag_ids'] ?? null);
        $rawTags = $criteria['tag_names'] ?? null;
        if ([] === $tagIds && null !== $rawTags) {
            $tagIds = $this->normalizeIntList($rawTags);
        }
        if ([] !== $tagIds) {
            $filters['tagIds'] = $tagIds;
        } else {
            $tagNames = $this->normalizeStringList($rawTags);
            if ([] !== $tagNames) {
                $filters['tagNames'] = $tagNames;
            }
        }

        $categoryIds = $this->normalizeIntList($criteria['category_ids'] ?? null);
        $rawCategories = $criteria['category_keys'] ?? null;
        if ([] === $categoryIds && null !== $rawCategories) {
            $categoryIds = $this->normalizeIntList($rawCategories);
        }
        if ([] !== $categoryIds) {
            $filters['categoryIds'] = $categoryIds;
        } else {
            $categoryKeys = $this->normalizeStringList($rawCategories);
            if ([] !== $categoryKeys) {
                $filters['categoryKeys'] = $categoryKeys;
            }
        }

        $navigationContexts = $this->normalizeStringList($criteria['navigation_contexts'] ?? null);
        if ([] !== $navigationContexts) {
            $filters['navigationContexts'] = $navigationContexts;
        }

        if (isset($criteria['depth']) && \is_numeric($criteria['depth']) && (int) $criteria['depth'] > 0) {
            $filters['depth'] = (int) $criteria['depth'];
        }

        $offset = isset($criteria['offset']) && \is_numeric($criteria['offset'])
            ? \max(0, \min(500, (int) $criteria['offset']))
            : 0;
        $excludedIds = $this->normalizeStringList($criteria['exclude_ids'] ?? null);

        $rawLimit = isset($criteria['limit']) && \is_numeric($criteria['limit']) ? (int) $criteria['limit'] : 10;
        $limit = \max(1, \min(100, $rawLimit));
        // Fetch extra rows when offset or excluded IDs are applied in memory
        $filters['limit'] = $limit + $offset + \count($excludedIds);

        /** @var array<string, 'asc'|'desc'> $sortBy */
        $sortBy = ['created' => 'desc'];
        if (isset($criteria['sort_by']) && \is_string($criteria['sort_by']) && '' !== \trim($criteria['sort_by'])) {
            $rawOrder = $criteria['sort_order'] ?? 'desc';
            $order = (\is_string($rawOrder) && 'asc' === \strtolower($rawOrder)) ? 'asc' : 'desc';
            $sortField = \trim($criteria['sort_by']);
            if ('published' === $sortField) {
                $sortField = 'workflowPublished';
            }
            if (\in_array($sortField, self::ALLOWED_SORT_FIELDS, true)) {
                $sortBy = [$sortField => $order];
            }
        }

        return [
            'filters' => $filters,
            'sortBy' => $sortBy,
            'limit' => $limit,
            'offset' => $offset,
            'excludedIds' => $excludedIds,
        ];
    }
}
