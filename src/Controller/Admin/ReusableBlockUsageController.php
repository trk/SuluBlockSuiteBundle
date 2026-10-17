<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Controller\Admin;

use Sulu\Bundle\ReferenceBundle\Domain\Repository\ReferenceRepositoryInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Security\Authorization\SecurityCondition;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Infrastructure\Sulu\Admin\SnippetAdmin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReusableBlockUsageController extends AbstractController
{
    public function __construct(
        private readonly ?ReferenceRepositoryInterface $referenceRepository = null,
        private readonly ?SecurityCheckerInterface $securityChecker = null,
    ) {
    }

    #[Route('/admin/api/reusable-blocks/{id}/usage', name: 'sulu_block_suite_reusable_block_usage', methods: ['GET'])]
    public function usage(Request $request, string $id): JsonResponse
    {
        $locale = $request->query->getString('locale') ?: null;
        if (
            null === $this->securityChecker
            || !$this->securityChecker->hasPermission(new SecurityCondition(SnippetAdmin::SECURITY_CONTEXT, $locale), PermissionTypes::VIEW)
        ) {
            return new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        if (1 !== \preg_match('/^[a-z0-9_-]+$/i', $id)) {
            return new JsonResponse(['error' => 'Invalid ID format'], Response::HTTP_BAD_REQUEST);
        }

        if (null === $this->referenceRepository) {
            return new JsonResponse([
                'id' => $id,
                'usage_count' => 0,
                'is_used' => false,
                'references' => [],
            ]);
        }

        $filters = [
            'resourceKey' => SnippetInterface::RESOURCE_KEY,
            'resourceId' => $id,
        ];

        $fields = [
            'referenceTitle',
            'referenceResourceKey',
            'referenceResourceId',
            'referenceLocale',
            'referenceRouterAttributes',
            'referenceProperty',
        ];

        /** @var iterable<array<string, mixed>> $rawReferences */
        $rawReferences = $this->referenceRepository->findFlatBy($filters, [], $fields, distinct: true);
        $totalCount = $this->referenceRepository->count($filters, ['referenceResourceId']);

        $adminBase = \rtrim($request->getBasePath(), '/') . '/admin';
        $references = [];
        foreach ($rawReferences as $row) {
            $resourceKey = isset($row['referenceResourceKey']) && \is_string($row['referenceResourceKey']) ? $row['referenceResourceKey'] : '';
            $resourceId = isset($row['referenceResourceId']) && \is_string($row['referenceResourceId']) ? $row['referenceResourceId'] : '';
            $locale = isset($row['referenceLocale']) && \is_string($row['referenceLocale']) ? $row['referenceLocale'] : 'en';
            $title = isset($row['referenceTitle']) && \is_string($row['referenceTitle']) ? $row['referenceTitle'] : $resourceId;
            $property = isset($row['referenceProperty']) && \is_string($row['referenceProperty']) ? $row['referenceProperty'] : '';
            $routerAttributes = isset($row['referenceRouterAttributes']) && \is_array($row['referenceRouterAttributes'])
                ? $row['referenceRouterAttributes']
                : [];

            $references[] = [
                'resource_key' => $resourceKey,
                'resource_id' => $resourceId,
                'title' => $title,
                'locale' => $locale,
                'property' => $property,
                'router_attributes' => $routerAttributes,
                'edit_url' => $this->resolveAdminEditUrl($adminBase, $resourceKey, $resourceId, $locale),
            ];
        }

        return new JsonResponse([
            'id' => $id,
            'usage_count' => $totalCount,
            'is_used' => $totalCount > 0,
            'references' => $references,
        ]);
    }

    #[Route('/admin/api/reusable-blocks/stats', name: 'sulu_block_suite_reusable_block_stats', methods: ['GET'])]
    public function stats(Request $request): JsonResponse
    {
        $locale = $request->query->getString('locale') ?: null;
        if (
            null === $this->securityChecker
            || !$this->securityChecker->hasPermission(new SecurityCondition(SnippetAdmin::SECURITY_CONTEXT, $locale), PermissionTypes::VIEW)
        ) {
            return new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $idsString = $request->query->getString('ids', '');
        $rawIds = \explode(',', $idsString);
        $ids = [];
        foreach ($rawIds as $rawId) {
            $trimmed = \trim($rawId);
            if ('' !== $trimmed && 1 === \preg_match('/^[a-z0-9_-]+$/i', $trimmed)) {
                $ids[$trimmed] = $trimmed;
                if (\count($ids) >= 50) {
                    break;
                }
            }
        }
        $ids = \array_values($ids);

        if ([] === $ids || null === $this->referenceRepository) {
            return new JsonResponse(['counts' => []]);
        }

        $counts = [];
        foreach ($ids as $id) {
            $filters = [
                'resourceKey' => SnippetInterface::RESOURCE_KEY,
                'resourceId' => $id,
            ];
            $counts[$id] = $this->referenceRepository->count($filters, ['referenceResourceId']);
        }

        return new JsonResponse(['counts' => $counts]);
    }

    private function resolveAdminEditUrl(string $adminBase, string $resourceKey, string $resourceId, string $locale): ?string
    {
        if ('' === $resourceId || '' === $resourceKey) {
            return null;
        }

        return match ($resourceKey) {
            'pages' => \sprintf('%s/#/pages/%s/%s/content', $adminBase, $locale, $resourceId),
            'snippets' => \sprintf('%s/#/snippets/%s/%s/content', $adminBase, $locale, $resourceId),
            'articles' => \sprintf('%s/#/articles/%s/%s/content', $adminBase, $locale, $resourceId),
            default => null,
        };
    }
}
