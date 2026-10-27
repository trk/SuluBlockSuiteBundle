<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Search;

use CmsIg\Seal\Converter\HtmlToTextConverter;
use Doctrine\ORM\QueryBuilder;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Page\Infrastructure\Sulu\Search\Visitor\WebsitePageReindexProviderEnhancerInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Totoglu\SuluBlockSuiteBundle\Metadata\BlockTargetFieldMetadataResolver;
use Totoglu\SuluBlockSuiteBundle\Normalizer\SuluDataNormalizer;
use Totoglu\SuluBlockSuiteBundle\Snippet\SnippetScopeResolver;

/**
 * Enhances Sulu 3 Website Page search documents (CMS-IG Seal) by recursively extracting
 * searchable text from fields of type `text_line`, `text_area`, and `text_editor`,
 * nested `block`-type fields, and embedded `reusable` block snippets.
 */
final class BlockSuitePageReindexEnhancer implements WebsitePageReindexProviderEnhancerInterface
{
    private const TEXT_FIELD_TYPES = [
        'text_line' => true,
        'text_area' => true,
        'text_editor' => true,
    ];

    private const MEDIA_FIELD_TYPES = [
        'single_media_selection' => true,
        'media_selection' => true,
    ];

    /**
     * @var array<string, array<string, FormMetadata>>
     */
    private array $typedFormsCache = [];

    public function __construct(
        private readonly ?SnippetRepositoryInterface $snippetRepository = null,
        private readonly ?MetadataProviderRegistry $metadataProviderRegistry = null,
    ) {
    }

    public function enhanceQuery(QueryBuilder $queryBuilder): void
    {
        $selectParts = $queryBuilder->getDQLPart('select');
        $hasTemplateData = false;
        $hasTemplateKey = false;
        if (\is_array($selectParts)) {
            foreach ($selectParts as $part) {
                $partString = (\is_string($part) || $part instanceof \Stringable) ? (string) $part : '';
                if (\str_contains($partString, 'dimensionContent.templateData')) {
                    $hasTemplateData = true;
                }
                if (\str_contains($partString, 'dimensionContent.templateKey')) {
                    $hasTemplateKey = true;
                }
            }
        }

        if (!$hasTemplateKey) {
            $queryBuilder->addSelect('dimensionContent.templateKey');
        }
        if (!$hasTemplateData) {
            $queryBuilder->addSelect('dimensionContent.templateData');
        }
    }

