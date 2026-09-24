<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Performance;

use Kingletas\ReadSplit\Model\Adapter\ReadSplitMysql;
use Kingletas\ReadSplit\Test\Support\CountingClassifier;
use Kingletas\ReadSplit\Test\Support\CountingFile;
use Kingletas\ReadSplit\Test\Support\SplitAdapterTestCase;

/**
 * What a request costs beyond its reads, for one read and for two hundred, since only the reads may grow with them.
 */
class ReplicaCostTest extends SplitAdapterTestCase
{
    public function testOneConnectionPerRequestWhateverTheReads(): void
    {
        $connects = fn (): int => $this->replica->connects;

        $this->assertSame([1, 1], [$this->countFor(1, $connects), $this->countFor(200, $connects)]);
    }

    public function testOneGtidCheckPerRequestWhateverTheReads(): void
    {
        $checks = fn (): int => $this->replica->gtidChecks();

        $this->assertSame([1, 1], [$this->countFor(1, $checks, true), $this->countFor(200, $checks, true)]);
    }

    public function testTheReplayCostsOneStatementPerSessionSetWhateverTheReads(): void
    {
        $replayed = fn (): int => count(array_filter(
            $this->replica->sql(),
            static fn (string $sql): bool => str_starts_with($sql, 'SET ')
        ));

        $this->assertSame([3, 3], [$this->countFor(1, $replayed), $this->countFor(200, $replayed)]);
    }

    public function testTheCookieAndTheRequestAreEachReadOncePerRequest(): void
    {
        $reads = fn (): int => $this->cookies->reads + $this->scope->asked;

        $this->assertSame([2, 2], [$this->countFor(1, $reads), $this->countFor(200, $reads)]);
    }

    public function testTheBreakerMarkersAreLookedAtAFixedNumberOfTimesPerRequest(): void
    {
        $looks = [$this->markerLooksFor(1), $this->markerLooksFor(200)];

        $this->assertSame($looks[0], $looks[1]);
        $this->assertLessThanOrEqual(6, $looks[0], 'Two markers, each in var/ and in the temp directory');
    }

    private function markerLooksFor(int $reads): int
    {
        $this->setUp();
        $file = new CountingFile();
        $this->fileDriver = $file;
        $this->breaker($file)->claimHealthCheck();
        $file->looks = 0;
        $adapter = $this->adapter();

        for ($read = 0; $read < $reads; ++$read) {
            $adapter->fetchOne('SELECT * FROM store');
        }

        return $file->looks;
    }

    /**
     * A request that has written reads only from the primary, so its later statements are not even parsed.
     */
    public function testAfterTheFirstWriteNoStatementIsClassified(): void
    {
        $this->assertSame([2, 2], [$this->classifiedAfterAWrite(1), $this->classifiedAfterAWrite(200)]);
    }

    private function classifiedAfterAWrite(int $reads): int
    {
        $this->setUp();
        $this->classifier = new CountingClassifier();
        $adapter = $this->adapter();
        $adapter->fetchOne('SELECT * FROM store');
        $adapter->insert('quote', ['entity_id' => 3]);

        for ($read = 0; $read < $reads; ++$read) {
            $adapter->fetchOne('SELECT * FROM catalog_product_entity');
        }

        return $this->classifier instanceof CountingClassifier ? $this->classifier->classified : -1;
    }

    /**
     * Runs one request of three session SETs and the given number of reads, and returns what the counter counted.
     */
    private function countFor(int $reads, callable $counter, bool $withPosition = false): int
    {
        $this->setUp();

        if ($withPosition) {
            $this->arriveWithPosition('0-1-700');
        }

        $adapter = $this->adapter();
        $this->sessionSetup($adapter);

        for ($read = 0; $read < $reads; ++$read) {
            $adapter->fetchOne('SELECT * FROM store');
        }

        return $counter();
    }

    private function sessionSetup(ReadSplitMysql $adapter): void
    {
        $adapter->query("SET SQL_MODE=''");
        $adapter->query("SET time_zone = '+00:00'");
        $adapter->query('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');
    }
}
