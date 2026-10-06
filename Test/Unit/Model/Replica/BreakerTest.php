<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model\Replica;

use Kingletas\ReadSplit\Test\Support\SplitAdapterTestCase;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Phrase;
use Psr\Log\LoggerInterface;

/**
 * The breaker on its own: a marker file under var/ whose age decides whether this node tries the replica.
 */
class BreakerTest extends SplitAdapterTestCase
{
    /**
     * A breaker for no host would be shared by every misconfigured connection on the node, so it does nothing.
     */
    public function testABreakerForNoHostNeverAllowsTheReplicaAndNeverWritesAMarker(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->never())->method('warning');
        $breaker = $this->breaker()->withReplicaHost('');

        $this->assertFalse($breaker->allowsAttempt());
        $breaker->trip('invented reason');
        $this->assertFalse($breaker->claimHealthCheck());
        $this->assertSame([], $this->markers());
        $this->assertTrue($this->breaker()->withReplicaHost('db-replica.example')->allowsAttempt());
    }

    public function testAClosedBreakerAllowsTheReplicaAndLeavesNoFile(): void
    {
        $this->assertTrue($this->breaker()->allowsAttempt());
        $this->assertSame([], $this->markers());
    }

    public function testATripIsKeptUnderVarAndStopsEveryRequestForThirtySeconds(): void
    {
        $this->breaker()->trip('invented reason');

        $this->assertCount(1, $this->markers());
        $this->assertMatchesRegularExpression('/^kingletas_read_split-[0-9a-f]{16}\.breaker$/', $this->markers()[0]);
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
        $this->assertMatchesRegularExpression('/\.checked$/', implode(' ', $this->markers()));
    }

    public function testAnUnwritableVarFallsBackToTheTempDirectoryWithOneWarningPerTrip(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning')->with($this->stringContains('temp'));
        $this->makeVarUnwritable();

        $this->breaker()->trip('invented reason');
        $this->clock->advance(29);

        $this->assertFalse($this->breaker()->allowsAttempt());
        $this->assertCount(1, $this->fallbackMarkers());
        $this->clock->advance(1);
        $probe = $this->breaker();
        $this->assertTrue($probe->allowsAttempt());
        $probe->trip('invented reason');
        $this->assertFalse($this->breaker()->allowsAttempt());
    }

    public function testWithVarWritableNothingIsMadeUnderTheTempDirectory(): void
    {
        $this->breaker()->trip('invented reason');
        $this->breaker()->claimHealthCheck();

        $this->assertCount(2, $this->markers());
        $this->assertSame([], glob($this->tempDir . '/*') ?: []);
    }

    /**
     * A store whose var/ turned read-only with the breaker open is told which file is in the way, once per retry.
     */
    public function testAMarkerThatCannotBeRemovedIsNamedInTheWarningAndKeepsTheBreakerOpen(): void
    {
        $this->breaker()->trip('invented reason');
        $marker = $this->varDir . '/' . $this->markers()[0];
        $this->clock->advance(30);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning')->with($this->stringContains($marker));
        $this->logger->expects($this->never())->method('notice');
        $readOnly = new class extends File {
            public function deleteFile($path)
            {
                throw new FileSystemException(new Phrase('The invented directory is read-only.'));
            }
        };

        $probe = $this->breaker($readOnly);
        $this->assertTrue($probe->allowsAttempt());
        $probe->recordSuccess();

        $this->assertFileExists($marker);
        $this->assertFalse($this->breaker()->allowsAttempt(), 'The retry claimed the next thirty seconds');
    }

    /**
     * Every account on a host can write the system temp directory, so the markers there go where only this one can.
     */
    public function testTheFallbackIsADirectoryOnlyThisUserCanWrite(): void
    {
        $this->makeVarUnwritable();

        $this->breaker()->trip('invented reason');

        $this->assertSame(posix_geteuid(), fileowner($this->fallbackDir()));
        $this->assertSame(0700, fileperms($this->fallbackDir()) & 0777);
        $this->assertSame([], glob($this->tempDir . '/*.breaker') ?: [], 'Nothing sits where others can write');
    }

    public function testAMarkerPlantedInTheSharedTempDirectoryItselfIsNotBelieved(): void
    {
        $this->makeVarUnwritable();
        $this->breaker()->trip('invented reason');
        $name = $this->fallbackMarkers()[0];
        unlink($this->fallbackDir() . '/' . $name);

        touch($this->tempDir . '/' . $name, $this->clock->now());

        $this->assertTrue($this->breaker()->allowsAttempt());
        unlink($this->tempDir . '/' . $name);
    }

    public function testAFallbackDirectoryThatIsALinkToSomewhereElseIsNotBelievedOrWritten(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning')->with($this->stringContains('no breaker'));
        $this->makeVarUnwritable();
        $elsewhere = $this->tempDir . '/somebody-elses';
        mkdir($elsewhere, 0700);
        symlink($elsewhere, $this->fallbackDir());

        $this->breaker()->trip('invented reason');

        $this->assertSame([], glob($elsewhere . '/*') ?: [], 'Nothing was written through the link');
        $this->assertTrue($this->breaker()->allowsAttempt());
        unlink($this->fallbackDir());
        rmdir($elsewhere);
    }

    public function testAFallbackDirectoryOthersCanWriteIsNotBelieved(): void
    {
        $this->makeVarUnwritable();
        $this->breaker()->trip('invented reason');
        $this->assertFalse($this->breaker()->allowsAttempt());

        chmod($this->fallbackDir(), 0777);
        clearstatcache();

        $before = glob($this->fallbackDir() . '/*') ?: [];
        $this->assertTrue($this->breaker()->allowsAttempt(), 'Anyone could have put that marker there');
        $this->assertTrue($this->breaker()->claimHealthCheck(), 'With no marker to keep, replication is asked about');
        $this->assertSame($before, glob($this->fallbackDir() . '/*') ?: [], 'And nothing more is written there');
    }

    /**
     * A marker dated ahead would hold the breaker open, or the replication check off, for as long as it said.
     */
    public function testAMarkerDatedInTheFutureCountsAsNoMarker(): void
    {
        $this->breaker()->trip('invented reason');
        $this->breaker()->claimHealthCheck();

        foreach (glob($this->varDir . '/*') ?: [] as $marker) {
            touch($marker, $this->clock->now() + 86400);
        }

        clearstatcache();

        $this->assertTrue($this->breaker()->allowsAttempt());
        $this->assertTrue($this->breaker()->claimHealthCheck());
    }

    public function testARecoveryClosesAFallbackMarkerToo(): void
    {
        $this->makeVarUnwritable();
        $this->breaker()->trip('invented reason');
        $this->clock->advance(30);
        $probe = $this->breaker();
        $probe->allowsAttempt();

        $probe->recordSuccess();

        $this->assertSame([], $this->fallbackMarkers());
        $this->assertTrue($this->breaker()->allowsAttempt());
    }

    public function testWithNeitherDirectoryWritableThereIsNoBreakerAndATripWarnsWithoutThrowing(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->exactly(2))->method('warning')->with($this->stringContains('no breaker'));
        $this->makeVarUnwritable();
        rmdir($this->tempDir);

        $breaker = $this->breaker();
        $breaker->trip('invented reason');
        $this->assertTrue($this->breaker()->allowsAttempt());
        $this->assertTrue($this->breaker()->claimHealthCheck(), 'Nobody can record the claim, so every request asks');
        $this->assertTrue($this->breaker()->claimHealthCheck());
        $this->breaker()->trip('invented reason');

        mkdir($this->tempDir);
    }

    public function testTwoInstallationsOnOneNodeNeverShareAMarker(): void
    {
        $this->installRoot = '/invented/store-a';
        $this->breaker()->withReplicaHost('db-replica.example')->trip('invented reason');
        $this->installRoot = '/invented/store-b';

        $this->assertTrue($this->breaker()->withReplicaHost('db-replica.example')->allowsAttempt());
        $this->assertCount(1, $this->markers());
    }

    public function testTwoReplicaHostsNeverShareAMarker(): void
    {
        $this->breaker()->withReplicaHost('db-replica-a.example')->trip('invented reason');

        $this->assertFalse($this->breaker()->withReplicaHost('db-replica-a.example')->allowsAttempt());
        $this->assertTrue($this->breaker()->withReplicaHost('db-replica-b.example')->allowsAttempt());
    }

    public function testMarkerNamesDifferByInstallationAndByHost(): void
    {
        $names = [];

        foreach (['/invented/store-a', '/invented/store-b'] as $root) {
            foreach (['db-replica-a.example', 'db-replica-b.example'] as $host) {
                $this->installRoot = $root;
                $this->breaker()->withReplicaHost($host)->trip('invented reason');
            }
        }

        $names = $this->markers();
        $this->assertCount(4, array_unique($names));
    }

    /**
     * @return string[]
     */
    private function markers(): array
    {
        return array_map('basename', glob($this->varDir . '/*') ?: []);
    }
}
