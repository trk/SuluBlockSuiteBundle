<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Ai;

/**
 * XInclude-aware XML schema extractor and in-memory cache for Sulu Block Suite block templates.
 *
 * Works in both booted Symfony kernels and standalone static CLI contexts (such as Symfony AI Mate).
 */
class BlockXmlSchemaExtractor
{
    /**
     * @var array<string, array{
     *     resolved_type: string,
     *     title: string,
     *     content_fields: list<array<string, mixed>>,
     *     style_props: list<array<string, mixed>>,
     *     nested_blocks: array<string, array<string, mixed>>,
     *     excluded_tabs: list<string>
     * }>
     */
    private array $parsedSchemaCache = [];

    /**
     * @var array<string, list<array{
     *     type: string,
     *     title: string,
     *     has_items: bool,
     *     has_elements: bool,
     *     is_layout_container: bool
     * }>>
     */
    private array $summariesCache = [];

    /**
     * @param list<string> $layoutContainerTypes
     */
    public function __construct(
        private readonly string $blocksXmlDir,
        private readonly array $layoutContainerTypes = ['row', 'section_break', 'column_break'],
    ) {
    }

    public function getBlocksXmlDir(): string
    {
        return $this->blocksXmlDir;
    }

    public function hasBlockDocument(string $blockType): bool
    {
        return null !== $this->resolveBlockXmlPath($blockType);
    }

    /**
     * @return list<array{
     *     type: string,
     *     title: string,
     *     has_items: bool,
     *     has_elements: bool,
     *     is_layout_container: bool
     * }>
     */
    public function listBlockSummaries(string $locale = 'en'): array
    {
        if (isset($this->summariesCache[$locale])) {
            return $this->summariesCache[$locale];
        }

        $xmlFiles = \glob($this->blocksXmlDir . '/*.xml');
        if (false === $xmlFiles) {
            return [];
        }

        \sort($xmlFiles);
        $summaries = [];

        foreach ($xmlFiles as $file) {
            $doc = $this->loadXmlFile($file);
            if (null === $doc) {
                continue;
            }

            $xpath = $this->createXPath($doc);
            $type = $this->evaluateString($xpath, 'string(/sulu:template/sulu:key)');
            if ('' === $type) {
                $type = \basename($file, '.xml');
            }

            $title = $this->extractLocalizedTitle($xpath, '/sulu:template/sulu:meta', $locale, \ucwords(\str_replace('_', ' ', $type)));
            $hasItems = $this->evaluateNodeCount($xpath, '//sulu:block[@name="items"]') > 0;
            $hasElements = $this->evaluateNodeCount($xpath, '//sulu:block[@name="elements"]') > 0;
            $isLayoutContainer = \in_array($type, $this->layoutContainerTypes, true)
                || $this->evaluateNodeCount($xpath, '//sulu:block[@name="blocks"]') > 0;

            $summaries[] = [
                'type' => $type,
                'title' => $title,
                'has_items' => $hasItems,
                'has_elements' => $hasElements,
                'is_layout_container' => $isLayoutContainer,
            ];
        }

        $this->summariesCache[$locale] = $summaries;

        return $summaries;
    }

