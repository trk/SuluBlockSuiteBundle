<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Navigation;

use Totoglu\SuluBlockSuiteBundle\Normalizer\SuluDataNormalizer;
use Totoglu\SuluBlockSuiteBundle\Util\PropReader;

/**
 * Encapsulates Sulu request-path resolution, locale-prefix canonicalization,
 * and active/ancestor URL matching for block and navigation components.
 */
class ActiveUrlMatcher
{
    protected function __construct()
    {
    }

    /**
     * Resolves the current request path from an explicit argument, component context/props, or Symfony Request.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $props
     */
    public static function resolveCurrentRequestPath(
        array $context,
        array $props,
        ?string $explicitCurrentPath = null,
    ): string {
        if (null !== $explicitCurrentPath && '' !== \trim($explicitCurrentPath)) {
            return \trim($explicitCurrentPath);
        }

        $ctxPath = $context['current_path'] ?? ($props['current_path'] ?? null);
        if (\is_string($ctxPath) && '' !== \trim($ctxPath)) {
            return \trim($ctxPath);
        }

        $app = $context['app'] ?? null;
        if (\is_object($app) && \method_exists($app, 'getRequest')) {
            /** @var mixed $request */
            $request = $app->getRequest();
            if (\is_object($request) && \method_exists($request, 'getPathInfo')) {
                /** @var mixed $pathInfo */
                $pathInfo = $request->getPathInfo();
                if (\is_string($pathInfo) && '' !== \trim($pathInfo)) {
                    return \trim($pathInfo);
                }
            }
        }

        $suluReq = $context['request'] ?? null;
        if (\is_array($suluReq) && \is_string($suluReq['resourceLocator'] ?? null)) {
            $prefix = \is_string($suluReq['resourceLocatorPrefix'] ?? null) ? \rtrim($suluReq['resourceLocatorPrefix'], '/') : '';
            $locator = '/' . \ltrim($suluReq['resourceLocator'], '/');

            return '' !== $prefix ? ('/' === $locator ? $prefix : $prefix . $locator) : $locator;
        }

        return '';
    }

    /**
     * Resolves the Sulu content root URL (e.g. `/en` or `/`) from an explicit argument, context/props, or Symfony Request.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $props
     */
    public static function resolveRootUrl(
        array $context,
        array $props,
        ?string $explicitRootUrl = null,
    ): string {
        if (null !== $explicitRootUrl && '' !== \trim($explicitRootUrl)) {
            $cleanExplicit = SuluDataNormalizer::sanitizeUrl(\trim($explicitRootUrl), '/');
            if ('/' !== $cleanExplicit && '#' !== $cleanExplicit && '' !== $cleanExplicit && !self::isExternalOrNonPageUrl($cleanExplicit)) {
                return '/' . \trim($cleanExplicit, '/');
            }
        }

        $ctxRoot = $context['root_url'] ?? ($props['root_url'] ?? null);
        if (\is_string($ctxRoot) && '' !== \trim($ctxRoot)) {
            $cleanCtx = SuluDataNormalizer::sanitizeUrl(\trim($ctxRoot), '/');
            if ('/' !== $cleanCtx && '#' !== $cleanCtx && '' !== $cleanCtx && !self::isExternalOrNonPageUrl($cleanCtx)) {
                return '/' . \trim($cleanCtx, '/');
            }
        }

        $suluReq = $context['request'] ?? null;
        if (\is_array($suluReq) && \is_string($suluReq['resourceLocatorPrefix'] ?? null)) {
            $prefix = \trim($suluReq['resourceLocatorPrefix'], '/');
            if ('' !== $prefix) {
                return '/' . $prefix;
            }
        }

        $app = $context['app'] ?? null;
        if (\is_object($app) && \method_exists($app, 'getRequest')) {
            /** @var mixed $request */
            $request = $app->getRequest();
            if (\is_object($request)) {
                if (\property_exists($request, 'attributes') && \is_object($request->attributes) && \method_exists($request->attributes, 'get')) {
                    /** @var mixed $suluAttrs */
                    $suluAttrs = $request->attributes->get('_sulu');
                    if (\is_object($suluAttrs) && \method_exists($suluAttrs, 'getAttribute')) {
                        /** @var mixed $portalInfo */
                        $portalInfo = $suluAttrs->getAttribute('portalInformation');
                        if (\is_object($portalInfo) && \method_exists($portalInfo, 'getPrefix')) {
                            /** @var mixed $portalPrefix */
                            $portalPrefix = $portalInfo->getPrefix();
                            if (\is_string($portalPrefix) && '' !== \trim($portalPrefix, '/')) {
                                return '/' . \trim($portalPrefix, '/');
                            }
                        }
                    }
                }

                if (\method_exists($request, 'getLocale') && \method_exists($request, 'getPathInfo')) {
                    /** @var mixed $locale */
                    $locale = $request->getLocale();
                    /** @var mixed $pathInfo */
                    $pathInfo = $request->getPathInfo();
                    if (\is_string($locale) && '' !== $locale && \is_string($pathInfo) && '' !== $pathInfo) {
                        $lang = \explode('_', $locale)[0];
                        if ('' !== $lang && ($pathInfo === '/' . $lang || \str_starts_with($pathInfo, '/' . $lang . '/'))) {
                            return '/' . $lang;
                        }
                    }
                }
            }
        }

        return '/';
    }

