<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Renderer;

use Symfony\Contracts\Service\ResetInterface;
use Totoglu\SuluBlockSuiteBundle\Normalizer\SuluDataNormalizer;
use Totoglu\SuluBlockSuiteBundle\Transformer\BlockTransformPipelineInterface;
use Twig\Environment;

/**
 * Core block rendering engine that resolves block templates across registered
 * namespaces and delegates block collection rendering to the active UI framework strategy.
 */
final class BlockRenderer implements ResetInterface
{
    private const MAX_RENDER_DEPTH = 16;

    /**
     * @var list<string>
     */
    private array $templateNamespaces;

    private ?BlockCollectionRendererInterface $collectionRenderer = null;

    /**
     * @var array<string, string|null>
     */
    private array $templateCache = [];

    private int $renderDepth = 0;

    /**
     * @var array<string, bool>
     */
    private array $activeReusableSnippets = [];

    private bool $previewFocusScriptInjected = false;

    /**
     * @param array<string, int>|list<string> $templateNamespaces
     */
    public function __construct(
        private readonly ?Environment $twig = null,
        private readonly ?BlockTransformPipelineInterface $pipeline = null,
        array $templateNamespaces = ['blocks' => 1000, '@SuluBlockSuite/blocks' => 0],
        ?BlockCollectionRendererInterface $collectionRenderer = null,
    ) {
        $this->templateNamespaces = $this->normalizeTemplateNamespaces($templateNamespaces);
        $this->collectionRenderer = $collectionRenderer;
    }

    /**
     * @param array<string, int>|list<string> $templateNamespaces
     *
     * @return list<string>
     */
    private function normalizeTemplateNamespaces(array $templateNamespaces): array
    {
        /** @var array<string, int> $prioritized */
        $prioritized = [];
        /** @var list<string> $listed */
        $listed = [];

        foreach ($templateNamespaces as $key => $value) {
            if (\is_string($key) && \is_int($value)) {
                $trimmed = \trim($key, '/ ');
                if ('' !== $trimmed) {
                    $prioritized[$trimmed] = $value;
                }
            } elseif (\is_string($value)) {
                $trimmed = \trim($value, '/ ');
                if ('' !== $trimmed && !\in_array($trimmed, $listed, true)) {
                    $listed[] = $trimmed;
                }
            }
        }

        if ([] !== $prioritized) {
            if (!\array_key_exists('blocks', $prioritized)) {
                $prioritized['blocks'] = 1000;
            }
            if (!\array_key_exists('@SuluBlockSuite/blocks', $prioritized)) {
                $prioritized['@SuluBlockSuite/blocks'] = 0;
            }
            \arsort($prioritized, \SORT_NUMERIC);

            return \array_keys($prioritized);
        }

        $ordered = $listed;
        if (!\in_array('blocks', $ordered, true)) {
            \array_unshift($ordered, 'blocks');
        }
        if (!\in_array('@SuluBlockSuite/blocks', $ordered, true)) {
            $ordered[] = '@SuluBlockSuite/blocks';
        }

        return $ordered;
    }

    public function reset(): void
    {
        $this->templateCache = [];
        $this->renderDepth = 0;
        $this->activeReusableSnippets = [];
        $this->previewFocusScriptInjected = false;
    }

    public function setCollectionRenderer(?BlockCollectionRendererInterface $collectionRenderer): void
    {
        $this->collectionRenderer = $collectionRenderer;
    }

    /**
     * Resolves the highest-priority existing Twig template for a given block type.
     */
    public function resolveBlockTemplate(string $blockType): ?string
    {
        $cleanType = \trim($blockType);
        if ('' === $cleanType || null === $this->twig) {
            return null;
        }

        if (\array_key_exists($cleanType, $this->templateCache)) {
            return $this->templateCache[$cleanType];
        }

        $loader = $this->twig->getLoader();
        foreach ($this->templateNamespaces as $namespace) {
            $candidate = $namespace . '/' . $cleanType . '.html.twig';
            if ($loader->exists($candidate)) {
                return $this->templateCache[$cleanType] = $candidate;
            }
        }

        return $this->templateCache[$cleanType] = null;
    }

