<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Normalizer;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Normalizer\SuluDataNormalizer;

final class SuluDataNormalizerTest extends TestCase
{
    public function testExtractPropsHandlesJsonStringArrayObjectAndMalformedJson(): void
    {
        // 1. JSON string
        $this->assertSame(
            ['variant' => 'solid', 'size' => 'lg'],
            SuluDataNormalizer::extractProps(['props' => '{"variant":"solid","size":"lg"}']),
        );

        // 2. Pre-normalized _props takes precedence
        $this->assertSame(
            ['cached' => true],
            SuluDataNormalizer::extractProps([
                '_props' => ['cached' => true],
                'props' => '{"cached":false}',
            ]),
        );

        // 3. Array props
        $this->assertSame(
            ['color' => 'primary'],
            SuluDataNormalizer::extractProps(['props' => ['color' => 'primary']]),
        );

        // 4. Object with toArray()
        $obj = new class {
            /**
             * @return array<string, string>
             */
            public function toArray(): array
            {
                return ['key' => 'val'];
            }
        };
        $this->assertSame(['key' => 'val'], SuluDataNormalizer::extractProps(['props' => $obj]));

        // 5. Malformed or empty JSON string returns empty array
        $this->assertSame([], SuluDataNormalizer::extractProps(['props' => '{invalid-json']));
        $this->assertSame([], SuluDataNormalizer::extractProps(['props' => '']));
        $this->assertSame([], SuluDataNormalizer::extractProps([]));
    }

    public function testResolveUrlHandlesStringsArraysAndObjects(): void
    {
        $this->assertSame('/about', SuluDataNormalizer::resolveUrl('/about'));
        $this->assertSame('/contact#map', SuluDataNormalizer::resolveUrl(['url' => '/contact', 'anchor' => 'map']));
        $this->assertSame('https://example.com', SuluDataNormalizer::resolveUrl(['href' => 'https://example.com']));

        $linkObj = new class {
            public function getUrl(): string
            {
                return '/object-url';
            }
        };
        $this->assertSame('/object-url', SuluDataNormalizer::resolveUrl($linkObj));
        $this->assertSame('#', SuluDataNormalizer::resolveUrl(null));
        $this->assertSame('/fallback', SuluDataNormalizer::resolveUrl(null, '/fallback'));

        // Reject dangerous URL schemes
        $this->assertSame('#', SuluDataNormalizer::resolveUrl('javascript:alert(1)'));
        $this->assertSame('#', SuluDataNormalizer::resolveUrl(" \t\nJavaScript:alert(1)"));
        $this->assertSame('#', SuluDataNormalizer::resolveUrl(['url' => 'data:text/html,<script>alert(1)</script>']));
        $this->assertSame('/safe', SuluDataNormalizer::resolveUrl(['href' => 'vbscript:msgbox(1)'], '/safe'));
        $this->assertNull(SuluDataNormalizer::resolveImageUrl('javascript:alert(1)'));
    }

    public function testResolveImageUrlHandlesStringsArraysAndObjects(): void
    {
        $this->assertNull(SuluDataNormalizer::resolveImageUrl(null));
        $this->assertSame('/img/hero.jpg', SuluDataNormalizer::resolveImageUrl('/img/hero.jpg'));
        $this->assertSame('/media/direct.webp', SuluDataNormalizer::resolveImageUrl(['url' => '/media/direct.webp']));
        $this->assertSame(
            '/media/400.webp',
            SuluDataNormalizer::resolveImageUrl(['formats' => ['sulu-400x400' => '/media/400.webp']]),
        );

        $mediaObj = new class {
            public function getUrl(): string
            {
                return '/media/obj.jpg';
            }
        };
        $this->assertSame('/media/obj.jpg', SuluDataNormalizer::resolveImageUrl($mediaObj));
    }

    public function testResolveSrcSetBuildsSortedResponsiveSources(): void
    {
        $this->assertNull(SuluDataNormalizer::resolveSrcSet(null));
        $this->assertNull(SuluDataNormalizer::resolveSrcSet(['formats' => []]));

        $media = [
            'formats' => [
                'col-lg' => '/media/960.webp',
                'sulu-400x400' => '/media/400.webp',
                'col-md' => '/media/640.webp',
            ],
        ];

        $this->assertSame(
            '/media/400.webp 400w, /media/640.webp 640w, /media/960.webp 960w',
            SuluDataNormalizer::resolveSrcSet($media),
        );
    }

    public function testResolveFocusPointStyleExtractsCoordinates(): void
    {
        $this->assertNull(SuluDataNormalizer::resolveFocusPointStyle(null));
        $this->assertSame(
            'object-position: 25% 75%;',
            SuluDataNormalizer::resolveFocusPointStyle(['focusPoint' => ['x' => 25, 'y' => 75]]),
        );

        $mediaObj = new class {
            /**
             * @return array{x: int, y: int}
             */
            public function getFocusPoint(): array
            {
                return ['x' => 50, 'y' => 50];
            }
        };
        $this->assertSame('object-position: 50% 50%;', SuluDataNormalizer::resolveFocusPointStyle($mediaObj));
    }

    public function testResolveIconExtractsRawAndPrefixedNames(): void
    {
        $this->assertNull(SuluDataNormalizer::resolveIcon(null));
        $this->assertNull(SuluDataNormalizer::resolveIcon(''));
        $this->assertSame('star', SuluDataNormalizer::resolveIcon('star'));
        $this->assertSame('rocket', SuluDataNormalizer::resolveIcon(['name' => 'rocket']));
        $this->assertSame('heart', SuluDataNormalizer::resolveIcon(['icon' => 'heart']));
    }
}
