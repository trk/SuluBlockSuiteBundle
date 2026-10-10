<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Schema;

interface BlockPropSchemaProviderInterface
{
    /**
     * Returns the tabs and fields schema for the given block type.
     *
     * @return array{
     *     tabs: list<array{
     *         id: string,
     *         title: string,
     *         fields: list<array{
     *             name: string,
     *             type: string,
     *             label: string,
     *             placeholder?: string,
     *             col_span?: int,
     *             options?: list<array{value: string, label: string}>,
     *             default?: mixed
     *         }>
     *     }>
     * }
     */
    public function getSchema(string $blockType, string $locale = 'en'): array;
}
