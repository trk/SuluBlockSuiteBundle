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

    public function testSanitizeUrlRejectsDangerousSchemesAndUnicodeWhitespaceBypasses(): void
    {
        // 1. Dangerous non-web schemes
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('file:///etc/passwd'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('blob:https://example.com/uuid'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('javascript:alert(1)'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('data:text/html,<script>alert(1)</script>'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('vbscript:msgbox(1)'));

        // 2. Custom fallback
        $this->assertSame('/safe', SuluDataNormalizer::sanitizeUrl('file:///etc/passwd', '/safe'));
        $this->assertSame('/safe', SuluDataNormalizer::sanitizeUrl('blob:https://example.com/uuid', '/safe'));

        // 3. Unicode whitespace and control character bypasses
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl("java\u{00A0}script:alert(1)"));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl("javascript\u{00A0}:alert(1)"));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl("\u{2000}javascript:alert(1)"));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl("java\u{200B}script:alert(1)"));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl("\u{00A0}javascript:alert(1)\u{00A0}"));

        // 4. Valid schemes and paths must remain intact
        $this->assertSame('https://example.com/path?foo=bar#hash', SuluDataNormalizer::sanitizeUrl('https://example.com/path?foo=bar#hash'));
        $this->assertSame('http://example.com', SuluDataNormalizer::sanitizeUrl('http://example.com'));
        $this->assertSame('mailto:user@example.com', SuluDataNormalizer::sanitizeUrl('mailto:user@example.com'));
        $this->assertSame('tel:+1234567890', SuluDataNormalizer::sanitizeUrl('tel:+1234567890'));
        $this->assertSame('/about-us', SuluDataNormalizer::sanitizeUrl('/about-us'));
        $this->assertSame('#top', SuluDataNormalizer::sanitizeUrl('#top'));
        $this->assertSame('//cdn.example.com/lib.js', SuluDataNormalizer::sanitizeUrl('//cdn.example.com/lib.js'));
        $this->assertSame('?query=test', SuluDataNormalizer::sanitizeUrl('?query=test'));
    }

    public function testSanitizeUrlRejectsMalformedUtf8Sequences(): void
    {
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl("javascript:/*\x80*/alert(1)"));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl("javascript:alert(1)\x80"));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl("javascript:alert(1)//\xff"));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl("data:text/html,<script>alert(1)</script>\x80"));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl("file:///etc/passwd\x80"));
    }

    public function testSanitizeUrlRejectsNumericAsciiControlEntities(): void
    {
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('jav&#x0D;ascript:alert(1)'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('jav&#13;ascript:alert(1)'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('data&#x0D;:text/html,<script>alert(1)</script>'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('&#13;javascript:alert(1)'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('java&#0;script:alert(1)'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('&#x00;javascript:alert(1)'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('&#x1F;javascript:alert(1)'));
    }

    public function testSanitizeUrlRejectsSemicolonLessNumericEntities(): void
    {
        // Decimal entities without trailing semicolons
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('javascript&#58alert(1)'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('javascript&#0000058alert(1)'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('&#106avascript:alert(1)'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('j&#97vascript:alert(1)'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('ja&#118ascript:alert(1)'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('jav&#97script:alert(1)'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('java&#115cript:alert(1)'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('&#100ata:text/html,<script>alert(1)</script>'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('&#118bscript:msgbox(1)'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('&#102ile:///etc/passwd'));

        // Decimal control entities followed by letters (e.g. 'a')
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('jav&#13ascript:alert(1)'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('jav&#10ascript:alert(1)'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('jav&#9ascript:alert(1)'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('jav&#0ascript:alert(1)'));

        // Hex entities without semicolons
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('jav&#x61script:alert(1)'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('javascript&#x3a alert(1)'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('javascript&#x3a/*comment*/alert(1)'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('data&#x3a text/html,evil'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('file&#x3a ///etc/passwd'));
    }

    public function testSanitizeUrlEntityFragmentCollisionAndLegitimateUrls(): void
    {
        // Entity references containing '#' must not be treated as URL fragment delimiters
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('foo&#bar:baz'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('jav&#99999999:alert(1)'));

        // Legitimate URLs with fragments remain intact
        $this->assertSame('/page#section', SuluDataNormalizer::sanitizeUrl('/page#section'));
        $this->assertSame('https://example.com#intro', SuluDataNormalizer::sanitizeUrl('https://example.com#intro'));
        $this->assertSame('https://example.com/path?foo=bar#hash', SuluDataNormalizer::sanitizeUrl('https://example.com/path?foo=bar#hash'));
        $this->assertSame('page#section:1', SuluDataNormalizer::sanitizeUrl('page#section:1'));
        $this->assertSame('#top', SuluDataNormalizer::sanitizeUrl('#top'));
    }

    public function testSanitizeUrlEnforcesSafeSchemeAllowlistOnLeadingColon(): void
    {
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('ftp://example.com/file'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('ssh://git@github.com'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('gopher://example.com'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('disallowed:payload'));
        $this->assertSame('#', SuluDataNormalizer::sanitizeUrl('foo:bar'));
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
        $this->assertNull(SuluDataNormalizer::resolveImageUrl(['id' => 42]));
        $this->assertSame('#', SuluDataNormalizer::resolveUrl('java&#x09;script:alert(1)'));
    }

    public function testResolveSrcSetBuildsSortedResponsiveSources(): void
    {
        SuluDataNormalizer::setDefaultSrcSetFormats([]);

        $this->assertNull(SuluDataNormalizer::resolveSrcSet(null));
        $this->assertNull(SuluDataNormalizer::resolveSrcSet(['formats' => []]));

        $media = [
            'formats' => [
                'col-lg' => '/media/960.webp',
                'sulu-400x400' => '/media/400.webp',
                'col-md' => '/media/640.webp',
                'col-xl' => 'javascript:alert(1)',
            ],
        ];

        // With empty default formats, only keys containing numeric widths are resolved
        $this->assertSame(
            '/media/400.webp 400w',
            SuluDataNormalizer::resolveSrcSet($media),
        );

        // With configured format widths (passed explicitly or via default config), named keys are resolved
        $formats = [
            'col-sm' => 400,
            'col-md' => 640,
            'col-lg' => 960,
            'col-xl' => 1280,
            'col-2xl' => 1600,
        ];
        $this->assertSame(
            '/media/400.webp 400w, /media/640.webp 640w, /media/960.webp 960w',
            SuluDataNormalizer::resolveSrcSet($media, $formats),
        );

        SuluDataNormalizer::setDefaultSrcSetFormats($formats);
        $this->assertSame($formats, SuluDataNormalizer::getDefaultSrcSetFormats());
        $this->assertSame(
            '/media/400.webp 400w, /media/640.webp 640w, /media/960.webp 960w',
            SuluDataNormalizer::resolveSrcSet($media),
        );
        SuluDataNormalizer::setDefaultSrcSetFormats([]);
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

    public function testNormalizeBlockAndExtractSnippetUuid(): void
    {
        $this->assertNull(SuluDataNormalizer::normalizeBlock(null));
        $this->assertNull(SuluDataNormalizer::normalizeBlock('invalid'));
        $this->assertSame(['type' => 'hero'], SuluDataNormalizer::normalizeBlock(['type' => 'hero']));
        $this->assertSame(['type' => 'hero'], SuluDataNormalizer::normalizeBlock((object) ['type' => 'hero']));

        $contentObj = new class {
            /**
             * @return array<string, mixed>
             */
            public function getContent(): array
            {
                return ['type' => 'text', 'title' => 'Hello'];
            }
        };
        $this->assertSame(['type' => 'text', 'title' => 'Hello'], SuluDataNormalizer::normalizeBlock($contentObj));

        $this->assertSame('uuid-1', SuluDataNormalizer::extractSnippetUuid(['snippet' => ' uuid-1 ']));
        $this->assertSame('uuid-2', SuluDataNormalizer::extractSnippetUuid(['reusable_snippet' => ['uuid' => 'uuid-2']]));
        $this->assertSame('uuid-3', SuluDataNormalizer::extractSnippetUuid(['snippet' => ['content' => ['id' => 'uuid-3']]]));
        $this->assertSame('uuid-4', SuluDataNormalizer::extractSnippetUuid(['view' => ['snippet' => ['id' => 'uuid-4']]]));
        $this->assertNull(SuluDataNormalizer::extractSnippetUuid(['type' => 'text']));
    }
}
