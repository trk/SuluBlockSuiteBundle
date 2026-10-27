<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent;

use Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface;
use Sulu\Component\Webspace\Analyzer\RequestAnalyzerInterface;
use Sulu\Content\Application\ContentAggregator\ContentAggregatorInterface;
use Sulu\Content\Application\ContentResolver\ContentResolverInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Page\Domain\Model\PageDimensionContentInterface;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\Bridge\SuluSmartContentQueryBridge;

class SuluPageSourceResolver implements PageSourceResolverInterface, ResetInterface
{
    /**
     * @var array<string, array<string, mixed>|null>
     */
    private array $resolvedEntityCache = [];

    private readonly DynamicContentQueryFilterNormalizer $queryFilterNormalizer;

    public function __construct(
        private readonly ?PageRepositoryInterface $pageRepository = null,
        private readonly ?ContentResolverInterface $contentResolver = null,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?ReferenceStoreInterface $referenceStore = null,
        private readonly ?ContentAggregatorInterface $contentAggregator = null,
        private readonly ?RequestAnalyzerInterface $requestAnalyzer = null,
        private readonly ?SuluSmartContentQueryBridge $smartContentBridge = null,
        ?DynamicContentQueryFilterNormalizer $queryFilterNormalizer = null,
    ) {
        $this->queryFilterNormalizer = $queryFilterNormalizer ?? new DynamicContentQueryFilterNormalizer();
    }

