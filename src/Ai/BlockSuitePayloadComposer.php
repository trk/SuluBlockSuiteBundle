<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Ai;

/**
 * Composes and validates canonical Sulu Block Suite block payloads for AI/MCP agents
 * and protects non-translatable technical `props/*` keys during AI translation.
 */
final class BlockSuitePayloadComposer
{
    public function __construct(
        private readonly BlockSuiteSchemaInspector $schemaInspector,
    ) {
    }

    /**
     * Composes a canonical Sulu block payload by merging preset defaults, content fields,
     * and `props` (synchronized across both nested `props` and flat `props/*` keys).
     *
     * @param array<string, mixed> $content
     * @param array<string, mixed> $props
     *
     * @return array<string, mixed>
     */
    public function compose(
        string $blockType,
        array $content = [],
        array $props = [],
        ?string $preset = null,
        string $locale = 'en',
    ): array {
        $cleanType = \trim($blockType);
        if ('' === $cleanType) {
            throw new \InvalidArgumentException('Block type cannot be empty.');
        }

        /** @var array<string, mixed> $mergedContent */
        $mergedContent = [];
        /** @var array<string, mixed> $mergedProps */
        $mergedProps = [];

        $cleanPreset = null !== $preset ? \trim($preset) : '';
        if ('' !== $cleanPreset) {
            $availablePresets = $this->schemaInspector->listPresets($cleanType, $locale);
            $matchedPreset = null;
            foreach ($availablePresets as $candidate) {
                if ($candidate['id'] === $cleanPreset) {
                    $matchedPreset = $candidate;
                    break;
                }
            }

            if (null === $matchedPreset && [] !== $availablePresets) {
                $presetIds = \implode(', ', \array_map(
                    static fn (array $p): string => $p['id'],
                    $availablePresets,
                ));

                throw new \InvalidArgumentException(\sprintf(
                    'Unknown preset "%s" for block "%s". Available presets: %s.',
                    $cleanPreset,
                    $cleanType,
                    $presetIds,
                ));
            }

            if (null !== $matchedPreset) {
                foreach ($matchedPreset['values'] as $valKey => $val) {
                    $mergedContent[$valKey] = $val;
                }
                foreach ($matchedPreset['props'] as $propKey => $propVal) {
                    $cleanPropKey = $this->stripPropsPrefix($propKey);
                    if ('' !== $cleanPropKey) {
                        $mergedProps[$cleanPropKey] = $propVal;
                    }
                }
            }

            $mergedProps['preset'] = $cleanPreset;
        }

        foreach ($content as $key => $value) {
            if ('type' === $key) {
                continue;
            }
            if ('props' === $key && \is_array($value)) {
                foreach ($value as $subKey => $subVal) {
                    if (\is_string($subKey) && '' !== $subKey) {
                        $cleanSub = $this->stripPropsPrefix($subKey);
                        if ('' !== $cleanSub) {
                            $mergedProps[$cleanSub] = $subVal;
                        }
                    }
                }
                continue;
            }
            if (\str_starts_with($key, 'props/')) {
                $subKey = $this->stripPropsPrefix($key);
                if ('' !== $subKey) {
                    $mergedProps[$subKey] = $value;
                }
                continue;
            }
            $mergedContent[$key] = $value;
        }

        foreach ($props as $propKey => $propVal) {
            $cleanPropKey = $this->stripPropsPrefix($propKey);
            if ('' !== $cleanPropKey) {
                $mergedProps[$cleanPropKey] = $propVal;
            }
        }

        $this->validatePropsAgainstSchema($cleanType, $mergedProps, $locale);

        $payload = ['type' => $cleanType];
        foreach ($mergedContent as $key => $value) {
            $payload[$key] = $value;
        }

        if ([] !== $mergedProps) {
            $payload['props'] = $mergedProps;
            foreach ($mergedProps as $propKey => $propVal) {
                $payload['props/' . $propKey] = $propVal;
            }
        }

        return $payload;
    }

