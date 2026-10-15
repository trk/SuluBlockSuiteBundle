<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Metadata;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Dynamically discovers mappable target fields for any registered Sulu CMS block
 * by inspecting Sulu FormMetadata and merging optional YAML overrides.
 */
final class BlockTargetFieldMetadataResolver
{
    private const EXCLUDED_FIELDS = [
        'props',
        'dynamic_source',
        'target_block',
        'reusable_snippet',
        'snippet',
        'rows',
        'items',
        'columns',
        'blocks',
    ];

    /**
     * @var list<string>
     */
    private array $schemaDirectories;

    /**
     * @param string|array<array-key, string> $schemaDirectory
     */
    public function __construct(
        private readonly ?MetadataProviderRegistry $metadataProviderRegistry = null,
        string|array $schemaDirectory = [],
    ) {
        $dirs = \is_array($schemaDirectory) ? \array_values($schemaDirectory) : [$schemaDirectory];
        $this->schemaDirectories = \array_values(\array_filter($dirs, static fn (string $dir): bool => '' !== \trim($dir)));
    }

    /**
     * @return list<array{name: string, label: string, type: string}>
     */
    public function resolveTargetFields(string $blockType, string $locale = 'en'): array
    {
        return $this->resolveBlockMetadata($blockType, $locale)['fields'];
    }

    /**
     * @return array{
     *     multiple: bool,
     *     default_item_type: string|null,
     *     fields: list<array{name: string, label: string, type: string}>
     * }
     */
    public function resolveBlockMetadata(string $blockType, string $locale = 'en'): array
    {
        $cleanType = \strtolower(\trim($blockType));
        if ('' === $cleanType) {
            return [
                'multiple' => false,
                'default_item_type' => null,
                'fields' => $this->getDefaultFallbackFields(),
            ];
        }

        /** @var array<string, array{name: string, label: string, type: string}> $fieldsByName */
        $fieldsByName = [];
        $isMultiple = false;
        $defaultItemType = null;

        // 1. Discover fields and multiplicity from Sulu FormMetadata
        $formMetadata = $this->getBlockFormMetadata($cleanType, $locale);
        if (null !== $formMetadata) {
            foreach ($formMetadata->getItems() as $item) {
                if (!$item instanceof FieldMetadata) {
                    continue;
                }

                $fieldName = $item->getName();
                $fieldType = $item->getType();

                if ('items' === $fieldName && 'block' === $fieldType) {
                    $isMultiple = true;
                    $defaultItemType = $item->getDefaultType();

                    foreach ($item->getTypes() as $typeKey => $childFormMetadata) {
                        if (null === $defaultItemType && \is_string($typeKey) && '' !== $typeKey) {
                            $defaultItemType = $typeKey;
                        }
                        foreach ($childFormMetadata->getItems() as $childItem) {
                            if (!$childItem instanceof FieldMetadata) {
                                continue;
                            }

                            $childFieldName = $childItem->getName();
                            $childFieldType = $childItem->getType();
                            if (\in_array($childFieldName, self::EXCLUDED_FIELDS, true) || 'block' === $childFieldType || 'block_props' === $childFieldType) {
                                continue;
                            }

                            $rawChildLabel = $childItem->getLabel($locale) ?? $childItem->getLabel('en');
                            $humanChildLabel = (null !== $rawChildLabel && '' !== \trim($rawChildLabel))
                                ? \trim($rawChildLabel)
                                : \ucwords(\str_replace(['_', '-'], ' ', $childFieldName));

                            $fieldsByName[$childFieldName] ??= [
                                'name' => $childFieldName,
                                'label' => $this->formatFieldLabel($humanChildLabel, $childFieldName),
                                'type' => $this->mapSuluFieldType($childFieldType),
                            ];
                        }
                    }

                    continue;
                }

                if (\in_array($fieldName, self::EXCLUDED_FIELDS, true) || 'block' === $fieldType || 'block_props' === $fieldType) {
                    continue;
                }

                $rawLabel = $item->getLabel($locale) ?? $item->getLabel('en');
                $humanLabel = (null !== $rawLabel && '' !== \trim($rawLabel))
                    ? \trim($rawLabel)
                    : \ucwords(\str_replace(['_', '-'], ' ', $fieldName));

                $fieldsByName[$fieldName] = [
                    'name' => $fieldName,
                    'label' => $this->formatFieldLabel($humanLabel, $fieldName),
                    'type' => $this->mapSuluFieldType($fieldType),
                ];
            }
        }

        // 2. Merge/override with optional YAML `multiple`, `default_item_type`, and `dynamic_fields` in props schema directories
        foreach ($this->schemaDirectories as $dir) {
            $basePath = \rtrim($dir, '/');
            $candidates = [
                $basePath . '/' . $cleanType . '.yaml',
                $basePath . '/' . $cleanType . '.yml',
                $basePath . '/props/' . $cleanType . '.yaml',
                $basePath . '/props/' . $cleanType . '.yml',
            ];

            foreach ($candidates as $yamlFile) {
                if (!\is_file($yamlFile)) {
                    continue;
                }

                try {
                    $parsed = Yaml::parseFile($yamlFile);
                    if (!\is_array($parsed)) {
                        break;
                    }

                    if (isset($parsed['multiple']) && \is_bool($parsed['multiple'])) {
                        $isMultiple = $parsed['multiple'];
                    } elseif (isset($parsed['is_multiple']) && \is_bool($parsed['is_multiple'])) {
                        $isMultiple = $parsed['is_multiple'];
                    }

                    if (isset($parsed['default_item_type']) && \is_string($parsed['default_item_type']) && '' !== \trim($parsed['default_item_type'])) {
                        $defaultItemType = \trim($parsed['default_item_type']);
                    }

                    if (isset($parsed['dynamic_fields']) && \is_array($parsed['dynamic_fields'])) {
                        foreach ($parsed['dynamic_fields'] as $entry) {
                            if (!\is_array($entry) || !isset($entry['name']) || !\is_string($entry['name'])) {
                                continue;
                            }

                            $name = \trim($entry['name']);
                            if ('' === $name) {
                                continue;
                            }

                            $rawLabel = isset($entry['label']) && \is_string($entry['label']) && '' !== \trim($entry['label'])
                                ? \trim($entry['label'])
                                : \ucwords(\str_replace(['_', '-'], ' ', $name));
                            $type = isset($entry['type']) && \is_string($entry['type']) && '' !== \trim($entry['type'])
                                ? \trim($entry['type'])
                                : 'string';

                            $fieldsByName[$name] = [
                                'name' => $name,
                                'label' => $this->formatFieldLabel($rawLabel, $name),
                                'type' => $type,
                            ];
                        }
                    }
                } catch (ParseException) {
                    // Ignore invalid YAML
                }

                break;
            }
        }

        $fields = [] === $fieldsByName
            ? $this->getDefaultFallbackFields()
            : \array_values($fieldsByName);

        return [
            'multiple' => $isMultiple,
            'default_item_type' => $defaultItemType,
            'fields' => $fields,
        ];
    }

