<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Layout;

use Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface;
use Sulu\Component\Webspace\Analyzer\RequestAnalyzerInterface;
use Sulu\Content\Application\ContentResolver\ContentResolverInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Service\ResetInterface;
use Totoglu\SuluBlockSuiteBundle\Snippet\SnippetScopeResolver;
use Totoglu\SuluBlockSuiteBundle\Template\TemplateKeys;
use Twig\Environment;

final class PageLayoutProvider implements PageLayoutProviderInterface, ResetInterface
{
    private const MAX_RENDER_DEPTH = 10;

    /**
     * @var array<string, array<string, mixed>|null>
     */
    private array $cache = [];

    /**
     * @var list<string>
     */
    private array $layoutsDirectories;

    /**
     * @var array<string, list<SnippetInterface>>
     */
    private array $liveSnippetsByLocale = [];

    private int $renderDepth = 0;

    /**
     * @var array<string, bool>
     */
    private array $activeLayouts = [];

    /**
     * @var array<string, bool>
     */
    private array $resolvingIds = [];

    /**
     * @param string|array<array-key, string> $layoutsDirectory
     */
    public function __construct(
        private readonly ?SnippetRepositoryInterface $snippetRepository = null,
        private readonly ?Environment $twig = null,
        private readonly ?RequestStack $requestStack = null,
        string|array $layoutsDirectory = '',
        private readonly ?ReferenceStoreInterface $referenceStore = null,
        private readonly ?ContentResolverInterface $contentResolver = null,
        private readonly ?RequestAnalyzerInterface $requestAnalyzer = null,
        private readonly bool $multiWebspaceEnabled = true,
    ) {
        $dirs = \is_array($layoutsDirectory) ? \array_values($layoutsDirectory) : [$layoutsDirectory];
        $this->layoutsDirectories = \array_values(\array_filter($dirs, static fn (string $dir): bool => '' !== \trim($dir)));
    }

    public function reset(): void
    {
        $this->cache = [];
        $this->liveSnippetsByLocale = [];
        $this->renderDepth = 0;
        $this->activeLayouts = [];
        $this->resolvingIds = [];
    }

