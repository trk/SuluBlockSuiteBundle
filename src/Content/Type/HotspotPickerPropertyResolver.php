<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Content\Type;

use Sulu\Content\Application\ContentResolver\Value\ContentView;
use Sulu\Content\Application\PropertyResolver\Resolver\PropertyResolverInterface;

/**
 * Resolves hotspot picker JSON / array data into normalized coordinates and marker configuration for Twig rendering.
 */
final class HotspotPickerPropertyResolver implements PropertyResolverInterface
{
    /**
     * @param array<string, mixed> $params
     */
    public function resolve(mixed $data, string $locale, array $params = []): ContentView
    {
        if (\is_string($data) && '' !== \trim($data)) {
            try {
                /** @var mixed $decoded */
                $decoded = \json_decode($data, true, 512, \JSON_THROW_ON_ERROR);
                if (\is_array($decoded)) {
                    $data = $decoded;
                }
            } catch (\JsonException) {
                // Ignore invalid JSON string
            }
        }

        /** @var array<string, mixed> $val */
        $val = \is_array($data) ? $data : [];

        $x = isset($val['x']) && \is_numeric($val['x']) ? (float) $val['x'] : 50.0;
        $y = isset($val['y']) && \is_numeric($val['y']) ? (float) $val['y'] : 50.0;

        $x = \round(\max(0.0, \min(100.0, $x)), 2);
        $y = \round(\max(0.0, \min(100.0, $y)), 2);

        $markerType = $this->resolveOption($val, 'marker_type', $params, 'types');
        $markerColor = $this->resolveOption($val, 'marker_color', $params, 'colors');

        $markerIcon = isset($val['marker_icon']) && \is_string($val['marker_icon']) && '' !== \trim($val['marker_icon'])
            ? \trim($val['marker_icon'])
            : null;

        $trigger = $this->resolveOption($val, 'trigger', $params, 'triggers');

        $normalized = [
            'x' => $x,
            'y' => $y,
            'marker_type' => $markerType,
            'marker_color' => $markerColor,
            'marker_icon' => $markerIcon,
            'trigger' => $trigger,
        ];

        return ContentView::create($normalized, ['value' => $normalized]);
    }

    public static function getType(): string
    {
        return 'hotspot_picker';
    }

    /**
     * @param array<string, mixed> $val
     * @param array<string, mixed> $params
     */
    private function resolveOption(array $val, string $valKey, array $params, string $paramKey): ?string
    {
        $allowed = $this->extractAllowedOptions($params[$paramKey] ?? null);
        $raw = $val[$valKey] ?? null;

        if (\is_string($raw) && '' !== \trim($raw)) {
            $trimmed = \trim($raw);
            if ([] === $allowed || \in_array($trimmed, $allowed, true)) {
                return $trimmed;
            }
        }

        return $allowed[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private function extractAllowedOptions(mixed $rawParam): array
    {
        if (\is_string($rawParam) && '' !== \trim($rawParam)) {
            $parts = \array_map('trim', \explode(',', $rawParam));

            return \array_values(\array_filter($parts, static fn (string $part): bool => '' !== $part));
        }

        if (!\is_array($rawParam)) {
            return [];
        }

        $result = [];
        foreach ($rawParam as $key => $item) {
            if (\is_string($item) && '' !== \trim($item)) {
                $result[] = \trim($item);
            } elseif (\is_string($key) && '' !== \trim($key)) {
                $result[] = \trim($key);
            }
        }

        return $result;
    }
}
