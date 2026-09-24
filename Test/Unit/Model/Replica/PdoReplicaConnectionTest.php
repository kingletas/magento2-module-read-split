<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model\Replica;

use Kingletas\ReadSplit\Model\Replica\PdoReplicaConnection;
use Kingletas\ReadSplit\Model\Replica\PdoReplicaConnector;
use Kingletas\ReadSplit\Model\Replica\ReplicaMysql;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Adapter\Pdo\MysqlFactory;
use Magento\Framework\DB\Logger\Quiet;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\DB\SelectFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;
use Zend_Db_Statement_Interface;

class PdoReplicaConnectionTest extends TestCase
{
    public function testTheConnectorOpensTheReplicaAdapterAtOnceWithItsReadTimeout(): void
    {
        $adapter = $this->createMock(ReplicaMysql::class);
        $adapter->expects($this->once())->method('connectWithin')->with(5);
        $factory = $this->createMock(MysqlFactory::class);
        $factory->expects($this->once())
            ->method('create')
            ->with(ReplicaMysql::class, ['host' => 'db-replica.example'])
            ->willReturn($adapter);

        $connection = (new PdoReplicaConnector($factory))
            ->connect(['host' => 'db-replica.example'], new Quiet(), new SelectFactory(new SelectRenderer([])), 5);

        $this->assertInstanceOf(PdoReplicaConnection::class, $connection);
    }

    public function testARefusedOrStalledConnectionThrows(): void
    {
        $adapter = $this->createStub(ReplicaMysql::class);
        $adapter->method('connectWithin')->willThrowException(new RuntimeException('invented refusal'));
        $factory = $this->createStub(MysqlFactory::class);
        $factory->method('create')->willReturn($adapter);

        $this->expectException(RuntimeException::class);

        (new PdoReplicaConnector($factory))->connect([], new Quiet(), new SelectFactory(new SelectRenderer([])), 5);
    }

    public function testAnAdapterThatIsNotTheReplicaAdapterIsRefused(): void
    {
        $factory = $this->createStub(MysqlFactory::class);
        $factory->method('create')->willReturn($this->createStub(Mysql::class));

        $this->expectException(UnexpectedValueException::class);

        (new PdoReplicaConnector($factory))->connect([], new Quiet(), new SelectFactory(new SelectRenderer([])), 5);
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function waitAnswers(): array
    {
        return [
            'caught up' => ['0', true],
            'caught up, as a number' => [0, true],
            'not yet' => ['-1', false],
            'an error' => [null, false],
            'no row' => [false, false],
        ];
    }

    #[DataProvider('waitAnswers')]
    public function testOnlyAZeroFromTheWaitMeansCaughtUp(mixed $answer, bool $caughtUp): void
    {
        $adapter = $this->createMock(Mysql::class);
        $adapter->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT MASTER_GTID_WAIT(?, 0)', ['0-1-996'])
            ->willReturn($answer);

        $this->assertSame($caughtUp, (new PdoReplicaConnection($adapter))->hasReached('0-1-996'));
    }

    public function testReplicationStatusIsTheReplicasOwnRow(): void
    {
        $row = ['Slave_IO_Running' => 'Yes', 'Slave_SQL_Running' => 'Yes', 'Seconds_Behind_Master' => '0'];
        $adapter = $this->createMock(Mysql::class);
        $adapter->expects($this->once())->method('fetchRow')->with('SHOW REPLICA STATUS')->willReturn($row);

        $this->assertSame($row, (new PdoReplicaConnection($adapter))->replicationStatus());
    }

    public function testAServerThatIsNotAReplicaGivesAnEmptyStatus(): void
    {
        $adapter = $this->createStub(Mysql::class);
        $adapter->method('fetchRow')->willReturn(false);

        $this->assertSame([], (new PdoReplicaConnection($adapter))->replicationStatus());
    }

    public function testAQueryIsPassedToTheCoreAdapterUnchanged(): void
    {
        $statement = $this->createStub(Zend_Db_Statement_Interface::class);
        $adapter = $this->createMock(Mysql::class);
        $adapter->expects($this->once())->method('query')->with('SELECT * FROM store', [3])->willReturn($statement);

        $this->assertSame($statement, (new PdoReplicaConnection($adapter))->query('SELECT * FROM store', [3]));
    }

    public function testAnAdapterThatReturnsNoStatementIsAnError(): void
    {
        $adapter = $this->createStub(Mysql::class);
        $adapter->method('query')->willReturn(null);

        $this->expectException(UnexpectedValueException::class);

        (new PdoReplicaConnection($adapter))->query('SELECT * FROM store', []);
    }

    public function testClosingClosesTheCoreAdapter(): void
    {
        $adapter = $this->createMock(Mysql::class);
        $adapter->expects($this->once())->method('closeConnection');

        (new PdoReplicaConnection($adapter))->close();
    }
}
