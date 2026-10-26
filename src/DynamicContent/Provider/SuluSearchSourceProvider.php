<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\AbstractDynamicContentSourceProvider;

/**
 * Built-in Dynamic Content source provider for Sulu Search (`search_results` collection and `search_context` single).
 */
final class SuluSearchSourceProvider extends AbstractDynamicContentSourceProvider
{
    public function __construct(
        private readonly ?RequestStack $requestStack = null,
        ?TranslatorInterface $translator = null,
    ) {
        $this->translator = $translator;
    }

    public function getKey(): string
    {
        return 'search_results';
    }

    public function getLabel(string $locale = 'en'): string
    {
        return $this->translate('sulu_block_suite.dynamic_source_search_results', $locale, 'Search Results');
    }

    public function getSingleKey(): string
    {
        return 'search_context';
    }

    public function getSingleLabel(string $locale = 'en'): string
    {
        return $this->translate('sulu_block_suite.dynamic_source_search_context', $locale, 'Search Context');
    }

    public function getConfigFields(string $locale = 'en'): array
    {
        return $this->createPaginationAndSortFields(20);
    }

    public function getSourceFields(string $locale = 'en'): array
    {
        return [
            ['name' => 'title', 'label' => $this->translateFieldLabel('title', $locale), 'type' => 'string'],
            ['name' => 'headline', 'label' => $this->translateFieldLabel('headline', $locale), 'type' => 'string'],
            ['name' => 'summary', 'label' => $this->translateFieldLabel('summary', $locale), 'type' => 'text'],
            ['name' => 'description', 'label' => $this->translateFieldLabel('description', $locale), 'type' => 'text'],
            ['name' => 'content', 'label' => $this->translateFieldLabel('content', $locale), 'type' => 'text'],
            ['name' => 'url', 'label' => $this->translateFieldLabel('url', $locale), 'type' => 'link'],
            ['name' => 'link', 'label' => $this->translateFieldLabel('link', $locale), 'type' => 'link'],
            ['name' => 'badge', 'label' => $this->translateFieldLabel('badge', $locale), 'type' => 'string'],
            ['name' => 'template', 'label' => $this->translateFieldLabel('template', $locale), 'type' => 'string'],
            ['name' => 'query', 'label' => $this->translate('sulu_block_suite.dynamic_field_query', $locale, 'Search Query'), 'type' => 'string'],
            ['name' => 'total', 'label' => $this->translate('sulu_block_suite.dynamic_field_total', $locale, 'Total Results'), 'type' => 'number'],
        ];
    }

    public function resolveSingle(array $config, string|object|array|null $contextPage = null, ?string $locale = null): array
    {
        $query = $this->extractSearchQuery($contextPage);
        $hits = $this->extractSearchHits($contextPage);
        $count = \count($hits);

        $title = '' !== $query
            ? \sprintf('Showing %d results for: %s', $count, $query)
            : 'Search Website Content';
        $description = '' !== $query
            ? \sprintf('%d matching items found for "%s".', $count, $query)
            : 'Enter a keyword to search across pages and articles.';

        return [
            'query' => $query,
            'total' => $count,
            'badge' => $count . ' results',
            'title' => $title,
            'headline' => $title,
            'description' => $description,
            'summary' => $description,
        ];
    }

    public function resolveCollection(array $config, string|object|array|null $contextPage = null, ?string $locale = null): array
    {
        $hits = $this->extractSearchHits($contextPage);
        if ([] === $hits) {
            return [];
        }

        $normalized = [];
        foreach ($hits as $hit) {
            $normalized[] = $this->normalizeHit($hit);
        }

        $offset = isset($config['offset']) && \is_numeric($config['offset'])
            ? \max(0, (int) $config['offset'])
            : 0;
        $limit = isset($config['limit']) && \is_numeric($config['limit'])
            ? (int) $config['limit']
            : 0;

        if ($offset > 0 || ($limit > 0 && \count($normalized) > $limit)) {
            $normalized = \array_slice($normalized, $offset, $limit > 0 ? $limit : null);
        }

        return $normalized;
    }

