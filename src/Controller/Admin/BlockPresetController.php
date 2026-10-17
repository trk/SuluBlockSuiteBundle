<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Controller\Admin;

use Psr\Log\LoggerInterface;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Security\Authorization\SecurityCondition;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Snippet\Application\Message\ApplyWorkflowTransitionSnippetMessage;
use Sulu\Snippet\Application\Message\CreateSnippetMessage;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Sulu\Snippet\Infrastructure\Sulu\Admin\SnippetAdmin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Totoglu\SuluBlockSuiteBundle\Metadata\BlockSuiteFormMetadataVisitor;
use Totoglu\SuluBlockSuiteBundle\Preset\BlockPresetProviderInterface;
use Totoglu\SuluBlockSuiteBundle\Preset\MediaPreviewUrlResolver;
use Totoglu\SuluBlockSuiteBundle\Template\TemplateKeys;

final class BlockPresetController extends AbstractController
{
    use HandleTrait;

    private ?MediaPreviewUrlResolver $mediaPreviewUrlResolverInstance = null;

    public function __construct(
        private readonly BlockPresetProviderInterface $presetProvider,
        private readonly ?SnippetRepositoryInterface $snippetRepository = null,
        private readonly ?MediaManagerInterface $mediaManager = null,
        private readonly ?BlockSuiteFormMetadataVisitor $formMetadataVisitor = null,
        ?MessageBusInterface $messageBus = null,
        private readonly ?SecurityCheckerInterface $securityChecker = null,
        ?MediaPreviewUrlResolver $mediaPreviewUrlResolver = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        if (null !== $messageBus) {
            $this->messageBus = $messageBus;
        }
        $this->mediaPreviewUrlResolverInstance = $mediaPreviewUrlResolver;
    }

    private function getMediaPreviewUrlResolver(): MediaPreviewUrlResolver
    {
        return $this->mediaPreviewUrlResolverInstance ??= new MediaPreviewUrlResolver($this->mediaManager);
    }

    public function __invoke(Request $request, string $blockType): JsonResponse
    {
        return $this->presets($request, $blockType);
    }

