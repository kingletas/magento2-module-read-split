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

/**
 * The replica host, one server or a pool of backends taking turns, recording each statement and failing when told to.
 */
class ReplicaServer implements ReplicaConnectorInterface
{
    public int $connects = 0;

    public int $closes = 0;

    public bool $refuseConnections = false;

    /**
     * A statement matching this pattern fails on the replica.
     */
    public string $failOn = '';

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
        SelectFactory $selectFactory
    ): ReplicaConnectionInterface {
        ++$this->connects;
        $this->lastConfig = $config;

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
            throw new RuntimeException('The invented replica refused a statement.');
        }

        return new RowsStatement([['source' => $backend]]);
    }

    public function hasReached(string $position): bool
    {
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