    /**
     * Checks whether a URL is external (`http://`, `https://`, `//`), anchor-only (`#...`), or non-navigational (`mailto:`, `tel:`, `javascript:`, `data:`).
     */
    public static function isExternalOrNonPageUrl(string $url): bool
    {
        $trimmed = \trim($url);
        if ('' === $trimmed || '#' === $trimmed || \str_starts_with($trimmed, '#')) {
            return true;
        }

        return 1 === \preg_match('/^(?:https?:\/\/|\/\/|mailto:|tel:|javascript:|data:|vbscript:)/i', $trimmed);
    }

    /**
     * Canonicalizes an internal URL into a locale-stripped, slash-normalized path (`/` for homepage, `/showcase` for subpages),
     * or returns `null` when the URL is not a valid internal page path.
     */
    public static function canonicalizeInternalPath(string $url, string $rootUrl = '/'): ?string
    {
        if (self::isExternalOrNonPageUrl($url)) {
            return null;
        }

        $clean = (string) \preg_replace('/[?#].*$/', '', \trim($url));
        if ('' === $clean) {
            return null;
        }

        $path = '/' . \trim($clean, '/');
        $normRoot = ('/' !== $rootUrl && '' !== \trim($rootUrl, '/') && !self::isExternalOrNonPageUrl($rootUrl))
            ? '/' . \trim((string) \preg_replace('/[?#].*$/', '', \trim($rootUrl)), '/')
            : '/';

        if ('/' !== $normRoot) {
            if ($path === $normRoot) {
                return '/';
            }
            if (\str_starts_with($path, $normRoot . '/')) {
                $stripped = \substr($path, \strlen($normRoot));

                return '' !== $stripped ? $stripped : '/';
            }
        }

        return $path;
    }