    /**
     * Restores non-translatable technical `props` and `props/*` keys from `$sourceBlockData`
     * onto `$translatedBlockData` so AI full-content translation cannot corrupt CSS classes, IDs, or enum keys.
     *
     * @param array<string, mixed> $sourceBlockData
     * @param array<string, mixed> $translatedBlockData
     *
     * @return array<string, mixed>
     */
    public function preserveTechnicalProps(array $sourceBlockData, array $translatedBlockData): array
    {
        $result = $translatedBlockData;

        if (isset($sourceBlockData['props']) && \is_array($sourceBlockData['props'])) {
            $translatedProps = isset($result['props']) && \is_array($result['props'])
                ? $result['props']
                : [];

            foreach ($sourceBlockData['props'] as $propKey => $sourceValue) {
                if (!\is_string($propKey) || '' === $propKey) {
                    continue;
                }
                $fullKey = \str_starts_with($propKey, 'props/') ? $propKey : 'props/' . $propKey;
                if (!$this->schemaInspector->isTranslatableField($fullKey)) {
                    $translatedProps[$propKey] = $sourceValue;
                }
            }

            $result['props'] = $translatedProps;
        }

        foreach ($sourceBlockData as $key => $sourceValue) {
            if (!\str_starts_with($key, 'props/')) {
                continue;
            }
            if (!$this->schemaInspector->isTranslatableField($key)) {
                $result[$key] = $sourceValue;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $props
     */
    private function validatePropsAgainstSchema(string $blockType, array $props, string $locale): void
    {
        if ([] === $props) {
            return;
        }

        $schema = $this->schemaInspector->inspectBlock($blockType, $locale);
        if (null === $schema) {
            return;
        }

        /** @var array<string, list<string>> $allowedByProp */
        $allowedByProp = [];

        foreach ($schema['content_fields'] as $fieldDesc) {
            if (!isset($fieldDesc['option_groups']) || !\is_array($fieldDesc['option_groups'])) {
                continue;
            }
            foreach ($fieldDesc['option_groups'] as $group) {
                if (!\is_array($group) || !isset($group['storage_key'], $group['values']) || !\is_string($group['storage_key']) || !\is_array($group['values'])) {
                    continue;
                }
                $propSubKey = $this->stripPropsPrefix($group['storage_key']);
                $allowedValues = $this->extractAllowedOptionValues($group['values']);
                if ('' !== $propSubKey && [] !== $allowedValues) {
                    $allowedByProp[$propSubKey] = $allowedValues;
                }
            }
        }

        foreach ($schema['settings_props'] as $propDesc) {
            if (!isset($propDesc['name'], $propDesc['values']) || !\is_string($propDesc['name']) || !\is_array($propDesc['values'])) {
                continue;
            }
            $propSubKey = $this->stripPropsPrefix($propDesc['name']);
            $allowedValues = $this->extractAllowedOptionValues($propDesc['values']);
            if ('' !== $propSubKey && [] !== $allowedValues) {
                $allowedByProp[$propSubKey] = $allowedValues;
            }
        }

        foreach ($props as $propKey => $propVal) {
            if (!isset($allowedByProp[$propKey]) || !\is_scalar($propVal)) {
                continue;
            }
            $strVal = (string) $propVal;
            if ('' === $strVal) {
                continue;
            }
            if (!\in_array($strVal, $allowedByProp[$propKey], true)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Invalid value "%s" for prop "props/%s" on block "%s". Allowed values: %s.',
                    $strVal,
                    $propKey,
                    $blockType,
                    \implode(', ', $allowedByProp[$propKey]),
                ));
            }
        }
    }

    private function stripPropsPrefix(string $key): string
    {
        return \str_starts_with($key, 'props/') ? \substr($key, 6) : $key;
    }

    /**
     * @param array<mixed> $options
     *
     * @return list<string>
     */
    private function extractAllowedOptionValues(array $options): array
    {
        $allowed = [];
        foreach ($options as $opt) {
            if (\is_array($opt) && isset($opt['value']) && \is_string($opt['value'])) {
                $allowed[] = $opt['value'];
            }
        }

        return $allowed;
    }
}
