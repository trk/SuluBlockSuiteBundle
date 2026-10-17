<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Security;

/**
 * Single source of truth for editor-provided custom CSS: sanitization against `<style>` breakout
 * and script expressions, scoping to an element selector, and stable scoping-id generation.
 */
final class CssScoper
{
    /**
     * Placeholder selector editors use to target the element itself.
     */
    public const ELEMENT_PLACEHOLDER = '.el-element';

    /**
     * Prefix of generated element ids.
     */
    public const GENERATED_ID_PREFIX = 'el-';

    /**
     * Sanitizes raw or pre-scoped CSS so it cannot break out of a `<style>` tag or execute script expressions.
     */
    public static function sanitize(string $css): string
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

        // Strip backslash escapes first, then CSS comments in a loop to prevent /\**\/ obfuscation
        $clean = \str_replace('\\', '', $clean);
        do {
            $prevCss = $clean;
            $clean = (string) \preg_replace('/\/\*.*?\*\//s', '', $clean);
        } while ($clean !== $prevCss);
        $clean = \str_replace(['/*', '*/'], '', $clean);

        // Strip @import and legacy script expressions
        $clean = (string) \preg_replace('/@import\b[^;{}]*;?/i', '', $clean);
        $clean = (string) \preg_replace('/expression\s*\([^)]*\)/i', '', $clean);
        $clean = (string) \preg_replace('/url\s*\(\s*[\'"]?\s*(?:javascript|vbscript|data):[^)]*\)/i', 'none', $clean);

        return \trim($clean);
    }

    /**
     * Scopes CSS to `$prefix`: replaces `.el-element` with the prefix, wraps bare declarations,
     * or prepends the prefix to each rule selector. Input and prefix are sanitized first.
     */
    public static function scope(string $css, string $prefix): string
    {
        $cleanCss = self::sanitize($css);
        $cleanPrefix = self::sanitize($prefix);
        if ('' === $cleanCss || '' === $cleanPrefix) {
            return '';
        }

        if (\str_contains($cleanCss, self::ELEMENT_PLACEHOLDER)) {
            $scoped = \str_replace(self::ELEMENT_PLACEHOLDER, $cleanPrefix, $cleanCss);
        } elseif (!\str_contains($cleanCss, '{')) {
            $declarations = \rtrim(\trim($cleanCss), ';');
            $scoped = $cleanPrefix . ' { ' . $declarations . '; }';
        } else {
            // Match CSS rule blocks: selector { declarations }
            $pattern = '/([@#:.\w[\]][\\\\\\\\@#:,~="\'+\-^$.()\w\s[\]*]*)({[^{}]+})/u';
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
     * Generates a stable element id (`el-` + 8 hex chars) from an arbitrary JSON-encodable seed,
     * used to scope custom CSS when the editor did not provide an explicit id.
     */
    public static function generateElementId(mixed $seed, string $salt = ''): string
    {
        $encoded = \json_encode($seed, \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return self::GENERATED_ID_PREFIX . \substr(\md5((false !== $encoded ? $encoded : '') . $salt), 0, 8);
    }
}