    #[Route('/admin/api/block-types', name: 'sulu_block_suite_block_types', methods: ['GET'])]
    public function blockTypes(Request $request): JsonResponse
    {
        $locale = $request->query->getString('locale', $request->getLocale());
        if (!$this->isValidLocale($locale)) {
            return new JsonResponse(['error' => 'Invalid locale format.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $this->requirePermission($locale, PermissionTypes::VIEW);

        if (null === $this->formMetadataVisitor) {
            return new JsonResponse(['blocks' => []]);
        }

        $types = $this->formMetadataVisitor->discoverAvailableBlockTypes($locale);

        /** @var list<array{type: string, title: string, presets_count: int}> $result */
        $result = [];
        foreach ($types as $type) {
            $presets = $this->presetProvider->getPresets($type, $locale);
            $title = $this->formMetadataVisitor->resolveBlockTitle($type, $locale);

            $result[] = [
                'type' => $type,
                'title' => $title,
                'presets_count' => \count($presets),
            ];
        }

        return new JsonResponse([
            'blocks' => $result,
        ]);
    }

    #[Route('/admin/api/block-presets/{blockType}', name: 'sulu_block_suite_presets', methods: ['GET'])]
    public function presets(Request $request, string $blockType): JsonResponse
    {
        if (1 !== \preg_match('/^[a-z0-9_-]+$/i', $blockType)) {
            return new JsonResponse(['presets' => []], JsonResponse::HTTP_BAD_REQUEST);
        }

        $locale = $request->query->getString('locale', $request->getLocale());
        if (!$this->isValidLocale($locale)) {
            return new JsonResponse(['presets' => []], JsonResponse::HTTP_BAD_REQUEST);
        }

        $this->requirePermission($locale, PermissionTypes::VIEW);

        $presets = $this->presetProvider->getPresets($blockType, $locale);

        return new JsonResponse([
            'block_type' => $blockType,
            'locale' => $locale,
            'presets' => $presets,
        ]);
    }

    #[Route('/admin/api/block-presets', name: 'sulu_block_suite_save_preset', methods: ['POST'])]
    public function savePreset(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = [];
        $content = $request->getContent();
        if ('' !== $content) {
            /** @var mixed $decoded */
            $decoded = \json_decode($content, true);
            if (\is_array($decoded)) {
                $data = $decoded;
            }
        }
        if ([] === $data) {
            $data = $request->request->all();
        }

        $blockType = isset($data['block_type']) && \is_string($data['block_type']) ? \trim($data['block_type']) : '';
        if ('' === $blockType || 1 !== \preg_match('/^[a-z0-9_-]+$/i', $blockType)) {
            return new JsonResponse(['error' => 'Invalid or missing block type.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $title = isset($data['title']) && \is_string($data['title']) ? \trim($data['title']) : '';
        if ('' === $title) {
            return new JsonResponse(['error' => 'Preset title cannot be empty.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (\mb_strlen($title) > 255) {
            return new JsonResponse(['error' => 'Preset title cannot exceed 255 characters.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $locale = isset($data['locale']) && \is_string($data['locale']) ? \trim($data['locale']) : $request->getLocale();
        if (!$this->isValidLocale($locale)) {
            return new JsonResponse(['error' => 'Invalid locale format.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $description = isset($data['description']) && \is_string($data['description']) ? \trim($data['description']) : null;

        $securityCondition = $this->requirePermission($locale, PermissionTypes::ADD);

        if (!isset($this->messageBus)) {
            return new JsonResponse(['error' => 'Message bus is not configured.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        /** @var array<string, mixed> $rawProps */
        $rawProps = isset($data['props']) && \is_array($data['props']) ? $data['props'] : [];
        unset($rawProps['_preset'], $rawProps['preset']);

        /** @var array<string, mixed> $props */
        $props = [];
        foreach ($rawProps as $k => $v) {
            if (null !== $v && '' !== $v) {
                $props[(string) $k] = $v;
            }
        }

        /** @var array<string, mixed> $rawValues */
        $rawValues = isset($data['values']) && \is_array($data['values']) ? $data['values'] : [];
        unset($rawValues['type'], $rawValues['props'], $rawValues['_preset'], $rawValues['preset']);

        $blockEntry = \array_merge(
            $rawValues,
            [
                'type' => $blockType,
                'props' => $props,
            ],
        );

        /** @var array<string, mixed> $snippetData */
        $snippetData = [
            'template' => TemplateKeys::BLOCK_PRESET,
            'locale' => $locale,
            'title' => $title,
            'description' => $description,
            'block' => [$blockEntry],
        ];

        if (
            isset($data['preview_image'])
            && (\is_int($data['preview_image']) || (\is_array($data['preview_image']) && isset($data['preview_image']['id']) && \is_numeric($data['preview_image']['id'])))
        ) {
            $snippetData['preview_image'] = $data['preview_image'];
        }

        try {
            $message = new CreateSnippetMessage($snippetData);
            /** @var SnippetInterface $snippet */
            $snippet = $this->handle(new Envelope($message, [new EnableFlushStamp()]));
            $uuid = $snippet->getUuid();

            $canPublish = $this->securityChecker?->hasPermission($securityCondition, PermissionTypes::LIVE) ?? false;
            if ($canPublish) {
                $transitionMessage = new ApplyWorkflowTransitionSnippetMessage(
                    ['uuid' => $uuid],
                    $locale,
                    WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH,
                );
                $this->handle(new Envelope($transitionMessage, [new EnableFlushStamp()]));
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to save block preset.', [
                'block_type' => $blockType,
                'locale' => $locale,
                'exception' => $e,
            ]);

            return new JsonResponse(['error' => 'Failed to save block preset.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->presetProvider->clearCache($blockType, $locale);

        return new JsonResponse([
            'id' => 'snippet_' . $uuid,
            'title' => $title,
            'description' => $description,
            'preview_image' => null,
            'props' => $props,
            'values' => $blockEntry,
        ], JsonResponse::HTTP_CREATED);
    }

    #[Route('/admin/api/reusable-blocks', name: 'sulu_block_suite_save_reusable_block', methods: ['POST'])]
    public function saveReusableBlock(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = [];
        $content = $request->getContent();
        if ('' !== $content) {
            /** @var mixed $decoded */
            $decoded = \json_decode($content, true);
            if (\is_array($decoded)) {
                $data = $decoded;
            }
        }
        if ([] === $data) {
            $data = $request->request->all();
        }

        $title = isset($data['title']) && \is_string($data['title']) ? \trim($data['title']) : '';
        if ('' === $title) {
            return new JsonResponse(['error' => 'Reusable block title cannot be empty.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (\mb_strlen($title) > 255) {
            return new JsonResponse(['error' => 'Reusable block title cannot exceed 255 characters.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var array<string, mixed> $block */
        $block = isset($data['block']) && \is_array($data['block']) ? $data['block'] : [];
        if ([] === $block || !isset($block['type']) || !\is_string($block['type']) || '' === \trim($block['type'])) {
            return new JsonResponse(['error' => 'Invalid or missing block content.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $blockType = \trim($block['type']);
        if (1 !== \preg_match('/^[a-z0-9_-]+$/i', $blockType)) {
            return new JsonResponse(['error' => 'Invalid block type.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $locale = isset($data['locale']) && \is_string($data['locale']) ? \trim($data['locale']) : $request->getLocale();
        if (!$this->isValidLocale($locale)) {
            return new JsonResponse(['error' => 'Invalid locale format.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $description = isset($data['description']) && \is_string($data['description']) ? \trim($data['description']) : null;

        $securityCondition = $this->requirePermission($locale, PermissionTypes::ADD);

        if (!isset($this->messageBus)) {
            return new JsonResponse(['error' => 'Message bus is not configured.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        unset($block['_preset'], $block['preset']);
        if (isset($block['props']) && \is_array($block['props'])) {
            unset($block['props']['_preset'], $block['props']['preset']);
        }

        /** @var array<string, mixed> $snippetData */
        $snippetData = [
            'template' => TemplateKeys::REUSABLE_BLOCK,
            'locale' => $locale,
            'title' => $title,
            'description' => $description,
            'blocks' => [$block],
        ];

        if (
            isset($data['preview_image'])
            && (\is_int($data['preview_image']) || (\is_array($data['preview_image']) && isset($data['preview_image']['id']) && \is_numeric($data['preview_image']['id'])))
        ) {
            $snippetData['preview_image'] = $data['preview_image'];
        }

        try {
            $message = new CreateSnippetMessage($snippetData);
            /** @var SnippetInterface $snippet */
            $snippet = $this->handle(new Envelope($message, [new EnableFlushStamp()]));
            $uuid = $snippet->getUuid();

            $canPublish = $this->securityChecker?->hasPermission($securityCondition, PermissionTypes::LIVE) ?? false;
            if ($canPublish) {
                $transitionMessage = new ApplyWorkflowTransitionSnippetMessage(
                    ['uuid' => $uuid],
                    $locale,
                    WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH,
                );
                $this->handle(new Envelope($transitionMessage, [new EnableFlushStamp()]));
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to save reusable block.', [
                'locale' => $locale,
                'exception' => $e,
            ]);

            return new JsonResponse(['error' => 'Failed to save reusable block.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'id' => $uuid,
            'title' => $title,
            'description' => $description,
            'block_count' => 1,
        ], JsonResponse::HTTP_CREATED);
    }

    #[Route(
        '/admin/api/reusable-blocks/{id}',
        name: 'sulu_block_suite_reusable_block_info',
        requirements: ['id' => '^(?!stats$)[^/]+'],
        methods: ['GET'],
    )]
    public function reusableBlock(Request $request, string $id): JsonResponse
    {
        $locale = $request->query->getString('locale', $request->getLocale());
        if (!$this->isValidLocale($locale)) {
            return new JsonResponse(['error' => 'Invalid locale format.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $this->requirePermission($locale, PermissionTypes::VIEW);

        if ('stats' === $id || 1 !== \preg_match('/^[a-z0-9_-]+$/i', $id) || null === $this->snippetRepository) {
            return new JsonResponse(['error' => 'Not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $snippet = $this->snippetRepository->getOneBy(
                ['uuid' => $id, 'locale' => $locale],
                [SnippetRepositoryInterface::SELECT_SNIPPET_CONTENT => []],
            );

            $title = null;
            $description = null;
            $previewImage = null;
            $blockCount = 0;

            $matchedContent = null;
            foreach ($snippet->getDimensionContents() as $content) {
                if (TemplateKeys::REUSABLE_BLOCK !== $content->getTemplateKey()) {
                    continue;
                }
                if (DimensionContentInterface::CURRENT_VERSION !== $content->getVersion()) {
                    continue;
                }
                if ($content->getLocale() === $locale) {
                    if (DimensionContentInterface::STAGE_DRAFT === $content->getStage() || null === $matchedContent) {
                        $matchedContent = $content;
                    }
                } elseif (null === $content->getLocale() && null === $matchedContent) {
                    $matchedContent = $content;
                }
            }

            if (null !== $matchedContent) {
                $title = $matchedContent->getTitle();
                $templateData = $matchedContent->getTemplateData();
                $description = isset($templateData['description']) && \is_string($templateData['description'])
                    ? $templateData['description']
                    : null;

                $previewImage = $this->getMediaPreviewUrlResolver()->resolve($templateData['preview_image'] ?? null, $locale);

                if (isset($templateData['blocks']) && \is_array($templateData['blocks'])) {
                    $blockCount = \count($templateData['blocks']);
                }
            }

            return new JsonResponse([
                'id' => $snippet->getUuid(),
                'title' => $title ?? $snippet->getUuid(),
                'description' => $description,
                'preview_image' => $previewImage,
                'block_count' => $blockCount,
            ]);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Not found'], JsonResponse::HTTP_NOT_FOUND);
        }
    }

    private function requirePermission(string $locale, string $permission): SecurityCondition
    {
        if (null === $this->securityChecker) {
            throw new AccessDeniedException('SecurityChecker is required for admin block preset endpoints.');
        }

        $condition = new SecurityCondition(SnippetAdmin::SECURITY_CONTEXT, $locale);
        $this->securityChecker->checkPermission($condition, $permission);

        return $condition;
    }

    private function isValidLocale(string $locale): bool
    {
        return 1 === \preg_match('/^[a-zA-Z]{2,3}(?:[_-][a-zA-Z0-9]{2,8})*$/', $locale);
    }
}
