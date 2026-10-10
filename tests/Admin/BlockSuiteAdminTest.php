<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItemCollection;
use Sulu\Bundle\AdminBundle\Admin\View\FormViewBuilderInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ListViewBuilderInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ResourceTabViewBuilderInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ViewBuilderFactoryInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;
use Sulu\Component\Localization\Manager\LocalizationManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Totoglu\SuluBlockSuiteBundle\Admin\BlockSuiteAdmin;

final class BlockSuiteAdminTest extends TestCase
{
    public function testConfigureNavigationItemsWithPermission(): void
    {
        $viewBuilderFactory = $this->createStub(ViewBuilderFactoryInterface::class);
        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $localizationManager = $this->createStub(LocalizationManagerInterface::class);

        $securityChecker
            ->expects(self::once())
            ->method('hasPermission')
            ->with(BlockSuiteAdmin::SECURITY_CONTEXT, PermissionTypes::VIEW)
            ->willReturn(true);

        $admin = new BlockSuiteAdmin($viewBuilderFactory, $securityChecker, $localizationManager);

        $collection = new NavigationItemCollection();
        $admin->configureNavigationItems($collection);

        self::assertTrue($collection->has('sulu_block_suite.title'));
        $rootItem = $collection->get('sulu_block_suite.title');
        self::assertSame('sulu_block_suite.title', $rootItem->getName());
        self::assertSame('su-brush', $rootItem->getIcon());
        self::assertTrue($rootItem->hasChildren());

        $children = \array_values($rootItem->getChildren());
        self::assertCount(3, $children);

        self::assertSame('sulu_block_suite.reusable_blocks', $children[0]->getName());
        self::assertSame('sulu_block_suite.presets', $children[1]->getName());
        self::assertSame('sulu_block_suite.layouts', $children[2]->getName());
    }

    public function testConfigureNavigationItemsWithoutPermission(): void
    {
        $viewBuilderFactory = $this->createStub(ViewBuilderFactoryInterface::class);
        $securityChecker = $this->createMock(SecurityCheckerInterface::class);
        $localizationManager = $this->createStub(LocalizationManagerInterface::class);

        $securityChecker
            ->expects(self::once())
            ->method('hasPermission')
            ->with(BlockSuiteAdmin::SECURITY_CONTEXT, PermissionTypes::VIEW)
            ->willReturn(false);

        $admin = new BlockSuiteAdmin($viewBuilderFactory, $securityChecker, $localizationManager);

        $collection = new NavigationItemCollection();
        $admin->configureNavigationItems($collection);

        self::assertCount(0, $collection->all());
    }

    public function testConfigureViews(): void
    {
        $securityChecker = $this->createStub(SecurityCheckerInterface::class);
        $securityChecker
            ->method('hasPermission')
            ->willReturn(true);

        $localizationManager = $this->createStub(LocalizationManagerInterface::class);
        $localizationManager
            ->method('getLocales')
            ->willReturn(['en', 'tr']);

        $viewBuilderFactory = $this->createStub(ViewBuilderFactoryInterface::class);

        $viewBuilderFactory
            ->method('createListViewBuilder')
            ->willReturnCallback(function (string $name): ListViewBuilderInterface {
                $builder = $this->createStub(ListViewBuilderInterface::class);
                $builder->method('getName')->willReturn($name);
                $builder->method('setResourceKey')->willReturnSelf();
                $builder->method('setListKey')->willReturnSelf();
                $builder->method('setTitle')->willReturnSelf();
                $builder->method('addListAdapters')->willReturnSelf();
                $builder->method('addLocales')->willReturnSelf();
                $builder->method('setDefaultLocale')->willReturnSelf();
                $builder->method('setAddView')->willReturnSelf();
                $builder->method('setEditView')->willReturnSelf();
                $builder->method('addToolbarActions')->willReturnSelf();
                $builder->method('addRouterAttributesToListRequest')->willReturnSelf();
                $builder->method('addRequestParameters')->willReturnSelf();

                return $builder;
            });

        $viewBuilderFactory
            ->method('createResourceTabViewBuilder')
            ->willReturnCallback(function (string $name): ResourceTabViewBuilderInterface {
                $builder = $this->createStub(ResourceTabViewBuilderInterface::class);
                $builder->method('getName')->willReturn($name);
                $builder->method('setResourceKey')->willReturnSelf();
                $builder->method('addLocales')->willReturnSelf();
                $builder->method('setBackView')->willReturnSelf();
                $builder->method('setTitleProperty')->willReturnSelf();
                $builder->method('addRouterAttributesToBackView')->willReturnSelf();

                return $builder;
            });

        $viewBuilderFactory
            ->method('createFormViewBuilder')
            ->willReturnCallback(function (string $name): FormViewBuilderInterface {
                $builder = $this->createStub(FormViewBuilderInterface::class);
                $builder->method('getName')->willReturn($name);
                $builder->method('setResourceKey')->willReturnSelf();
                $builder->method('setFormKey')->willReturnSelf();
                $builder->method('setTabTitle')->willReturnSelf();
                $builder->method('setEditView')->willReturnSelf();
                $builder->method('addToolbarActions')->willReturnSelf();
                $builder->method('addMetadataRequestParameters')->willReturnSelf();
                $builder->method('setParent')->willReturnSelf();

                return $builder;
            });

        $admin = new BlockSuiteAdmin($viewBuilderFactory, $securityChecker, $localizationManager);

        $viewCollection = new ViewCollection();
        $admin->configureViews($viewCollection);

        // 3 snippet types * 5 views each (list, add tab, edit tab, add form, edit form) = 15 views
        self::assertCount(15, $viewCollection->all());

        // Verify key views exist
        self::assertTrue($viewCollection->has('sulu_block_suite.reusable_blocks.list'));
        self::assertTrue($viewCollection->has('sulu_block_suite.presets.list'));
        self::assertTrue($viewCollection->has('sulu_block_suite.layouts.list'));
    }

