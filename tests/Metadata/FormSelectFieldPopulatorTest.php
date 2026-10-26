<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Navigation;
use Sulu\Component\Webspace\NavigationContext;
use Sulu\Component\Webspace\Webspace;
use Totoglu\SuluBlockSuiteBundle\Metadata\FormSelectFieldPopulator;

final class FormSelectFieldPopulatorTest extends TestCase
{
    public function testPopulatesTemplateWebspaceAndNavigationContextSelectFields(): void
    {
        $ws = new Webspace();
        $ws->setKey('totoglu');
        $ws->setName('Totoglu Global');

        $nav = new Navigation();
        $nav->addContext(new NavigationContext('main', ['title' => ['en' => 'Main Navigation']]));
        $ws->setNavigation($nav);

        $collection = new WebspaceCollection();
        $collection->setWebspaces(['totoglu' => $ws]);

        $webspaceManager = $this->createStub(WebspaceManagerInterface::class);
        $webspaceManager->method('getWebspaceCollection')->willReturn($collection);

        $populator = new FormSelectFieldPopulator(
            webspaceManager: $webspaceManager,
            multiWebspaceEnabled: true,
        );

        $pageForms = new TypedFormMetadata();
        $homeForm = new FormMetadata();
        $homeForm->setKey('homepage');
        $homeForm->setTitles(['en' => 'Homepage']);
        $pageForms->addForm('homepage', $homeForm);

        $populator->cacheTemplateDescriptors($pageForms, 'page', 'en');

        $templateField = new FieldMetadata('target_template');
        $templateField->setType('single_template_selection');
        $templateField->setRequired(true);

        $webspaceField = new FieldMetadata('webspace');
        $webspaceField->setType('single_webspace_select');

        $navSection = new SectionMetadata('content');
        $navField = new FieldMetadata('navigation_context');
        $navField->setType('single_select');
        $navSection->addItem($navField);

        $hidden = $populator->populateSelectFieldsInItems([
            'target_template' => $templateField,
            'webspace' => $webspaceField,
        ], 'en');
        $populator->populateNavigationContextFields($navSection, 'en');

        self::assertFalse($hidden);
        self::assertSame('single_select', $templateField->getType());
        self::assertSame('homepage', $templateField->findOption('default_value')?->getValue());

        self::assertSame('single_select', $webspaceField->getType());
        self::assertSame('totoglu', $webspaceField->findOption('default_value')?->getValue());

        /** @var list<OptionMetadata> $navOptions */
        $navOptions = (array) $navField->findOption('values')?->getValue();
        self::assertCount(1, $navOptions);
        self::assertSame('main', $navOptions[0]->getName());
        self::assertSame('Main Navigation', $navOptions[0]->getTitle('en'));
    }

    public function testOptionalSingleTemplateSelectionIncludesEmptyOptionAndNoDefaultValue(): void
    {
        $populator = new FormSelectFieldPopulator();

        $pageForms = new TypedFormMetadata();
        $defaultForm = new FormMetadata();
        $defaultForm->setKey('default');
        $defaultForm->setTitles(['en' => 'Default']);
        $pageForms->addForm('default', $defaultForm);

        $populator->cacheTemplateDescriptors($pageForms, 'page', 'en');

        $optionalTemplateField = new FieldMetadata('target_template');
        $optionalTemplateField->setType('single_template_selection');
        $optionalTemplateField->setRequired(false);

        $populator->populateSelectFieldsInItems([
            'target_template' => $optionalTemplateField,
        ], 'en');

        self::assertSame('single_select', $optionalTemplateField->getType());
        self::assertNull($optionalTemplateField->findOption('default_value'));

        /** @var list<OptionMetadata> $options */
        $options = (array) $optionalTemplateField->findOption('values')?->getValue();
        self::assertCount(2, $options);
        self::assertSame('', $options[0]->getName());
        self::assertSame('', $options[0]->getValue());
        self::assertSame('default', $options[1]->getName());
    }
}
