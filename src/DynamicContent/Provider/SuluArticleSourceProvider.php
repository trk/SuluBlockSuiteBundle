<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider;

use Sulu\Article\Domain\Model\ArticleDimensionContentInterface;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface;
use Sulu\Content\Application\ContentAggregator\ContentAggregatorInterface;
use Sulu\Content\Application\ContentResolver\ContentResolverInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\AbstractDynamicContentSourceProvider;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\Bridge\SuluSmartContentQueryBridge;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentQueryFilterNormalizer;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\SuluContentDimensionExtractor;

/**
 * Built-in Dynamic Content source provider for Sulu 3 Articles (Single & Collection).
 */
final class SuluArticleSourceProvider extends AbstractDynamicContentSourceProvider implements ResetInterface
{
    /** @var array<string, array<string, mixed>|null> */
    private array $resolvedEntityCache = [];

    private readonly DynamicContentQueryFilterNormalizer $queryFilterNormalizer;

    public function __construct(
        private readonly ?ArticleRepositoryInterface $articleRepository = null,
        private readonly ?ContentResolverInterface $contentResolver = null,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?ReferenceStoreInterface $referenceStore = null,
        private readonly ?ContentAggregatorInterface $contentAggregator = null,
        private readonly ?SuluSmartContentQueryBridge $smartContentBridge = null,
        ?TranslatorInterface $translator = null,
        ?DynamicContentQueryFilterNormalizer $queryFilterNormalizer = null,
    ) {
        $this->translator = $translator;
        $this->queryFilterNormalizer = $queryFilterNormalizer ?? new DynamicContentQueryFilterNormalizer();
    }

    public function reset(): void
    {
        $this->resolvedEntityCache = [];
    }

    public function getKey(): string
    {
        return 'sulu_articles';
    }

    public function getLabel(string $locale = 'en'): string
    {
        return $this->translate('sulu_block_suite.dynamic_source_sulu_articles', $locale, 'Articles');
    }

    public function getSingleKey(): string
    {
        return 'single_article';
    }

    public function getSingleLabel(string $locale = 'en'): string
    {
        return $this->translate('sulu_block_suite.dynamic_source_single_article', $locale, 'Single Article Selection');
    }

    public function getSingleConfigFields(string $locale = 'en'): array
    {
        return [
            $this->createSelectionField(
                name: 'article_id',
                type: 'single_article_selection',
                label: 'sulu_block_suite.dynamic_single_article',
                description: 'sulu_block_suite.dynamic_single_article_desc',
                required: true,
                colSpan: 12,
                triggerSourceFieldsReload: true,
            ),
        ];
    }

    public function getConfigFields(string $locale = 'en'): array
    {
        return [
            $this->createSelectionField(
                name: 'article_ids',
                type: 'article_selection',
                label: 'sulu_block_suite.dynamic_article_selection',
                description: 'sulu_block_suite.dynamic_article_selection_desc',
                required: false,
                colSpan: 12,
            ),
            ...$this->createPaginationAndSortFields(10),
            ...$this->createTaxonomyFilterFields(includeTemplateFilter: true, includeTags: true, includeCategories: true),
        ];
    }

    public function getSourceFields(string $locale = 'en'): array
    {
        return [
            ['name' => 'title', 'label' => $this->translateFieldLabel('title', $locale), 'type' => 'text_line'],
            ['name' => 'headline', 'label' => $this->translateFieldLabel('headline', $locale), 'type' => 'text_line'],
            ['name' => 'summary', 'label' => $this->translateFieldLabel('summary', $locale), 'type' => 'text_area'],
            ['name' => 'description', 'label' => $this->translateFieldLabel('description', $locale), 'type' => 'text_editor'],
            ['name' => 'content', 'label' => $this->translateFieldLabel('content', $locale), 'type' => 'text_editor'],
            ['name' => 'image', 'label' => $this->translateFieldLabel('image', $locale), 'type' => 'single_media_selection'],
            ['name' => 'url', 'label' => $this->translateFieldLabel('url', $locale), 'type' => 'url'],
            ['name' => 'link', 'label' => $this->translateFieldLabel('link', $locale), 'type' => 'link'],
            ['name' => 'authored', 'label' => $this->translateFieldLabel('authored', $locale), 'type' => 'datetime'],
            ['name' => 'badge', 'label' => $this->translateFieldLabel('badge', $locale), 'type' => 'text_line'],
            ['name' => 'template', 'label' => $this->translateFieldLabel('template', $locale), 'type' => 'text_line'],
        ];
    }

