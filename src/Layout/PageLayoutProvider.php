<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Layout;

use Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;

final class PageLayoutProvider implements PageLayoutProviderInterface
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

    private int $renderDepth = 0;

    /**
     * @var array<string, bool>
     */
    private array $activeLayouts = [];

    /**
     * @param string|array<array-key, string> $layoutsDirectory
     */
    public function __construct(
        private readonly ?SnippetRepositoryInterface $snippetRepository = null,
        private readonly ?Environment $twig = null,
        private readonly ?RequestStack $requestStack = null,
        string|array $layoutsDirectory = '',
        private readonly ?ReferenceStoreInterface $referenceStore = null,
    ) {
        $dirs = \is_array($layoutsDirectory) ? \array_values($layoutsDirectory) : [$layoutsDirectory];
        $this->layoutsDirectories = \array_values(\array_filter($dirs, static fn (string $dir): bool => '' !== \trim($dir)));
    }

    public function getLayout(string $identifier, ?string $locale = null): ?array
    {
        $trimId = \trim($identifier);
        if ('' === $trimId) {
            return null;
        }

        $resolvedLocale = $this->resolveLocale($locale);
        $normalizedId = \strtolower($trimId);
        $sluggedId = \str_replace([' ', '-'], '_', $normalizedId);
        $cacheKey = $resolvedLocale . ':' . $normalizedId;

        if (\array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        // 1. Try finding in Sulu snippet repository
        if (null !== $this->snippetRepository) {
            try {
                /** @var iterable<SnippetInterface> $snippets */
                $snippets = $this->snippetRepository->findBy(
                    [
                        'templateKeys' => ['page_layout'],
                        'locale' => $resolvedLocale,
                        'stage' => DimensionContentInterface::STAGE_LIVE,
                    ],
                    ['title' => 'asc'],
                    [SnippetRepositoryInterface::SELECT_SNIPPET_CONTENT => []],
                );

                foreach ($snippets as $snippet) {
                    $uuid = $snippet->getUuid();
                    if ($uuid === $trimId) {
                        $matched = $this->extractSnippetData($snippet, null, $resolvedLocale);
                        if (null !== $matched) {
                            return $this->cache[$cacheKey] = $matched;
                        }
                    }

                    $targetContent = $this->findLiveDimensionContent($snippet, 'page_layout', $resolvedLocale);

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
            if (\is_dir($dir)) {
                $yamlFile = \rtrim($dir, '/') . '/' . $sluggedId . '.yaml';
                if (\is_file($yamlFile)) {
                    try {
                        $data = Yaml::parseFile($yamlFile);
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
        if (null === $layout || '' === $layout || $this->renderDepth >= self::MAX_RENDER_DEPTH) {
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
                    $loadedLayout = $this->getLayout($layoutId, $resolvedLocale);
                    if (null !== $loadedLayout) {
                        $layoutData = $loadedLayout;
                    }
                }
            }
        }

        if (null === $layoutData || null === $this->twig) {
            return '';
        }

        $layoutKey = null;
        if (\is_string($layout)) {
            $layoutKey = \strtolower(\trim($layout));
        } elseif (isset($layoutData['uuid']) && \is_string($layoutData['uuid']) && '' !== $layoutData['uuid']) {
            $layoutKey = $layoutData['uuid'];
        } elseif (isset($layoutData['id']) && \is_string($layoutData['id']) && '' !== $layoutData['id']) {
            $layoutKey = $layoutData['id'];
        } elseif (isset($layoutData['key']) && \is_string($layoutData['key']) && '' !== $layoutData['key']) {
            $layoutKey = \strtolower(\trim($layoutData['key']));
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

            return $this->twig->render(
                $template,
                \array_merge($context, [
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
            return $this->cache[$cacheKey];
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

            $content = $this->findLiveDimensionContent($snippet, 'reusable_block', $resolvedLocale);
            if (null !== $content) {
                /** @var array<string, mixed> $templateData */
                $templateData = $content->getTemplateData();
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
        $targetContent = $dimensionContent ?? $this->findLiveDimensionContent(
            $snippet,
            'page_layout',
            $this->resolveLocale($locale),
        );

        if (null === $targetContent) {
            return null;
        }

        $this->referenceStore?->add($snippet->getUuid(), SnippetInterface::RESOURCE_KEY);

        /** @var array<string, mixed> $templateData */
        $templateData = $targetContent->getTemplateData();
        $title = $targetContent->getTitle();

        $templateData['id'] = $snippet->getUuid();
        $templateData['uuid'] = $snippet->getUuid();
        $templateData['title'] = $title ?? $snippet->getUuid();
        $templateData['blocks'] = isset($templateData['blocks']) && \is_array($templateData['blocks'])
            ? $templateData['blocks']
            : [];

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
            if ($templateKey !== $content->getTemplateKey()) {
                continue;
            }
            $contentLocale = $content->getLocale();
            if (null !== $contentLocale && '' !== $contentLocale && $contentLocale !== $locale) {
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
