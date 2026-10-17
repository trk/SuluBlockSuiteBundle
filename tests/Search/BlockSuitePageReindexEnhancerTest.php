<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Search;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Totoglu\SuluBlockSuiteBundle\Search\BlockSuitePageReindexEnhancer;

final class BlockSuitePageReindexEnhancerTest extends TestCase
{
    public function testEnhanceDocumentExtractsTextViaFormMetadataAndRecursesIntoNestedBlockFieldsAndReusableSnippets(): void
    {
        $snippetDimension = $this->createStub(SnippetDimensionContentInterface::class);
        $snippetDimension->method('getLocale')->willReturn('en');
        $snippetDimension->method('getStage')->willReturn(DimensionContentInterface::STAGE_LIVE);
        $snippetDimension->method('getVersion')->willReturn(DimensionContentInterface::CURRENT_VERSION);
        $snippetDimension->method('getTemplateKey')->willReturn('reusable_block');
        $snippetDimension->method('getTemplateData')->willReturn([
            'title' => 'Global Banner Reusable Title',
            'blocks' => [
                [
                    'type' => 'fixture_headline',
                    'heading_text' => 'Reusable CTA Headline Text',
                    'body_html' => '<p>Reusable rich text description</p>',
                ],
            ],
        ]);

        $snippet = $this->createStub(SnippetInterface::class);
        $snippet->method('getDimensionContents')->willReturn(new ArrayCollection([$snippetDimension]));

        $snippetRepository = $this->createMock(SnippetRepositoryInterface::class);
        $snippetRepository->expects(self::once())
            ->method('findOneBy')
            ->with(
                [
                    'uuid' => 'reusable-uuid-001',
                    'locale' => 'en',
                    'stage' => DimensionContentInterface::STAGE_LIVE,
                ],
                [SnippetRepositoryInterface::SELECT_SNIPPET_CONTENT => []],
            )
            ->willReturn($snippet);

        $registry = $this->createFixtureMetadataRegistry();
        $enhancer = new BlockSuitePageReindexEnhancer($snippetRepository, $registry);

        $pageData = [
            'locale' => 'en',
            'templateKey' => 'fixture_page',
            'templateData' => [
                'page_lead' => 'Top level lead paragraph.',
                'featured_media' => ['id' => 77],
                'layout_blocks' => [
                    [
                        'type' => 'fixture_container',
                        'nested_rows' => [
                            [
                                'type' => 'fixture_row',
                                'nested_cols' => [
                                    [
                                        'type' => 'fixture_col',
                                        'inner_blocks' => [
                                            [
                                                'type' => 'fixture_card',
                                                'card_heading' => 'Enterprise AI Solutions',
                                                'card_kicker' => 'Cloud & Architecture',
                                                'card_body' => '<p>High performance <strong>scalable</strong> architecture.</p>',
                                                'non_indexed_select' => 'elevated-variant',
                                                'props' => [
                                                    'card_style' => 'card-default',
                                                    'name' => 'Internal Card Name Should Not Be Indexed',
                                                    'css' => '.el-element { color: red; }',
                                                ],
                                                'cta_elements' => [
                                                    [
                                                        'type' => 'fixture_button',
                                                        'button_label' => 'Explore Platform',
                                                    ],
                                                ],
                                            ],
                                            [
                                                'type' => 'fixture_accordion',
                                                'entries' => [
                                                    [
                                                        'type' => 'fixture_accordion_entry',
                                                        'question_text' => 'How does Seal indexing work?',
                                                        'answer_html' => '<p>It indexes nested layout blocks seamlessly.</p>',
                                                    ],
                                                ],
                                            ],
                                            [
                                                'type' => 'reusable',
                                                'snippet' => 'reusable-uuid-001',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $document = [
            'id' => 'page-1',
            'title' => 'Homepage',
            'content' => ['Existing root text'],
        ];

        $enhanced = $enhancer->enhanceDocument($pageData, $document);

        self::assertSame('77', $enhanced['mediaId'] ?? null);
        self::assertIsArray($enhanced['content']);
        /** @var list<string> $content */
        $content = $enhanced['content'];

        self::assertContains('Existing root text', $content);
        self::assertContains('Top level lead paragraph.', $content);
        self::assertContains('Enterprise AI Solutions', $content);
        self::assertContains('Cloud & Architecture', $content);
        self::assertContains('High performance scalable architecture.', $content);
        self::assertContains('Explore Platform', $content);
        self::assertContains('How does Seal indexing work?', $content);
        self::assertContains('It indexes nested layout blocks seamlessly.', $content);
        self::assertContains('Global Banner Reusable Title', $content);
        self::assertContains('Reusable CTA Headline Text', $content);
        self::assertContains('Reusable rich text description', $content);

        // Non-text fields (e.g. single_select) and props must not be indexed
        self::assertNotContains('elevated-variant', $content);
        self::assertNotContains('card-default', $content);
        self::assertNotContains('Internal Card Name Should Not Be Indexed', $content);
        self::assertNotContains('.el-element { color: red; }', $content);
    }

    public function testEnhanceDocumentFallsBackStructurallyWhenMetadataRegistryIsUnavailable(): void
    {
        $enhancer = new BlockSuitePageReindexEnhancer();

        $queryResult = [
            'locale' => 'en',
            'templateData' => [
                'headline' => 'Top Level Display Headline',
                'summary' => 'Top level plain summary text.',
                'content' => '<p>Top level <strong>rich body</strong> content.</p>',
                'image' => ['id' => 42],
                'blocks' => [
                    [
                        'type' => 'fixture_card',
                        'title' => 'Fallback Block Title',
                        'props' => [
                            'name' => 'Hidden Prop Value',
                        ],
                    ],
                ],
            ],
        ];

        $document = [
            'id' => 'page-top-1',
            'title' => 'Page Title',
            'content' => [],
        ];

        $enhanced = $enhancer->enhanceDocument($queryResult, $document);

        self::assertSame('42', $enhanced['mediaId'] ?? null);
        self::assertIsArray($enhanced['content']);
        /** @var list<string> $content */
        $content = $enhanced['content'];
        self::assertContains('Top Level Display Headline', $content);
        self::assertContains('Top level plain summary text.', $content);
        self::assertContains('Top level rich body content.', $content);
        self::assertContains('Fallback Block Title', $content);
        self::assertNotContains('Hidden Prop Value', $content);
    }

    public function testEnhanceDocumentSkipsDraftReusableSnippet(): void
    {
        $draftDimension = $this->createStub(SnippetDimensionContentInterface::class);
        $draftDimension->method('getLocale')->willReturn('en');
        $draftDimension->method('getStage')->willReturn(DimensionContentInterface::STAGE_DRAFT);
        $draftDimension->method('getTemplateData')->willReturn([
            'title' => 'Unpublished Draft Snippet Title',
            'blocks' => [
                ['type' => 'fixture_headline', 'heading_text' => 'Unpublished Draft Headline'],
            ],
        ]);

        $snippet = $this->createStub(SnippetInterface::class);
        $snippet->method('getDimensionContents')->willReturn(new ArrayCollection([$draftDimension]));

        $snippetRepository = $this->createStub(SnippetRepositoryInterface::class);
        $snippetRepository->method('findOneBy')->willReturn($snippet);

        $enhancer = new BlockSuitePageReindexEnhancer($snippetRepository, $this->createFixtureMetadataRegistry());
        $enhanced = $enhancer->enhanceDocument([
            'locale' => 'en',
            'templateKey' => 'fixture_page',
            'templateData' => [
                'layout_blocks' => [
                    ['type' => 'reusable', 'snippet' => 'draft-snippet-uuid'],
                ],
            ],
        ], ['id' => 'page-1', 'content' => []]);

        /** @var list<string> $content */
        $content = $enhanced['content'];
        self::assertNotContains('Unpublished Draft Snippet Title', $content);
        self::assertNotContains('Unpublished Draft Headline', $content);
    }

    private function createFixtureMetadataRegistry(): MetadataProviderRegistry
    {
        // Page form metadata
        $pageForm = new FormMetadata();
        $pageForm->setKey('fixture_page');
        $pageForm->addItem($this->createField('page_lead', 'text_area'));
        $pageForm->addItem($this->createField('featured_media', 'single_media_selection'));
        $pageForm->addItem($this->createField('layout_blocks', 'block'));

        $typedPageMetadata = new TypedFormMetadata();
        $typedPageMetadata->addForm('fixture_page', $pageForm);

        // Snippet form metadata
        $snippetForm = new FormMetadata();
        $snippetForm->setKey('reusable_block');
        $snippetForm->addItem($this->createField('title', 'text_line'));
        $snippetForm->addItem($this->createField('blocks', 'block'));

        $typedSnippetMetadata = new TypedFormMetadata();
        $typedSnippetMetadata->addForm('reusable_block', $snippetForm);

        // Block forms metadata
        $typedBlockMetadata = new TypedFormMetadata();

        $headlineForm = new FormMetadata();
        $headlineForm->setKey('fixture_headline');
        $headlineForm->addItem($this->createField('heading_text', 'text_line'));
        $headlineForm->addItem($this->createField('body_html', 'text_editor'));
        $typedBlockMetadata->addForm('fixture_headline', $headlineForm);

        $buttonForm = new FormMetadata();
        $buttonForm->setKey('fixture_button');
        $buttonForm->addItem($this->createField('button_label', 'text_line'));
        $typedBlockMetadata->addForm('fixture_button', $buttonForm);

        $cardForm = new FormMetadata();
        $cardForm->setKey('fixture_card');
        $cardTabs = new SectionMetadata('__tabs');
        $cardContent = new SectionMetadata('content');
        $cardContent->addItem($this->createField('card_heading', 'text_line'));
        $cardContent->addItem($this->createField('card_kicker', 'text_area'));
        $cardContent->addItem($this->createField('card_body', 'text_editor'));
        $cardContent->addItem($this->createField('non_indexed_select', 'single_select'));
        $ctaElements = $this->createField('cta_elements', 'block');
        $ctaElements->addType($buttonForm);
        $cardContent->addItem($ctaElements);
        $cardSettings = new SectionMetadata('settings');
        $cardSettings->addItem($this->createField('props/card_style', 'single_select'));
        $cardTabs->addItem($cardContent);
        $cardTabs->addItem($cardSettings);
        $cardForm->addItem($cardTabs);
        $typedBlockMetadata->addForm('fixture_card', $cardForm);

        $accordionEntryForm = new FormMetadata();
        $accordionEntryForm->setKey('fixture_accordion_entry');
        $accordionEntryForm->addItem($this->createField('question_text', 'text_line'));
        $accordionEntryForm->addItem($this->createField('answer_html', 'text_editor'));

        $accordionForm = new FormMetadata();
        $accordionForm->setKey('fixture_accordion');
        $entriesField = $this->createField('entries', 'block');
        $entriesField->addType($accordionEntryForm);
        $accordionForm->addItem($entriesField);
        $typedBlockMetadata->addForm('fixture_accordion', $accordionForm);

        $reusableForm = new FormMetadata();
        $reusableForm->setKey('reusable');
        $reusableForm->addItem($this->createField('snippet', 'single_snippet_selection'));
        $typedBlockMetadata->addForm('reusable', $reusableForm);

        $colForm = new FormMetadata();
        $colForm->setKey('fixture_col');
        $colForm->addItem($this->createField('inner_blocks', 'block'));

        $rowForm = new FormMetadata();
        $rowForm->setKey('fixture_row');
        $colsField = $this->createField('nested_cols', 'block');
        $colsField->addType($colForm);
        $rowForm->addItem($colsField);

        $containerForm = new FormMetadata();
        $containerForm->setKey('fixture_container');
        $rowsField = $this->createField('nested_rows', 'block');
        $rowsField->addType($rowForm);
        $containerForm->addItem($rowsField);
        $typedBlockMetadata->addForm('fixture_container', $containerForm);

        $provider = new class($typedPageMetadata, $typedBlockMetadata, $typedSnippetMetadata) implements MetadataProviderInterface {
            public function __construct(
                private readonly TypedFormMetadata $pageMetadata,
                private readonly TypedFormMetadata $blockMetadata,
                private readonly TypedFormMetadata $snippetMetadata,
            ) {
            }

            /**
             * @param array<string, mixed> $metadataOptions
             */
            public function getMetadata(string $key, string $locale, array $metadataOptions = []): \Sulu\Bundle\AdminBundle\Metadata\MetadataInterface
            {
                return match ($key) {
                    'page' => $this->pageMetadata,
                    'block' => $this->blockMetadata,
                    'snippet' => $this->snippetMetadata,
                    default => new TypedFormMetadata(),
                };
            }
        };

        return new MetadataProviderRegistry(new ServiceLocator([
            'form' => static fn (): MetadataProviderInterface => $provider,
        ]));
    }

    private function createField(string $name, string $type): FieldMetadata
    {
        $field = new FieldMetadata($name);
        $field->setType($type);

        return $field;
    }
}
