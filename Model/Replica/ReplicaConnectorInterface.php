<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Replica;

use Magento\Framework\DB\LoggerInterface;
use Magento\Framework\DB\SelectFactory;

/**
 * Opens a connection to the replica, and throws when it cannot.
 */
interface ReplicaConnectorInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function connect(
        array $config,
        LoggerInterface $logger,
        SelectFactory $selectFactory
    ): ReplicaConnectionInterface;
}
