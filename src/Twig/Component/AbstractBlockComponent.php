<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Twig\Component;

use Symfony\UX\TwigComponent\Attribute\PreMount;
use Totoglu\SuluBlockSuiteBundle\Normalizer\SuluDataNormalizer;
use Totoglu\SuluBlockSuiteBundle\Util\ClassList;
use Totoglu\SuluBlockSuiteBundle\Util\PropReader;

/**
 * Base class for Sulu Block Suite Twig Components.
 *
 * Provides per-mount state isolation, strict separation of content (`data`)
 * and visual/behavioral configuration (`props`), default prop merging,
 * rich-text/media-aware emptiness checks, capability queries (`is(...)`),
 * and the `shouldRender()` lifecycle contract.
 */
abstract class AbstractBlockComponent
{
    /**
     * Universal content payload keys recognized automatically when a flat block or input array is mounted.
     *
     * @var list<string>
     */
    private const UNIVERSAL_DATA_KEYS = [
        'title',
        'subtitle',
        'content',
        'summary',
        'description',
        'text',
        'image',
        'video',
        'icon',
        'link',
        'url',
        'items',
        'blocks',
        'form',
        'form_view',
        'form_entity',
        'form_submitted',
    ];

    /**
     * Metadata keys from Sulu block arrays that are neither content data nor visual props.
     *
     * @var list<string>
     */
    private const IGNORED_BLOCK_META_KEYS = [
        'type',
        'settings',
        '_dynamic_hydrated',
        '_props',
    ];

    /**
     * Key prefix used by form metadata for flattened, namespaced prop fields (e.g. `props/size`).
     */
    private const PROPS_NAMESPACE = 'props/';

    /**
     * @var array<string, mixed>
     */
    protected array $data = [];

    /**
     * @var array<string, mixed>
     */
    protected array $props = [];

    /**
     * @var array<string, mixed>
     */
    protected array $context = [];

    /**
     * @param array<string, mixed> $input
     */
    public function __construct(array $input = [])
    {
        $this->resetState();

        if ([] !== $input) {
            $this->initializeBlockComponent($input);
        }
    }

