<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model\Adapter;

use Kingletas\ReadSplit\Test\Support\SplitAdapterTestCase;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A visitor who wrote in the last request reads from the replica only once the replica has applied that write.
 */
class ReadAfterWriteGateTest extends SplitAdapterTestCase
{
    public function testWithNoPositionTheReplicaAnswersWithoutAnyCheck(): void
    {
        $this->assertSame('replica', $this->answeredBy($this->adapter(), 'SELECT * FROM catalog_product_entity'));
        $this->assertSame(0, $this->replica->gtidChecks());
    }

    public function testAReplicaThatHasCaughtUpAnswers(): void
    {
        $this->arriveWithPosition('0-1-512');

        $this->assertSame('replica', $this->answeredBy($this->adapter(), 'SELECT * FROM catalog_product_entity'));
        $this->assertSame(['MASTER_GTID_WAIT', 'SELECT * FROM catalog_product_entity'], $this->replica->sql());
        $this->assertSame(['0-1-512'], $this->replica->statements[0]['bind'], 'The position is bound, not in the SQL');
    }

    public function testAReplicaThatHasNotCaughtUpLeavesTheWholeRequestToThePrimary(): void
    {
        $this->replica->caughtUp['replica'] = false;
        $this->arriveWithPosition('0-1-512');
        $adapter = $this->adapter();

        $answers = [
            $this->answeredBy($adapter, 'SELECT * FROM catalog_product_entity'),
            $this->answeredBy($adapter, 'SELECT * FROM catalog_product_entity_int'),
        ];

        $this->assertSame(['primary', 'primary'], $answers);
        $this->assertSame(['MASTER_GTID_WAIT'], $this->replica->sql(), 'The check is asked once, and nothing else');
        $this->assertSame(1, $this->replica->closes);
    }

    public function testTheCheckRunsOncePerRequestHoweverManyReadsFollow(): void
    {
        $this->arriveWithPosition('0-1-512');
        $adapter = $this->adapter();

        for ($read = 0; $read < 10; ++$read) {
            $this->answeredBy($adapter, 'SELECT * FROM catalog_product_entity');
        }

        $this->assertSame(1, $this->replica->gtidChecks());
    }

    public function testAnExpiredPositionIsIgnored(): void
    {
        $this->replica->caughtUp['replica'] = false;
        $this->arriveWithPosition('0-1-512', 61);

        $this->assertSame('replica', $this->answeredBy($this->adapter(), 'SELECT * FROM catalog_product_entity'));
        $this->assertSame(0, $this->replica->gtidChecks());
    }

    public function testAPositionTheWriterCouldNotReadHoldsTheVisitorOnThePrimary(): void
    {
        $this->arriveWithPosition('');

        $this->assertSame('primary', $this->answeredBy($this->adapter(), 'SELECT * FROM catalog_product_entity'));
        $this->assertSame(0, $this->replica->connects);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function valuesTheStoreDidNotWrite(): array
    {
        return [
            'a plain GTID' => ['0-1-512'],
            'SQL' => ["0-1-1', 0); DROP TABLE invented_table; --"],
            'the right shape, random bytes' => ['1:3:' . str_repeat('QUJD', 20)],
            'a legacy cipher' => ['0:2:' . str_repeat('QUJD', 20)],
            'the right shape, truncated' => ['1:3:QUJD'],
        ];
    }

    #[DataProvider('valuesTheStoreDidNotWrite')]
    public function testAForgedOrMalformedValueSendsThatRequestToThePrimaryAndNothingElse(string $value): void
    {
        $this->cookies->incoming['kingletas_read_split'] = $value;
        $adapter = $this->adapter();

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM catalog_product_entity'));
        $this->assertSame(0, $this->replica->connects, 'Nothing from the value reaches the replica');
        $this->assertFalse($this->ledger->hasWritten());
        $this->assertSame([], $this->cookies->set);
    }

    public function testAValueEncryptedWithAnotherKeyIsRejected(): void
    {
        $otherKey = $this->createStub(DeploymentConfig::class);
        $otherKey->method('get')->willReturn('ffffffffffffffffffffffffffffffff');
        $value = $this->encryptor($otherKey)->encrypt('rs1|1800000000|0-1-512');
        $this->cookies->incoming['kingletas_read_split'] = $value;

        $this->assertSame('primary', $this->answeredBy($this->adapter(), 'SELECT * FROM catalog_product_entity'));
        $this->assertSame(0, $this->replica->connects);
    }

    public function testPooledSkipsTheCheckAndKeepsAVisitorWithAPositionOnThePrimary(): void
    {
        $this->arriveWithPosition('0-1-512');
        $adapter = $this->adapter(['pooled' => true]);

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM catalog_product_entity'));
        $this->assertSame(0, $this->replica->connects);
        $this->assertSame(0, $this->replica->gtidChecks());
    }

    public function testPooledSendsAVisitorWithoutAPositionToTheReplica(): void
    {
        $this->assertSame('replica', $this->answeredBy($this->adapter(['pooled' => true]), 'SELECT * FROM cms_page'));
        $this->assertSame(0, $this->replica->gtidChecks());
    }

    public function testPooledReleasesTheVisitorOnceThePositionExpires(): void
    {
        $this->arriveWithPosition('0-1-512', 61);

        $this->assertSame('replica', $this->answeredBy($this->adapter(['pooled' => true]), 'SELECT * FROM cms_page'));
    }
}