    public function resolveCollectionMeta(array $config, string|object|array|null $contextPage = null, ?string $locale = null): array
    {
        $query = $this->extractSearchQuery($contextPage);
        $hits = $this->extractSearchHits($contextPage);
        $count = \count($hits);

        return [
            'unfiltered_total' => $count,
            'filtered_total' => $count,
            'is_filtered' => '' !== $query,
        ];
    }

    /**
     * @param string|object|array<mixed, mixed>|null $contextPage
     */
    private function extractSearchQuery(string|object|array|null $contextPage): string
    {
        if (\is_array($contextPage)) {
            if (isset($contextPage['query']) && \is_scalar($contextPage['query'])) {
                return \trim((string) $contextPage['query']);
            }
            if (isset($contextPage['q']) && \is_scalar($contextPage['q'])) {
                return \trim((string) $contextPage['q']);
            }
        }

        $request = $this->requestStack?->getCurrentRequest();
        if (null !== $request) {
            $attrQuery = $request->attributes->get('query');
            $fallback = \is_scalar($attrQuery) ? (string) $attrQuery : '';
            $q = $request->query->get('q', $fallback);

            return \trim((string) $q);
        }

        return '';
    }

    /**
     * @param string|object|array<mixed, mixed>|null $contextPage
     *
     * @return list<array<string, mixed>>
     */
    private function extractSearchHits(string|object|array|null $contextPage): array
    {
        $rawHits = null;

        if (\is_array($contextPage) && isset($contextPage['hits']) && \is_iterable($contextPage['hits'])) {
            $rawHits = $contextPage['hits'];
        } else {
            $request = $this->requestStack?->getCurrentRequest();
            if (null !== $request) {
                $attrHits = $request->attributes->get('hits');
                if (\is_iterable($attrHits)) {
                    $rawHits = $attrHits;
                }
            }
        }

        if (null === $rawHits) {
            return [];
        }

        /** @var list<array<string, mixed>> $hits */
        $hits = [];
        foreach ($rawHits as $hit) {
            if (\is_array($hit)) {
                /** @var array<string, mixed> $hit */
                $hits[] = $hit;
            } elseif (\is_object($hit)) {
                /** @var array<string, mixed> $objArr */
                $objArr = (array) $hit;
                $hits[] = $objArr;
            }
        }

        return $hits;
    }

    /**
     * @param array<string, mixed> $hit
     *
     * @return array<string, mixed>
     */
    private function normalizeHit(array $hit): array
    {
        $title = isset($hit['title']) && \is_scalar($hit['title']) && '' !== \trim((string) $hit['title'])
            ? \trim((string) $hit['title'])
            : 'Untitled';

        $excerpt = '';
        if (isset($hit['content'])) {
            if (\is_array($hit['content'])) {
                foreach ($hit['content'] as $chunk) {
                    if (\is_scalar($chunk) && '' !== \trim((string) $chunk)) {
                        $excerpt = \trim((string) $chunk);
                        break;
                    }
                }
            } elseif (\is_scalar($hit['content'])) {
                $excerpt = \trim((string) $hit['content']);
            }
        }

        if ('' === $excerpt) {
            if (isset($hit['summary']) && \is_scalar($hit['summary'])) {
                $excerpt = \trim((string) $hit['summary']);
            } elseif (isset($hit['description']) && \is_scalar($hit['description'])) {
                $excerpt = \trim((string) $hit['description']);
            }
        }

        $url = isset($hit['url']) && \is_scalar($hit['url'])
            ? \trim((string) $hit['url'])
            : '';

        $resourceKey = isset($hit['resourceKey']) && \is_scalar($hit['resourceKey']) && '' !== \trim((string) $hit['resourceKey'])
            ? \trim((string) $hit['resourceKey'])
            : 'page';

        $badge = \strtoupper($resourceKey);

        return [
            'id' => isset($hit['id']) && \is_scalar($hit['id']) ? (string) $hit['id'] : '',
            'title' => $title,
            'headline' => $title,
            'summary' => $excerpt,
            'description' => $excerpt,
            'content' => $excerpt,
            'url' => $url,
            'link' => $url,
            'badge' => $badge,
            'meta' => $badge,
            'template' => $resourceKey,
        ];
    }
}
