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
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Adapter\Pdo\MysqlFactory;
use PHPUnit\Framework\TestCase;

/**
 * The preference Magento builds every connection through: it names this module's adapter only when asked to.
 */
class ConnectionTypeTest extends TestCase
{
    private const array PRIMARY = [
        'host' => 'db-primary.example',
        'dbname' => 'invented_store',
        'username' => 'invented_user',
        'password' => 'invented-password',
    ];

    public function testAnUnconfiguredConnectionRunsTheCoreAdapterWithItsConfigUnchanged(): void
    {
        $this->assertBuilds(Mysql::class, self::PRIMARY + ['type' => 'pdo_mysql', 'active' => false], self::PRIMARY);
    }

    public function testTheKillSwitchRunsTheCoreAdapterWithoutTheBlock(): void
    {
        $config = self::PRIMARY + ['read_split' => ['enabled' => false, 'replica' => ['host' => 'db-replica.example']]];

        $this->assertBuilds(Mysql::class, self::PRIMARY + ['type' => 'pdo_mysql', 'active' => false], $config);
    }

    public function testAConfiguredReplicaRunsThisModulesAdapterWithTheBlock(): void
    {
        $config = self::PRIMARY + ['read_split' => ['replica' => ['host' => 'db-replica.example']]];

        $this->assertBuilds(ReadSplitMysql::class, $config + ['type' => 'pdo_mysql', 'active' => false], $config);
    }

    public function testTheAdapterIsOneMagentosFactoryAccepts(): void
    {
        $this->assertContains(Mysql::class, class_parents(ReadSplitMysql::class));
    }

    /**
     * @param array<string, mixed> $expectedConfig
     * @param array<string, mixed> $config
     */
    private function assertBuilds(string $class, array $expectedConfig, array $config): void
    {
        $factory = $this->createMock(MysqlFactory::class);
        $factory->expects($this->once())
            ->method('create')
            ->with($class, $expectedConfig)
            ->willReturn($this->createStub(Mysql::class));

        $deploymentConfig = $this->createStub(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturn('');

        (new ConnectionType($config, $factory, new SettingsReader($deploymentConfig)))->getConnection();
    }
}