    public function getLayout(string $identifier, ?string $locale = null, ?string $webspaceKey = null): ?array
    {
        $resolvedWebspace = $this->resolveWebspaceKey($webspaceKey);

        if (\str_contains($identifier, '|')) {
            foreach (\explode('|', $identifier) as $candidate) {
                $candidate = \trim($candidate);
                if ('' === $candidate) {
                    continue;
                }

                $resolved = $this->getLayout($candidate, $locale, $resolvedWebspace);
                if (null !== $resolved) {
                    return $resolved;
                }
            }

            return null;
        }

        $trimId = \trim($identifier);
        if ('' === $trimId) {
            return null;
        }

        $resolvedLocale = $this->resolveLocale($locale);
        $normalizedId = \strtolower($trimId);
        $sluggedId = \str_replace([' ', '-'], '_', $normalizedId);

        if (1 !== \preg_match('/^[a-zA-Z0-9_-]+$/', $sluggedId)) {
            return null;
        }

        $cacheKey = $resolvedLocale . ':' . ($resolvedWebspace ?? '') . ':' . $normalizedId;

        if (\array_key_exists($cacheKey, $this->cache)) {
            $cached = $this->cache[$cacheKey];
            if (null !== $cached) {
                $cachedUuid = $cached['uuid'] ?? ($cached['id'] ?? null);
                if (\is_string($cachedUuid) && '' !== $cachedUuid) {
                    $this->referenceStore?->add($cachedUuid, SnippetInterface::RESOURCE_KEY);
                }
            }

            return $cached;
        }

        // 1. Try finding in Sulu snippet repository
        if (null !== $this->snippetRepository) {
            try {
                if (!isset($this->liveSnippetsByLocale[$resolvedLocale])) {
                    /** @var iterable<SnippetInterface> $fetched */
                    $fetched = $this->snippetRepository->findBy(
                        [
                            'templateKeys' => [TemplateKeys::PAGE_LAYOUT],
                            'locale' => $resolvedLocale,
                            'stage' => DimensionContentInterface::STAGE_LIVE,
                        ],
                        ['title' => 'asc'],
                        [SnippetRepositoryInterface::SELECT_SNIPPET_CONTENT => []],
                    );

                    $list = [];
                    foreach ($fetched as $item) {
                        $list[] = $item;
                    }
                    $this->liveSnippetsByLocale[$resolvedLocale] = $list;
                }

                /** @var array{snippet: SnippetInterface, content: SnippetDimensionContentInterface}|null $exactTemplateMatch */
                $exactTemplateMatch = null;
                /** @var array{snippet: SnippetInterface, content: SnippetDimensionContentInterface}|null $exactKeyMatch */
                $exactKeyMatch = null;
                /** @var array{snippet: SnippetInterface, content: SnippetDimensionContentInterface}|null $fallbackTemplateMatch */
                $fallbackTemplateMatch = null;
                /** @var array{snippet: SnippetInterface, content: SnippetDimensionContentInterface}|null $fallbackKeyMatch */
                $fallbackKeyMatch = null;

                foreach ($this->liveSnippetsByLocale[$resolvedLocale] as $snippet) {
                    $targetContent = $this->findLiveDimensionContent($snippet, TemplateKeys::PAGE_LAYOUT, $resolvedLocale);
                    if (null === $targetContent) {
                        continue;
                    }

                    $templateData = $this->getMergedLiveTemplateData($snippet, $targetContent);
                    $snippetWebspace = SnippetScopeResolver::extractWebspace($templateData, '');

                    $uuid = $snippet->getUuid();
                    if ($uuid === $trimId) {
                        if (!SnippetScopeResolver::matchesWebspace($snippetWebspace, $resolvedWebspace, $this->multiWebspaceEnabled)) {
                            continue;
                        }

                        $matched = $this->extractSnippetData($snippet, $targetContent, $resolvedLocale);
                        if (null !== $matched) {
                            return $this->cache[$cacheKey] = $matched;
                        }
                    }

                    if (!SnippetScopeResolver::matchesWebspace($snippetWebspace, $resolvedWebspace, $this->multiWebspaceEnabled)) {
                        continue;
                    }

                    $targetTemplate = isset($templateData['target_template']) && \is_scalar($templateData['target_template'])
                        ? \strtolower(\trim((string) $templateData['target_template']))
                        : null;

                    $title = $targetContent->getTitle();
                    $titleNorm = null !== $title ? \strtolower(\trim($title)) : null;
                    $titleSlug = null !== $titleNorm ? \str_replace([' ', '-'], '_', $titleNorm) : null;

                    $matchesTemplate = null !== $targetTemplate && '' !== $targetTemplate
                        && ($targetTemplate === $normalizedId || $targetTemplate === $sluggedId);
                    $matchesTitle = (null === $targetTemplate || '' === $targetTemplate)
                        && ($titleNorm === $normalizedId || $titleSlug === $sluggedId);

                    if (!$matchesTemplate && !$matchesTitle) {
                        continue;
                    }

                    $isExactWebspace = null !== $resolvedWebspace && $snippetWebspace === $resolvedWebspace;

                    if ($isExactWebspace && $matchesTemplate) {
                        $exactTemplateMatch ??= ['snippet' => $snippet, 'content' => $targetContent];
                        break;
                    }

                    if ($isExactWebspace) {
                        $exactKeyMatch ??= ['snippet' => $snippet, 'content' => $targetContent];

                        continue;
                    }

                    if ($matchesTemplate) {
                        $fallbackTemplateMatch ??= ['snippet' => $snippet, 'content' => $targetContent];

                        continue;
                    }

                    $fallbackKeyMatch ??= ['snippet' => $snippet, 'content' => $targetContent];
                }

                $winner = $exactTemplateMatch ?? $exactKeyMatch ?? $fallbackTemplateMatch ?? $fallbackKeyMatch;
                if (null !== $winner) {
                    $matched = $this->extractSnippetData($winner['snippet'], $winner['content'], $resolvedLocale);
                    if (null !== $matched) {
                        return $this->cache[$cacheKey] = $matched;
                    }
                }
            } catch (\Throwable) {
                // Graceful fallback if snippets database or tables are unavailable
            }
        }

        // 2. Try filesystem YAML layouts if directory exists
        foreach ($this->layoutsDirectories as $dir) {
            $realDir = \realpath($dir);
            if (false === $realDir || !\is_dir($realDir)) {
                continue;
            }

            $yamlFile = $realDir . \DIRECTORY_SEPARATOR . $sluggedId . '.yaml';
            if (\is_file($yamlFile)) {
                $realFile = \realpath($yamlFile);
                if (false !== $realFile && \str_starts_with($realFile, $realDir . \DIRECTORY_SEPARATOR)) {
                    try {
                        $data = Yaml::parseFile($realFile);
                        if (\is_array($data)) {
                            /** @var array<string, mixed> $data */
                            return $this->cache[$cacheKey] = $data;
                        }
                    } catch (ParseException) {
                        // Fallback on parse error
                    }
                }
            }
        }

        return $this->cache[$cacheKey] = null;
    }

