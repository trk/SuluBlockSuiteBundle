<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Preset;

use Sulu\Content\Application\PropertyResolver\BlockVisitor\BlockVisitorInterface;

/**
 * Native Sulu BlockVisitor that hydrates preset defaults into `$block['props']` and `$block` root
 * when `$block['props']['preset']` (or `$block['props']['_preset']`) is configured.
 */
final readonly class PresetBlockVisitor implements BlockVisitorInterface
{
    public function __construct(
        private BlockPresetProviderInterface $presetProvider,
        private bool $presetsEnabled = true,
    ) {
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    public function visit(array $block): array
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
        $preset = $this->findPreset($blockType, $presetId);
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
    private function findPreset(string $blockType, string $presetId): ?array
    {
        foreach ($this->presetProvider->getPresets($blockType) as $preset) {
            if ($preset['id'] === $presetId) {
                return $preset;
            }
        }

        return null;
    }
}
