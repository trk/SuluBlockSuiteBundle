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
        return empty($block['_dynamic_content_resolved']);
    }

    public function transform(array $block, array $context): array
    {
        $contextPage = $this->extractContextPage($context);

        $results = $this->hydrator->hydrateBlock($block, $contextPage);

        if ([] === $results) {
            $block['_hidden'] = true;
            $block['_dynamic_content_resolved'] = true;

            return $block;
        }

        foreach ($results as $idx => $item) {
            $results[$idx]['_dynamic_content_resolved'] = true;
        }

        /** @var array<string, mixed> $transformed */
        $transformed = $results[0];
        if (\count($results) > 1) {
            $transformed['_expanded_blocks'] = $results;
        }

        return $transformed;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>|object|string|null
     */
    private function extractContextPage(array $context): array|object|string|null
    {
        if ([] === $context) {
            return null;
        }

        if (
            isset($context['resource'])
            || isset($context['object'])
            || isset($context['view'])
            || isset($context['extension'])
        ) {
            return $context;
        }

        $rawContextPage = $context['page'] ?? ($context['content'] ?? null);
        if (\is_string($rawContextPage) || \is_object($rawContextPage) || \is_array($rawContextPage)) {
            /** @var array<string, mixed>|object|string $rawContextPage */
            return $rawContextPage;
        }

        return $context;
    }
}