    public function renderLayout(string|array|null $layout, array $context = [], ?string $locale = null, ?string $webspaceKey = null): string
    {
        if (null === $layout || '' === $layout || [] === $layout || $this->renderDepth >= self::MAX_RENDER_DEPTH) {
            return '';
        }

        $resolvedLocale = $this->resolveLocale($locale);
        $resolvedWebspace = $this->resolveWebspaceKey($webspaceKey, $context);
        $defaultLayout = isset($context['default_layout']) && \is_string($context['default_layout'])
            ? $context['default_layout']
            : null;

        $layoutData = SnippetScopeResolver::resolveLayoutCandidate(
            $layout,
            $defaultLayout,
            $resolvedLocale,
            $resolvedWebspace,
            $this->multiWebspaceEnabled,
            $this->getLayout(...),
        );

        if (null === $layoutData || null === $this->twig) {
            return '';
        }

        $layoutKey = null;
        if (isset($layoutData['uuid']) && \is_string($layoutData['uuid']) && '' !== $layoutData['uuid']) {
            $layoutKey = $layoutData['uuid'];
        } elseif (isset($layoutData['id']) && \is_string($layoutData['id']) && '' !== $layoutData['id']) {
            $layoutKey = $layoutData['id'];
        } elseif (isset($layoutData['key']) && \is_string($layoutData['key']) && '' !== $layoutData['key']) {
            $layoutKey = \strtolower(\trim($layoutData['key']));
        } elseif (\is_string($layout)) {
            $layoutKey = \strtolower(\trim($layout));
        }

        if (null !== $layoutKey && isset($this->activeLayouts[$layoutKey])) {
            return '';
        }

        ++$this->renderDepth;
        if (null !== $layoutKey) {
            $this->activeLayouts[$layoutKey] = true;
        }

        try {
            $innerContent = isset($layoutData['content']) && \is_array($layoutData['content']) ? $layoutData['content'] : [];
            /** @var mixed $rawBlocks */
            $rawBlocks = $layoutData['blocks'] ?? ($innerContent['blocks'] ?? []);
            /** @var list<array<string, mixed>> $blocks */
            $blocks = \is_array($rawBlocks) ? \array_values($rawBlocks) : [];
            foreach ($blocks as &$blockItem) {
                if (isset($blockItem['type']) && 'reusable' === $blockItem['type'] && isset($blockItem['snippet']) && \is_string($blockItem['snippet'])) {
                    $reusableData = $this->getReusableBlock($blockItem['snippet'], $resolvedLocale, $resolvedWebspace);
                    if (null !== $reusableData) {
                        $blockItem['snippet'] = $reusableData;
                    }
                }
            }
            unset($blockItem);
            $layoutData['blocks'] = $blocks;

            $template = 'snippets/page_layout.html.twig';
            if (!$this->twig->getLoader()->exists($template)) {
                $template = '@SuluBlockSuite/snippets/page_layout.html.twig';
            }

            $contextPage = \array_key_exists('page', $context)
                ? $context['page']
                : ($context['content'] ?? null);

            $isLayoutPreview = (bool) ($context['_is_page_layout_preview'] ?? false);

            return $this->twig->render(
                $template,
                \array_merge($context, [
                    'page' => $contextPage,
                    'content' => $layoutData,
                    'layout' => $layoutData,
                    'blocks' => $blocks,
                    '_in_page_layout' => !$isLayoutPreview,
                ]),
            );
        } finally {
            --$this->renderDepth;
            if (null !== $layoutKey) {
                unset($this->activeLayouts[$layoutKey]);
            }
        }
    }

