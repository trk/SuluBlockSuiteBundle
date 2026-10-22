<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Security;

/**
 * Single source of truth for editor-provided HTML element ids and custom attributes:
 * validation, parsing, sanitization (no inline event handlers, no dangerous URL schemes,
 * no reserved attributes), and escaped rendering.
 */
final class AttributeSanitizer
{
    /**
     * Attributes that are owned by the rendering pipeline and must never be set through custom attributes.
     */
    private const RESERVED_ATTRIBUTES = ['id', 'class', 'style', 'srcdoc', 'srcset'];

    /**
     * Attributes whose values are URLs and must not use script-capable schemes.
     */
    private const URL_ATTRIBUTES = ['href', 'src', 'xlink:href', 'action', 'formaction', 'data', 'poster'];

    /**
     * Returns the trimmed id when it is a valid element id (`[a-zA-Z][a-zA-Z0-9_-]*`), otherwise `null`.
     */
    public static function sanitizeId(mixed $id): ?string
    {
        if (!\is_string($id)) {
            return null;
        }

        $candidate = \trim($id);

        return 1 === \preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $candidate) ? $candidate : null;
    }

    /**
     * Parses multi-line `key=value` (or bare `key`) custom HTML attributes, dropping disallowed entries.
     *
     * @return array<string, string|bool>
     */
    public static function parse(string $rawAttributes): array
    {
        if ('' === \trim($rawAttributes)) {
            return [];
        }

        /** @var array<string, string|bool> $attributes */
        $attributes = [];
        foreach (\explode("\n", $rawAttributes) as $line) {
            $line = \trim($line);
            if ('' === $line) {
                continue;
            }

            $parts = \explode('=', $line, 2);
            $attrName = \trim($parts[0]);

            if (!self::isAllowedName($attrName)) {
                continue;
            }

            if (2 === \count($parts)) {
                $val = \trim($parts[1]);
                // Strip surrounding quotes if present
                $unquoted = (string) \preg_replace('/^([\'"])(.*)(\1)$/', '$2', $val);
                if (!self::isAllowedValue($attrName, $unquoted)) {
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
     * Sanitizes an associative attribute array with the same rules as `parse()`.
     * `true` renders as a bare attribute; `false` and non-scalar values are dropped.
     *
     * @param array<mixed, mixed> $rawAttributes
     *
     * @return array<string, string|bool>
     */
    public static function sanitize(array $rawAttributes): array
    {
        /** @var array<string, string|bool> $sanitized */
        $sanitized = [];
        foreach ($rawAttributes as $name => $value) {
            if (!\is_string($name)) {
                continue;
            }
            $attrName = \trim($name);
            if (!self::isAllowedName($attrName)) {
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
                if (!self::isAllowedValue($attrName, $strVal)) {
                    continue;
                }
                $sanitized[$attrName] = $strVal;
            }
        }

        return $sanitized;
    }

    /**
     * Renders an attribute array as an HTML-escaped attribute string.
     *
     * @param array<string, string|bool> $attributes
     */
    public static function render(array $attributes): string
    {
        $rendered = [];
        foreach ($attributes as $name => $value) {
            if (true === $value) {
                $rendered[] = \htmlspecialchars($name, \ENT_QUOTES, 'UTF-8');
            } elseif (false !== $value) {
                $rendered[] = \sprintf(
                    '%s="%s"',
                    \htmlspecialchars($name, \ENT_QUOTES, 'UTF-8'),
                    \htmlspecialchars($value, \ENT_QUOTES, 'UTF-8'),
                );
            }
        }

        return \implode(' ', $rendered);
    }

    private static function isAllowedName(string $attrName): bool
    {
        if ('' === $attrName) {
            return false;
        }

        $lowerName = \strtolower($attrName);

        return !\in_array($lowerName, self::RESERVED_ATTRIBUTES, true)
            && !\str_starts_with($lowerName, 'on')
            && 1 === \preg_match('/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/', $attrName);
    }

    private static function isAllowedValue(string $attrName, string $value): bool
    {
        if (!\in_array(\strtolower($attrName), self::URL_ATTRIBUTES, true)) {
            return true;
        }

        $decoded = $value;
        for ($i = 0; $i < 2; ++$i) {
            $prev = $decoded;
            $numericDecoded = \preg_replace_callback(
                '/&#(?:x([0-9a-f]+)|([0-9]+));?/i',
                static function (array $m): string {
                    $code = '' !== $m[1] ? (int) \hexdec($m[1]) : (int) $m[2];
                    if ($code <= 31 || 127 === $code) {
                        return '';
                    }

                    return (string) \mb_chr($code, 'UTF-8');
                },
                $decoded,
            );
            if (null === $numericDecoded || \PREG_NO_ERROR !== \preg_last_error()) {
                return false;
            }

            $decoded = \html_entity_decode($numericDecoded, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
            if ($decoded === $prev) {
                break;
            }
        }

        $cleanUtf8 = \mb_convert_encoding($decoded, 'UTF-8', 'UTF-8');
        $normalized = \preg_replace('/[\x00-\x20\p{C}\p{Z}]+/u', '', $cleanUtf8);
        if (null === $normalized || \PREG_NO_ERROR !== \preg_last_error()) {
            return false;
        }

        return 1 !== \preg_match('/^(?:javascript|vbscript|data|file|blob):/i', $normalized);
    }
}
