<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Transformer;

/**
 * Pipeline that orchestrates all registered BlockTransformers in priority order.
 */
interface BlockTransformPipelineInterface
{
    /**
     * Process a single block through all registered transformers.
     *
     * @param array<string, mixed> $block
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function process(array $block, array $context = []): array;

    /**
     * Process a collection of blocks through all registered transformers.
     *
     * @param list<array<string, mixed>>|array<string, mixed> $blocks
     * @param array<string, mixed> $context
     *
     * @return list<array<string, mixed>>
     */
    public function processCollection(array $blocks, array $context = []): array;
}
