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
     */
    public function __construct(
        private readonly SettingsState $state,
        private readonly string $reason = '',
        private readonly array $replicaConfig = [],
        private readonly bool $pooled = false,
        private readonly PrimaryOnlyTables $primaryOnlyTables = new PrimaryOnlyTables(),
        private readonly int $positionLifetime = 30,
        private readonly int $maxLag = 30,
        private readonly int $readTimeout = 5,
        private readonly bool $positionLifetimeWasRaised = false
    ) {
    }

    /**
     * False unless the block splits this connection and is complete; the store then runs the core adapter.
     */
    public function isActive(): bool
    {
        return $this->state === SettingsState::Active;
    }

    public function state(): SettingsState
    {
        return $this->state;
    }

    /**
     * Why a configured block is not in use, empty otherwise.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * The replica's host, port and database, never its credentials.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public function replicaIdentity(): array
    {
        return (new ConnectionIdentity())->of($this->replicaConfig);
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

    public function primaryOnlyTables(): PrimaryOnlyTables
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
