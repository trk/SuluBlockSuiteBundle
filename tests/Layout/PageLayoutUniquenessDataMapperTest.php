<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Layout;

use PHPUnit\Framework\TestCase;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetDimensionContentInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Totoglu\SuluBlockSuiteBundle\Layout\PageLayoutUniquenessDataMapper;
use Totoglu\SuluBlockSuiteBundle\Template\TemplateKeys;

final class PageLayoutUniquenessDataMapperTest extends TestCase
{
    public function testAllowsSavingPageLayoutWhenNoDuplicateExists(): void
    {
        $existingSnippet = $this->createPageLayoutSnippet(
            uuid: 'snippet-1',
            webspace: 'totoglu',
            targetTemplate: 'default',
            title: 'Default Layout',
        );

        $repository = $this->createMock(SnippetRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findBy')
            ->willReturn([$existingSnippet]);

        $mapper = new PageLayoutUniquenessDataMapper($repository);

        [$unlocalized, $localized] = $this->createTargetDimensions('snippet-2');

        $mapper->map($unlocalized, $localized, [
            'template' => TemplateKeys::PAGE_LAYOUT,
            'title' => 'Homepage Layout',
            'webspace' => 'totoglu',
            'target_template' => 'homepage',
        ]);
    }

    public function testAllowsUpdatingSamePageLayoutSnippetWithSameWebspaceAndTemplate(): void
    {
        $existingSnippet = $this->createPageLayoutSnippet(
            uuid: 'snippet-1',
            webspace: 'totoglu',
            targetTemplate: 'homepage',
            title: 'Homepage Layout',
        );

        $repository = $this->createMock(SnippetRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findBy')
            ->willReturn([$existingSnippet]);

        $mapper = new PageLayoutUniquenessDataMapper($repository);

        [$unlocalized, $localized] = $this->createTargetDimensions('snippet-1');

        $mapper->map($unlocalized, $localized, [
            'template' => TemplateKeys::PAGE_LAYOUT,
            'title' => 'Homepage Layout Updated',
            'webspace' => 'totoglu',
            'target_template' => 'homepage',
        ]);
    }

    public function testThrowsConflictHttpExceptionWhenSameTemplateAlreadyAssignedInSameWebspace(): void
    {
        $existingSnippet = $this->createPageLayoutSnippet(
            uuid: 'snippet-1',
            webspace: 'totoglu',
            targetTemplate: 'homepage',
            title: 'Main Homepage Layout',
        );

        $repository = $this->createMock(SnippetRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findBy')
            ->willReturn([$existingSnippet]);

        $mapper = new PageLayoutUniquenessDataMapper($repository);

        [$unlocalized, $localized] = $this->createTargetDimensions('snippet-2');

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('A Page Layout for webspace "totoglu" and template "homepage" already exists ("Main Homepage Layout").');

        $mapper->map($unlocalized, $localized, [
            'template' => TemplateKeys::PAGE_LAYOUT,
            'title' => 'Second Homepage Layout',
            'webspace' => 'totoglu',
            'target_template' => 'homepage',
        ]);
    }

    public function testAllowsSameTemplateAcrossDifferentWebspaces(): void
    {
        $existingSnippet = $this->createPageLayoutSnippet(
            uuid: 'snippet-1',
            webspace: 'totoglu',
            targetTemplate: 'homepage',
            title: 'Totoglu Homepage Layout',
        );

        $repository = $this->createMock(SnippetRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findBy')
            ->willReturn([$existingSnippet]);

        $mapper = new PageLayoutUniquenessDataMapper($repository);

        [$unlocalized, $localized] = $this->createTargetDimensions('snippet-2');

        $mapper->map($unlocalized, $localized, [
            'template' => TemplateKeys::PAGE_LAYOUT,
            'title' => 'Turkiye Homepage Layout',
            'webspace' => 'turkiye',
            'target_template' => 'homepage',
        ]);
    }

    public function testAllowsMultiplePageLayoutsInSameWebspaceWhenTargetTemplateIsEmpty(): void
    {
        $repository = $this->createMock(SnippetRepositoryInterface::class);
        $repository->expects(self::never())->method('findBy');

        $mapper = new PageLayoutUniquenessDataMapper($repository);

        [$unlocalized, $localized] = $this->createTargetDimensions('snippet-2');

        $mapper->map($unlocalized, $localized, [
            'template' => TemplateKeys::PAGE_LAYOUT,
            'title' => 'Custom Layout Without Template',
            'webspace' => 'totoglu',
            'target_template' => '',
        ]);
    }

    public function testAutoGeneratesTitleFromWebspaceAndTargetTemplateWhenTitleIsOmitted(): void
    {
        $repository = $this->createMock(SnippetRepositoryInterface::class);
        $repository->expects(self::once())->method('findBy')->willReturn([]);

        $mapper = new PageLayoutUniquenessDataMapper($repository);

        $snippet = $this->createStub(SnippetInterface::class);
        $snippet->method('getUuid')->willReturn('snippet-auto-title');

        $unlocalized = $this->createStub(SnippetDimensionContentInterface::class);
        $unlocalized->method('getResource')->willReturn($snippet);
        $unlocalized->method('getTemplateData')->willReturn([
            'webspace' => 'totoglu',
            'target_template' => 'homepage',
        ]);

        $capturedData = null;
        $localized = $this->createMock(SnippetDimensionContentInterface::class);
        $localized->method('getResource')->willReturn($snippet);
        $localized->method('getLocale')->willReturn('en');
        $localized->method('getTemplateKey')->willReturn(TemplateKeys::PAGE_LAYOUT);
        $localized->method('getTemplateData')->willReturn(['blocks' => []]);
        $localized->expects(self::once())
            ->method('setTemplateData')
            ->willReturnCallback(static function (array $data) use (&$capturedData): void {
                $capturedData = $data;
            });

        $mapper->map($unlocalized, $localized, [
            'template' => TemplateKeys::PAGE_LAYOUT,
            'webspace' => 'totoglu',
            'target_template' => 'homepage',
        ]);

        self::assertIsArray($capturedData);
        self::assertSame('totoglu — homepage', $capturedData['title'] ?? null);
    }

    public function testEnforcesGlobalUniquenessAndOmitsWebspacePrefixFromTitleWhenMultiWebspaceIsDisabled(): void
    {
        $existingSnippet = $this->createPageLayoutSnippet(
            uuid: 'snippet-existing-1',
            webspace: 'totoglu',
            targetTemplate: 'homepage',
            title: 'Homepage Layout',
        );

        $repository = $this->createStub(SnippetRepositoryInterface::class);
        $repository->method('findBy')->willReturn([$existingSnippet]);

        $mapper = new PageLayoutUniquenessDataMapper(
            snippetRepository: $repository,
            multiWebspaceEnabled: false,
        );

        $snippet = $this->createStub(SnippetInterface::class);
        $snippet->method('getUuid')->willReturn('snippet-new');

        $unlocalized = $this->createStub(SnippetDimensionContentInterface::class);
        $unlocalized->method('getResource')->willReturn($snippet);
        $unlocalized->method('getTemplateData')->willReturn([]);

        $capturedData = null;
        $localized = $this->createMock(SnippetDimensionContentInterface::class);
        $localized->method('getResource')->willReturn($snippet);
        $localized->method('getLocale')->willReturn('en');
        $localized->method('getTemplateKey')->willReturn(TemplateKeys::PAGE_LAYOUT);
        $localized->method('getTemplateData')->willReturn(['blocks' => []]);
        $localized->expects(self::once())
            ->method('setTemplateData')
            ->willReturnCallback(static function (array $data) use (&$capturedData): void {
                $capturedData = $data;
            });

        // Different template succeeds and generates title without webspace prefix
        $mapper->map($unlocalized, $localized, [
            'template' => TemplateKeys::PAGE_LAYOUT,
            'webspace' => 'turkiye',
            'target_template' => 'default',
        ]);

        self::assertIsArray($capturedData);
        self::assertSame('default', $capturedData['title'] ?? null);

        // Same template throws ConflictHttpException even if a different webspace was passed
        $this->expectException(ConflictHttpException::class);
        $mapper->map($unlocalized, $localized, [
            'template' => TemplateKeys::PAGE_LAYOUT,
            'webspace' => 'turkiye',
            'target_template' => 'homepage',
        ]);
    }

    /**
     * @return array{0: SnippetDimensionContentInterface, 1: SnippetDimensionContentInterface}
     */
    private function createTargetDimensions(string $uuid): array
    {
        $snippet = $this->createStub(SnippetInterface::class);
        $snippet->method('getUuid')->willReturn($uuid);

        $unlocalized = $this->createStub(SnippetDimensionContentInterface::class);
        $unlocalized->method('getResource')->willReturn($snippet);
        $unlocalized->method('getTemplateData')->willReturn([]);

        $localized = $this->createStub(SnippetDimensionContentInterface::class);
        $localized->method('getResource')->willReturn($snippet);
        $localized->method('getLocale')->willReturn('en');
        $localized->method('getTemplateKey')->willReturn(TemplateKeys::PAGE_LAYOUT);
        $localized->method('getTemplateData')->willReturn([]);

        return [$unlocalized, $localized];
    }

    private function createPageLayoutSnippet(
        string $uuid,
        string $webspace,
        string $targetTemplate,
        string $title,
    ): SnippetInterface {
        $snippet = $this->createStub(SnippetInterface::class);
        $snippet->method('getUuid')->willReturn($uuid);

        $unlocalizedDim = $this->createStub(SnippetDimensionContentInterface::class);
        $unlocalizedDim->method('getStage')->willReturn(DimensionContentInterface::STAGE_DRAFT);
        $unlocalizedDim->method('getLocale')->willReturn(null);
        $unlocalizedDim->method('getVersion')->willReturn(DimensionContentInterface::CURRENT_VERSION);
        $unlocalizedDim->method('getTemplateData')->willReturn([
            'webspace' => $webspace,
            'target_template' => $targetTemplate,
        ]);

        $localizedDim = $this->createStub(SnippetDimensionContentInterface::class);
        $localizedDim->method('getStage')->willReturn(DimensionContentInterface::STAGE_DRAFT);
        $localizedDim->method('getLocale')->willReturn('en');
        $localizedDim->method('getVersion')->willReturn(DimensionContentInterface::CURRENT_VERSION);
        $localizedDim->method('getTemplateKey')->willReturn(TemplateKeys::PAGE_LAYOUT);
        $localizedDim->method('getTemplateData')->willReturn([
            'title' => $title,
        ]);

        $snippet->method('getDimensionContents')->willReturn(
            new \Doctrine\Common\Collections\ArrayCollection([$unlocalizedDim, $localizedDim]),
        );

        return $snippet;
    }
}