    public static function isExactMatch(string $itemUrl, string $currentPath, string $rootUrl): bool
    {
        if ('' === $currentPath) {
            return false;
        }

        $canonCurrent = self::canonicalizeInternalPath($currentPath, $rootUrl);
        $canonItem = self::canonicalizeInternalPath($itemUrl, $rootUrl);

        if (null === $canonCurrent || null === $canonItem) {
            return false;
        }

        if ($canonCurrent === $canonItem) {
            return true;
        }

        if ('/' === $rootUrl) {
            $altCurrent = self::stripLeadingLocaleSegment($canonCurrent);
            $altItem = self::stripLeadingLocaleSegment($canonItem);
            if (null !== $altCurrent && null === $altItem && $altCurrent === $canonItem) {
                return true;
            }
            if (null !== $altItem && null === $altCurrent && $canonCurrent === $altItem) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $props
     */
    public static function isActive(
        string $itemUrl,
        string $currentPath,
        string $rootUrl,
        bool $allowParentPrefixMatch,
        array $context,
        array $props,
    ): bool {
        if (self::isExactMatch($itemUrl, $currentPath, $rootUrl)) {
            return true;
        }

        if (!$allowParentPrefixMatch || '' === $currentPath) {
            return false;
        }

        $canonCurrent = self::canonicalizeInternalPath($currentPath, $rootUrl);
        $canonItem = self::canonicalizeInternalPath($itemUrl, $rootUrl);

        if (null === $canonCurrent || null === $canonItem) {
            return false;
        }

        $effectiveCurrent = $canonCurrent;
        $effectiveItem = $canonItem;

        if ('/' === $rootUrl) {
            $altCurrent = self::stripLeadingLocaleSegment($canonCurrent);
            $altItem = self::stripLeadingLocaleSegment($canonItem);
            if (null !== $altCurrent && null === $altItem) {
                $effectiveCurrent = $altCurrent;
            } elseif (null !== $altItem && null === $altCurrent) {
                $effectiveItem = $altItem;
            }
        }

        if ('/' === $effectiveItem) {
            return false;
        }

        if (\str_starts_with($effectiveCurrent . '/', $effectiveItem . '/')) {
            return true;
        }

        $ancestorUrls = $context['ancestor_urls'] ?? ($props['ancestor_urls'] ?? null);
        if (\is_iterable($ancestorUrls)) {
            foreach ($ancestorUrls as $ancestorUrl) {
                if (\is_string($ancestorUrl) && '' !== \trim($ancestorUrl)) {
                    $canonAncestor = self::canonicalizeInternalPath($ancestorUrl, $rootUrl);
                    if (null !== $canonAncestor && '/' !== $canonAncestor && $canonAncestor === $effectiveItem) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Recursively inspects nested navigation/mega-menu/collection entries (`items`, `elements`,
     * `buttons`, `columns`, `blocks`) to determine if any descendant item is active.
     *
     * @param list<array<string, mixed>> $entries
     * @param array<string, mixed>       $context
     * @param array<string, mixed>       $props
     */
    public static function hasActiveNestedEntries(
        array $entries,
        string $currentPath,
        string $rootUrl,
        array $context = [],
        array $props = [],
        int $depth = 0,
    ): bool {
        if ($depth > 6) {
            return false;
        }

        foreach ($entries as $entry) {
            if (PropReader::itemBool($entry, 'is_active', false)) {
                return true;
            }

            if (isset($entry['link']) || isset($entry['url'])) {
                $rawUrl = $entry['link'] ?? $entry['url'];
                if (!(\is_string($rawUrl) && self::isExternalOrNonPageUrl($rawUrl))) {
                    $resolvedUrl = SuluDataNormalizer::resolveUrl($rawUrl, '');
                    if ('' !== $resolvedUrl && '#' !== $resolvedUrl && !self::isExternalOrNonPageUrl($resolvedUrl)) {
                        if (self::isActive($resolvedUrl, $currentPath, $rootUrl, true, $context, $props)) {
                            return true;
                        }
                    }
                }
            }

            foreach (['items', 'elements', 'buttons', 'columns', 'blocks'] as $collectionKey) {
                if (!isset($entry[$collectionKey]) || !\is_iterable($entry[$collectionKey])) {
                    continue;
                }

                $nested = [];
                foreach ($entry[$collectionKey] as $subEntry) {
                    if (\is_array($subEntry)) {
                        /** @var array<string, mixed> $subEntry */
                        $nested[] = $subEntry;
                    }
                }

                if ([] !== $nested && self::hasActiveNestedEntries($nested, $currentPath, $rootUrl, $context, $props, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function stripLeadingLocaleSegment(string $path): ?string
    {
        if (1 === \preg_match('#^/[a-z]{2}(?:-[a-z]{2})?(/.*|$)#i', $path, $matches)) {
            return '' !== $matches[1] ? $matches[1] : '/';
        }

        return null;
    }
}
