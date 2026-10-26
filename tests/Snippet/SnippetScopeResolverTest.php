<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Snippet;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Sulu\Component\Webspace\Analyzer\Attributes\RequestAttributes;
use Sulu\Component\Webspace\Analyzer\RequestAnalyzerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Totoglu\SuluBlockSuiteBundle\Snippet\SnippetScopeResolver;
use Totoglu\SuluBlockSuiteBundle\Template\TemplateKeys;

final class SnippetScopeResolverTest extends TestCase
{
    public function testFindDimensionContentAndGetMergedTemplateDataUnifiesUnlocalizedAndLocalizedData(): void
    {
        $unlocalized = $this->createStub(SnippetDimensionContentInterface::class);
        $unlocalized->method('getVersion')->willReturn(DimensionContentInterface::CURRENT_VERSION);
        $unlocalized->method('getStage')->willReturn(DimensionContentInterface::STAGE_LIVE);
        $unlocalized->method('getLocale')->willReturn(null);
        $unlocalized->method('getTemplateKey')->willReturn(TemplateKeys::PAGE_LAYOUT);
        $unlocalized->method('getTemplateData')->willReturn([
            'webspace' => 'totoglu',
            'target_template' => 'homepage',
        ]);

        $localized = $this->createStub(SnippetDimensionContentInterface::class);
        $localized->method('getVersion')->willReturn(DimensionContentInterface::CURRENT_VERSION);
        $localized->method('getStage')->willReturn(DimensionContentInterface::STAGE_LIVE);
        $localized->method('getLocale')->willReturn('en');
        $localized->method('getTemplateKey')->willReturn(TemplateKeys::PAGE_LAYOUT);
        $localized->method('getTitle')->willReturn('Totoglu — Homepage');
        $localized->method('getTemplateData')->willReturn([
            'title' => 'Totoglu — Homepage',
            'blocks' => [['type' => 'hero']],
        ]);

        $snippet = $this->createStub(SnippetInterface::class);
        $snippet->method('getDimensionContents')->willReturn(new ArrayCollection([$unlocalized, $localized]));

        $found = SnippetScopeResolver::findDimensionContent(
            $snippet,
            TemplateKeys::PAGE_LAYOUT,
            'en',
            DimensionContentInterface::STAGE_LIVE,
        );

        self::assertSame($localized, $found);

        $merged = SnippetScopeResolver::getMergedTemplateData(
            $snippet,
            $found,
            DimensionContentInterface::STAGE_LIVE,
        );

        self::assertSame('totoglu', $merged['webspace'] ?? null);
        self::assertSame('homepage', $merged['target_template'] ?? null);
        self::assertSame('Totoglu — Homepage', $merged['title'] ?? null);
        self::assertSame('totoglu', SnippetScopeResolver::extractWebspace($merged));
    }

    public function testMatchesWebspaceRespectsGlobalWildcardAndMultiWebspaceFlag(): void
    {
        self::assertTrue(SnippetScopeResolver::matchesWebspace('_global', 'totoglu', true));
        self::assertTrue(SnippetScopeResolver::matchesWebspace('', 'totoglu', true));
        self::assertTrue(SnippetScopeResolver::matchesWebspace('totoglu', 'totoglu', true));
        self::assertFalse(SnippetScopeResolver::matchesWebspace('turkiye', 'totoglu', true));
        self::assertTrue(SnippetScopeResolver::matchesWebspace('turkiye', 'totoglu', false));
        self::assertTrue(SnippetScopeResolver::matchesWebspace('turkiye', null, true));
    }

    public function testResolveActiveWebspaceChecksExplicitContextRequestAnalyzerAndRequestAttributes(): void
    {
        $ws = new Webspace();
        $ws->setKey('totoglu');

        $analyzer = $this->createStub(RequestAnalyzerInterface::class);
        $analyzer->method('getWebspace')->willReturn($ws);

        self::assertSame('explicit_ws', SnippetScopeResolver::resolveActiveWebspace('explicit_ws', [], null, $analyzer, true));
        self::assertNull(SnippetScopeResolver::resolveActiveWebspace('explicit_ws', [], null, $analyzer, false));
        self::assertSame('ctx_ws', SnippetScopeResolver::resolveActiveWebspace(null, ['webspace' => ['key' => 'ctx_ws']], null, $analyzer, true));
        self::assertSame('totoglu', SnippetScopeResolver::resolveActiveWebspace(null, [], null, $analyzer, true));

        $request = new Request();
        $request->attributes->set('_sulu', new RequestAttributes(['webspaceKey' => 'req_attr_ws']));
        $requestStack = new RequestStack();
        $requestStack->push($request);

        self::assertSame('req_attr_ws', SnippetScopeResolver::resolveActiveWebspace(null, [], $requestStack, null, true));
    }

    public function testResolveLayoutCandidateValidatesInlineWebspaceAndFallsBackToDefaultLayout(): void
    {
        $loader = static fn (string $id, ?string $locale, ?string $ws): ?array => 'default' === $id && 'totoglu' === $ws
            ? ['uuid' => 'default-layout', 'blocks' => [['type' => 'navbar']]]
            : null;

        // Inline layout from another webspace is rejected in multi-webspace mode and falls back to defaultLayout
        $inlineForeign = [
            'content' => [
                'webspace' => 'turkiye',
                'blocks' => [['type' => 'hero']],
            ],
        ];

        $resolved = SnippetScopeResolver::resolveLayoutCandidate(
            $inlineForeign,
            'default',
            'en',
            'totoglu',
            true,
            $loader,
        );

        self::assertSame('default-layout', $resolved['uuid'] ?? null);

        // When multiWebspaceEnabled is false, inline layout is accepted regardless of webspace
        $resolvedSingle = SnippetScopeResolver::resolveLayoutCandidate(
            $inlineForeign,
            'default',
            'en',
            null,
            false,
            $loader,
        );

        self::assertSame('turkiye', $resolvedSingle['webspace'] ?? null);
    }
}
