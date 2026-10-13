<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Transformer;

/**
 * Contract for all block transformers in the BlockTransformPipeline.
 */
interface BlockTransformerInterface
{
    /**
     * Transform the block data prior to rendering.
     *
     * @param array<string, mixed> $block
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function transform(array $block, array $context): array;

    /**
     * Whether this transformer should execute for the given block.
     *
     * @param array<string, mixed> $block
     * @param array<string, mixed> $context
     */
    public function supports(array $block, array $context): bool;

    /**
     * Default priority for pipeline sorting (higher runs first).
     */
    public static function getDefaultPriority(): int;
}
