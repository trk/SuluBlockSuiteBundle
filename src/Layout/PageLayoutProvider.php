<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Layout;

use Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface;
use Sulu\Content\Application\ContentResolver\ContentResolverInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Service\ResetInterface;
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

    public function getLayout(string $identifier, ?string $locale = null): ?array
    {
        if (\str_contains($identifier, '|')) {
            foreach (\explode('|', $identifier) as $candidate) {
                $candidate = \trim($candidate);
                if ('' === $candidate) {
                    continue;
                }

                $resolved = $this->getLayout($candidate, $locale);
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

        $cacheKey = $resolvedLocale . ':' . $normalizedId;

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

                foreach ($this->liveSnippetsByLocale[$resolvedLocale] as $snippet) {
                    $uuid = $snippet->getUuid();
                    if ($uuid === $trimId) {
                        $matched = $this->extractSnippetData($snippet, null, $resolvedLocale);
                        if (null !== $matched) {
                            return $this->cache[$cacheKey] = $matched;
                        }
                    }

                    $targetContent = $this->findLiveDimensionContent($snippet, TemplateKeys::PAGE_LAYOUT, $resolvedLocale);

                    if (null !== $targetContent) {
                        /** @var array<string, mixed> $templateData */
                        $templateData = $targetContent->getTemplateData();

                        $key = isset($templateData['key']) && \is_scalar($templateData['key'])
                            ? \strtolower(\trim((string) $templateData['key']))
                            : null;
                        $title = $targetContent->getTitle();
                        $titleNorm = null !== $title ? \strtolower(\trim($title)) : null;
                        $titleSlug = null !== $titleNorm ? \str_replace([' ', '-'], '_', $titleNorm) : null;

                        if (
                            $key === $normalizedId
                            || $key === $sluggedId
                            || $titleNorm === $normalizedId
                            || $titleSlug === $sluggedId
                        ) {
                            $matched = $this->extractSnippetData($snippet, $targetContent, $resolvedLocale);
                            if (null !== $matched) {
                                return $this->cache[$cacheKey] = $matched;
                            }
                        }
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

    public function renderLayout(string|array|null $layout, array $context = [], ?string $locale = null): string
    {
        if (null === $layout || '' === $layout || [] === $layout || $this->renderDepth >= self::MAX_RENDER_DEPTH) {
            return '';
        }

        $resolvedLocale = $this->resolveLocale($locale);

        /** @var array<string, mixed>|null $layoutData */
        $layoutData = null;
        if (\is_string($layout)) {
            $layoutData = $this->getLayout($layout, $resolvedLocale);
        } elseif (\is_array($layout)) {
            if (isset($layout['content']) && \is_array($layout['content'])) {
                /** @var array<string, mixed> $layoutData */
                $layoutData = $layout['content'];
            } else {
                $layoutData = $layout;
            }

            if (!isset($layoutData['blocks'])) {
                $layoutId = $layoutData['id'] ?? ($layoutData['uuid'] ?? null);
                if (\is_string($layoutId) && '' !== $layoutId) {
                    $layoutData = $this->getLayout($layoutId, $resolvedLocale);
                } else {
                    $layoutData = null;
                }
            }

            if (null === $layoutData && isset($context['default_layout']) && \is_string($context['default_layout']) && '' !== $context['default_layout']) {
                $layoutData = $this->getLayout($context['default_layout'], $resolvedLocale);
            }
        }

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
                    $reusableData = $this->getReusableBlock($blockItem['snippet'], $resolvedLocale);
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

            return $this->twig->render(
                $template,
                \array_merge($context, [
                    'page' => $contextPage,
                    'content' => $layoutData,
                    'layout' => $layoutData,
                    'blocks' => $blocks,
                ]),
            );
        } finally {
            --$this->renderDepth;
            if (null !== $layoutKey) {
                unset($this->activeLayouts[$layoutKey]);
            }
        }
    }

    public function getReusableBlock(string $identifier, ?string $locale = null): ?array
    {
        $trimId = \trim($identifier);
        if ('' === $trimId || null === $this->snippetRepository) {
            return null;
        }

        $resolvedLocale = $this->resolveLocale($locale);
        $cacheKey = 'reusable:' . $resolvedLocale . ':' . $trimId;

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
                $templateData = $this->resolveSnippetContentData($content, $cacheKey);
                $title = $content->getTitle();

                $this->referenceStore?->add($snippet->getUuid(), SnippetInterface::RESOURCE_KEY);

                return $this->cache[$cacheKey] = [
                    'id' => $snippet->getUuid(),
                    'uuid' => $snippet->getUuid(),
                    'title' => $title ?? $snippet->getUuid(),
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

        $templateData = $this->resolveSnippetContentData(
            $targetContent,
            'layout:' . $resolvedLocale . ':' . $snippet->getUuid(),
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
        /** @var iterable<SnippetDimensionContentInterface> $dimensionContents */
        $dimensionContents = $snippet->getDimensionContents();

        foreach ($dimensionContents as $content) {
            if (
                $templateKey !== $content->getTemplateKey()
                || DimensionContentInterface::CURRENT_VERSION !== $content->getVersion()
                || $content->getLocale() !== $locale
            ) {
                continue;
            }
            $stage = $content->getStage();
            if (DimensionContentInterface::STAGE_LIVE === $stage || '' === $stage) {
                return $content;
            }
        }

        foreach ($dimensionContents as $content) {
            if (
                $templateKey !== $content->getTemplateKey()
                || DimensionContentInterface::CURRENT_VERSION !== $content->getVersion()
            ) {
                continue;
            }
            $contentLocale = $content->getLocale();
            if (null !== $contentLocale && '' !== $contentLocale) {
                continue;
            }
            $stage = $content->getStage();
            if (DimensionContentInterface::STAGE_LIVE === $stage || '' === $stage) {
                return $content;
            }
        }

        return null;
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
}
