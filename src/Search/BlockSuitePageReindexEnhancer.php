<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Search;

use CmsIg\Seal\Converter\HtmlToTextConverter;
use Doctrine\ORM\QueryBuilder;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Page\Infrastructure\Sulu\Search\Visitor\WebsitePageReindexProviderEnhancerInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;

/**
 * Enhances Sulu 3 Website Page search documents (CMS-IG Seal) by recursively extracting
 * searchable text from nested container blocks (`section` -> `rows` -> `columns` -> `blocks`),
 * collection sub-items (`items`), and embedded `reusable_block` snippets.
 */
final class BlockSuitePageReindexEnhancer implements WebsitePageReindexProviderEnhancerInterface
{
    private const TEXT_FIELDS = [
        'title',
        'subtitle',
        'headline',
        'meta',
        'text',
        'content',
        'description',
        'summary',
        'caption',
        'quote',
        'author',
        'author_role',
        'label',
        'badge',
        'term',
        'details',
        'button_text',
        'link_text',
        'col_1',
        'col_2',
        'col_3',
        'col_4',
        'col_5',
    ];

    private const CHILD_COLLECTION_KEYS = [
        'blocks',
        'rows',
        'columns',
        'items',
        'buttons',
        'badges',
    ];

    public function __construct(
        private readonly ?SnippetRepositoryInterface $snippetRepository = null,
    ) {
    }

    public function enhanceQuery(QueryBuilder $queryBuilder): void
    {
        $selectParts = $queryBuilder->getDQLPart('select');
        $hasTemplateData = false;
        if (\is_array($selectParts)) {
            foreach ($selectParts as $part) {
                $partString = (\is_string($part) || $part instanceof \Stringable) ? (string) $part : '';
                if (\str_contains($partString, 'dimensionContent.templateData')) {
                    $hasTemplateData = true;
                    break;
                }
            }
        }

        if (!$hasTemplateData) {
            $queryBuilder->addSelect('dimensionContent.templateData');
        }
    }

    public function enhanceDocument(array $queryResult, array $document): array
    {
        $locale = isset($queryResult['locale']) && \is_string($queryResult['locale']) ? $queryResult['locale'] : 'en';
        $templateData = $queryResult['templateData'] ?? null;

        if (!\is_array($templateData) || [] === $templateData) {
            return $document;
        }

        /** @var array<string, mixed> $templateData */
        $extractedTexts = [];
        $visitedSnippets = [];

        foreach (['headline', 'subtitle', 'summary', 'description', 'content', 'article'] as $topLevelField) {
            if (isset($templateData[$topLevelField]) && \is_string($templateData[$topLevelField])) {
                $plain = $this->normalizePlainText($templateData[$topLevelField]);
                if ('' !== $plain) {
                    $extractedTexts[] = $plain;
                }
            }
        }

        if ((!isset($document['mediaId']) || '' === $document['mediaId'])
            && isset($templateData['image'])
            && \is_array($templateData['image'])
            && isset($templateData['image']['id'])
            && (\is_int($templateData['image']['id']) || \is_numeric($templateData['image']['id']))
        ) {
            $document['mediaId'] = (string) $templateData['image']['id'];
        }

        foreach (self::CHILD_COLLECTION_KEYS as $collectionKey) {
            if (isset($templateData[$collectionKey]) && \is_array($templateData[$collectionKey])) {
                $this->extractFromBlockList($templateData[$collectionKey], $locale, $extractedTexts, $visitedSnippets);
            }
        }

        if ([] === $extractedTexts) {
            return $document;
        }

        /** @var list<string> $existingContent */
        $existingContent = [];
        if (isset($document['content']) && \is_array($document['content'])) {
            foreach ($document['content'] as $entry) {
                if (\is_string($entry) && '' !== \trim($entry)) {
                    $existingContent[] = \trim($entry);
                }
            }
        }

        $merged = \array_values(\array_unique(\array_merge($existingContent, $extractedTexts)));
        $document['content'] = $merged;

        return $document;
    }

    /**
     * @param array<mixed> $blocks
     * @param list<string> $extractedTexts
     * @param array<string, bool> $visitedSnippets
     */
    private function extractFromBlockList(
        array $blocks,
        string $locale,
        array &$extractedTexts,
        array &$visitedSnippets,
    ): void {
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }

            /** @var array<string, mixed> $block */
            if ($this->isBlockDisabled($block)) {
                continue;
            }

            $type = isset($block['type']) && \is_string($block['type']) ? $block['type'] : '';

            // Resolve embedded reusable block snippet
            if ('reusable' === $type) {
                $snippetUuid = $this->extractReusableUuid($block);
                if (null !== $snippetUuid && !isset($visitedSnippets[$snippetUuid])) {
                    $visitedSnippets[$snippetUuid] = true;
                    $this->extractFromReusableSnippet($snippetUuid, $locale, $extractedTexts, $visitedSnippets);
                }
            }

