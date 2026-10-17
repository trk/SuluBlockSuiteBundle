<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Transformer;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Unwraps nested container blocks into flat leaf blocks when a reusable block
 * is expanded inside an already active nested container or column.
 */
#[AutoconfigureTag('sulu_block_suite.nested_block_flattener')]
interface NestedBlockFlattenerInterface
{
    /**
     * @param array<string, mixed> $block
     */
    public function supports(array $block): bool;

    /**
     * @param array<string, mixed> $block
     * @param callable(array<string, mixed>): list<array<string, mixed>> $expandChild
     *
     * @return list<array<string, mixed>>
     */
    public function flatten(array $block, callable $expandChild): array;
}
