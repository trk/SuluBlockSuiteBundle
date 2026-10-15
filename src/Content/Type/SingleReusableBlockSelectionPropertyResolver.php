<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Content\Type;

use Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface;
use Sulu\Content\Application\ContentResolver\Value\ContentView;
use Sulu\Content\Application\PropertyResolver\Resolver\PropertyResolverInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Infrastructure\Sulu\Content\ResourceLoader\SnippetResourceLoader;

/**
 * Resolves a single reusable block snippet selection with automatic reference tracking and HTTP cache tagging.
 */
final class SingleReusableBlockSelectionPropertyResolver implements PropertyResolverInterface
{
    public function __construct(
        private readonly ?ReferenceStoreInterface $referenceStore = null,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function resolve(mixed $data, string $locale, array $params = []): ContentView
    {
        if (!\is_string($data) || '' === $data) {
            return ContentView::create(null, \array_merge(['id' => null], $params));
        }

        $this->referenceStore?->add($data, SnippetInterface::RESOURCE_KEY);

        /** @var string $resourceLoaderKey */
        $resourceLoaderKey = isset($params['resourceLoader']) && \is_string($params['resourceLoader'])
            ? $params['resourceLoader']
            : SnippetResourceLoader::getKey();

        $properties = isset($params['properties']) && \is_array($params['properties'])
            ? $params['properties']
            : [
                'title' => 'title',
                'blocks' => 'blocks',
            ];

        return ContentView::createResolvableWithReferences(
            id: $data,
            resourceLoaderKey: $resourceLoaderKey,
            resourceKey: SnippetInterface::RESOURCE_KEY,
            view: [
                'id' => $data,
                ...$params,
            ],
            priority: 100,
            metadata: [
                'properties' => $properties,
            ],
        );
    }

    public static function getType(): string
    {
        return 'single_reusable_block_selection';
    }
}
