<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model;

use Kingletas\ReadSplit\Model\Adapter\ReadSplitMysql;
use Kingletas\ReadSplit\Model\ConnectionType;
use Kingletas\ReadSplit\Model\SettingsReader;
use Kingletas\ReadSplit\Test\Support\SplitAdapterTestCase;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Adapter\Pdo\MysqlFactory;
use Magento\Framework\ObjectManagerInterface;
use ReflectionProperty;
use Zend_Db_Adapter_Abstract;

/**
 * Through Magento's own connection type, whose getValidConfig() adds 'type' and 'active' to the config before the
 * adapter sees it; the suites that built the adapter directly never saw that config, which is how the store proof
 * found the module inactive.
 */
class RealConnectionTypeTest extends SplitAdapterTestCase
{
    public function testTheModuleIsActiveOnTheConfigMagentosConnectionTypeHandsItsAdapter(): void
    {
        $adapter = $this->connect($this->deploymentConfig('', $this->readSplitBlock()), $this->connectionConfig());

        $this->assertInstanceOf(ReadSplitMysql::class, $adapter);
        $this->assertTrue($adapter->readSplitSettings()->isActive());
        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM store'));
        $this->assertSame('db-replica.example', $this->replica->lastConfig['host']);
        $this->assertSame('invented_store', $this->replica->lastConfig['dbname']);
    }

    public function testAnotherConnectionThroughTheSameTypeGetsTheCoreAdapter(): void
    {
        $indexer = ['host' => 'db-indexer.example'] + $this->connectionConfig();

        $adapter = $this->connect($this->deploymentConfig('', $this->readSplitBlock()), $indexer);

        $this->assertNotInstanceOf(ReadSplitMysql::class, $adapter);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function connect(DeploymentConfig $deploymentConfig, array $config): AdapterInterface
    {
        $test = $this;
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('create')->willReturnCallback(
            static function (string $class, array $arguments) use ($test, $deploymentConfig): AdapterInterface {
                if ($class !== ReadSplitMysql::class) {
                    return $test->createStub(Mysql::class);
                }

                $adapter = $test->adapterFor($arguments['config'], $deploymentConfig);
                (new ReflectionProperty(Zend_Db_Adapter_Abstract::class, '_connection'))
                    ->setValue($adapter, $test->primary);

                return $adapter;
            }
        );

        $type = new ConnectionType(
            $config,
            new MysqlFactory($objectManager),
            new SettingsReader($deploymentConfig),
            $this->breaker()
        );

        return $type->getConnection();
    }
}
