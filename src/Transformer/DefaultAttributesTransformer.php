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
        /** @var array<string, string|bool> $attributes */
        $attributes = [];
        $rawAttrs = $props['attributes'] ?? ($block['attributes'] ?? null);
        if (\is_string($rawAttrs) && '' !== \trim($rawAttrs)) {
            foreach (\explode("\n", $rawAttrs) as $line) {
                $line = \trim($line);
                if ('' === $line) {
                    continue;
                }

                $parts = \explode('=', $line, 2);
                $attrName = \trim($parts[0]);
                if ('' === $attrName || \in_array(\strtolower($attrName), ['id', 'class'], true)) {
                    continue;
                }

                if (2 === \count($parts)) {
                    $val = \trim($parts[1]);
                    // Strip surrounding quotes if present
                    $unquoted = (string) \preg_replace('/^([\'"])(.*)(\1)$/', '$2', $val);
                    $attributes[$attrName] = $unquoted;
                } else {
                    $attributes[$attrName] = true;
                }
            }
        }

        // 6. Custom Scoped CSS
        $rawCss = $props['css'] ?? ($block['css'] ?? null);
        $scopedCss = null;
        if (\is_string($rawCss) && '' !== \trim($rawCss)) {
            if (null === $elementId || '' === $elementId) {
                // Generate a stable unique element ID for CSS scoping
                $blockType = isset($block['type']) && \is_scalar($block['type']) ? (string) $block['type'] : '';
                $blockHash = \substr(\md5(\serialize($block) . $blockType), 0, 8);
                $elementId = 'el-' . $blockHash;
            }

            $scopedCss = self::scopeCss(\trim($rawCss), '#' . $elementId);
        }

        // Attach resolved id to attributes if present
        if (null !== $elementId && '' !== $elementId) {
            $attributes['id'] = $elementId;
            $block['_id'] = $elementId;
        }

        $block['_classes'] = $classes;
        $block['_class'] = \implode(' ', $classes);
        $block['_attributes'] = $attributes;
        $block['_attributes_string'] = $this->renderAttributesString($attributes);

        if (null !== $scopedCss) {
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
     * Scope CSS by replacing .el-element with the prefix, or prepending the prefix to each rule.
     */
    public static function scopeCss(string $css, string $prefix): string
    {
        if ('' === \trim($css)) {
            return '';
        }

        if (\str_contains($css, '.el-element')) {
            $scoped = \str_replace('.el-element', $prefix, $css);
        } else {
            // Match CSS rule blocks: selector { declarations }
            $pattern = '/([@#:.\w[\]][\\\\@#:,<>~="\'+\-^$.()\w\s[\]*]*)({[^{}]+})/u';
            $scoped = (string) \preg_replace_callback(
                $pattern,
                static function (array $matches) use ($prefix): string {
                    $selector = \trim((string) $matches[1]);
                    $body = (string) $matches[2];

                    if (\str_starts_with($selector, '@')) {
                        // At-rules like @media or @keyframes
                        return $selector . ' ' . $body;
                    }

                    $selectors = \array_map('trim', \explode(',', $selector));
                    $prefixed = \array_map(static fn (string $s): string => $prefix . ' ' . $s, $selectors);

                    return \implode(', ', $prefixed) . ' ' . $body;
                },
                $css,
            );
        }

        // Normalize whitespace
        $scoped = (string) \preg_replace('/[\r\n\t]+/u', ' ', $scoped);

        return \trim((string) \preg_replace('/\s+/', ' ', $scoped));
    }

    /**
     * @param array<string, string|bool> $attributes
     */
    private function renderAttributesString(array $attributes): string
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