    private function getBlockFormMetadata(string $blockType, string $locale): ?FormMetadata
    {
        if (null === $this->metadataProviderRegistry) {
            return null;
        }

        try {
            $provider = $this->metadataProviderRegistry->getMetadataProvider('form');
            $metadata = $provider->getMetadata('block', $locale, ['ignore_global_blocks' => true]);
            if ($metadata instanceof TypedFormMetadata) {
                $forms = $metadata->getForms();
                if (isset($forms[$blockType])) {
                    return $forms[$blockType];
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function formatFieldLabel(string $label, string $name): string
    {
        return '' !== \trim($label) ? \trim($label) : $name;
    }

    private function mapSuluFieldType(string $suluType): string
    {
        return match ($suluType) {
            'text_area', 'text_editor' => 'text',
            'single_media_selection', 'media_selection' => 'media',
            'link' => 'link',
            'single_icon_selection', 'icon' => 'icon',
            'checkbox' => 'boolean',
            'single_select', 'select' => 'select',
            'date', 'datetime', 'time' => 'date',
            'number' => 'number',
            default => 'string',
        };
    }

    /**
     * @return list<array{name: string, label: string, type: string}>
     */
    private function getDefaultFallbackFields(): array
    {
        return [
            ['name' => 'title', 'label' => 'Title', 'type' => 'string'],
            ['name' => 'content', 'label' => 'Content', 'type' => 'text'],
            ['name' => 'image', 'label' => 'Image', 'type' => 'media'],
            ['name' => 'link', 'label' => 'Link', 'type' => 'link'],
            ['name' => 'icon', 'label' => 'Icon', 'type' => 'icon'],
        ];
    }
}