    /**
     * Extracts and memoizes the parsed block XML structure for `(blockType, locale)`.
     *
     * @return array{
     *     resolved_type: string,
     *     title: string,
     *     content_fields: list<array<string, mixed>>,
     *     style_props: list<array<string, mixed>>,
     *     nested_blocks: array<string, array<string, mixed>>,
     *     excluded_tabs: list<string>
     * }|null
     */
    public function extractSchema(string $blockType, string $locale = 'en'): ?array
    {
        $cacheKey = \trim($blockType) . '::' . $locale;
        if (isset($this->parsedSchemaCache[$cacheKey])) {
            return $this->parsedSchemaCache[$cacheKey];
        }

        $doc = $this->loadBlockDocument($blockType);
        if (null === $doc) {
            return null;
        }

        $xpath = $this->createXPath($doc);
        $resolvedType = $this->evaluateString($xpath, 'string(/sulu:template/sulu:key)');
        if ('' === $resolvedType) {
            $resolvedType = \trim($blockType);
        }

        $title = $this->extractLocalizedTitle($xpath, '/sulu:template/sulu:meta', $locale, \ucwords(\str_replace('_', ' ', $resolvedType)));
        [$contentFields, $styleProps, $nestedBlocks] = $this->extractSectionsAndProperties(
            $xpath,
            '/sulu:template/sulu:properties',
            $locale,
        );

        $extracted = [
            'resolved_type' => $resolvedType,
            'title' => $title,
            'content_fields' => $contentFields,
            'style_props' => $styleProps,
            'nested_blocks' => $nestedBlocks,
            'excluded_tabs' => $this->extractExcludedTabs($xpath),
        ];

        $this->parsedSchemaCache[$cacheKey] = $extracted;
        $this->parsedSchemaCache[$resolvedType . '::' . $locale] = $extracted;

        return $extracted;
    }

    /**
     * Canonical schema definition for Core's `block_suite_dynamic.xml` section.
     *
     * @return list<array<string, mixed>>
     */
    public static function getCoreDynamicPropsSchema(): array
    {
        return [
            [
                'name' => 'dynamic_source',
                'storage_key' => 'props/dynamic_source',
                'type' => 'single_select',
                'default' => 'none',
                'description' => 'Content source key (`none`, `pages`, `articles`, `media`, `categories`, or custom entity source).',
            ],
            [
                'name' => 'dynamic_limit',
                'storage_key' => 'props/dynamic_limit',
                'type' => 'number',
                'default' => '6',
                'description' => 'Maximum number of dynamic items to load.',
            ],
            [
                'name' => 'dynamic_sort_by',
                'storage_key' => 'props/dynamic_sort_by',
                'type' => 'single_select',
                'default' => 'published',
                'description' => 'Field to sort dynamic items by.',
            ],
            [
                'name' => 'dynamic_sort_order',
                'storage_key' => 'props/dynamic_sort_order',
                'type' => 'single_select',
                'allowed_values' => ['asc', 'desc'],
                'default' => 'desc',
                'description' => 'Sort direction (`asc` or `desc`).',
            ],
            [
                'name' => 'dynamic_mapping',
                'storage_key' => 'props/dynamic_mapping',
                'type' => 'block',
                'description' => 'Field mapping items (`type: "field_map"`, `target_field`, `source_field`).',
            ],
        ];
    }

    /**
     * Canonical schema definition for Core's `block_suite_advanced.xml` section.
     *
     * @return list<array<string, mixed>>
     */
    public static function getCoreAdvancedPropsSchema(): array
    {
        return [
            [
                'name' => 'name',
                'storage_key' => 'props/name',
                'type' => 'text_line',
                'description' => 'Internal label shown in the Sulu Admin block list.',
            ],
            [
                'name' => 'id',
                'storage_key' => 'props/id',
                'type' => 'text_line',
                'description' => 'HTML `id` attribute for anchor links.',
            ],
            [
                'name' => 'class',
                'storage_key' => 'props/class',
                'type' => 'text_line',
                'description' => 'Additional CSS utility classes appended to the block wrapper.',
            ],
            [
                'name' => 'attributes',
                'storage_key' => 'props/attributes',
                'type' => 'text_area',
                'description' => 'Custom HTML attributes (`key="value"`, one per line).',
            ],
            [
                'name' => 'css',
                'storage_key' => 'props/css',
                'type' => 'text_area',
                'description' => 'Scoped custom CSS rules (`#block-id { ... }`).',
            ],
        ];
    }

    private function resolveBlockXmlPath(string $blockType): ?string
    {
        $normalized = \trim($blockType);
        if ('' === $normalized || 1 !== \preg_match('/^[a-z0-9_]+$/', $normalized)) {
            return null;
        }

        $file = $this->blocksXmlDir . '/' . $normalized . '.xml';

        return \is_file($file) ? $file : null;
    }

