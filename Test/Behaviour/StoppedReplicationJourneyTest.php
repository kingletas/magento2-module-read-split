<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Behaviour;

use Kingletas\ReadSplit\Test\Support\VisitorJourneyTestCase;
use Psr\Log\LoggerInterface;

/**
 * The case that matters most: a replica that still answers while its replication has stopped, so every read from it
 * would be stale with no end, and no cookie is involved.
 */
class StoppedReplicationJourneyTest extends VisitorJourneyTestCase
{
    public function testStoppedReplicationSendsTheStorefrontToThePrimaryUntilItRunsAgain(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning');
        $this->logger->expects($this->once())->method('notice');

        $this->assertSame('replica', $this->pageRead());

        $this->replica->status = self::row('Yes', 'No', null);
        $this->clock->advance(30);
        $this->assertSame('primary', $this->pageRead());

        $this->clock->advance(15);
        $this->assertSame('primary', $this->pageRead());

        $this->replica->status = self::row('Yes', 'Yes', '2');
        $this->clock->advance(15);
        $this->assertSame('replica', $this->pageRead());

        $this->assertSame(3, $this->replica->statusChecks);
    }

    /**
     * A new storefront request, and where its catalog read was answered.
     */
    private function pageRead(): string
    {
        return $this->answeredBy($this->startRequest('GET'), 'SELECT * FROM catalog_product_entity');
    }

    /**
     * @return array<string, string|null>
     */
    private static function row(string $io, string $sql, ?string $lag): array
    {
        return ['Slave_IO_Running' => $io, 'Slave_SQL_Running' => $sql, 'Seconds_Behind_Master' => $lag];
    }
}
