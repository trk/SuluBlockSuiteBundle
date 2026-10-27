<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Transformer;

use Totoglu\SuluBlockSuiteBundle\Normalizer\SuluDataNormalizer;

/**
 * Unwraps a `row` block (and strips `section_break` / `column_break` markers) into flat leaf blocks
 * when a reusable block is expanded inside an already active nested container or column,
 * preventing nested `<section>` or grid row wrappers.
 */
class SectionFlattener implements NestedBlockFlattenerInterface
{
    private const MAX_DEPTH = 8;

    public function supports(array $block): bool
    {
        return \in_array($block['type'] ?? null, ['row', 'section_break'], true);
    }

    public function flatten(array $block, callable $expandChild): array
    {
        return $this->flattenAtDepth($block, $expandChild, 0);
    }

    /**
     * @param array<string, mixed> $block
     * @param callable(array<string, mixed>): list<array<string, mixed>> $expandChild
     *
     * @return list<array<string, mixed>>
     */
    private function flattenAtDepth(array $block, callable $expandChild, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $type = $block['type'] ?? null;
        if ('section_break' === $type || 'row' !== $type || !isset($block['blocks']) || !\is_iterable($block['blocks'])) {
            return [];
        }

        /** @var list<array<string, mixed>> $directRowLeaves */
        $directRowLeaves = [];
        foreach ($block['blocks'] as $leaf) {
            if (!\is_array($leaf) && !\is_object($leaf)) {
                continue;
            }
            $normalizedLeaf = SuluDataNormalizer::normalizeBlock($leaf);
            if (null === $normalizedLeaf || [] === $normalizedLeaf || 'column_break' === ($normalizedLeaf['type'] ?? null)) {
                continue;
            }
            if ($this->supports($normalizedLeaf)) {
                foreach ($this->flattenAtDepth($normalizedLeaf, $expandChild, $depth + 1) as $nestedLeaf) {
                    $directRowLeaves[] = $nestedLeaf;
                }
                continue;
            }
            foreach ($expandChild($normalizedLeaf) as $expandedLeaf) {
                if ($this->supports($expandedLeaf)) {
                    foreach ($this->flattenAtDepth($expandedLeaf, $expandChild, $depth + 1) as $nestedLeaf) {
                        $directRowLeaves[] = $nestedLeaf;
                    }
                    continue;
                }
                $directRowLeaves[] = $expandedLeaf;
            }
        }

        return $directRowLeaves;
    }
}