    /**
     * Symfony UX TwigComponent PreMount hook.
     *
     * Resets state per render, normalizes block/data/props payloads, and returns
     * only valid HTML root attributes so `ComponentAttributes` is never polluted
     * with non-stringable arrays.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    #[PreMount]
    final public function initializeBlockComponent(array $input): array
    {
        $this->resetState();

        $htmlAttributes = [];

        if (isset($input['context']) && \is_array($input['context'])) {
            /** @var array<string, mixed> $ctx */
            $ctx = $input['context'];
            $this->context = $ctx;
            unset($input['context']);
        }

        if (isset($input['block']) && \is_array($input['block'])) {
            /** @var array<string, mixed> $block */
            $block = $input['block'];
            $this->ingestBlockArray($block);
            unset($input['block']);
        }

        if (isset($input['data']) && \is_array($input['data'])) {
            /** @var array<string, mixed> $data */
            $data = $input['data'];
            foreach ($data as $k => $v) {
                $this->data[self::toSnakeCase($k)] = $v;
            }
            unset($input['data']);
        }

        if (isset($input['props']) && \is_array($input['props'])) {
            /** @var array<string, mixed> $props */
            $props = $input['props'];
            $this->ingestPropsArray($props);
            unset($input['props']);
        }

        $knownDataKeys = $this->getKnownDataKeyMap();

        foreach ($input as $rawKey => $value) {
            if ($this->isPassThroughHtmlAttribute($rawKey, $value)) {
                $htmlAttributes[$rawKey] = $value;
                continue;
            }

            $key = self::toSnakeCase($rawKey);
            if (\in_array($key, self::IGNORED_BLOCK_META_KEYS, true)) {
                continue;
            }

            if (isset($knownDataKeys[$key])) {
                $this->data[$key] = $value;
            } else {
                $this->props[$key] = $value;
            }
        }

        return $htmlAttributes;
    }

    /**
     * Programmatic (re)initialization for direct PHP usage and unit tests.
     *
     * Intentionally not named `mount()`: Symfony UX `ComponentFactory` would otherwise
     * invoke it reflectively after `#[PreMount]`. Runtime rendering relies solely on
     * `initializeBlockComponent()`.
     *
     * @param array<string, mixed> $block
     * @param array<string, mixed> $data
     * @param array<string, mixed> $props
     * @param array<string, mixed> $context
     *
     * @phpstan-impure
     */
    public function load(
        array $block = [],
        array $data = [],
        array $props = [],
        array $context = [],
    ): static {
        $this->initializeBlockComponent([
            'block' => $block,
            'data' => $data,
            'props' => $props,
            'context' => $context,
        ]);

        return $this;
    }

    /**
     * Default visual and behavioral props for the component.
     *
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [];
    }

    /**
     * Additional component-specific content (`data`) keys beyond `UNIVERSAL_DATA_KEYS`.
     *
     * @return list<string>
     */
    protected function dataKeys(): array
    {
        return [];
    }

    /**
     * Component-specific capability definitions for `$this->is($capability)`.
     *
     * Each entry may be a boolean or a `callable(): bool` closure.
     *
     * @return array<string, bool|\Closure(): bool>
     */
    protected function capabilities(): array
    {
        return [];
    }

    /**
     * Determines whether the component has sufficient content/configuration to render.
     *
     * When `false`, `BlockComponentPreRenderSubscriber` short-circuits rendering
     * to `@SuluBlockSuite/components/_empty.html.twig`.
     */
    public function shouldRender(): bool
    {
        return true;
    }

    // =========================================================================
    // Content (`data`) Accessors
    // =========================================================================

    public function get(string $key, mixed $default = null): mixed
    {
        $normalized = self::toSnakeCase($key);

        if (\array_key_exists($normalized, $this->data) && null !== $this->data[$normalized]) {
            return $this->data[$normalized];
        }

        if (\array_key_exists($normalized, $this->props) && null !== $this->props[$normalized]) {
            return $this->props[$normalized];
        }

        return $default;
    }

    /**
     * Stores a value in `data` regardless of whether the key is a known content key.
     *
     * @phpstan-impure
     */
    public function set(string $key, mixed $value): static
    {
        $this->data[self::toSnakeCase($key)] = $value;

        return $this;
    }

    public function string(string $key, string $default = '', bool $trim = true, ?string $fn = null): string
    {
        $value = $this->get($key);
        $resolved = PropReader::toString($value);
        if (null === $resolved) {
            return $default;
        }

        if ($trim) {
            $resolved = \trim($resolved);
        }

        if (null !== $fn) {
            $resolved = $this->applyStringFunction($resolved, $fn);
        }

        return '' !== $resolved ? $resolved : $default;
    }

    public function nullableString(string $key, ?string $fn = null): ?string
    {
        $value = $this->string($key, '', true, $fn);

        return '' !== $value ? $value : null;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        if (\is_int($value)) {
            return $value;
        }
        if (\is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        return PropReader::toBool($value, $default);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function array(string $key): array
    {
        $value = $this->get($key);

        return \is_array($value) ? $value : [];
    }

    /**
     * Returns normalized list of associative item arrays from a collection key.
     *
     * @return list<array<string, mixed>>
     */
    public function items(string $key = 'items'): array
    {
        $raw = $this->array($key);
        $list = [];

        foreach ($raw as $item) {
            if (\is_array($item) && $this->isNonEmptyItemArray($item)) {
                /** @var array<string, mixed> $typedItem */
                $typedItem = $item;
                $list[] = $typedItem;
            }
        }

        return $list;
    }

    /**
     * Checks whether a data (or fallback prop) key has a non-empty value.
     */
    public function has(string $key): bool
    {
        $normalized = self::toSnakeCase($key);
        $value = $this->get($normalized);

        if (\in_array($normalized, ['content', 'summary', 'description', 'text', 'quote'], true) && \is_string($value)) {
            return $this->isNonEmptyRichText($value);
        }

        return $this->isNonEmptyValue($value);
    }

    public function hasAny(string ...$keys): bool
    {
        foreach ($keys as $key) {
            if ($this->has($key)) {
                return true;
            }
        }

        return false;
    }

    public function hasAll(string ...$keys): bool
    {
        if ([] === $keys) {
            return false;
        }

        foreach ($keys as $key) {
            if (!$this->has($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rich-text emptiness check that strips empty CKEditor tags (`<p><br></p>`, `&nbsp;`)
     * while preserving embedded media/elements (`<img>`, `<video>`, `<iframe>`, `<svg>`, `<hr>`).
     */
    public function hasRichText(string $key = 'content'): bool
    {
        $value = $this->get($key);
        if (!\is_string($value)) {
            return false;
        }

        return $this->isNonEmptyRichText($value);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    // =========================================================================
    // Configuration (`props`) Accessors
    // =========================================================================

    public function prop(string $key, mixed $default = null): mixed
    {
        $normalized = self::toSnakeCase($key);

        if (\array_key_exists($normalized, $this->props) && null !== $this->props[$normalized]) {
            return $this->props[$normalized];
        }

        if (\array_key_exists($normalized, $this->data) && null !== $this->data[$normalized]) {
            return $this->data[$normalized];
        }

        return $default;
    }

    /**
     * @phpstan-impure
     */
    public function setProp(string $key, mixed $value): static
    {
        $this->props[self::toSnakeCase($key)] = $value;

        return $this;
    }

    /**
     * Merges a (possibly nested or `props/`-namespaced) props array into the current props.
     *
     * @param array<string, mixed> $props
     *
     * @phpstan-impure
     */
    public function mergeProps(array $props): static
    {
        $this->ingestPropsArray($props);

        return $this;
    }

    /**
     * Stores a value in `data` when the key is a known content key, otherwise in `props`.
     *
     * @phpstan-impure
     */
    public function assign(string $key, mixed $value): static
    {
        $normalized = self::toSnakeCase($key);

        if (isset($this->getKnownDataKeyMap()[$normalized])) {
            $this->data[$normalized] = $value;
        } else {
            $this->props[$normalized] = $value;
        }

        return $this;
    }

    public function stringProp(string $key, string $default = '', bool $trim = true, ?string $fn = null): string
    {
        $value = $this->prop($key);
        $resolved = PropReader::toString($value);
        if (null === $resolved) {
            return $default;
        }

        if ($trim) {
            $resolved = \trim($resolved);
        }

        if (null !== $fn) {
            $resolved = $this->applyStringFunction($resolved, $fn);
        }

        return '' !== $resolved ? $resolved : $default;
    }

    public function nullableStringProp(string $key, ?string $fn = null): ?string
    {
        $value = $this->stringProp($key, '', true, $fn);

        return '' !== $value ? $value : null;
    }

    public function boolProp(string $key, bool $default = false): bool
    {
        $normalized = self::toSnakeCase($key);

        if (\array_key_exists($normalized, $this->props) && null !== $this->props[$normalized]) {
            return PropReader::toBool($this->props[$normalized], $default);
        }

        if (\array_key_exists($normalized, $this->data) && null !== $this->data[$normalized]) {
            return PropReader::toBool($this->data[$normalized], $default);
        }

        return $default;
    }

    public function intProp(string $key, int $default = 0): int
    {
        $value = $this->prop($key);
        if (\is_int($value)) {
            return $value;
        }
        if (\is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    public function floatProp(string $key, float $default = 0.0): float
    {
        $value = $this->prop($key);
        if (\is_float($value) || \is_int($value)) {
            return (float) $value;
        }
        if (\is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }

    /**
     * @param list<string> $allowed
     */
    public function enumProp(string $key, array $allowed, string $default): string
    {
        $value = $this->stringProp($key, $default);

        return \in_array($value, $allowed, true) ? $value : $default;
    }

    // =========================================================================
    // String, Value, Class & Collection Item Helpers (`$this->{fn}`)
    // =========================================================================

    public function trim(mixed $value, string $default = '', string $characters = " \n\r\t\v\x00"): string
    {
        $str = PropReader::toString($value);
        if (null === $str) {
            return $default;
        }

        $trimmed = \trim($str, $characters);

        return '' !== $trimmed ? $trimmed : $default;
    }

    public function ltrim(mixed $value, string $characters = " \n\r\t\v\x00", string $default = ''): string
    {
        $str = PropReader::toString($value);
        if (null === $str) {
            return $default;
        }

        $trimmed = \ltrim($str, $characters);

        return '' !== $trimmed ? $trimmed : $default;
    }

    public function rtrim(mixed $value, string $characters = " \n\r\t\v\x00", string $default = ''): string
    {
        $str = PropReader::toString($value);
        if (null === $str) {
            return $default;
        }

        $trimmed = \rtrim($str, $characters);

        return '' !== $trimmed ? $trimmed : $default;
    }

    public function lower(mixed $value, string $default = ''): string
    {
        $trimmed = $this->trim($value);

        return '' !== $trimmed ? \strtolower($trimmed) : $default;
    }

    public function upper(mixed $value, string $default = ''): string
    {
        $trimmed = $this->trim($value);

        return '' !== $trimmed ? \strtoupper($trimmed) : $default;
    }

    public function ucfirst(mixed $value, string $default = ''): string
    {
        $trimmed = $this->trim($value);

        return '' !== $trimmed ? \ucfirst($trimmed) : $default;
    }

    public function slug(mixed $value, string $separator = '-'): string
    {
        $trimmed = $this->trim($value);
        if ('' === $trimmed) {
            return '';
        }

        return \strtolower((string) \preg_replace('/[^a-zA-Z0-9_-]/', $separator, $trimmed));
    }

    /**
     * Splits a string (or normalizes a list of strings/objects) into trimmed non-empty strings.
     *
     * @param non-empty-string $delimiter
     *
     * @return list<string>
     */
    public function split(
        mixed $value,
        string $delimiter = ',',
        bool $trimItems = true,
        bool $filterEmpty = true,
    ): array {
        if (\is_string($value)) {
            $rawParts = '' !== $value ? \explode($delimiter, $value) : [];
        } elseif (\is_array($value)) {
            $rawParts = $value;
        } else {
            return [];
        }

        $result = [];
        foreach ($rawParts as $part) {
            if (\is_array($part) && isset($part['name']) && \is_string($part['name'])) {
                $str = $part['name'];
            } else {
                $str = PropReader::toString($part);
            }

            if (null === $str) {
                continue;
            }

            if ($trimItems) {
                $str = \trim($str);
            }

            if ($filterEmpty && '' === $str) {
                continue;
            }

            $result[] = $str;
        }

        return $result;
    }

    /**
     * Joins an iterable of items into a single string, optionally filtering out empty values.
     *
     * @param iterable<mixed> $items
     */
    public function join(iterable $items, string $glue = ' ', bool $filterEmpty = true): string
    {
        $parts = [];
        foreach ($items as $item) {
            $str = PropReader::toString($item);
            if (null === $str) {
                continue;
            }

            $trimmed = \trim($str);
            if ($filterEmpty && '' === $trimmed) {
                continue;
            }

            $parts[] = $trimmed;
        }

        return \implode($glue, $parts);
    }

    /**
     * Joins any combination of CSS class strings, arrays of class strings, or falsy values
     * into a single whitespace-normalized class string.
     *
     * @param string|array<int|string, mixed>|false|null ...$parts
     */
    public function classes(string|array|false|null ...$parts): string
    {
        return ClassList::join(...$parts);
    }

    /**
     * Checks whether `$value` strictly matches one of `$candidates`.
     *
     * @param list<mixed> $candidates
     */
    public function oneOf(mixed $value, array $candidates): bool
    {
        return \in_array($value, $candidates, true);
    }

    /**
     * Validates a string against an allowed list (optionally ignoring `'inherit'`), returning `$default` otherwise.
     *
     * @param list<string> $allowed
     */
    public function matchEnum(mixed $value, array $allowed, string $default, bool $ignoreInherit = true): string
    {
        $trimmed = $this->trim($value);
        if ('' === $trimmed || ($ignoreInherit && 'inherit' === $trimmed)) {
            return $default;
        }

        return \in_array($trimmed, $allowed, true) ? $trimmed : $default;
    }

    public function clamp(int|float $value, int|float $min, int|float $max): int|float
    {
        return \max($min, \min($max, $value));
    }

    public function isNotEmpty(mixed $value): bool
    {
        return $this->isNonEmptyValue($value);
    }

    /**
     * Reads a key from a collection item array (falling back to camelCase/snake_case and nested `props`).
     *
     * @param array<int|string, mixed> $item
     */
    public function itemValue(array $item, string $key, mixed $default = null): mixed
    {
        if (\array_key_exists($key, $item) && null !== $item[$key]) {
            return $item[$key];
        }

        $snake = self::toSnakeCase($key);
        if ($snake !== $key && \array_key_exists($snake, $item) && null !== $item[$snake]) {
            return $item[$snake];
        }

        if (isset($item['props']) && \is_array($item['props'])) {
            if (\array_key_exists($key, $item['props']) && null !== $item['props'][$key]) {
                return $item['props'][$key];
            }
            if ($snake !== $key && \array_key_exists($snake, $item['props']) && null !== $item['props'][$snake]) {
                return $item['props'][$snake];
            }
        }

        return $default;
    }

    /**
     * @param array<int|string, mixed> $item
     */
    public function itemString(
        array $item,
        string $key,
        string $default = '',
        bool $trim = true,
        ?string $fn = null,
    ): string {
        $raw = $this->itemValue($item, $key);
        $resolved = PropReader::toString($raw);
        if (null === $resolved) {
            return $default;
        }

        if ($trim) {
            $resolved = \trim($resolved);
        }

        if (null !== $fn) {
            $resolved = $this->applyStringFunction($resolved, $fn);
        }

        return '' !== $resolved ? $resolved : $default;
    }

    /**
     * @param array<int|string, mixed> $item
     */
    public function itemNullableString(array $item, string $key, ?string $fn = null): ?string
    {
        $val = $this->itemString($item, $key, '', true, $fn);

        return '' !== $val ? $val : null;
    }

    /**
     * Reads an item string prop/field, ignoring `'inherit'` or empty strings and falling back to `$default`.
     *
     * @param array<int|string, mixed> $item
     */
    public function itemInheritedString(
        array $item,
        string $key,
        string $default = '',
        ?string $fallbackKey = null,
    ): string {
        $val = $this->itemString($item, $key);
        if ('' !== $val && 'inherit' !== $val) {
            return $val;
        }

        if (null !== $fallbackKey) {
            $fallbackVal = $this->itemString($item, $fallbackKey);
            if ('' !== $fallbackVal && 'inherit' !== $fallbackVal) {
                return $fallbackVal;
            }
        }

        return $default;
    }

    /**
     * @param array<int|string, mixed> $item
     * @param list<string> $allowed
     */
    public function itemEnum(
        array $item,
        string $key,
        array $allowed,
        string $default,
        bool $ignoreInherit = true,
    ): string {
        return $this->matchEnum($this->itemValue($item, $key), $allowed, $default, $ignoreInherit);
    }

    /**
     * @param array<int|string, mixed> $item
     */
    public function itemBool(array $item, string $key, bool $default = false): bool
    {
        $raw = $this->itemValue($item, $key);

        return null !== $raw ? PropReader::toBool($raw, $default) : $default;
    }

    /**
     * @param array<int|string, mixed> $item
     */
    public function itemInt(array $item, string $key, int $default = 0): int
    {
        $raw = $this->itemValue($item, $key);
        if (\is_int($raw)) {
            return $raw;
        }
        if (\is_numeric($raw)) {
            return (int) $raw;
        }

        return $default;
    }

    /**
     * @param array<int|string, mixed> $item
     */
    public function itemFloat(array $item, string $key, float $default = 0.0): float
    {
        $raw = $this->itemValue($item, $key);
        if (\is_float($raw) || \is_int($raw)) {
            return (float) $raw;
        }
        if (\is_numeric($raw)) {
            return (float) $raw;
        }

        return $default;
    }

    /**
     * @param array<int|string, mixed> $item
     *
     * @return array<int|string, mixed>
     */
    public function itemArray(array $item, string $key): array
    {
        $raw = $this->itemValue($item, $key);

        return \is_array($raw) ? $raw : [];
    }

    private function applyStringFunction(string $value, string $fn): string
    {
        return match ($fn) {
            'trim' => $this->trim($value),
            'ltrim' => $this->ltrim($value),
            'rtrim' => $this->rtrim($value),
            'lower', 'strtolower' => $this->lower($value),
            'upper', 'strtoupper' => $this->upper($value),
            'ucfirst' => $this->ucfirst($value),
            'slug' => $this->slug($value),
            default => $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function props(): array
    {
        return $this->props;
    }

    /**
     * Alias for `props()` used across existing Twig templates.
     *
     * @return array<string, mixed>
     */
    public function getResolvedProps(): array
    {
        return $this->props;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    // =========================================================================
    // Capability & State Queries (`$this->is(...)`)
    // =========================================================================

    /**
     * Built-in capability definitions for `$this->is($capability)`.
     *
     * Framework base classes (e.g. `PrelineComponent`) may override this map to
     * register framework-wide capability rules, while concrete components override
     * `capabilities()` for block-specific rules.
     *
     * @return array<string, bool|\Closure(): bool>
     */
    protected function capabilityMap(): array
    {
        return [
            'empty' => fn (): bool => !$this->shouldRender(),
            'multiple' => fn (): bool => $this->hasNonEmptyCollection(['items']) || $this->boolProp('multiple', false),
            'container' => fn (): bool => $this->hasNonEmptyCollection(['blocks']),
            'media' => fn (): bool => null !== SuluDataNormalizer::resolveImageUrl($this->get('image'))
                || null !== SuluDataNormalizer::resolveImageUrl($this->get('video')),
            'image' => fn (): bool => null !== SuluDataNormalizer::resolveImageUrl($this->get('image')),
            'video' => fn (): bool => null !== SuluDataNormalizer::resolveImageUrl($this->get('video')),
            'linked' => fn (): bool => '' !== SuluDataNormalizer::resolveUrl($this->get('link') ?? $this->get('url'), ''),
            'clickable' => fn (): bool => '' !== SuluDataNormalizer::resolveUrl($this->get('link') ?? $this->get('url'), ''),
        ];
    }

    /**
     * Evaluates a structural capability, custom component capability, or boolean prop.
     */
    public function is(string $capability): bool
    {
        $normalized = self::toSnakeCase($capability);
        $custom = [
            ...$this->capabilityMap(),
            ...$this->capabilities(),
        ];

        if (\array_key_exists($capability, $custom)) {
            $entry = $custom[$capability];

            return \is_bool($entry) ? $entry : (bool) $entry();
        }

        if (\array_key_exists($normalized, $custom)) {
            $entry = $custom[$normalized];

            return \is_bool($entry) ? $entry : (bool) $entry();
        }

        return $this->boolProp($normalized, false);
    }

    /**
     * Returns variables to expose directly into the Twig component template scope.
     *
     * @return array<string, mixed>
     */
    public function getTemplateVariables(): array
    {
        return [
            ...\array_fill_keys(\array_keys($this->getKnownDataKeyMap()), null),
            ...$this->props,
            ...$this->data,
            'props' => $this->props,
            'data' => $this->data,
            'context' => $this->context,
        ];
    }

    public function __get(string $name): mixed
    {
        if ('props' === $name) {
            return $this->props;
        }
        if ('data' === $name) {
            return $this->data;
        }
        if ('context' === $name) {
            return $this->context;
        }

        $normalized = self::toSnakeCase($name);
        if (\array_key_exists($normalized, $this->data)) {
            return $this->data[$normalized];
        }
        if (\array_key_exists($normalized, $this->props)) {
            return $this->props[$normalized];
        }

        return null;
    }

    public function __isset(string $name): bool
    {
        if (\in_array($name, ['props', 'data', 'context'], true)) {
            return true;
        }

        $normalized = self::toSnakeCase($name);

        return \array_key_exists($normalized, $this->data)
            || \array_key_exists($normalized, $this->props)
            || isset($this->getKnownDataKeyMap()[$normalized]);
    }

    // =========================================================================
    // Internal Normalization & Validation Helpers
    // =========================================================================

    private function resetState(): void
    {
        $this->data = [];
        $this->props = [];
        $this->context = [];

        foreach ($this->defaults() as $key => $value) {
            $this->props[self::toSnakeCase($key)] = $value;
        }

        $this->onReset();
    }

    /**
     * Hook for subclasses that keep additional per-render state.
     *
     * Twig component services are shared, so any memoized state must be cleared here.
     */
    protected function onReset(): void
    {
    }

    /**
     * @param array<string, mixed> $block
     */
    private function ingestBlockArray(array $block): void
    {
        $knownDataKeys = $this->getKnownDataKeyMap();

        foreach ($block as $rawKey => $value) {
            if ('props' === $rawKey || \str_starts_with($rawKey, self::PROPS_NAMESPACE)) {
                continue;
            }

            $key = self::toSnakeCase($rawKey);
            if (\in_array($key, self::IGNORED_BLOCK_META_KEYS, true)) {
                continue;
            }

            if (isset($knownDataKeys[$key])) {
                if (null !== $value) {
                    $this->data[$key] = $value;
                }
            } elseif (null !== $value && '' !== $value) {
                $this->props[$key] = $value;
            }
        }

        $extractedProps = SuluDataNormalizer::extractProps($block);
        if ([] !== $extractedProps) {
            $this->ingestPropsArray($extractedProps);
        }

        if (isset($block['props']) && \is_array($block['props'])) {
            /** @var array<string, mixed> $nestedProps */
            $nestedProps = $block['props'];
            $this->ingestPropsArray($nestedProps);
        }

        foreach ($block as $rawKey => $value) {
            if (\str_starts_with($rawKey, self::PROPS_NAMESPACE)) {
                $this->ingestNamespacedProp($rawKey, $value);
            }
        }
    }

    /**
     * @param array<string, mixed> $props
     */
    private function ingestPropsArray(array $props): void
    {
        if (isset($props['props']) && \is_array($props['props'])) {
            /** @var array<string, mixed> $nested */
            $nested = $props['props'];
            $this->ingestPropsArray($nested);
            unset($props['props']);
        }

        $extracted = SuluDataNormalizer::extractProps($props);
        foreach ($extracted as $k => $v) {
            $this->props[self::toSnakeCase($k)] = $v;
        }

        foreach ($props as $rawKey => $value) {
            if (\str_starts_with($rawKey, self::PROPS_NAMESPACE)) {
                $this->ingestNamespacedProp($rawKey, $value);
                continue;
            }

            $key = self::toSnakeCase($rawKey);
            if (\in_array($key, self::IGNORED_BLOCK_META_KEYS, true)) {
                continue;
            }

            if (null !== $value) {
                $this->props[$key] = $value;
            }
        }
    }

    private function ingestNamespacedProp(string $rawKey, mixed $value): void
    {
        $name = \substr($rawKey, \strlen(self::PROPS_NAMESPACE));
        if ('' === $name || null === $value || '' === $value) {
            return;
        }

        $this->props[self::toSnakeCase($name)] = $value;
    }

    /**
     * @return array<string, true>
     */
    private function getKnownDataKeyMap(): array
    {
        $map = [];
        foreach (self::UNIVERSAL_DATA_KEYS as $key) {
            $map[$key] = true;
        }
        foreach ($this->collectDataKeys() as $key) {
            $map[self::toSnakeCase($key)] = true;
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function collectDataKeys(): array
    {
        $keys = $this->dataKeys();
        $parent = \get_parent_class($this);
        while (false !== $parent && self::class !== $parent && \is_subclass_of($parent, self::class)) {
            $rm = new \ReflectionMethod($parent, 'dataKeys');
            if ($rm->getDeclaringClass()->getName() === $parent) {
                /** @var list<string> $parentKeys */
                $parentKeys = $rm->invoke($this);
                $keys = [...$parentKeys, ...$keys];
            }
            $parent = \get_parent_class($parent);
        }

        return $keys;
    }

    private function isPassThroughHtmlAttribute(string $key, mixed $value): bool
    {
        if (!\is_scalar($value) && null !== $value && !$value instanceof \Stringable) {
            return false;
        }

        if (\in_array($key, ['class', 'role', 'tabindex', 'dir', 'lang', 'hidden'], true)) {
            return true;
        }

        return \str_starts_with($key, 'data-') || \str_starts_with($key, 'aria-');
    }

    /**
     * @param list<string> $keys
     */
    protected function hasNonEmptyCollection(array $keys): bool
    {
        foreach ($keys as $key) {
            if ([] !== $this->items($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $item
     */
    private function isNonEmptyItemArray(array $item): bool
    {
        foreach ($item as $key => $value) {
            if (\is_string($key) && (\in_array($key, self::IGNORED_BLOCK_META_KEYS, true) || 'props' === $key)) {
                continue;
            }

            if ($this->isNonEmptyValue($value)) {
                return true;
            }
        }

        return false;
    }

    protected function isNonEmptyValue(mixed $value): bool
    {
        if (null === $value || false === $value) {
            return false;
        }

        if (\is_string($value)) {
            return '' !== \trim($value);
        }

        if (\is_int($value) || \is_float($value) || true === $value) {
            return true;
        }

        if (\is_array($value)) {
            if ([] === $value) {
                return false;
            }

            // Check if it looks like a Sulu media or link structure
            if (\array_key_exists('url', $value) || \array_key_exists('id', $value) || \array_key_exists('thumbnails', $value) || \array_key_exists('href', $value)) {
                return null !== SuluDataNormalizer::resolveImageUrl($value)
                    || '' !== SuluDataNormalizer::resolveUrl($value);
            }

            foreach ($value as $nested) {
                if ($this->isNonEmptyValue($nested)) {
                    return true;
                }
            }

            return false;
        }

        if (\is_object($value)) {
            if (\method_exists($value, 'getFormats') || \method_exists($value, 'getThumbnails') || \method_exists($value, 'getUrl')) {
                return null !== SuluDataNormalizer::resolveImageUrl($value);
            }

            return true;
        }

        return false;
    }

    protected function isNonEmptyRichText(string $html): bool
    {
        $trimmed = \trim($html);
        if ('' === $trimmed) {
            return false;
        }

        if (1 === \preg_match('/<(img|video|iframe|svg|embed|object|audio|hr|table)\b/i', $trimmed)) {
            return true;
        }

        $stripped = \strip_tags($trimmed);
        $decoded = \html_entity_decode($stripped, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $normalized = \preg_replace('/[\s\x{00A0}\x{200B}]+/u', '', $decoded);

        return null !== $normalized && '' !== $normalized;
    }

    public static function toSnakeCase(string $input): string
    {
        if ('' === $input || \strtolower($input) === $input) {
            return \str_replace('-', '_', $input);
        }

        $snake = \preg_replace('/(?<!^)[A-Z]/', '_$0', $input);

        return \strtolower(\str_replace('-', '_', (string) $snake));
    }
}
