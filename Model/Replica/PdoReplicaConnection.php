<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Replica;

use Magento\Framework\DB\Adapter\Pdo\Mysql;
use UnexpectedValueException;
use Zend_Db_Statement_Interface;

/**
 * The replica, reached through Magento's own adapter.
 */
class PdoReplicaConnection implements ReplicaConnectionInterface
{
    public function __construct(
        private readonly Mysql $adapter
    ) {
    }

    /**
     * @inheritDoc
     */
    public function query(string $sql, mixed $bind): Zend_Db_Statement_Interface
    {
        $statement = $this->adapter->query($sql, $bind);

        if (!$statement instanceof Zend_Db_Statement_Interface) {
            throw new UnexpectedValueException('The replica returned no statement.');
        }

        return $statement;
    }

    /**
     * A timeout of zero makes the wait answer at once: 0 when the replica has caught up, -1 when it has not.
     */
    public function hasReached(string $position): bool
    {
        $answer = $this->adapter->fetchOne('SELECT MASTER_GTID_WAIT(?, 0)', [$position]);

        return $answer !== null && $answer !== false && (string) $answer === '0';
    }

    public function close(): void
    {
        $this->adapter->closeConnection();
    }
}
