<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Tests\Config;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\SuluAdminBundle;

final class XmlSchemaValidationTest extends TestCase
{
    public function testAllBundleXmlTemplatesAndFormsValidateAgainstSuluXsd(): void
    {
        $adminBundleRef = new \ReflectionClass(SuluAdminBundle::class);
        $adminBundleDir = \dirname((string) $adminBundleRef->getFileName());
        $templateXsd = $adminBundleDir . '/Resources/config/schema/template-1.0.xsd';
        $formXsd = $adminBundleDir . '/Resources/config/schema/form-1.0.xsd';

        $this->assertFileExists($templateXsd);
        $this->assertFileExists($formXsd);

        $bundleRoot = \dirname(__DIR__, 2);

        $this->assertDirectoryDoesNotExist($bundleRoot . '/config/templates/snippets');

        $templateXmlFiles = \array_merge(
            \glob($bundleRoot . '/config/templates/blocks/*.xml') ?: [],
            \glob($bundleRoot . '/docs/examples/snippets/*.xml') ?: [],
        );
        $this->assertCount(4, $templateXmlFiles);

        foreach ($templateXmlFiles as $xmlFile) {
            $dom = new \DOMDocument();
            $this->assertTrue($dom->load($xmlFile), \sprintf('Failed to load XML "%s".', $xmlFile));
            $dom->xinclude();
            $this->assertTrue(
                $dom->schemaValidate($templateXsd),
                \sprintf('Template XML "%s" failed Sulu template-1.0.xsd validation.', \basename($xmlFile)),
            );
        }

        $formXmlFiles = \glob($bundleRoot . '/config/forms/*.xml') ?: [];
        $this->assertNotEmpty($formXmlFiles);

        foreach ($formXmlFiles as $xmlFile) {
            $dom = new \DOMDocument();
            $this->assertTrue($dom->load($xmlFile), \sprintf('Failed to load form XML "%s".', $xmlFile));
            $dom->xinclude();
            $this->assertTrue(
                $dom->schemaValidate($formXsd),
                \sprintf('Form XML "%s" failed Sulu form-1.0.xsd validation.', \basename($xmlFile)),
            );
        }
    }
}