    public function enhanceDocument(array $queryResult, array $document): array
    {
        $locale = isset($queryResult['locale']) && \is_string($queryResult['locale']) ? $queryResult['locale'] : 'en';
        $templateKey = isset($queryResult['templateKey']) && \is_string($queryResult['templateKey']) ? $queryResult['templateKey'] : null;
        $templateData = $queryResult['templateData'] ?? null;

        if (!\is_array($templateData) || [] === $templateData) {
            return $document;
        }

        /** @var array<string, mixed> $templateData */
        $extractedTexts = [];
        $visitedSnippets = [];

        $pageForm = $this->resolveTemplateForm('page', $templateKey, $locale);
        if (null !== $pageForm) {
            $this->extractFromTemplateDataWithMetadata(
                $templateData,
                $pageForm,
                $locale,
                $extractedTexts,
                $visitedSnippets,
                $document,
                isRootPage: true,
            );
        } else {
            $this->extractFromRootDataFallback($templateData, $locale, $extractedTexts, $visitedSnippets, $document);
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
     * @param array<string, mixed> $data
     * @param list<string> $extractedTexts
     * @param array<string, bool> $visitedSnippets
     * @param array<string, mixed> $document
     */
    private function extractFromTemplateDataWithMetadata(
        array $data,
        FormMetadata $formMetadata,
        string $locale,
        array &$extractedTexts,
        array &$visitedSnippets,
        array &$document,
        bool $isRootPage = false,
    ): void {
        $fields = BlockTargetFieldMetadataResolver::collectContentFields($formMetadata->getItems());

        foreach ($fields as $field) {
            $fieldName = $field->getName();
            if (\str_starts_with($fieldName, 'props/')) {
                continue;
            }

            $fieldType = $this->getFieldType($field);
            $value = $data[$fieldName] ?? null;

            if ($isRootPage && isset(self::MEDIA_FIELD_TYPES[$fieldType])) {
                if ((!isset($document['mediaId']) || '' === $document['mediaId'])
                    && \is_array($value)
                    && isset($value['id'])
                    && (\is_int($value['id']) || \is_numeric($value['id']))
                ) {
                    $document['mediaId'] = (string) $value['id'];
                }

                continue;
            }

            if ($isRootPage && 'title' === $fieldName && 'text_line' === $fieldType) {
                continue;
            }

            if (isset(self::TEXT_FIELD_TYPES[$fieldType])) {
                if (\is_string($value)) {
                    $plain = $this->normalizePlainText($value, 'text_editor' === $fieldType);
                    if ('' !== $plain) {
                        $extractedTexts[] = $plain;
                    }
                }

                continue;
            }

            if ('block' === $fieldType && \is_array($value)) {
                $this->extractFromBlockList($value, $field, $locale, $extractedTexts, $visitedSnippets);
            }
        }
    }

    /**
     * Structural fallback when Sulu FormMetadata is not available (e.g. standalone unit tests without metadata registry).
     *
     * @param array<string, mixed> $templateData
     * @param list<string> $extractedTexts
     * @param array<string, bool> $visitedSnippets
     * @param array<string, mixed> $document
     */
    private function extractFromRootDataFallback(
        array $templateData,
        string $locale,
        array &$extractedTexts,
        array &$visitedSnippets,
        array &$document,
    ): void {
        foreach ($templateData as $key => $value) {
            if ('props' === $key || 'url' === $key || 'layout' === $key || \str_starts_with($key, '_')) {
                continue;
            }

            if (\is_string($value)) {
                $plain = $this->normalizePlainText($value, \str_contains($value, '<'));
                if ('' !== $plain) {
                    $extractedTexts[] = $plain;
                }

                continue;
            }

            if (\is_array($value)) {
                if ((!isset($document['mediaId']) || '' === $document['mediaId'])
                    && isset($value['id'])
                    && (\is_int($value['id']) || \is_numeric($value['id']))
                ) {
                    $document['mediaId'] = (string) $value['id'];

                    continue;
                }

                if (\array_is_list($value)) {
                    $this->extractFromBlockList($value, null, $locale, $extractedTexts, $visitedSnippets);
                }
            }
        }
    }

    /**
     * @param array<mixed> $blocks
     * @param list<string> $extractedTexts
     * @param array<string, bool> $visitedSnippets
     */
    private function extractFromBlockList(
        array $blocks,
        ?FieldMetadata $blockFieldMetadata,
        string $locale,
        array &$extractedTexts,
        array &$visitedSnippets,
    ): void {
        $typeForms = null !== $blockFieldMetadata ? $blockFieldMetadata->getTypes() : [];

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

                continue;
            }

            $blockForm = $this->resolveBlockTypeForm($type, $typeForms, $locale);
            if (null !== $blockForm) {
                $dummyDoc = [];
                $this->extractFromTemplateDataWithMetadata(
                    $block,
                    $blockForm,
                    $locale,
                    $extractedTexts,
                    $visitedSnippets,
                    $dummyDoc,
                    isRootPage: false,
                );
            } else {
                $this->extractFromBlockFallback($block, $locale, $extractedTexts, $visitedSnippets);
            }
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string> $extractedTexts
     * @param array<string, bool> $visitedSnippets
     */
    private function extractFromBlockFallback(
        array $block,
        string $locale,
        array &$extractedTexts,
        array &$visitedSnippets,
    ): void {
        foreach ($block as $key => $value) {
            if ('type' === $key || 'props' === $key || 'settings' === $key || \str_starts_with($key, '_')) {
                continue;
            }

            if (\is_string($value)) {
                $plain = $this->normalizePlainText($value, \str_contains($value, '<'));
                if ('' !== $plain) {
                    $extractedTexts[] = $plain;
                }

                continue;
            }

            if (\is_array($value) && \array_is_list($value)) {
                $this->extractFromBlockList($value, null, $locale, $extractedTexts, $visitedSnippets);
            }
        }
    }

    /**
     * @param array<array-key, FormMetadata> $inlineTypeForms
     */
    private function resolveBlockTypeForm(string $type, array $inlineTypeForms, string $locale): ?FormMetadata
    {
        if ('' === $type) {
            return null;
        }

        $inlineForm = $inlineTypeForms[$type] ?? null;
        if ($inlineForm instanceof FormMetadata) {
            $globalBlockTag = $inlineForm->getTagsByName('sulu.global_block')[0] ?? null;
            if (null !== $globalBlockTag) {
                $globalName = $globalBlockTag->getAttribute('global_block');
                if (\is_string($globalName) && '' !== $globalName) {
                    $globalForm = $this->resolveTemplateForm('block', $globalName, $locale);
                    if (null !== $globalForm) {
                        return $globalForm;
                    }
                }
            }

            if ([] !== $inlineForm->getItems()) {
                return $inlineForm;
            }
        }

        return $this->resolveTemplateForm('block', $type, $locale);
    }

    private function resolveTemplateForm(string $metadataKey, ?string $templateKey, string $locale): ?FormMetadata
    {
        $forms = $this->getTypedForms($metadataKey, $locale);
        if ([] === $forms) {
            return null;
        }

        if (null !== $templateKey && '' !== $templateKey && isset($forms[$templateKey])) {
            return $forms[$templateKey];
        }

        if ('page' === $metadataKey) {
            return $forms['default'] ?? \reset($forms);
        }

        return null;
    }

    /**
     * @return array<string, FormMetadata>
     */
    private function getTypedForms(string $metadataKey, string $locale): array
    {
        $cacheKey = $metadataKey . '__' . $locale;
        if (isset($this->typedFormsCache[$cacheKey])) {
            return $this->typedFormsCache[$cacheKey];
        }

        if (null === $this->metadataProviderRegistry) {
            return $this->typedFormsCache[$cacheKey] = [];
        }

        try {
            $provider = $this->metadataProviderRegistry->getMetadataProvider('form');
            $options = 'block' === $metadataKey ? ['ignore_global_blocks' => true] : [];
            $metadata = $provider->getMetadata($metadataKey, $locale, $options);
            if ($metadata instanceof TypedFormMetadata) {
                /** @var array<string, FormMetadata> $forms */
                $forms = $metadata->getForms();

                return $this->typedFormsCache[$cacheKey] = $forms;
            }
        } catch (\Throwable) {
            // Gracefully fallback when metadata cannot be loaded
        }

        return $this->typedFormsCache[$cacheKey] = [];
    }

    private function getFieldType(FieldMetadata $field): string
    {
        try {
            return $field->getType();
        } catch (\TypeError) {
            return '';
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

            $matchedContent = SnippetScopeResolver::findDimensionContent(
                $snippet,
                null,
                $locale,
                DimensionContentInterface::STAGE_LIVE,
            );

            if (null !== $matchedContent) {
                $templateData = $matchedContent->getTemplateData();
                $snippetForm = $this->resolveTemplateForm('snippet', $matchedContent->getTemplateKey(), $locale);
                if (null !== $snippetForm) {
                    $dummyDoc = [];
                    $this->extractFromTemplateDataWithMetadata(
                        $templateData,
                        $snippetForm,
                        $locale,
                        $extractedTexts,
                        $visitedSnippets,
                        $dummyDoc,
                        isRootPage: false,
                    );
                } else {
                    $dummyDoc = [];
                    $this->extractFromRootDataFallback(
                        $templateData,
                        $locale,
                        $extractedTexts,
                        $visitedSnippets,
                        $dummyDoc,
                    );
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
        return SuluDataNormalizer::extractSnippetUuid($block);
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
        if ($this->isTruthyFlag($block['is_dynamic_template'] ?? null)) {
            return true;
        }

        $props = SuluDataNormalizer::extractProps($block);
        if ([] !== $props) {
            if (isset($props['status']) && 'disabled' === $props['status']) {
                return true;
            }
            if ($this->isTruthyFlag($props['is_dynamic_template'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function isTruthyFlag(mixed $value): bool
    {
        return true === $value || 1 === $value || 'true' === $value || '1' === $value;
    }

    private function normalizePlainText(string $value, bool $isRichText = false): string
    {
        $trimmed = \trim($value);
        if ('' === $trimmed) {
            return '';
        }

        if ($isRichText || \str_contains($trimmed, '<')) {
            if (\class_exists(HtmlToTextConverter::class)) {
                return \trim(HtmlToTextConverter::convert($trimmed));
            }

            return \trim(\html_entity_decode(\strip_tags($trimmed), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        }

        return $trimmed;
    }
}