    public function resolveSourceFields(string $locale = 'en', array $context = []): array
    {
        $fields = parent::resolveSourceFields($locale, $context);

        $articleId = $context['article_id'] ?? ($context['id'] ?? ($context['uuid'] ?? null));
        if (\is_string($articleId) && '' !== \trim($articleId) && null !== $this->articleRepository) {
            $articleData = $this->resolveSingle(['article_id' => \trim($articleId)], null, $locale);
            if (null !== $articleData) {
                $existingNames = \array_column($fields, 'name');
                foreach ($articleData as $key => $value) {
                    if (\in_array($key, $existingNames, true) || \str_starts_with($key, '_') || 'id' === $key || 'uuid' === $key) {
                        continue;
                    }

                    $type = 'text_line';
                    if (\is_array($value) && isset($value['id'])) {
                        $type = 'single_media_selection';
                    } elseif (\is_array($value)) {
                        $type = 'array';
                    } elseif (\is_bool($value)) {
                        $type = 'boolean';
                    }

                    $fields[] = [
                        'name' => $key,
                        'label' => \ucwords(\str_replace(['_', '-'], ' ', $key)),
                        'type' => $type,
                    ];
                }
            }
        }

        return $fields;
    }

    public function resolveSingle(array $config, string|object|array|null $contextPage = null, ?string $locale = null): ?array
    {
        if (null === $this->articleRepository) {
            return null;
        }

        $uuid = $config['article_id'] ?? ($config['uuid'] ?? ($config['id'] ?? ($config['page_id'] ?? null)));
        if (!\is_string($uuid) || '' === \trim($uuid)) {
            $collection = $this->resolveCollection(\array_merge($config, ['limit' => 1]), $contextPage, $locale);

            return $collection[0] ?? null;
        }

        $trimUuid = \trim($uuid);
        $resolvedLocale = $this->resolveLocale($locale, $this->requestStack);
        $isPreview = $this->isPreviewContext();
        $stageKey = $isPreview ? DimensionContentInterface::STAGE_DRAFT : DimensionContentInterface::STAGE_LIVE;
        $cacheKey = "{$stageKey}:{$resolvedLocale}:{$trimUuid}";

        if (\array_key_exists($cacheKey, $this->resolvedEntityCache)) {
            $cached = $this->resolvedEntityCache[$cacheKey];
            if (null !== $cached) {
                $this->referenceStore?->add($trimUuid, ArticleInterface::RESOURCE_KEY);
            }

            return $cached;
        }

        try {
            $article = $this->articleRepository->findOneBy(
                [
                    'uuid' => $trimUuid,
                    'locale' => $resolvedLocale,
                    'stage' => DimensionContentInterface::STAGE_LIVE,
                ],
                [ArticleRepositoryInterface::GROUP_SELECT_ARTICLE_WEBSITE => true],
            );

            if (null === $article && $isPreview) {
                $article = $this->articleRepository->findOneBy(
                    [
                        'uuid' => $trimUuid,
                        'locale' => $resolvedLocale,
                        'stage' => DimensionContentInterface::STAGE_DRAFT,
                    ],
                    [ArticleRepositoryInterface::GROUP_SELECT_ARTICLE_ADMIN => true],
                );
            }

            if (null === $article) {
                $this->resolvedEntityCache[$cacheKey] = null;

                return null;
            }

            return $this->normalizeArticleEntity($article, $resolvedLocale);
        } catch (\Throwable) {
            return null;
        }
    }

