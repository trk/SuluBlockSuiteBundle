<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Controller\Website;

use Sulu\Content\Application\ContentAggregator\ContentAggregatorInterface;
use Sulu\Content\Application\ContentResolver\ContentResolverInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\UserInterface\Controller\Website\ContentController;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface;
use Totoglu\SuluBlockSuiteBundle\Preview\PageLayoutPreviewResolver;
use Totoglu\SuluBlockSuiteBundle\Preview\SnippetPreviewProvider;
use Twig\Environment;

/**
 * Renders Sulu snippets (`page_layout` and `reusable_block`) in the Sulu Admin Live Preview pane.
 *
 * @extends ContentController<SnippetDimensionContentInterface>
 */
final class SnippetPreviewController extends ContentController
{
    public function __construct(
        private readonly ?PageRepositoryInterface $pageRepository = null,
        private readonly ?ContentAggregatorInterface $contentAggregator = null,
    ) {
    }

    /**
     * @param SnippetDimensionContentInterface $object
     *
     * @return array<string, mixed>
     */
    protected function resolveSuluParameters(
        DimensionContentInterface $object,
        string $webspaceKey,
        bool $normalize,
    ): array {
        $snippetParameters = parent::resolveSuluParameters($object, $webspaceKey, $normalize);

        $contentResolver = null;
        try {
            if ($this->container->has('sulu_content.content_resolver')) {
                $candidate = $this->container->get('sulu_content.content_resolver');
                if ($candidate instanceof ContentResolverInterface) {
                    $contentResolver = $candidate;
                }
            }
        } catch (\Throwable) {
            // Optional content resolver unavailable
        }

        return PageLayoutPreviewResolver::enrichPreviewParameters(
            $object,
            $snippetParameters,
            $webspaceKey,
            $this->pageRepository,
            $this->contentAggregator,
            $contentResolver,
        );
    }

    protected function renderSuluView(
        string $view,
        string $requestFormat,
        array $parameters,
        bool $preview,
        bool $partial,
    ): string {
        if (SnippetPreviewProvider::DEFAULT_VIEW === $view && $this->container->has('twig')) {
            /** @var Environment $twig */
            $twig = $this->container->get('twig');
            if (!$twig->getLoader()->exists('base.html.twig')) {
                $view = '@SuluBlockSuite/preview/snippet_preview_standalone';
            }
        }

        return parent::renderSuluView($view, $requestFormat, $parameters, $preview, $partial);
    }
}
