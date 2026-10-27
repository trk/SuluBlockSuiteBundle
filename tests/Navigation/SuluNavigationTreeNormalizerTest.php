<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Navigation;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Navigation\SuluNavigationTreeNormalizer;

final class SuluNavigationTreeNormalizerTest extends TestCase
{
    public function testDefaultPropertiesRespectsExcerptsAndIconsFlags(): void
    {
        $all = SuluNavigationTreeNormalizer::defaultProperties(true, true);
        self::assertArrayHasKey('excerpt_title', $all);
        self::assertArrayHasKey('excerpt_icon', $all);

        $minimal = SuluNavigationTreeNormalizer::defaultProperties(false, false);
        self::assertArrayNotHasKey('excerpt_title', $minimal);
        self::assertArrayNotHasKey('excerpt_icon', $minimal);
    }

    public function testNormalizesMultiLevelTreeWithActiveChildAndHomePrepend(): void
    {
        $tree = [
            [
                'uuid' => 'parent-1',
                'title' => 'Services',
                'url' => '/services',
                'excerpt_icon' => 'rocket',
                'children' => [
                    [
                        'uuid' => 'child-1',
                        'title' => 'Web Design',
                        'url' => '/services/web-design',
                    ],
                ],
            ],
        ];

        $normalized = SuluNavigationTreeNormalizer::normalizeTree(
            $tree,
            fallbackItems: [],
            maxDepth: 2,
            showExcerpts: true,
            showIcons: true,
            showHome: true,
            homeTitle: 'Start',
            currentPath: '/services/web-design',
            rootUrl: '/',
        );

        self::assertCount(2, $normalized);
        self::assertSame('home', $normalized[0]['uuid']);
        self::assertSame('Start', $normalized[0]['title']);
        self::assertFalse($normalized[0]['is_active']);

        self::assertSame('parent-1', $normalized[1]['uuid']);
        self::assertSame('ti ti-rocket', $normalized[1]['icon']);
        self::assertTrue($normalized[1]['has_active_child']);
        self::assertTrue($normalized[1]['is_active']);
        self::assertTrue($normalized[1]['children'][0]['is_active']);
    }
}