            // Extract text fields on this block
            foreach (self::TEXT_FIELDS as $fieldName) {
                if (!isset($block[$fieldName]) || !\is_string($block[$fieldName])) {
                    continue;
                }

                $plain = $this->normalizePlainText($block[$fieldName]);
                if ('' !== $plain) {
                    $extractedTexts[] = $plain;
                }
            }

            // Recurse into nested containers (`rows`, `columns`, `blocks`, `items`, etc.)
            foreach (self::CHILD_COLLECTION_KEYS as $childKey) {
                if (isset($block[$childKey]) && \is_array($block[$childKey])) {
                    $this->extractFromBlockList($block[$childKey], $locale, $extractedTexts, $visitedSnippets);
                }
            }
        }
    }

    /**
     * @param list<string> $extractedTexts
     * @param array<string, bool> $visitedSnippets
     */
    private function extractFromReusableSnippet(
        string $uuid,
        string $locale,
        array &$extractedTexts,
        array &$visitedSnippets,
    ): void {
        if (null === $this->snippetRepository) {
            return;
        }

        try {
            $snippet = $this->snippetRepository->findOneBy(
                [
                    'uuid' => $uuid,
                    'locale' => $locale,
                    'stage' => DimensionContentInterface::STAGE_LIVE,
                ],
                [SnippetRepositoryInterface::SELECT_SNIPPET_CONTENT => []],
            );

            if (null === $snippet) {
                return;
            }

            $matchedContent = null;
            foreach ($snippet->getDimensionContents() as $content) {
                if (
                    DimensionContentInterface::CURRENT_VERSION === $content->getVersion()
                    && $content->getLocale() === $locale
                    && (DimensionContentInterface::STAGE_LIVE === $content->getStage() || '' === $content->getStage())
                ) {
                    $matchedContent = $content;
                    break;
                }
            }

            if (null === $matchedContent) {
                foreach ($snippet->getDimensionContents() as $content) {
                    if (
                        DimensionContentInterface::CURRENT_VERSION === $content->getVersion()
                        && null === $content->getLocale()
                        && (DimensionContentInterface::STAGE_LIVE === $content->getStage() || '' === $content->getStage())
                    ) {
                        $matchedContent = $content;
                        break;
                    }
                }
            }

            if (null !== $matchedContent) {
                $templateData = $matchedContent->getTemplateData();
                if (isset($templateData['title']) && \is_string($templateData['title'])) {
                    $plainTitle = $this->normalizePlainText($templateData['title']);
                    if ('' !== $plainTitle) {
                        $extractedTexts[] = $plainTitle;
                    }
                }
                if (isset($templateData['blocks']) && \is_array($templateData['blocks'])) {
                    $this->extractFromBlockList($templateData['blocks'], $locale, $extractedTexts, $visitedSnippets);
                }
            }
        } catch (\Throwable) {
            // Fail gracefully during search indexing if snippet cannot be loaded
        }
    }

    /**
     * @param array<string, mixed> $block
     */
    private function extractReusableUuid(array $block): ?string
    {
        foreach (['snippet', 'reusable_snippet'] as $key) {
            $val = $block[$key] ?? null;
            if (\is_string($val) && '' !== \trim($val)) {
                return \trim($val);
            }
            if (\is_array($val) && isset($val['id']) && \is_string($val['id']) && '' !== \trim($val['id'])) {
                return \trim($val['id']);
            }
        }

        if (isset($block['view']) && \is_array($block['view']) && isset($block['view']['snippet']) && \is_array($block['view']['snippet'])) {
            $viewId = $block['view']['snippet']['id'] ?? null;
            if (\is_string($viewId) && '' !== \trim($viewId)) {
                return \trim($viewId);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function isBlockDisabled(array $block): bool
    {
        if (isset($block['hidden']) && true === $block['hidden']) {
            return true;
        }
        if (isset($block['_hidden']) && true === $block['_hidden']) {
            return true;
        }

        $props = $block['props'] ?? null;
        if (\is_string($props) && '' !== \trim($props)) {
            $decoded = \json_decode($props, true);
            if (\is_array($decoded)) {
                $props = $decoded;
            }
        }

        if (\is_array($props) && isset($props['status']) && 'disabled' === $props['status']) {
            return true;
        }

        return false;
    }

    private function normalizePlainText(string $value): string
    {
        $trimmed = \trim($value);
        if ('' === $trimmed) {
            return '';
        }

        if (\str_contains($trimmed, '<')) {
            if (\class_exists(HtmlToTextConverter::class)) {
                return \trim(HtmlToTextConverter::convert($trimmed));
            }

            return \trim(\html_entity_decode(\strip_tags($trimmed), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        }

        return $trimmed;
    }
}
