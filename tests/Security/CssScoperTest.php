<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Security;

use PHPUnit\Framework\TestCase;
use Totoglu\SuluBlockSuiteBundle\Security\CssScoper;

final class CssScoperTest extends TestCase
{
    public function testScopeReplacesElementPlaceholder(): void
    {
        $this->assertSame(
            '#card { border-radius: 12px; } #card:hover { opacity: 0.8; }',
            CssScoper::scope(".el-element { border-radius: 12px; }\n.el-element:hover { opacity: 0.8; }", '#card'),
        );
    }

    public function testScopePrefixesEachSelectorAndKeepsAtRules(): void
    {
        $scoped = CssScoper::scope("h2, p { margin: 0; }\n@media (min-width: 640px) { h2 { font-size: 2rem; } }", '#my-block');

        $this->assertStringContainsString('#my-block h2, #my-block p { margin: 0; }', $scoped);
        $this->assertStringContainsString('#my-block h2 { font-size: 2rem; }', $scoped);
        $this->assertStringContainsString('@media (min-width: 640px)', $scoped);
    }

    public function testScopeWrapsBareDeclarationsAndStripsScriptTags(): void
    {
        $this->assertSame(
            '#card-1 { border: 2px solid red; background: #fff; }',
            CssScoper::scope('<style>border: 2px solid red; background: #fff;</style><script>alert(1)</script>', '#card-1'),
        );
    }

    public function testScopeReturnsEmptyStringForEmptyCssOrPrefix(): void
    {
        $this->assertSame('', CssScoper::scope('   ', '#x'));
        $this->assertSame('', CssScoper::scope('color: red;', '  '));
        $this->assertSame('', CssScoper::scope('<script>alert(1)</script>', '#x'));
    }

    public function testScopePreventsStyleBreakoutAndImportInjection(): void
    {
        $scoped = CssScoper::scope('color: red;</sty</style>le><img src=x onerror=alert(1)>@import url("https://evil.test/x.css");', '#safe-el');

        $this->assertStringNotContainsString('<', $scoped);
        $this->assertStringNotContainsString('>', $scoped);
        $this->assertStringNotContainsString('@import', $scoped);
        $this->assertStringContainsString('#safe-el { color: red; }', $scoped);
    }

    public function testSanitizeStripsCommentObfuscationEscapesAndScriptUrls(): void
    {
        $clean = CssScoper::sanitize('#x { background: u/**/rl("javascript:alert(1)"); content: \65 xpression(alert(1)); width: expression(alert(1)); }');

        $this->assertStringNotContainsString('/*', $clean);
        $this->assertStringNotContainsString('\\', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('expression(', $clean);
        $this->assertStringContainsString('background: none', $clean);
    }

    public function testSanitizeIsIdempotentOnScopedOutput(): void
    {
        $scoped = CssScoper::scope('.el-element { color: red; }', '#a');

        $this->assertSame($scoped, CssScoper::sanitize($scoped));
        $this->assertSame('', CssScoper::sanitize('   '));
    }

    public function testGenerateElementIdIsStableAndSeedSensitive(): void
    {
        $id = CssScoper::generateElementId(['type' => 'card', 'css' => 'color: red;'], 'card');

        $this->assertMatchesRegularExpression('/^el-[0-9a-f]{8}$/', $id);
        $this->assertSame($id, CssScoper::generateElementId(['type' => 'card', 'css' => 'color: red;'], 'card'));
        $this->assertNotSame($id, CssScoper::generateElementId(['type' => 'card', 'css' => 'color: red;']));
        $this->assertNotSame($id, CssScoper::generateElementId(['type' => 'card', 'css' => 'color: blue;'], 'card'));
        $this->assertSame('el-' . \substr(\md5('{"a":1}x'), 0, 8), CssScoper::generateElementId(['a' => 1], 'x'));
    }
}
