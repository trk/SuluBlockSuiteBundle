<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Normalizer;

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
            } else {
                $props = (array) $props;
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
     * Resolves a Sulu link structure, object, or plain URL string into an href string.
     */
    public static function resolveUrl(mixed $link, string $fallback = '#'): string
    {
        if (\is_string($link)) {
            return '' !== \trim($link) ? $link : $fallback;
        }

        if (\is_array($link)) {
            if (isset($link['url']) && \is_string($link['url']) && '' !== \trim($link['url'])) {
                $url = $link['url'];
                if (isset($link['anchor']) && \is_string($link['anchor']) && '' !== $link['anchor']) {
                    $url .= '#' . \ltrim($link['anchor'], '#');
                }

                return $url;
            }

            if (isset($link['href']) && \is_string($link['href']) && '' !== \trim($link['href'])) {
                return $link['href'];
            }
        }

        if (\is_object($link) && \method_exists($link, 'getUrl')) {
            /** @var mixed $url */
            $url = $link->getUrl();
            if (\is_string($url) && '' !== $url) {
                return $url;
            }
        }

        return $fallback;
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
            return '' !== \trim($media) ? $media : $fallback;
        }

        if (\is_array($media)) {
            if (null !== $preferredFormat && isset($media['formats']) && \is_array($media['formats'])) {
                $candidate = $media['formats'][$preferredFormat] ?? null;
                if (\is_string($candidate) && '' !== \trim($candidate)) {
                    return $candidate;
                }
            }

            if (isset($media['url']) && \is_string($media['url']) && '' !== \trim($media['url'])) {
                return $media['url'];
            }

            if (isset($media['formats']) && \is_array($media['formats'])) {
                $first = \reset($media['formats']);
                if (\is_string($first) && '' !== \trim($first)) {
                    return $first;
                }
            }

            if (isset($media['id']) && (\is_int($media['id']) || \is_string($media['id']))) {
                return (string) $media['id'];
            }
        }

        if (\is_object($media)) {
            if (\method_exists($media, 'getUrl')) {
                /** @var mixed $url */
                $url = $media->getUrl();
                if (\is_string($url) && '' !== $url) {
                    return $url;
                }
            }

            if (\method_exists($media, 'getFormats')) {
                /** @var mixed $formats */
                $formats = $media->getFormats();
                if (\is_array($formats) && [] !== $formats) {
                    if (null !== $preferredFormat && isset($formats[$preferredFormat]) && \is_string($formats[$preferredFormat]) && '' !== $formats[$preferredFormat]) {
                        return $formats[$preferredFormat];
                    }

                    $first = \reset($formats);
                    if (\is_string($first) && '' !== $first) {
                        return $first;
                    }
                }
            }
        }

        return $fallback;
    }

    /**
     * Resolves responsive `srcset` string from a Sulu Media structure or object.
     *
     * @param array<string, int> $standardWidths
     */
    public static function resolveSrcSet(
        mixed $media,
        array $standardWidths = [
            'col-sm' => 400,
            'col-md' => 640,
            'col-lg' => 960,
            'col-xl' => 1280,
            'col-2xl' => 1600,
        ],
    ): ?string {
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

            $width = $standardWidths[$formatKey] ?? null;
            if (null === $width) {
                if (1 === \preg_match('/(?:^|[^\d])(\d+)(?:x\d*|w)?$/', $formatKey, $matches)) {
                    $width = (int) $matches[1];
                }
            }

            if (null !== $width && $width > 0) {
                $sources[$width] = \sprintf('%s %dw', $formatUrl, $width);
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
}
