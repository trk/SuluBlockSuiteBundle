<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider;

use Sulu\Article\Domain\Model\ArticleDimensionContentInterface;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Content\Application\ContentResolver\ContentResolverInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentSourceProviderInterface;

/**
 * Built-in Dynamic Content source provider for Sulu 3 Articles.
 */
final class SuluArticleSourceProvider implements DynamicContentSourceProviderInterface
{
    public function __construct(
        private readonly ?ArticleRepositoryInterface $articleRepository = null,
        private readonly ?ContentResolverInterface $contentResolver = null,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    public function getKey(): string
    {
        return 'sulu_articles';
    }

    public function getLabel(string $locale = 'en'): string
    {
        return match (\strtolower(\substr($locale, 0, 2))) {
            'tr' => 'Sulu Makaleleri (Articles)',
            'de' => 'Sulu-Artikel (Articles)',
            'ru' => 'Статьи Sulu (Articles)',
            'es' => 'Artículos de Sulu (Articles)',
            'fr' => 'Articles Sulu (Articles)',
            default => 'Sulu Articles',
        };
    }

    public function isMultiItem(): bool
    {
        return true;
    }

    public function getSourceFields(string $locale = 'en'): array
    {
        return [
            ['name' => 'title', 'label' => 'Title', 'type' => 'text_line'],
            ['name' => 'headline', 'label' => 'Headline', 'type' => 'text_line'],
            ['name' => 'summary', 'label' => 'Summary / Excerpt Description', 'type' => 'text_area'],
            ['name' => 'description', 'label' => 'Description', 'type' => 'text_editor'],
            ['name' => 'image', 'label' => 'Image / Excerpt Image', 'type' => 'single_media_selection'],
            ['name' => 'url', 'label' => 'URL', 'type' => 'url'],
            ['name' => 'link', 'label' => 'Link', 'type' => 'link'],
            ['name' => 'authored', 'label' => 'Authored Date (ISO)', 'type' => 'datetime'],
            ['name' => 'badge', 'label' => 'First Tag / Badge', 'type' => 'text_line'],
            ['name' => 'template', 'label' => 'Template Key', 'type' => 'text_line'],
        ];
    }

    public function resolveSingle(array $config, string|object|array|null $contextPage = null, ?string $locale = null): ?array
    {
        if (null === $this->articleRepository) {
            return null;
        }

        $uuid = $config['uuid'] ?? ($config['id'] ?? ($config['page_id'] ?? null));
        if (!\is_string($uuid) || '' === \trim($uuid)) {
            $collection = $this->resolveCollection(\array_merge($config, ['limit' => 1]), $contextPage, $locale);

            return $collection[0] ?? null;
        }

        $resolvedLocale = $this->resolveLocale($locale);

        try {
            $article = $this->articleRepository->findOneBy(
                [
                    'uuid' => \trim($uuid),
                    'locale' => $resolvedLocale,
                    'stage' => DimensionContentInterface::STAGE_LIVE,
                ],
                [ArticleRepositoryInterface::GROUP_SELECT_ARTICLE_WEBSITE => true],
            );

            $article ??= $this->articleRepository->findOneBy(
                [
                    'uuid' => \trim($uuid),
                    'locale' => $resolvedLocale,
                    'stage' => DimensionContentInterface::STAGE_DRAFT,
                ],
                [ArticleRepositoryInterface::GROUP_SELECT_ARTICLE_ADMIN => true],
            );

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

        $resolvedLocale = $this->resolveLocale($locale);
        $query = isset($config['query']) && \is_array($config['query'])
            ? \array_merge($config, $config['query'])
            : $config;

        /** @var array<string, mixed> $filters */
        $filters = [
            'locale' => $resolvedLocale,
            'stage' => DimensionContentInterface::STAGE_LIVE,
        ];

        $uuids = $this->normalizeStringList($query['uuids'] ?? ($query['ids'] ?? ($query['article_ids'] ?? null)));
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
                $articleData['headline'] = $articleData['headline'] ?? ($articleData['title'] ?? null);

                /** @var mixed $excerptDesc */
                $excerptDesc = null;
                /** @var mixed $excerptImage */
                $excerptImage = null;
                $excerpt = $resolved['extension']['excerpt'] ?? null;
                if (\is_array($excerpt)) {
                    $excerptDesc = $excerpt['description'] ?? null;
                    $excerptImage = $excerpt['image'] ?? null;
                }

                $articleData['summary'] = $articleData['summary'] ?? ($articleData['description'] ?? $excerptDesc);
                $articleData['description'] = $articleData['description'] ?? $excerptDesc;
                $articleData['image'] = $articleData['image'] ?? $excerptImage;

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
        $existingHeadline = $articleData['headline'] ?? null;
        if (!\is_string($existingHeadline) || '' === \trim($existingHeadline)) {
            $articleData['headline'] = $entityTitle;
        }
        $articleData['summary'] = $articleData['summary'] ?? ($articleData['description'] ?? $dimensionContent->getExcerptDescription());
        $articleData['description'] = $articleData['description'] ?? $dimensionContent->getExcerptDescription();
        $articleData['image'] = $articleData['image'] ?? $dimensionContent->getExcerptImage();

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
        /** @var iterable<ArticleDimensionContentInterface> $dimensionContents */
        $dimensionContents = $article->getDimensionContents();

        $fallback = null;
        foreach ($dimensionContents as $content) {
            if ($content->getLocale() === $locale) {
                if (DimensionContentInterface::STAGE_LIVE === $content->getStage()) {
                    return $content;
                }
                $fallback ??= $content;
            }
        }

        if (null !== $fallback) {
            return $fallback;
        }

        foreach ($dimensionContents as $content) {
            return $content;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $raw): array
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
    private function normalizeIntList(mixed $raw): array
    {
        if (\is_int($raw)) {
            return [$raw];
        }

        if (\is_string($raw)) {
            $raw = \explode(',', $raw);
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

    private function resolveLocale(?string $locale): string
    {
        if (null !== $locale && '' !== \trim($locale)) {
            return \trim($locale);
        }

        if (null !== $this->requestStack) {
            $request = $this->requestStack->getCurrentRequest();
            if (null !== $request && '' !== $request->getLocale()) {
                return $request->getLocale();
            }
        }

        return 'en';
    }
}
