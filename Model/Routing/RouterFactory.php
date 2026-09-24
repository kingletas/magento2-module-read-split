<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Routing;

use Kingletas\ReadSplit\Model\Gtid\PositionCookie;
use Kingletas\ReadSplit\Model\Replica\ReplicaConnectorInterface;
use Kingletas\ReadSplit\Model\Request\RequestScopeInterface;
use Kingletas\ReadSplit\Model\Request\WriteLedger;
use Kingletas\ReadSplit\Model\Settings;
use Kingletas\ReadSplit\Model\Statement\Classifier;
use Magento\Framework\DB\LoggerInterface as DbLogger;
use Magento\Framework\DB\SelectFactory;
use Psr\Log\LoggerInterface;

/**
 * Gives each split connection a router and a replica of its own, sharing the services that hold no request state.
 */
class RouterFactory
{
    public function __construct(
        private readonly Classifier $classifier,
        private readonly RequestScopeInterface $requestScope,
        private readonly PositionCookie $positionCookie,
        private readonly WriteLedger $writeLedger,
        private readonly ReplicaConnectorInterface $replicaConnector,
        private readonly LoggerInterface $logger
    ) {
    }

    public function create(Settings $settings, DbLogger $dbLogger, SelectFactory $selectFactory): Router
    {
        $replica = new Replica(
            $settings->replicaConfig(),
            $this->replicaConnector,
            $dbLogger,
            $selectFactory,
            $this->logger
        );

        return new Router(
            $settings,
            $this->classifier,
            $this->requestScope,
            $this->positionCookie,
            $this->writeLedger,
            $replica
        );
    }
}
