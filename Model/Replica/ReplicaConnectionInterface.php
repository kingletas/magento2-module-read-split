<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Replica;

use Zend_Db_Statement_Interface;

/**
 * An open connection to the replica.
 */
interface ReplicaConnectionInterface
{
    public function query(string $sql, mixed $bind): Zend_Db_Statement_Interface;

    /**
     * The replica's SHOW REPLICA STATUS row, empty when it has none; throws when it cannot say.
     *
     * @return array<string, mixed>
     */
    public function replicationStatus(): array;

    /**
     * Whether the replica has already applied every transaction up to this position, asked without waiting.
     */
    public function hasReached(string $position): bool;

    public function close(): void;
}
