<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Routing;

use Kingletas\ReadSplit\Model\Replica\ReplicaConnectionInterface;
use Kingletas\ReadSplit\Model\Replica\ReplicaConnectorInterface;
use Magento\Framework\DB\LoggerInterface as DbLogger;
use Magento\Framework\DB\SelectFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One connection's replica for one request: opened at the first statement that may use it, given up at the first error.
 */
class Replica
{
    private ?ReplicaConnectionInterface $connection = null;

    private bool $available = true;

    /**
     * @var array<int, array{0: string, 1: mixed}>
     */
    private array $sessionState = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly ReplicaConnectorInterface $connector,
        private readonly DbLogger $dbLogger,
        private readonly SelectFactory $selectFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * False once the replica failed or was behind for this request, which sends the rest of it to the primary.
     */
    public function isAvailable(): bool
    {
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
        if (!$this->available) {
            return null;
        }

        $stage = $this->connection === null ? 'open' : 'query';

        try {
            $connection = $this->connection ?? $this->open($position);

            return $connection?->query($sql, $bind);
        } catch (Throwable $e) {
            $this->giveUp($e, $stage);

            return null;
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
        } catch (Throwable $e) {
            $this->giveUp($e, 'session state');
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
        $this->sessionState = [];
    }

    private function open(string $position): ?ReplicaConnectionInterface
    {
        $connection = $this->connector->connect($this->config, $this->dbLogger, $this->selectFactory);
        $this->connection = $connection;

        if ($position !== '' && !$connection->hasReached($position)) {
            $this->close();
            $this->available = false;

            return null;
        }

        foreach ($this->sessionState as [$sql, $bind]) {
            $connection->query($sql, $bind);
        }

        return $connection;
    }

    private function giveUp(Throwable $failure, string $stage): void
    {
        $this->close();
        $this->available = false;
        $this->logger->warning(
            'Read split: the replica failed at ' . $stage . ', so this request reads from the primary.',
            ['exception_class' => get_class($failure), 'code' => $failure->getCode()]
        );
    }
}
