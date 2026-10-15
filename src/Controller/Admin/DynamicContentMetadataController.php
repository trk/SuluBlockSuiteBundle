<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentSourceProviderInterface;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentSourceRegistry;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\PageSourceResolverInterface;
use Totoglu\SuluBlockSuiteBundle\Metadata\BlockTargetFieldMetadataResolver;

final class DynamicContentMetadataController extends AbstractController
{
    private readonly DynamicContentSourceRegistry $registry;

    /**
     * @param iterable<DynamicContentSourceProviderInterface> $customProviders
     */
    public function __construct(
        ?PageSourceResolverInterface $sourceResolver = null,
        private readonly ?BlockTargetFieldMetadataResolver $targetFieldResolver = null,
        iterable $customProviders = [],
    ) {
        $this->registry = new DynamicContentSourceRegistry($customProviders, $sourceResolver);
    }

    #[Route('/admin/api/block-suite/dynamic/source-fields', name: 'sulu_block_suite_dynamic_source_fields', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        return $this->sourceFields($request);
    }

    #[Route('/admin/api/block-suite/dynamic/sources', name: 'sulu_block_suite_dynamic_sources', methods: ['GET'])]
    public function sources(Request $request): JsonResponse
    {
        $locale = $request->query->getString('locale', $request->getLocale());

        return new JsonResponse([
            'sources' => $this->registry->getSourceDescriptors($locale),
        ]);
    }

    #[Route('/admin/api/block-suite/dynamic/target-fields/{blockType}', name: 'sulu_block_suite_dynamic_target_fields', methods: ['GET'])]
    public function targetFields(Request $request, string $blockType): JsonResponse
    {
        if (1 !== \preg_match('/^[a-z0-9_-]+$/i', $blockType)) {
            return new JsonResponse(['multiple' => false, 'default_item_type' => null, 'fields' => []], JsonResponse::HTTP_BAD_REQUEST);
        }

        $locale = $request->query->getString('locale', $request->getLocale());
        $resolver = $this->targetFieldResolver ?? new BlockTargetFieldMetadataResolver();

        return new JsonResponse($resolver->resolveBlockMetadata($blockType, $locale));
    }

    #[Route('/admin/api/block-suite/dynamic/source-fields', name: 'sulu_block_suite_dynamic_source_fields_list', methods: ['GET'])]
    public function sourceFields(Request $request): JsonResponse
    {
        $locale = $request->query->getString('locale', $request->getLocale());
        $sourceType = $request->query->getString('source_type', '');
        /** @var array<string, mixed> $context */
        $context = $request->query->all();

        return new JsonResponse([
            'fields' => $this->registry->getSourceFields($sourceType, $locale, $context),
        ]);
    }
}
