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
use Totoglu\SuluBlockSuiteBundle\DynamicContent\AbstractDynamicContentSourceProvider;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentHydrator;

/**
 * Built-in Dynamic Content source provider for Sulu 3 Articles (Single & Collection).
 */
final class SuluArticleSourceProvider extends AbstractDynamicContentSourceProvider
{
    public function __construct(
        private readonly ?ArticleRepositoryInterface $articleRepository = null,
        private readonly ?ContentResolverInterface $contentResolver = null,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?ReferenceStoreInterface $referenceStore = null,
        private readonly ?ContentAggregatorInterface $contentAggregator = null,
    ) {
    }

    public function getKey(): string
    {
        return 'sulu_articles';
    }

    public function getLabel(string $locale = 'en'): string
    {
        return match (\strtolower(\substr($locale, 0, 2))) {
            'tr' => 'Makaleler',
            'de' => 'Artikel',
            'ru' => 'Статьи',
            'es' => 'Artículos',
            'fr' => 'Articles',
            default => 'Articles',
        };
    }

    public function getSingleKey(): string
    {
        return 'single_article';
    }

    public function getSingleLabel(string $locale = 'en'): string
    {
        return match (\strtolower(\substr($locale, 0, 2))) {
            'tr' => 'Tekil Makale Seçimi',
            'de' => 'Einzelne Artikelauswahl',
            'ru' => 'Выбор одной статьи',
            'es' => 'Selección de artículo único',
            'fr' => 'Sélection d’article unique',
            default => 'Single Article Selection',
        };
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
        $fields = $this->getSourceFields($locale);

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

        $resolvedLocale = $this->resolveLocale($locale, $this->requestStack);

        try {
            $article = $this->articleRepository->findOneBy(
                [
                    'uuid' => \trim($uuid),
                    'locale' => $resolvedLocale,
                    'stage' => DimensionContentInterface::STAGE_LIVE,
                ],
                [ArticleRepositoryInterface::GROUP_SELECT_ARTICLE_WEBSITE => true],
            );

            if (null === $article && $this->isPreviewContext()) {
                $article = $this->articleRepository->findOneBy(
                    [
                        'uuid' => \trim($uuid),
                        'locale' => $resolvedLocale,
                        'stage' => DimensionContentInterface::STAGE_DRAFT,
                    ],
                    [ArticleRepositoryInterface::GROUP_SELECT_ARTICLE_ADMIN => true],
                );
            }

            return null !== $article ? $this->normalizeArticleEntity($article, $resolvedLocale) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function resolveCollection(array $config, string|object|array|null $contextPage = null, ?string $locale = null): array
    {
        if (null === $this->articleRepository) {
            return [];
        }

        $resolvedLocale = $this->resolveLocale($locale, $this->requestStack);
        $query = isset($config['query']) && \is_array($config['query'])
            ? \array_merge($config, $config['query'])
            : $config;

        /** @var array<string, mixed> $filters */
        $filters = [
            'locale' => $resolvedLocale,
            'stage' => DimensionContentInterface::STAGE_LIVE,
        ];

        $uuids = $this->normalizeStringList($query['article_ids'] ?? ($query['uuids'] ?? ($query['ids'] ?? null)));
        if ([] !== $uuids) {
            $filters['uuids'] = $uuids;
        }

        $templateKeys = $this->normalizeStringList($query['template_keys'] ?? ($query['templates'] ?? ($query['template'] ?? null)));
        if ([] !== $templateKeys) {
            $filters['templateKeys'] = $templateKeys;
        }

        $tagIds = $this->normalizeIntList($query['tag_ids'] ?? null);
        $rawTags = $query['tag_names'] ?? ($query['tags'] ?? ($query['tag'] ?? null));
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

        $categoryIds = $this->normalizeIntList($query['category_ids'] ?? null);
        $rawCategories = $query['category_keys'] ?? ($query['categories'] ?? null);
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

        $offset = isset($query['offset']) && \is_numeric($query['offset']) && (int) $query['offset'] > 0
            ? (int) $query['offset']
            : 0;
        $excludedIds = $this->normalizeStringList($query['exclude_ids'] ?? ($query['exclude_id'] ?? null));

        $limit = isset($query['limit']) && \is_numeric($query['limit']) ? (int) $query['limit'] : 10;
        if ($limit > 0) {
            $filters['limit'] = $limit + $offset + \count($excludedIds);
        }

        /** @var array<string, 'asc'|'desc'> $sortBy */
        $sortBy = ['created' => 'desc'];
        if (isset($query['sort_by']) && \is_string($query['sort_by']) && '' !== \trim($query['sort_by'])) {
            $rawOrder = $query['sort_order'] ?? 'desc';
            $order = (\is_string($rawOrder) && 'asc' === \strtolower($rawOrder)) ? 'asc' : 'desc';
            $sortField = \trim($query['sort_by']);
            if ('published' === $sortField) {
                $sortField = 'workflowPublished';
            }
            if (\in_array($sortField, ['id', 'uuid', 'title', 'authored', 'workflowPublished', 'created', 'changed'], true)) {
                $sortBy = [$sortField => $order];
            }
        }

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
            foreach ($articles as $article) {
                if (isset($excludedMap[$article->getUuid()])) {
                    continue;
                }
                $normalized = $this->normalizeArticleEntity($article, $resolvedLocale);
                if (null !== $normalized) {
                    $result[] = $normalized;
                }
            }

            if ($offset > 0 || ($limit > 0 && \count($result) > $limit)) {
                $result = \array_slice($result, $offset, $limit > 0 ? $limit : null);
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
        $dimensionContent = $this->extractDimensionContent($article, $locale);
        if (null === $dimensionContent) {
            return null;
        }

        if ('' !== $article->getUuid()) {
            $this->referenceStore?->add($article->getUuid(), ArticleInterface::RESOURCE_KEY);
        }

        if (null !== $this->contentResolver) {
            try {
                $resolved = $this->contentResolver->resolve($dimensionContent);
                /** @var array<string, mixed> $articleData */
                $articleData = $resolved['content'];
                $articleData['id'] = $article->getUuid();
                $articleData['uuid'] = $article->getUuid();

                $title = $dimensionContent->getTitle();
                if (null !== $title) {
                    $articleData['title'] = $title;
                }
                $articleData['headline'] = $this->firstNonEmptyString($articleData['headline'] ?? null, $articleData['title'] ?? null);

                /** @var mixed $excerptDesc */
                $excerptDesc = null;
                /** @var mixed $excerptImage */
                $excerptImage = null;
                $excerpt = $resolved['extension']['excerpt'] ?? null;
                if (\is_array($excerpt)) {
                    $excerptDesc = $excerpt['description'] ?? null;
                    $excerptImage = $excerpt['image'] ?? null;
                }

                $rawSummary = $articleData['summary'] ?? null;
                $rawDescription = $articleData['description'] ?? null;
                $rawBodyContent = $this->firstNonEmptyString($articleData['content'] ?? null, $articleData['article'] ?? null);
                if (null !== $rawBodyContent && !isset($articleData['content'])) {
                    $articleData['content'] = $rawBodyContent;
                }

                $articleData['summary'] = $this->resolveSummaryWithContentFallback($rawSummary, $rawDescription, $excerptDesc, $rawBodyContent);
                $articleData['description'] = $this->resolveSummaryWithContentFallback($rawDescription, $rawSummary, $excerptDesc, $rawBodyContent);
                $articleData['image'] = $this->firstNonEmptyMedia($articleData['image'] ?? null, $excerptImage);

                if (isset($resolved['view']['url']) && \is_string($resolved['view']['url'])) {
                    $articleData['url'] = $resolved['view']['url'];
                    $articleData['link'] = $resolved['view']['url'];
                }

                if (null !== $dimensionContent->getAuthored()) {
                    $articleData['authored'] = $dimensionContent->getAuthored()->format('c');
                    $articleData['date'] = $articleData['authored'];
                }

                return $this->enrichArticleMetadata($articleData, $dimensionContent);
            } catch (\Throwable) {
                // Fallback to direct entity getters
            }
        }

        /** @var array<string, mixed> $articleData */
        $articleData = $dimensionContent->getTemplateData();
        $articleData['id'] = $article->getUuid();
        $articleData['uuid'] = $article->getUuid();
        $entityTitle = $dimensionContent->getTitle();
        $articleData['title'] = $entityTitle;
        $articleData['headline'] = $this->firstNonEmptyString($articleData['headline'] ?? null, $entityTitle);

        $excerptDesc = $dimensionContent->getExcerptDescription();
        $rawSummary = $articleData['summary'] ?? null;
        $rawDescription = $articleData['description'] ?? null;
        $rawBodyContent = $this->firstNonEmptyString($articleData['content'] ?? null, $articleData['article'] ?? null);
        if (null !== $rawBodyContent && !isset($articleData['content'])) {
            $articleData['content'] = $rawBodyContent;
        }

        $articleData['summary'] = $this->resolveSummaryWithContentFallback($rawSummary, $rawDescription, $excerptDesc, $rawBodyContent);
        $articleData['description'] = $this->resolveSummaryWithContentFallback($rawDescription, $rawSummary, $excerptDesc, $rawBodyContent);
        $articleData['image'] = $this->firstNonEmptyMedia($articleData['image'] ?? null, $dimensionContent->getExcerptImage());

        $route = $dimensionContent->getRoute();
        if (null !== $route) {
            $articleData['url'] = $route->getSlug();
            $articleData['link'] = $route->getSlug();
        }

        if (null !== $dimensionContent->getAuthored()) {
            $articleData['authored'] = $dimensionContent->getAuthored()->format('c');
            $articleData['date'] = $articleData['authored'];
        }

        return $this->enrichArticleMetadata($articleData, $dimensionContent);
    }

    /**
     * @param array<string, mixed> $articleData
     *
     * @return array<string, mixed>
     */
    private function enrichArticleMetadata(array $articleData, ArticleDimensionContentInterface $dimensionContent): array
    {
        $templateKey = $dimensionContent->getTemplateKey();
        if (null !== $templateKey && '' !== $templateKey) {
            $articleData['template'] ??= $templateKey;
        }

        $tagNames = $dimensionContent->getExcerptTagNames();
        if ([] !== $tagNames) {
            $articleData['tags'] ??= \array_values($tagNames);
            $articleData['badge'] ??= $tagNames[\array_key_first($tagNames)];
        }

        $categoryIds = $dimensionContent->getExcerptCategoryIds();
        if ([] !== $categoryIds) {
            $articleData['categories'] ??= \array_values($categoryIds);
        }

        return $articleData;
    }

    private function extractDimensionContent(ArticleInterface $article, string $locale): ?ArticleDimensionContentInterface
    {
        $isPreview = $this->isPreviewContext();

        if (null !== $this->contentAggregator) {
            try {
                /** @var ArticleDimensionContentInterface $aggregated */
                $aggregated = $this->contentAggregator->aggregate($article, [
                    'locale' => $locale,
                    'stage' => DimensionContentInterface::STAGE_LIVE,
                ]);

                return $aggregated;
            } catch (\Throwable) {
                if ($isPreview) {
                    try {
                        /** @var ArticleDimensionContentInterface $aggregatedDraft */
                        $aggregatedDraft = $this->contentAggregator->aggregate($article, [
                            'locale' => $locale,
                            'stage' => DimensionContentInterface::STAGE_DRAFT,
                        ]);

                        return $aggregatedDraft;
                    } catch (\Throwable) {
                        // Fallback to direct dimension iteration
                    }
                }
            }
        }

        /** @var iterable<ArticleDimensionContentInterface> $dimensionContents */
        $dimensionContents = $article->getDimensionContents();

        $draftFallback = null;
        foreach ($dimensionContents as $content) {
            if ($content->getLocale() === $locale) {
                $stage = $content->getStage();
                if (DimensionContentInterface::STAGE_LIVE === $stage || '' === $stage) {
                    return $content;
                }
                if ($isPreview && DimensionContentInterface::STAGE_DRAFT === $stage) {
                    $draftFallback ??= $content;
                }
            }
        }

        return $draftFallback;
    }

    private function isPreviewContext(): bool
    {
        $request = $this->requestStack?->getCurrentRequest();
        if (null === $request) {
            return false;
        }

        $provider = $request->query->get('provider');
        $route = $request->attributes->get('_route');

        return ('articles' === $provider || 'pages' === $provider || 'snippets' === $provider)
            || $request->query->getBoolean('preview')
            || $request->attributes->getBoolean('preview')
            || $request->attributes->getBoolean('_sulu_preview')
            || (\is_string($route) && \str_starts_with($route, 'sulu_preview'));
    }

    private function firstNonEmptyString(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (\is_string($candidate) && '' !== \trim($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveSummaryWithContentFallback(
        mixed $summary,
        mixed $description,
        mixed $excerptDescription,
        mixed $content,
    ): ?string {
        $resolved = $this->firstNonEmptyString($summary, $description, $excerptDescription);
        if (null !== $resolved) {
            return $resolved;
        }

        if (\is_string($content) && '' !== \trim($content)) {
            $truncated = DynamicContentHydrator::truncatePlainText($content, 160);
            if ('' !== $truncated) {
                return $truncated;
            }
        }

        return null;
    }

    private function firstNonEmptyMedia(mixed ...$candidates): mixed
    {
        foreach ($candidates as $candidate) {
            if (null === $candidate) {
                continue;
            }
            if (\is_string($candidate) && '' === \trim($candidate)) {
                continue;
            }
            if (\is_array($candidate) && [] === $candidate) {
                continue;
            }

            return $candidate;
        }

        return null;
    }
}
