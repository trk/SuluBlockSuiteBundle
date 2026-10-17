<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Transformer;

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
        $rawCss = $props['css'] ?? ($block['css'] ?? null);
        $scopedCss = null;
        if (\is_string($rawCss) && '' !== \trim($rawCss)) {
            if (null === $elementId) {
                // Generate a stable unique element ID for CSS scoping without PHP object serialization
                $blockType = isset($block['type']) && \is_scalar($block['type']) ? (string) $block['type'] : '';
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

        // 7. Sulu CMS Data Normalization (Media & Links)
        if (isset($block['image']) && !isset($block['image_url'])) {
            $block['image_url'] = SuluDataNormalizer::resolveImageUrl($block['image']);
            $block['image_srcset'] = SuluDataNormalizer::resolveSrcSet($block['image']);
            $block['image_focus_style'] = SuluDataNormalizer::resolveFocusPointStyle($block['image']);
        }

        if (isset($block['hover_image']) && !isset($block['hover_image_url'])) {
            $block['hover_image_url'] = SuluDataNormalizer::resolveImageUrl($block['hover_image']);
        }

        $link = $block['link'] ?? ($block['card_link'] ?? null);
        if (null !== $link && !isset($block['link_url'])) {
            $block['link_url'] = SuluDataNormalizer::resolveUrl($link);
        }

        return $block;
    }
}
