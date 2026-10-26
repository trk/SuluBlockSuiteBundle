<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Preset;

use Sulu\Content\Application\PropertyResolver\BlockVisitor\BlockVisitorInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Totoglu\SuluBlockSuiteBundle\Normalizer\SuluDataNormalizer;
use Totoglu\SuluBlockSuiteBundle\Snippet\SnippetScopeResolver;
use Totoglu\SuluBlockSuiteBundle\Transformer\BlockTransformerInterface;

/**
 * Native Sulu BlockVisitor and BlockTransformer that hydrates preset defaults into `$block['props']` and `$block` root
 * when `$block['props']['preset']` (or `$block['props/preset']` / `$block['preset']`) is configured.
 */
final readonly class PresetBlockVisitor implements BlockVisitorInterface, BlockTransformerInterface
{
    public function __construct(
        private BlockPresetProviderInterface $presetProvider,
        private bool $presetsEnabled = true,
        private ?RequestStack $requestStack = null,
        private bool $multiWebspaceEnabled = true,
    ) {
    }

    public static function getDefaultPriority(): int
    {
        return 95;
    }

    public function supports(array $block, array $context): bool
    {
        return $this->presetsEnabled;
    }

    public function transform(array $block, array $context): array
    {
        $locale = isset($context['locale']) && \is_string($context['locale']) ? $context['locale'] : null;
        if (!isset($block['_webspace'])) {
            $ctxWebspace = $context['webspaceKey'] ?? ($context['webspace'] ?? null);
            if (\is_string($ctxWebspace) && '' !== \trim($ctxWebspace)) {
                $block['_webspace'] = \trim($ctxWebspace);
            }
        }

        return $this->visit($block, $locale);
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    public function visit(array $block, ?string $locale = null): array
    {
        if (!$this->presetsEnabled) {
            return $block;
        }

        $blockType = isset($block['type']) && \is_string($block['type']) ? \trim($block['type']) : '';
        if ('' === $blockType) {
            return $block;
        }

        $resolvedLocale = $this->resolveLocale($locale, $block);
        $resolvedWebspace = $this->resolveWebspace($block);

        $props = SuluDataNormalizer::extractProps($block);
        if (isset($block['props']) && \is_array($block['props'])) {
            $props = \array_merge($props, $block['props']);
        }
        $rawPresetId = $props['preset']
            ?? ($props['_preset'] ?? ($block['props/preset'] ?? ($block['preset'] ?? null)));
        if (\is_string($rawPresetId) && '' !== \trim($rawPresetId)) {
            $presetId = \trim($rawPresetId);
            $preset = $this->findPreset($blockType, $presetId, $resolvedLocale, $resolvedWebspace);
            if (null !== $preset) {
                if (isset($preset['props']) && \is_array($preset['props'])) {
                    foreach ($preset['props'] as $propKey => $presetPropVal) {
                        if (!\is_string($propKey)) {
                            continue;
                        }
                        if (
                            !\array_key_exists($propKey, $props)
                            || null === $props[$propKey]
                            || '' === $props[$propKey]
                            || 'default' === $props[$propKey]
                            || 'inherit' === $props[$propKey]
                        ) {
                            $props[$propKey] = $presetPropVal;
                        }
                    }
                    $block['props'] = $props;
                }

                if (isset($preset['values']) && \is_array($preset['values'])) {
                    foreach ($preset['values'] as $valKey => $presetVal) {
                        if (!\is_string($valKey) || 'type' === $valKey || 'props' === $valKey) {
                            continue;
                        }
                        if (
                            !\array_key_exists($valKey, $block)
                            || null === $block[$valKey]
                            || '' === $block[$valKey]
                            || ([] === $block[$valKey] && \is_array($presetVal) && [] !== $presetVal)
                        ) {
                            $block[$valKey] = $presetVal;
                        } elseif (
                            ('items' === $valKey || 'elements' === $valKey)
                            && \is_array($block[$valKey])
                            && \is_array($presetVal)
                        ) {
                            foreach ($presetVal as $itemIdx => $presetItem) {
                                if (!\is_array($presetItem) || !isset($block[$valKey][$itemIdx]) || !\is_array($block[$valKey][$itemIdx])) {
                                    continue;
                                }
                                foreach ($presetItem as $itemKey => $itemVal) {
                                    if (!\is_string($itemKey)) {
                                        continue;
                                    }
                                    if (
                                        !\array_key_exists($itemKey, $block[$valKey][$itemIdx])
                                        || null === $block[$valKey][$itemIdx][$itemKey]
                                        || '' === $block[$valKey][$itemIdx][$itemKey]
                                    ) {
                                        $block[$valKey][$itemIdx][$itemKey] = $itemVal;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        if ('section' === $blockType && isset($block['rows']) && \is_array($block['rows'])) {
            $block['rows'] = $this->visitSectionRows($block['rows'], $resolvedLocale);
        } elseif ('row' === $blockType) {
            if (isset($block['blocks']) && \is_array($block['blocks'])) {
                foreach ($block['blocks'] as $blockIndex => $childBlock) {
                    if (!\is_array($childBlock)) {
                        continue;
                    }
                    /** @var array<string, mixed> $typedChildBlock */
                    $typedChildBlock = [];
                    foreach ($childBlock as $k => $v) {
                        $typedChildBlock[(string) $k] = $v;
                    }
                    if ('column_break' === ($typedChildBlock['type'] ?? null)) {
                        continue;
                    }
                    $block['blocks'][$blockIndex] = $this->visit($typedChildBlock, $resolvedLocale);
                }
            }
            if (isset($block['columns']) && \is_array($block['columns'])) {
                $block['columns'] = $this->visitRowColumns($block['columns'], $resolvedLocale);
            }
        }

        return $block;
    }

    /**
     * @param array<array-key, mixed> $rows
     *
     * @return array<array-key, mixed>
     */
    private function visitSectionRows(array $rows, string $locale): array
    {
        foreach ($rows as $rowIndex => $row) {
            if (!\is_array($row) || !isset($row['columns']) || !\is_array($row['columns'])) {
                continue;
            }

            $row['columns'] = $this->visitRowColumns($row['columns'], $locale);
            $rows[$rowIndex] = $row;
        }

        return $rows;
    }

    /**
     * @param array<array-key, mixed> $columns
     *
     * @return array<array-key, mixed>
     */
    private function visitRowColumns(array $columns, string $locale): array
    {
        foreach ($columns as $colIndex => $column) {
            if (!\is_array($column) || !isset($column['blocks']) || !\is_array($column['blocks'])) {
                continue;
            }

            foreach ($column['blocks'] as $blockIndex => $childBlock) {
                if (!\is_array($childBlock)) {
                    continue;
                }

                /** @var array<string, mixed> $typedChildBlock */
                $typedChildBlock = [];
                foreach ($childBlock as $k => $v) {
                    $typedChildBlock[(string) $k] = $v;
                }

                $column['blocks'][$blockIndex] = $this->visit($typedChildBlock, $locale);
            }

            $columns[$colIndex] = $column;
        }

        return $columns;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPreset(string $blockType, string $presetId, string $locale, ?string $webspace = null): ?array
    {
        foreach ($this->presetProvider->getPresets($blockType, $locale, $webspace) as $preset) {
            if ($preset['id'] === $presetId) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function resolveWebspace(array $block): ?string
    {
        return SnippetScopeResolver::resolveActiveWebspace(
            null,
            $block,
            $this->requestStack,
            null,
            $this->multiWebspaceEnabled,
        );
    }

    /**
     * @param array<string, mixed> $block
     */
    private function resolveLocale(?string $locale, array $block): string
    {
        if (null !== $locale && '' !== \trim($locale)) {
            return \trim($locale);
        }

        if (isset($block['_locale']) && \is_string($block['_locale']) && '' !== \trim($block['_locale'])) {
            return \trim($block['_locale']);
        }

        if (isset($block['locale']) && \is_string($block['locale']) && '' !== \trim($block['locale'])) {
            return \trim($block['locale']);
        }

        $request = $this->requestStack?->getCurrentRequest();
        if (null !== $request) {
            $object = $request->attributes->get('object');
            if ($object instanceof DimensionContentInterface && null !== $object->getLocale() && '' !== $object->getLocale()) {
                return $object->getLocale();
            }

            if ('' !== $request->getLocale()) {
                return $request->getLocale();
            }
        }

        return 'en';
    }
}
