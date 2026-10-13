<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Transformer;

use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentHydratorInterface;

/**
 * Pipeline transformer that resolves and maps dynamic content into block fields.
 * Priority: 100 (Runs first in the pipeline to supply dynamic data).
 */
final class DynamicContentTransformer implements BlockTransformerInterface
{
    public function __construct(
        private readonly DynamicContentHydratorInterface $hydrator,
    ) {
    }

    public static function getDefaultPriority(): int
    {
        return 100;
    }

    public function supports(array $block, array $context): bool
    {
        return true;
    }

    public function transform(array $block, array $context): array
    {
        $rawContextPage = $context['page'] ?? ($context['content'] ?? null);
        /** @var array<string, mixed>|object|string|null $contextPage */
        $contextPage = \is_string($rawContextPage) || \is_object($rawContextPage) || \is_array($rawContextPage) ? $rawContextPage : null;

        $results = $this->hydrator->hydrateBlock($block, $contextPage);

        if ([] === $results) {
            $block['_hidden'] = true;

            return $block;
        }

        /** @var array<string, mixed> $transformed */
        $transformed = $results[0];
        $transformed['_dynamic_content_resolved'] = true;
        if (\count($results) > 1) {
            $transformed['_expanded_blocks'] = $results;
        }

        return $transformed;
    }
}
