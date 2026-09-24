<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model\Adapter;

use Kingletas\ReadSplit\Test\Support\SplitAdapterTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Throwable;

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
        $this->assertSame('primary', $this->answeredBy($this->adapter(), 'SELECT * FROM quote'));

        $this->cookies->incoming = [];

        $this->assertSame('primary', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));
        $this->assertSame(1, $this->replica->connects);
    }

    public function testAReplicaThatIsMerelyBehindAVisitorDoesNotTripIt(): void
    {
        $this->replica->caughtUp['replica'] = false;
        $this->arriveWithPosition('0-1-512');
        $this->assertSame('primary', $this->answeredBy($this->adapter(), 'SELECT * FROM quote'));

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
}