    /**
     * Processes a single block through the pipeline (if not already processed) and renders its Twig template.
     *
     * @param array<string, mixed> $block
     * @param array<string, mixed> $context
     */
    public function renderBlock(array $block, array $context = []): string
    {
        if (null === $this->twig || $this->renderDepth >= self::MAX_RENDER_DEPTH) {
            return '';
        }

        if (empty($block['_pipeline_processed']) && null !== $this->pipeline) {
            $block = $this->pipeline->process($block, $context);
            $block['_pipeline_processed'] = true;
        }

        if (!empty($block['_hidden'])) {
            return '';
        }

        $type = isset($block['type']) && \is_scalar($block['type']) ? (string) $block['type'] : '';
        $template = $this->resolveBlockTemplate($type);
        if (null === $template) {
            return '';
        }

        $reusableSnippetId = null;
        if ('reusable' === $type) {
            $reusableSnippetId = SuluDataNormalizer::extractSnippetUuid($block);
            if (null !== $reusableSnippetId && isset($this->activeReusableSnippets[$reusableSnippetId])) {
                return '';
            }
        }

        ++$this->renderDepth;
        if (null !== $reusableSnippetId) {
            $this->activeReusableSnippets[$reusableSnippetId] = true;
        }

        try {
            $html = $this->twig->render($template, \array_merge($context, [
                'block' => $block,
            ]));

            $hasSummary = isset($block['_summary'])
                && \is_array($block['_summary'])
                && isset($block['_summary']['formatted_summary'])
                && \is_string($block['_summary']['formatted_summary'])
                && '' !== \trim($block['_summary']['formatted_summary']);
            $hasEmptyState = isset($block['_empty_state'])
                && \is_array($block['_empty_state'])
                && isset($block['_empty_state']['message'])
                && \is_string($block['_empty_state']['message'])
                && '' !== \trim($block['_empty_state']['message']);

            if ('' === \trim($html) && !$hasSummary && !$hasEmptyState) {
                return $html;
            }

            if ('' !== \trim($html) && isset($block['_attributes']) && \is_array($block['_attributes']) && [] !== $block['_attributes']) {
                /** @var array<string, string|bool> $attrs */
                $attrs = $block['_attributes'];
                $html = $this->injectRootAttributes($html, $attrs);
            }

            if (
                isset($block['_scoped_css'])
                && \is_string($block['_scoped_css'])
                && '' !== \trim($block['_scoped_css'])
                && !\str_contains($html, $block['_scoped_css'])
            ) {
                $html = '<style>' . $block['_scoped_css'] . '</style>' . $html;
            }

            if ($hasSummary) {
                /** @var array<string, mixed> $summaryData */
                $summaryData = $block['_summary'];
                $summaryPosition = isset($summaryData['position']) && \is_string($summaryData['position'])
                    ? \trim($summaryData['position'])
                    : 'top';
                $summaryTemplate = $this->resolveBlockTemplate('_summary');
                if (null !== $summaryTemplate) {
                    if (\in_array($summaryPosition, ['top', 'both'], true)) {
                        $topSummaryHtml = $this->twig->render($summaryTemplate, \array_merge($context, [
                            'summary' => $summaryData,
                            'summary_position' => 'top',
                            'block' => $block,
                        ]));
                        $html = $topSummaryHtml . $html;
                    }
                    if (\in_array($summaryPosition, ['bottom', 'both'], true)) {
                        $bottomSummaryHtml = $this->twig->render($summaryTemplate, \array_merge($context, [
                            'summary' => $summaryData,
                            'summary_position' => 'bottom',
                            'block' => $block,
                        ]));
                        $html .= $bottomSummaryHtml;
                    }
                }
            }

            if ($hasEmptyState) {
                /** @var array<string, mixed> $emptyStateData */
                $emptyStateData = $block['_empty_state'];
                $emptyStateTemplate = $this->resolveBlockTemplate('_empty_state');
                if (null !== $emptyStateTemplate) {
                    $html .= $this->twig->render($emptyStateTemplate, \array_merge($context, [
                        'empty_state' => $emptyStateData,
                        'block' => $block,
                    ]));
                }
            }

            if (
                isset($block['_pagination'])
                && \is_array($block['_pagination'])
                && (($block['_pagination']['total_pages'] ?? 1) > 1)
            ) {
                $paginationTemplate = $this->resolveBlockTemplate('_pagination');
                if (null !== $paginationTemplate) {
                    $html .= $this->twig->render($paginationTemplate, \array_merge($context, [
                        'pagination' => $block['_pagination'],
                        'block' => $block,
                    ]));
                }
            }

            if (
                !$this->previewFocusScriptInjected
                && 1 === $this->renderDepth
                && (
                    true === ($context['preview'] ?? false)
                    || (isset($block['_attributes']) && \is_array($block['_attributes']) && isset($block['_attributes']['data-block-id']))
                )
            ) {
                $this->previewFocusScriptInjected = true;
                $html .= self::getPreviewBlockFocusScript();
            }

            return $html;
        } finally {
            --$this->renderDepth;
            if (null !== $reusableSnippetId) {
                unset($this->activeReusableSnippets[$reusableSnippetId]);
            }
        }
    }