    public function resolveCollection(array $config, string|object|array|null $contextPage = null, ?string $locale = null): array
    {
        $resolvedLocale = $this->resolveLocale($locale, $this->requestStack);
        $query = isset($config['query']) && \is_array($config['query'])
            ? \array_merge($config, $config['query'])
            : $config;

        $uuids = $this->queryFilterNormalizer->normalizeStringList($query['article_ids'] ?? ($query['uuids'] ?? ($query['ids'] ?? null)));
        $hasStringCategoryKeys = $this->queryFilterNormalizer->hasStringCategoryKeys($query);

        if (
            [] === $uuids
            && !$hasStringCategoryKeys
            && null !== $this->smartContentBridge
            && $this->smartContentBridge->hasProvider('articles')
        ) {
            $smartPlan = $this->queryFilterNormalizer->buildSmartContentQuery($query);

            $flatIds = $this->smartContentBridge->queryFlatIds('articles', $smartPlan['bridgeQuery'], $resolvedLocale);
            $filteredIds = $this->queryFilterNormalizer->sliceSmartContentIds(
                $flatIds,
                $smartPlan['excludedIds'],
                $smartPlan['limit'],
            );

            if ([] === $filteredIds) {
                return [];
            }

            $loaded = $this->smartContentBridge->loadOrderedResources('articles', $filteredIds, $resolvedLocale);
            if ([] === $loaded && null !== $this->articleRepository) {
                $loaded = $this->loadArticlesByOrderedUuids($filteredIds, $resolvedLocale);
            }

            $result = [];
            foreach ($loaded as $resource) {
                if ($resource instanceof ArticleInterface) {
                    $normalized = $this->normalizeArticleEntity($resource, $resolvedLocale);
                    if (null !== $normalized) {
                        $result[] = $normalized;
                    }
                }
            }

            return $result;
        }

        if (null === $this->articleRepository) {
            return [];
        }

        $queryPlan = $this->queryFilterNormalizer->buildRepositoryQueryPlan(
            criteria: $query,
            locale: $resolvedLocale,
        );
        $filters = $queryPlan['filters'];
        $sortBy = $queryPlan['sortBy'];
        $limit = $queryPlan['limit'];
        $offset = $queryPlan['offset'];
        $excludedIds = $queryPlan['excludedIds'];

        try {
            /**
             * @var array{
             *     uuid?: string,
             *     uuids?: list<string>,
             *     locale?: string,
             *     stage?: string,
             *     categoryIds?: list<int>,
             *     categoryKeys?: list<string>,
             *     tagIds?: list<int>,
             *     tagNames?: list<string>,
             *     templateKeys?: list<string>,
             *     limit?: int,
             * } $typedFilters
             */
            $typedFilters = $filters;
            /** @var array{id?: 'asc'|'desc', title?: 'asc'|'desc'} $typedSortBy */
            $typedSortBy = $sortBy;

            $articles = $this->articleRepository->findBy(
                $typedFilters,
                $typedSortBy,
                [ArticleRepositoryInterface::GROUP_SELECT_ARTICLE_WEBSITE => true],
            );

            $excludedMap = \array_fill_keys($excludedIds, true);
            $result = [];
            $skipped = 0;
            foreach ($articles as $article) {
                if (isset($excludedMap[$article->getUuid()])) {
                    continue;
                }
                if ($skipped < $offset) {
                    ++$skipped;
                    continue;
                }
                $normalized = $this->normalizeArticleEntity($article, $resolvedLocale);
                if (null !== $normalized) {
                    $result[] = $normalized;
                    if (\count($result) >= $limit) {
                        break;
                    }
                }
            }

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeArticleEntity(ArticleInterface $article, string $locale): ?array
    {
        $uuid = $article->getUuid();
        $stageKey = $this->isPreviewContext() ? DimensionContentInterface::STAGE_DRAFT : DimensionContentInterface::STAGE_LIVE;
        $cacheKey = '' !== $uuid ? "{$stageKey}:{$locale}:{$uuid}" : null;

        if (null !== $cacheKey && \array_key_exists($cacheKey, $this->resolvedEntityCache)) {
            $cached = $this->resolvedEntityCache[$cacheKey];
            if (null !== $cached) {
                $this->referenceStore?->add($uuid, ArticleInterface::RESOURCE_KEY);
            }

            return $cached;
        }

        $dimensionContent = $this->extractDimensionContent($article, $locale);
        if (null === $dimensionContent) {
            if (null !== $cacheKey) {
                $this->resolvedEntityCache[$cacheKey] = null;
            }

            return null;
        }

        if ('' !== $uuid) {
            $this->referenceStore?->add($uuid, ArticleInterface::RESOURCE_KEY);
        }

        $enriched = SuluContentDimensionExtractor::normalizeDimensionContent(
            $uuid,
            $dimensionContent,
            $this->contentResolver,
            includeAuthorName: false,
        );

        if (null !== $cacheKey) {
            $this->resolvedEntityCache[$cacheKey] = $enriched;
        }

        return $enriched;
    }

    private function extractDimensionContent(ArticleInterface $article, string $locale): ?ArticleDimensionContentInterface
    {
        return SuluContentDimensionExtractor::extractDimensionContent(
            $article,
            $locale,
            $this->contentAggregator,
            $this->isPreviewContext(),
        );
    }

    private function isPreviewContext(): bool
    {
        return SuluContentDimensionExtractor::isPreviewContext($this->requestStack);
    }

    /**
     * @param list<string> $uuids
     *
     * @return list<ArticleInterface>
     */
    private function loadArticlesByOrderedUuids(array $uuids, string $locale): array
    {
        if (null === $this->articleRepository || [] === $uuids) {
            return [];
        }

        try {
            $articles = $this->articleRepository->findBy(
                [
                    'uuids' => $uuids,
                    'locale' => $locale,
                    'stage' => DimensionContentInterface::STAGE_LIVE,
                ],
                [],
                [ArticleRepositoryInterface::GROUP_SELECT_ARTICLE_WEBSITE => true],
            );

            $byUuid = [];
            foreach ($articles as $article) {
                $byUuid[$article->getUuid()] = $article;
            }

            $ordered = [];
            foreach ($uuids as $uuid) {
                if (isset($byUuid[$uuid])) {
                    $ordered[] = $byUuid[$uuid];
                }
            }

            return $ordered;
        } catch (\Throwable) {
            return [];
        }
    }
}
