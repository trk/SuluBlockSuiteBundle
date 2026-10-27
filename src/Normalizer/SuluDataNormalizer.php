<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Normalizer;

use Totoglu\SuluBlockSuiteBundle\Content\PropertyResolver\TablerIconPropertyResolver;

/**
 * UI-framework-agnostic normalizer for raw Sulu CMS data structures
 * (block props JSON, Sulu Media arrays/objects, Sulu Link payloads, and icon selections).
 */
final class SuluDataNormalizer
{
    /**
     * Extracts and decodes block properties from `_props` cache or `props` field (JSON string, array, or object).
     *
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    public static function extractProps(array $block): array
    {
        if (isset($block['_props']) && \is_array($block['_props'])) {
            /** @var array<string, mixed> $cached */
            $cached = $block['_props'];

            return $cached;
        }

        $props = $block['props'] ?? null;
        if (\is_object($props)) {
            if (\method_exists($props, 'getContent')) {
                /** @var mixed $props */
                $props = $props->getContent();
            } elseif (\method_exists($props, 'toArray')) {
                /** @var mixed $props */
                $props = $props->toArray();
            } elseif ($props instanceof \stdClass) {
                $props = (array) $props;
            } elseif ($props instanceof \Traversable) {
                $props = \iterator_to_array($props);
            } else {
                return [];
            }
        } elseif (\is_string($props) && '' !== \trim($props)) {
            $decoded = \json_decode($props, true);
            $props = \is_array($decoded) ? $decoded : [];
        }

        if (!\is_array($props)) {
            return [];
        }

        /** @var array<string, mixed> $props */
        return $props;
    }

    /**
     * Resolves a Sulu link structure, object, or plain URL string into a safe href string.
     */
    public static function resolveUrl(mixed $link, string $fallback = '#'): string
    {
        if (\is_string($link)) {
            return self::sanitizeUrl($link, $fallback);
        }

        if (\is_array($link)) {
            if (isset($link['url']) && \is_string($link['url']) && '' !== \trim($link['url'])) {
                $url = self::sanitizeUrl($link['url'], '');
                if ('' === $url) {
                    return $fallback;
                }
                if (isset($link['anchor']) && \is_string($link['anchor']) && '' !== $link['anchor']) {
                    $url .= '#' . \ltrim($link['anchor'], '#');
                }

                return $url;
            }

            if (isset($link['href']) && \is_string($link['href']) && '' !== \trim($link['href'])) {
                return self::sanitizeUrl($link['href'], $fallback);
            }
        }

        if (\is_object($link) && \method_exists($link, 'getUrl')) {
            /** @var mixed $url */
            $url = $link->getUrl();
            if (\is_string($url) && '' !== $url) {
                return self::sanitizeUrl($url, $fallback);
            }
        }

        return $fallback;
    }

    /**
     * Sanitize a URL string by rejecting executable and dangerous schemes (javascript:, vbscript:, data:, file:, blob:).
     */
    public static function sanitizeUrl(string $url, string $fallback = '#'): string
    {
        $trimmed = \trim($url);
        $inspected = self::inspectUrlScheme($trimmed);
        if (null === $inspected || !$inspected['is_safe']) {
            return $fallback;
        }

        return $trimmed;
    }

    /**
     * Decodes numeric/HTML entities, strips control characters/whitespace, and inspects the URL scheme.
     *
     * @return array{normalized: string, has_scheme_colon: bool, scheme: ?string, is_dangerous_scheme: bool, is_safe: bool}|null
     */
    public static function inspectUrlScheme(string $url): ?array
    {
        $trimmed = \trim($url);
        if ('' === $trimmed || !\mb_check_encoding($trimmed, 'UTF-8')) {
            return null;
        }

        // Generic decoding of numeric character references (decimal and hex, with or without trailing semicolon)
        // Strips ASCII control characters (0-31 and 127) and converts valid code points to UTF-8
        $decoded = $trimmed;
        for ($i = 0; $i < 2; ++$i) {
            $prev = $decoded;
            $numericDecoded = \preg_replace_callback(
                '/&#(?:x([0-9a-f]+)|([0-9]+));?/i',
                static function (array $m): string {
                    $code = '' !== $m[1] ? (int) \hexdec($m[1]) : (int) $m[2];
                    if ($code <= 31 || 127 === $code) {
                        return '';
                    }

                    return (string) \mb_chr($code, 'UTF-8');
                },
                $decoded,
            );
            if (null === $numericDecoded || \PREG_NO_ERROR !== \preg_last_error()) {
                return null;
            }

            $decoded = \html_entity_decode($numericDecoded, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
            if ($decoded === $prev) {
                break;
            }
        }

        // Clean invalid UTF-8 sequences
        $cleanUtf8 = \mb_convert_encoding($decoded, 'UTF-8', 'UTF-8');

        // Strip ASCII control characters/spaces as well as Unicode control and separator characters
        $normalized = \preg_replace('/[\x00-\x20\p{C}\p{Z}]+/u', '', $cleanUtf8);
        if (null === $normalized || \PREG_NO_ERROR !== \preg_last_error()) {
            return null;
        }

        $isDangerousScheme = 1 === \preg_match('/^(?:javascript|vbscript|data|file|blob):/i', $normalized);

        // Enforce safe scheme allowlist on any leading colon in first path segment before '/', '?', or '#'
        // Ensure '#' is only treated as a URL fragment delimiter if it is NOT part of an HTML entity ('&#')
        $firstColonPos = \strpos($normalized, ':');
        $firstSlashPos = \strpos($normalized, '/');
        $firstQuestionPos = \strpos($normalized, '?');

        $firstHashPos = false;
        if (1 === \preg_match('/(?<!&)#/', $normalized, $matches, \PREG_OFFSET_CAPTURE)) {
            $firstHashPos = (int) $matches[0][1];
        }

        $delims = \array_filter(
            [$firstSlashPos, $firstQuestionPos, $firstHashPos],
            static fn ($pos) => false !== $pos,
        );
        $firstDelimiter = !empty($delims) ? \min($delims) : false;

        $hasSchemeColon = false !== $firstColonPos && (false === $firstDelimiter || $firstColonPos < $firstDelimiter);
        $scheme = $hasSchemeColon ? \strtolower(\substr($normalized, 0, $firstColonPos)) : null;
        $isSafe = !$isDangerousScheme && (!$hasSchemeColon || \in_array($scheme, ['http', 'https', 'mailto', 'tel', 'sms'], true));

        return [
            'normalized' => $normalized,
            'has_scheme_colon' => $hasSchemeColon,
            'scheme' => $scheme,
            'is_dangerous_scheme' => $isDangerousScheme,
            'is_safe' => $isSafe,
        ];
    }

    /**
     * Resolves a Sulu Media structure, object, or string into an image URL.
     */
    public static function resolveImageUrl(mixed $media, ?string $preferredFormat = null, ?string $fallback = null): ?string
    {
        if (null === $media) {
            return $fallback;
        }

        if (\is_string($media)) {
            return self::sanitizeNullableUrl($media, $fallback);
        }

        if (\is_array($media)) {
            if (null !== $preferredFormat && isset($media['formats']) && \is_array($media['formats'])) {
                $candidate = $media['formats'][$preferredFormat] ?? null;
                if (\is_string($candidate) && '' !== \trim($candidate)) {
                    return self::sanitizeNullableUrl($candidate, $fallback);
                }
            }

            if (isset($media['url']) && \is_string($media['url']) && '' !== \trim($media['url'])) {
                return self::sanitizeNullableUrl($media['url'], $fallback);
            }

            if (isset($media['formats']) && \is_array($media['formats'])) {
                $first = \reset($media['formats']);
                if (\is_string($first) && '' !== \trim($first)) {
                    return self::sanitizeNullableUrl($first, $fallback);
                }
            }
        }

        if (\is_object($media)) {
            if (\method_exists($media, 'getUrl')) {
                /** @var mixed $url */
                $url = $media->getUrl();
                if (\is_string($url) && '' !== $url) {
                    return self::sanitizeNullableUrl($url, $fallback);
                }
            }

            if (\method_exists($media, 'getFormats')) {
                /** @var mixed $formats */
                $formats = $media->getFormats();
                if (\is_array($formats) && [] !== $formats) {
                    if (null !== $preferredFormat && isset($formats[$preferredFormat]) && \is_string($formats[$preferredFormat]) && '' !== $formats[$preferredFormat]) {
                        return self::sanitizeNullableUrl($formats[$preferredFormat], $fallback);
                    }

                    $first = \reset($formats);
                    if (\is_string($first) && '' !== $first) {
                        return self::sanitizeNullableUrl($first, $fallback);
                    }
                }
            }
        }

        return $fallback;
    }

    public static function sanitizeNullableUrl(string $url, ?string $fallback = null): ?string
    {
        $sanitized = self::sanitizeUrl($url, '');

        return '' !== $sanitized ? $sanitized : $fallback;
    }

    /**
     * @var array<string, int>
     */
    private static array $defaultSrcSetFormats = [];

    /**
     * Configures default format key => pixel width mappings for `resolveSrcSet()`.
     *
     * @param array<string, int> $formats
     */
    public static function setDefaultSrcSetFormats(array $formats): void
    {
        self::$defaultSrcSetFormats = $formats;
    }

    /**
     * @return array<string, int>
     */
    public static function getDefaultSrcSetFormats(): array
    {
        return self::$defaultSrcSetFormats;
    }

    /**
     * Resolves responsive `srcset` string from a Sulu Media structure or object.
     *
     * @param array<string, int>|null $standardWidths
     */
    public static function resolveSrcSet(
        mixed $media,
        ?array $standardWidths = null,
    ): ?string {
        $standardWidths ??= self::$defaultSrcSetFormats;

        if (null === $media || (!\is_array($media) && !\is_object($media))) {
            return null;
        }

        /** @var array<string, mixed> $formats */
        $formats = [];
        if (\is_array($media) && isset($media['formats']) && \is_array($media['formats'])) {
            $formats = $media['formats'];
        } elseif (\is_object($media) && \method_exists($media, 'getFormats')) {
            /** @var mixed $extracted */
            $extracted = $media->getFormats();
            if (\is_array($extracted)) {
                $formats = $extracted;
            }
        }

        if ([] === $formats) {
            return null;
        }

        /** @var array<int, string> $sources */
        $sources = [];

        foreach ($formats as $formatKey => $formatUrl) {
            if (!\is_string($formatKey) || !\is_string($formatUrl) || '' === \trim($formatUrl)) {
                continue;
            }

            $sanitizedUrl = self::sanitizeUrl($formatUrl, '');
            if ('' === $sanitizedUrl) {
                continue;
            }

            $width = $standardWidths[$formatKey] ?? null;
            if (null === $width) {
                if (1 === \preg_match('/(?:^|[^\d])(\d+)(?:x\d*|w)?$/', $formatKey, $matches)) {
                    $width = (int) $matches[1];
                }
            }

            if (null !== $width && $width > 0) {
                $sources[$width] = \sprintf('%s %dw', $sanitizedUrl, $width);
            }
        }

        if ([] === $sources) {
            return null;
        }

        \ksort($sources);

        return \implode(', ', $sources);
    }

    /**
     * Resolves Sulu media focus/focal point into CSS `object-position: X% Y%;`.
     */
    public static function resolveFocusPointStyle(mixed $media): ?string
    {
        if (null === $media) {
            return null;
        }

        $x = null;
        $y = null;

        if (\is_array($media)) {
            $point = $media['focusPoint'] ?? ($media['focalPoint'] ?? null);
            if (\is_array($point)) {
                $x = $point['x'] ?? null;
                $y = $point['y'] ?? null;
            }
        } elseif (\is_object($media) && \method_exists($media, 'getFocusPoint')) {
            /** @var mixed $point */
            $point = $media->getFocusPoint();
            if (\is_array($point)) {
                $x = $point['x'] ?? null;
                $y = $point['y'] ?? null;
            } elseif (\is_object($point) && \method_exists($point, 'getX') && \method_exists($point, 'getY')) {
                /** @var mixed $xVal */
                $xVal = $point->getX();
                /** @var mixed $yVal */
                $yVal = $point->getY();
                $x = $xVal;
                $y = $yVal;
            }
        }

        if (null !== $x && null !== $y && \is_numeric($x) && \is_numeric($y)) {
            return \sprintf('object-position: %s%% %s%%;', $x, $y);
        }

        return null;
    }

    /**
     * Extracts raw icon identifier from Sulu icon selection string, array, or object.
     */
    public static function resolveIcon(mixed $icon, ?string $default = null): ?string
    {
        if (null === $icon || '' === $icon) {
            return $default;
        }

        $raw = null;
        if (\is_string($icon)) {
            $raw = \trim($icon);
        } elseif (\is_array($icon)) {
            $candidate = $icon['name'] ?? $icon['icon'] ?? $icon['id'] ?? null;
            if (\is_string($candidate)) {
                $raw = \trim($candidate);
            }
        } elseif (\is_object($icon)) {
            if (\method_exists($icon, 'getName')) {
                /** @var mixed $name */
                $name = $icon->getName();
                if (\is_string($name)) {
                    $raw = \trim($name);
                }
            } elseif (\method_exists($icon, 'getIcon')) {
                /** @var mixed $name */
                $name = $icon->getIcon();
                if (\is_string($name)) {
                    $raw = \trim($name);
                }
            } elseif (\method_exists($icon, '__toString')) {
                $raw = \trim((string) $icon);
            }
        }

        if (null === $raw || '' === $raw) {
            return $default;
        }

        return $raw;
    }

    /**
     * Resolves a Sulu icon value into a normalized CSS icon class string (e.g. `ti ti-rocket`).
     */
    public static function resolveIconClass(mixed $icon): ?string
    {
        $raw = self::resolveIcon($icon);
        if (null === $raw || '' === $raw) {
            return null;
        }

        if (
            \str_starts_with($raw, 'su-')
            || \str_starts_with($raw, 'bi-')
            || \str_starts_with($raw, 'fa-')
            || \str_starts_with($raw, 'ri-')
            || \str_starts_with($raw, 'lucide-')
            || (\str_contains($raw, ' ') && !\str_starts_with(\strtolower($raw), 'ti '))
        ) {
            return $raw;
        }

        $normalized = TablerIconPropertyResolver::normalizeTablerIconName($raw);
        if (null !== $normalized) {
            return 'ti ti-' . $normalized;
        }

        if (\str_starts_with($raw, 'ti ')) {
            return $raw;
        }

        if (\str_starts_with($raw, 'ti-')) {
            return 'ti ' . $raw;
        }

        if (\str_starts_with($raw, 'tabler-')) {
            return 'ti ti-' . \substr($raw, 7);
        }

        return 'ti ti-' . $raw;
    }

    /**
     * Sanitizes a map location query, embed URL, or `<iframe>` snippet into a safe HTTPS
     * Google Maps or OpenStreetMap embed URL.
     */
    public static function sanitizeMapEmbedUrl(?string $query, int $zoom = 14): string
    {
        if (null === $query || '' === \trim($query)) {
            return '';
        }

        $cleanQuery = \trim($query);
        $fromIframe = false;

        if (\str_contains($cleanQuery, '<iframe')) {
            if (1 !== \preg_match('/src="([^"]+)"/i', $cleanQuery, $matches)) {
                return '';
            }
            $cleanQuery = \trim(\html_entity_decode($matches[1], \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
            $fromIframe = true;
        }

        if ($fromIframe || 1 === \preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $cleanQuery) || \str_starts_with($cleanQuery, '//')) {
            $parts = \parse_url($cleanQuery);
            if (!\is_array($parts)) {
                return '';
            }

            $scheme = \strtolower((string) ($parts['scheme'] ?? ''));
            $host = \strtolower((string) ($parts['host'] ?? ''));
            $path = (string) ($parts['path'] ?? '');

            if ('https' !== $scheme) {
                return '';
            }

            $isGoogleMaps = \in_array($host, ['www.google.com', 'maps.google.com', 'google.com'], true)
                && \str_starts_with($path, '/maps');
            $isOpenStreetMap = \in_array($host, ['www.openstreetmap.org', 'openstreetmap.org'], true);

            return ($isGoogleMaps || $isOpenStreetMap) ? $cleanQuery : '';
        }

        $clampedZoom = \max(1, \min(20, $zoom));

        return 'https://maps.google.com/maps?q=' . \rawurlencode($cleanQuery) . '&z=' . $clampedZoom . '&output=embed';
    }

    /**
     * Resolves a Sulu `date` / `datetime` field value (`\DateTimeInterface` or date string)
     * into an ISO-8601 (`\DateTimeInterface::ATOM`) string, falling back to `$defaultOffsetSeconds` from now.
     */
    public static function resolveIsoDate(mixed $date, int $defaultOffsetSeconds = 604800): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format(\DateTimeInterface::ATOM);
        }

        if (\is_string($date)) {
            $trimmed = \trim($date);
            if ('' !== $trimmed) {
                $parsed = \strtotime($trimmed);
                if (false !== $parsed) {
                    return \date(\DateTimeInterface::ATOM, $parsed);
                }

                return $trimmed;
            }
        }

        return \date(\DateTimeInterface::ATOM, \time() + $defaultOffsetSeconds);
    }

    /**
     * Extracts uppercase Unicode initials from an explicit initials string (up to 3 chars)
     * or a single/multi-word display name (up to 2 chars).
     */
    public static function extractInitials(?string $name, ?string $explicitInitials = null): ?string
    {
        if (null !== $explicitInitials && '' !== \trim($explicitInitials)) {
            return \mb_strtoupper(\mb_substr(\trim($explicitInitials), 0, 3));
        }

        if (null === $name || '' === \trim($name)) {
            return null;
        }

        $cleaned = \preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name);
        $parts = \preg_split('/\s+/u', \trim(\is_string($cleaned) ? $cleaned : $name), -1, \PREG_SPLIT_NO_EMPTY);
        if (!\is_array($parts) || [] === $parts) {
            return null;
        }

        if (1 === \count($parts)) {
            return \mb_strtoupper(\mb_substr($parts[0], 0, 2));
        }

        $first = \mb_substr($parts[0], 0, 1);
        $last = \mb_substr($parts[\count($parts) - 1], 0, 1);

        return \mb_strtoupper($first . $last);
    }

    /**
     * Hydrates Sulu media (`$imageKeys` and `$videoKeys`), `icon`, and `link` / `url`
     * fields inside a collection item array into resolved URLs, srcsets, focal-point styles, icon classes,
     * and link targets/titles.
     *
     * @param array<string, mixed> $item
     * @param list<string> $imageKeys
     * @param list<string> $videoKeys
     *
     * @return array<string, mixed>
     */
    public static function normalizeItemMediaAndLinks(
        array $item,
        array $imageKeys = ['image'],
        array $videoKeys = ['video'],
    ): array {
        foreach ($imageKeys as $imageKey) {
            if (isset($item[$imageKey])) {
                $item[$imageKey . '_url'] = self::resolveImageUrl($item[$imageKey]);
                $item[$imageKey . '_srcset'] = self::resolveSrcSet($item[$imageKey]);
                $item[$imageKey . '_focus_style'] = self::resolveFocusPointStyle($item[$imageKey]);
            }
        }

        foreach ($videoKeys as $videoKey) {
            if (isset($item[$videoKey])) {
                $item[$videoKey . '_url'] = self::resolveImageUrl($item[$videoKey]);
            }
        }

        if (isset($item['icon'])) {
            $resolvedIcon = self::resolveIconClass($item['icon']);
            $item['icon_class'] = $resolvedIcon;
            if (null !== $resolvedIcon) {
                $item['icon'] = $resolvedIcon;
            }
        }

        if (isset($item['link'])) {
            $item['link_href'] = self::resolveUrl($item['link']);
            $item['link_url'] = $item['link_href'];
            if (\is_array($item['link'])) {
                if (!isset($item['link_target']) && '_blank' === ($item['link']['target'] ?? null)) {
                    $item['link_target'] = '_blank';
                }
                if (!isset($item['link_title']) && \is_string($item['link']['title'] ?? null) && '' !== \trim($item['link']['title'])) {
                    $item['link_title'] = \trim($item['link']['title']);
                }
            }
        } elseif (isset($item['url'])) {
            $item['link_href'] = self::resolveUrl($item['url']);
            $item['link_url'] = $item['link_href'];
        }

        return $item;
    }

    /**
     * Unwraps a Sulu block structure (`array`, `ContentView`/`getContent()`, `toArray()`, or `\stdClass`)
     * into an associative array.
     *
     * @return array<string, mixed>|null
     */
    public static function normalizeBlock(mixed $block): ?array
    {
        if (!\is_array($block) && !\is_object($block)) {
            return null;
        }

        if (\is_object($block)) {
            if (\method_exists($block, 'getContent')) {
                /** @var mixed $block */
                $block = $block->getContent();
            } elseif (\method_exists($block, 'toArray')) {
                /** @var mixed $block */
                $block = $block->toArray();
            } elseif ($block instanceof \stdClass) {
                $block = (array) $block;
            } else {
                return null;
            }
        }

        if (!\is_array($block)) {
            return null;
        }

        /** @var array<string, mixed> $block */
        return $block;
    }

    /**
     * Extracts a reusable snippet UUID from a Sulu block array (`snippet`, `reusable_snippet`, or `view.snippet`).
     *
     * @param array<string, mixed> $block
     */
    public static function extractSnippetUuid(array $block): ?string
    {
        foreach (['snippet', 'reusable_snippet'] as $key) {
            $snippet = $block[$key] ?? null;
            if (\is_string($snippet) && '' !== \trim($snippet)) {
                return \trim($snippet);
            }
            if (\is_array($snippet)) {
                $innerContent = isset($snippet['content']) && \is_array($snippet['content']) ? $snippet['content'] : [];
                $candidate = $snippet['uuid'] ?? ($snippet['id'] ?? ($innerContent['uuid'] ?? ($innerContent['id'] ?? null)));
                if (\is_string($candidate) && '' !== \trim($candidate)) {
                    return \trim($candidate);
                }
            }
        }

        if (
            isset($block['view'])
            && \is_array($block['view'])
            && isset($block['view']['snippet'])
            && \is_array($block['view']['snippet'])
        ) {
            $viewSnippet = $block['view']['snippet'];
            $candidate = $viewSnippet['id'] ?? ($viewSnippet['uuid'] ?? null);
            if (\is_string($candidate) && '' !== \trim($candidate)) {
                return \trim($candidate);
            }
        }

        return null;
    }

    /**
     * Recursively unwraps Sulu `ContentView` objects (or any wrapper exposing `getContent()`) into raw values.
     */
    public static function unwrapContentViews(mixed $value): mixed
    {
        if (\is_object($value) && \method_exists($value, 'getContent')) {
            return self::unwrapContentViews($value->getContent());
        }

        if (\is_array($value)) {
            $unwrapped = [];
            foreach ($value as $k => $v) {
                $unwrapped[$k] = self::unwrapContentViews($v);
            }

            return $unwrapped;
        }

        return $value;
    }
}
