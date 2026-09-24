<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model\Replica;

use Kingletas\ReadSplit\Model\Replica\ReplicationStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReplicationStatusTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, string|null>}>
     */
    public static function healthy(): array
    {
        return [
            'both threads, no lag' => [self::row('Yes', 'Yes', '0')],
            'lag at the limit' => [self::row('Yes', 'Yes', '30')],
            'the newer column names' => [
                ['Replica_IO_Running' => 'Yes', 'Replica_SQL_Running' => 'Yes', 'Seconds_Behind_Source' => '3'],
            ],
        ];
    }

    /**
     * @param array<string, string|null> $row
     */
    #[DataProvider('healthy')]
    public function testARunningReplicaWithinMaxLagIsHealthy(array $row): void
    {
        $this->assertNull((new ReplicationStatus())->problem($row, 30));
    }

    /**
     * @return array<string, array{0: array<string, string|null>}>
     */
    public static function unhealthy(): array
    {
        return [
            'no row: not a replica, or not allowed to say' => [[]],
            'the I/O thread stopped' => [self::row('No', 'Yes', '0')],
            'the I/O thread reconnecting' => [
                self::row('Connecting', 'Yes', '0'),
            ],
            'the SQL thread stopped' => [self::row('Yes', 'No', null)],
            'lag past the limit' => [self::row('Yes', 'Yes', '31')],
            'lag unknown' => [self::row('Yes', 'Yes', null)],
            'lag that is not a number' => [self::row('Yes', 'Yes', 'soon')],
        ];
    }

    /**
     * @param array<string, string|null> $row
     */
    #[DataProvider('unhealthy')]
    public function testAnythingElseIsAProblem(array $row): void
    {
        $this->assertIsString((new ReplicationStatus())->problem($row, 30));
    }

    public function testTheWarningSaysWhichProblemItWas(): void
    {
        $status = new ReplicationStatus();

        $this->assertSame('it gave no replication status', $status->problem([], 30));
        $this->assertSame(
            'its replication SQL thread is not running',
            $status->problem(['Slave_IO_Running' => 'Yes', 'Slave_SQL_Running' => 'No'], 30)
        );
        $this->assertSame(
            'it is 31 seconds behind, over max_lag of 30',
            $status->problem(self::row('Yes', 'Yes', '31'), 30)
        );
    }

    public function testTheLimitIsTheOneConfigured(): void
    {
        $row = self::row('Yes', 'Yes', '6');

        $this->assertNull((new ReplicationStatus())->problem($row, 6));
        $this->assertIsString((new ReplicationStatus())->problem($row, 5));
    }

    /**
     * @return array<string, string|null>
     */
    private static function row(string $io, string $sql, ?string $lag): array
    {
        return ['Slave_IO_Running' => $io, 'Slave_SQL_Running' => $sql, 'Seconds_Behind_Master' => $lag];
    }
}