    public function reset(): void
    {
        $this->resolvedEntityCache = [];
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

        if (isset($context['layout']) && \is_array($context['layout']) && ($context['content'] ?? null) === $context['layout']) {
            if (isset($context['object']) && $context['object'] instanceof PageDimensionContentInterface) {
                return $this->normalizePageEntity($context['object']->getResource(), $locale);
            }
            if (isset($context['resource']) && $context['resource'] instanceof PageInterface) {
                return $this->normalizePageEntity($context['resource'], $locale);
            }

            return null;
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

        $isPreview = $this->isPreviewContext();

        // 1. Preview request query parameter (?provider=pages&id=UUID)
        $previewId = $request->query->get('id');
        if ($isPreview && \is_string($previewId) && '' !== \trim($previewId)) {
            $resolved = $this->resolvePage(\trim($previewId), $locale);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        // 2. Reuse already-resolved Sulu Website content attribute before re-resolving entities
        $reqContent = $request->attributes->get('content');
        if (\is_array($reqContent) && (isset($reqContent['title']) || isset($reqContent['headline']))) {
            /** @var array<string, mixed> $context */
            $context = ['content' => $reqContent];
            $extension = $request->attributes->get('extension');
            if (\is_array($extension)) {
                $context['extension'] = $extension;
            }
            $view = $request->attributes->get('view');
            if (\is_array($view)) {
                $context['view'] = $view;
            }
            $object = $request->attributes->get('object');
            if (\is_object($object)) {
                $context['object'] = $object;
            }
            $resource = $request->attributes->get('resource');
            if (\is_object($resource)) {
                $context['resource'] = $resource;
            }
            $uuidAttr = $request->attributes->get('uuid') ?? $request->attributes->get('id');
            if (\is_string($uuidAttr) && '' !== \trim($uuidAttr)) {
                $context['resource_id'] = \trim($uuidAttr);
            }

            return $this->resolveCurrentPage($context, $locale);
        }

        // 3. Request attributes from Sulu 3 Website routing
        $object = $request->attributes->get('object');
        if ($object instanceof PageInterface || $object instanceof PageDimensionContentInterface) {
            return $this->resolvePage($object, $locale);
        }

        $resource = $request->attributes->get('resource');
        if ($resource instanceof PageInterface || $resource instanceof PageDimensionContentInterface) {
            return $this->resolvePage($resource, $locale);
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

        $clampedLimit = \max(1, \min(100, $limit));

        $criteria = \array_merge($options, [
            'parent_id' => $parentUuid,
            'limit' => $clampedLimit,
        ]);

        if ($excludeCurrent && null !== $currentUuid && '' !== $currentUuid) {
            $criteria['exclude_ids'] = [$currentUuid];
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
        return $this->resolveQuery([
            'parent_id' => $parent,
            'limit' => \max(1, \min(100, $limit > 0 ? $limit : 50)),
            'sort_by' => 'created',
            'sort_order' => 'desc',
        ], $locale);
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
        if (null === $this->pageRepository && null === $this->smartContentBridge) {
            return [];
        }

        $resolvedLocale = $this->resolveLocale($locale);

        /** @var array<string, mixed> $baseFilters */
        $baseFilters = [];

        $uuids = $this->queryFilterNormalizer->normalizeStringList($criteria['uuids'] ?? null);
        if ([] !== $uuids) {
            $baseFilters['uuids'] = $uuids;
        }

        $resolvedParentUuid = null;
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
            $baseFilters['parentId'] = $resolvedParentUuid;
        }

        $descendantOfId = null;
        if (isset($criteria['descendant_of_id']) && \is_string($criteria['descendant_of_id']) && '' !== \trim($criteria['descendant_of_id'])) {
            $descendantOfId = \trim($criteria['descendant_of_id']);
            $baseFilters['descendantOfId'] = $descendantOfId;
        }

        $hasStringCategoryKeys = $this->queryFilterNormalizer->hasStringCategoryKeys($criteria);
        $navigationContexts = $this->queryFilterNormalizer->normalizeStringList($criteria['navigation_contexts'] ?? null);
        $hasCustomDepth = isset($criteria['depth']) && \is_numeric($criteria['depth']) && (int) $criteria['depth'] > 0;
        $bridgeDataSource = $resolvedParentUuid ?? $descendantOfId;

        if (
            [] === $uuids
            && null !== $bridgeDataSource
            && !$hasStringCategoryKeys
            && [] === $navigationContexts
            && !$hasCustomDepth
            && null !== $this->smartContentBridge
            && $this->smartContentBridge->hasProvider('pages')
        ) {
            $smartPlan = $this->queryFilterNormalizer->buildSmartContentQuery($criteria);

            $flatIds = $this->smartContentBridge->queryFlatIds(
                providerType: 'pages',
                config: $smartPlan['bridgeQuery'],
                locale: $resolvedLocale,
                dataSource: $bridgeDataSource,
                includeSubFolders: null === $resolvedParentUuid && null !== $descendantOfId,
            );

            $filteredIds = $this->queryFilterNormalizer->sliceSmartContentIds(
                $flatIds,
                $smartPlan['excludedIds'],
                $smartPlan['limit'],
            );

            return $this->loadAndNormalizePagesByOrderedUuids($filteredIds, $resolvedLocale);
        }

        if (null === $this->pageRepository) {
            return [];
        }

        if (isset($criteria['webspace_key']) && \is_string($criteria['webspace_key']) && '' !== \trim($criteria['webspace_key'])) {
            $baseFilters['webspaceKey'] = \trim($criteria['webspace_key']);
        } else {
            $webspaceKey = $this->resolveCurrentWebspaceKey();
            if (null !== $webspaceKey) {
                $baseFilters['webspaceKey'] = $webspaceKey;
            }
        }

        $queryPlan = $this->queryFilterNormalizer->buildRepositoryQueryPlan(
            criteria: $criteria,
            locale: $resolvedLocale,
            baseFilters: $baseFilters,
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
            $skipped = 0;
            foreach ($pages as $page) {
                if (isset($excludedMap[$page->getUuid()])) {
                    continue;
                }
                if ($skipped < $offset) {
                    ++$skipped;
                    continue;
                }
                $data = $this->normalizePageEntity($page, $resolvedLocale);
                if (null !== $data) {
                    $result[] = $data;
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
    private function normalizePageEntity(PageInterface $page, string $locale): ?array
    {
        $uuid = $page->getUuid();
        $stageKey = $this->isPreviewContext() ? DimensionContentInterface::STAGE_DRAFT : DimensionContentInterface::STAGE_LIVE;
        $cacheKey = '' !== $uuid ? "{$stageKey}:{$locale}:{$uuid}" : null;

        if (null !== $cacheKey && \array_key_exists($cacheKey, $this->resolvedEntityCache)) {
            $cached = $this->resolvedEntityCache[$cacheKey];
            if (null !== $cached) {
                $this->referenceStore?->add($uuid, PageInterface::RESOURCE_KEY);
            }

            return $cached;
        }

        $dimensionContent = $this->extractDimensionContent($page, $locale);
        if (null === $dimensionContent) {
            if (null !== $cacheKey) {
                $this->resolvedEntityCache[$cacheKey] = null;
            }

            return null;
        }

        if ('' !== $uuid) {
            $this->referenceStore?->add($uuid, PageInterface::RESOURCE_KEY);
        }

        $pageData = SuluContentDimensionExtractor::normalizeDimensionContent(
            $uuid,
            $dimensionContent,
            $this->contentResolver,
            includeAuthorName: true,
        );

        $enriched = $this->enrichPageMetadata($pageData, $page, $dimensionContent);
        if (null !== $cacheKey) {
            $this->resolvedEntityCache[$cacheKey] = $enriched;
        }

        return $enriched;
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

        return $pageData;
    }

    private function extractDimensionContent(
        PageInterface $page,
        string $locale,
        bool $allowReload = true,
    ): ?PageDimensionContentInterface {
        $reloadCallback = ($allowReload && null !== $this->pageRepository && '' !== $page->getUuid())
            ? fn (): ?PageInterface => $this->findPageEntityByUuid($page->getUuid(), $locale)
            : null;

        return SuluContentDimensionExtractor::extractDimensionContent(
            $page,
            $locale,
            $this->contentAggregator,
            $this->isPreviewContext(),
            $reloadCallback,
        );
    }

    private function findPageEntityByUuid(string $uuid, string $locale): ?PageInterface
    {
        $trimId = \trim($uuid);
        if ('' === $trimId || null === $this->pageRepository) {
            return null;
        }

        $webspaceKey = $this->resolveCurrentWebspaceKey();

        try {
            $liveFilters = [
                'uuid' => $trimId,
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_LIVE,
            ];
            if (null !== $webspaceKey) {
                $liveFilters['webspaceKey'] = $webspaceKey;
            }

            $pageEntity = $this->pageRepository->findOneBy(
                $liveFilters,
                [PageRepositoryInterface::GROUP_SELECT_PAGE_WEBSITE => true],
            );

            if (null !== $pageEntity) {
                return $pageEntity;
            }

            if (!$this->isPreviewContext()) {
                return null;
            }

            $draftFilters = [
                'uuid' => $trimId,
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ];
            if (null !== $webspaceKey) {
                $draftFilters['webspaceKey'] = $webspaceKey;
            }

            return $this->pageRepository->findOneBy(
                $draftFilters,
                [PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true],
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveCurrentWebspaceKey(): ?string
    {
        if (null === $this->requestAnalyzer) {
            return null;
        }

        $webspaceKey = $this->requestAnalyzer->getWebspace()?->getKey();
        if (\is_string($webspaceKey) && '' !== \trim($webspaceKey)) {
            return \trim($webspaceKey);
        }

        return null;
    }

    private function isPreviewContext(): bool
    {
        return SuluContentDimensionExtractor::isPreviewContext($this->requestStack);
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
        return SuluContentDimensionExtractor::firstNonEmptyString(...$candidates);
    }

    private function resolveSummaryWithContentFallback(
        mixed $summary,
        mixed $description,
        mixed $excerptDescription,
        mixed $content,
    ): ?string {
        return SuluContentDimensionExtractor::resolveSummaryWithContentFallback(
            $summary,
            $description,
            $excerptDescription,
            $content,
        );
    }

    private function firstNonEmptyMedia(mixed ...$candidates): mixed
    {
        return SuluContentDimensionExtractor::firstNonEmptyMedia(...$candidates);
    }

    /**
     * @param list<string> $uuids
     *
     * @return list<array<string, mixed>>
     */
    private function loadAndNormalizePagesByOrderedUuids(array $uuids, string $locale): array
    {
        if ([] === $uuids) {
            return [];
        }

        $loaded = null !== $this->smartContentBridge
            ? $this->smartContentBridge->loadOrderedResources('pages', $uuids, $locale)
            : [];

        if ([] === $loaded && null !== $this->pageRepository) {
            try {
                /** @var iterable<PageInterface> $pages */
                $pages = $this->pageRepository->findBy(
                    [
                        'uuids' => $uuids,
                        'locale' => $locale,
                        'stage' => DimensionContentInterface::STAGE_LIVE,
                    ],
                    [],
                    [PageRepositoryInterface::GROUP_SELECT_PAGE_WEBSITE => true],
                );

                $byUuid = [];
                foreach ($pages as $page) {
                    $byUuid[$page->getUuid()] = $page;
                }

                foreach ($uuids as $uuid) {
                    if (isset($byUuid[$uuid])) {
                        $loaded[] = $byUuid[$uuid];
                    }
                }
            } catch (\Throwable) {
                return [];
            }
        }

        $result = [];
        foreach ($loaded as $resource) {
            if ($resource instanceof PageInterface) {
                $normalized = $this->normalizePageEntity($resource, $locale);
                if (null !== $normalized) {
                    $result[] = $normalized;
                }
            }
        }

        return $result;
    }
}
