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

    /**
     * Reads a non-empty value from a collection item array (`$item[$key]`, `$item['props'][$key]`, or `$item['props/' . $key]`).
     *
     * @param array<array-key, mixed> $item
     */
    public static function itemValue(array $item, string $key): mixed
    {
        if (\array_key_exists($key, $item) && null !== $item[$key] && '' !== $item[$key]) {
            return $item[$key];
        }

        if (
            isset($item['props'])
            && \is_array($item['props'])
            && \array_key_exists($key, $item['props'])
            && null !== $item['props'][$key]
            && '' !== $item['props'][$key]
        ) {
            return $item['props'][$key];
        }

        $prefixedKey = 'props/' . $key;
        if (\array_key_exists($prefixedKey, $item) && null !== $item[$prefixedKey] && '' !== $item[$prefixedKey]) {
            return $item[$prefixedKey];
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $item
     */
    public static function itemString(array $item, string $key, string $default = ''): string
    {
        $val = self::itemValue($item, $key);
        if (\is_string($val)) {
            $trimmed = \trim($val);

            return '' !== $trimmed ? $trimmed : $default;
        }
        if (\is_int($val) || \is_float($val)) {
            return (string) $val;
        }

        return $default;
    }

    /**
     * @param array<array-key, mixed> $item
     */
    public static function itemNullableString(array $item, string $key): ?string
    {
        $val = self::itemString($item, $key, '');

        return '' !== $val ? $val : null;
    }

    /**
     * @param array<array-key, mixed> $item
     */
    public static function itemBool(array $item, string $key, bool $default = false): bool
    {
        $val = self::itemValue($item, $key);
        if (\is_bool($val)) {
            return $val;
        }
        if (\is_int($val)) {
            return 1 === $val;
        }
        if (\is_string($val)) {
            return \in_array(\strtolower(\trim($val)), ['1', 'true', 'yes', 'on'], true);
        }

        return $default;
    }

    /**
     * Reads a string prop from a collection item (`$item[$key]`, `$item['props'][$key]`, `$item['props/' . $key]`,
     * and optional `$aliasKey`), ignoring `'inherit'` and falling back to `$fallback`.
     *
     * @param array<array-key, mixed> $item
     */
    public static function itemInheritedString(
        array $item,
        string $key,
        string $fallback,
        ?string $aliasKey = null,
    ): string {
        $itemProps = isset($item['props']) && \is_array($item['props']) ? $item['props'] : [];

        $candidates = [
            $item[$key] ?? null,
            $itemProps[$key] ?? null,
            $item['props/' . $key] ?? null,
        ];
        if (null !== $aliasKey) {
            $candidates[] = $item[$aliasKey] ?? null;
            $candidates[] = $itemProps[$aliasKey] ?? null;
            $candidates[] = $item['props/' . $aliasKey] ?? null;
        }

        foreach ($candidates as $candidate) {
            if (\is_string($candidate) || \is_int($candidate) || \is_float($candidate)) {
                $trimmed = \trim((string) $candidate);
                if ('' !== $trimmed && 'inherit' !== $trimmed) {
                    return $trimmed;
                }
            }
        }

        return $fallback;
    }
}
