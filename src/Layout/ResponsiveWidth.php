<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Layout;

/**
 * Immutable description of the rendered width of a layout slot at every responsive breakpoint.
 *
 * A slot is expressed as `fraction(bp) × min(viewport, container)`, optionally capped by a fixed
 * pixel width (block `max_width`). Nested layouts (Section → Row → Column → Grid → Card media)
 * compose by multiplying fractions per breakpoint, so the HTML `sizes` attribute and every
 * derived pixel width come from the same data that produces the responsive layout classes.
 *
 * Widths are upper bounds of each breakpoint range: inner paddings and gaps are not subtracted,
 * which guarantees the browser never selects a source smaller than the rendered slot.
 */
class ResponsiveWidth
{
    /**
     * Responsive breakpoints (min-width, px), ordered mobile-first.
     *
     * @var array<string, int>
     */
    public const BREAKPOINTS = [
        'base' => 0,
        'sm' => 640,
        'md' => 768,
        'lg' => 1024,
        'xl' => 1280,
        '2xl' => 1536,
    ];

    /**
     * @param int|null $containerPx maximum container width in px; `null` means the container follows the viewport
     * @param array<string, float> $fractions fraction of the container per breakpoint (all breakpoint keys present)
     */
    final protected function __construct(
        private readonly ?int $containerPx,
        private readonly array $fractions,
        private readonly ?int $capPx,
    ) {
    }

    /**
     * A slot filling a container that is at most `$containerPx` wide (`null` = full viewport).
     */
    public static function container(?int $containerPx): static
    {
        return new static(
            null !== $containerPx ? \max(1, $containerPx) : null,
            \array_fill_keys(\array_keys(self::BREAKPOINTS), 1.0),
            null,
        );
    }

    /**
     * Restores a width previously exported with `toArray()`; returns `null` for malformed input.
     */
    public static function fromArray(mixed $data): ?static
    {
        if (!\is_array($data) || !\array_key_exists('container', $data) || !isset($data['fractions']) || !\is_array($data['fractions'])) {
            return null;
        }

        $container = $data['container'];
        if (null !== $container && !\is_int($container)) {
            return null;
        }

        $fractions = [];
        foreach (\array_keys(self::BREAKPOINTS) as $bp) {
            $value = $data['fractions'][$bp] ?? null;
            if (!\is_int($value) && !\is_float($value)) {
                return null;
            }
            $fractions[$bp] = \max(0.0, (float) $value);
        }

        $cap = $data['cap'] ?? null;
        if (null !== $cap && !\is_int($cap)) {
            return null;
        }

        return new static($container, $fractions, $cap);
    }

    /**
     * @return array{container: int|null, fractions: array<string, float>, cap: int|null}
     */
    public function toArray(): array
    {
        return [
            'container' => $this->containerPx,
            'fractions' => $this->fractions,
            'cap' => $this->capPx,
        ];
    }

    /**
     * Multiplies the slot by a mobile-first fraction map; breakpoints that are not listed inherit
     * the value of the nearest smaller breakpoint (`base` defaults to `1.0`).
     *
     * @param array<string, float|int> $fractionsByBreakpoint
     */
    public function scale(array $fractionsByBreakpoint): static
    {
        $cascaded = self::cascade($fractionsByBreakpoint, 1.0);
        $fractions = [];
        foreach ($this->fractions as $bp => $fraction) {
            $fractions[$bp] = $fraction * \max(0.0, (float) $cascaded[$bp]);
        }

        return new static($this->containerPx, $fractions, $this->capPx);
    }

    /**
     * Splits the slot into equal tracks per breakpoint (e.g. grid columns, slides per view).
     *
     * @param array<string, int> $countsByBreakpoint
     */
    public function divide(array $countsByBreakpoint): static
    {
        $fractions = [];
        foreach (self::cascade($countsByBreakpoint, 1) as $bp => $count) {
            $fractions[$bp] = 1.0 / \max(1, (int) $count);
        }

        return $this->scale($fractions);
    }

    /**
     * Caps the slot to a fixed pixel width (e.g. block-level `max_width`); `null` leaves it unchanged.
     */
    public function cap(?int $px): static
    {
        if (null === $px || $px <= 0) {
            return $this;
        }

        return new static($this->containerPx, $this->fractions, null !== $this->capPx ? \min($this->capPx, $px) : $px);
    }

    /**
     * Upper-bound pixel width of the slot within the given breakpoint range.
     */
    public function slotPx(string $breakpoint): int
    {
        $upper = $this->rangeUpperBoundPx($breakpoint);
        $slot = (int) \ceil($upper * ($this->fractions[$breakpoint] ?? 1.0));

        return null !== $this->capPx ? \min($slot, $this->capPx) : $slot;
    }

