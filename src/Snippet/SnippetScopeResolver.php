<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Snippet;

use Sulu\Component\Webspace\Analyzer\Attributes\RequestAttributes;
use Sulu\Component\Webspace\Analyzer\RequestAnalyzerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Deep module encapsulating Sulu snippet dimension lookup, unlocalized/localized templateData
 * merging, active webspace resolution, and multi-webspace scope matching.
 */
final class SnippetScopeResolver
{
    public static function findDimensionContent(
        SnippetInterface $snippet,
        ?string $templateKey,
        string $locale,
        string $preferredStage = DimensionContentInterface::STAGE_LIVE,
        bool $allowStageFallback = false,
    ): ?SnippetDimensionContentInterface {
        /** @var iterable<SnippetDimensionContentInterface> $dimensionContents */
        $dimensionContents = $snippet->getDimensionContents();

        $fallbackLocalized = null;
        $fallbackUnlocalized = null;

        foreach ($dimensionContents as $content) {
            if (DimensionContentInterface::CURRENT_VERSION !== $content->getVersion()) {
                continue;
            }

            if (null !== $templateKey && $templateKey !== $content->getTemplateKey()) {
                continue;
            }

            $contentLocale = $content->getLocale();
            $stage = $content->getStage();
            $matchesStage = $preferredStage === $stage
                || (DimensionContentInterface::STAGE_LIVE === $preferredStage && '' === $stage);

            if ($contentLocale === $locale) {
                if ($matchesStage) {
                    return $content;
                }

                if ($allowStageFallback && null === $fallbackLocalized) {
                    $fallbackLocalized = $content;
                }
            }
        }

        if (null !== $fallbackLocalized) {
            return $fallbackLocalized;
        }

        foreach ($dimensionContents as $content) {
            if (DimensionContentInterface::CURRENT_VERSION !== $content->getVersion()) {
                continue;
            }

            if (null !== $templateKey && $templateKey !== $content->getTemplateKey()) {
                continue;
            }

            $contentLocale = $content->getLocale();
            if (null !== $contentLocale && '' !== $contentLocale) {
                continue;
            }

            $stage = $content->getStage();
            $matchesStage = $preferredStage === $stage
                || (DimensionContentInterface::STAGE_LIVE === $preferredStage && '' === $stage);

            if ($matchesStage) {
                return $content;
            }

            if ($allowStageFallback && null === $fallbackUnlocalized) {
                $fallbackUnlocalized = $content;
            }
        }

        return $fallbackUnlocalized;
    }

    /**
     * Merges unlocalized dimension `templateData` (`locale === null`) with localized `templateData`
     * so unlocalized properties (`webspace`, `target_template`) are always preserved alongside localized fields.
     *
     * @return array<string, mixed>
     */
    public static function getMergedTemplateData(
        SnippetInterface $snippet,
        ?SnippetDimensionContentInterface $localizedContent = null,
        string $preferredStage = DimensionContentInterface::STAGE_LIVE,
    ): array {
        /** @var array<string, mixed> $unlocalizedData */
        $unlocalizedData = [];
        /** @var iterable<SnippetDimensionContentInterface> $dimensionContents */
        $dimensionContents = $snippet->getDimensionContents();

        foreach ($dimensionContents as $content) {
            if (DimensionContentInterface::CURRENT_VERSION !== $content->getVersion()) {
                continue;
            }

            $contentLocale = $content->getLocale();
            if (null !== $contentLocale && '' !== $contentLocale) {
                continue;
            }

            $stage = $content->getStage();
            $matchesPreferredStage = $preferredStage === $stage
                || (DimensionContentInterface::STAGE_LIVE === $preferredStage && '' === $stage);

            if ($matchesPreferredStage) {
                $unlocalizedData = $content->getTemplateData();
                break;
            }

            if ([] === $unlocalizedData) {
                $unlocalizedData = $content->getTemplateData();
            }
        }

        /** @var array<string, mixed> $localizedData */
        $localizedData = null !== $localizedContent ? $localizedContent->getTemplateData() : [];

        return \array_replace($unlocalizedData, $localizedData);
    }

    /**
     * @param array<string, mixed> $templateData
     */
    public static function extractWebspace(array $templateData, string $default = '_global'): string
    {
        $raw = $templateData['webspace'] ?? null;
        if (\is_scalar($raw)) {
            $trimmed = \trim((string) $raw);
            if ('' !== $trimmed) {
                return $trimmed;
            }
        }

        return $default;
    }

