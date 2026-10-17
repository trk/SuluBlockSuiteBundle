<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Transformer;

use Totoglu\SuluBlockSuiteBundle\Metadata\BlockTargetFieldMetadataResolver;
use Totoglu\SuluBlockSuiteBundle\Normalizer\SuluDataNormalizer;
use Totoglu\SuluBlockSuiteBundle\Security\AttributeSanitizer;
use Totoglu\SuluBlockSuiteBundle\Security\CssScoper;
use Totoglu\SuluBlockSuiteBundle\Util\ClassList;

/**
 * Universal builder transformer that standardizes core element properties:
 * name, id, class, attributes, css, status, and normalized Sulu media/link values.
 *
 * Priority: 90 (Runs after dynamic content, before UI-framework-specific transformers).
 */
final class DefaultAttributesTransformer implements BlockTransformerInterface
{
    private const RESERVED_KEYS = [
        'type',
        'settings',
        'props',
        'id',
        'class',
        'custom_class',
        'attributes',
        'css',
        'custom_css',
        'status',
        'name',
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private array $metadataFieldTypesCache = [];

    /**
     * @param array<string, int>|null $srcSetFormats
     */
    public function __construct(
        private readonly ?BlockTargetFieldMetadataResolver $fieldMetadataResolver = null,
        private readonly ?array $srcSetFormats = null,
    ) {
        if (null !== $this->srcSetFormats && [] !== $this->srcSetFormats) {
            SuluDataNormalizer::setDefaultSrcSetFormats($this->srcSetFormats);
        }
    }

    public static function getDefaultPriority(): int
    {
        return 90;
    }

    public function supports(array $block, array $context): bool
    {
        return true;
    }

    public function transform(array $block, array $context): array
    {
        $props = SuluDataNormalizer::extractProps($block);
        if ([] !== $props) {
            foreach ($props as $propKey => $propVal) {
                if (!\array_key_exists($propKey, $block) || '' === $block[$propKey] || null === $block[$propKey]) {
                    $block[$propKey] = $propVal;
                }
            }
        }
        $block['props'] = $props;
        $block['_props'] = $props;

        // 1. Status / Visibility
        $status = $props['status'] ?? ($block['status'] ?? null);
        if ('disabled' === $status) {
            $block['_hidden'] = true;

            return $block;
        }

        // 2. Name
        $name = $props['name'] ?? ($block['name'] ?? ($block['type'] ?? ''));
        if (\is_string($name) && '' !== \trim($name)) {
            $block['_name'] = \trim($name);
        }

        // 3. ID
        $elementId = AttributeSanitizer::sanitizeId($props['id'] ?? ($block['id'] ?? null));

        // 4. Classes
        $rawClass = $props['class'] ?? ($props['custom_class'] ?? ($block['class'] ?? null));
        $classes = ClassList::tokens(\is_string($rawClass) ? $rawClass : null);

        // 5. Custom Attributes (key=value multi-line)
        $rawAttrs = $props['attributes'] ?? ($block['attributes'] ?? null);
        $attributes = \is_string($rawAttrs) ? AttributeSanitizer::parse($rawAttrs) : [];

        // 6. Custom Scoped CSS
        $blockType = isset($block['type']) && \is_scalar($block['type']) ? (string) $block['type'] : '';
        $rawCss = $props['css'] ?? ($block['css'] ?? null);
        $scopedCss = null;
        if (\is_string($rawCss) && '' !== \trim($rawCss)) {
            if (null === $elementId) {
                // Generate a stable unique element ID for CSS scoping without PHP object serialization
                $elementId = CssScoper::generateElementId($block, $blockType);
            }

            $scopedCss = CssScoper::scope(\trim($rawCss), '#' . $elementId);
        }

        // Attach resolved id to attributes and props if present
        if (null !== $elementId) {
            $attributes = \array_merge(['id' => $elementId], $attributes);
            $block['_id'] = $elementId;
            $block['id'] = $elementId;
            $props['id'] = $elementId;
            $block['props'] = $props;
            $block['_props'] = $props;
        }

        $block['_classes'] = $classes;
        $block['_class'] = \implode(' ', $classes);
        $block['_attributes'] = $attributes;
        $block['_attributes_string'] = AttributeSanitizer::render($attributes);

        if (null !== $scopedCss && '' !== $scopedCss) {
            $block['_scoped_css'] = $scopedCss;
        }

        // 7. Generic Sulu CMS Data Normalization (Media & Links)
        return $this->normalizeMediaAndLinkFields($block, $blockType);
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private function normalizeMediaAndLinkFields(array $block, string $blockType): array
    {
        $metadataFieldTypes = $this->getMetadataFieldTypes($blockType);

        foreach ($block as $field => $value) {
            if (!$this->isCandidateFieldKey($field) || null === $value || '' === $value || [] === $value) {
                continue;
            }

            $fieldType = $metadataFieldTypes[$field] ?? $this->detectValueFieldType($value);

            if ('media' === $fieldType) {
                if (!isset($block[$field . '_url']) || '' === $block[$field . '_url']) {
                    $block[$field . '_url'] = SuluDataNormalizer::resolveImageUrl($value);
                }
                if (!isset($block[$field . '_srcset'])) {
                    $block[$field . '_srcset'] = SuluDataNormalizer::resolveSrcSet($value, $this->srcSetFormats);
                }
                if (!isset($block[$field . '_focus_style'])) {
                    $block[$field . '_focus_style'] = SuluDataNormalizer::resolveFocusPointStyle($value);
                }
            } elseif ('link' === $fieldType) {
                if (!isset($block[$field . '_url']) || '' === $block[$field . '_url']) {
                    $block[$field . '_url'] = SuluDataNormalizer::resolveUrl($value);
                }
            }
        }

        return $block;
    }

    private function isCandidateFieldKey(string $field): bool
    {
        if ('' === $field || \str_starts_with($field, '_') || \str_starts_with($field, 'props/')) {
            return false;
        }

        if (\in_array($field, self::RESERVED_KEYS, true)) {
            return false;
        }

        return !\str_ends_with($field, '_url')
            && !\str_ends_with($field, '_srcset')
            && !\str_ends_with($field, '_focus_style');
    }

    /**
     * @return array<string, string>
     */
    private function getMetadataFieldTypes(string $blockType): array
    {
        $cleanType = \strtolower(\trim($blockType));
        if ('' === $cleanType || null === $this->fieldMetadataResolver) {
            return [];
        }

        if (isset($this->metadataFieldTypesCache[$cleanType])) {
            return $this->metadataFieldTypesCache[$cleanType];
        }

        $types = [];
        foreach ($this->fieldMetadataResolver->resolveTargetFields($cleanType) as $fieldMeta) {
            $types[$fieldMeta['name']] = $fieldMeta['type'];
        }

        return $this->metadataFieldTypesCache[$cleanType] = $types;
    }

    private function detectValueFieldType(mixed $value): ?string
    {
        if (\is_object($value)) {
            if (
                \method_exists($value, 'getFormats')
                || \method_exists($value, 'getFocusPoint')
                || \method_exists($value, 'getThumbnails')
                || \method_exists($value, 'getMimeType')
            ) {
                return 'media';
            }

            if (\method_exists($value, 'getUrl')) {
                /** @var mixed $url */
                $url = $value->getUrl();
                if (\is_string($url) && 1 === \preg_match('/\.(?:jpe?g|png|webp|gif|avif|svg|bmp|ico|mp4|webm|ogg|mov|m4v|mp3|wav|pdf)(?:[?#].*)?$/i', $url)) {
                    return 'media';
                }

                return 'link';
            }

            return null;
        }

        if (!\is_array($value) || [] === $value || \array_is_list($value)) {
            return null;
        }

        if (
            \array_key_exists('formats', $value)
            || \array_key_exists('thumbnails', $value)
            || \array_key_exists('focusPoint', $value)
            || \array_key_exists('focalPoint', $value)
            || \array_key_exists('mimeType', $value)
            || \array_key_exists('fileVersion', $value)
        ) {
            return 'media';
        }

        if (
            \array_key_exists('href', $value)
            || \array_key_exists('provider', $value)
            || \array_key_exists('anchor', $value)
            || \array_key_exists('target', $value)
            || \array_key_exists('query', $value)
        ) {
            return 'link';
        }

        if (\is_string($value['url'] ?? null) && '' !== \trim($value['url'])) {
            if (
                \array_key_exists('id', $value)
                || 1 === \preg_match('/\.(?:jpe?g|png|webp|gif|avif|svg|bmp|ico|mp4|webm|ogg|mov|m4v|mp3|wav|pdf)(?:[?#].*)?$/i', $value['url'])
            ) {
                return 'media';
            }

            return 'link';
        }

        return null;
    }
}
