<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Transformer;

use Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RequestStack;
use Totoglu\SuluBlockSuiteBundle\Metadata\BlockTargetFieldMetadataResolver;

/**
 * Normalizes Sulu Form Bundle (`single_form_selection`) fields on blocks into a
 * framework-agnostic contract (`<field>_view`, `<field>_entity`, `<field>_submitted`),
 * supporting pages, articles, reusable blocks, and page layouts.
 *
 * Priority: 85 (Runs after DefaultAttributesTransformer at 90, before UI framework transformers at 50).
 */
final class FormBlockTransformer implements BlockTransformerInterface
{
    public const FORM_RESOURCE_KEY = 'forms';

    public function __construct(
        private readonly ?BlockTargetFieldMetadataResolver $fieldMetadataResolver = null,
        private readonly ?object $formBuilder = null,
        private readonly ?object $formRepository = null,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?ReferenceStoreInterface $referenceStore = null,
    ) {
    }

    public static function getDefaultPriority(): int
    {
        return 85;
    }

    public function supports(array $block, array $context): bool
    {
        if (!empty($block['_hidden'])) {
            return false;
        }

        $blockType = isset($block['type']) && \is_scalar($block['type']) ? (string) $block['type'] : '';
        $metadataFieldTypes = $this->getMetadataFieldTypes($blockType);

        foreach ($block as $field => $value) {
            if ($this->isFormField($field, $value, $metadataFieldTypes)) {
                return true;
            }
        }

        return false;
    }

    public function transform(array $block, array $context): array
    {
        $blockType = isset($block['type']) && \is_scalar($block['type']) ? (string) $block['type'] : '';
        $metadataFieldTypes = $this->getMetadataFieldTypes($blockType);
        $locale = $this->resolveLocale($context);

        foreach ($block as $field => $value) {
            if (!$this->isFormField($field, $value, $metadataFieldTypes)) {
                continue;
            }

            $formView = ($block[$field . '_view'] ?? null) instanceof FormView
                ? $block[$field . '_view']
                : null;
            /** @var array<string, mixed>|null $formEntity */
            $formEntity = isset($block[$field . '_entity']) && \is_array($block[$field . '_entity'])
                ? $block[$field . '_entity']
                : null;
            $formId = $this->extractFormId($value);

            if ($value instanceof FormView) {
                $formView = $value;
            } elseif (null === $formView && null !== $formId && $formId > 0) {
                $formView = $this->buildFormView($formId, $locale, $field);
                if (null !== $formView) {
                    $block[$field] = $formView;
                }
            }

            if (null !== $formView) {
                $extractedId = $this->extractFormIdFromView($formView);
                if (null !== $extractedId) {
                    $formId = $extractedId;
                }

                if (null === $formEntity) {
                    $formEntity = $this->extractEntityFromView($formView, $locale);
                }
            }

            if (null === $formEntity && \is_array($value)) {
                if (isset($value['entity']) && \is_array($value['entity'])) {
                    /** @var array<string, mixed> $nestedEntity */
                    $nestedEntity = $value['entity'];
                    $formEntity = $nestedEntity;
                } elseif (isset($value['title']) || isset($value['successText']) || isset($value['submitLabel'])) {
                    /** @var array<string, mixed> $directEntity */
                    $directEntity = $value;
                    $formEntity = $directEntity;
                }
            }

            if (null === $formEntity && null !== $formId && $formId > 0) {
                $formEntity = $this->loadSerializedFormEntity($formId, $locale);
            }

            if (null !== $formId && $formId > 0) {
                $this->referenceStore?->add((string) $formId, self::FORM_RESOURCE_KEY);
            }

            $isSubmitted = $this->resolveSubmittedState($block, $context, $field);

            $block[$field . '_view'] = $formView;
            $block[$field . '_entity'] = $formEntity;
            $block[$field . '_submitted'] = $isSubmitted;
        }

        return $block;
    }

    /**
     * @param array<string, string> $metadataFieldTypes
     */
    private function isFormField(string $field, mixed $value, array $metadataFieldTypes): bool
    {
        if (
            '' === $field
            || \str_starts_with($field, '_')
            || \str_starts_with($field, 'props/')
            || \str_ends_with($field, '_view')
            || \str_ends_with($field, '_entity')
            || \str_ends_with($field, '_submitted')
        ) {
            return false;
        }

        if (($metadataFieldTypes[$field] ?? null) === 'form') {
            return true;
        }

        if ($value instanceof FormView) {
            return true;
        }

        if ('form' !== $field) {
            return false;
        }

        if (\is_int($value) || (\is_string($value) && '' !== \trim($value) && \ctype_digit(\trim($value)))) {
            return true;
        }

        if (\is_array($value) && (isset($value['id']) || isset($value['formId']) || isset($value['entity']) || isset($value['successText']))) {
            return true;
        }

        return false;
    }

