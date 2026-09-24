<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Replica;

use Magento\Framework\DB\Adapter\ConnectionException;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\LoggerInterface;
use Magento\Framework\DB\SelectFactory;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Setup\Declaration\Schema\Dto\Factories\Table as DtoFactoriesTable;
use Magento\Framework\Stdlib\DateTime;
use Magento\Framework\Stdlib\StringUtils;
use PDO;

/**
 * Magento's adapter for the replica, with a read timeout of its own and no reconnect within a request.
 */
class ReplicaMysql extends Mysql
{
    private int $readTimeout = 5;

    private bool $opened = false;

    /**
     * The timeout switch comes before `$config`, since Magento builds adapters by argument name.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(
        StringUtils $string,
        DateTime $dateTime,
        LoggerInterface $logger,
        SelectFactory $selectFactory,
        private readonly NetReadTimeout $netReadTimeout,
        array $config = [],
        ?SerializerInterface $serializer = null,
        ?DtoFactoriesTable $dtoFactoriesTable = null
    ) {
        parent::__construct($string, $dateTime, $logger, $selectFactory, $config, $serializer, $dtoFactoriesTable);
    }

    /**
     * Connects now, so a replica that refuses or stalls fails here, within the read timeout.
     */
    public function connectWithin(int $readTimeout): void
    {
        $this->readTimeout = $readTimeout;
        $this->getConnection();
    }

    /**
     * The server stops a slow query a second before the client would stop waiting for it.
     *
     * @return string[]
     */
    public function sessionGuards(int $readTimeout): array
    {
        return [sprintf('SET SESSION max_statement_time = %d', max(1, $readTimeout - 1))];
    }

    /**
     * @inheritDoc
     */
    protected function _connect()
    {
        if ($this->_connection) {
            parent::_connect();

            return;
        }

        if ($this->opened) {
            throw new ConnectionException('The replica is not reconnected within a request.');
        }

        $this->opened = true;
        $previous = $this->netReadTimeout->apply($this->readTimeout);

        try {
            parent::_connect();
        } finally {
            $this->netReadTimeout->restore($previous);
        }

        $connection = $this->_connection;

        if (!$connection instanceof PDO) {
            return;
        }

        foreach ($this->sessionGuards($this->readTimeout) as $sql) {
            $connection->query($sql);
        }
    }
}
