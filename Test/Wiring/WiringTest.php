<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Wiring;

use Kingletas\ReadSplit\Model\Adapter\ReadSplitMysql;
use Kingletas\ReadSplit\Model\ConnectionType;
use Magento\Framework\App\ResourceConnection\ConnectionAdapterInterface;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Model\ResourceModel\Type\Db\Pdo\Mysql as CoreConnectionType;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use SimpleXMLElement;

/**
 * The XML in etc/ against the code it names, because a typo there fails at runtime and nowhere else.
 */
class WiringTest extends TestCase
{
    public function testEveryConnectionIsBuiltThroughThisModulesConnectionType(): void
    {
        $preferences = $this->preferences();

        $this->assertSame(ConnectionType::class, $preferences[ConnectionAdapterInterface::class] ?? null);
        $this->assertTrue(is_subclass_of(ConnectionType::class, CoreConnectionType::class));
    }

    public function testEveryPreferenceNamesAClassThatImplementsIt(): void
    {
        foreach ($this->preferences() as $for => $type) {
            $this->assertTrue(class_exists($type), $type . ' is named in di.xml and does not exist');
            $this->assertTrue(is_a($type, $for, true), $type . ' does not implement ' . $for);
        }
    }

    /**
     * A Proxy is generated from the class before its suffix, so that class has to exist.
     */
    public function testEveryClassDiXmlNamesExists(): void
    {
        $config = $this->xml('etc/di.xml');
        $types = $config->xpath('//type') ?: [];
        $objects = $config->xpath('//*[@xsi:type="object"]') ?: [];
        $names = array_merge(
            array_map(static fn (SimpleXMLElement $type): string => (string) $type['name'], $types),
            array_map(static fn (SimpleXMLElement $item): string => trim((string) $item), $objects)
        );

        $this->assertNotEmpty($names);

        foreach ($names as $name) {
            $class = preg_replace('/\\\\Proxy$/', '', $name);
            $this->assertTrue(
                class_exists($class) || interface_exists($class),
                $name . ' is named in di.xml and does not exist'
            );
        }
    }

    public function testEveryArgumentDiXmlSetsIsAConstructorParameter(): void
    {
        foreach ($this->xml('etc/di.xml')->xpath('//type') ?: [] as $type) {
            $parameters = array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                (new ReflectionClass((string) $type['name']))->getConstructor()?->getParameters() ?? []
            );

            foreach ($type->xpath('arguments/argument') ?: [] as $argument) {
                $this->assertContains((string) $argument['name'], $parameters, (string) $type['name']);
            }
        }
    }

    /**
     * Magento's factory passes config, logger and selectFactory by name, and the object manager resolves the rest.
     */
    public function testTheAdapterCanBeBuiltTheWayMagentosFactoryBuildsIt(): void
    {
        $this->assertTrue(is_subclass_of(ReadSplitMysql::class, Mysql::class));
        $constructor = (new ReflectionClass(ReadSplitMysql::class))->getConstructor();
        $this->assertNotNull($constructor);
        $names = [];
        $seenOptional = false;

        foreach ($constructor->getParameters() as $parameter) {
            $names[] = $parameter->getName();
            $type = $parameter->getType();
            $this->assertTrue(
                $parameter->isOptional() || ($type instanceof ReflectionNamedType && !$type->isBuiltin()),
                $parameter->getName() . ' cannot be resolved by the object manager'
            );
            $this->assertFalse(
                $seenOptional && !$parameter->isOptional(),
                $parameter->getName() . ' is required after an optional one'
            );
            $seenOptional = $seenOptional || $parameter->isOptional();
        }

        foreach (['config', 'logger', 'selectFactory'] as $passed) {
            $this->assertContains($passed, $names);
        }
    }

    /**
     * REST is where Luma places orders, so a write there carries its position too, though REST never reads the replica.
     */
    public function testTheCookieIsSetInTheStorefrontGraphQlAndRestJustBeforeTheResponseIsSent(): void
    {
        $this->assertFileDoesNotExist($this->root() . '/etc/events.xml');

        foreach (['frontend', 'graphql', 'webapi_rest'] as $area) {
            $events = $this->xml('etc/' . $area . '/events.xml');
            $observers = $events->xpath('//event[@name="controller_front_send_response_before"]/observer') ?: [];

            $this->assertCount(1, $observers, $area);
            $class = (string) $observers[0]['instance'];
            $this->assertTrue((new ReflectionClass($class))->implementsInterface(ObserverInterface::class), $class);
            $this->assertCount(1, $events->xpath('//observer') ?: [], $area . ' observes nothing else');
        }

        $areas = array_map('basename', glob($this->root() . '/etc/*', GLOB_ONLYDIR) ?: []);
        sort($areas);
        $this->assertSame(['frontend', 'graphql', 'webapi_rest'], $areas);
    }

    /**
     * The design adds no plugin of any kind, and no schema, patch or data of its own.
     */
    public function testNoPluginNoSchemaAndNoData(): void
    {
        foreach (glob($this->root() . '/etc/{,*/}*.xml', GLOB_BRACE) ?: [] as $file) {
            $this->assertSame([], $this->xmlFile($file)->xpath('//plugin') ?: [], $file);
        }

        $this->assertFileDoesNotExist($this->root() . '/etc/db_schema.xml');
        $this->assertDirectoryDoesNotExist($this->root() . '/Setup');
    }

    public function testTheModuleNameAgreesEverywhere(): void
    {
        $this->assertSame('Kingletas_ReadSplit', (string) $this->xml('etc/module.xml')->module['name']);
        $registration = (string) file_get_contents($this->root() . '/registration.php');

        $this->assertStringContainsString("'Kingletas_ReadSplit'", $registration);
    }

    /**
     * @return array<string, string>
     */
    private function preferences(): array
    {
        $preferences = [];

        foreach ($this->xml('etc/di.xml')->xpath('//preference') ?: [] as $preference) {
            $preferences[(string) $preference['for']] = (string) $preference['type'];
        }

        return $preferences;
    }

    private function xml(string $relative): SimpleXMLElement
    {
        return $this->xmlFile($this->root() . '/' . $relative);
    }

    private function xmlFile(string $file): SimpleXMLElement
    {
        $xml = simplexml_load_file($file);
        $this->assertNotFalse($xml, $file);
        $xml->registerXPathNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance');

        return $xml;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
