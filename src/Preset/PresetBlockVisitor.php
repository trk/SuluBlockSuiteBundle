<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Preset;

use Sulu\Content\Application\PropertyResolver\BlockVisitor\BlockVisitorInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Native Sulu BlockVisitor that hydrates preset defaults into `$block['props']` and `$block` root
 * when `$block['props']['preset']` (or `$block['props']['_preset']`) is configured.
 */
final readonly class PresetBlockVisitor implements BlockVisitorInterface
{
    public function __construct(
        private BlockPresetProviderInterface $presetProvider,
        private bool $presetsEnabled = true,
        private ?RequestStack $requestStack = null,
    ) {
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

        $props = isset($block['props']) && \is_array($block['props']) ? $block['props'] : [];
        $rawPresetId = $props['preset'] ?? ($props['_preset'] ?? null);
        if (!\is_string($rawPresetId) || '' === \trim($rawPresetId)) {
            return $block;
        }

        $presetId = \trim($rawPresetId);
        $resolvedLocale = $this->resolveLocale($locale, $block);
        $preset = $this->findPreset($blockType, $presetId, $resolvedLocale);
        if (null === $preset) {
            return $block;
        }

        if (isset($preset['props']) && \is_array($preset['props'])) {
            foreach ($preset['props'] as $propKey => $presetPropVal) {
                if (!\is_string($propKey)) {
                    continue;
                }
                if (!\array_key_exists($propKey, $props) || null === $props[$propKey] || '' === $props[$propKey]) {
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
                if (!\array_key_exists($valKey, $block) || null === $block[$valKey] || '' === $block[$valKey]) {
                    $block[$valKey] = $presetVal;
                }
            }
        }

        return $block;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPreset(string $blockType, string $presetId, string $locale): ?array
    {
        foreach ($this->presetProvider->getPresets($blockType, $locale) as $preset) {
            if ($preset['id'] === $presetId) {
                return $preset;
            }
        }

        return null;
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
