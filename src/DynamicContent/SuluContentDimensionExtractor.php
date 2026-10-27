<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent;

use Sulu\Content\Application\ContentAggregator\ContentAggregatorInterface;
use Sulu\Content\Application\ContentResolver\ContentResolverInterface;
use Sulu\Content\Domain\Model\AuthorInterface;
use Sulu\Content\Domain\Model\ContentRichEntityInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\ExcerptInterface;
use Sulu\Content\Domain\Model\RoutableInterface;
use Sulu\Content\Domain\Model\TaxonomyInterface;
use Sulu\Content\Domain\Model\TemplateInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Deep module unifying Sulu ContentRichEntity dimension resolution, ContentResolver normalization,
 * and excerpt/taxonomy/author/route metadata extraction across Page and Article source providers.
 */
final class SuluContentDimensionExtractor
{
    /**
     * @template T of DimensionContentInterface
     *
     * @param ContentRichEntityInterface<T> $entity
     * @param (callable(): (T|ContentRichEntityInterface<T>|null))|null $missingLocaleReloadCallback
     *
     * @return T|null
     */
    public static function extractDimensionContent(
        ContentRichEntityInterface $entity,
        string $locale,
        ?ContentAggregatorInterface $contentAggregator = null,
        bool $isPreview = false,
        ?callable $missingLocaleReloadCallback = null,
    ): ?DimensionContentInterface {
        if (null !== $contentAggregator) {
            try {
                /** @var T $aggregated */
                $aggregated = $contentAggregator->aggregate($entity, [
                    'locale' => $locale,
                    'stage' => DimensionContentInterface::STAGE_LIVE,
                ]);

                return $aggregated;
            } catch (\Throwable) {
                if ($isPreview) {
                    try {
                        /** @var T $aggregatedDraft */
                        $aggregatedDraft = $contentAggregator->aggregate($entity, [
                            'locale' => $locale,
                            'stage' => DimensionContentInterface::STAGE_DRAFT,
                        ]);

                        return $aggregatedDraft;
                    } catch (\Throwable) {
                        // Fall back to direct dimension inspection
                    }
                }
            }
        }

        /** @var iterable<T> $dimensionContents */
        $dimensionContents = $entity->getDimensionContents();

        $draftFallback = null;
        foreach ($dimensionContents as $content) {
            if (DimensionContentInterface::CURRENT_VERSION !== $content->getVersion()) {
                continue;
            }
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

        if (null !== $draftFallback) {
            return $draftFallback;
        }

        if (null !== $missingLocaleReloadCallback) {
            $reloaded = $missingLocaleReloadCallback();
            if ($reloaded instanceof ContentRichEntityInterface) {
                /** @var ContentRichEntityInterface<T> $reloaded */
                return self::extractDimensionContent($reloaded, $locale, $contentAggregator, $isPreview, null);
            }
            if ($reloaded instanceof DimensionContentInterface) {
                /** @var T $reloaded */
                return $reloaded;
            }
        }

        foreach ($dimensionContents as $content) {
            if (DimensionContentInterface::CURRENT_VERSION !== $content->getVersion() || null !== $content->getLocale()) {
                continue;
            }
            $title = \method_exists($content, 'getTitle') ? $content->getTitle() : null;
            $templateData = $content instanceof TemplateInterface ? $content->getTemplateData() : [];
            if (null === $title && [] === $templateData) {
                continue;
            }
            $stage = $content->getStage();
            if (DimensionContentInterface::STAGE_LIVE === $stage || '' === $stage) {
                return $content;
            }
            if ($isPreview && DimensionContentInterface::STAGE_DRAFT === $stage) {
                $draftFallback ??= $content;
            }
        }

        return $draftFallback;
    }

    /**
     * @template TEntity of ContentRichEntityInterface
     *
     * @param DimensionContentInterface<TEntity> $dimensionContent
     *
     * @return array<string, mixed>
     */
    public static function normalizeDimensionContent(
        string $uuid,
        DimensionContentInterface $dimensionContent,
        ?ContentResolverInterface $contentResolver = null,
        bool $includeAuthorName = false,
        ?string $parentUuid = null,
    ): array {
        $entityTitle = \method_exists($dimensionContent, 'getTitle')
            ? $dimensionContent->getTitle()
            : null;
        $entityTitle = \is_string($entityTitle) ? $entityTitle : null;

        if (null !== $contentResolver) {
            try {
                $resolved = $contentResolver->resolve($dimensionContent);
                /** @var array<string, mixed> $data */
                $data = $resolved['content'];
                $data['id'] = $uuid;
                $data['uuid'] = $uuid;

                if (null !== $entityTitle) {
                    $data['title'] = $entityTitle;
                }
                $data['headline'] = self::firstNonEmptyString($data['headline'] ?? null, $data['title'] ?? null);

                /** @var mixed $excerptDesc */
                $excerptDesc = null;
                /** @var mixed $excerptImage */
                $excerptImage = null;
                $excerpt = $resolved['extension']['excerpt'] ?? null;
                if (\is_array($excerpt)) {
                    $excerptDesc = $excerpt['description'] ?? null;
                    $excerptImage = $excerpt['image'] ?? null;
                }

                $rawSummary = $data['summary'] ?? null;
                $rawDescription = $data['description'] ?? null;
                $rawBodyContent = self::firstNonEmptyString($data['content'] ?? null, $data['article'] ?? null);
                if (null !== $rawBodyContent && !isset($data['content'])) {
                    $data['content'] = $rawBodyContent;
                }

                $data['summary'] = self::resolveSummaryWithContentFallback($rawSummary, $rawDescription, $excerptDesc, $rawBodyContent);
                $data['description'] = self::resolveSummaryWithContentFallback($rawDescription, $rawSummary, $excerptDesc, $rawBodyContent);
                $data['image'] = self::firstNonEmptyMedia($data['image'] ?? null, $excerptImage);

                if (isset($resolved['view']['url']) && \is_string($resolved['view']['url'])) {
                    $data['url'] = $resolved['view']['url'];
                    $data['link'] = $resolved['view']['url'];
                }

                if ($dimensionContent instanceof AuthorInterface) {
                    if (null !== $dimensionContent->getAuthored()) {
                        $data['authored'] = $dimensionContent->getAuthored()->format('c');
                        $data['date'] = $data['authored'];
                    }
                    if ($includeAuthorName && null !== $dimensionContent->getAuthor()) {
                        $data['author'] = $dimensionContent->getAuthor();
                    }
                }

                return self::enrichMetadata($data, $dimensionContent, $parentUuid);
            } catch (\Throwable) {
                // Fallback to direct dimension getters below
            }
        }

        /** @var array<string, mixed> $data */
        $data = $dimensionContent instanceof TemplateInterface ? $dimensionContent->getTemplateData() : [];
        $data['id'] = $uuid;
        $data['uuid'] = $uuid;
        $data['title'] = $entityTitle;
        $data['headline'] = self::firstNonEmptyString($data['headline'] ?? null, $entityTitle);

        $excerptDesc = $dimensionContent instanceof ExcerptInterface ? $dimensionContent->getExcerptDescription() : null;
        $excerptImage = $dimensionContent instanceof ExcerptInterface ? $dimensionContent->getExcerptImage() : null;
        $rawSummary = $data['summary'] ?? null;
        $rawDescription = $data['description'] ?? null;
        $rawBodyContent = self::firstNonEmptyString($data['content'] ?? null, $data['article'] ?? null);
        if (null !== $rawBodyContent && !isset($data['content'])) {
            $data['content'] = $rawBodyContent;
        }

        $data['summary'] = self::resolveSummaryWithContentFallback($rawSummary, $rawDescription, $excerptDesc, $rawBodyContent);
        $data['description'] = self::resolveSummaryWithContentFallback($rawDescription, $rawSummary, $excerptDesc, $rawBodyContent);
        $data['image'] = self::firstNonEmptyMedia($data['image'] ?? null, $excerptImage);

        if ($dimensionContent instanceof RoutableInterface) {
            $route = $dimensionContent->getRoute();
            if (null !== $route) {
                $data['url'] = $route->getSlug();
                $data['link'] = $route->getSlug();
            }
        }

        if ($dimensionContent instanceof AuthorInterface) {
            if (null !== $dimensionContent->getAuthored()) {
                $data['authored'] = $dimensionContent->getAuthored()->format('c');
                $data['date'] = $data['authored'];
            }
            if ($includeAuthorName && null !== $dimensionContent->getAuthor()) {
                $data['author'] = $dimensionContent->getAuthor();
            }
        }

        return self::enrichMetadata($data, $dimensionContent, $parentUuid);
    }

    /**
     * @template TEntity of ContentRichEntityInterface
     *
     * @param array<string, mixed> $data
     * @param DimensionContentInterface<TEntity> $dimensionContent
     *
     * @return array<string, mixed>
     */
    public static function enrichMetadata(
        array $data,
        DimensionContentInterface $dimensionContent,
        ?string $parentUuid = null,
    ): array {
        if (null !== $parentUuid && '' !== $parentUuid) {
            $data['parent_id'] ??= $parentUuid;
        }

        if ($dimensionContent instanceof TemplateInterface) {
            $templateKey = $dimensionContent->getTemplateKey();
            if (null !== $templateKey && '' !== $templateKey) {
                $data['template'] ??= $templateKey;
            }
        }

        if ($dimensionContent instanceof TaxonomyInterface) {
            $tagNames = $dimensionContent->getExcerptTagNames();
            if ([] !== $tagNames) {
                $data['tags'] ??= \array_values($tagNames);
                $data['badge'] ??= $tagNames[\array_key_first($tagNames)];
            }

            $categoryIds = $dimensionContent->getExcerptCategoryIds();
            if ([] !== $categoryIds) {
                $data['categories'] ??= \array_values($categoryIds);
            }
        }

        return $data;
    }

    public static function isPreviewContext(?RequestStack $requestStack): bool
    {
        $request = $requestStack?->getCurrentRequest();
        if (null === $request) {
            return false;
        }

        return $request->attributes->getBoolean('preview')
            || $request->attributes->getBoolean('_sulu_preview');
    }

    public static function firstNonEmptyString(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (\is_string($candidate) && '' !== \trim($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function resolveSummaryWithContentFallback(
        mixed $summary,
        mixed $description,
        mixed $excerptDescription,
        mixed $content,
    ): ?string {
        $resolved = self::firstNonEmptyString($summary, $description, $excerptDescription);
        if (null !== $resolved) {
            return $resolved;
        }

        if (\is_string($content) && '' !== \trim($content)) {
            $truncated = DynamicContentMappingApplier::truncatePlainText($content, 160);
            if ('' !== $truncated) {
                return $truncated;
            }
        }

        return null;
    }

    public static function firstNonEmptyMedia(mixed ...$candidates): mixed
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
