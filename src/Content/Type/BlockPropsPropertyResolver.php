<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Content\Type;

use Sulu\Content\Application\ContentResolver\Value\ContentView;
use Sulu\Content\Application\PropertyResolver\Resolver\PropertyResolverInterface;

/**
 * Resolves block props JSON / associative array data into structured ContentView for Twig rendering.
 */
final class BlockPropsPropertyResolver implements PropertyResolverInterface
{
    /**
     * @param array<string, mixed> $params
     */
    public function resolve(mixed $data, string $locale, array $params = []): ContentView
    {
        /** @var array<string, mixed> $value */
        $value = \is_array($data) ? $data : [];

        return ContentView::create($value, ['value' => $value]);
    }

    public static function getType(): string
    {
        return 'block_props';
    }
}
