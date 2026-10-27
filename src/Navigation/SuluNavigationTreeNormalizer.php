<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Navigation;

use Totoglu\SuluBlockSuiteBundle\Normalizer\SuluDataNormalizer;
use Totoglu\SuluBlockSuiteBundle\Util\PropReader;

/**
 * UI-framework-agnostic normalizer for Sulu Webspace navigation trees
 * (`sulu_page_navigation_root_tree` / `sulu_page_navigation_flat`) and manual fallback items.
 */
final class SuluNavigationTreeNormalizer
{
    /**
     * Property mapping passed to Sulu's `sulu_page_navigation_root_tree(context, depth, properties)`.
     *
     * @return array<string, string>
     */
    public static function defaultProperties(bool $showExcerpts = true, bool $showIcons = true): array
    {
        $props = [
            'uuid' => 'object.uuid',
            'title' => 'title',
            'url' => 'url',
        ];

        if ($showExcerpts) {
            $props['excerpt_title'] = 'excerpt.title';
            $props['excerpt_description'] = 'excerpt.description';
            $props['excerpt_more'] = 'excerpt.more';
            $props['excerpt_image'] = 'excerpt.image';
        }

        if ($showIcons) {
            $props['excerpt_icon'] = 'excerpt.icon';
        }

        return $props;
    }

