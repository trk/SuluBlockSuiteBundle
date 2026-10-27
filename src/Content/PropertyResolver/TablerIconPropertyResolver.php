<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Content\PropertyResolver;

use Sulu\Content\Application\ContentResolver\Value\ContentView;
use Sulu\Content\Application\PropertyResolver\Resolver\PropertyResolverInterface;

/**
 * Resolves `single_icon_selection` properties for the `tabler` icon set in O(1)
 * while normalizing both bare Tabler icon names (`shield-check`) and
 * webfont-prefixed names (`ti ti-shield-check`, `ti-shield-check`, `tabler-shield-check`).
 */
final class TablerIconPropertyResolver implements PropertyResolverInterface
{
    /**
     * Legacy/preset icon aliases mapped to canonical Tabler icon filenames.
     *
     * @var array<string, string>
     */
    public const ICON_ALIASES = [
        'star-filled' => 'star',
        'discount-2' => 'discount',
        'player-play-filled' => 'player-play',
        'megaphone' => 'speakerphone',
        'discount-check' => 'rosette-discount-check',
        'brand-mailchimp' => 'mail-forward',
    ];

    private readonly ?string $tablerIconsDir;

    public function __construct(
        private readonly ?PropertyResolverInterface $inner = null,
        ?string $projectDir = null,
    ) {
        $dir = null !== $projectDir && '' !== $projectDir
            ? \rtrim($projectDir, '/') . '/public/icons/tabler'
            : null;
        $this->tablerIconsDir = null !== $dir && \is_dir($dir) ? $dir : null;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function resolve(mixed $data, string $locale, array $params = []): ContentView
    {
        $raw = self::extractRawIconName($data);
        if (null === $raw) {
            return ContentView::create(null, [...$params]);
        }

        $iconSet = \is_string($params['icon_set'] ?? null) && '' !== $params['icon_set']
            ? $params['icon_set']
            : 'tabler';

        if ('tabler' !== $iconSet) {
            if (null !== $this->inner) {
                return $this->inner->resolve($raw, $locale, $params);
            }

            return ContentView::create(['name' => $raw, 'icon_set' => $iconSet], [...$params]);
        }

        $normalized = self::normalizeTablerIconName($raw);
        if (null === $normalized) {
            return ContentView::create(null, [...$params]);
        }

        if (null !== $this->tablerIconsDir && !\is_file($this->tablerIconsDir . '/' . $normalized . '.svg')) {
            return ContentView::create(null, [...$params]);
        }

        return ContentView::create([
            'name' => $normalized,
            'icon_set' => 'tabler',
        ], [...$params]);
    }

    public static function getType(): string
    {
        return 'single_icon_selection';
    }

    public static function normalizeTablerIconName(string $raw): ?string
    {
        $clean = \strtolower(\trim($raw));
        if ('' === $clean) {
            return null;
        }

        if (\str_starts_with($clean, 'ti ti-')) {
            $clean = \substr($clean, 6);
        } elseif (\str_starts_with($clean, 'ti-')) {
            $clean = \substr($clean, 3);
        } elseif (\str_starts_with($clean, 'tabler-')) {
            $clean = \substr($clean, 7);
        }

        $clean = \trim($clean);
        if (isset(self::ICON_ALIASES[$clean])) {
            $clean = self::ICON_ALIASES[$clean];
        }

        if (1 !== \preg_match('/^[a-z0-9-]+$/', $clean)) {
            return null;
        }

        return $clean;
    }

    private static function extractRawIconName(mixed $data): ?string
    {
        if (\is_string($data)) {
            $trimmed = \trim($data);

            return '' !== $trimmed ? $trimmed : null;
        }

        if (\is_array($data)) {
            $candidate = $data['name'] ?? $data['icon'] ?? $data['id'] ?? null;
            if (\is_string($candidate)) {
                $trimmed = \trim($candidate);

                return '' !== $trimmed ? $trimmed : null;
            }
        }

        return null;
    }
}
