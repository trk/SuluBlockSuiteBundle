<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Transformer;

use Totoglu\SuluBlockSuiteBundle\Normalizer\SuluDataNormalizer;

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
        $rawId = $props['id'] ?? ($block['id'] ?? null);
        $elementId = null;
        if (\is_string($rawId) && '' !== \trim($rawId)) {
            $elementId = \trim($rawId);
        }

        // 4. Classes
        /** @var list<string> $classes */
        $classes = [];
        $rawClass = $props['class'] ?? ($props['custom_class'] ?? ($block['class'] ?? null));
        if (\is_string($rawClass) && '' !== \trim($rawClass)) {
            foreach (\explode(' ', \trim($rawClass)) as $c) {
                $trimmed = \trim($c);
                if ('' !== $trimmed && !\in_array($trimmed, $classes, true)) {
                    $classes[] = $trimmed;
                }
            }
        }

        // 5. Custom Attributes (key=value multi-line)
        $rawAttrs = $props['attributes'] ?? ($block['attributes'] ?? null);
        $attributes = \is_string($rawAttrs) ? self::parseAttributes($rawAttrs) : [];

        // 6. Custom Scoped CSS
        $rawCss = $props['css'] ?? ($block['css'] ?? null);
        $scopedCss = null;
        if (\is_string($rawCss) && '' !== \trim($rawCss)) {
            if (null === $elementId || '' === $elementId) {
                // Generate a stable unique element ID for CSS scoping without PHP object serialization
                $blockType = isset($block['type']) && \is_scalar($block['type']) ? (string) $block['type'] : '';
                $encoded = \json_encode($block, \JSON_PARTIAL_OUTPUT_ON_ERROR);
                $blockHash = \substr(\md5((false !== $encoded ? $encoded : '') . $blockType), 0, 8);
                $elementId = 'el-' . $blockHash;
            }

            $scopedCss = self::scopeCss(\trim($rawCss), '#' . $elementId);
        }

        // Attach resolved id to attributes and props if present
        if (null !== $elementId && '' !== $elementId) {
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
        $block['_attributes_string'] = self::renderAttributesString($attributes);

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

    /**
     * Parse multi-line key=value custom HTML attributes safely, rejecting inline JS event handlers
     * and dangerous URL schemes.
     *
     * @return array<string, string|bool>
     */
    public static function parseAttributes(string $rawAttrs): array
    {
        if ('' === \trim($rawAttrs)) {
            return [];
        }

        /** @var array<string, string|bool> $attributes */
        $attributes = [];
        foreach (\explode("\n", $rawAttrs) as $line) {
            $line = \trim($line);
            if ('' === $line) {
                continue;
            }

            $parts = \explode('=', $line, 2);
            $attrName = \trim($parts[0]);

            if (!self::isAllowedAttributeName($attrName)) {
                continue;
            }

            if (2 === \count($parts)) {
                $val = \trim($parts[1]);
                // Strip surrounding quotes if present
                $unquoted = (string) \preg_replace('/^([\'"])(.*)(\1)$/', '$2', $val);
                if (!self::isAllowedAttributeValue($attrName, $unquoted)) {
                    continue;
                }
                $attributes[$attrName] = $unquoted;
            } else {
                $attributes[$attrName] = true;
            }
        }

        return $attributes;
    }

    /**
     * Sanitize an associative array of custom attributes, enforcing the same security rules as parseAttributes().
     *
     * @param array<mixed, mixed> $rawAttributes
     *
     * @return array<string, string|bool>
     */
    public static function sanitizeAttributesArray(array $rawAttributes): array
    {
        /** @var array<string, string|bool> $sanitized */
        $sanitized = [];
        foreach ($rawAttributes as $name => $value) {
            if (!\is_string($name)) {
                continue;
            }
            $attrName = \trim($name);
            if (!self::isAllowedAttributeName($attrName)) {
                continue;
            }

            if (\is_bool($value)) {
                if ($value) {
                    $sanitized[$attrName] = true;
                }
                continue;
            }

            if (\is_scalar($value)) {
                $strVal = (string) $value;
                if (!self::isAllowedAttributeValue($attrName, $strVal)) {
                    continue;
                }
                $sanitized[$attrName] = $strVal;
            }
        }

        return $sanitized;
    }

    private static function isAllowedAttributeName(string $attrName): bool
    {
        if ('' === $attrName) {
            return false;
        }

        $lowerName = \strtolower($attrName);
        if (
            \in_array($lowerName, ['id', 'class', 'srcdoc'], true)
            || \str_starts_with($lowerName, 'on')
            || 1 !== \preg_match('/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/', $attrName)
        ) {
            return false;
        }

        return true;
    }

    private static function isAllowedAttributeValue(string $attrName, string $value): bool
    {
        $lowerName = \strtolower($attrName);
        if (\in_array($lowerName, ['href', 'src', 'xlink:href', 'action', 'formaction', 'data', 'poster'], true)) {
            $normalized = (string) \preg_replace('/[\x00-\x20]+/', '', $value);
            if (1 === \preg_match('/^(?:javascript|vbscript|data):/i', $normalized)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sanitize pre-scoped or raw CSS so it cannot break out of a <style> tag or execute script expressions.
     */
    public static function sanitizeCssFragment(string $css): string
    {
        $clean = \trim($css);
        if ('' === $clean) {
            return '';
        }

        do {
            $previous = $clean;
            $clean = (string) \preg_replace('/<script\b[^<>]*>.*?<\/script>/is', '', $clean);
            $clean = (string) \preg_replace('/<\/?[a-zA-Z!\/?][^<>]*>/s', '', $clean);
        } while ($clean !== $previous);

        // Strip any remaining angle brackets so </style> breakout is impossible
        $clean = \str_replace(['<', '>'], '', $clean);

        // Strip @import and legacy script expressions
        $clean = (string) \preg_replace('/@import\b[^;{}]*;?/i', '', $clean);
        $clean = (string) \preg_replace('/expression\s*\([^)]*\)/i', '', $clean);
        $clean = (string) \preg_replace('/url\s*\(\s*[\'"]?\s*(?:javascript|vbscript|data):[^)]*\)/i', 'none', $clean);

        return \trim($clean);
    }

    /**
     * Scope CSS by replacing .el-element with the prefix, wrapping bare declarations,
     * or prepending the prefix to each rule selector.
     */
    public static function scopeCss(string $css, string $prefix): string
    {
        $cleanCss = self::sanitizeCssFragment($css);
        $cleanPrefix = self::sanitizeCssFragment($prefix);
        if ('' === $cleanCss || '' === $cleanPrefix) {
            return '';
        }

        if (\str_contains($cleanCss, '.el-element')) {
            $scoped = \str_replace('.el-element', $cleanPrefix, $cleanCss);
        } elseif (!\str_contains($cleanCss, '{')) {
            $declarations = \rtrim(\trim($cleanCss), ';');
            $scoped = $cleanPrefix . ' { ' . $declarations . '; }';
        } else {
            // Match CSS rule blocks: selector { declarations }
            $pattern = '/([@#:.\w[\]][\\\\@#:,~="\'+\-^$.()\w\s[\]*]*)({[^{}]+})/u';
            $scoped = (string) \preg_replace_callback(
                $pattern,
                static function (array $matches) use ($cleanPrefix): string {
                    $selector = \trim((string) $matches[1]);
                    $body = (string) $matches[2];

                    if (\str_starts_with($selector, '@')) {
                        // At-rules like @media or @keyframes
                        return $selector . ' ' . $body;
                    }

                    $selectors = \array_map('trim', \explode(',', $selector));
                    $prefixed = \array_map(static fn (string $s): string => $cleanPrefix . ' ' . $s, $selectors);

                    return \implode(', ', $prefixed) . ' ' . $body;
                },
                $cleanCss,
            );
        }

        // Normalize whitespace
        $scoped = (string) \preg_replace('/[\r\n\t]+/u', ' ', $scoped);

        return \trim((string) \preg_replace('/\s+/', ' ', $scoped));
    }

    /**
     * @param array<string, string|bool> $attributes
     */
    public static function renderAttributesString(array $attributes): string
    {
        $rendered = [];
        foreach ($attributes as $name => $value) {
            if (true === $value) {
                $rendered[] = \htmlspecialchars($name, \ENT_QUOTES, 'UTF-8');
            } elseif (false !== $value) {
                $rendered[] = \sprintf(
                    '%s="%s"',
                    \htmlspecialchars($name, \ENT_QUOTES, 'UTF-8'),
                    \htmlspecialchars((string) $value, \ENT_QUOTES, 'UTF-8'),
                );
            }
        }

        return \implode(' ', $rendered);
    }
}
