<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Schema;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

final class YamlBlockPropSchemaProvider implements BlockPropSchemaProviderInterface
{
    /**
     * @var array<string, array{tabs: list<array{id: string, title: string, fields: list<array{name: string, type: string, label: string, placeholder?: string, col_span?: int, options?: list<array{value: string, label: string}>, default?: mixed}>}>}>
     */
    private array $cache = [];

    /**
     * @var list<string>
     */
    private array $schemaDirectories;

    /**
     * @param string|array<array-key, string> $schemaDirectory
     */
    public function __construct(
        string|array $schemaDirectory,
        private readonly ?TranslatorInterface $translator = null,
    ) {
        $this->schemaDirectories = \is_array($schemaDirectory) ? \array_values($schemaDirectory) : [$schemaDirectory];
    }

    public function getSchema(string $blockType, string $locale = 'en'): array
    {
        $normalizedType = \strtolower(\trim($blockType));
        $cacheKey = $normalizedType . '_' . $locale;

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        // Directories are provided in priority order from base to override: [engine, framework, project]
        /** @var array<string, array<string, mixed>> $blockTabs */
        $blockTabs = [];

        foreach ($this->schemaDirectories as $dir) {
            $basePath = \rtrim($dir, '/');
            $candidateFiles = [
                $basePath . '/' . $normalizedType . '.yaml',
                $basePath . '/' . $normalizedType . '.yml',
                $basePath . '/props/' . $normalizedType . '.yaml',
                $basePath . '/props/' . $normalizedType . '.yml',
            ];

            foreach ($candidateFiles as $file) {
                if (\is_file($file)) {
                    $data = $this->loadYamlFile($file);
                    $this->mergeTabsInto($blockTabs, $data);
                    break;
                }
            }
        }

        /** @var array<string, array<string, mixed>> $commonTabs */
        $commonTabs = [];

        foreach ($this->schemaDirectories as $dir) {
            $basePath = \rtrim($dir, '/');
            $commonCandidates = [
                $basePath . '/common.yaml',
                $basePath . '/common.yml',
                $basePath . '/props/common.yaml',
                $basePath . '/props/common.yml',
            ];

            foreach ($commonCandidates as $commonFile) {
                if (\is_file($commonFile)) {
                    $commonData = $this->loadYamlFile($commonFile);
                    $this->mergeTabsInto($commonTabs, $commonData);
                    break;
                }
            }
        }

        // Deduplicate fields from commonTabs if blockTabs defines the same field in a different tab (e.g. settings vs general)
        foreach ($blockTabs as $blockTabKey => $blockTabConfig) {
            if (!isset($blockTabConfig['fields']) || !\is_array($blockTabConfig['fields'])) {
                continue;
            }
            foreach (\array_keys($blockTabConfig['fields']) as $fieldKey) {
                $fieldKeyStr = (string) $fieldKey;
                foreach ($commonTabs as $commonTabKey => &$commonTabConfig) {
                    if ($commonTabKey === $blockTabKey) {
                        continue;
                    }
                    if (
                        isset($commonTabConfig['fields'])
                        && \is_array($commonTabConfig['fields'])
                        && isset($commonTabConfig['fields'][$fieldKeyStr])
                        && (!isset($blockTabs[$commonTabKey]['fields']) || !\is_array($blockTabs[$commonTabKey]['fields']) || !isset($blockTabs[$commonTabKey]['fields'][$fieldKeyStr]))
                    ) {
                        unset($commonTabConfig['fields'][$fieldKeyStr]);
                    }
                }
                unset($commonTabConfig);
            }
        }

        // Merge commonTabs and blockTabs with canonical ordering:
        // 1) settings (block-specific settings)
        // 2) any custom developer/block tabs (excluding settings, general, advanced)
        // 3) general (universal layout/spacing/alignment/animation)
        // 4) advanced (universal name, status, id, class, attributes, css)
        /** @var array<string, array<string, mixed>> $combinedTabs */
        $combinedTabs = $commonTabs;
        $this->mergeTabsMapInto($combinedTabs, $blockTabs);

        if ([] === $combinedTabs) {
            $emptyResult = ['tabs' => []];
            $this->cache[$cacheKey] = $emptyResult;

            return $emptyResult;
        }

        /** @var array<string, array<string, mixed>> $mergedTabs */
        $mergedTabs = [];

        if (isset($combinedTabs['settings'])) {
            $mergedTabs['settings'] = $combinedTabs['settings'];
        }

        foreach (\array_keys($blockTabs) as $tabKey) {
            if (!\in_array($tabKey, ['settings', 'general', 'advanced'], true) && isset($combinedTabs[$tabKey])) {
                $mergedTabs[$tabKey] = $combinedTabs[$tabKey];
            }
        }

        foreach (\array_keys($commonTabs) as $tabKey) {
            if (!\in_array($tabKey, ['settings', 'general', 'advanced'], true) && !isset($mergedTabs[$tabKey]) && isset($combinedTabs[$tabKey])) {
                $mergedTabs[$tabKey] = $combinedTabs[$tabKey];
            }
        }

        if (isset($combinedTabs['general'])) {
            $mergedTabs['general'] = $combinedTabs['general'];
        }

        if (isset($combinedTabs['advanced'])) {
            $mergedTabs['advanced'] = $combinedTabs['advanced'];
        }

        /** @var list<array{id: string, title: string, fields: list<array{name: string, type: string, label: string, placeholder?: string, col_span?: int, options?: list<array{value: string, label: string}>, default?: mixed}>}> $tabs */
        $tabs = [];

        foreach ($mergedTabs as $tabId => $tabConfig) {
            $rawTitle = $tabConfig['title'] ?? (string) $tabId;
            $title = $this->resolveLocalizedText($rawTitle, $locale, (string) $tabId);

            $fields = [];
            $rawFields = isset($tabConfig['fields']) && \is_array($tabConfig['fields']) ? $tabConfig['fields'] : [];

            foreach ($rawFields as $fieldName => $fieldConfig) {
                if (!\is_array($fieldConfig)) {
                    continue;
                }

                $type = isset($fieldConfig['type']) && \is_string($fieldConfig['type']) ? $fieldConfig['type'] : 'text';
                $rawLabel = $fieldConfig['label'] ?? (string) $fieldName;
                $label = $this->resolveLocalizedText($rawLabel, $locale, (string) $fieldName);

                /** @var array{name: string, type: string, label: string, placeholder?: string, col_span?: int, options?: list<array{value: string, label: string}>, default?: mixed} $field */
                $field = [
                    'name' => (string) $fieldName,
                    'type' => $type,
                    'label' => $label,
                ];

                if (isset($fieldConfig['placeholder'])) {
                    $field['placeholder'] = $this->resolveLocalizedText($fieldConfig['placeholder'], $locale, '');
                }

                if (isset($fieldConfig['col_span']) && \is_int($fieldConfig['col_span'])) {
                    $field['col_span'] = $fieldConfig['col_span'];
                }

                if (isset($fieldConfig['default'])) {
                    $field['default'] = $fieldConfig['default'];
                }

                if (isset($fieldConfig['options']) && \is_array($fieldConfig['options'])) {
                    $options = [];
                    foreach ($fieldConfig['options'] as $rawOption) {
                        if (!\is_array($rawOption) || !isset($rawOption['value'])) {
                            continue;
                        }

                        $optVal = \is_scalar($rawOption['value']) ? (string) $rawOption['value'] : '';
                        $rawOptLabel = $rawOption['label'] ?? $optVal;
                        $optLabel = $this->resolveLocalizedText($rawOptLabel, $locale, $optVal);

                        $options[] = [
                            'value' => $optVal,
                            'label' => $optLabel,
                        ];
                    }
                    $field['options'] = $options;
                }

                $fields[] = $field;
            }

            $tabs[] = [
                'id' => (string) $tabId,
                'title' => $title,
                'fields' => $fields,
            ];
        }

        $result = ['tabs' => $tabs];
        $this->cache[$cacheKey] = $result;

        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $targetTabs
     * @param array<string, mixed> $yamlData
     */
    private function mergeTabsInto(array &$targetTabs, array $yamlData): void
    {
        if (!isset($yamlData['tabs']) || !\is_array($yamlData['tabs'])) {
            return;
        }

        /** @var array<string, mixed> $rawTabs */
        $rawTabs = $yamlData['tabs'];
        $this->mergeTabsMapInto($targetTabs, $rawTabs);
    }

    /**
     * @param array<string, array<string, mixed>> $targetTabs
     * @param array<string, mixed> $sourceTabs
     */
    private function mergeTabsMapInto(array &$targetTabs, array $sourceTabs): void
    {
        foreach ($sourceTabs as $tabId => $tabConfig) {
            if (!\is_array($tabConfig)) {
                continue;
            }
            $tabIdStr = (string) $tabId;
            if (!isset($targetTabs[$tabIdStr])) {
                /** @var array<string, mixed> $tabConfig */
                $targetTabs[$tabIdStr] = $tabConfig;
            } else {
                if (isset($tabConfig['title'])) {
                    $targetTabs[$tabIdStr]['title'] = $tabConfig['title'];
                }
                if (isset($tabConfig['fields']) && \is_array($tabConfig['fields'])) {
                    if (!isset($targetTabs[$tabIdStr]['fields']) || !\is_array($targetTabs[$tabIdStr]['fields'])) {
                        $targetTabs[$tabIdStr]['fields'] = [];
                    }
                    foreach ($tabConfig['fields'] as $fieldKey => $fieldConfig) {
                        if (\is_array($fieldConfig)) {
                            $fieldKeyStr = (string) $fieldKey;
                            if (isset($targetTabs[$tabIdStr]['fields'][$fieldKeyStr]) && \is_array($targetTabs[$tabIdStr]['fields'][$fieldKeyStr])) {
                                $targetTabs[$tabIdStr]['fields'][$fieldKeyStr] = \array_replace_recursive(
                                    $targetTabs[$tabIdStr]['fields'][$fieldKeyStr],
                                    $fieldConfig,
                                );
                            } else {
                                $targetTabs[$tabIdStr]['fields'][$fieldKeyStr] = $fieldConfig;
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadYamlFile(string $filePath): array
    {
        if (!\is_file($filePath)) {
            return [];
        }

        try {
            /** @var mixed $parsed */
            $parsed = Yaml::parseFile($filePath);
            if (!\is_array($parsed)) {
                return [];
            }

            /** @var array<string, mixed> $parsed */
            return $parsed;
        } catch (ParseException) {
            return [];
        }
    }

    private function resolveLocalizedText(mixed $data, string $locale, string $fallback = ''): string
    {
        if (null === $data) {
            return $fallback;
        }

        if (\is_string($data)) {
            if (null !== $this->translator) {
                $translated = $this->translator->trans($data, [], 'admin', $locale);
                if ($translated !== $data) {
                    return $translated;
                }

                $translatedDefault = $this->translator->trans($data, [], null, $locale);
                if ($translatedDefault !== $data) {
                    return $translatedDefault;
                }
            }

            return $data;
        }

        if (\is_array($data)) {
            if (isset($data[$locale]) && \is_string($data[$locale])) {
                return $data[$locale];
            }

            $shortLocale = \explode('_', $locale)[0];
            if (isset($data[$shortLocale]) && \is_string($data[$shortLocale])) {
                return $data[$shortLocale];
            }

            if (isset($data['en']) && \is_string($data['en'])) {
                return $data['en'];
            }

            $first = \reset($data);
            if (\is_string($first)) {
                return $first;
            }
        }

        return $fallback;
    }
}
