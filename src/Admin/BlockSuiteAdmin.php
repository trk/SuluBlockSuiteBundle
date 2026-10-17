<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Admin;

use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItem;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItemCollection;
use Sulu\Bundle\AdminBundle\Admin\View\DropdownToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\ToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\ViewBuilderFactoryInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;
use Sulu\Bundle\ReferenceBundle\Infrastructure\Sulu\Admin\View\ReferenceViewBuilderFactoryInterface;
use Sulu\Component\Localization\Manager\LocalizationManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Infrastructure\Sulu\Admin\SnippetAdmin;
use Totoglu\SuluBlockSuiteBundle\Template\TemplateKeys;

final class BlockSuiteAdmin extends Admin
{
    public const SECURITY_CONTEXT = SnippetAdmin::SECURITY_CONTEXT;

    public function __construct(
        private readonly ViewBuilderFactoryInterface $viewBuilderFactory,
        private readonly SecurityCheckerInterface $securityChecker,
        private readonly LocalizationManagerInterface $localizationManager,
        private readonly ?ReferenceViewBuilderFactoryInterface $referenceViewBuilderFactory = null,
        private readonly bool $reusableBlocksEnabled = true,
        private readonly bool $presetsEnabled = true,
        private readonly bool $layoutEnabled = true,
    ) {
    }

    public function configureNavigationItems(NavigationItemCollection $navigationItemCollection): void
    {
        if (!$this->securityChecker->hasPermission(static::SECURITY_CONTEXT, PermissionTypes::VIEW)) {
            return;
        }

        if (!$this->reusableBlocksEnabled && !$this->presetsEnabled && !$this->layoutEnabled) {
            return;
        }

        $rootItem = new NavigationItem('sulu_block_suite.title');
        $rootItem->setPosition(22);
        $rootItem->setIcon('su-brush');

        if ($this->reusableBlocksEnabled) {
            $reusableItem = new NavigationItem('sulu_block_suite.reusable_blocks');
            $reusableItem->setPosition(10);
            $reusableItem->setIcon('su-brush');
            $reusableItem->setView('sulu_block_suite.reusable_blocks.list');
            $rootItem->addChild($reusableItem);
        }

        if ($this->presetsEnabled) {
            $presetsItem = new NavigationItem('sulu_block_suite.presets');
            $presetsItem->setPosition(20);
            $presetsItem->setIcon('su-cog');
            $presetsItem->setView('sulu_block_suite.presets.list');
            $rootItem->addChild($presetsItem);
        }

        if ($this->layoutEnabled) {
            $layoutsItem = new NavigationItem('sulu_block_suite.layouts');
            $layoutsItem->setPosition(30);
            $layoutsItem->setIcon('su-columns');
            $layoutsItem->setView('sulu_block_suite.layouts.list');
            $rootItem->addChild($layoutsItem);
        }

        $navigationItemCollection->add($rootItem);
    }

    public function configureViews(ViewCollection $viewCollection): void
    {
        if (!$this->securityChecker->hasPermission(static::SECURITY_CONTEXT, PermissionTypes::VIEW)) {
            return;
        }

        $locales = $this->localizationManager->getLocales();

        if ($this->reusableBlocksEnabled) {
            $this->configureSnippetGroupViews(
                $viewCollection,
                TemplateKeys::REUSABLE_BLOCK,
                'sulu_block_suite.reusable_blocks',
                '/block-suite/reusable',
                'sulu_block_suite.reusable_blocks',
                $locales,
            );
        }

        if ($this->presetsEnabled) {
            $this->configureSnippetGroupViews(
                $viewCollection,
                TemplateKeys::BLOCK_PRESET,
                'sulu_block_suite.presets',
                '/block-suite/presets',
                'sulu_block_suite.presets',
                $locales,
            );
        }

        if ($this->layoutEnabled) {
            $this->configureSnippetGroupViews(
                $viewCollection,
                TemplateKeys::PAGE_LAYOUT,
                'sulu_block_suite.layouts',
                '/block-suite/layouts',
                'sulu_block_suite.layouts',
                $locales,
            );
        }
    }

