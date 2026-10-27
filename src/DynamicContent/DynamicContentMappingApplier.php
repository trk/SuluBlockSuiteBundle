<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent;

use Totoglu\SuluBlockSuiteBundle\Metadata\BlockTargetFieldMetadataResolver;

/**
 * Pure field mapper, `{{ token }}` interpolator, auto-mapper, path extractor,
 * and condition evaluator for Dynamic Content hydration.
 */
final class DynamicContentMappingApplier
{
    public function __construct(
        private readonly ?BlockTargetFieldMetadataResolver $targetFieldResolver = null,
        private readonly ?DynamicContentSourceRegistry $registry = null,
    ) {
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $pageData
     * @param array<string, string> $mapping
     * @param int $appliedCount incremented for every mapped field that received a non-empty dynamic value
     *
     * @return array<string, mixed>
     */
    public function applyMapping(array $block, array $pageData, array $mapping, int &$appliedCount = 0): array
    {
        $hydrated = $block;
        $hasInlineTokens = $this->blockContainsTokens($block);

        if ([] === $mapping && !$hasInlineTokens) {
            $mapping = $this->buildAutoMapping($block, $pageData);
        }

        /** @var array<string, true> $mappedTargets */
        $mappedTargets = [];

        foreach ($mapping as $targetField => $sourcePath) {
            if ('' === $targetField || '' === $sourcePath) {
                continue;
            }

            if (\str_contains($sourcePath, '{{')) {
                $value = $this->interpolateStringTokens($sourcePath, $pageData, $appliedCount);
            } else {
                $value = $this->extractValue($pageData, $sourcePath);
            }

            // Smart Fallback: only override if the resolved dynamic value is non-empty
            if ($this->isValuePresent($value)) {
                $hydrated[$targetField] = $this->normalizeMappedValue($targetField, $value, $pageData, $appliedCount);
                $mappedTargets[$targetField] = true;
                ++$appliedCount;
            }
        }

        // Interpolate `{{field}}` tokens in any unmapped template fields (including nested sub-blocks like buttons/elements)
        foreach ($hydrated as $fieldKey => $fieldValue) {
            if (
                isset($mappedTargets[$fieldKey])
                || \in_array($fieldKey, ['type', 'props', 'id', 'items', 'settings', 'is_dynamic_template'], true)
                || \str_starts_with($fieldKey, '_')
            ) {
                continue;
            }

            $hydrated[$fieldKey] = $this->interpolateTokensInValue($fieldValue, $pageData, $appliedCount);
        }

        return $hydrated;
    }

    /**
     * Extracts a value from source data supporting dot-notation (`a.b`),
     * pipe-separated fallback chains (`subtitle|title`, `summary|content:length=160`),
     * and inline text modifiers (`:length=160`, `:limit=160`, `:truncate=160`, `:plain`).
     *
     * @param array<string, mixed> $data
     */
    public function extractValue(array $data, string $path): mixed
    {
        $alternatives = \explode('|', $path);
        $lastValue = null;

        foreach ($alternatives as $rawCandidate) {
            $candidate = \trim($rawCandidate);
            if ('' === $candidate) {
                continue;
            }

            $fieldPath = $candidate;
            $maxLength = 0;
            $stripHtml = false;

            if (1 === \preg_match('/^([^:]+):(?:length|limit|truncate)=(\d+)$/i', $candidate, $matches)) {
                $fieldPath = \trim($matches[1]);
                $maxLength = (int) $matches[2];
                $stripHtml = true;
            } elseif (1 === \preg_match('/^([^:]+):plain$/i', $candidate, $matches)) {
                $fieldPath = \trim($matches[1]);
                $maxLength = 0;
                $stripHtml = true;
            }

            $val = $this->extractSinglePathValue($data, $fieldPath);

            if ($stripHtml) {
                $val = \is_scalar($val) ? self::truncatePlainText((string) $val, $maxLength) : null;
            }

            $lastValue = $val;
            if ($this->isValuePresent($val)) {
                return $val;
            }
        }

        return $lastValue;
    }

    public static function truncatePlainText(string $value, int $maxLength = 160): string
    {
        if ('' === \trim($value)) {
            return '';
        }

        $spaced = \preg_replace('/<\/(p|div|li|h[1-6]|blockquote|tr|section|article)>|<br\s*\/?>/i', ' ', $value) ?? $value;
        $stripped = \strip_tags($spaced);
        $decoded = \html_entity_decode($stripped, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $decoded = \str_replace(['<', '>'], '', $decoded);
        $normalized = \trim(\preg_replace('/\s+/u', ' ', $decoded) ?? $decoded);

        if ('' === $normalized || $maxLength <= 0) {
            return $normalized;
        }

        if (\mb_strlen($normalized, 'UTF-8') <= $maxLength) {
            return $normalized;
        }

        if ($maxLength <= 3) {
            return \mb_substr($normalized, 0, $maxLength, 'UTF-8');
        }

        $cut = \mb_substr($normalized, 0, $maxLength - 3, 'UTF-8');

        return \rtrim($cut, " \t\n\r\0\x0B.,;:-") . '...';
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $condition
     */
    public function evaluateCondition(array $data, array $condition): bool
    {
        $field = isset($condition['field']) && \is_string($condition['field']) ? $condition['field'] : '';
        if ('' === $field) {
            return true;
        }

        $operator = isset($condition['operator']) && \is_string($condition['operator']) ? $condition['operator'] : 'not_empty';
        $val = $this->extractValue($data, $field);
        $hasValue = $this->isValuePresent($val);

        $condVal = isset($condition['value']) && \is_scalar($condition['value']) ? (string) $condition['value'] : '';
        $strVal = \is_scalar($val) ? (string) $val : '';

        return match ($operator) {
            'is_empty', 'empty' => !$hasValue,
            'equals', 'eq' => $strVal === $condVal,
            'not_equals', 'neq' => $strVal !== $condVal,
            'contains' => $this->evaluateContains($val, $strVal, $condVal),
            'not_contains' => !$this->evaluateContains($val, $strVal, $condVal),
            'starts_with' => '' !== $condVal && \str_starts_with(\strtolower($strVal), \strtolower($condVal)),
            'ends_with' => '' !== $condVal && \str_ends_with(\strtolower($strVal), \strtolower($condVal)),
            'in' => \in_array($strVal, \array_map('trim', \explode(',', $condVal)), true),
            'gt' => \is_numeric($strVal) && \is_numeric($condVal) ? (float) $strVal > (float) $condVal : $strVal > $condVal,
            'gte' => \is_numeric($strVal) && \is_numeric($condVal) ? (float) $strVal >= (float) $condVal : $strVal >= $condVal,
            'lt' => \is_numeric($strVal) && \is_numeric($condVal) ? (float) $strVal < (float) $condVal : $strVal < $condVal,
            'lte' => \is_numeric($strVal) && \is_numeric($condVal) ? (float) $strVal <= (float) $condVal : $strVal <= $condVal,
            default => $hasValue, // 'not_empty'
        };
    }

    /**
     * @param array<mixed, mixed> $block
     */
    private function blockContainsTokens(array $block): bool
    {
        foreach ($block as $key => $value) {
            if (\in_array($key, ['type', 'props', 'id', 'items', 'settings'], true) || \str_starts_with((string) $key, '_')) {
                continue;
            }
            if (\is_string($value) && \str_contains($value, '{{')) {
                return true;
            }
            if (\is_array($value) && $this->blockContainsTokens($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalizes collection values for sub-block targets (`badges`, `buttons`, `elements`) and interpolates any nested tokens.
     *
     * @param array<string, mixed> $pageData
     */
    private function normalizeMappedValue(string $targetField, mixed $value, array $pageData, int &$appliedCount): mixed
    {
        if (\is_array($value) && \in_array($targetField, ['badges', 'buttons', 'elements'], true)) {
            $defaultSubType = match ($targetField) {
                'badges' => 'badge',
                'buttons' => 'button',
                default => 'badge',
            };

            $normalizedList = [];
            foreach ($value as $entry) {
                if (\is_string($entry) || \is_int($entry) || \is_float($entry)) {
                    $strVal = \trim((string) $entry);
                    if ('' === $strVal) {
                        continue;
                    }
                    $normalizedList[] = 'button' === $defaultSubType
                        ? ['type' => 'button', 'url' => $strVal]
                        : ['type' => 'badge', 'title' => $strVal];
                } elseif (\is_array($entry)) {
                    if (!isset($entry['type']) || !\is_string($entry['type']) || '' === \trim($entry['type'])) {
                        $entry['type'] = $defaultSubType;
                    }
                    $normalizedList[] = $this->interpolateTokensInValue($entry, $pageData, $appliedCount);
                }
            }

            return $normalizedList;
        }

        return $this->interpolateTokensInValue($value, $pageData, $appliedCount);
    }

    /**
     * Recursively interpolates `{{field}}` tokens inside strings and arrays.
     *
     * @param array<string, mixed> $pageData
     */
    private function interpolateTokensInValue(mixed $value, array $pageData, int &$appliedCount): mixed
    {
        if (\is_string($value) && \str_contains($value, '{{')) {
            return $this->interpolateStringTokens($value, $pageData, $appliedCount);
        }

        if (\is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = $this->interpolateTokensInValue($v, $pageData, $appliedCount);
            }

            return $result;
        }

        return $value;
    }

    /**
     * Replaces `{{field}}` (supporting pipe fallbacks & modifiers like `{{summary|content:length=100}}`)
     * and cleans up empty separator segments (`|`, `•`, `—`).
     *
     * @param array<string, mixed> $pageData
     */
    private function interpolateStringTokens(string $template, array $pageData, int &$appliedCount): string
    {
        foreach ([' | ', ' • ', ' — '] as $separator) {
            if (\str_contains($template, $separator)) {
                $segments = \explode($separator, $template);
                $keptSegments = [];
                foreach ($segments as $segment) {
                    $segmentHadToken = \str_contains($segment, '{{');
                    $segmentResolvedCount = 0;
                    $resolvedSegment = $this->replaceTokenExpressions($segment, $pageData, $segmentResolvedCount);
                    if ($segmentHadToken && 0 === $segmentResolvedCount) {
                        continue;
                    }
                    $appliedCount += $segmentResolvedCount;
                    $trimmedSegment = \trim($resolvedSegment);
                    if ('' !== $trimmedSegment) {
                        $keptSegments[] = $trimmedSegment;
                    }
                }

                return \implode($separator, $keptSegments);
            }
        }

        $tokenResolvedCount = 0;
        $hadToken = \str_contains($template, '{{');
        $resolved = $this->replaceTokenExpressions($template, $pageData, $tokenResolvedCount);
        if ($hadToken && 0 === $tokenResolvedCount) {
            return '';
        }
        $appliedCount += $tokenResolvedCount;

        return $resolved;
    }

    /**
     * @param array<string, mixed> $pageData
     */
    private function replaceTokenExpressions(string $text, array $pageData, int &$resolvedCount): string
    {
        return (string) \preg_replace_callback(
            '/\{\{\s*([^}]+?)\s*\}\}/',
            function (array $matches) use ($pageData, &$resolvedCount): string {
                $expr = \trim($matches[1]);
                if ('' === $expr) {
                    return '';
                }
                $extracted = $this->extractValue($pageData, $expr);
                if (\is_array($extracted)) {
                    $scalars = [];
                    foreach ($extracted as $item) {
                        if (\is_scalar($item) && '' !== \trim((string) $item)) {
                            $scalars[] = \trim((string) $item);
                        }
                    }
                    if ([] !== $scalars) {
                        ++$resolvedCount;

                        return \implode(', ', $scalars);
                    }

                    return '';
                }

                if (\is_scalar($extracted)) {
                    $str = \trim((string) $extracted);
                    if ('' !== $str) {
                        ++$resolvedCount;

                        return $str;
                    }
                }

                return '';
            },
            $text,
        );
    }

    /**
     * Builds an automatic target-to-source field mapping using:
     * 1. Equal field name matching against non-empty source data keys.
     * 2. Field type compatibility when block field metadata (`BlockTargetFieldMetadataResolver`) is available.
     *
     * @param array<string, mixed> $block
     * @param array<string, mixed> $pageData
     *
     * @return array<string, string>
     */
    private function buildAutoMapping(array $block, array $pageData): array
    {
        /** @var array<string, string|null> $targetFields */
        $targetFields = [];
        $blockType = isset($block['type']) && \is_string($block['type']) ? $block['type'] : '';

        if (null !== $this->targetFieldResolver && '' !== $blockType) {
            foreach ($this->targetFieldResolver->resolveTargetFields($blockType) as $fieldMeta) {
                $targetFields[$fieldMeta['name']] = $fieldMeta['type'];
            }
        }

        foreach (\array_keys($block) as $key) {
            if (\in_array($key, ['type', 'props', 'id', 'items', 'settings', 'is_dynamic_template'], true) || \str_starts_with($key, '_')) {
                continue;
            }
            if (!\array_key_exists($key, $targetFields)) {
                $targetFields[$key] = null;
            }
        }

        /** @var array<string, string> $declaredSourceTypes */
        $declaredSourceTypes = [];
        if (null !== $this->registry) {
            foreach ($this->registry->getProviders() as $provider) {
                $sourceFields = $provider instanceof ConfigurableDynamicContentSourceProviderInterface
                    ? $provider->resolveSourceFields()
                    : $provider->getSourceFields();
                foreach ($sourceFields as $sourceField) {
                    $declaredSourceTypes[$sourceField['name']] ??= $sourceField['type'];
                }
            }
        }

        /** @var array<string, string> $availableSourceTypes */
        $availableSourceTypes = [];
        foreach ($pageData as $sourceKey => $sourceValue) {
            if (\str_starts_with($sourceKey, '_') || \in_array($sourceKey, ['id', 'uuid', 'template'], true)) {
                continue;
            }
            if (!$this->isValuePresent($sourceValue)) {
                continue;
            }
            $availableSourceTypes[$sourceKey] = $this->inferSourceValueType(
                $sourceValue,
                $declaredSourceTypes[$sourceKey] ?? null,
            );
        }

        /** @var array<string, string> $mapping */
        $mapping = [];
        /** @var array<string, true> $usedSources */
        $usedSources = [];

        // 1. Equal field name match
        foreach ($targetFields as $targetField => $targetType) {
            if (\array_key_exists($targetField, $pageData) && $this->isValuePresent($pageData[$targetField])) {
                $mapping[$targetField] = $targetField;
                $usedSources[$targetField] = true;
            }
        }

        // 2. Field type compatibility match via resolved block field metadata
        foreach ($targetFields as $targetField => $targetType) {
            if (isset($mapping[$targetField]) || null === $targetType) {
                continue;
            }

            $matchedSource = null;
            foreach ($availableSourceTypes as $sourceKey => $sourceType) {
                if ($sourceType === $targetType && !isset($usedSources[$sourceKey])) {
                    $matchedSource = $sourceKey;
                    break;
                }
            }

            if (null === $matchedSource) {
                foreach ($availableSourceTypes as $sourceKey => $sourceType) {
                    if ($sourceType === $targetType) {
                        $matchedSource = $sourceKey;
                        break;
                    }
                }
            }

            if (null !== $matchedSource) {
                $mapping[$targetField] = $matchedSource;
                $usedSources[$matchedSource] = true;
            }
        }

        return $mapping;
    }

    private function inferSourceValueType(mixed $value, ?string $declaredType): string
    {
        if (\is_array($value)) {
            if (isset($value['id']) || isset($value['url']) || isset($value['thumbnails']) || isset($value['ids'])) {
                return 'media';
            }

            return 'collection';
        }

        if (\is_bool($value)) {
            return 'boolean';
        }

        if (\is_int($value) || \is_float($value)) {
            return 'number';
        }

        if (null !== $declaredType && '' !== $declaredType) {
            return BlockTargetFieldMetadataResolver::normalizeFieldType($declaredType);
        }

        if (\is_string($value)) {
            if (\str_starts_with($value, '/') || \str_starts_with($value, 'http://') || \str_starts_with($value, 'https://')) {
                return 'link';
            }

            if ($value !== \strip_tags($value) || \mb_strlen($value, 'UTF-8') > 120) {
                return 'text';
            }
        }

        return 'string';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractSinglePathValue(array $data, string $path): mixed
    {
        $segments = \explode('.', $path);
        $current = $data;

        foreach ($segments as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    private function evaluateContains(mixed $val, string $strVal, string $condVal): bool
    {
        if ('' === $condVal) {
            return false;
        }

        if (\is_array($val)) {
            foreach ($val as $item) {
                if (\is_scalar($item) && \strtolower((string) $item) === \strtolower($condVal)) {
                    return true;
                }
            }

            return false;
        }

        return \str_contains(\strtolower($strVal), \strtolower($condVal));
    }

    private function isValuePresent(mixed $value): bool
    {
        if (null === $value) {
            return false;
        }
        if (\is_string($value) && '' === \trim($value)) {
            return false;
        }
        if (\is_array($value) && [] === $value) {
            return false;
        }

        return true;
    }
}
