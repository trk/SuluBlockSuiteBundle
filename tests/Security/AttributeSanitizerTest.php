<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Security;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Security\AttributeSanitizer;

final class AttributeSanitizerTest extends TestCase
{
    public function testSanitizeIdAcceptsOnlyValidElementIds(): void
    {
        $this->assertSame('hero-section', AttributeSanitizer::sanitizeId('  hero-section '));
        $this->assertSame('a_1-B', AttributeSanitizer::sanitizeId('a_1-B'));
        $this->assertNull(AttributeSanitizer::sanitizeId(''));
        $this->assertNull(AttributeSanitizer::sanitizeId('123-starts-with-digit'));
        $this->assertNull(AttributeSanitizer::sanitizeId('bad id, body { display:none }'));
        $this->assertNull(AttributeSanitizer::sanitizeId("bad');alert(1)//"));
        $this->assertNull(AttributeSanitizer::sanitizeId(null));
        $this->assertNull(AttributeSanitizer::sanitizeId(123));
    }

    public function testParseRejectsInlineEventHandlersReservedNamesAndDangerousSchemes(): void
    {
        $attrs = AttributeSanitizer::parse("data-track=\"hero\"\nonclick=\"alert(1)\"\nONMOUSEOVER=hack()\naria-expanded=\"false\"\ndisabled\nhref=\"javascript:alert(1)\"\nsrcdoc=\"<script>alert(1)</script>\"\nid=override\nclass=override\nstyle=color:red\ntitle='Quoted'");

        $this->assertSame([
            'data-track' => 'hero',
            'aria-expanded' => 'false',
            'disabled' => true,
            'title' => 'Quoted',
        ], $attrs);
    }

    public function testParseHandlesEmptyInputAndInvalidNames(): void
    {
        $this->assertSame([], AttributeSanitizer::parse(''));
        $this->assertSame([], AttributeSanitizer::parse("  \n  "));
        $this->assertSame([], AttributeSanitizer::parse("=value\n1bad=x\nbad name=x"));
        $this->assertSame(['href' => '/safe'], AttributeSanitizer::parse('href=/safe'));
    }

    public function testSanitizeFiltersEventHandlersReservedNamesAndDangerousSchemes(): void
    {
        $sanitized = AttributeSanitizer::sanitize([
            'id' => 'ignored-id',
            'class' => 'ignored-class',
            'style' => 'background:url(javascript:alert(1))',
            'srcset' => 'javascript:alert(1) 1x',
            'onfocus' => 'alert(1)',
            'href' => " \tjavascript:alert(1)",
            'action' => 'java&#115;cript:alert(1)',
            'poster' => 'data:image/png;base64,AAAA',
            'data-hs-overlay' => '#modal-1',
            'data-count' => 3,
            'disabled' => true,
            'hidden' => false,
            'data-list' => ['x'],
            0 => 'numeric-key',
        ]);

        $this->assertSame(['data-hs-overlay' => '#modal-1', 'data-count' => '3', 'disabled' => true], $sanitized);
    }

    public function testRenderEscapesNamesAndValues(): void
    {
        $this->assertSame('', AttributeSanitizer::render([]));
        $this->assertSame(
            'id="card" data-x="a&quot;b&lt;c&gt;" required',
            AttributeSanitizer::render(['id' => 'card', 'data-x' => 'a"b<c>', 'required' => true, 'skipped' => false]),
        );
    }

    public function testSanitizeRejectsDangerousSchemesAndUnicodeWhitespaceBypasses(): void
    {
        $sanitized = AttributeSanitizer::sanitize([
            'href' => 'file:///etc/passwd',
            'src' => 'blob:https://example.com/uuid',
            'action' => "java\u{200B}script:alert(1)",
            'poster' => "data\u{00A0}:image/png;base64,AAAA",
            'formaction' => "vbscript\u{200D}:execute",
        ]);

        $this->assertSame([], $sanitized);
    }
}
