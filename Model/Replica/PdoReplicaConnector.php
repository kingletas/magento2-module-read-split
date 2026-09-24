<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Replica;

use Magento\Framework\DB\Adapter\Pdo\MysqlFactory;
use Magento\Framework\DB\LoggerInterface;
use Magento\Framework\DB\SelectFactory;
use UnexpectedValueException;

/**
 * Opens the replica with Magento's adapter, so it runs the primary's connection setup, with a read timeout added.
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
        SelectFactory $selectFactory,
        int $readTimeout
    ): ReplicaConnectionInterface {
        $adapter = $this->mysqlFactory->create(ReplicaMysql::class, $config, $logger, $selectFactory);

        if (!$adapter instanceof ReplicaMysql) {
            throw new UnexpectedValueException('The replica adapter is not ' . ReplicaMysql::class . '.');
        }

        $adapter->connectWithin($readTimeout);

        return new PdoReplicaConnection($adapter);
    }
}