    private function loadBlockDocument(string $blockType): ?\DOMDocument
    {
        $path = $this->resolveBlockXmlPath($blockType);

        return null !== $path ? $this->loadXmlFile($path) : null;
    }

    /**
     * @return list<string>
     */
    private function extractExcludedTabs(\DOMXPath $xpath): array
    {
        $rawExclude = $this->evaluateString($xpath, 'string(/sulu:template/sulu:tag[@name="sulu_block_suite.tabs"]/@exclude)');
        if ('' === $rawExclude) {
            return [];
        }

        $tabs = [];
        foreach (\explode(',', $rawExclude) as $part) {
            $trimmed = \trim($part);
            if ('' !== $trimmed) {
                $tabs[] = $trimmed;
            }
        }

        return $tabs;
    }

    /**
     * @return array{
     *     0: list<array<string, mixed>>,
     *     1: list<array<string, mixed>>,
     *     2: array<string, array<string, mixed>>
     * }
     */
    private function extractSectionsAndProperties(\DOMXPath $xpath, string $propertiesXPath, string $locale, ?\DOMNode $contextNode = null): array
    {
        /** @var list<array<string, mixed>> $contentFields */
        $contentFields = [];
        /** @var array<string, array<string, mixed>> $stylePropsByName */
        $stylePropsByName = [];
        /** @var array<string, array<string, mixed>> $nestedBlocks */
        $nestedBlocks = [];

        $sections = $xpath->query(\sprintf('%s/sulu:section', $propertiesXPath), $contextNode);
        if (false !== $sections && $sections->length > 0) {
            foreach ($sections as $sectionNode) {
                if (!$sectionNode instanceof \DOMElement) {
                    continue;
                }
                $sectionName = $sectionNode->getAttribute('name');
                $isContentSection = \in_array($sectionName, ['content', 'media'], true);

                $this->extractFromPropertiesContainer(
                    $xpath,
                    'sulu:properties',
                    $sectionNode,
                    $isContentSection,
                    $locale,
                    $contentFields,
                    $stylePropsByName,
                    $nestedBlocks,
                );
            }
        }

        $this->extractFromPropertiesContainer(
            $xpath,
            $propertiesXPath,
            $contextNode,
            true,
            $locale,
            $contentFields,
            $stylePropsByName,
            $nestedBlocks,
        );

        return [$contentFields, \array_values($stylePropsByName), $nestedBlocks];
    }