    private function extractFormId(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (\is_string($value) && '' !== \trim($value) && \ctype_digit(\trim($value))) {
            $id = (int) \trim($value);

            return $id > 0 ? $id : null;
        }

        if (\is_array($value)) {
            $rawId = $value['id'] ?? ($value['formId'] ?? null);
            if (\is_int($rawId) || (\is_string($rawId) && \ctype_digit($rawId))) {
                $id = (int) $rawId;

                return $id > 0 ? $id : null;
            }
        }

        return null;
    }

    private function extractFormIdFromView(FormView $formView): ?int
    {
        $dynamic = $formView->vars['value'] ?? null;
        if (\is_object($dynamic) && \method_exists($dynamic, 'getForm')) {
            /** @var mixed $formObj */
            $formObj = $dynamic->getForm();
            if (\is_object($formObj) && \method_exists($formObj, 'getId')) {
                /** @var mixed $id */
                $id = $formObj->getId();
                if (\is_int($id) && $id > 0) {
                    return $id;
                }
            }
        }

        $formIdChild = $formView->children['formId'] ?? null;
        if ($formIdChild instanceof FormView) {
            $rawId = $formIdChild->vars['value'] ?? null;
            if (\is_int($rawId) || (\is_string($rawId) && \ctype_digit($rawId))) {
                $id = (int) $rawId;

                return $id > 0 ? $id : null;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractEntityFromView(FormView $formView, string $locale): ?array
    {
        $dynamic = $formView->vars['value'] ?? null;
        if (!\is_object($dynamic) || !\method_exists($dynamic, 'getForm')) {
            return null;
        }

        try {
            /** @var mixed $formObj */
            $formObj = $dynamic->getForm();
            if (\is_object($formObj) && \method_exists($formObj, 'serializeForLocale')) {
                $effectiveLocale = \method_exists($dynamic, 'getLocale') && \is_string($dynamic->getLocale()) && '' !== $dynamic->getLocale()
                    ? $dynamic->getLocale()
                    : $locale;
                /** @var mixed $serialized */
                $serialized = $formObj->serializeForLocale($effectiveLocale, $dynamic);
                if (\is_array($serialized)) {
                    /** @var array<string, mixed> $serialized */
                    return $serialized;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function buildFormView(int $formId, string $locale, string $fieldName): ?FormView
    {
        if (null === $this->formBuilder || !\method_exists($this->formBuilder, 'build')) {
            return null;
        }

        $source = $this->resolveSource();

        try {
            /** @var mixed $form */
            $form = $this->formBuilder->build($formId, $source['type'], $source['typeId'], $locale, $fieldName);
            if ($form instanceof FormInterface) {
                return $form->createView();
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadSerializedFormEntity(int $formId, string $locale): ?array
    {
        if (null === $this->formRepository || !\method_exists($this->formRepository, 'loadById')) {
            return null;
        }

        try {
            /** @var mixed $formObj */
            $formObj = $this->formRepository->loadById($formId, $locale);
            if (\is_object($formObj) && \method_exists($formObj, 'serializeForLocale')) {
                /** @var mixed $serialized */
                $serialized = $formObj->serializeForLocale($locale);
                if (\is_array($serialized)) {
                    /** @var array<string, mixed> $serialized */
                    return $serialized;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return array{type: string, typeId: string}
     */
    private function resolveSource(): array
    {
        $request = $this->requestStack?->getMainRequest() ?? $this->requestStack?->getCurrentRequest();
        $object = $request?->attributes->get('object');

        if ($object instanceof DimensionContentInterface) {
            return [
                'type' => $object::getResourceKey(),
                'typeId' => (string) $object->getResource()->getId(),
            ];
        }

        return [
            'type' => 'pages',
            'typeId' => '',
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $context
     */
    private function resolveSubmittedState(array $block, array $context, string $field): bool
    {
        if (isset($block[$field . '_submitted']) && \is_bool($block[$field . '_submitted'])) {
            return $block[$field . '_submitted'];
        }

        if (isset($context['form_submitted']) && \is_bool($context['form_submitted'])) {
            return $context['form_submitted'];
        }

        $request = $this->requestStack?->getMainRequest() ?? $this->requestStack?->getCurrentRequest();
        if (null !== $request && 'true' === (string) $request->query->get('send', '')) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveLocale(array $context): string
    {
        if (isset($context['locale']) && \is_string($context['locale']) && '' !== \trim($context['locale'])) {
            return \trim($context['locale']);
        }

        if (
            isset($context['request'])
            && \is_array($context['request'])
            && isset($context['request']['locale'])
            && \is_string($context['request']['locale'])
            && '' !== \trim($context['request']['locale'])
        ) {
            return \trim($context['request']['locale']);
        }

        $request = $this->requestStack?->getMainRequest() ?? $this->requestStack?->getCurrentRequest();
        if (null !== $request && '' !== $request->getLocale()) {
            return $request->getLocale();
        }

        return 'en';
    }

    /**
     * @return array<string, string>
     */
    private function getMetadataFieldTypes(string $blockType): array
    {
        return null !== $this->fieldMetadataResolver
            ? $this->fieldMetadataResolver->resolveFieldTypeMap($blockType)
            : [];
    }
}
