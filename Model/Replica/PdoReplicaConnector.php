<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Replica;

use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Adapter\Pdo\MysqlFactory;
use Magento\Framework\DB\LoggerInterface;
use Magento\Framework\DB\SelectFactory;

/**
 * Opens the replica with Magento's own adapter, so it runs the same connection setup as the primary.
 */
class PdoReplicaConnector implements ReplicaConnectorInterface
{
    public function __construct(
        private readonly MysqlFactory $mysqlFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function connect(
        array $config,
        LoggerInterface $logger,
        SelectFactory $selectFactory
    ): ReplicaConnectionInterface {
        $adapter = $this->mysqlFactory->create(Mysql::class, $config, $logger, $selectFactory);
        $adapter->getConnection();

        return new PdoReplicaConnection($adapter);
    }
}
