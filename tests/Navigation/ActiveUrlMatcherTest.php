<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Navigation;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Navigation\ActiveUrlMatcher;

final class ActiveUrlMatcherTest extends TestCase
{
    public function testResolveCurrentRequestPathAndRootUrl(): void
    {
        $context = [
            'request' => [
                'resourceLocatorPrefix' => '/en',
                'resourceLocator' => '/about/team',
            ],
        ];

        self::assertSame('/en/about/team', ActiveUrlMatcher::resolveCurrentRequestPath($context, []));
        self::assertSame('/en', ActiveUrlMatcher::resolveRootUrl($context, []));
    }

    public function testIsExactMatchAndIsActiveWithParentPrefixAndAncestors(): void
    {
        self::assertTrue(ActiveUrlMatcher::isExactMatch('/en/about', '/en/about', '/en'));
        self::assertTrue(ActiveUrlMatcher::isExactMatch('/about', '/en/about', '/'));
        self::assertFalse(ActiveUrlMatcher::isExactMatch('/en/about', '/en/about/team', '/en'));

        self::assertTrue(ActiveUrlMatcher::isActive(
            '/en/about',
            '/en/about/team',
            '/en',
            allowParentPrefixMatch: true,
            context: [],
            props: [],
        ));

        self::assertFalse(ActiveUrlMatcher::isActive(
            '/en',
            '/en/about/team',
            '/en',
            allowParentPrefixMatch: true,
            context: [],
            props: [],
        ));

        self::assertTrue(ActiveUrlMatcher::isActive(
            '/en/services',
            '/en/custom-landing',
            '/en',
            allowParentPrefixMatch: true,
            context: ['ancestor_urls' => ['/en/services']],
            props: [],
        ));
    }
}
