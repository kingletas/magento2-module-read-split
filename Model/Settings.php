<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model;

/**
 * The read split one connection was configured with in env.php, already checked.
 */
class Settings
{
    /**
     * @param array<string, mixed> $replicaConfig the connection config the replica is opened with
     * @param string[] $primaryOnlyTables table names as the database knows them, prefix included
     */
    public function __construct(
        private readonly bool $active,
        private readonly array $replicaConfig = [],
        private readonly bool $pooled = false,
        private readonly array $primaryOnlyTables = [],
        private readonly int $positionLifetime = 30,
        private readonly int $maxLag = 30,
        private readonly int $readTimeout = 5,
        private readonly bool $positionLifetimeWasRaised = false
    ) {
    }

    /**
     * False when the kill switch is off or no replica is configured, and the store then runs the core adapter.
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @return array<string, mixed>
     */
    public function replicaConfig(): array
    {
        return $this->replicaConfig;
    }

    /**
     * True when the replica host may move statements between backends, so a GTID check on it proves nothing.
     */
    public function isPooled(): bool
    {
        return $this->pooled;
    }

    /**
     * @return string[]
     */
    public function primaryOnlyTables(): array
    {
        return $this->primaryOnlyTables;
    }

    /**
     * Seconds the replica may take to answer before the request gives up on it, from connect to the last row.
     */
    public function readTimeout(): int
    {
        return $this->readTimeout;
    }

    /**
     * True when env.php asked for a position shorter than max_lag, which was raised to it.
     */
    public function positionLifetimeWasRaised(): bool
    {
        return $this->positionLifetimeWasRaised;
    }

    /**
     * Seconds the replica may be behind before it is taken out of use.
     */
    public function maxLag(): int
    {
        return $this->maxLag;
    }

    /**
     * Seconds a visitor's read-after-write position is honoured.
     */
    public function positionLifetime(): int
    {
        return $this->positionLifetime;
    }
}
