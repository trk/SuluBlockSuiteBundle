<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Transformer;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
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
     * @param array<string, int>|null $srcSetFormats
     */
    public function __construct(
        private readonly ?BlockTargetFieldMetadataResolver $fieldMetadataResolver = null,
        private readonly ?array $srcSetFormats = null,
        private readonly ?RequestStack $requestStack = null,
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
        $suluBlockId = isset($block['_sulu_block_id']) && \is_string($block['_sulu_block_id']) && '' !== \trim($block['_sulu_block_id'])
            ? \trim($block['_sulu_block_id'])
            : (isset($block['_id']) && \is_string($block['_id']) && '' !== \trim($block['_id']) ? \trim($block['_id']) : null);
        if (null !== $suluBlockId) {
            $block['_sulu_block_id'] = $suluBlockId;
        }

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

        // 5. Custom Attributes (key=value multi-line or pre-supplied array)
        $rawAttrs = $props['attributes'] ?? ($block['attributes'] ?? null);
        $preAttrs = $props['_attributes'] ?? ($block['_attributes'] ?? null);
        $attributes = \is_array($preAttrs) ? AttributeSanitizer::sanitize($preAttrs) : [];
        if (\is_string($rawAttrs)) {
            $attributes = \array_merge($attributes, AttributeSanitizer::parse($rawAttrs));
        }

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

        // Sulu Live Preview Block Focus: attach original Sulu block `_id` as `data-block-id` in preview mode
        $isPreview = $this->isPreviewMode($context);
        if ($isPreview) {
            if (null !== $suluBlockId && !isset($attributes['data-block-id'])) {
                $attributes['data-block-id'] = $suluBlockId;
            }
            $block = $this->propagatePreviewBlockIds($block);
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
     * Recursively attaches `_sulu_block_id` and preview `data-block-id` attributes
     * to nested Sulu block/item collections (`items`, `rows`, `columns`, `blocks`).
     *
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function propagatePreviewBlockIds(array $node): array
    {
        if (isset($node['_reusable_parent_block_id']) && \is_string($node['_reusable_parent_block_id']) && '' !== $node['_reusable_parent_block_id']) {
            return $node;
        }

        foreach (['items', 'rows', 'columns', 'blocks'] as $collectionKey) {
            if (!isset($node[$collectionKey]) || !\is_array($node[$collectionKey])) {
                continue;
            }

            foreach ($node[$collectionKey] as $idx => $subItem) {
                if (!\is_array($subItem)) {
                    continue;
                }

                /** @var array<string, mixed> $subItem */
                $subBlockId = isset($subItem['_sulu_block_id']) && \is_string($subItem['_sulu_block_id']) && '' !== \trim($subItem['_sulu_block_id'])
                    ? \trim($subItem['_sulu_block_id'])
                    : (isset($subItem['_id']) && \is_string($subItem['_id']) && '' !== \trim($subItem['_id']) ? \trim($subItem['_id']) : null);

                if (null !== $subBlockId) {
                    $subItem['_sulu_block_id'] = $subBlockId;
                    $subAttrs = isset($subItem['_attributes']) && \is_array($subItem['_attributes']) ? $subItem['_attributes'] : [];
                    if (!isset($subAttrs['data-block-id'])) {
                        $subAttrs['data-block-id'] = $subBlockId;
                    }
                    $subItem['_attributes'] = $subAttrs;
                }

                $node[$collectionKey][$idx] = $this->propagatePreviewBlockIds($subItem);
            }
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function isPreviewMode(array $context = [], ?Request $request = null): bool
    {
        if (true === ($context['_in_page_layout'] ?? false)) {
            return false;
        }

        if (true === ($context['preview'] ?? false)) {
            return true;
        }

        $activeRequest = $request ?? $this->requestStack?->getCurrentRequest();
        if (null === $activeRequest) {
            return false;
        }

        if ((bool) ($activeRequest->attributes->get('preview') ?? false)) {
            return true;
        }

        $rawRequestUri = $activeRequest->server->get('REQUEST_URI');
        $requestUri = \is_string($rawRequestUri) ? $rawRequestUri : '';

        return \str_ends_with($requestUri, '/_sulu_preview');
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

            $fieldType = $metadataFieldTypes[$field] ?? $this->detectValueFieldType($value, $field);

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

                if (\is_string($block[$field]) && null === SuluDataNormalizer::sanitizeNullableUrl($block[$field])) {
                    $block[$field] = '#';
                }

                if (isset($block['props']) && \is_array($block['props'])) {
                    if (\array_key_exists($field, $block['props'])) {
                        $block['props'][$field] = $block[$field];
                    }
                    if (isset($block[$field . '_url']) && \array_key_exists($field . '_url', $block['props'])) {
                        $block['props'][$field . '_url'] = $block[$field . '_url'];
                    }
                }
                if (isset($block['_props']) && \is_array($block['_props'])) {
                    if (\array_key_exists($field, $block['_props'])) {
                        $block['_props'][$field] = $block[$field];
                    }
                    if (isset($block[$field . '_url']) && \array_key_exists($field . '_url', $block['_props'])) {
                        $block['_props'][$field . '_url'] = $block[$field . '_url'];
                    }
                }
            } elseif ('link' === $fieldType) {
                $resolvedUrl = SuluDataNormalizer::resolveUrl($value);
                if (!isset($block[$field . '_url']) || '' === $block[$field . '_url']) {
                    $block[$field . '_url'] = $resolvedUrl;
                } elseif (\is_string($block[$field . '_url'])) {
                    $block[$field . '_url'] = SuluDataNormalizer::sanitizeUrl($block[$field . '_url']);
                } else {
                    $block[$field . '_url'] = $resolvedUrl;
                }

                if (\is_string($block[$field])) {
                    $block[$field] = SuluDataNormalizer::sanitizeUrl($block[$field]);
                } elseif (\is_array($block[$field])) {
                    if (isset($block[$field]['url']) && \is_string($block[$field]['url'])) {
                        $block[$field]['url'] = SuluDataNormalizer::sanitizeUrl($block[$field]['url']);
                    }
                    if (isset($block[$field]['href']) && \is_string($block[$field]['href'])) {
                        $block[$field]['href'] = SuluDataNormalizer::sanitizeUrl($block[$field]['href']);
                    }
                }

                // Synchronize sanitized link and url values back into props and _props if present
                if (isset($block['props']) && \is_array($block['props'])) {
                    if (\array_key_exists($field, $block['props'])) {
                        $block['props'][$field] = $block[$field];
                        if (isset($block[$field . '_url'])) {
                            $block['props'][$field . '_url'] = $block[$field . '_url'];
                        }
                    } elseif (isset($block[$field . '_url']) && \array_key_exists($field . '_url', $block['props'])) {
                        $block['props'][$field . '_url'] = $block[$field . '_url'];
                    }
                }
                if (isset($block['_props']) && \is_array($block['_props'])) {
                    if (\array_key_exists($field, $block['_props'])) {
                        $block['_props'][$field] = $block[$field];
                        if (isset($block[$field . '_url'])) {
                            $block['_props'][$field . '_url'] = $block[$field . '_url'];
                        }
                    } elseif (isset($block[$field . '_url']) && \array_key_exists($field . '_url', $block['_props'])) {
                        $block['_props'][$field . '_url'] = $block[$field . '_url'];
                    }
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

        return !\str_ends_with($field, '_srcset')
            && !\str_ends_with($field, '_focus_style')
            && !\str_ends_with($field, '_view')
            && !\str_ends_with($field, '_entity')
            && !\str_ends_with($field, '_submitted');
    }

    /**
     * @return array<string, string>
     */
    private function getMetadataFieldTypes(string $blockType): array
    {
        return null !== $this->fieldMetadataResolver
            ? $this->fieldMetadataResolver->resolveFieldTypeMap($blockType)
            : [];
    }

    private function detectValueFieldType(mixed $value, ?string $field = null): ?string
    {
        if (\is_string($value)) {
            $trimmed = \trim($value);
            if ('' === $trimmed) {
                return null;
            }

            $inspected = SuluDataNormalizer::inspectUrlScheme($trimmed);
            // If decoding failed or a dangerous scheme is detected, classify as link so it gets sanitized
            if (null === $inspected || $inspected['is_dangerous_scheme']) {
                return 'link';
            }

            $cleanStr = $inspected['normalized'];
            $hasSchemeColon = $inspected['has_scheme_colon'];
            $isMediaFile = 1 === \preg_match('/\.(?:jpe?g|png|webp|gif|avif|svg|bmp|ico|mp4|webm|ogg|mov|m4v|mp3|wav|pdf)(?:[?#].*)?$/i', $trimmed);

            if (null !== $field && ('link' === $field || \str_ends_with($field, '_link') || 'url' === $field || \str_ends_with($field, '_url'))) {
                return $isMediaFile ? 'media' : 'link';
            }

            if ($hasSchemeColon || 1 === \preg_match('/^(?:https?:\/\/|\/|#|\?|mailto:|tel:|sms:|\/\/)/i', $cleanStr)) {
                return $isMediaFile ? 'media' : 'link';
            }

            return null;
        }

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

        if (\array_key_exists('url', $value)) {
            if (\is_string($value['url'])) {
                if (
                    \array_key_exists('id', $value)
                    || 1 === \preg_match('/\.(?:jpe?g|png|webp|gif|avif|svg|bmp|ico|mp4|webm|ogg|mov|m4v|mp3|wav|pdf)(?:[?#].*)?$/i', $value['url'])
                ) {
                    return 'media';
                }
            }

            return 'link';
        }

        return null;
    }
}
