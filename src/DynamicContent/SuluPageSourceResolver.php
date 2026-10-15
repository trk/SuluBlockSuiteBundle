<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent;

use Sulu\Content\Application\ContentResolver\ContentResolverInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Page\Domain\Model\PageDimensionContentInterface;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class SuluPageSourceResolver implements PageSourceResolverInterface
{
    public function __construct(
        private readonly ?PageRepositoryInterface $pageRepository = null,
        private readonly ?ContentResolverInterface $contentResolver = null,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    /**
     * Resolves a single page by UUID, PageInterface, PageDimensionContentInterface, or context array.
     *
     * @param string|object|array<mixed, mixed>|null $page
     *
     * @return array<string, mixed>|null
     */
    public function resolvePage(string|object|array|null $page = null, ?string $locale = null): ?array
    {
        $resolvedLocale = $this->resolveLocale($locale);

        if (\is_array($page) && [] !== $page) {
            /** @var array<string, mixed> $pageArray */
            $pageArray = $page;

            $resolved = $this->resolveCurrentPage($pageArray, $resolvedLocale);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        if (\is_object($page)) {
            if (\method_exists($page, 'getContent') && \is_array($page->getContent())) {
                $resolved = $this->resolveCurrentPage(['content' => $page->getContent()], $resolvedLocale);
                if (null !== $resolved) {
                    return $resolved;
                }
            }
        }

        if (\is_string($page)) {
            $pageEntity = $this->findPageEntityByUuid($page, $resolvedLocale);
            if (null !== $pageEntity) {
                return $this->normalizePageEntity($pageEntity, $resolvedLocale);
            }
        }

        if ($page instanceof PageDimensionContentInterface) {
            $resource = $page->getResource();

            return $this->normalizePageEntity($resource, $resolvedLocale);
        }

        if ($page instanceof PageInterface) {
            return $this->normalizePageEntity($page, $resolvedLocale);
        }

        return $this->resolveFromRequest($resolvedLocale);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>|null
     */
    public function resolveCurrentPage(array $context, string $locale): ?array
    {
        // 1. If explicit "page" is provided (e.g. from page_layout), prioritize it!
        if (isset($context['page'])) {
            $pageContext = $context['page'];
            if (\is_array($pageContext) && (isset($pageContext['title']) || isset($pageContext['headline']))) {
                return $this->resolveCurrentPage([
                    'content' => $pageContext,
                    'resource' => $context['resource'] ?? null,
                    'object' => $context['object'] ?? null,
                    'view' => $context['view'] ?? null,
                    'extension' => $context['extension'] ?? null,
                ], $locale);
            }
            if (\is_object($pageContext)) {
                if (\method_exists($pageContext, 'getContent') && \is_array($pageContext->getContent())) {
                    return $this->resolveCurrentPage([
                        'content' => $pageContext->getContent(),
                        'resource' => $context['resource'] ?? null,
                        'object' => $context['object'] ?? null,
                        'view' => $context['view'] ?? null,
                        'extension' => $context['extension'] ?? null,
                    ], $locale);
                }
                if ($pageContext instanceof PageDimensionContentInterface) {
                    return $this->normalizePageEntity($pageContext->getResource(), $locale);
                }
                if ($pageContext instanceof PageInterface) {
                    return $this->normalizePageEntity($pageContext, $locale);
                }
            }
        }

        // 2. Standard content context
        if (isset($context['content']) && \is_array($context['content'])) {
            /** @var array<string, mixed> $content */
            $content = $context['content'];

            $resourceObj = $context['resource'] ?? null;
            if (!$resourceObj instanceof PageInterface && isset($context['object']) && $context['object'] instanceof PageDimensionContentInterface) {
                $resourceObj = $context['object']->getResource();
            }

            /** @var mixed $rawId */
            $rawId = $content['id']
                ?? ($content['uuid']
                ?? ($context['resource_id']
                ?? ($resourceObj instanceof PageInterface ? $resourceObj->getUuid() : null)));
            $content['id'] = \is_scalar($rawId) ? (string) $rawId : null;
            $content['uuid'] = $content['id'];

            if (!isset($content['parent_id']) && $resourceObj instanceof PageInterface && null !== $resourceObj->getParent()) {
                $content['parent_id'] = $resourceObj->getParent()->getUuid();
            }

            $content['headline'] = $this->firstNonEmptyString($content['headline'] ?? null, $content['title'] ?? null);

            /** @var mixed $excerptDesc */
            $excerptDesc = null;
            /** @var mixed $excerptImage */
            $excerptImage = null;
            if (isset($context['extension']) && \is_array($context['extension']) && isset($context['extension']['excerpt']) && \is_array($context['extension']['excerpt'])) {
                $excerptDesc = $context['extension']['excerpt']['description'] ?? null;
                $excerptImage = $context['extension']['excerpt']['image'] ?? null;
            }

            $rawSummary = $content['summary'] ?? null;
            $rawDescription = $content['description'] ?? null;
            $rawBodyContent = $content['content'] ?? null;

            $content['summary'] = $this->resolveSummaryWithContentFallback($rawSummary, $rawDescription, $excerptDesc, $rawBodyContent);
            $content['description'] = $this->resolveSummaryWithContentFallback($rawDescription, $rawSummary, $excerptDesc, $rawBodyContent);
            $content['image'] = $this->firstNonEmptyMedia($content['image'] ?? null, $excerptImage);

            /** @var mixed $url */
            $url = $content['url'] ?? null;
            if (null === $url && isset($context['view']) && \is_array($context['view']) && isset($context['view']['url'])) {
                $url = $context['view']['url'];
            }
            if (null === $url && isset($context['localizations']) && \is_array($context['localizations']) && isset($context['localizations'][$locale]) && \is_array($context['localizations'][$locale])) {
                $url = $context['localizations'][$locale]['url'] ?? null;
            }
            $content['url'] = \is_string($url) ? $url : null;
            $content['link'] = $content['link'] ?? $content['url'];

            return $content;
        }

        if (isset($context['object']) && $context['object'] instanceof PageDimensionContentInterface) {
            $page = $context['object']->getResource();

            return $this->normalizePageEntity($page, $locale);
        }

        if (isset($context['resource']) && $context['resource'] instanceof PageInterface) {
            return $this->normalizePageEntity($context['resource'], $locale);
        }

        if (isset($context['title']) || isset($context['headline'])) {
            /** @var array<string, mixed> $normalized */
            $normalized = $context;
            $normalized['headline'] = $this->firstNonEmptyString($context['headline'] ?? null, $context['title'] ?? null);

            $rawSummary = $context['summary'] ?? null;
            $rawDescription = $context['description'] ?? null;
            $rawBodyContent = $context['content'] ?? null;

            $normalized['summary'] = $this->resolveSummaryWithContentFallback($rawSummary, $rawDescription, null, $rawBodyContent);
            $normalized['description'] = $this->resolveSummaryWithContentFallback($rawDescription, $rawSummary, null, $rawBodyContent);

            /** @var mixed $url */
            $url = $context['url'] ?? null;
            $normalized['link'] = $context['link'] ?? (\is_string($url) ? $url : null);

            return $normalized;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveFromRequest(string $locale): ?array
    {
        if (null === $this->requestStack) {
            return null;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return null;
        }

        // 1. Preview request query parameter (?provider=pages&id=UUID)
        $previewId = $request->query->get('id');
        $provider = $request->query->get('provider');
        if (('pages' === $provider || null === $provider) && \is_string($previewId) && '' !== \trim($previewId)) {
            $resolved = $this->resolvePage(\trim($previewId), $locale);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        // 2. Request attributes from Sulu 3 / Sulu 2 Website routing
        $object = $request->attributes->get('object');
        if ($object instanceof PageInterface || $object instanceof PageDimensionContentInterface) {
            return $this->resolvePage($object, $locale);
        }

        $resource = $request->attributes->get('resource');
        if ($resource instanceof PageInterface || $resource instanceof PageDimensionContentInterface) {
            return $this->resolvePage($resource, $locale);
        }

        $structure = $request->attributes->get('structure');
        if ($structure instanceof PageInterface || $structure instanceof PageDimensionContentInterface) {
            return $this->resolvePage($structure, $locale);
        }

        $reqContent = $request->attributes->get('content');
        if (\is_array($reqContent) && (isset($reqContent['title']) || isset($reqContent['headline']))) {
            return $this->resolveCurrentPage(['content' => $reqContent], $locale);
        }

        $uuid = $request->attributes->get('uuid') ?? $request->attributes->get('id');
        if (\is_string($uuid) && '' !== \trim($uuid)) {
            return $this->resolvePage(\trim($uuid), $locale);
        }

        return null;
    }

    /**
     * Resolves the parent page of the given page (or of the current page when null).
     *
     * @param string|object|array<mixed, mixed>|null $page
     *
     * @return array<string, mixed>|null
     */
    public function resolveParent(string|object|array|null $page = null, ?string $locale = null): ?array
    {
        $resolvedLocale = $this->resolveLocale($locale);

        if ($page instanceof PageInterface) {
            $parent = $page->getParent();

            return null !== $parent ? $this->normalizePageEntity($parent, $resolvedLocale) : null;
        }

        if ($page instanceof PageDimensionContentInterface) {
            $parent = $page->getResource()->getParent();

            return null !== $parent ? $this->normalizePageEntity($parent, $resolvedLocale) : null;
        }

        if (\is_array($page)) {
            if (isset($page['resource']) && $page['resource'] instanceof PageInterface) {
                $parent = $page['resource']->getParent();

                return null !== $parent ? $this->normalizePageEntity($parent, $resolvedLocale) : null;
            }
            if (isset($page['object']) && $page['object'] instanceof PageDimensionContentInterface) {
                $parent = $page['object']->getResource()->getParent();

                return null !== $parent ? $this->normalizePageEntity($parent, $resolvedLocale) : null;
            }
            if (isset($page['page']) && $page['page'] instanceof PageInterface) {
                $parent = $page['page']->getParent();

                return null !== $parent ? $this->normalizePageEntity($parent, $resolvedLocale) : null;
            }
            if (isset($page['page']) && $page['page'] instanceof PageDimensionContentInterface) {
                $parent = $page['page']->getResource()->getParent();

                return null !== $parent ? $this->normalizePageEntity($parent, $resolvedLocale) : null;
            }

            $rawParentId = $page['parent_id'] ?? ($page['parentId'] ?? ($page['parent_uuid'] ?? null));
            if (\is_string($rawParentId) && '' !== \trim($rawParentId)) {
                return $this->resolvePage(\trim($rawParentId), $resolvedLocale);
            }
            if (isset($page['content']) && \is_array($page['content'])) {
                $contentParentId = $page['content']['parent_id'] ?? ($page['content']['parentId'] ?? null);
                if (\is_string($contentParentId) && '' !== \trim($contentParentId)) {
                    return $this->resolvePage(\trim($contentParentId), $resolvedLocale);
                }
            }
            if (isset($page['page']) && \is_array($page['page'])) {
                $pageParentId = $page['page']['parent_id'] ?? ($page['page']['parentId'] ?? null);
                if (\is_string($pageParentId) && '' !== \trim($pageParentId)) {
                    return $this->resolvePage(\trim($pageParentId), $resolvedLocale);
                }
            }
        }

        $targetUuid = null !== $page ? $this->resolvePageUuid($page) : null;
        if (null === $targetUuid || '' === $targetUuid) {
            $currentData = $this->resolveFromRequest($resolvedLocale);
            if (null !== $currentData) {
                $rawParent = $currentData['parent_id'] ?? ($currentData['parentId'] ?? null);
                if (\is_string($rawParent) && '' !== \trim($rawParent)) {
                    return $this->resolvePage(\trim($rawParent), $resolvedLocale);
                }
                $rawUuid = $currentData['id'] ?? ($currentData['uuid'] ?? null);
                if (\is_string($rawUuid) && '' !== \trim($rawUuid)) {
                    $targetUuid = \trim($rawUuid);
                }
            }
        }

        if (null !== $targetUuid && '' !== $targetUuid) {
            $pageEntity = $this->findPageEntityByUuid($targetUuid, $resolvedLocale);
            if (null !== $pageEntity && null !== $pageEntity->getParent()) {
                return $this->normalizePageEntity($pageEntity->getParent(), $resolvedLocale);
            }
        }

        return null;
    }

    /**
     * Resolves sibling pages sharing the same parent as the given page (or current page).
     *
     * @param string|object|array<mixed, mixed>|null $page
     * @param array<string, mixed> $options
     *
     * @return list<array<string, mixed>>
     */
    public function resolveSiblings(
        string|object|array|null $page = null,
        ?string $locale = null,
        int $limit = 50,
        bool $excludeCurrent = true,
        array $options = [],
    ): array {
        $resolvedLocale = $this->resolveLocale($locale);
        $currentUuid = null !== $page ? $this->resolvePageUuid($page) : null;
        if (null === $currentUuid || '' === $currentUuid) {
            $currentData = $this->resolveFromRequest($resolvedLocale);
            if (null !== $currentData && isset($currentData['id']) && \is_string($currentData['id'])) {
                $currentUuid = $currentData['id'];
            }
        }

        $parentData = $this->resolveParent($page, $resolvedLocale);
        $parentUuid = null !== $parentData && isset($parentData['id']) && \is_string($parentData['id'])
            ? $parentData['id']
            : null;

        if (null === $parentUuid || '' === $parentUuid) {
            return [];
        }

        $criteria = \array_merge($options, [
            'parent_id' => $parentUuid,
            'limit' => $limit,
        ]);

        if ($excludeCurrent && null !== $currentUuid && '' !== $currentUuid) {
            $criteria['exclude_id'] = $currentUuid;
        }

        return $this->resolveQuery($criteria, $resolvedLocale);
    }

    /**
     * Resolves child pages of a parent page.
     *
     * @param string|object|array<mixed, mixed> $parent
     *
     * @return list<array<string, mixed>>
     */
    public function resolveChildren(string|object|array $parent, ?string $locale = null, int $limit = 50): array
    {
        if (null === $this->pageRepository) {
            return [];
        }

        $resolvedLocale = $this->resolveLocale($locale);
        $parentId = $this->resolvePageUuid($parent);
        if (null === $parentId || '' === $parentId) {
            $currentData = $this->resolveFromRequest($resolvedLocale);
            if (null !== $currentData && isset($currentData['id']) && \is_string($currentData['id'])) {
                $parentId = $currentData['id'];
            }
        }

        if (null === $parentId || '' === $parentId) {
            return [];
        }

        /** @var array{uuid?: string, uuids?: list<string>, locale?: string, stage?: string, parentId?: string, descendantOfId?: string, webspaceKey?: string, limit?: int} $filters */
        $filters = [
            'parentId' => $parentId,
            'locale' => $resolvedLocale,
            'stage' => DimensionContentInterface::STAGE_LIVE,
        ];

        $limit = $limit > 0 ? $limit : 50;
        $filters['limit'] = $limit;

        try {
            /** @var iterable<PageInterface> $pages */
            $pages = $this->pageRepository->findBy(
                $filters,
                ['created' => 'desc'],
                [PageRepositoryInterface::GROUP_SELECT_PAGE_WEBSITE => true],
            );

            $result = [];
            foreach ($pages as $page) {
                $data = $this->normalizePageEntity($page, $resolvedLocale);
                if (null !== $data) {
                    $result[] = $data;
                }
            }

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Resolves pages matching category / tag / template / custom query filters.
     *
     * @param array<string, mixed> $criteria
     *
     * @return list<array<string, mixed>>
     */
    public function resolveQuery(array $criteria, ?string $locale = null): array
    {
        if (null === $this->pageRepository) {
            return [];
        }

        $resolvedLocale = $this->resolveLocale($locale);

        /** @var array<string, mixed> $filters */
        $filters = [
            'locale' => $resolvedLocale,
            'stage' => DimensionContentInterface::STAGE_LIVE,
        ];

        $uuids = $this->normalizeStringList($criteria['uuids'] ?? ($criteria['ids'] ?? null));
        if ([] !== $uuids) {
            $filters['uuids'] = $uuids;
        }

        if (\array_key_exists('parent_id', $criteria)) {
            $rawParentCriteria = $criteria['parent_id'];
            $resolvedParentUuid = (\is_string($rawParentCriteria) || \is_object($rawParentCriteria) || \is_array($rawParentCriteria))
                ? $this->resolvePageUuid($rawParentCriteria)
                : null;
            if (null === $resolvedParentUuid || '' === $resolvedParentUuid) {
                $currentData = $this->resolveFromRequest($resolvedLocale);
                if (null !== $currentData && isset($currentData['id']) && \is_string($currentData['id'])) {
                    $resolvedParentUuid = $currentData['id'];
                }
            }
            if (null === $resolvedParentUuid || '' === $resolvedParentUuid) {
                return [];
            }
            $filters['parentId'] = $resolvedParentUuid;
        }

        if (isset($criteria['descendant_of_id']) && \is_string($criteria['descendant_of_id']) && '' !== \trim($criteria['descendant_of_id'])) {
            $filters['descendantOfId'] = \trim($criteria['descendant_of_id']);
        }

        if (isset($criteria['webspace_key']) && \is_string($criteria['webspace_key']) && '' !== \trim($criteria['webspace_key'])) {
            $filters['webspaceKey'] = \trim($criteria['webspace_key']);
        }

        $templateKeys = $this->normalizeStringList($criteria['template_keys'] ?? ($criteria['templates'] ?? ($criteria['template'] ?? null)));
        if ([] !== $templateKeys) {
            $filters['templateKeys'] = $templateKeys;
        }

        $tagIds = $this->normalizeIntList($criteria['tag_ids'] ?? null);
        $rawTags = $criteria['tag_names'] ?? ($criteria['tags'] ?? ($criteria['tag'] ?? null));
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
        $rawCategories = $criteria['category_keys'] ?? ($criteria['categories'] ?? null);
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

        $navigationContexts = $this->normalizeStringList($criteria['navigation_contexts'] ?? ($criteria['navigation_context'] ?? null));
        if ([] !== $navigationContexts) {
            $filters['navigationContexts'] = $navigationContexts;
        }

        if (isset($criteria['depth']) && \is_numeric($criteria['depth']) && (int) $criteria['depth'] > 0) {
            $filters['depth'] = (int) $criteria['depth'];
        }

        $offset = isset($criteria['offset']) && \is_numeric($criteria['offset']) && (int) $criteria['offset'] > 0
            ? (int) $criteria['offset']
            : 0;
        $excludedIds = $this->normalizeStringList($criteria['exclude_ids'] ?? ($criteria['exclude_id'] ?? null));

        $limit = isset($criteria['limit']) && \is_numeric($criteria['limit']) ? (int) $criteria['limit'] : 10;
        if ($limit > 0) {
            // Fetch extra rows when offset or excluded IDs are applied in memory
            $filters['limit'] = $limit + $offset + \count($excludedIds);
        }

        /** @var array<string, 'asc'|'desc'> $sortBy */
        $sortBy = ['created' => 'desc'];
        if (isset($criteria['sort_by']) && \is_string($criteria['sort_by']) && '' !== \trim($criteria['sort_by'])) {
            $rawOrder = $criteria['sort_order'] ?? 'desc';
            $order = (\is_string($rawOrder) && 'asc' === \strtolower($rawOrder)) ? 'asc' : 'desc';
            $sortField = \trim($criteria['sort_by']);
            if ('published' === $sortField) {
                $sortField = 'workflowPublished';
            }
            $sortBy = [$sortField => $order];
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
             *     parentId?: string|null,
             *     descendantOfId?: string,
             *     limit?: int,
             *     navigationContexts?: list<string>,
             *     depth?: int,
             * } $typedFilters
             */
            $typedFilters = $filters;
            /** @var array{id?: 'asc'|'desc', title?: 'asc'|'desc'} $typedSortBy */
            $typedSortBy = $sortBy;

            /** @var iterable<PageInterface> $pages */
            $pages = $this->pageRepository->findBy(
                $typedFilters,
                $typedSortBy,
                [PageRepositoryInterface::GROUP_SELECT_PAGE_WEBSITE => true],
            );

            $excludedMap = \array_fill_keys($excludedIds, true);
            $result = [];
            foreach ($pages as $page) {
                if (isset($excludedMap[$page->getUuid()])) {
                    continue;
                }
                $data = $this->normalizePageEntity($page, $resolvedLocale);
                if (null !== $data) {
                    $result[] = $data;
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

    /**
     * @return array<string, mixed>|null
     */
    private function normalizePageEntity(PageInterface $page, string $locale): ?array
    {
        $dimensionContent = $this->extractDimensionContent($page, $locale);
        if (null === $dimensionContent) {
            return null;
        }

        if (null !== $this->contentResolver) {
            try {
                $resolved = $this->contentResolver->resolve($dimensionContent);
                /** @var array<string, mixed> $pageData */
                $pageData = $resolved['content'];
                $pageData['id'] = $page->getUuid();
                $pageData['uuid'] = $page->getUuid();

                $title = $dimensionContent->getTitle();
                if (null !== $title) {
                    $pageData['title'] = $title;
                }
                $pageData['headline'] = $this->firstNonEmptyString($pageData['headline'] ?? null, $pageData['title'] ?? null);

                /** @var mixed $excerptDesc */
                $excerptDesc = null;
                /** @var mixed $excerptImage */
                $excerptImage = null;
                $excerpt = $resolved['extension']['excerpt'] ?? null;
                if (\is_array($excerpt)) {
                    $excerptDesc = $excerpt['description'] ?? null;
                    $excerptImage = $excerpt['image'] ?? null;
                }

                $rawSummary = $pageData['summary'] ?? null;
                $rawDescription = $pageData['description'] ?? null;
                $rawBodyContent = $pageData['content'] ?? null;

                $pageData['summary'] = $this->resolveSummaryWithContentFallback($rawSummary, $rawDescription, $excerptDesc, $rawBodyContent);
                $pageData['description'] = $this->resolveSummaryWithContentFallback($rawDescription, $rawSummary, $excerptDesc, $rawBodyContent);
                $pageData['image'] = $this->firstNonEmptyMedia($pageData['image'] ?? null, $excerptImage);

                if (isset($resolved['view']['url']) && \is_string($resolved['view']['url'])) {
                    $pageData['url'] = $resolved['view']['url'];
                    $pageData['link'] = $resolved['view']['url'];
                }

                if (null !== $dimensionContent->getAuthored()) {
                    $pageData['authored'] = $dimensionContent->getAuthored()->format('c');
                    $pageData['date'] = $pageData['authored'];
                }

                if (null !== $dimensionContent->getAuthor()) {
                    $pageData['author'] = $dimensionContent->getAuthor();
                }

                return $this->enrichPageMetadata($pageData, $page, $dimensionContent);
            } catch (\Throwable) {
                // Fallback to direct entity getters
            }
        }

        /** @var array<string, mixed> $pageData */
        $pageData = $dimensionContent->getTemplateData();
        $pageData['id'] = $page->getUuid();
        $pageData['uuid'] = $page->getUuid();
        $entityTitle = $dimensionContent->getTitle();
        $pageData['title'] = $entityTitle;
        $pageData['headline'] = $this->firstNonEmptyString($pageData['headline'] ?? null, $entityTitle);

        $excerptDesc = $dimensionContent->getExcerptDescription();
        $rawSummary = $pageData['summary'] ?? null;
        $rawDescription = $pageData['description'] ?? null;
        $rawBodyContent = $pageData['content'] ?? null;

        $pageData['summary'] = $this->resolveSummaryWithContentFallback($rawSummary, $rawDescription, $excerptDesc, $rawBodyContent);
        $pageData['description'] = $this->resolveSummaryWithContentFallback($rawDescription, $rawSummary, $excerptDesc, $rawBodyContent);
        $pageData['image'] = $this->firstNonEmptyMedia($pageData['image'] ?? null, $dimensionContent->getExcerptImage());

        $route = $dimensionContent->getRoute();
        if (null !== $route) {
            $pageData['url'] = $route->getSlug();
            $pageData['link'] = $route->getSlug();
        }

        if (null !== $dimensionContent->getAuthored()) {
            $pageData['authored'] = $dimensionContent->getAuthored()->format('c');
            $pageData['date'] = $pageData['authored'];
        }

        if (null !== $dimensionContent->getAuthor()) {
            $pageData['author'] = $dimensionContent->getAuthor();
        }

        return $this->enrichPageMetadata($pageData, $page, $dimensionContent);
    }

    /**
     * @param array<string, mixed> $pageData
     *
     * @return array<string, mixed>
     */
    private function enrichPageMetadata(
        array $pageData,
        PageInterface $page,
        PageDimensionContentInterface $dimensionContent,
    ): array {
        $parent = $page->getParent();
        if (null !== $parent) {
            $pageData['parent_id'] ??= $parent->getUuid();
        }

        $templateKey = $dimensionContent->getTemplateKey();
        if (null !== $templateKey && '' !== $templateKey) {
            $pageData['template'] ??= $templateKey;
        }

        $tagNames = $dimensionContent->getExcerptTagNames();
        if ([] !== $tagNames) {
            $pageData['tags'] ??= \array_values($tagNames);
            $pageData['badge'] ??= $tagNames[\array_key_first($tagNames)];
        }

        $categoryIds = $dimensionContent->getExcerptCategoryIds();
        if ([] !== $categoryIds) {
            $pageData['categories'] ??= \array_values($categoryIds);
        }

        return $pageData;
    }

    private function extractDimensionContent(
        PageInterface $page,
        string $locale,
        bool $allowReload = true,
    ): ?PageDimensionContentInterface {
        /** @var iterable<PageDimensionContentInterface> $dimensionContents */
        $dimensionContents = $page->getDimensionContents();

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

        // If localized dimension content was not initialized on this entity (e.g. parent proxy), reload it
        if ($allowReload && null !== $this->pageRepository && '' !== $page->getUuid()) {
            $reloaded = $this->findPageEntityByUuid($page->getUuid(), $locale);
            if (null !== $reloaded) {
                return $this->extractDimensionContent($reloaded, $locale, false);
            }
        }

        // Secondary fallback: check unlocalized
        foreach ($dimensionContents as $content) {
            if (null === $content->getLocale() || '' === $content->getLocale()) {
                return $content;
            }
        }

        foreach ($dimensionContents as $content) {
            return $content;
        }

        return null;
    }

    private function findPageEntityByUuid(string $uuid, string $locale): ?PageInterface
    {
        $trimId = \trim($uuid);
        if ('' === $trimId || null === $this->pageRepository) {
            return null;
        }

        try {
            $pageEntity = $this->pageRepository->findOneBy(
                [
                    'uuid' => $trimId,
                    'locale' => $locale,
                    'stage' => DimensionContentInterface::STAGE_LIVE,
                ],
                [PageRepositoryInterface::GROUP_SELECT_PAGE_WEBSITE => true],
            );

            if (null !== $pageEntity) {
                return $pageEntity;
            }

            return $this->pageRepository->findOneBy(
                [
                    'uuid' => $trimId,
                    'locale' => $locale,
                    'stage' => DimensionContentInterface::STAGE_DRAFT,
                ],
                [PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true],
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param string|object|array<mixed, mixed> $page
     */
    private function resolvePageUuid(string|object|array $page): ?string
    {
        if (\is_array($page)) {
            if (isset($page['resource']) && $page['resource'] instanceof PageInterface) {
                return $page['resource']->getUuid();
            }
            if (isset($page['object']) && $page['object'] instanceof PageDimensionContentInterface) {
                return $page['object']->getResource()->getUuid();
            }
            if (isset($page['page'])) {
                if ($page['page'] instanceof PageInterface) {
                    return $page['page']->getUuid();
                }
                if ($page['page'] instanceof PageDimensionContentInterface) {
                    return $page['page']->getResource()->getUuid();
                }
                if (\is_array($page['page'])) {
                    $pageId = $page['page']['id'] ?? ($page['page']['uuid'] ?? null);
                    if (\is_scalar($pageId) && '' !== \trim((string) $pageId)) {
                        return \trim((string) $pageId);
                    }
                }
                if (\is_string($page['page']) && '' !== \trim($page['page'])) {
                    return \trim($page['page']);
                }
            }

            $rawId = $page['id'] ?? ($page['uuid'] ?? ($page['resource_id'] ?? null));
            if (\is_scalar($rawId) && '' !== \trim((string) $rawId)) {
                return \trim((string) $rawId);
            }

            if (isset($page['content']) && \is_array($page['content'])) {
                $contentId = $page['content']['id'] ?? ($page['content']['uuid'] ?? null);
                if (\is_scalar($contentId) && '' !== \trim((string) $contentId)) {
                    return \trim((string) $contentId);
                }
            }

            return null;
        }

        if (\is_string($page)) {
            $trimId = \trim($page);

            return '' !== $trimId ? $trimId : null;
        }

        if ($page instanceof PageInterface) {
            return $page->getUuid();
        }

        if ($page instanceof PageDimensionContentInterface) {
            return $page->getResource()->getUuid();
        }

        return null;
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
