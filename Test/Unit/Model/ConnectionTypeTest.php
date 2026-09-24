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

    private const array BUILT = self::PRIMARY + ['type' => 'pdo_mysql', 'active' => false];

    public function testAnUnconfiguredStoreRunsTheCoreAdapterWithItsConfigUnchanged(): void
    {
        $this->assertBuilds(Mysql::class, null);
    }

    public function testTheKillSwitchRunsTheCoreAdapter(): void
    {
        $this->assertBuilds(Mysql::class, ['enabled' => false, 'replica' => ['host' => 'db-replica.example']]);
    }

    /**
     * Whichever adapter is built, its config is the connection's own, with nothing of this module's in it.
     */
    public function testAConfiguredReplicaRunsThisModulesAdapterWithTheConnectionsOwnConfig(): void
    {
        $this->assertBuilds(ReadSplitMysql::class, ['replica' => ['host' => 'db-replica.example']]);
    }

    public function testTheAdapterIsOneMagentosFactoryAccepts(): void
    {
        $this->assertContains(Mysql::class, class_parents(ReadSplitMysql::class));
    }

    /**
     * @param array<string, mixed>|null $block
     */
    private function assertBuilds(string $class, ?array $block): void
    {
        $factory = $this->createMock(MysqlFactory::class);
        $factory->expects($this->once())
            ->method('create')
            ->with($class, self::BUILT)
            ->willReturn($this->createStub(Mysql::class));

        $deploymentConfig = $this->createStub(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(
            static fn (string $path): mixed => match ($path) {
                'db/read_split' => $block,
                'db/connection/default' => self::PRIMARY,
                default => '',
            }
        );

        (new ConnectionType(self::PRIMARY, $factory, new SettingsReader($deploymentConfig)))->getConnection();
    }
}
