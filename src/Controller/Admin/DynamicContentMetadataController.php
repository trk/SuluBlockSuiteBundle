<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\DynamicContentSourceProviderInterface;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\PageSourceResolverInterface;
use Totoglu\SuluBlockSuiteBundle\Metadata\BlockTargetFieldMetadataResolver;

final class DynamicContentMetadataController extends AbstractController
{
    /**
     * @var array<string, DynamicContentSourceProviderInterface>
     */
    private array $customProviders = [];

    /**
     * @param iterable<DynamicContentSourceProviderInterface> $customProviders
     */
    public function __construct(
        private readonly ?PageSourceResolverInterface $sourceResolver = null,
        private readonly ?BlockTargetFieldMetadataResolver $targetFieldResolver = null,
        iterable $customProviders = [],
    ) {
        foreach ($customProviders as $provider) {
            $this->customProviders[$provider->getKey()] = $provider;
        }
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

        /** @var list<array{key: string, label: string, multi_item: bool}> $sources */
        $sources = [
            ['key' => 'none', 'label' => 'None (Static Manual Content)', 'multi_item' => false],
            ['key' => 'current_page', 'label' => 'Current Page', 'multi_item' => false],
            ['key' => 'parent_page', 'label' => 'Parent Page', 'multi_item' => false],
            ['key' => 'specific_page', 'label' => 'Specific Page', 'multi_item' => false],
            ['key' => 'page_children', 'label' => 'Child Pages (Repeater / Multi-Item)', 'multi_item' => true],
            ['key' => 'sibling_pages', 'label' => 'Sibling Pages (Repeater / Multi-Item)', 'multi_item' => true],
            ['key' => 'custom_query', 'label' => 'Smart Query (Repeater / Multi-Item)', 'multi_item' => true],
        ];

        foreach ($this->customProviders as $provider) {
            $sources[] = [
                'key' => $provider->getKey(),
                'label' => $provider->getLabel($locale),
                'multi_item' => $provider->isMultiItem(),
            ];
        }

        return new JsonResponse($sources);
    }

    #[Route('/admin/api/block-suite/dynamic/target-fields/{blockType}', name: 'sulu_block_suite_dynamic_target_fields', methods: ['GET'])]
    public function targetFields(Request $request, string $blockType): JsonResponse
    {
        if (1 !== \preg_match('/^[a-z0-9_-]+$/i', $blockType)) {
            return new JsonResponse([], JsonResponse::HTTP_BAD_REQUEST);
        }

        $locale = $request->query->getString('locale', $request->getLocale());
        $resolver = $this->targetFieldResolver ?? new BlockTargetFieldMetadataResolver();

        return new JsonResponse($resolver->resolveTargetFields($blockType, $locale));
    }

    #[Route('/admin/api/block-suite/dynamic/source-fields', name: 'sulu_block_suite_dynamic_source_fields_list', methods: ['GET'])]
    public function sourceFields(Request $request): JsonResponse
    {
        $locale = $request->query->getString('locale', $request->getLocale());
        $sourceType = $request->query->getString('source_type', '');

        if ('' !== $sourceType && isset($this->customProviders[$sourceType])) {
            return new JsonResponse($this->customProviders[$sourceType]->getSourceFields($locale));
        }

        /** @var list<array{name: string, label: string, type: string}> $fields */
        $fields = [
            ['name' => 'title', 'label' => 'Title', 'type' => 'string'],
            ['name' => 'headline', 'label' => 'Headline', 'type' => 'string'],
            ['name' => 'subtitle', 'label' => 'Subtitle', 'type' => 'string'],
            ['name' => 'summary', 'label' => 'Summary / Excerpt', 'type' => 'text'],
            ['name' => 'description', 'label' => 'Description', 'type' => 'text'],
            ['name' => 'image', 'label' => 'Image (Media)', 'type' => 'media'],
            ['name' => 'url', 'label' => 'Page URL', 'type' => 'link'],
            ['name' => 'link', 'label' => 'Page Link', 'type' => 'link'],
            ['name' => 'badge', 'label' => 'Primary Tag / Badge', 'type' => 'string'],
            ['name' => 'tags', 'label' => 'Tags', 'type' => 'array'],
            ['name' => 'template', 'label' => 'Template Key', 'type' => 'string'],
            ['name' => 'date', 'label' => 'Publish Date', 'type' => 'date'],
            ['name' => 'author', 'label' => 'Author', 'type' => 'string'],
        ];

        $pageId = $request->query->get('page_id');
        if (\is_string($pageId) && '' !== \trim($pageId) && null !== $this->sourceResolver) {
            $pageData = $this->sourceResolver->resolvePage(\trim($pageId), $locale);

            if (null !== $pageData) {
                $existingNames = \array_column($fields, 'name');
                foreach ($pageData as $key => $value) {
                    if (\in_array($key, $existingNames, true) || \str_starts_with($key, '_') || 'id' === $key || 'uuid' === $key) {
                        continue;
                    }

                    $type = 'string';
                    if (\is_array($value) && isset($value['id'])) {
                        $type = 'media';
                    } elseif (\is_array($value)) {
                        $type = 'array';
                    } elseif (\is_bool($value)) {
                        $type = 'boolean';
                    }

                    $fields[] = [
                        'name' => $key,
                        'label' => \ucwords(\str_replace(['_', '-'], ' ', $key)),
                        'type' => $type,
                    ];
                }
            }
        }

        return new JsonResponse($fields);
    }
}
