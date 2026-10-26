<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Preview;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\Query\Expr\Join;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;
use Sulu\Bundle\PreviewBundle\Preview\PreviewContext;
use Sulu\Bundle\PreviewBundle\Preview\Provider\CachablePreviewDefaultsProviderInterface;
use Sulu\Component\Security\Authorization\AccessControl\SecuredEntityInterface;
use Sulu\Content\Application\ContentAggregator\ContentAggregatorInterface;
use Sulu\Content\Application\ContentDataMapper\ContentDataMapperInterface;
use Sulu\Content\Application\ContentNormalizer\ContentNormalizerInterface;
use Sulu\Content\Domain\Exception\ContentNotFoundException;
use Sulu\Content\Domain\Model\ContentRichEntityInterface;
use Sulu\Content\Domain\Model\DimensionContentCollection;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\ShadowInterface;
use Sulu\Content\Domain\Model\TemplateInterface;
use Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Infrastructure\Sulu\Admin\SnippetAdmin;
use Totoglu\SuluBlockSuiteBundle\Controller\Website\SnippetPreviewController;

/**
 * Preview object provider for Sulu snippets (`page_layout` and `reusable_block`),
 * supporting live form updates and reference page preview rendering.
 */
final readonly class SnippetPreviewProvider implements CachablePreviewDefaultsProviderInterface
{
    public const DEFAULT_VIEW = '@SuluBlockSuite/preview/snippet_preview';
    public const DEFAULT_CONTROLLER = SnippetPreviewController::class . '::indexAction';

    /**
     * @param class-string<SnippetInterface> $contentRichEntityClass
     */
    public function __construct(
        private MetadataProviderRegistry $metadataProviderRegistry,
        private EntityManagerInterface $entityManager,
        private ContentAggregatorInterface $contentAggregator,
        private ContentDataMapperInterface $contentDataMapper,
        private string $contentRichEntityClass,
        private ?string $securityContext = SnippetAdmin::SECURITY_CONTEXT,
        private ?ContentNormalizerInterface $contentNormalizer = null,
    ) {
    }

    public function getDefaults(PreviewContext $previewContext): array
    {
        $id = $previewContext->getId();
        $locale = $previewContext->getLocale();

        if (null === $id || null === $locale) {
            return [];
        }

        try {
            /** @var SnippetInterface $contentRichEntity */
            $contentRichEntity = $this->entityManager->createQueryBuilder()
                ->select('entity')
                ->from($this->contentRichEntityClass, 'entity')
                ->leftJoin(
                    'entity.dimensionContents',
                    'dimensionContent',
                    Join::WITH,
                    'dimensionContent.stage = :stage AND dimensionContent.version = :version AND (dimensionContent.locale IS NULL OR dimensionContent.locale = :locale)',
                )
                ->addSelect('dimensionContent')
                ->where('entity = :id')
                ->setParameter('id', $id)
                ->setParameter('locale', $locale)
                ->setParameter('stage', DimensionContentInterface::STAGE_DRAFT)
                ->setParameter('version', DimensionContentInterface::CURRENT_VERSION)
                ->getQuery()
                ->getSingleResult();
        } catch (NoResultException) {
            return [];
        }

        $object = $this->resolveContent($contentRichEntity, $locale);
        if (!$object instanceof TemplateInterface) {
            return [];
        }

        $templateKey = $object->getTemplateKey();
        if (!$templateKey) {
            return [];
        }

        [$view, $controller] = $this->resolveViewAndController($object::getTemplateType(), $templateKey, $locale);

        return [
            'object' => $object,
            'view' => $view,
            '_controller' => $controller,
        ];
    }

    public function updateValues(PreviewContext $previewContext, array $defaults, array $data): array
    {
        $object = $defaults['object'] ?? null;
        if (!$object instanceof DimensionContentInterface) {
            throw new \RuntimeException('Object must be instance of DimensionContentInterface.');
        }

        $locale = $previewContext->getLocale();
        if (null === $locale) {
            throw new \RuntimeException('The SnippetPreviewProvider requires a locale to be set in the PreviewContext.');
        }

        $dimensionContents = $object->getResource()->getDimensionContents();
        $dimensionContentCollection = new DimensionContentCollection(
            $dimensionContents,
            [
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ],
            $object::class,
        );

        $this->contentDataMapper->map(
            $dimensionContentCollection,
            $dimensionContentCollection->getDimensionAttributes(),
            $data,
        );

        $defaults['object'] = $this->contentAggregator->aggregate(
            $object->getResource(),
            $dimensionContentCollection->getDimensionAttributes(),
        );

        return $defaults;
    }

    public function updateContext(PreviewContext $previewContext, array $defaults, array $context): array
    {
        $object = $defaults['object'] ?? null;
        if (!$object instanceof DimensionContentInterface) {
            throw new \RuntimeException('Object must be instance of DimensionContentInterface.');
        }

        if ($object instanceof TemplateInterface && \array_key_exists('template', $context) && \is_string($context['template'])) {
            $object->setTemplateKey($context['template']);
        }

        return $defaults;
    }

    public function serialize(PreviewContext $previewContext, array $defaults): string
    {
        $object = $defaults['object'] ?? null;
        if (null === $this->contentNormalizer || !$object instanceof DimensionContentInterface) {
            return '';
        }

        return \json_encode($this->contentNormalizer->normalize($object), \JSON_THROW_ON_ERROR);
    }

    public function deserialize(PreviewContext $previewContext, string $serializedDefaults): array
    {
        $defaults = $this->getDefaults($previewContext);
        if ([] === $defaults || '' === $serializedDefaults) {
            return $defaults;
        }

        /** @var array<string, mixed> $data */
        $data = \json_decode($serializedDefaults, true, 512, \JSON_THROW_ON_ERROR);

        return $this->updateValues($previewContext, $defaults, $data);
    }

    public function getSecurityContext(PreviewContext $previewContext): ?string
    {
        if (null !== $this->securityContext) {
            return $this->securityContext;
        }

        $id = $previewContext->getId();
        if (null === $id) {
            return null;
        }

        $contentRichEntity = $this->entityManager->find($this->contentRichEntityClass, $id);

        return $contentRichEntity instanceof SecuredEntityInterface
            ? $contentRichEntity->getSecurityContext()
            : null;
    }

    /**
     * @param ContentRichEntityInterface<SnippetDimensionContentInterface> $contentRichEntity
     *
     * @return DimensionContentInterface<SnippetInterface>|null
     */
    private function resolveContent(
        ContentRichEntityInterface $contentRichEntity,
        string $locale,
    ): ?DimensionContentInterface {
        try {
            $resolvedDimensionContent = $this->contentAggregator->aggregate(
                $contentRichEntity,
                [
                    'locale' => $locale,
                    'stage' => DimensionContentInterface::STAGE_DRAFT,
                ],
            );

            if ($resolvedDimensionContent instanceof ShadowInterface && $resolvedDimensionContent->getShadowLocale()) {
                return $this->resolveContent($contentRichEntity, $resolvedDimensionContent->getShadowLocale());
            }

            if (!$resolvedDimensionContent->getLocale()) {
                $resolvedDimensionContent->setLocale($locale);
            }

            return $resolvedDimensionContent;
        } catch (ContentNotFoundException) {
            return null;
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveViewAndController(string $type, string $templateKey, string $locale): array
    {
        $view = self::DEFAULT_VIEW;
        $controller = self::DEFAULT_CONTROLLER;

        try {
            $typedMetadata = $this->metadataProviderRegistry->getMetadataProvider('form')
                ->getMetadata($type, $locale, []);

            if ($typedMetadata instanceof TypedFormMetadata) {
                $metadata = $typedMetadata->getForms()[$templateKey] ?? null;
                if ($metadata instanceof FormMetadata) {
                    $templateMetadata = $metadata->getTemplate();
                    if (null !== $templateMetadata) {
                        $configuredView = \trim((string) $templateMetadata->getView());
                        $configuredController = \trim((string) $templateMetadata->getController());
                        if ('' !== $configuredView && '' !== $configuredController) {
                            $view = $configuredView;
                            $controller = $configuredController;
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Fall back to default snippet preview view and controller
        }

        return [$view, $controller];
    }
}
