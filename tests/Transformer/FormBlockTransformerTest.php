<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Transformer;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Totoglu\SuluBlockSuiteBundle\Transformer\FormBlockTransformer;

final class FormBlockTransformerTest extends TestCase
{
    public function testExtractsFormViewEntityAndRecordsCacheReference(): void
    {
        $referenceStore = $this->createMock(ReferenceStoreInterface::class);
        $referenceStore->expects($this->once())
            ->method('add')
            ->with('7', 'forms');

        $formEntity = new class {
            public function getId(): int
            {
                return 7;
            }

            /**
             * @return array<string, mixed>
             */
            public function serializeForLocale(string $locale, mixed $dynamic): array
            {
                return [
                    'id' => 7,
                    'formId' => 7,
                    'locale' => $locale,
                    'title' => 'Contact Form',
                    'submitLabel' => 'Send Message',
                    'successText' => '<p>Thank you for contacting us!</p>',
                ];
            }
        };

        $dynamic = new class($formEntity) {
            public function __construct(private readonly object $form)
            {
            }

            public function getForm(): object
            {
                return $this->form;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };

        $formView = new FormView();
        $formView->vars['value'] = $dynamic;

        $transformer = new FormBlockTransformer(
            fieldMetadataResolver: null,
            formBuilder: null,
            formRepository: null,
            requestStack: null,
            referenceStore: $referenceStore,
        );

        $transformed = $transformer->transform([
            'type' => 'form',
            'form' => $formView,
        ], ['locale' => 'en']);

        $this->assertSame($formView, $transformed['form_view']);
        $this->assertFalse($transformed['form_submitted']);
        $this->assertIsArray($transformed['form_entity']);
        $this->assertSame(7, $transformed['form_entity']['id']);
        $this->assertSame('Contact Form', $transformed['form_entity']['title']);
        $this->assertSame('<p>Thank you for contacting us!</p>', $transformed['form_entity']['successText']);
    }

    public function testResolvesNumericFormIdViaFormBuilderAndDetectsSubmittedQuery(): void
    {
        $formView = new FormView();
        $symfonyForm = $this->createMock(FormInterface::class);
        $symfonyForm->method('createView')->willReturn($formView);

        $formBuilder = new class($symfonyForm) {
            /**
             * @param FormInterface<mixed> $form
             */
            public function __construct(private readonly FormInterface $form)
            {
            }

            /**
             * @return FormInterface<mixed>
             */
            public function build(int $id, string $type, string $typeId, ?string $locale, string $name): FormInterface
            {
                return $this->form;
            }
        };

        $formEntity = new class {
            public function getId(): int
            {
                return 12;
            }

            /**
             * @return array<string, mixed>
             */
            public function serializeForLocale(string $locale): array
            {
                return [
                    'id' => 12,
                    'title' => 'Newsletter Signup',
                    'successText' => '<p>Subscribed!</p>',
                ];
            }
        };

        $formRepository = new class($formEntity) {
            public function __construct(private readonly object $entity)
            {
            }

            public function loadById(int $id, ?string $locale = null): object
            {
                return $this->entity;
            }
        };

        $requestStack = new RequestStack();
        $requestStack->push(new Request(['send' => 'true']));

        $referenceStore = $this->createMock(ReferenceStoreInterface::class);
        $referenceStore->expects($this->once())
            ->method('add')
            ->with('12', 'forms');

        $transformer = new FormBlockTransformer(
            fieldMetadataResolver: null,
            formBuilder: $formBuilder,
            formRepository: $formRepository,
            requestStack: $requestStack,
            referenceStore: $referenceStore,
        );

        $transformed = $transformer->transform([
            'type' => 'form',
            'form' => 12,
        ], ['locale' => 'tr']);

        $this->assertSame($formView, $transformed['form']);
        $this->assertSame($formView, $transformed['form_view']);
        $this->assertTrue($transformed['form_submitted']);
        $this->assertIsArray($transformed['form_entity']);
        $this->assertSame(12, $transformed['form_entity']['id']);
        $this->assertSame('Newsletter Signup', $transformed['form_entity']['title']);
    }
}
