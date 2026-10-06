<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Routing;

use Kingletas\ReadSplit\Model\Replica\Breaker;
use Kingletas\ReadSplit\Model\Replica\ReplicaConnectionInterface;
use Kingletas\ReadSplit\Model\Replica\ReplicaConnectorInterface;
use Kingletas\ReadSplit\Model\Replica\ReplicationStatus;
use Magento\Framework\DB\LoggerInterface as DbLogger;
use Magento\Framework\DB\SelectFactory;
use PDOException;
use Throwable;

/**
 * One connection's replica for one request: opened at the first statement that may use it, given up at the first error.
 */
class Replica
{
    /**
     * MariaDB's error for a statement it stopped because it ran past max_statement_time.
     */
    private const int STATEMENT_RAN_TOO_LONG = 1969;

    /**
     * The refusals whose message names a database, a table, a column, a routine or a grant, and never the statement.
     */
    private const array NAMES_AN_OBJECT_OR_A_GRANT = [1044, 1049, 1054, 1142, 1143, 1146, 1305, 1370];

    private ?ReplicaConnectionInterface $connection = null;

    private bool $available = true;

    private ?bool $breakerAllows = null;

    /**
     * Set when a statement failed here, until the primary shows whether the statement or the replica was at fault.
     */
    private bool $unconfirmedFailure = false;

    private string $failed = '';

    /**
     * What the server said of a statement it refused as wrong for it, until the primary shows it was only here.
     */
    private string $refused = '';

    /**
     * @var array<int, array{0: string, 1: mixed}>
     */
    private array $sessionState = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly int $maxLag,
        private readonly int $readTimeout,
        private readonly ReplicaConnectorInterface $connector,
        private readonly Breaker $breaker,
        private readonly ReplicationStatus $replicationStatus,
        private readonly DbLogger $dbLogger,
        private readonly SelectFactory $selectFactory
    ) {
    }

    /**
     * False once the replica failed or was behind for this request, or while the node's breaker is open.
     */
    public function isAvailable(): bool
    {
        if ($this->available && $this->breakerAllows === null) {
            $this->breakerAllows = $this->breaker->allowsAttempt();
            $this->available = $this->breakerAllows;
        }

        return $this->available;
    }

    public function isOpen(): bool
    {
        return $this->connection !== null;
    }

    /**
     * Runs a read on the replica, or answers null so the caller asks the primary instead.
     *
     * @param string $position a GTID position the replica must have reached first, or empty
     */
    public function query(string $sql, mixed $bind, string $position): mixed
    {
        if (!$this->isAvailable()) {
            return null;
        }

        if ($this->connection === null) {
            try {
                $this->open($position);
            } catch (Throwable $failure) {
                $this->giveUp('opening it failed (' . $this->describe($failure) . ')');

                return null;
            }
        }

        try {
            return $this->connection?->query($sql, $bind);
        } catch (Throwable $failure) {
            $this->close();
            $this->available = false;

            if ($this->ranTooLong($failure)) {
                $this->sayAStatementRanTooLong();

                return null;
            }

            $this->refused = $this->refusal($failure);

            if ($this->refused !== '') {
                return null;
            }

            $this->unconfirmedFailure = true;
            $this->failed = $this->describe($failure);

            return null;
        }
    }

    /**
     * The primary answered what the replica failed, so the replica was at fault and the breaker opens.
     */
    public function confirmFailure(): void
    {
        if ($this->refused !== '') {
            $this->breaker->warnOccasionally(
                'refused',
                'Read split: the replica refused a statement the primary answered, so the primary answers the rest '
                . 'of that request. The replica stays in use. It said: ' . $this->refused . '. This is said at most '
                . 'once an hour on this node.'
            );
            $this->refused = '';
        }

        if ($this->unconfirmedFailure) {
            $this->unconfirmedFailure = false;
            $this->breaker->trip('it failed a statement the primary answered (' . $this->failed . ')');
        }
    }

    /**
     * Records a session SET in order, and runs it on the replica now if the replica is already open.
     */
    public function remember(string $sql, mixed $bind): void
    {
        $this->sessionState[] = [$sql, $bind];

        if ($this->connection === null) {
            return;
        }

        try {
            $this->connection->query($sql, $bind);
        } catch (Throwable $failure) {
            $this->giveUp('it failed a session SET (' . $this->describe($failure) . ')');
        }
    }

    public function close(): void
    {
        $connection = $this->connection;
        $this->connection = null;
        $connection?->close();
    }

    /**
     * Forgets what this request learned, for an application server that serves the next request from the same object.
     */
    public function reset(): void
    {
        $this->close();
        $this->available = true;
        $this->breakerAllows = null;
        $this->unconfirmedFailure = false;
        $this->refused = '';
        $this->sessionState = [];
    }

    /**
     * Connects, checks replication when due, checks the visitor's position, replays session state, and records success.
     */
    private function open(string $position): void
    {
        $this->connection = $this->connector->connect(
            $this->config,
            $this->dbLogger,
            $this->selectFactory,
            $this->readTimeout
        );

        if ($this->breaker->isProbing() || $this->breaker->claimHealthCheck()) {
            $problem = $this->replicationStatus->problem($this->connection->replicationStatus(), $this->maxLag);

            if ($problem !== null) {
                $this->giveUp($problem);

                return;
            }
        }

        if ($position !== '' && !$this->connection->hasReached($position)) {
            $this->breaker->recordSuccess();
            $this->close();
            $this->available = false;

            return;
        }

        foreach ($this->sessionState as [$sql, $bind]) {
            $this->connection->query($sql, $bind);
        }

        $this->breaker->recordSuccess();
    }

    /**
     * A statement stopped for running too long says something about the query, not about the replica.
     */
    private function ranTooLong(Throwable $failure): bool
    {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException && (int) ($cause->errorInfo[1] ?? 0) === self::STATEMENT_RAN_TOO_LONG) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the server said when it refused a statement as wrong for it, a missing table or grant: SQLSTATE class 42.
     * That is the statement's fault on this server, not a sign the server is unwell; empty for any other failure.
     */
    private function refusal(Throwable $failure): string
    {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException && str_starts_with((string) ($cause->errorInfo[0] ?? ''), '42')) {
                return $this->refusalWords($cause);
            }
        }

        return '';
    }

    /**
     * The server's message only where it names an object or a grant; any other can quote the statement, and with it
     * a shopper's data, so that one is told by its numbers alone.
     */
    private function refusalWords(PDOException $refusal): string
    {
        $code = (int) ($refusal->errorInfo[1] ?? 0);
        $words = rtrim(trim((string) ($refusal->errorInfo[2] ?? '')), '.');

        return in_array($code, self::NAMES_AN_OBJECT_OR_A_GRANT, true) && $words !== ''
            ? $words
            : 'error ' . $code . ', SQLSTATE ' . $refusal->errorInfo[0];
    }

    private function sayAStatementRanTooLong(): void
    {
        $this->breaker->warnOccasionally(
            'slow',
            'Read split: the replica stopped a statement that ran past ' . max(1, $this->readTimeout - 1)
            . ' seconds, and the primary was asked instead. The replica stays in use; a page that slow wants a rate '
            . 'limit in front of it. This is said at most once an hour on this node.'
        );
    }

    private function giveUp(string $reason): void
    {
        $this->close();
        $this->available = false;
        $this->breaker->trip($reason);
    }

    private function describe(Throwable $failure): string
    {
        return get_class($failure) . ' ' . $failure->getCode();
    }
}