    public static function matchesWebspace(
        string $snippetWebspace,
        ?string $activeWebspace,
        bool $multiWebspaceEnabled = true,
    ): bool {
        if (!$multiWebspaceEnabled) {
            return true;
        }

        $normalizedActive = null !== $activeWebspace ? \trim($activeWebspace) : '';
        if ('' === $normalizedActive || '_global' === $normalizedActive) {
            return true;
        }

        $normalizedSnippet = \trim($snippetWebspace);
        if ('' === $normalizedSnippet || '_global' === $normalizedSnippet) {
            return true;
        }

        return $normalizedSnippet === $normalizedActive;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function resolveActiveWebspace(
        ?string $explicitWebspace = null,
        array $context = [],
        ?RequestStack $requestStack = null,
        ?RequestAnalyzerInterface $requestAnalyzer = null,
        bool $multiWebspaceEnabled = true,
    ): ?string {
        if (!$multiWebspaceEnabled) {
            return null;
        }

        if (null !== $explicitWebspace && '' !== \trim($explicitWebspace)) {
            return \trim($explicitWebspace);
        }

        foreach (['_webspace', 'webspaceKey', 'webspace'] as $ctxKey) {
            $ctxVal = $context[$ctxKey] ?? null;
            if (\is_string($ctxVal) && '' !== \trim($ctxVal)) {
                return \trim($ctxVal);
            }
            if (\is_array($ctxVal) && isset($ctxVal['key']) && \is_string($ctxVal['key']) && '' !== \trim($ctxVal['key'])) {
                return \trim($ctxVal['key']);
            }
            if (\is_object($ctxVal) && \method_exists($ctxVal, 'getKey')) {
                $key = $ctxVal->getKey();
                if (\is_string($key) && '' !== \trim($key)) {
                    return \trim($key);
                }
            }
        }

        if (null !== $requestAnalyzer) {
            try {
                $webspace = $requestAnalyzer->getWebspace();
                if (null !== $webspace && '' !== \trim($webspace->getKey())) {
                    return \trim($webspace->getKey());
                }
            } catch (\Throwable) {
                // Ignore if request analyzer is not initialized
            }
        }

        if (null !== $requestStack) {
            $request = $requestStack->getCurrentRequest();
            if (null !== $request) {
                $suluAttr = $request->attributes->get('_sulu');
                if ($suluAttr instanceof RequestAttributes) {
                    $webspace = $suluAttr->getAttribute('webspace');
                    if (\is_object($webspace) && \method_exists($webspace, 'getKey')) {
                        $key = $webspace->getKey();
                        if (\is_string($key) && '' !== \trim($key)) {
                            return \trim($key);
                        }
                    }
                    $attrKey = $suluAttr->getAttribute('webspaceKey');
                    if (\is_string($attrKey) && '' !== \trim($attrKey)) {
                        return \trim($attrKey);
                    }
                }

                foreach (['webspaceKey', 'webspace'] as $paramName) {
                    $attr = $request->attributes->get($paramName);
                    if (\is_string($attr) && '' !== \trim($attr)) {
                        return \trim($attr);
                    }
                    $queryVal = $request->query->get($paramName);
                    if (\is_string($queryVal) && '' !== \trim($queryVal)) {
                        return \trim($queryVal);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Resolves a layout candidate (string identifier, pre-loaded snippet array, or snippet UUID reference)
     * with webspace scope validation and optional default layout fallback.
     *
     * @param string|array<string, mixed>|null $layout
     * @param callable(string, ?string, ?string): (array<string, mixed>|null) $layoutLoader
     *
     * @return array<string, mixed>|null
     */
    public static function resolveLayoutCandidate(
        string|array|null $layout,
        ?string $defaultLayout,
        ?string $locale,
        ?string $resolvedWebspace,
        bool $multiWebspaceEnabled,
        callable $layoutLoader,
    ): ?array {
        $layoutData = null;

        if (\is_string($layout) && '' !== \trim($layout)) {
            $layoutData = $layoutLoader($layout, $locale, $resolvedWebspace);
        } elseif (\is_array($layout) && [] !== $layout) {
            /** @var array<string, mixed> $inner */
            $inner = isset($layout['content']) && \is_array($layout['content']) ? $layout['content'] : $layout;

            if (isset($inner['blocks']) && \is_array($inner['blocks'])) {
                $inlineWebspace = self::extractWebspace($inner, '');
                $webspaceMatches = true === ($inner['_preview_bypass_webspace'] ?? false)
                    || self::matchesWebspace($inlineWebspace, $resolvedWebspace, $multiWebspaceEnabled);

                if ($webspaceMatches) {
                    $layoutData = $inner;
                }
            } else {
                $layoutId = $inner['id'] ?? ($inner['uuid'] ?? null);
                if (\is_string($layoutId) && '' !== \trim($layoutId)) {
                    $layoutData = $layoutLoader($layoutId, $locale, $resolvedWebspace);
                }
            }
        }

        if (null === $layoutData && null !== $defaultLayout && '' !== \trim($defaultLayout)) {
            $layoutData = $layoutLoader($defaultLayout, $locale, $resolvedWebspace);
        }

        return \is_array($layoutData) ? $layoutData : null;
    }
}