    /**
     * @param list<array<string, mixed>> $contentFields
     * @param array<string, array<string, mixed>> $stylePropsByName
     * @param array<string, array<string, mixed>> $nestedBlocks
     */
    private function extractFromPropertiesContainer(
        \DOMXPath $xpath,
        string $containerXPath,
        ?\DOMNode $contextNode,
        bool $isContentSection,
        string $locale,
        array &$contentFields,
        array &$stylePropsByName,
        array &$nestedBlocks,
    ): void {
        $propNodes = $xpath->query(\sprintf('%s/sulu:property', $containerXPath), $contextNode);
        if (false !== $propNodes) {
            foreach ($propNodes as $propNode) {
                if (!$propNode instanceof \DOMElement) {
                    continue;
                }
                $propName = \trim($propNode->getAttribute('name'));
                if ('' === $propName) {
                    continue;
                }

                $propType = \trim($propNode->getAttribute('type')) ?: 'text_line';
                $label = $this->extractLocalizedTitle($xpath, 'sulu:meta', $locale, $propName, $propNode);
                $required = 'true' === \strtolower($propNode->getAttribute('mandatory'));
                $visibleCondition = \trim($propNode->getAttribute('visibleCondition'));
                $defaultValue = $this->extractParamScalarValue($xpath, $propNode, 'default_value');
                $allowedValues = $this->extractCollectionValues($xpath, $propNode, 'values');

                if ($isContentSection) {
                    $fieldDef = [
                        'name' => $propName,
                        'type' => $propType,
                        'label' => $label,
                        'required' => $required,
                        'translatable' => !\in_array($propName, ['media_type', 'media_source', 'aspect_ratio', 'object_fit', 'aspect_ratio_width', 'aspect_ratio_height'], true),
                    ];
                    if (null !== $defaultValue) {
                        $fieldDef['default'] = $defaultValue;
                    }
                    if ([] !== $allowedValues) {
                        $fieldDef['allowed_values'] = $allowedValues;
                    }
                    if ('' !== $visibleCondition) {
                        $fieldDef['visible_condition'] = $visibleCondition;
                    }
                    $contentFields[] = $fieldDef;

                    foreach ($this->extractInlineOptionGroups($xpath, $propNode, $propName, $locale) as $inlineKey => $inlineProp) {
                        $stylePropsByName[$inlineKey] = $inlineProp;
                    }
                } else {
                    $styleDef = [
                        'name' => $propName,
                        'storage_key' => 'props/' . $propName,
                        'type' => $propType,
                        'label' => $label,
                        'translatable' => false,
                    ];
                    if (null !== $defaultValue) {
                        $styleDef['default'] = $defaultValue;
                    }
                    if ([] !== $allowedValues) {
                        $styleDef['allowed_values'] = $allowedValues;
                    }
                    if ('' !== $visibleCondition) {
                        $styleDef['visible_condition'] = $visibleCondition;
                    }
                    $stylePropsByName[$propName] = $styleDef;
                }
            }
        }

        $blockNodes = $xpath->query(\sprintf('%s/sulu:block', $containerXPath), $contextNode);
        if (false !== $blockNodes) {
            foreach ($blockNodes as $blockNode) {
                if (!$blockNode instanceof \DOMElement) {
                    continue;
                }
                $blockName = \trim($blockNode->getAttribute('name'));
                if ('' === $blockName) {
                    continue;
                }
                $defaultType = \trim($blockNode->getAttribute('default-type'));
                $blockLabel = $this->extractLocalizedTitle($xpath, 'sulu:meta', $locale, $blockName, $blockNode);

                $types = [];
                $typeNodes = $xpath->query('sulu:types/sulu:type', $blockNode);
                if (false !== $typeNodes) {
                    foreach ($typeNodes as $typeNode) {
                        if (!$typeNode instanceof \DOMElement) {
                            continue;
                        }
                        $ref = \trim($typeNode->getAttribute('ref'));
                        if ('' !== $ref) {
                            $types[] = [
                                'type' => $ref,
                                'global_block_ref' => true,
                            ];
                            continue;
                        }

                        $subTypeName = \trim($typeNode->getAttribute('name'));
                        if ('' === $subTypeName) {
                            continue;
                        }
                        $subTypeTitle = $this->extractLocalizedTitle($xpath, 'sulu:meta', $locale, $subTypeName, $typeNode);
                        [$subContent, $subStyle, $subNested] = $this->extractSectionsAndProperties($xpath, 'sulu:properties', $locale, $typeNode);

                        $subEntry = [
                            'type' => $subTypeName,
                            'title' => $subTypeTitle,
                            'content_fields' => $subContent,
                            'style_props' => $subStyle,
                        ];
                        if ([] !== $subNested) {
                            $subEntry['nested_blocks'] = $subNested;
                        }
                        $types[] = $subEntry;
                    }
                }

                $nestedBlocks[$blockName] = [
                    'name' => $blockName,
                    'label' => $blockLabel,
                    'default_type' => $defaultType,
                    'types' => $types,
                ];
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function extractInlineOptionGroups(\DOMXPath $xpath, \DOMElement $propNode, string $parentFieldName, string $locale): array
    {
        $groups = [];
        $paramNodes = $xpath->query('sulu:params/sulu:param[@type="collection"]', $propNode);
        if (false === $paramNodes) {
            return [];
        }

        foreach ($paramNodes as $paramNode) {
            if (!$paramNode instanceof \DOMElement) {
                continue;
            }
            $groupName = \trim($paramNode->getAttribute('name'));
            if ('' === $groupName || 'values' === $groupName) {
                continue;
            }

            $defaultVal = null;
            $allowedValues = [];
            $children = $xpath->query('sulu:param', $paramNode);
            if (false !== $children) {
                foreach ($children as $child) {
                    if (!$child instanceof \DOMElement) {
                        continue;
                    }
                    $childName = \trim($child->getAttribute('name'));
                    if ('' === $childName) {
                        continue;
                    }
                    if ('_default' === $childName) {
                        $defaultVal = \trim($child->getAttribute('value'));
                        continue;
                    }
                    if (\str_starts_with($childName, '_')) {
                        continue;
                    }
                    $allowedValues[] = $childName;
                }
            }

            $label = $this->extractLocalizedTitle($xpath, 'sulu:meta', $locale, \ucwords(\str_replace('_', ' ', $groupName)), $paramNode);
            $groupDef = [
                'name' => $groupName,
                'storage_key' => 'props/' . $groupName,
                'type' => 'single_select',
                'source' => 'inline_popover',
                'parent_field' => $parentFieldName,
                'label' => $label,
                'translatable' => false,
            ];
            if (null !== $defaultVal && '' !== $defaultVal) {
                $groupDef['default'] = $defaultVal;
            }
            if ([] !== $allowedValues) {
                $groupDef['allowed_values'] = $allowedValues;
            }
            $groups[$groupName] = $groupDef;
        }

        return $groups;
    }

    private function extractParamScalarValue(\DOMXPath $xpath, \DOMElement $propNode, string $paramName): ?string
    {
        $trimmed = $this->evaluateString($xpath, \sprintf('string(sulu:params/sulu:param[@name="%s"]/@value)', $paramName), $propNode);

        return '' !== $trimmed ? $trimmed : null;
    }

    /**
     * @return list<string>
     */
    private function extractCollectionValues(\DOMXPath $xpath, \DOMElement $propNode, string $collectionParamName): array
    {
        $values = [];
        $nodes = $xpath->query(\sprintf('sulu:params/sulu:param[@name="%s" and @type="collection"]/sulu:param', $collectionParamName), $propNode);
        if (false === $nodes) {
            return [];
        }

        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $name = \trim($node->getAttribute('name'));
            if ('' !== $name && !\str_starts_with($name, '_')) {
                $values[] = $name;
            }
        }

        return $values;
    }

    private function loadXmlFile(string $path): ?\DOMDocument
    {
        if (!\is_file($path)) {
            return null;
        }

        $doc = new \DOMDocument();
        $prev = \libxml_use_internal_errors(true);
        try {
            if (!$doc->load($path, \LIBXML_NONET)) {
                return null;
            }
            $doc->xinclude(\LIBXML_NONET);
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($prev);
        }

        return $doc;
    }

    private function createXPath(\DOMDocument $doc): \DOMXPath
    {
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('sulu', 'http://schemas.sulu.io/template/template');

        return $xpath;
    }

    private function evaluateString(\DOMXPath $xpath, string $expression, ?\DOMNode $contextNode = null): string
    {
        $res = $xpath->evaluate($expression, $contextNode);

        return \is_string($res) ? \trim($res) : '';
    }

    private function evaluateNodeCount(\DOMXPath $xpath, string $expression, ?\DOMNode $contextNode = null): int
    {
        $nodes = $xpath->query($expression, $contextNode);

        return false !== $nodes ? $nodes->length : 0;
    }

    private function extractLocalizedTitle(\DOMXPath $xpath, string $metaXPath, string $locale, string $fallback, ?\DOMNode $contextNode = null): string
    {
        $val = $this->evaluateString($xpath, \sprintf('string(%s/sulu:title[@lang="%s"])', $metaXPath, $locale), $contextNode);
        if ('' !== $val) {
            return $val;
        }

        $enVal = $this->evaluateString($xpath, \sprintf('string(%s/sulu:title[@lang="en"])', $metaXPath), $contextNode);
        if ('' !== $enVal) {
            return $enVal;
        }

        $anyVal = $this->evaluateString($xpath, \sprintf('string((%s/sulu:title)[1])', $metaXPath), $contextNode);

        return '' !== $anyVal ? $anyVal : $fallback;
    }
}
