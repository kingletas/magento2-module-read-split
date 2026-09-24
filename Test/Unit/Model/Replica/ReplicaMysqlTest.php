<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model\Replica;

use Kingletas\ReadSplit\Model\Replica\NetReadTimeout;
use Kingletas\ReadSplit\Model\Replica\ReplicaMysql;
use Kingletas\ReadSplit\Test\Support\RecordingNetReadTimeout;
use Magento\Framework\DB\Adapter\ConnectionException;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Logger\Quiet;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\DB\SelectFactory;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Setup\Declaration\Schema\Dto\Factories\Table as DtoFactoriesTable;
use Magento\Framework\Stdlib\DateTime;
use Magento\Framework\Stdlib\StringUtils;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * mysqlnd takes net_read_timeout when a connection is made and keeps it for that connection, so the replica's is set
 * for its connect alone and the primary's connections keep their own.
 */
class ReplicaMysqlTest extends TestCase
{
    public function testTheReadTimeoutIsSetForTheConnectAndRestoredEvenWhenTheConnectFails(): void
    {
        $timeout = new RecordingNetReadTimeout();

        $this->assertConnectFails($this->replica($timeout), 5);

        $this->assertSame(['apply 5', 'restore 86400'], $timeout->changes);
    }

    public function testTheRealSettingIsBackToItsValueAfterwards(): void
    {
        $before = ini_get('mysqlnd.net_read_timeout');

        $this->assertConnectFails($this->replica(new NetReadTimeout()), 3);

        $this->assertSame($before, ini_get('mysqlnd.net_read_timeout'));
    }

    /**
     * A read that times out drops the connection, and the core adapter would reconnect and retry without a timeout.
     */
    public function testItNeverReconnectsWithinARequest(): void
    {
        $timeout = new RecordingNetReadTimeout();
        $replica = $this->replica($timeout);
        $this->assertConnectFails($replica, 5);

        try {
            $replica->getConnection();
            $this->fail('A second connect should be refused');
        } catch (ConnectionException) {
            $this->assertCount(2, $timeout->changes, 'Nothing was tried the second time');
        }
    }

    public function testASlowQueryIsStoppedByTheServerJustBeforeTheReadTimeout(): void
    {
        $replica = $this->replica(new RecordingNetReadTimeout());

        $this->assertSame(['SET SESSION max_statement_time = 4'], $replica->sessionGuards(5));
        $this->assertSame(['SET SESSION max_statement_time = 1'], $replica->sessionGuards(1));
    }

    public function testMagentosFactoryAcceptsIt(): void
    {
        $this->assertContains(Mysql::class, class_parents(ReplicaMysql::class));
    }

    private function assertConnectFails(ReplicaMysql $replica, int $readTimeout): void
    {
        try {
            $replica->connectWithin($readTimeout);
            $this->fail('The invented socket should refuse the connection');
        } catch (Throwable $e) {
            $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
        }
    }

    private function replica(NetReadTimeout $timeout): ReplicaMysql
    {
        return new ReplicaMysql(
            new StringUtils(),
            new DateTime(),
            new Quiet(),
            new SelectFactory(new SelectRenderer([])),
            $timeout,
            [
                'host' => '/nonexistent/invented-replica.sock',
                'dbname' => 'invented_store',
                'username' => 'invented_user',
                'password' => 'invented-password',
            ],
            $this->createStub(SerializerInterface::class),
            $this->createStub(DtoFactoriesTable::class)
        );
    }
}