    /**
     * @param string[] $locales
     */
    private function configureSnippetGroupViews(
        ViewCollection $viewCollection,
        string $snippetType,
        string $viewPrefix,
        string $urlPrefix,
        string $titleKey,
        array $locales,
    ): void {
        $listToolbarActions = [];

        if ($this->securityChecker->hasPermission(static::SECURITY_CONTEXT, PermissionTypes::ADD)) {
            $listToolbarActions[] = new ToolbarAction('sulu_admin.add');
        }

        if ($this->securityChecker->hasPermission(static::SECURITY_CONTEXT, PermissionTypes::DELETE)) {
            $listToolbarActions[] = new ToolbarAction('sulu_admin.delete');
        }

        if ($this->securityChecker->hasPermission(static::SECURITY_CONTEXT, PermissionTypes::VIEW)) {
            $listToolbarActions[] = new ToolbarAction('sulu_admin.export');
        }

        $formToolbarActions = [];
        if ($this->securityChecker->hasPermission(static::SECURITY_CONTEXT, PermissionTypes::EDIT)) {
            $formToolbarActions[] = new ToolbarAction(
                'sulu_admin.save_with_publishing',
                [
                    'publish_visible_condition' => '(!_permissions || _permissions.live)',
                    'save_visible_condition' => '(!_permissions || _permissions.edit)',
                ],
            );

            $formToolbarActions[] = new DropdownToolbarAction(
                'sulu_admin.edit',
                'su-pen',
                [
                    new ToolbarAction('sulu_admin.copy', ['visible_condition' => '!!id']),
                    new ToolbarAction('sulu_admin.copy_locale', ['visible_condition' => '!!id']),
                ],
            );
        }

        if ($this->securityChecker->hasPermission(static::SECURITY_CONTEXT, PermissionTypes::DELETE)) {
            $formToolbarActions[] = new DropdownToolbarAction(
                'sulu_admin.delete',
                'su-trash-alt',
                [
                    new ToolbarAction('sulu_admin.delete', ['visible_condition' => '(!_permissions || _permissions.delete)']),
                    new ToolbarAction('sulu_admin.delete', ['visible_condition' => '(!_permissions || _permissions.delete)', 'delete_locale' => true]),
                ],
            );
        }

        $viewCollection->add(
            $this->viewBuilderFactory->createListViewBuilder($viewPrefix . '.list', $urlPrefix . '/:locale')
                ->setResourceKey(SnippetInterface::RESOURCE_KEY)
                ->setListKey(SnippetInterface::RESOURCE_KEY)
                ->setTitle($titleKey)
                ->addListAdapters(['table'])
                ->addLocales($locales)
                ->setDefaultLocale($locales[0] ?? 'en')
                ->setAddView($viewPrefix . '.add')
                ->setEditView($viewPrefix . '.edit')
                ->addToolbarActions($listToolbarActions)
                ->addRouterAttributesToListRequest(['locale'])
                ->addRequestParameters([
                    'types' => $snippetType,
                    'templateKeys' => $snippetType,
                ]),
        );

        $viewCollection->add(
            $this->viewBuilderFactory->createResourceTabViewBuilder($viewPrefix . '.add', $urlPrefix . '/:locale/add')
                ->setResourceKey(SnippetInterface::RESOURCE_KEY)
                ->addLocales($locales)
                ->setBackView($viewPrefix . '.list'),
        );

        $viewCollection->add(
            $this->viewBuilderFactory->createResourceTabViewBuilder($viewPrefix . '.edit', $urlPrefix . '/:locale/:id')
                ->setResourceKey(SnippetInterface::RESOURCE_KEY)
                ->addLocales($locales)
                ->setTitleProperty('title')
                ->addRouterAttributesToBackView(['locale'])
                ->setBackView($viewPrefix . '.list'),
        );

        $viewCollection->add(
            $this->viewBuilderFactory->createFormViewBuilder($viewPrefix . '.add.content', '/content')
                ->setResourceKey(SnippetInterface::RESOURCE_KEY)
                ->setFormKey('snippet')
                ->setTabTitle('sulu_content.content')
                ->setEditView($viewPrefix . '.edit')
                ->addToolbarActions($formToolbarActions)
                ->addMetadataRequestParameters(['overwriteDefaultType' => $snippetType])
                ->setParent($viewPrefix . '.add'),
        );

        $viewCollection->add(
            $this->viewBuilderFactory->createFormViewBuilder($viewPrefix . '.edit.content', '/content')
                ->setResourceKey(SnippetInterface::RESOURCE_KEY)
                ->setFormKey('snippet')
                ->setTabTitle('sulu_content.content')
                ->addToolbarActions($formToolbarActions)
                ->addMetadataRequestParameters(['overwriteDefaultType' => $snippetType])
                ->setParent($viewPrefix . '.edit'),
        );

        if (null !== $this->referenceViewBuilderFactory && $this->referenceViewBuilderFactory->hasReferenceListPermission()) {
            $viewCollection->add(
                $this->referenceViewBuilderFactory
                    ->createReferenceListViewBuilder(
                        $viewPrefix . '.edit.references',
                        '/references',
                        SnippetInterface::RESOURCE_KEY,
                    )
                    ->setParent($viewPrefix . '.edit'),
            );
        }
    }
}