    /**
     * Processes and renders a collection of blocks via the configured BlockCollectionRendererInterface.
     *
     * @param iterable<mixed> $blocks
     * @param array<string, mixed> $context
     */
    public function renderBlocks(iterable $blocks, array $context = []): string
    {
        /** @var list<array<string, mixed>> $normalizedBlocks */
        $normalizedBlocks = [];
        foreach ($blocks as $block) {
            $normalized = $this->normalizeBlock($block);
            if (null !== $normalized) {
                $normalizedBlocks[] = $normalized;
            }
        }

        if ([] === $normalizedBlocks) {
            return '';
        }

        if (null !== $this->pipeline) {
            $needsPipeline = false;
            foreach ($normalizedBlocks as $item) {
                if (empty($item['_pipeline_processed'])) {
                    $needsPipeline = true;
                    break;
                }
            }

            if ($needsPipeline) {
                $normalizedBlocks = $this->pipeline->processCollection($normalizedBlocks, $context);
                foreach ($normalizedBlocks as &$item) {
                    $item['_pipeline_processed'] = true;
                }
                unset($item);
            }
        }

        /** @var list<array<string, mixed>> $visibleBlocks */
        $visibleBlocks = [];
        foreach ($normalizedBlocks as $item) {
            if (empty($item['_hidden'])) {
                $visibleBlocks[] = $item;
            }
        }

        if ([] === $visibleBlocks) {
            return '';
        }

        if (null !== $this->collectionRenderer && empty($context['disable_auto_section'])) {
            $output = $this->collectionRenderer->renderCollection($visibleBlocks, $context);
            if (!$this->previewFocusScriptInjected && $this->hasPreviewBlocks($visibleBlocks, $context)) {
                $this->previewFocusScriptInjected = true;
                $output .= self::getPreviewBlockFocusScript();
            }

            return $output;
        }

        $output = '';
        foreach ($visibleBlocks as $block) {
            $output .= $this->renderBlock($block, $context);
        }

        return $output;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array<string, mixed> $context
     */
    private function hasPreviewBlocks(array $blocks, array $context): bool
    {
        if (true === ($context['preview'] ?? false)) {
            return true;
        }

        foreach ($blocks as $block) {
            if (isset($block['_attributes']) && \is_array($block['_attributes']) && isset($block['_attributes']['data-block-id'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Injects custom HTML attributes (such as id, data-*, aria-*) into the first root HTML element
     * if those attributes are not already present on the element.
     *
     * @param array<string, string|bool> $attributes
     */
    private function injectRootAttributes(string $html, array $attributes): string
    {
        return (string) \preg_replace_callback(
            '/^((?:\s|<!--.*?-->|<style\b[^>]*>.*?<\/style>)*<([a-zA-Z][a-zA-Z0-9:-]*))(\s[^>]*?)?(\/?>)/is',
            static function (array $matches) use ($attributes): string {
                $tagStart = $matches[1];
                $existingAttrs = $matches[3];
                $tagEnd = $matches[4];

                $injected = [];
                foreach ($attributes as $name => $value) {
                    if (false === $value || '' === \trim($name)) {
                        continue;
                    }

                    $quotedName = \preg_quote($name, '/');
                    // Skip if attribute is already present on the opening tag
                    if (1 === \preg_match('/(?:^|\s)' . $quotedName . '(?:\s*=|\s|$)/i', $existingAttrs)) {
                        continue;
                    }

                    if (true === $value) {
                        $injected[] = \htmlspecialchars($name, \ENT_QUOTES, 'UTF-8');
                    } else {
                        $strVal = (string) $value;
                        if ('' === $strVal && 'id' === \strtolower($name)) {
                            continue;
                        }
                        $injected[] = \sprintf(
                            '%s="%s"',
                            \htmlspecialchars($name, \ENT_QUOTES, 'UTF-8'),
                            \htmlspecialchars($strVal, \ENT_QUOTES, 'UTF-8'),
                        );
                    }
                }

                if ([] === $injected) {
                    return $matches[0];
                }

                return $tagStart . $existingAttrs . ' ' . \implode(' ', $injected) . $tagEnd;
            },
            $html,
            1,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeBlock(mixed $block): ?array
    {
        return SuluDataNormalizer::normalizeBlock($block);
    }

    /**
     * Returns the self-contained website iframe script for Sulu Live Preview Block Focus.
     */
    public static function getPreviewBlockFocusScript(): string
    {
        return <<<'HTML'
<script data-sulu-preview-block-focus="1">
(function () {
    if (window.parent === window) {
        return;
    }
    if (typeof window.__suluBlockSuitePreviewFocusCleanup === 'function') {
        try {
            window.__suluBlockSuitePreviewFocusCleanup();
        } catch (err) {}
    }
    window.__suluBlockSuitePreviewFocusInitialized = true;

    var activeBlock = null;
    var hoverIndicator = document.createElement('div');
    Object.assign(hoverIndicator.style, {
        position: 'absolute',
        width: '6px',
        backgroundColor: '#6db4c7',
        borderRadius: '2px',
        pointerEvents: 'none',
        zIndex: '99998',
        display: 'none',
        transition: 'top 0.1s ease, left 0.1s ease, height 0.1s ease'
    });

    var focusIcon = document.createElement('button');
    focusIcon.type = 'button';
    focusIcon.title = 'Focus block in editor';
    focusIcon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"></circle><path d="M12 2v3m0 14v3m10-10h-3M5 12H2m15.07-7.07-2.12 2.12M9.05 14.95l-2.12 2.12m0-12.14 2.12 2.12m7.9 7.9 2.12 2.12"></path></svg>';
    Object.assign(focusIcon.style, {
        position: 'absolute',
        width: '27px',
        height: '26px',
        borderRadius: '3px',
        background: '#6db4c7',
        color: '#fff',
        border: 'none',
        display: 'none',
        alignItems: 'center',
        justifyContent: 'center',
        cursor: 'pointer',
        zIndex: '99999',
        boxShadow: '0 2px 6px rgba(0, 0, 0, 0.2)',
        transition: 'opacity 0.15s ease'
    });

    function ensureAttached() {
        if (!document.body) {
            return false;
        }
        if (!document.body.contains(hoverIndicator)) {
            document.body.appendChild(hoverIndicator);
        }
        if (!document.body.contains(focusIcon)) {
            document.body.appendChild(focusIcon);
        }
        return true;
    }

    function positionIndicators(target) {
        if (!target || !ensureAttached()) {
            return;
        }
        activeBlock = target;
        var rect = target.getBoundingClientRect();

        hoverIndicator.style.top = (window.scrollY + rect.top) + 'px';
        hoverIndicator.style.left = (window.scrollX + Math.max(0, rect.left)) + 'px';
        hoverIndicator.style.height = rect.height + 'px';
        hoverIndicator.style.display = 'block';

        focusIcon.style.top = (window.scrollY + rect.top + 6) + 'px';
        focusIcon.style.left = (window.scrollX + Math.max(0, rect.left) + 8) + 'px';
        focusIcon.style.display = 'flex';
    }

    function hideIndicators() {
        hoverIndicator.style.display = 'none';
        focusIcon.style.display = 'none';
        activeBlock = null;
    }

    focusIcon.addEventListener('click', function (e) {
        e.stopPropagation();
        e.preventDefault();
        if (!activeBlock) {
            return;
        }

        var ids = [];
        var current = activeBlock;
        while (current && current !== document.body) {
            if (current.hasAttribute('data-block-id')) {
                var bid = current.getAttribute('data-block-id');
                if (bid && ids.indexOf(bid) === -1) {
                    ids.unshift(bid);
                }
            }
            current = current.parentElement;
        }

        if (ids.length === 0) {
            return;
        }

        var targetOrigin = '*';
        if (document.referrer) {
            try {
                targetOrigin = new URL(document.referrer).origin;
            } catch (err) {
                targetOrigin = '*';
            }
        }

        window.parent.postMessage(
            {
                type: 'sulu-preview-block-click',
                id: ids[ids.length - 1],
                ids: ids
            },
            targetOrigin
        );
    });

    function onMouseOver(e) {
        var target = e.target && typeof e.target.closest === 'function' ? e.target.closest('[data-block-id]') : null;
        if (!target) {
            return;
        }
        positionIndicators(target);
    }

    function onMouseMove(e) {
        if (!activeBlock) {
            return;
        }
        var rect = activeBlock.getBoundingClientRect();
        var insideBlock = e.clientX >= rect.left && e.clientX <= rect.right && e.clientY >= rect.top && e.clientY <= rect.bottom;
        var overIcon = focusIcon.contains(e.target);

        if (!insideBlock && !overIcon) {
            hideIndicators();
        }
    }

    function onAdminMessage(e) {
        var data = e.data;
        if (!data || data.type !== 'sulu-admin-block-focus' || typeof data.id !== 'string' || !data.id) {
            return;
        }

        var selector = '[data-block-id="' + data.id.replace(/"/g, '\\"') + '"]';
        var target = document.querySelector(selector);
        if (!target) {
            return;
        }

        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        positionIndicators(target);
        setTimeout(function () {
            positionIndicators(target);
        }, 350);
    }

    document.addEventListener('mouseover', onMouseOver);
    document.addEventListener('mousemove', onMouseMove);
    window.addEventListener('scroll', hideIndicators, { passive: true });
    window.addEventListener('message', onAdminMessage);

    window.__suluBlockSuitePreviewFocusCleanup = function () {
        document.removeEventListener('mouseover', onMouseOver);
        document.removeEventListener('mousemove', onMouseMove);
        window.removeEventListener('scroll', hideIndicators);
        window.removeEventListener('message', onAdminMessage);
        if (hoverIndicator.parentNode) {
            hoverIndicator.parentNode.removeChild(hoverIndicator);
        }
        if (focusIcon.parentNode) {
            focusIcon.parentNode.removeChild(focusIcon);
        }
    };
})();
</script>
HTML;
    }
}
