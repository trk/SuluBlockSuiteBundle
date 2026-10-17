<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Util;

/**
 * Framework-agnostic readers that coerce loosely typed block/prop values into strict scalars.
 */
final class PropReader
{
    /**
     * Coerces strings, numbers, and `Stringable` objects to a string; returns `null` for anything else.
     */
    public static function toString(mixed $value): ?string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return null;
    }

    /**
     * Interprets booleans, integers, and boolean-like strings (`true`/`1`/`yes`/`on`,
     * `false`/`0`/`no`/`off`/`none`/empty). Any other non-empty string counts as `true`;
     * other types fall back to `$default`.
     */
    public static function toBool(mixed $value, bool $default = false): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return 0 !== $value;
        }

        if (\is_string($value)) {
            $lower = \strtolower(\trim($value));
            if (\in_array($lower, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
            if (\in_array($lower, ['false', '0', 'no', 'off', 'none', ''], true)) {
                return false;
            }

            return true;
        }

        return $default;
    }

    /**
     * Reads a trimmed non-empty string from `$values[$key]` (then `$values[$fallbackKey]`),
     * optionally ignoring `'inherit'`, or returns `null`.
     *
     * @param array<array-key, mixed> $values
     */
    public static function nullableString(
        array $values,
        string $key,
        ?string $fallbackKey = null,
        bool $ignoreInherit = false,
    ): ?string {
        foreach ([$key, $fallbackKey] as $candidateKey) {
            if (null === $candidateKey) {
                continue;
            }

            $raw = self::toString($values[$candidateKey] ?? null);
            if (null === $raw) {
                continue;
            }

            $trimmed = \trim($raw);
            if ('' === $trimmed || ($ignoreInherit && 'inherit' === $trimmed)) {
                continue;
            }

            return $trimmed;
        }

        return null;
    }

    /**
     * Reads a trimmed non-empty string from `$values[$key]` (then `$values[$fallbackKey]`),
     * optionally ignoring `'inherit'`, falling back to `$default`.
     *
     * @param array<array-key, mixed> $values
     */
    public static function string(
        array $values,
        string $key,
        string $default = '',
        ?string $fallbackKey = null,
        bool $ignoreInherit = false,
    ): string {
        return self::nullableString($values, $key, $fallbackKey, $ignoreInherit) ?? $default;
    }

    /**
     * Reads a boolean from `$values[$key]` via `toBool()`; a missing or `null` value yields `$default`.
     *
     * @param array<array-key, mixed> $values
     */
    public static function bool(array $values, string $key, bool $default = false): bool
    {
        $value = $values[$key] ?? null;

        return null === $value ? $default : self::toBool($value, $default);
    }
}
