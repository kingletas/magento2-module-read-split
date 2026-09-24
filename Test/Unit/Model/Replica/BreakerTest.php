<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model\Replica;

use Kingletas\ReadSplit\Test\Support\SplitAdapterTestCase;
use Psr\Log\LoggerInterface;

/**
 * The breaker on its own: a marker file under var/ whose age decides whether this node tries the replica.
 */
class BreakerTest extends SplitAdapterTestCase
{
    public function testAClosedBreakerAllowsTheReplicaAndLeavesNoFile(): void
    {
        $this->assertTrue($this->breaker()->allowsAttempt());
        $this->assertSame([], $this->markers());
    }

    public function testATripIsKeptUnderVarAndStopsEveryRequestForThirtySeconds(): void
    {
        $this->breaker()->trip('invented reason');

        $this->assertSame(['kingletas_read_split.breaker'], $this->markers());
        $this->clock->advance(29);
        $this->assertFalse($this->breaker()->allowsAttempt());
        $this->assertFalse($this->breaker()->allowsAttempt());
    }

    public function testAfterThirtySecondsOneRequestRetriesAndTheOthersWait(): void
    {
        $this->breaker()->trip('invented reason');
        $this->clock->advance(30);

        $first = $this->breaker();
        $this->assertTrue($first->allowsAttempt());
        $this->assertTrue($first->isProbing());
        $this->assertFalse($this->breaker()->allowsAttempt(), 'The retry claimed the next thirty seconds');
    }

    public function testOneWarningWhenItTripsAndOneNoticeWhenItRecovers(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning')->with($this->stringContains('invented reason'));
        $this->logger->expects($this->once())->method('notice');

        $this->breaker()->trip('invented reason');
        $this->breaker()->trip('invented reason');
        $this->clock->advance(30);
        $probe = $this->breaker();
        $probe->allowsAttempt();
        $probe->trip('invented reason');
        $this->clock->advance(30);
        $probe = $this->breaker();
        $probe->allowsAttempt();
        $probe->recordSuccess();
        $this->breaker()->recordSuccess();

        $this->assertSame([], $this->markers());
        $this->assertTrue($this->breaker()->allowsAttempt());
    }

    public function testAFailedRetryKeepsItOpenForAnotherThirtySeconds(): void
    {
        $this->breaker()->trip('invented reason');
        $this->clock->advance(30);
        $probe = $this->breaker();
        $probe->allowsAttempt();
        $probe->trip('invented reason');

        $this->clock->advance(29);
        $this->assertFalse($this->breaker()->allowsAttempt());
        $this->clock->advance(1);
        $this->assertTrue($this->breaker()->allowsAttempt());
    }

    public function testSuccessOnAClosedBreakerChangesNothing(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->never())->method('notice');

        $this->breaker()->recordSuccess();

        $this->assertSame([], $this->markers());
    }

    public function testTheReplicationCheckIsDueAtMostOnceEveryThirtySeconds(): void
    {
        $this->assertTrue($this->breaker()->claimHealthCheck());
        $this->assertFalse($this->breaker()->claimHealthCheck());
        $this->clock->advance(29);
        $this->assertFalse($this->breaker()->claimHealthCheck());
        $this->clock->advance(1);
        $this->assertTrue($this->breaker()->claimHealthCheck());
        $this->assertContains('kingletas_read_split.checked', $this->markers());
    }

    public function testAVarDirectoryItCannotWriteIsLoggedNotThrown(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->atLeastOnce())->method('warning');
        rmdir($this->varDir);

        $breaker = $this->breaker();
        $breaker->trip('invented reason');
        $breaker->claimHealthCheck();

        mkdir($this->varDir);
        $this->assertSame([], $this->markers());
    }

    /**
     * @return string[]
     */
    private function markers(): array
    {
        return array_map('basename', glob($this->varDir . '/*') ?: []);
    }
}
