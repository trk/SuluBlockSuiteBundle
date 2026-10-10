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

        $blockData = [];
        foreach ($this->schemaDirectories as $dir) {
            $schemaFile = \rtrim($dir, '/') . '/' . $normalizedType . '.yaml';
            if (\is_file($schemaFile)) {
                $blockData = $this->loadYamlFile($schemaFile);
                break;
            }
        }

        $mergedTabs = [];
        if (isset($blockData['tabs']) && \is_array($blockData['tabs'])) {
            $mergedTabs = $blockData['tabs'];
        }

        foreach ($this->schemaDirectories as $dir) {
            $commonFile = \rtrim($dir, '/') . '/common.yaml';
            if (\is_file($commonFile)) {
                $commonData = $this->loadYamlFile($commonFile);
                if (isset($commonData['tabs']) && \is_array($commonData['tabs'])) {
                    foreach ($commonData['tabs'] as $tabKey => $tabConfig) {
                        if (!isset($mergedTabs[$tabKey]) && \is_array($tabConfig)) {
                            $mergedTabs[$tabKey] = $tabConfig;
                        }
                    }
                }
            }
        }

        if ([] === $mergedTabs) {
            $emptyResult = ['tabs' => []];
            $this->cache[$cacheKey] = $emptyResult;

            return $emptyResult;
        }

        /** @var list<array{id: string, title: string, fields: list<array{name: string, type: string, label: string, placeholder?: string, col_span?: int, options?: list<array{value: string, label: string}>, default?: mixed}>}> $tabs */
        $tabs = [];

        foreach ($mergedTabs as $tabId => $tabConfig) {
            if (!\is_array($tabConfig)) {
                continue;
            }

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
