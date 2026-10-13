<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Transformer;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Executes a prioritized chain of BlockTransformerInterface instances on block data.
 */
final class BlockTransformPipeline implements BlockTransformPipelineInterface
{
    /**
     * @var list<array{transformer: BlockTransformerInterface, priority: int}>
     */
    private array $transformers = [];

    /**
     * @param iterable<BlockTransformerInterface> $transformers
     */
    public function __construct(
        #[AutowireIterator('sulu_block_suite.block_transformer')]
        iterable $transformers = [],
    ) {
        foreach ($transformers as $transformer) {
            $this->addTransformer($transformer);
        }
    }

    public function addTransformer(BlockTransformerInterface $transformer, ?int $priority = null): self
    {
        $priority = $priority ?? $transformer::getDefaultPriority();

        $this->transformers[] = [
            'transformer' => $transformer,
            'priority' => $priority,
        ];

        \usort($this->transformers, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        return $this;
    }

    /**
     * @return list<BlockTransformerInterface>
     */
    public function getTransformers(): array
    {
        return \array_map(static fn (array $item): BlockTransformerInterface => $item['transformer'], $this->transformers);
    }

    public function process(array $block, array $context = []): array
    {
        foreach ($this->transformers as $item) {
            $transformer = $item['transformer'];
            if ($transformer->supports($block, $context)) {
                $block = $transformer->transform($block, $context);
                unset($block['_expanded_blocks']);
            }
        }

        return $block;
    }

    public function processCollection(array $blocks, array $context = []): array
    {
        /** @var list<array<string, mixed>> $currentBlocks */
        $currentBlocks = [];
        foreach ($blocks as $block) {
            if (\is_array($block)) {
                /** @var array<string, mixed> $block */
                $currentBlocks[] = $block;
            }
        }

        foreach ($this->transformers as $item) {
            $transformer = $item['transformer'];
            /** @var list<array<string, mixed>> $nextBlocks */
            $nextBlocks = [];
            foreach ($currentBlocks as $block) {
                if ($transformer->supports($block, $context)) {
                    $transformed = $transformer->transform($block, $context);
                    if (isset($transformed['_expanded_blocks']) && \is_array($transformed['_expanded_blocks'])) {
                        foreach ($transformed['_expanded_blocks'] as $expanded) {
                            if (\is_array($expanded)) {
                                /** @var array<string, mixed> $expanded */
                                $expanded['_dynamic_content_resolved'] = true;
                                unset($expanded['_expanded_blocks']);
                                $nextBlocks[] = $expanded;
                            }
                        }

                        continue;
                    }
                    $nextBlocks[] = $transformed;
                } else {
                    $nextBlocks[] = $block;
                }
            }
            $currentBlocks = $nextBlocks;
        }

        return $currentBlocks;
    }
}