    public function testConfigureViewsWithReferenceFactory(): void
    {
        $securityChecker = $this->createStub(SecurityCheckerInterface::class);
        $securityChecker->method('hasPermission')->willReturn(true);

        $localizationManager = $this->createStub(LocalizationManagerInterface::class);
        $localizationManager->method('getLocales')->willReturn(['en']);

        $viewBuilderFactory = $this->createStub(ViewBuilderFactoryInterface::class);
        $viewBuilderFactory->method('createListViewBuilder')->willReturnCallback(function (string $name): ListViewBuilderInterface {
            $builder = $this->createStub(ListViewBuilderInterface::class);
            $builder->method('getName')->willReturn($name);
            $builder->method('setResourceKey')->willReturnSelf();
            $builder->method('setListKey')->willReturnSelf();
            $builder->method('setTitle')->willReturnSelf();
            $builder->method('addListAdapters')->willReturnSelf();
            $builder->method('addLocales')->willReturnSelf();
            $builder->method('setDefaultLocale')->willReturnSelf();
            $builder->method('setAddView')->willReturnSelf();
            $builder->method('setEditView')->willReturnSelf();
            $builder->method('addToolbarActions')->willReturnSelf();
            $builder->method('addRouterAttributesToListRequest')->willReturnSelf();
            $builder->method('addRequestParameters')->willReturnSelf();

            return $builder;
        });

        $viewBuilderFactory->method('createResourceTabViewBuilder')->willReturnCallback(function (string $name): ResourceTabViewBuilderInterface {
            $builder = $this->createStub(ResourceTabViewBuilderInterface::class);
            $builder->method('getName')->willReturn($name);
            $builder->method('setResourceKey')->willReturnSelf();
            $builder->method('addLocales')->willReturnSelf();
            $builder->method('setBackView')->willReturnSelf();
            $builder->method('setTitleProperty')->willReturnSelf();
            $builder->method('addRouterAttributesToBackView')->willReturnSelf();

            return $builder;
        });

        $viewBuilderFactory->method('createFormViewBuilder')->willReturnCallback(function (string $name): FormViewBuilderInterface {
            $builder = $this->createStub(FormViewBuilderInterface::class);
            $builder->method('getName')->willReturn($name);
            $builder->method('setResourceKey')->willReturnSelf();
            $builder->method('setFormKey')->willReturnSelf();
            $builder->method('setTabTitle')->willReturnSelf();
            $builder->method('setEditView')->willReturnSelf();
            $builder->method('addToolbarActions')->willReturnSelf();
            $builder->method('addMetadataRequestParameters')->willReturnSelf();
            $builder->method('setParent')->willReturnSelf();

            return $builder;
        });

        $referenceFactory = $this->createStub(\Sulu\Bundle\ReferenceBundle\Infrastructure\Sulu\Admin\View\ReferenceViewBuilderFactoryInterface::class);
        $referenceFactory->method('hasReferenceListPermission')->willReturn(true);
        $referenceFactory->method('createReferenceListViewBuilder')->willReturnCallback(function (string $name): ListViewBuilderInterface {
            $builder = $this->createStub(ListViewBuilderInterface::class);
            $builder->method('getName')->willReturn($name);
            $builder->method('setParent')->willReturnSelf();

            return $builder;
        });

        $admin = new BlockSuiteAdmin($viewBuilderFactory, $securityChecker, $localizationManager, $referenceFactory);

        $viewCollection = new ViewCollection();
        $admin->configureViews($viewCollection);

        // 3 snippet types * (5 standard views + 1 reference view) = 18 views
        self::assertCount(18, $viewCollection->all());
        self::assertTrue($viewCollection->has('sulu_block_suite.reusable_blocks.edit.references'));
    }

    public function testConfigureNavigationItemsWithSelectiveFeatures(): void
    {
        $viewBuilderFactory = $this->createStub(ViewBuilderFactoryInterface::class);
        $securityChecker = $this->createStub(SecurityCheckerInterface::class);
        $localizationManager = $this->createStub(LocalizationManagerInterface::class);

        $securityChecker->method('hasPermission')->willReturn(true);

        // Only reusable blocks enabled
        $admin = new BlockSuiteAdmin(
            $viewBuilderFactory,
            $securityChecker,
            $localizationManager,
            null,
            reusableBlocksEnabled: true,
            presetsEnabled: false,
            layoutEnabled: false,
        );

        $collection = new NavigationItemCollection();
        $admin->configureNavigationItems($collection);

        self::assertTrue($collection->has('sulu_block_suite.title'));
        $children = \array_values($collection->get('sulu_block_suite.title')->getChildren());
        self::assertCount(1, $children);
        self::assertSame('sulu_block_suite.reusable_blocks', $children[0]->getName());
    }

    public function testConfigureNavigationItemsWithAllFeaturesDisabled(): void
    {
        $viewBuilderFactory = $this->createStub(ViewBuilderFactoryInterface::class);
        $securityChecker = $this->createStub(SecurityCheckerInterface::class);
        $localizationManager = $this->createStub(LocalizationManagerInterface::class);

        $securityChecker->method('hasPermission')->willReturn(true);

        $admin = new BlockSuiteAdmin(
            $viewBuilderFactory,
            $securityChecker,
            $localizationManager,
            null,
            reusableBlocksEnabled: false,
            presetsEnabled: false,
            layoutEnabled: false,
        );

        $collection = new NavigationItemCollection();
        $admin->configureNavigationItems($collection);

        self::assertFalse($collection->has('sulu_block_suite.title'));
        self::assertCount(0, $collection->all());
    }
}
