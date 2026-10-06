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
 * An unwritable var/ with a dead replica, across separate requests: every adapter here is a fresh object graph, so
 * nothing held in memory can carry the breaker from one request to the next, and only the marker file can.
 */
class UnwritableVarBreakerTest extends SplitAdapterTestCase
{
    public function testOneSlowRequestPerThirtySecondsAndOneWarningPerTrip(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning');
        $this->makeVarUnwritable();
        $this->replica->refuseConnections = true;

        foreach ([0, 10, 10, 9] as $seconds) {
            $this->clock->advance($seconds);
            $this->assertSame('primary', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));
        }

        $this->assertSame(1, $this->replica->connects, 'One slow request in the first thirty seconds');

        foreach ([1, 5, 20, 5] as $seconds) {
            $this->clock->advance($seconds);
            $this->assertSame('primary', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));
        }

        $this->assertSame(3, $this->replica->connects, 'One retry at 30 seconds and one at 60');
        $this->assertNotSame([], $this->fallbackMarkers());
    }

    public function testTheReplicaComesBackThroughTheFallbackMarker(): void
    {
        $this->makeVarUnwritable();
        $this->replica->refuseConnections = true;
        $this->answeredBy($this->adapter(), 'SELECT * FROM store');
        $this->replica->refuseConnections = false;
        $this->clock->advance(30);

        $this->assertSame('replica', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));
        $this->assertSame('replica', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));
        $this->assertSame([], array_filter(
            $this->fallbackMarkers(),
            static fn (string $name): bool => str_ends_with($name, '.breaker')
        ));
    }
}
