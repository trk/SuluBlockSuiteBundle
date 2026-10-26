<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Preview;

use Sulu\Content\Application\ContentAggregator\ContentAggregatorInterface;
use Sulu\Content\Application\ContentResolver\ContentResolverInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface;
use Totoglu\SuluBlockSuiteBundle\Snippet\SnippetScopeResolver;
use Totoglu\SuluBlockSuiteBundle\Template\TemplateKeys;

/**
 * Deep module encapsulating Sulu snippet Live Preview parameter enrichment and automatic
 * preview page resolution for `page_layout` and `reusable_block` snippets.
 */
final class PageLayoutPreviewResolver
{
    /**
     * @param array<string, mixed> $snippetParameters
     *
     * @return array<string, mixed>
     */
    public static function enrichPreviewParameters(
        SnippetDimensionContentInterface $object,
        array $snippetParameters,
        string $webspaceKey,
        ?PageRepositoryInterface $pageRepository = null,
        ?ContentAggregatorInterface $contentAggregator = null,
        ?ContentResolverInterface $contentResolver = null,
    ): array {
        $templateKey = $object->getTemplateKey();

        if (TemplateKeys::PAGE_LAYOUT === $templateKey) {
            $rawTemplateData = self::extractMergedDraftTemplateData($object);
            $locale = $object->getLocale() ?? 'en';

            /** @var array<string, mixed> $layoutContent */
            $layoutContent = isset($snippetParameters['content']) && \is_array($snippetParameters['content'])
                ? $snippetParameters['content']
                : [];

            foreach (['webspace', 'target_template'] as $unlocalizedKey) {
                if (!\array_key_exists($unlocalizedKey, $layoutContent) && \array_key_exists($unlocalizedKey, $rawTemplateData)) {
                    $layoutContent[$unlocalizedKey] = $rawTemplateData[$unlocalizedKey];
                }
            }

            $layoutUuid = $object->getResource()->getUuid();
            $layoutContent['id'] ??= $layoutUuid;
            $layoutContent['uuid'] ??= $layoutUuid;
            $layoutContent['title'] ??= $object->getTitle() ?? $layoutUuid;
            $layoutContent['blocks'] = isset($layoutContent['blocks']) && \is_array($layoutContent['blocks'])
                ? $layoutContent['blocks']
                : [];
            $layoutContent['_preview_bypass_webspace'] = true;

            $pageResolved = self::resolvePreviewPageParameters(
                $layoutUuid,
                $rawTemplateData,
                $locale,
                $webspaceKey,
                $pageRepository,
                $contentAggregator,
                $contentResolver,
            );

            if (null !== $pageResolved) {
                /** @var array<string, mixed> $pageContent */
                $pageContent = isset($pageResolved['content']) && \is_array($pageResolved['content'])
                    ? $pageResolved['content']
                    : [];
                $pageContent['layout'] = $layoutContent;

                return \array_merge($pageResolved, [
                    'content' => $pageContent,
                    'page' => $pageContent,
                    'layout' => $layoutContent,
                    'blocks' => $layoutContent['blocks'],
                    'localizations' => $snippetParameters['localizations'] ?? [],
                    '_is_page_layout_preview' => true,
                ]);
            }

            return \array_merge($snippetParameters, [
                'content' => [
                    'title' => $layoutContent['title'],
                    'layout' => $layoutContent,
                    'blocks' => [],
                ],
                'page' => $layoutContent,
                'layout' => $layoutContent,
                'blocks' => $layoutContent['blocks'],
                '_is_page_layout_preview' => true,
            ]);
        }

        if (TemplateKeys::REUSABLE_BLOCK === $templateKey) {
            /** @var array<string, mixed> $reusableContent */
            $reusableContent = isset($snippetParameters['content']) && \is_array($snippetParameters['content'])
                ? $snippetParameters['content']
                : [];
            $reusableContent['layout'] = null;
            $snippetParameters['content'] = $reusableContent;
            $snippetParameters['page'] = $reusableContent;
            $snippetParameters['layout'] = null;
        }

        return $snippetParameters;
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractMergedDraftTemplateData(SnippetDimensionContentInterface $object): array
    {
        try {
            return SnippetScopeResolver::getMergedTemplateData(
                $object->getResource(),
                $object,
                DimensionContentInterface::STAGE_DRAFT,
            );
        } catch (\Throwable) {
            /** @var array<string, mixed> $localizedData */
            $localizedData = $object->getTemplateData();

            return $localizedData;
        }
    }

    /**
     * @param array<string, mixed> $rawTemplateData
     *
     * @return array<string, mixed>|null
     */
    private static function resolvePreviewPageParameters(
        string $layoutUuid,
        array $rawTemplateData,
        string $locale,
        string $fallbackWebspaceKey,
        ?PageRepositoryInterface $pageRepository,
        ?ContentAggregatorInterface $contentAggregator,
        ?ContentResolverInterface $contentResolver,
    ): ?array {
        if (null === $pageRepository || null === $contentAggregator || null === $contentResolver) {
            return null;
        }

        $page = self::findPreviewPage($layoutUuid, $rawTemplateData, $locale, $fallbackWebspaceKey, $pageRepository);
        if (null === $page) {
            return null;
        }

        $pageDimensionContent = self::aggregatePageDimensionContent($page, $locale, $contentAggregator);
        if (null === $pageDimensionContent) {
            return null;
        }

        try {
            /** @var array<string, mixed> $resolved */
            $resolved = $contentResolver->resolve($pageDimensionContent);

            return $resolved;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $rawTemplateData
     */
    private static function findPreviewPage(
        string $layoutUuid,
        array $rawTemplateData,
        string $locale,
        string $fallbackWebspaceKey,
        PageRepositoryInterface $pageRepository,
    ): ?PageInterface {
        $rawTargetTemplate = $rawTemplateData['target_template'] ?? ($rawTemplateData['page_template'] ?? null);
        $targetTemplate = \is_string($rawTargetTemplate) ? \trim($rawTargetTemplate) : '';

        $rawWebspace = $rawTemplateData['webspace'] ?? null;
        $targetWebspace = \is_string($rawWebspace) && '' !== \trim($rawWebspace) && '_global' !== \trim($rawWebspace)
            ? \trim($rawWebspace)
            : ('' !== \trim($fallbackWebspaceKey) ? \trim($fallbackWebspaceKey) : null);

        if ('' !== $targetTemplate) {
            foreach ([DimensionContentInterface::STAGE_LIVE, DimensionContentInterface::STAGE_DRAFT] as $stage) {
                try {
                    $candidates = $pageRepository->findBy(
                        [
                            'templateKeys' => [$targetTemplate],
                            'locale' => $locale,
                            'stage' => $stage,
                            'limit' => 20,
                        ],
                        ['id' => 'asc'],
                        [PageRepositoryInterface::SELECT_PAGE_CONTENT => []],
                    );

                    $firstCandidate = null;
                    foreach ($candidates as $candidate) {
                        $assignedLayoutUuid = self::extractPageAssignedLayoutUuid($candidate, $locale, $stage);
                        if (null !== $assignedLayoutUuid && $assignedLayoutUuid !== $layoutUuid) {
                            continue;
                        }

                        $firstCandidate ??= $candidate;
                        if (null === $targetWebspace || $candidate->getWebspaceKey() === $targetWebspace) {
                            return $candidate;
                        }
                    }

                    if (null !== $firstCandidate) {
                        return $firstCandidate;
                    }
                } catch (\Throwable) {
                    // Continue fallback
                }
            }
        }

        if ('' !== $layoutUuid) {
            foreach ([DimensionContentInterface::STAGE_LIVE, DimensionContentInterface::STAGE_DRAFT] as $stage) {
                try {
                    $candidates = $pageRepository->findBy(
                        [
                            'locale' => $locale,
                            'stage' => $stage,
                            'limit' => 50,
                        ],
                        ['id' => 'asc'],
                        [PageRepositoryInterface::SELECT_PAGE_CONTENT => []],
                    );

                    $firstCandidate = null;
                    foreach ($candidates as $candidate) {
                        $assignedLayoutUuid = self::extractPageAssignedLayoutUuid($candidate, $locale, $stage);
                        if ($assignedLayoutUuid !== $layoutUuid) {
                            continue;
                        }

                        $firstCandidate ??= $candidate;
                        if (null === $targetWebspace || $candidate->getWebspaceKey() === $targetWebspace) {
                            return $candidate;
                        }
                    }

                    if (null !== $firstCandidate) {
                        return $firstCandidate;
                    }
                } catch (\Throwable) {
                    // Continue fallback
                }
            }
        }

        return null;
    }

    private static function extractPageAssignedLayoutUuid(
        PageInterface $page,
        string $locale,
        string $stage,
    ): ?string {
        try {
            /** @var array<string, mixed> $unlocalizedData */
            $unlocalizedData = [];
            /** @var array<string, mixed> $localizedData */
            $localizedData = [];

            foreach ($page->getDimensionContents() as $dim) {
                if (DimensionContentInterface::CURRENT_VERSION !== $dim->getVersion()) {
                    continue;
                }

                $dimStage = $dim->getStage();
                $matchesStage = $dimStage === $stage
                    || (DimensionContentInterface::STAGE_LIVE === $stage && '' === $dimStage);
                if (!$matchesStage) {
                    continue;
                }

                if (null === $dim->getLocale()) {
                    $unlocalizedData = $dim->getTemplateData();
                } elseif ($dim->getLocale() === $locale) {
                    $localizedData = $dim->getTemplateData();
                }
            }

            $merged = \array_replace($unlocalizedData, $localizedData);
            $rawLayout = $merged['layout'] ?? null;

            if (\is_string($rawLayout) && '' !== \trim($rawLayout)) {
                return \trim($rawLayout);
            }

            if (\is_array($rawLayout)) {
                $candidateId = $rawLayout['id'] ?? ($rawLayout['uuid'] ?? null);
                if (\is_string($candidateId) && '' !== \trim($candidateId)) {
                    return \trim($candidateId);
                }
            }
        } catch (\Throwable) {
            // Ignore if page dimension contents are unavailable
        }

        return null;
    }

    /**
     * @return DimensionContentInterface<PageInterface>|null
     */
    private static function aggregatePageDimensionContent(
        PageInterface $page,
        string $locale,
        ContentAggregatorInterface $contentAggregator,
    ): ?DimensionContentInterface {
        foreach ([DimensionContentInterface::STAGE_LIVE, DimensionContentInterface::STAGE_DRAFT] as $stage) {
            try {
                $aggregated = $contentAggregator->aggregate(
                    $page,
                    [
                        'locale' => $locale,
                        'stage' => $stage,
                    ],
                );

                if (null !== $aggregated->getTemplateKey()) {
                    return $aggregated;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
