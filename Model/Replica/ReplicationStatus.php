<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Replica;

/**
 * Reads a SHOW REPLICA STATUS row and says what, if anything, makes the replica unfit to read from.
 */
class ReplicationStatus
{
    /**
     * @param array<string, mixed> $row
     * @return string|null the problem, or null when both threads run and the lag is within max_lag
     */
    public function problem(array $row, int $maxLag): ?string
    {
        if ($row === []) {
            return 'it gave no replication status';
        }

        foreach (['IO', 'SQL'] as $thread) {
            if ($this->column($row, 'Slave_' . $thread . '_Running', 'Replica_' . $thread . '_Running') !== 'Yes') {
                return 'its replication ' . $thread . ' thread is not running';
            }
        }

        $lag = $this->column($row, 'Seconds_Behind_Master', 'Seconds_Behind_Source');

        if (!ctype_digit($lag)) {
            return 'its replication lag is unknown';
        }

        return (int) $lag > $maxLag ? 'it is ' . $lag . ' seconds behind, over max_lag of ' . $maxLag : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function column(array $row, string $name, string $newerName): string
    {
        $value = $row[$name] ?? $row[$newerName] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
