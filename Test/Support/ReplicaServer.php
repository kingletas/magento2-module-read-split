<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Support;

use Kingletas\ReadSplit\Model\Replica\ReplicaConnectionInterface;
use Kingletas\ReadSplit\Model\Replica\ReplicaConnectorInterface;
use Magento\Framework\DB\LoggerInterface;
use Magento\Framework\DB\SelectFactory;
use RuntimeException;
use Throwable;

/**
 * The replica host, one server or a pool of backends taking turns, recording each statement and failing when told to.
 */
class ReplicaServer implements ReplicaConnectorInterface
{
    public int $connects = 0;

    public int $closes = 0;

    public bool $refuseConnections = false;

    public bool $failGtidCheck = false;

    /**
     * What SHOW REPLICA STATUS answers, as MariaDB names the columns; null makes the question fail.
     *
     * @var array<string, string|null>|null
     */
    public ?array $status = [
        'Slave_IO_Running' => 'Yes',
        'Slave_SQL_Running' => 'Yes',
        'Seconds_Behind_Master' => '0',
    ];

    public int $statusChecks = 0;

    /**
     * A statement matching this pattern fails on the replica.
     */
    public string $failOn = '';

    /**
     * What a failing statement throws, when the test needs a particular error rather than any.
     */
    public ?Throwable $failWith = null;

    /**
     * @var array<string, bool> backend name => whether it has caught up with the primary
     */
    public array $caughtUp;

    /**
     * @var array<int, array{backend: string, sql: string, bind: mixed}>
     */
    public array $statements = [];

    /**
     * @var array<string, mixed> the config the last connection was opened with
     */
    public array $lastConfig = [];

    public int $lastReadTimeout = 0;

    private int $next = 0;

    /**
     * @param string[] $backends one name for a plain server, several for a pooling endpoint
     */
    public function __construct(array $backends = ['replica'])
    {
        $this->caughtUp = array_fill_keys($backends, true);
    }

    /**
     * @inheritDoc
     */
    public function connect(
        array $config,
        LoggerInterface $logger,
        SelectFactory $selectFactory,
        int $readTimeout
    ): ReplicaConnectionInterface {
        ++$this->connects;
        $this->lastConfig = $config;
        $this->lastReadTimeout = $readTimeout;

        if ($this->refuseConnections) {
            throw new RuntimeException('Connection refused by the invented replica.');
        }

        return new ReplicaDouble($this);
    }

    /**
     * Runs one statement on the backend whose turn it is, and answers with a row naming that backend.
     */
    public function run(string $sql, mixed $bind = []): RowsStatement
    {
        $backend = $this->nextBackend();
        $this->statements[] = ['backend' => $backend, 'sql' => $sql, 'bind' => $bind];

        if ($this->failOn !== '' && preg_match($this->failOn, $sql) === 1) {
            throw $this->failWith ?? new RuntimeException('The invented replica refused a statement.');
        }

        return new RowsStatement([['source' => $backend]]);
    }

    /**
     * The status question is answered without taking a backend's turn, so routing reads the same with it.
     *
     * @return array<string, string|null>
     */
    public function replicationStatus(): array
    {
        ++$this->statusChecks;

        if ($this->status === null) {
            throw new RuntimeException('Access denied; you need the SLAVE MONITOR privilege.');
        }

        return $this->status;
    }

    public function hasReached(string $position): bool
    {
        if ($this->failGtidCheck) {
            throw new RuntimeException('The invented replica failed the GTID check.');
        }

        $backend = $this->nextBackend();
        $this->statements[] = ['backend' => $backend, 'sql' => 'MASTER_GTID_WAIT', 'bind' => [$position]];

        return $this->caughtUp[$backend];
    }

    /**
     * @return string[] the statements the replica ran, in order, whichever backend ran them
     */
    public function sql(): array
    {
        return array_column($this->statements, 'sql');
    }

    /**
     * @return int how many GTID checks any backend answered
     */
    public function gtidChecks(): int
    {
        return count(array_keys($this->sql(), 'MASTER_GTID_WAIT', true));
    }

    private function nextBackend(): string
    {
        $backends = array_keys($this->caughtUp);
        $backend = $backends[$this->next % count($backends)];
        ++$this->next;

        return $backend;
    }
}
