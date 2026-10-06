<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model\Adapter;

use Kingletas\ReadSplit\Test\Support\SplitAdapterTestCase;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Throwable;
use Zend_Db_Statement_Exception;

/**
 * A dead replica, or one whose replication has stopped, is taken out of use on this node for thirty seconds, and
 * each adapter here is a new request on that node.
 */
class ReplicaBreakerRoutingTest extends SplitAdapterTestCase
{
    public function testARefusedConnectionStopsTheNextRequestsTryingAtAll(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning');
        $this->replica->refuseConnections = true;

        $answers = [];

        for ($request = 0; $request < 3; ++$request) {
            $answers[] = $this->answeredBy($this->adapter(), 'SELECT * FROM store');
            $this->clock->advance(5);
        }

        $this->assertSame(['primary', 'primary', 'primary'], $answers);
        $this->assertSame(1, $this->replica->connects);
    }

    public function testAfterThirtySecondsOneRequestRetriesAndARecoveredReplicaIsUsedAgain(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning');
        $this->logger->expects($this->once())->method('notice');
        $this->replica->refuseConnections = true;
        $this->answeredBy($this->adapter(), 'SELECT * FROM store');

        $this->replica->refuseConnections = false;
        $this->clock->advance(30);

        $this->assertSame('replica', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));
        $this->assertSame('replica', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));
    }

    public function testWhileOneRequestRetriesTheOthersKeepToThePrimary(): void
    {
        $this->replica->refuseConnections = true;
        $this->answeredBy($this->adapter(), 'SELECT * FROM store');
        $this->clock->advance(30);

        $this->answeredBy($this->adapter(), 'SELECT * FROM store');
        $this->assertSame('primary', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));

        $this->assertSame(2, $this->replica->connects, 'The first request and one retry');
    }

    public function testAFailedRetryLogsNothingMore(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning');
        $this->replica->refuseConnections = true;

        for ($request = 0; $request < 4; ++$request) {
            $this->answeredBy($this->adapter(), 'SELECT * FROM store');
            $this->clock->advance(30);
        }

        $this->assertSame(4, $this->replica->connects, 'One retry every thirty seconds');
    }

    public function testAStatementTheReplicaFailsAndThePrimaryAnswersTripsIt(): void
    {
        $this->replica->failOn = '/catalog_product_entity/';
        $this->answeredBy($this->adapter(), 'SELECT * FROM catalog_product_entity');

        $this->assertSame('primary', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));
        $this->assertSame(1, $this->replica->connects);
    }

    /**
     * A table the replica does not have, or a grant its user lacks, once made the breaker open and close for ever.
     */
    public function testAStatementOnlyTheReplicaRefusesLeavesTheBreakerClosedAndIsSaidOnce(): void
    {
        $words = "SELECT command denied to user 'invented_reader'@'%' for table `invented_report`";
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning')->with($this->stringContains($words));
        $this->replica->failOn = '/invented_report/';
        $this->replica->failWith = $this->databaseError(1142, '42000', $words);

        for ($request = 0; $request < 3; ++$request) {
            $adapter = $this->adapter();
            $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM invented_report'));
            $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM store'), 'The rest of it');
            $this->clock->advance(40);
        }

        $this->assertSame([], $this->breakerMarkers());
        $this->assertSame('replica', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));
    }

    /**
     * A syntax error's message quotes the statement, and a statement can carry a shopper's email or address.
     */
    public function testARefusalWhoseMessageCanQuoteTheStatementIsToldByItsNumbersAlone(): void
    {
        $words = "You have an error in your SQL syntax; check the manual near 'invented.shopper@example.com' at line 1";
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning')->with($this->logicalAnd(
            $this->stringContains('It said: error 1064, SQLSTATE 42000. This is said'),
            $this->logicalNot($this->stringContains('invented.shopper'))
        ));
        $this->replica->failOn = '/invented_report/';
        $this->replica->failWith = $this->databaseError(1064, '42000', $words);

        $this->assertSame('primary', $this->answeredBy($this->adapter(), 'SELECT * FROM invented_report'));
        $this->assertSame([], $this->breakerMarkers(), 'It is still the statement at fault, whatever is logged');
    }

    public function testARefusalThatNamesAnObjectButCameWithoutWordsIsToldByItsNumbers(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning')
            ->with($this->stringContains('It said: error 1146, SQLSTATE 42S02. This is said'));
        $this->replica->failOn = '/invented_report/';
        $this->replica->failWith = $this->databaseError(1146, '42S02', '');

        $this->assertSame('primary', $this->answeredBy($this->adapter(), 'SELECT * FROM invented_report'));
    }

    public function testAStatementBothServersRefuseSaysNothingAboutTheReplica(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->never())->method('warning');
        $this->replica->failOn = '/invented_missing_table/';
        $words = "Table 'store.invented_missing_table' doesn't exist";
        $this->replica->failWith = $this->databaseError(1146, '42S02', $words);
        $this->primary->failOn = '/invented_missing_table/';

        try {
            $this->answeredBy($this->adapter(), 'SELECT * FROM invented_missing_table');
            $this->fail('The primary should have refused the statement too');
        } catch (Throwable) {
            // The statement is wrong everywhere, which is the caller's to hear and not the log's.
        }

        $this->assertSame('replica', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));
    }

    public function testATripForAFailedStatementSaysWhatFailed(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning')
            ->with($this->stringContains('it failed a statement the primary answered (RuntimeException 0)'));
        $this->replica->failOn = '/catalog_product_entity/';

        $this->answeredBy($this->adapter(), 'SELECT * FROM catalog_product_entity');
    }

    public function testAStatementThatFailsOnBothServersDoesNotTripIt(): void
    {
        $this->replica->failOn = '/invented_missing_table/';
        $this->primary->failOn = '/invented_missing_table/';

        try {
            $this->answeredBy($this->adapter(), 'SELECT * FROM invented_missing_table');
            $this->fail('The primary should have refused the statement too');
        } catch (Throwable) {
            // The statement is at fault, not the replica.
        }

        $this->assertSame('replica', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));
    }

    /**
     * A stalled replica shows as a read that times out, which drops the connection with error 2006.
     */
    public function testAReadThatTimesOutOnTheReplicaTripsItAndThePrimaryAnswers(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning');
        $this->replica->failOn = '/catalog_product_entity/';

        $this->assertSame('primary', $this->answeredBy($this->adapter(), 'SELECT * FROM catalog_product_entity'));
        $this->assertSame('primary', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));
        $this->assertSame(1, $this->replica->connects);
    }

    /**
     * One slow page asked for twice a minute once kept a node's reads off the replica, with nothing said.
     */
    public function testAStatementTheReplicaStopsForRunningTooLongLeavesTheBreakerClosed(): void
    {
        $this->replica->failOn = '/invented_slow_report/';
        $this->replica->failWith = $this->ranTooLong();
        $slow = $this->adapter();

        $this->assertSame('primary', $this->answeredBy($slow, 'SELECT * FROM invented_slow_report'));
        $this->assertSame('primary', $this->answeredBy($slow, 'SELECT * FROM store'), 'The rest of that request');
        $this->assertSame([], $this->breakerMarkers());
        $this->assertSame('replica', $this->answeredBy($this->adapter(), 'SELECT * FROM store'), 'The next request');
        $this->assertSame(2, $this->replica->connects);
    }

    public function testAStatementThatRanTooLongIsSaidOnceAcrossRequests(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning')->with($this->stringContains('ran past 4 seconds'));
        $this->replica->failOn = '/invented_slow_report/';
        $this->replica->failWith = $this->ranTooLong();

        for ($request = 0; $request < 3; ++$request) {
            $this->answeredBy($this->adapter(), 'SELECT * FROM invented_slow_report');
            $this->clock->advance(40);
        }
    }

    /**
     * A connection lost in the middle of a statement is still the replica's fault.
     */
    public function testAnyOtherFailedStatementStillTripsIt(): void
    {
        $this->replica->failOn = '/catalog_product_entity/';
        $this->replica->failWith = $this->databaseError(2006, 'HY000', 'MySQL server has gone away');

        $this->answeredBy($this->adapter(), 'SELECT * FROM catalog_product_entity');

        $this->assertNotSame([], $this->breakerMarkers());
        $this->assertSame('primary', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));
    }

    public function testAFailedReplayTripsIt(): void
    {
        $this->replica->failOn = '/^SET NAMES/';
        $first = $this->adapter();
        $first->query('SET NAMES utf8mb4');
        $this->answeredBy($first, 'SELECT * FROM store');
        $this->replica->failOn = '';

        $this->assertSame('primary', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));
        $this->assertSame(1, $this->replica->connects);
    }

    public function testAGtidCheckThatErrorsTripsIt(): void
    {
        $this->replica->failGtidCheck = true;
        $this->arriveWithPosition('0-1-512');
        $this->assertSame('primary', $this->answeredBy($this->adapter(), 'SELECT * FROM catalog_product_entity'));

        $this->cookies->incoming = [];

        $this->assertSame('primary', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));
        $this->assertSame(1, $this->replica->connects);
    }

    public function testAReplicaThatIsMerelyBehindAVisitorDoesNotTripIt(): void
    {
        $this->replica->caughtUp['replica'] = false;
        $this->arriveWithPosition('0-1-512');
        $this->assertSame('primary', $this->answeredBy($this->adapter(), 'SELECT * FROM catalog_product_entity'));

        $this->cookies->incoming = [];

        $this->assertSame('replica', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));
    }

    public function testAVisitorHeldOnThePrimaryDoesNotSpendTheRetry(): void
    {
        $this->replica->refuseConnections = true;
        $this->answeredBy($this->adapter(), 'SELECT * FROM store');
        $this->replica->refuseConnections = false;
        $this->clock->advance(30);

        $this->arriveWithPosition('');
        $this->assertSame('primary', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));
        $this->cookies->incoming = [];

        $this->assertSame('replica', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));
    }

    public function testAHealthyReplicaIsAskedAboutReplicationAtMostOnceEveryThirtySeconds(): void
    {
        for ($request = 0; $request < 3; ++$request) {
            $this->assertSame('replica', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));
            $this->clock->advance(10);
        }

        $this->assertSame(1, $this->replica->statusChecks);

        $this->answeredBy($this->adapter(), 'SELECT * FROM store');

        $this->assertSame(2, $this->replica->statusChecks);
    }

    /**
     * @return array<string, array{0: array<string, string|null>|null}>
     */
    public static function stoppedOrUnknownReplication(): array
    {
        return [
            'the SQL thread stopped' => [self::row('Yes', 'No', null)],
            'the I/O thread stopped' => [self::row('No', 'Yes', '0')],
            'more than max_lag behind' => [self::row('Yes', 'Yes', '31')],
            'not a replica' => [[]],
            'the privilege to ask is missing' => [null],
        ];
    }

    /**
     * @param array<string, string|null>|null $status
     */
    #[DataProvider('stoppedOrUnknownReplication')]
    public function testReplicationThatHasStoppedOrCannotBeCheckedTripsIt(?array $status): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning');
        $this->replica->status = $status;

        $this->assertSame('primary', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));
        $this->clock->advance(10);
        $this->assertSame('primary', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));

        $this->assertSame(1, $this->replica->connects);
        $this->assertSame([], $this->replica->sql(), 'Nothing is read from it');
    }

    public function testMaxLagIsTheConfiguredOne(): void
    {
        $this->replica->status = self::row('Yes', 'Yes', '6');

        $this->assertSame('primary', $this->answeredBy($this->adapter(['max_lag' => 5]), 'SELECT * FROM store'));
    }

    /**
     * @return array<string, string|null>
     */
    private static function row(string $io, string $sql, ?string $lag): array
    {
        return ['Slave_IO_Running' => $io, 'Slave_SQL_Running' => $sql, 'Seconds_Behind_Master' => $lag];
    }

    /**
     * The error as Magento's adapter hands it on: its own exception, with the driver's underneath.
     */
    private function ranTooLong(): Throwable
    {
        return $this->databaseError(1969, '70100', 'Query execution was interrupted (max_statement_time exceeded)');
    }

    private function databaseError(int $number, string $state, string $message): Throwable
    {
        $driver = new PDOException('SQLSTATE[' . $state . ']: ' . $message);
        $driver->errorInfo = [$state, $number, $message];

        return new Zend_Db_Statement_Exception($driver->getMessage(), (int) $state, $driver);
    }

    /**
     * @return string[]
     */
    private function breakerMarkers(): array
    {
        return array_values(array_filter(
            $this->markersIn($this->varDir),
            static fn (string $name): bool => str_ends_with($name, '.breaker')
        ));
    }
}
