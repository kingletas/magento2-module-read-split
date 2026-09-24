<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Observer;

use Kingletas\ReadSplit\Model\Adapter\ReadSplitMysql;
use Kingletas\ReadSplit\Model\Gtid\PositionCookie;
use Kingletas\ReadSplit\Model\Request\WriteLedger;
use Kingletas\ReadSplit\Model\Response\Cacheability;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\HTTP\PhpEnvironment\Request as HttpRequest;
use Magento\Framework\HTTP\PhpEnvironment\Response as HttpResponse;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Before a response that wrote is sent, gives the visitor the primary's position, unless a cache could store it.
 */
class CarryWritePosition implements ObserverInterface
{
    public function __construct(
        private readonly WriteLedger $writeLedger,
        private readonly Cacheability $cacheability,
        private readonly ResourceConnection $resourceConnection,
        private readonly PositionCookie $positionCookie,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        if (!$this->writeLedger->hasWritten()) {
            return;
        }

        $request = $observer->getEvent()->getData('request');
        $response = $observer->getEvent()->getData('response');

        if (!$request instanceof HttpRequest || !$response instanceof HttpResponse
            || $this->cacheability->isCacheable($request, $response)
        ) {
            return;
        }

        try {
            $connection = $this->resourceConnection->getConnection();

            if ($connection instanceof ReadSplitMysql) {
                $this->positionCookie->write(
                    $connection->primaryPosition(),
                    $connection->readSplitSettings()->positionLifetime()
                );
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                'Read split: the read-after-write position could not be set, so the next request may read stale data.',
                ['exception' => $e]
            );
        }
    }
}