    /**
     * Normalizes a Sulu navigation tree (or fallback `items` array) into a uniform multi-level tree structure.
     *
     * @param iterable<mixed>            $tree
     * @param list<array<string, mixed>> $fallbackItems
     * @param array<string, mixed>       $context
     * @param array<string, mixed>       $props
     *
     * @return list<array{
     *     uuid: string,
     *     title: string,
     *     url: string,
     *     excerpt_title: ?string,
     *     excerpt_description: ?string,
     *     excerpt_more: ?string,
     *     image_url: ?string,
     *     icon: ?string,
     *     is_active: bool,
     *     has_active_child: bool,
     *     children: list<array<string, mixed>>
     * }>
     */
    public static function normalizeTree(
        iterable $tree = [],
        array $fallbackItems = [],
        int $maxDepth = 2,
        bool $showExcerpts = true,
        bool $showIcons = true,
        bool $showHome = false,
        string $homeTitle = 'Home',
        ?string $currentPath = null,
        ?string $rootUrl = null,
        array $context = [],
        array $props = [],
    ): array {
        $resolvedCurrentPath = ActiveUrlMatcher::resolveCurrentRequestPath($context, $props, $currentPath);
        if ('' === $resolvedCurrentPath) {
            $resolvedCurrentPath = '/';
        }
        $resolvedRootUrl = ActiveUrlMatcher::resolveRootUrl($context, $props, $rootUrl);

        $rawList = [];
        foreach ($tree as $node) {
            if (\is_array($node)) {
                /** @var array<string, mixed> $node */
                $rawList[] = $node;
            }
        }

        if ([] === $rawList) {
            $rawList = $fallbackItems;
        }

        $normalized = self::normalizeLevel(
            $rawList,
            1,
            \max(1, $maxDepth),
            $showExcerpts,
            $showIcons,
            $resolvedCurrentPath,
            $resolvedRootUrl,
            $context,
            $props,
        );

        if ($showHome) {
            $homeUrl = SuluDataNormalizer::sanitizeUrl($resolvedRootUrl, '/');
            if ('' === $homeUrl || '#' === $homeUrl) {
                $homeUrl = '/';
            }

            $firstUrl = $normalized[0]['url'] ?? null;
            if ($firstUrl !== $homeUrl && '/' !== $firstUrl) {
                \array_unshift($normalized, [
                    'uuid' => 'home',
                    'title' => $homeTitle,
                    'url' => $homeUrl,
                    'excerpt_title' => null,
                    'excerpt_description' => null,
                    'excerpt_more' => null,
                    'image_url' => null,
                    'icon' => $showIcons ? 'ti ti-home' : null,
                    'is_active' => ActiveUrlMatcher::isExactMatch($homeUrl, $resolvedCurrentPath, $resolvedRootUrl),
                    'has_active_child' => false,
                    'children' => [],
                ]);
            }
        }

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param array<string, mixed>       $context
     * @param array<string, mixed>       $props
     *
     * @return list<array{
     *     uuid: string,
     *     title: string,
     *     url: string,
     *     excerpt_title: ?string,
     *     excerpt_description: ?string,
     *     excerpt_more: ?string,
     *     image_url: ?string,
     *     icon: ?string,
     *     is_active: bool,
     *     has_active_child: bool,
     *     children: list<array<string, mixed>>
     * }>
     */
    private static function normalizeLevel(
        array $nodes,
        int $level,
        int $maxDepth,
        bool $showExcerpts,
        bool $showIcons,
        string $currentPath,
        string $rootUrl,
        array $context,
        array $props,
    ): array {
        $result = [];

        foreach ($nodes as $index => $node) {
            $excerpt = isset($node['excerpt']) && \is_array($node['excerpt']) ? $node['excerpt'] : [];

            $title = self::itemNullableString($node, 'title')
                ?? self::itemNullableString($node, 'excerpt_title')
                ?? (\is_string($excerpt['title'] ?? null) ? \trim($excerpt['title']) : null);

            if (null === $title || '' === $title) {
                continue;
            }

            $uuid = self::itemNullableString($node, 'uuid')
                ?? self::itemNullableString($node, 'id')
                ?? ('nav-' . $level . '-' . $index);

            $rawUrl = $node['url'] ?? ($node['link'] ?? '/');
            $isDangerousRawUrl = \is_string($rawUrl) && ActiveUrlMatcher::isExternalOrNonPageUrl($rawUrl);
            $url = SuluDataNormalizer::resolveUrl($rawUrl, '/');
            if ('' === $url) {
                $url = '/';
            }

            $excerptTitle = null;
            $excerptDesc = null;
            $excerptMore = null;
            $imageUrl = null;

            if ($showExcerpts) {
                $excerptTitle = self::itemNullableString($node, 'excerpt_title')
                    ?? (\is_string($excerpt['title'] ?? null) && '' !== \trim($excerpt['title']) ? \trim($excerpt['title']) : null);

                $rawDesc = self::itemNullableString($node, 'excerpt_description')
                    ?? self::itemNullableString($node, 'description')
                    ?? (\is_string($excerpt['description'] ?? null) ? $excerpt['description'] : null);

                if (null !== $rawDesc) {
                    $cleanDesc = \trim(\strip_tags($rawDesc));
                    $excerptDesc = '' !== $cleanDesc ? $cleanDesc : null;
                }

                $excerptMore = self::itemNullableString($node, 'excerpt_more')
                    ?? (\is_string($excerpt['more'] ?? null) && '' !== \trim($excerpt['more']) ? \trim($excerpt['more']) : null);

                $rawImage = $node['excerpt_image'] ?? ($node['image'] ?? ($excerpt['image'] ?? null));
                $imageUrl = SuluDataNormalizer::resolveImageUrl($rawImage);
            }

            $icon = null;
            if ($showIcons) {
                $rawIcon = $node['excerpt_icon'] ?? ($node['icon'] ?? ($excerpt['icon'] ?? null));
                $icon = SuluDataNormalizer::resolveIconClass($rawIcon);
            }

            $children = [];
            if ($level < $maxDepth && isset($node['children']) && \is_iterable($node['children'])) {
                $childNodes = [];
                foreach ($node['children'] as $child) {
                    if (\is_array($child)) {
                        /** @var array<string, mixed> $child */
                        $childNodes[] = $child;
                    }
                }
                if ([] !== $childNodes) {
                    $children = self::normalizeLevel(
                        $childNodes,
                        $level + 1,
                        $maxDepth,
                        $showExcerpts,
                        $showIcons,
                        $currentPath,
                        $rootUrl,
                        $context,
                        $props,
                    );
                }
            }

            $hasActiveChild = false;
            foreach ($children as $childItem) {
                if ($childItem['is_active'] || $childItem['has_active_child']) {
                    $hasActiveChild = true;
                    break;
                }
            }

            $isSelfActive = PropReader::bool($node, 'is_active', false)
                || (
                    !$isDangerousRawUrl
                    && ActiveUrlMatcher::isActive($url, $currentPath, $rootUrl, true, $context, $props)
                );
            $isActive = $isSelfActive || $hasActiveChild;

            $result[] = [
                'uuid' => $uuid,
                'title' => $title,
                'url' => $url,
                'excerpt_title' => $excerptTitle,
                'excerpt_description' => $excerptDesc,
                'excerpt_more' => $excerptMore,
                'image_url' => $imageUrl,
                'icon' => $icon,
                'is_active' => $isActive,
                'has_active_child' => $hasActiveChild,
                'children' => $children,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function itemNullableString(array $node, string $key): ?string
    {
        return PropReader::itemNullableString($node, $key);
    }
}
