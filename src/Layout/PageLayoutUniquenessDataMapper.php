<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Layout;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Content\Application\ContentDataMapper\DataMapper\DataMapperInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Totoglu\SuluBlockSuiteBundle\Snippet\SnippetScopeResolver;
use Totoglu\SuluBlockSuiteBundle\Template\TemplateKeys;

/**
 * Enforces a strict 1-to-1 `(webspace, target_template)` uniqueness constraint on `page_layout` snippets
 * and automatically generates the snippet `title` from the selected webspace and template.
 */
final readonly class PageLayoutUniquenessDataMapper implements DataMapperInterface
{
    public function __construct(
        private ?SnippetRepositoryInterface $snippetRepository = null,
        private ?TranslatorInterface $translator = null,
        private ?WebspaceManagerInterface $webspaceManager = null,
        private ?MetadataProviderRegistry $metadataProviderRegistry = null,
        private bool $multiWebspaceEnabled = true,
    ) {
    }

    public function map(
        DimensionContentInterface $unlocalizedDimensionContent,
        DimensionContentInterface $localizedDimensionContent,
        array $data,
    ): void {
        if (
            !$localizedDimensionContent instanceof SnippetDimensionContentInterface
            || !$unlocalizedDimensionContent instanceof SnippetDimensionContentInterface
        ) {
            return;
        }

        $snippetTemplateKey = \is_string($data['template'] ?? null)
            ? \trim($data['template'])
            : ($localizedDimensionContent->getTemplateKey() ?? '');

        if (TemplateKeys::PAGE_LAYOUT !== $snippetTemplateKey) {
            return;
        }

        $existingData = \array_replace(
            $unlocalizedDimensionContent->getTemplateData(),
            $localizedDimensionContent->getTemplateData(),
        );

        $rawTargetTemplate = \array_key_exists('target_template', $data)
            ? $data['target_template']
            : (\array_key_exists('page_template', $data)
                ? $data['page_template']
                : ($existingData['target_template'] ?? $existingData['page_template'] ?? null));

        $targetTemplate = \is_string($rawTargetTemplate) ? \trim($rawTargetTemplate) : '';

        $rawWebspace = \array_key_exists('webspace', $data)
            ? $data['webspace']
            : ($existingData['webspace'] ?? null);

        $webspace = $this->multiWebspaceEnabled && \is_string($rawWebspace) && '' !== \trim($rawWebspace)
            ? \trim($rawWebspace)
            : '_global';

        $locale = $localizedDimensionContent->getLocale() ?? 'en';

        if ('' !== $targetTemplate && null !== $this->snippetRepository) {
            $currentUuid = $this->resolveSnippetUuid($localizedDimensionContent);

            $snippets = $this->snippetRepository->findBy(
                filters: [
                    'templateKeys' => [TemplateKeys::PAGE_LAYOUT],
                    'stage' => DimensionContentInterface::STAGE_DRAFT,
                ],
                selects: [
                    SnippetRepositoryInterface::SELECT_SNIPPET_CONTENT => [],
                ],
            );

            foreach ($snippets as $otherSnippet) {
                if (null !== $currentUuid && $otherSnippet->getUuid() === $currentUuid) {
                    continue;
                }

                $otherMeta = $this->extractDraftPageLayoutMetadata($otherSnippet, $locale);
                $webspaceMatches = !$this->multiWebspaceEnabled || $otherMeta['webspace'] === $webspace;
                if ($otherMeta['target_template'] === $targetTemplate && $webspaceMatches) {
                    throw new ConflictHttpException($this->formatConflictMessage(
                        $webspace,
                        $targetTemplate,
                        $otherMeta['title'],
                        $locale,
                    ));
                }
            }
        }

        $explicitTitle = isset($data['title']) && \is_string($data['title']) ? \trim($data['title']) : '';
        $resolvedTitle = '' !== $explicitTitle
            ? $explicitTitle
            : $this->generateLayoutTitle($webspace, $targetTemplate, $locale);

        if ('' !== $resolvedTitle) {
            $localizedTemplateData = $localizedDimensionContent->getTemplateData();
            $localizedTemplateData['title'] = $resolvedTitle;
            $localizedDimensionContent->setTemplateData($localizedTemplateData);
        }
    }

    private function generateLayoutTitle(string $webspace, string $targetTemplate, string $locale): string
    {
        $webspaceLabel = '_global' === $webspace ? 'Global' : $webspace;
        if ('_global' !== $webspace && null !== $this->webspaceManager) {
            try {
                $ws = $this->webspaceManager->findWebspaceByKey($webspace);
                if (null !== $ws && '' !== \trim((string) $ws->getName())) {
                    $webspaceLabel = \trim((string) $ws->getName());
                }
            } catch (\Throwable) {
                // Keep raw webspace key on failure
            }
        }

        if ('' === $targetTemplate) {
            return $webspaceLabel;
        }

        $templateLabel = $targetTemplate;
        if (null !== $this->metadataProviderRegistry) {
            try {
                $typed = $this->metadataProviderRegistry->getMetadataProvider('form')
                    ->getMetadata('page', $locale, []);
                if ($typed instanceof TypedFormMetadata) {
                    $form = $typed->getForms()[$targetTemplate] ?? null;
                    if ($form instanceof FormMetadata) {
                        $title = \trim($form->getTitle($locale));
                        if ('' !== $title && $title !== $targetTemplate) {
                            $templateLabel = \sprintf('%s (%s)', $title, $targetTemplate);
                        }
                    }
                }
            } catch (\Throwable) {
                // Keep raw template key on failure
            }
        }

        if (!$this->multiWebspaceEnabled) {
            return $templateLabel;
        }

        return \sprintf('%s — %s', $webspaceLabel, $templateLabel);
    }

    private function resolveSnippetUuid(SnippetDimensionContentInterface $dimensionContent): ?string
    {
        try {
            $uuid = $dimensionContent->getResource()->getUuid();

            return '' !== \trim($uuid) ? $uuid : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{webspace: string, target_template: string, title: string}
     */
    private function extractDraftPageLayoutMetadata(SnippetInterface $snippet, string $preferredLocale): array
    {
        /** @var array<string, mixed> $unlocalizedData */
        $unlocalizedData = [];
        /** @var array<string, mixed> $localizedData */
        $localizedData = [];
        /** @var array<string, mixed> $fallbackLocalizedData */
        $fallbackLocalizedData = [];

        foreach ($snippet->getDimensionContents() as $dim) {
            if (
                DimensionContentInterface::STAGE_DRAFT !== $dim->getStage()
                || DimensionContentInterface::CURRENT_VERSION !== $dim->getVersion()
            ) {
                continue;
            }

            $dimLocale = $dim->getLocale();
            if (null === $dimLocale) {
                $unlocalizedData = $dim->getTemplateData();

                continue;
            }

            if ($dimLocale === $preferredLocale) {
                $localizedData = $dim->getTemplateData();
            } elseif ([] === $fallbackLocalizedData) {
                $fallbackLocalizedData = $dim->getTemplateData();
            }
        }

        $merged = \array_replace(
            $unlocalizedData,
            [] !== $localizedData ? $localizedData : $fallbackLocalizedData,
        );

        $webspace = SnippetScopeResolver::extractWebspace($merged);

        $rawTemplate = $merged['target_template'] ?? $merged['page_template'] ?? null;
        $targetTemplate = \is_string($rawTemplate) ? \trim($rawTemplate) : '';

        $rawTitle = $merged['title'] ?? null;
        $title = \is_string($rawTitle) && '' !== \trim($rawTitle)
            ? \trim($rawTitle)
            : \sprintf('%s — %s', $webspace, '' !== $targetTemplate ? $targetTemplate : $snippet->getUuid());

        return [
            'webspace' => $webspace,
            'target_template' => $targetTemplate,
            'title' => $title,
        ];
    }

    private function formatConflictMessage(
        string $webspace,
        string $targetTemplate,
        string $existingTitle,
        string $locale,
    ): string {
        $fallback = \sprintf(
            'A Page Layout for webspace "%s" and template "%s" already exists ("%s"). Each template can only be assigned once per webspace.',
            $webspace,
            $targetTemplate,
            $existingTitle,
        );

        if (null === $this->translator) {
            return $fallback;
        }

        $translated = $this->translator->trans(
            'sulu_block_suite.page_layout_duplicate_webspace_template',
            [
                '%webspace%' => $webspace,
                '%template%' => $targetTemplate,
                '%title%' => $existingTitle,
            ],
            'admin',
            $locale,
        );

        return ('' !== \trim($translated) && 'sulu_block_suite.page_layout_duplicate_webspace_template' !== $translated)
            ? $translated
            : $fallback;
    }
}
