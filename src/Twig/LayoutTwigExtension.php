<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Twig;

use Sulu\Component\Webspace\Analyzer\RequestAnalyzerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Totoglu\SuluBlockSuiteBundle\Layout\LayoutResolverInterface;
use Totoglu\SuluBlockSuiteBundle\Layout\PageLayoutProviderInterface;
use Totoglu\SuluBlockSuiteBundle\Snippet\SnippetScopeResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class LayoutTwigExtension extends AbstractExtension
{
    private const MAX_RECURSION_DEPTH = 10;

    public function __construct(
        private readonly LayoutResolverInterface $layoutResolver,
        private readonly ?PageLayoutProviderInterface $layoutProvider = null,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?RequestAnalyzerInterface $requestAnalyzer = null,
        private readonly bool $multiWebspaceEnabled = true,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sulu_layout_classes', [$this, 'getClasses']),
            new TwigFunction('layout_classes', [$this, 'getClasses']),
            new TwigFunction('sulu_layout_class', [$this, 'getClass']),
            new TwigFunction('layout_class', [$this, 'getClass']),
            new TwigFunction('sulu_layout_attributes', [$this, 'getAttributes'], ['is_safe' => ['html']]),
            new TwigFunction('layout_attributes', [$this, 'getAttributes'], ['is_safe' => ['html']]),
            new TwigFunction('sulu_layout_load', [$this, 'loadLayout']),
            new TwigFunction('layout_load', [$this, 'loadLayout']),
            new TwigFunction('sulu_layout_exists', [$this, 'hasLayout']),
            new TwigFunction('layout_exists', [$this, 'hasLayout']),
            new TwigFunction('sulu_layout_has_block', [$this, 'hasBlock']),
            new TwigFunction('layout_has_block', [$this, 'hasBlock']),
            new TwigFunction('sulu_layout_render', [$this, 'renderLayout'], ['is_safe' => ['html']]),
            new TwigFunction('layout_render', [$this, 'renderLayout'], ['is_safe' => ['html']]),
            new TwigFunction('sulu_reusable_block_load', [$this, 'loadReusableBlock']),
            new TwigFunction('reusable_block_load', [$this, 'loadReusableBlock']),
        ];
    }

    public function getClass(string $group, ?string $value, string $default = ''): string
    {
        return $this->layoutResolver->resolveClass($group, $value, $default);
    }

    /**
     * @param string|array<string, mixed>|null $layout
     * @param array<string, mixed> $context
     */
    public function renderLayout(string|array|null $layout, array $context = [], ?string $locale = null, ?string $webspaceKey = null): string
    {
        if (null === $this->layoutProvider) {
            return '';
        }

        return $this->layoutProvider->renderLayout($layout, $context, $locale, $webspaceKey);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadLayout(string $identifier, ?string $locale = null, ?string $webspaceKey = null): ?array
    {
        if (null === $this->layoutProvider) {
            return null;
        }

        return $this->layoutProvider->getLayout($identifier, $locale, $webspaceKey);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadReusableBlock(string $identifier, ?string $locale = null, ?string $webspaceKey = null): ?array
    {
        if (null === $this->layoutProvider) {
            return null;
        }

        return $this->layoutProvider->getReusableBlock($identifier, $locale, $webspaceKey);
    }

    /**
     * Checks whether a page layout can be resolved for the given layout selection or fallback key.
     *
     * @param string|array<string, mixed>|null $layout
     */
    public function hasLayout(
        string|array|null $layout,
        ?string $defaultLayout = null,
        ?string $locale = null,
        ?string $webspaceKey = null,
    ): bool {
        return null !== $this->resolveLayoutData($layout, $defaultLayout, $locale, $webspaceKey);
    }

    /**
     * Checks whether the resolved page layout contains at least one block of the given type.
     *
     * @param string|array<string, mixed>|null $layout
     */
    public function hasBlock(
        string|array|null $layout,
        string $blockType,
        ?string $defaultLayout = null,
        ?string $locale = null,
        ?string $webspaceKey = null,
    ): bool {
        if ('' === \trim($blockType)) {
            return false;
        }

        $resolvedWebspace = SnippetScopeResolver::resolveActiveWebspace(
            $webspaceKey,
            [],
            $this->requestStack,
            $this->requestAnalyzer,
            $this->multiWebspaceEnabled,
        );
        $layoutData = $this->resolveLayoutData($layout, $defaultLayout, $locale, $resolvedWebspace);

        if (!\is_array($layoutData)) {
            return false;
        }

        $innerContent = isset($layoutData['content']) && \is_array($layoutData['content']) ? $layoutData['content'] : [];
        $rawBlocks = $layoutData['blocks'] ?? ($innerContent['blocks'] ?? []);
        if (!\is_array($rawBlocks)) {
            return false;
        }

        return $this->blocksContainType($rawBlocks, $blockType, $locale, $resolvedWebspace);
    }

    /**
     * @param string|array<string, mixed>|null $layout
     *
     * @return array<string, mixed>|null
     */
    private function resolveLayoutData(
        string|array|null $layout,
        ?string $defaultLayout = null,
        ?string $locale = null,
        ?string $webspaceKey = null,
    ): ?array {
        $resolvedWebspace = SnippetScopeResolver::resolveActiveWebspace(
            $webspaceKey,
            [],
            $this->requestStack,
            $this->requestAnalyzer,
            $this->multiWebspaceEnabled,
        );

        return SnippetScopeResolver::resolveLayoutCandidate(
            $layout,
            $defaultLayout,
            $locale,
            $resolvedWebspace,
            $this->multiWebspaceEnabled,
            $this->loadLayout(...),
        );
    }

    /**
     * @param array<int|string, mixed> $blocks
     * @param array<string, bool> $visited
     */
    private function blocksContainType(
        array $blocks,
        string $blockType,
        ?string $locale = null,
        ?string $webspaceKey = null,
        array &$visited = [],
        int $depth = 0,
    ): bool {
        if ($depth > self::MAX_RECURSION_DEPTH) {
            return false;
        }

        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? null;
            if ($type === $blockType) {
                return true;
            }

            if ('reusable' === $type && isset($block['snippet'])) {
                $snippetId = null;
                $snippetBlocks = null;
                if (\is_string($block['snippet'])) {
                    $snippetId = $block['snippet'];
                    if (isset($visited[$snippetId])) {
                        continue;
                    }
                    $visited[$snippetId] = true;

                    $reusable = $this->loadReusableBlock($snippetId, $locale, $webspaceKey);
                    $snippetBlocks = \is_array($reusable['blocks'] ?? null) ? $reusable['blocks'] : null;
                } elseif (\is_array($block['snippet'])) {
                    $rawId = $block['snippet']['id'] ?? ($block['snippet']['uuid'] ?? null);
                    if (\is_string($rawId) && '' !== $rawId) {
                        $snippetId = $rawId;
                        if (isset($visited[$snippetId])) {
                            continue;
                        }
                        $visited[$snippetId] = true;
                    }

                    if (\is_array($block['snippet']['blocks'] ?? null)) {
                        $snippetBlocks = $block['snippet']['blocks'];
                    }
                }

                if (null !== $snippetBlocks && $this->blocksContainType($snippetBlocks, $blockType, $locale, $webspaceKey, $visited, $depth + 1)) {
                    return true;
                }
            }

            if ('section' === $type) {
                if (isset($block['rows']) && \is_array($block['rows'])) {
                    foreach ($block['rows'] as $row) {
                        if (!\is_array($row) || !isset($row['columns']) || !\is_array($row['columns'])) {
                            continue;
                        }
                        foreach ($row['columns'] as $column) {
                            if (!\is_array($column) || !isset($column['blocks']) || !\is_array($column['blocks'])) {
                                continue;
                            }
                            if ($this->blocksContainType($column['blocks'], $blockType, $locale, $webspaceKey, $visited, $depth + 1)) {
                                return true;
                            }
                        }
                    }
                }

                if (isset($block['blocks']) && \is_array($block['blocks'])) {
                    if ($this->blocksContainType($block['blocks'], $blockType, $locale, $webspaceKey, $visited, $depth + 1)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $layoutData
     *
     * @return array<string, string>
     */
    public function getClasses(?array $layoutData): array
    {
        return $this->layoutResolver->resolveClasses($layoutData);
    }

    /**
     * @param array<string, mixed>|null $layoutData
     */
    public function getAttributes(?array $layoutData): string
    {
        $attrs = $this->layoutResolver->resolveAttributes($layoutData);
        $result = [];
        foreach ($attrs as $k => $v) {
            if (1 !== \preg_match('/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/', $k)) {
                continue;
            }
            $result[] = \sprintf('%s="%s"', $k, \htmlspecialchars($v, \ENT_QUOTES, 'UTF-8'));
        }

        return \implode(' ', $result);
    }
}
