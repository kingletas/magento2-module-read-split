<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model\Adapter;

use Kingletas\ReadSplit\Test\Support\SplitAdapterTestCase;
use Psr\Log\LoggerInterface;

/**
 * A position shorter than max_lag lost a cart on the store, so it is raised to max_lag, and the store is told once.
 */
class PositionLifetimeTest extends SplitAdapterTestCase
{
    public function testAShortLifetimeIsRaisedToMaxLagWithOneWarningAcrossRequests(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning')->with($this->stringContains('position_lifetime'));
        $this->replica->caughtUp['replica'] = false;
        $this->arriveWithPosition('0-1-512', 20);

        for ($request = 0; $request < 3; ++$request) {
            $adapter = $this->adapter(['position_lifetime' => 10, 'max_lag' => 30]);
            $this->assertSame(30, $adapter->readSplitSettings()->positionLifetime());
            $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM catalog_product_entity'));
        }
    }

    public function testALifetimeAtMaxLagWarnsNothing(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->never())->method('warning');

        $this->answeredBy($this->adapter(['position_lifetime' => 30, 'max_lag' => 30]), 'SELECT * FROM store');
        $this->answeredBy($this->adapter(), 'SELECT * FROM store');
    }
}