    public function getReusableBlock(string $identifier, ?string $locale = null, ?string $webspaceKey = null): ?array
    {
        $trimId = \trim($identifier);
        if ('' === $trimId || null === $this->snippetRepository) {
            return null;
        }

        $resolvedLocale = $this->resolveLocale($locale);
        $resolvedWebspace = $this->resolveWebspaceKey($webspaceKey);
        $cacheKey = 'reusable:' . $resolvedLocale . ':' . ($resolvedWebspace ?? '') . ':' . $trimId;

        if (\array_key_exists($cacheKey, $this->cache)) {
            $cached = $this->cache[$cacheKey];
            if (null !== $cached) {
                $cachedUuid = $cached['uuid'] ?? ($cached['id'] ?? $trimId);
                if (\is_string($cachedUuid) && '' !== $cachedUuid) {
                    $this->referenceStore?->add($cachedUuid, SnippetInterface::RESOURCE_KEY);
                }
            }

            return $cached;
        }

        try {
            $snippet = $this->snippetRepository->getOneBy(
                [
                    'uuid' => $trimId,
                    'locale' => $resolvedLocale,
                    'stage' => DimensionContentInterface::STAGE_LIVE,
                ],
                [SnippetRepositoryInterface::SELECT_SNIPPET_CONTENT => []],
            );

            $content = $this->findLiveDimensionContent($snippet, TemplateKeys::REUSABLE_BLOCK, $resolvedLocale);
            if (null !== $content) {
                $mergedRaw = $this->getMergedLiveTemplateData($snippet, $content);
                $snippetWebspace = SnippetScopeResolver::extractWebspace($mergedRaw);

                if (!SnippetScopeResolver::matchesWebspace($snippetWebspace, $resolvedWebspace, $this->multiWebspaceEnabled)) {
                    return $this->cache[$cacheKey] = null;
                }

                $templateData = \array_replace($mergedRaw, $this->resolveSnippetContentData($content, $cacheKey));
                $title = $content->getTitle();

                $this->referenceStore?->add($snippet->getUuid(), SnippetInterface::RESOURCE_KEY);

                return $this->cache[$cacheKey] = [
                    'id' => $snippet->getUuid(),
                    'uuid' => $snippet->getUuid(),
                    'title' => $title ?? $snippet->getUuid(),
                    'webspace' => $snippetWebspace,
                    'blocks' => isset($templateData['blocks']) && \is_array($templateData['blocks'])
                        ? $templateData['blocks']
                        : [],
                ];
            }
        } catch (\Throwable) {
            // Graceful fallback
        }

        return $this->cache[$cacheKey] = null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractSnippetData(
        SnippetInterface $snippet,
        ?SnippetDimensionContentInterface $dimensionContent = null,
        ?string $locale = null,
    ): ?array {
        $resolvedLocale = $this->resolveLocale($locale);
        $targetContent = $dimensionContent ?? $this->findLiveDimensionContent(
            $snippet,
            TemplateKeys::PAGE_LAYOUT,
            $resolvedLocale,
        );

        if (null === $targetContent) {
            return null;
        }

        $this->referenceStore?->add($snippet->getUuid(), SnippetInterface::RESOURCE_KEY);

        $templateData = \array_replace(
            $this->getMergedLiveTemplateData($snippet, $targetContent),
            $this->resolveSnippetContentData(
                $targetContent,
                'layout:' . $resolvedLocale . ':' . $snippet->getUuid(),
            ),
        );
        $title = $targetContent->getTitle();

        $templateData['id'] = $snippet->getUuid();
        $templateData['uuid'] = $snippet->getUuid();
        $templateData['title'] = $title ?? $snippet->getUuid();
        $templateData['blocks'] = isset($templateData['blocks']) && \is_array($templateData['blocks'])
            ? $templateData['blocks']
            : [];

        return $templateData;
    }

    /**
     * @return array<string, mixed>
     */
    private function getMergedLiveTemplateData(
        SnippetInterface $snippet,
        SnippetDimensionContentInterface $targetContent,
    ): array {
        return SnippetScopeResolver::getMergedTemplateData(
            $snippet,
            $targetContent,
            DimensionContentInterface::STAGE_LIVE,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSnippetContentData(
        SnippetDimensionContentInterface $dimensionContent,
        string $guardKey,
    ): array {
        /** @var array<string, mixed> $templateData */
        $templateData = $dimensionContent->getTemplateData();

        if (null === $this->contentResolver || isset($this->resolvingIds[$guardKey])) {
            return $templateData;
        }

        $this->resolvingIds[$guardKey] = true;
        try {
            $resolved = $this->contentResolver->resolve($dimensionContent);

            return \array_replace($templateData, $resolved['content']);
        } catch (\Throwable) {
            // Fallback to raw templateData
        } finally {
            unset($this->resolvingIds[$guardKey]);
        }

        return $templateData;
    }

    private function findLiveDimensionContent(
        SnippetInterface $snippet,
        string $templateKey,
        string $locale,
    ): ?SnippetDimensionContentInterface {
        return SnippetScopeResolver::findDimensionContent(
            $snippet,
            $templateKey,
            $locale,
            DimensionContentInterface::STAGE_LIVE,
        );
    }

    private function resolveLocale(?string $locale): string
    {
        if (null !== $locale && '' !== $locale) {
            return $locale;
        }

        if (null !== $this->requestStack) {
            $request = $this->requestStack->getCurrentRequest();
            if (null !== $request) {
                return $request->getLocale();
            }
        }

        return 'en';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveWebspaceKey(?string $webspaceKey = null, array $context = []): ?string
    {
        return SnippetScopeResolver::resolveActiveWebspace(
            $webspaceKey,
            $context,
            $this->requestStack,
            $this->requestAnalyzer,
            $this->multiWebspaceEnabled,
        );
    }
}