    /**
     * Largest pixel width the slot can reach on any viewport.
     */
    public function maxSlotPx(): int
    {
        $max = 0;
        foreach (\array_keys(self::BREAKPOINTS) as $bp) {
            $max = \max($max, $this->slotPx($bp));
        }

        return $max;
    }

    /**
     * Builds the HTML `sizes` attribute (mobile-first values, emitted largest breakpoint first).
     *
     * Within each breakpoint range the slot is `fraction × min(viewport, container)`, capped by
     * `max_width`; the switch from a viewport-relative value (`vw`) to a fixed pixel value is placed
     * exactly where the container or the cap is reached.
     */
    public function toSizes(): string
    {
        /** @var list<array{int, string}> $entries */
        $entries = [];
        $previous = null;

        foreach (self::BREAKPOINTS as $bp => $minWidth) {
            foreach ($this->rangeEntries($bp, $minWidth) as [$from, $value]) {
                if ($value !== $previous) {
                    $entries[] = [$from, $value];
                    $previous = $value;
                }
            }
        }

        $parts = [];
        foreach (\array_reverse($entries) as [$minWidth, $value]) {
            $parts[] = 0 === $minWidth ? $value : \sprintf('(min-width: %dpx) %s', $minWidth, $value);
        }

        return \implode(', ', $parts);
    }

    /**
     * Builds a `sizes` attribute for elements with fixed pixel widths per breakpoint
     * (e.g. dropdown cards, modal dialogs) that do not depend on the page layout.
     *
     * @param array<string, int> $pxByBreakpoint
     */
    public static function fixedSizes(array $pxByBreakpoint): string
    {
        $cascaded = self::cascade($pxByBreakpoint, 0);
        $entries = [];
        $previous = null;

        foreach (self::BREAKPOINTS as $bp => $minWidth) {
            $px = (int) $cascaded[$bp];
            $value = $px > 0 ? $px . 'px' : '100vw';
            if ($value !== $previous) {
                $entries[] = [$minWidth, $value];
                $previous = $value;
            }
        }

        $parts = [];
        foreach (\array_reverse($entries) as [$minWidth, $value]) {
            $parts[] = 0 === $minWidth ? $value : \sprintf('(min-width: %dpx) %s', $minWidth, $value);
        }

        return \implode(', ', $parts);
    }

    /**
     * Expands a sparse mobile-first map to every breakpoint.
     *
     * @param array<string, int|float> $map
     *
     * @return array<string, int|float>
     */
    public static function cascade(array $map, int|float $baseDefault): array
    {
        $result = [];
        $current = $map['base'] ?? $baseDefault;

        foreach (\array_keys(self::BREAKPOINTS) as $bp) {
            if (\array_key_exists($bp, $map)) {
                $current = $map[$bp];
            }
            $result[$bp] = $current;
        }

        return $result;
    }

    /**
     * `sizes` entries for one breakpoint range: viewport-relative until the slot stops growing
     * (container or cap reached), fixed pixels afterwards.
     *
     * @return list<array{int, string}>
     */
    private function rangeEntries(string $breakpoint, int $rangeStart): array
    {
        $fraction = $this->fractions[$breakpoint] ?? 1.0;
        $next = $this->nextBreakpointPx($breakpoint);

        $limits = [];
        if (null !== $this->containerPx) {
            $limits[] = (float) $this->containerPx;
        }
        if (null !== $this->capPx && $fraction > 0.0) {
            $limits[] = $this->capPx / $fraction;
        }
        $crossover = [] !== $limits ? \min($limits) : null;

        $viewportValue = self::formatNumber($fraction * 100) . 'vw';
        if (null === $crossover || (null !== $next && $crossover >= $next)) {
            return [[$rangeStart, $viewportValue]];
        }

        $fixedPx = (int) \ceil($fraction * $crossover);
        if (null !== $this->capPx) {
            $fixedPx = \min($fixedPx, $this->capPx);
        }
        $fixedValue = $fixedPx . 'px';

        if ($crossover <= $rangeStart) {
            return [[$rangeStart, $fixedValue]];
        }

        return [[$rangeStart, $viewportValue], [(int) \ceil($crossover), $fixedValue]];
    }

    private function rangeUpperBoundPx(string $breakpoint): int
    {
        $next = $this->nextBreakpointPx($breakpoint);

        if (null === $this->containerPx) {
            return $next ?? self::BREAKPOINTS['2xl'];
        }

        return null !== $next ? \min($next, $this->containerPx) : $this->containerPx;
    }

    private function nextBreakpointPx(string $breakpoint): ?int
    {
        $found = false;
        foreach (self::BREAKPOINTS as $bp => $px) {
            if ($found) {
                return $px;
            }
            if ($bp === $breakpoint) {
                $found = true;
            }
        }

        return null;
    }

    private static function formatNumber(float $value): string
    {
        $rounded = \round($value, 2);

        return \rtrim(\rtrim(\number_format($rounded, 2, '.', ''), '0'), '.');
    }
}
