<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Totoglu\SuluBlockSuiteBundle\Metadata\PopoverOptionGroupNormalizer;

final class PopoverOptionGroupNormalizerTest extends TestCase
{
    public function testInjectsStorageKeyAndDefaultOptionAndRemovesDuplicateFieldFromNonContentSection(): void
    {
        $normalizer = new PopoverOptionGroupNormalizer();

        $contentSection = new SectionMetadata('content');
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');

        $tagOpt1 = new OptionMetadata();
        $tagOpt1->setName('h1');
        $tagOpt1->setValue('h1');
        $tagOpt1->setTitle('H1', 'en');

        $tagOpt2 = new OptionMetadata();
        $tagOpt2->setName('h2');
        $tagOpt2->setValue('h2');
        $tagOpt2->setTitle('H2', 'en');

        $collectionOpt = new OptionMetadata();
        $collectionOpt->setName('title_element');
        $collectionOpt->setType(OptionMetadata::TYPE_COLLECTION);
        $collectionOpt->setValue([$tagOpt1, $tagOpt2]);
        $titleField->addOption($collectionOpt);
        $contentSection->addItem($titleField);

        $settingsSection = new SectionMetadata('settings');
        $dupField = new FieldMetadata('title_element');
        $dupField->setType('single_select');
        $keepField = new FieldMetadata('custom_id');
        $keepField->setType('text_line');
        $settingsSection->addItem($dupField);
        $settingsSection->addItem($keepField);

        $nonContentSections = ['settings' => $settingsSection];

        $normalizer->processInSection($contentSection, $nonContentSections, 'props/', 'en');

        $normalizedChildren = $collectionOpt->getValue();
        self::assertIsArray($normalizedChildren);

        $byName = [];
        foreach ($normalizedChildren as $child) {
            $name = $child->getName();
            if (\is_string($name)) {
                $byName[$name] = $child->getValue();
            }
        }

        self::assertSame('h1', $byName['_default'] ?? null);
        self::assertSame('props/title_element', $byName['_storage_key'] ?? null);
        self::assertArrayNotHasKey('title_element', $nonContentSections['settings']->getItems());
        self::assertArrayHasKey('custom_id', $nonContentSections['settings']->getItems());
    }
}
