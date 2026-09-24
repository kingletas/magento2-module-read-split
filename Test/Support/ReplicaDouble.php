<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Support;

use Kingletas\ReadSplit\Model\Replica\ReplicaConnectionInterface;
use Zend_Db_Statement_Interface;

/**
 * One open connection to the invented replica host.
 */
class ReplicaDouble implements ReplicaConnectionInterface
{
    public function __construct(
        private readonly ReplicaServer $server
    ) {
    }

    public function query(string $sql, mixed $bind): Zend_Db_Statement_Interface
    {
        return $this->server->run($sql, $bind);
    }

    public function replicationStatus(): array
    {
        return $this->server->replicationStatus();
    }

    public function hasReached(string $position): bool
    {
        return $this->server->hasReached($position);
    }

    public function close(): void
    {
        ++$this->server->closes;
    }
}
