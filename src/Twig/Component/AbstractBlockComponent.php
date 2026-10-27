<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Twig\Component;

use Symfony\Component\Form\FormView;
use Symfony\UX\TwigComponent\Attribute\PreMount;
use Totoglu\SuluBlockSuiteBundle\Layout\ResponsiveWidth;
use Totoglu\SuluBlockSuiteBundle\Navigation\ActiveUrlMatcher;
use Totoglu\SuluBlockSuiteBundle\Normalizer\SuluDataNormalizer;
use Totoglu\SuluBlockSuiteBundle\Security\AttributeSanitizer;
use Totoglu\SuluBlockSuiteBundle\Security\CssScoper;
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
     * Prop / render-context key carrying the serialized `ResponsiveWidth` of the parent slot.
     */
    public const SLOT_KEY = '_slot';
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
        'meta',
        'image',
        'video',
        'alt',
        'caption',
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
        '_sulu_block_id',
        '_attributes',
        '_attributes_string',
        '_scoped_css',
        '_classes',
        '_class',
        '_pipeline_processed',
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
            $this->copyPreviewBlockIdAttribute($block, $htmlAttributes);
            $this->ingestBlockArray($block);
            unset($input['block']);
        }

        if (isset($input['data']) && \is_array($input['data'])) {
            /** @var array<string, mixed> $data */
            $data = $input['data'];
            $this->copyPreviewBlockIdAttribute($data, $htmlAttributes);
            foreach ($data as $k => $v) {
                $normKey = self::toSnakeCase($k);
                if (\in_array($normKey, self::IGNORED_BLOCK_META_KEYS, true)) {
                    continue;
                }
                $this->data[$normKey] = $v;
            }
            unset($input['data']);
        }

        if (isset($input['props']) && \is_array($input['props'])) {
            /** @var array<string, mixed> $props */
            $props = $input['props'];
            $this->copyPreviewBlockIdAttribute($props, $htmlAttributes);
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
     * @param array<string, mixed> $source
     * @param array<string, mixed> $htmlAttributes
     */
    private function copyPreviewBlockIdAttribute(array $source, array &$htmlAttributes): void
    {
        if (
            isset($source['_attributes'])
            && \is_array($source['_attributes'])
            && isset($source['_attributes']['data-block-id'])
            && \is_string($source['_attributes']['data-block-id'])
            && '' !== $source['_attributes']['data-block-id']
        ) {
            $htmlAttributes['data-block-id'] = $source['_attributes']['data-block-id'];
        }
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
     * Reads a list of item arrays from `$this->items($key)` with fallback to `$this->prop($key)`.
     *
     * @return list<array<string, mixed>>
     */
    protected function itemsOrProp(string $key): array
    {
        $raw = $this->items($key);
        if ([] === $raw) {
            $propItems = $this->prop($key, []);
            if (\is_array($propItems)) {
                foreach ($propItems as $item) {
                    if (\is_array($item)) {
                        /** @var array<string, mixed> $item */
                        $raw[] = $item;
                    }
                }
            }
        }

        return $raw;
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
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    // =========================================================================
    // Universal Element Identity, Attributes, Scoped CSS, Media, Links & Active URL
    // =========================================================================

    public function getElementId(): ?string
    {
        $resolvedId = AttributeSanitizer::sanitizeId($this->props['_id'] ?? null)
            ?? AttributeSanitizer::sanitizeId($this->props['id'] ?? null);
        if (null !== $resolvedId) {
            return $resolvedId;
        }

        if (null !== (PropReader::nullableString($this->props, 'css') ?? PropReader::nullableString($this->props, 'custom_css'))) {
            return CssScoper::generateElementId([
                [static::class, self::normalizeUidSeed($this->data), $this->extractRenderLoopIndex()],
                $this->props,
            ]);
        }

        return null;
    }

    public function getComponentUid(string $prefix = 'hs'): string
    {
        $elementId = $this->getElementId();
        if (null !== $elementId) {
            return $elementId;
        }

        $payload = \json_encode(
            [static::class, self::normalizeUidSeed($this->data), $this->props, $this->extractRenderLoopIndex()],
            \JSON_PARTIAL_OUTPUT_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return $prefix . '-' . \substr(\md5(false !== $payload ? $payload : ''), 0, 10);
    }

    public function getCustomClass(): string
    {
        if (isset($this->props['_class']) && \is_string($this->props['_class'])) {
            return $this->props['_class'];
        }

        $raw = PropReader::nullableString($this->props, 'class')
            ?? PropReader::nullableString($this->props, 'css_classes');

        return \implode(' ', ClassList::tokens($raw));
    }

    /**
     * @return array<string, string|bool>
     */
    public function getCustomAttributes(): array
    {
        $sanitized = isset($this->props['_attributes']) && \is_array($this->props['_attributes'])
            ? AttributeSanitizer::sanitize($this->props['_attributes'])
            : [];

        $raw = PropReader::nullableString($this->props, 'attributes');
        if (null === $raw) {
            return $sanitized;
        }

        return \array_merge($sanitized, AttributeSanitizer::parse($raw));
    }

    public function getScopedCss(): ?string
    {
        $preScoped = PropReader::nullableString($this->props, '_scoped_css');
        if (null !== $preScoped) {
            $sanitized = CssScoper::sanitize($preScoped);

            return '' !== $sanitized ? $sanitized : null;
        }

        $rawCss = PropReader::nullableString($this->props, 'css')
            ?? PropReader::nullableString($this->props, 'custom_css');
        if (null === $rawCss) {
            return null;
        }

        $elementId = $this->getElementId();
        if (null === $elementId || '' === $elementId) {
            return null;
        }

        $scoped = CssScoper::scope($rawCss, '#' . $elementId);

        return '' !== $scoped ? $scoped : null;
    }

    protected static function sanitizeMediaUrl(?string $url): ?string
    {
        if (null === $url) {
            return null;
        }

        $clean = SuluDataNormalizer::sanitizeUrl(\trim($url), '');

        return ('' !== $clean && '#' !== $clean) ? $clean : null;
    }

    public function getImageUrl(string $key = 'image'): ?string
    {
        $urlKey = $key . '_url';
        $source = $this->stringProp('media_source', 'media');
        if ('url' === $source) {
            $url = self::sanitizeMediaUrl($this->nullableString($urlKey) ?? $this->nullableStringProp($urlKey));
            if (null !== $url) {
                return $url;
            }
        }

        return SuluDataNormalizer::resolveImageUrl($this->get($key))
            ?? self::sanitizeMediaUrl($this->nullableString($urlKey) ?? $this->nullableStringProp($urlKey));
    }

    public function getImageSrcSet(string $key = 'image'): ?string
    {
        return SuluDataNormalizer::resolveSrcSet($this->get($key));
    }

    /**
     * Resolves an image URL from a collection item array, honoring item/parent `media_source` (`media` vs `url`).
     *
     * @param array<string, mixed> $item
     */
    public function resolveItemImageUrl(array $item, string $key = 'image'): ?string
    {
        $source = $this->itemInheritedString($item, 'media_source', $this->stringProp('media_source', 'media'));
        $urlKey = $key . '_url';
        $itemProps = $this->itemArray($item, 'props');
        if ('url' === $source) {
            $url = self::sanitizeMediaUrl($this->itemNullableString($item, $urlKey) ?? $this->itemNullableString($itemProps, $urlKey));
            if (null !== $url) {
                return $url;
            }
        }

        $resolved = SuluDataNormalizer::resolveImageUrl($item[$key] ?? null);
        if (null !== $resolved) {
            return $resolved;
        }

        return self::sanitizeMediaUrl($this->itemNullableString($item, $urlKey) ?? $this->itemNullableString($itemProps, $urlKey));
    }

    /**
     * Resolves a responsive `srcset` string from a collection item array's media field.
     *
     * @param array<string, mixed> $item
     */
    public function resolveItemImageSrcSet(array $item, string $key = 'image'): ?string
    {
        return SuluDataNormalizer::resolveSrcSet($item[$key] ?? null);
    }

    public function getImageFocusPointStyle(string $key = 'image'): ?string
    {
        return SuluDataNormalizer::resolveFocusPointStyle($this->get($key));
    }

    public function getImageAlt(string $key = 'image', string $fallbackKey = 'title'): string
    {
        $explicitAlt = $this->nullableString('alt') ?? $this->nullableStringProp('alt');
        if (null !== $explicitAlt) {
            return $explicitAlt;
        }

        $extracted = $this->extractMediaAlt($this->get($key));
        if (null !== $extracted) {
            return $extracted;
        }

        return '' !== $fallbackKey ? $this->string($fallbackKey, '') : '';
    }

    protected function extractMediaAlt(mixed $media): ?string
    {
        if (\is_array($media)) {
            foreach (['alt', 'description', 'title'] as $field) {
                $candidate = $this->itemNullableString($media, $field);
                if (null !== $candidate) {
                    return $candidate;
                }
            }
        } elseif (\is_object($media)) {
            if (\method_exists($media, 'getDescription')) {
                $desc = $this->trim($media->getDescription());
                if ('' !== $desc) {
                    return $desc;
                }
            }
            if (\method_exists($media, 'getTitle')) {
                $title = $this->trim($media->getTitle());
                if ('' !== $title) {
                    return $title;
                }
            }
        }

        return null;
    }

    public function getVideoUrl(string $key = 'video'): ?string
    {
        return $this->getImageUrl($key);
    }

    public function isImageEager(): bool
    {
        return $this->boolProp('image_loading', false)
            || 'eager' === $this->stringProp('loading', 'lazy');
    }

    public function getImageLoading(bool $isFirstItem = true): string
    {
        return ($isFirstItem && $this->isImageEager()) ? 'eager' : 'lazy';
    }

    public function getImageFetchPriority(bool $isFirstItem = true, bool $isHiddenOrOffscreen = false): ?string
    {
        $explicit = $this->nullableStringProp('fetchpriority');
        if ('low' === $explicit) {
            return 'low';
        }
        if ('high' === $explicit && $isFirstItem && $this->isImageEager()) {
            return 'high';
        }

        if ($isFirstItem && $this->isImageEager()) {
            return 'high';
        }

        if ($isHiddenOrOffscreen) {
            return 'low';
        }

        return null;
    }

    /**
     * Whether `media_type === 'auto'` checks video before image when both are present.
     */
    protected function prefersVideoInAutoMedia(): bool
    {
        return false;
    }

    public function getMediaType(): ?string
    {
        $forced = $this->stringProp('media_type', 'auto');

        if ('none' === $forced) {
            return null;
        }

        if ('video' === $forced && null !== $this->getVideoUrl()) {
            return 'video';
        }

        if ('image' === $forced && null !== $this->getImageUrl()) {
            return 'image';
        }

        if ($this->prefersVideoInAutoMedia()) {
            if (null !== $this->getVideoUrl()) {
                return 'video';
            }
            if (null !== $this->getImageUrl()) {
                return 'image';
            }

            return null;
        }

        if (null !== $this->getImageUrl()) {
            return 'image';
        }

        if (null !== $this->getVideoUrl()) {
            return 'video';
        }

        return null;
    }

    public function isImage(): bool
    {
        return 'image' === $this->getMediaType();
    }

    public function isVideo(): bool
    {
        return 'video' === $this->getMediaType();
    }

    public function hasMedia(): bool
    {
        return $this->isImage() || $this->isVideo();
    }

    public function getActiveImageUrl(): ?string
    {
        return $this->isImage() ? $this->getImageUrl() : null;
    }

    public function getActiveVideoUrl(): ?string
    {
        return $this->isVideo() ? $this->getVideoUrl() : null;
    }

    public function getIconClass(string $key = 'icon'): ?string
    {
        $mediaType = $this->nullableStringProp('media_type');
        if (null !== $mediaType && 'icon' !== $mediaType) {
            return null;
        }

        return SuluDataNormalizer::resolveIconClass($this->get($key));
    }

    public function getResolvedContent(): ?string
    {
        return $this->nullableString('summary')
            ?? $this->nullableString('content');
    }

    public function getTitle(): ?string
    {
        return $this->nullableString('title');
    }

    public function getSubtitle(): ?string
    {
        return $this->nullableString('subtitle');
    }

    public function getMeta(): ?string
    {
        return $this->nullableString('meta');
    }

    /**
     * Active aspect-ratio key (`aspect_ratio`).
     */
    public function getAspectRatioKey(string $default = 'auto'): string
    {
        return $this->stringProp('aspect_ratio', $default);
    }

    /**
     * Custom ratio entered by the editor when the aspect ratio is `manual`.
     *
     * @return array{float, float}|null
     */
    public function getManualAspectRatio(): ?array
    {
        if ('manual' !== $this->getAspectRatioKey()) {
            return null;
        }

        $width = $this->floatProp('aspect_ratio_width', 0.0);
        $height = $this->floatProp('aspect_ratio_height', 0.0);

        return $width > 0.0 && $height > 0.0 ? [$width, $height] : null;
    }

    /**
     * Resolves a preset aspect ratio key (e.g. `16/9`, `4/3`, `1/1`, `square`, `video`) into `[width, height]`.
     *
     * @return array{float, float}|null
     */
    protected function resolvePresetAspectRatio(string $aspectKey): ?array
    {
        return match ($aspectKey) {
            '16/9', 'video' => [16.0, 9.0],
            '4/3' => [4.0, 3.0],
            '1/1', 'square' => [1.0, 1.0],
            '3/4', 'portrait' => [3.0, 4.0],
            '9/16' => [9.0, 16.0],
            '21/9', 'ultrawide' => [21.0, 9.0],
            '3/2' => [3.0, 2.0],
            '2/3' => [2.0, 3.0],
            '2/1' => [2.0, 1.0],
            default => null,
        };
    }

    /**
     * Numeric width/height ratio of the media (preset or manual); `null` when intrinsic (`auto`).
     *
     * @return array{float, float}|null
     */
    public function getAspectRatioValue(string $default = 'auto'): ?array
    {
        $manual = $this->getManualAspectRatio();
        if (null !== $manual) {
            return $manual;
        }

        return $this->resolvePresetAspectRatio($this->getAspectRatioKey($default));
    }

    public function getMediaAspectRatioStyle(): string
    {
        $manual = $this->getManualAspectRatio();
        if (null === $manual) {
            return '';
        }

        return \sprintf('aspect-ratio: %s / %s;', self::formatRatioNumber($manual[0]), self::formatRatioNumber($manual[1]));
    }

    /**
     * Intrinsic `width`/`height` attributes for `<img>` (reserves space and prevents layout shift):
     * width is the largest rendered slot width, height follows the active aspect ratio.
     *
     * @return array{width: int, height: int}|null
     */
    public function getIntrinsicImageDimensions(string $defaultAspect = 'auto'): ?array
    {
        $ratio = $this->getAspectRatioValue($defaultAspect);
        if (null === $ratio) {
            return null;
        }

        $width = $this->getMediaSlotWidth()->maxSlotPx();

        return ['width' => $width, 'height' => (int) \round($width * $ratio[1] / $ratio[0])];
    }

    /**
     * Hook returning the pixel cap for this block's `max_width` prop (`null` when unconstrained).
     */
    protected function resolveBlockMaxWidthPx(): ?int
    {
        return null;
    }

    /**
     * Hook returning the default container pixel cap when the block is rendered standalone without a parent slot.
     */
    protected function resolveDefaultContainerMaxWidthPx(): ?int
    {
        return 1280;
    }

    /**
     * Rendered width of this block at every breakpoint: the layout slot inherited from the
     * parent container (`_slot` prop or render context) capped by the block's own `max_width`.
     */
    public function getSlotWidth(): ResponsiveWidth
    {
        return $this->narrowSlotWidth($this->resolveInheritedSlotWidth())
            ->cap($this->resolveBlockMaxWidthPx());
    }

    /**
     * Slot provided by the parent (`_slot` prop, then render context), or the default container
     * when the block is rendered standalone.
     */
    protected function resolveInheritedSlotWidth(): ResponsiveWidth
    {
        return ResponsiveWidth::fromArray($this->prop(self::SLOT_KEY))
            ?? ResponsiveWidth::fromArray($this->context()[self::SLOT_KEY] ?? null)
            ?? ResponsiveWidth::container($this->resolveDefaultContainerMaxWidthPx());
    }

    /**
     * Hook for components that occupy only part of the inherited slot (e.g. a standalone column span).
     */
    protected function narrowSlotWidth(ResponsiveWidth $inherited): ResponsiveWidth
    {
        return $inherited;
    }

    /**
     * Rendered width of the block's media slot. Components with split layouts
     * (e.g. horizontal cards, side images) narrow it further.
     */
    public function getMediaSlotWidth(): ResponsiveWidth
    {
        return $this->getSlotWidth();
    }

    /**
     * Render-context entries that pass this block's layout slot to nested blocks.
     *
     * @return array<string, mixed>
     */
    public function getChildContext(): array
    {
        return \array_merge($this->context(), [
            self::SLOT_KEY => $this->getSlotWidth()->toArray(),
            '_nested_block' => true,
        ]);
    }

    public function getImageSizes(): string
    {
        return $this->nullableStringProp('sizes') ?? $this->getMediaSlotWidth()->toSizes();
    }

    /**
     * Resolves inline CSS position offsets (`position_left`, `position_right`, `position_top`, `position_bottom`)
     * when `position` is active (`relative`, `absolute`, `fixed`, `sticky`).
     */
    public function getPositionStyle(): string
    {
        return self::resolvePositionStyle($this->props);
    }

    /**
     * @param array<string, mixed> $props
     */
    public static function resolvePositionStyle(array $props): string
    {
        $pos = PropReader::nullableString($props, 'position');
        if (null === $pos || '' === $pos || 'static' === $pos) {
            return '';
        }

        $declarations = [];

        $left = self::normalizePositionOffset($props['position_left'] ?? null);
        $right = (null === $left) ? self::normalizePositionOffset($props['position_right'] ?? null) : null;
        $top = self::normalizePositionOffset($props['position_top'] ?? null);
        $bottom = (null === $top) ? self::normalizePositionOffset($props['position_bottom'] ?? null) : null;

        if (null !== $left) {
            $declarations[] = 'left: ' . $left . ';';
        } elseif (null !== $right) {
            $declarations[] = 'right: ' . $right . ';';
        }

        if (null !== $top) {
            $declarations[] = 'top: ' . $top . ';';
        } elseif (null !== $bottom) {
            $declarations[] = 'bottom: ' . $bottom . ';';
        }

        return \implode(' ', $declarations);
    }

    private static function normalizePositionOffset(mixed $raw): ?string
    {
        if (\is_int($raw) || \is_float($raw)) {
            return $raw . 'px';
        }

        if (!\is_string($raw)) {
            return null;
        }

        $trimmed = \trim($raw);
        if ('' === $trimmed) {
            return null;
        }

        if (1 === \preg_match('/^-?\d+(?:\.\d+)?$/', $trimmed)) {
            return $trimmed . 'px';
        }

        if (1 === \preg_match('/^-?\d+(?:\.\d+)?(?:px|%|vw|vh|rem|em)$/i', $trimmed)) {
            return $trimmed;
        }

        return null;
    }

    private static function formatRatioNumber(float $value): string
    {
        return \rtrim(\rtrim(\number_format($value, 4, '.', ''), '0'), '.');
    }

    public function getLinkUrl(string $key = 'link', bool $fallbackToFirstButton = false): ?string
    {
        $raw = $this->get($key);
        if (null !== $raw && '' !== $raw && [] !== $raw) {
            $resolved = SuluDataNormalizer::resolveUrl($raw);
            if ('#' !== $resolved && '' !== $resolved) {
                return $resolved;
            }
        }

        if ($fallbackToFirstButton) {
            $buttonList = $this->items('buttons');
            if ([] === $buttonList) {
                $buttonList = $this->items('elements');
            }

            foreach ($buttonList as $button) {
                if ('badge' !== $this->itemString($button, 'type') && isset($button['link'])) {
                    $resolved = SuluDataNormalizer::resolveUrl($button['link']);
                    if ('#' !== $resolved && '' !== $resolved) {
                        return $resolved;
                    }
                }
            }
        }

        return null;
    }

    public function getLinkTarget(string $key = 'link'): ?string
    {
        $explicit = $this->nullableStringProp('target') ?? $this->nullableString('target');
        if (null !== $explicit) {
            return \in_array($explicit, ['_blank', '_self', '_parent', '_top'], true) ? $explicit : null;
        }

        $link = $this->get($key);
        if (\is_array($link)) {
            $target = $this->itemNullableString($link, 'target');
            if (null !== $target && \in_array($target, ['_blank', '_self', '_parent', '_top'], true)) {
                return $target;
            }
        }

        return null;
    }

    public function getLinkTitle(
        string $key = 'link',
        string $fallbackKey = 'link_title',
        bool $fallbackToFirstButton = false,
    ): ?string {
        $explicit = $this->nullableString($fallbackKey);
        if (null !== $explicit) {
            return $explicit;
        }

        $link = $this->get($key);
        if (\is_array($link)) {
            $fromLink = $this->itemNullableString($link, 'title');
            if (null !== $fromLink) {
                return $fromLink;
            }
        }

        if ($fallbackToFirstButton) {
            $buttonList = $this->items('buttons');
            if ([] === $buttonList) {
                $buttonList = $this->items('elements');
            }

            foreach ($buttonList as $button) {
                if ('badge' === $this->itemString($button, 'type')) {
                    continue;
                }

                $buttonLabel = $this->itemNullableString($button, 'title')
                    ?? $this->itemNullableString($button, 'link_title');
                if (null !== $buttonLabel) {
                    return $buttonLabel;
                }
            }
        }

        return null;
    }

    public function resolveCurrentRequestPath(?string $explicitCurrentPath = null): string
    {
        return ActiveUrlMatcher::resolveCurrentRequestPath($this->context, $this->props, $explicitCurrentPath);
    }

    public function resolveRootUrl(?string $explicitRootUrl = null): string
    {
        return ActiveUrlMatcher::resolveRootUrl($this->context, $this->props, $explicitRootUrl);
    }

    /**
     * Resolves the current Sulu page UUID from the render context (`uuid`, `view.uuid`, `content.id`) or `uuid` prop.
     */
    public function getPageUuid(): ?string
    {
        $uuid = $this->context['uuid'] ?? null;

        if (!\is_string($uuid) || '' === \trim($uuid)) {
            $view = $this->context['view'] ?? null;
            if (\is_array($view) && \is_string($view['uuid'] ?? null)) {
                $uuid = $view['uuid'];
            }
        }

        if (!\is_string($uuid) || '' === \trim($uuid)) {
            $content = $this->context['content'] ?? null;
            if (\is_array($content) && \is_string($content['id'] ?? null)) {
                $uuid = $content['id'];
            }
        }

        if (!\is_string($uuid) || '' === \trim($uuid)) {
            $uuid = $this->nullableStringProp('uuid');
        }

        if (!\is_string($uuid)) {
            return null;
        }

        $trimmed = \trim($uuid);

        return '' !== $trimmed ? $trimmed : null;
    }

    public function isLightboxThumbnailsEnabled(): bool
    {
        return $this->boolProp('lightbox_thumbnails', true);
    }

    public function isLightboxZoomEnabled(): bool
    {
        return $this->boolProp('lightbox_zoom', true);
    }

    public function isLightboxFullscreenEnabled(): bool
    {
        return $this->boolProp('lightbox_fullscreen', true);
    }

    public function isLightboxDownloadEnabled(): bool
    {
        return $this->boolProp('lightbox_download', true);
    }

    public function isLightboxCounterEnabled(): bool
    {
        return $this->boolProp('lightbox_counter', true);
    }

    public function isLightboxCaptionEnabled(): bool
    {
        return $this->boolProp('lightbox_caption', true);
    }

    /**
     * @return array{thumbnails: bool, zoom: bool, fullscreen: bool, download: bool, counter: bool, caption: bool}
     */
    public function getLightboxOptions(): array
    {
        return [
            'thumbnails' => $this->isLightboxThumbnailsEnabled(),
            'zoom' => $this->isLightboxZoomEnabled(),
            'fullscreen' => $this->isLightboxFullscreenEnabled(),
            'download' => $this->isLightboxDownloadEnabled(),
            'counter' => $this->isLightboxCounterEnabled(),
            'caption' => $this->isLightboxCaptionEnabled(),
        ];
    }

    public function getLightboxConfigJson(): string
    {
        $config = $this->prop('lightbox_config');
        $data = \is_array($config)
            ? \array_merge($this->getLightboxOptions(), $config)
            : $this->getLightboxOptions();

        return (string) \json_encode(
            $data,
            \JSON_THROW_ON_ERROR | \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_AMP | \JSON_HEX_QUOT,
        );
    }

    public static function isExternalOrNonPageUrl(string $url): bool
    {
        return ActiveUrlMatcher::isExternalOrNonPageUrl($url);
    }

    public function isInternalUrlActive(
        string $itemUrl,
        ?string $currentPath = null,
        ?string $rootUrl = null,
        bool $allowParentPrefixMatch = true,
    ): bool {
        return ActiveUrlMatcher::isActive(
            $itemUrl,
            $this->resolveCurrentRequestPath($currentPath),
            $this->resolveRootUrl($rootUrl),
            $allowParentPrefixMatch,
            $this->context,
            $this->props,
        );
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    public function hasActiveNestedEntries(
        array $entries,
        ?string $currentPath = null,
        ?string $rootUrl = null,
    ): bool {
        return ActiveUrlMatcher::hasActiveNestedEntries(
            $entries,
            $this->resolveCurrentRequestPath($currentPath),
            $this->resolveRootUrl($rootUrl),
            $this->context,
            $this->props,
        );
    }

    /**
     * Resolves a Sulu FormBundle `FormView` (`form_view` or `form`), automatically cloning the `FormView`
     * tree when it has already been rendered once in the current request/preview lifecycle.
     */
    protected function resolveReusableFormView(): ?FormView
    {
        $view = $this->get('form_view') ?? $this->get('form');
        if (!$view instanceof FormView) {
            return null;
        }

        if ($view->isRendered() || $view->isMethodRendered()) {
            return self::cloneFormView($view);
        }

        return $view;
    }

    /**
     * Recursively clones a rendered Symfony `FormView` tree so it can be rendered again safely.
     */
    public static function cloneFormView(FormView $source, ?FormView $parent = null): FormView
    {
        $clone = new FormView($parent);
        $clone->vars = $source->vars;
        $clone->vars['form'] = $clone;

        foreach ($source->children as $name => $child) {
            $clone->children[$name] = self::cloneFormView($child, $clone);
        }

        return $clone;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getFormEntity(): ?array
    {
        $entity = $this->get('form_entity');

        if (\is_array($entity)) {
            /** @var array<string, mixed> $entity */
            return $entity;
        }

        return null;
    }

    public function isSubmitted(): bool
    {
        return (bool) ($this->get('form_submitted') ?? false);
    }

    public function getSuccessText(): ?string
    {
        $entity = $this->getFormEntity();
        if (null !== $entity && \is_string($entity['successText'] ?? null)) {
            $trimmed = \trim($entity['successText']);
            if ('' !== $trimmed) {
                return $trimmed;
            }
        }

        return null;
    }

    public function hasSummary(): bool
    {
        $summary = $this->get('_summary');

        return \is_array($summary)
            && isset($summary['formatted_summary'])
            && \is_string($summary['formatted_summary'])
            && '' !== \trim($summary['formatted_summary']);
    }

    public function hasEmptyState(): bool
    {
        $emptyState = $this->get('_empty_state');

        return \is_array($emptyState)
            && isset($emptyState['message'])
            && \is_string($emptyState['message'])
            && '' !== \trim($emptyState['message']);
    }

    public function hasSummaryOrEmptyState(): bool
    {
        return $this->hasSummary() || $this->hasEmptyState();
    }

    /**
     * Extracts unique tag slug => label pairs across collection items (`tags` array/string or `tag` string).
     *
     * @return array<string, string>
     */
    public function getFilterTags(string $itemsKey = 'items'): array
    {
        $uniqueTags = [];

        foreach ($this->items($itemsKey) as $item) {
            foreach (['tags', 'tag'] as $field) {
                foreach ($this->split($item[$field] ?? null) as $label) {
                    $slug = $this->slug($label);
                    if ('' !== $slug && !isset($uniqueTags[$slug])) {
                        $uniqueTags[$slug] = $label;
                    }
                }
            }
        }

        return $uniqueTags;
    }

    /**
     * @param array<string, mixed> $item
     */
    public function getItemTagSlugs(array $item): string
    {
        $slugs = [];

        foreach (['tags', 'tag'] as $field) {
            foreach ($this->split($item[$field] ?? null) as $label) {
                $slug = $this->slug($label);
                if ('' !== $slug) {
                    $slugs[] = $slug;
                }
            }
        }

        return $this->join(\array_unique($slugs));
    }

    protected static function normalizeUidSeed(mixed $value, int $depth = 0): mixed
    {
        if (\is_object($value)) {
            $id = null;
            if (\method_exists($value, 'getId')) {
                /** @var mixed $rawId */
                $rawId = $value->getId();
                if (\is_scalar($rawId)) {
                    $id = $rawId;
                }
            }

            $url = null;
            if (\method_exists($value, 'getUrl')) {
                /** @var mixed $rawUrl */
                $rawUrl = $value->getUrl();
                if (\is_string($rawUrl)) {
                    $url = $rawUrl;
                }
            }

            return [
                '__class' => $value::class,
                'id' => $id,
                'url' => $url,
                'oid' => \spl_object_id($value),
            ];
        }

        if (\is_array($value) && $depth < 4) {
            $normalized = [];
            foreach ($value as $k => $v) {
                $normalized[$k] = self::normalizeUidSeed($v, $depth + 1);
            }

            return $normalized;
        }

        return $value;
    }

    protected function extractRenderLoopIndex(): ?int
    {
        $loop = $this->context['loop'] ?? null;
        if (\is_array($loop) && isset($loop['index0']) && \is_numeric($loop['index0'])) {
            return (int) $loop['index0'];
        }

        return null;
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

        if (isset($block['_attributes']) && \is_array($block['_attributes'])) {
            $this->props['_attributes'] = $block['_attributes'];
        }
        if (isset($block['_class']) && \is_string($block['_class'])) {
            $this->props['_class'] = $block['_class'];
        }
        if (isset($block['_scoped_css']) && \is_string($block['_scoped_css'])) {
            $this->props['_scoped_css'] = $block['_scoped_css'];
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

        if (isset($props['_attributes']) && \is_array($props['_attributes'])) {
            $this->props['_attributes'] = $props['_attributes'];
        }
        if (isset($props['_class']) && \is_string($props['_class'])) {
            $this->props['_class'] = $props['_class'];
        }
        if (isset($props['_scoped_css']) && \is_string($props['_scoped_css'])) {
            $this->props['_scoped_css'] = $props['_scoped_css'];
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
