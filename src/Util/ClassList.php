<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Util;

/**
 * Framework-agnostic CSS class list helpers.
 */
final class ClassList
{
    /**
     * Joins any combination of CSS class strings, (nested) arrays of class strings, or falsy values
     * into a single whitespace-normalized class string. Non-string scalars are ignored.
     *
     * @param string|array<int|string, mixed>|false|null ...$parts
     */
    public static function join(string|array|false|null ...$parts): string
    {
        $tokens = [];

        foreach ($parts as $part) {
            if (\is_array($part)) {
                foreach ($part as $nested) {
                    if (\is_string($nested) || \is_array($nested) || null === $nested || false === $nested) {
                        $sub = self::join($nested);
                        if ('' !== $sub) {
                            $tokens[] = $sub;
                        }
                    }
                }
                continue;
            }

            if (!\is_string($part)) {
                continue;
            }

            $normalized = \trim((string) \preg_replace('/\s+/', ' ', $part));
            if ('' !== $normalized) {
                $tokens[] = $normalized;
            }
        }

        return \implode(' ', $tokens);
    }

    /**
     * Splits a class string into unique, non-empty class tokens, preserving first-occurrence order.
     *
     * @return list<string>
     */
    public static function tokens(?string $classes): array
    {
        if (null === $classes) {
            return [];
        }

        $tokens = [];
        foreach (\preg_split('/\s+/', \trim($classes), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (!\in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }
}
