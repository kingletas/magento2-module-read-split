<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model\Adapter;

use Kingletas\ReadSplit\Test\Support\SplitAdapterTestCase;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use ReflectionProperty;
use Zend_Db_Adapter_Abstract;

/**
 * The rules that belong to the request rather than the statement: its kind, its transactions and what it did before.
 */
class RequestRoutingTest extends SplitAdapterTestCase
{
    public function testAStorefrontReadRequestSendsPlainReadsToTheReplica(): void
    {
        $this->scope->storefrontRead = true;

        $this->assertSame('replica', $this->answeredBy($this->adapter(), 'SELECT * FROM store'));
    }

    /**
     * A POST, the admin, REST, cron and any other area all reduce to "not a storefront read" in the request scope.
     */
    public function testAnyOtherRequestSendsEveryReadToThePrimaryAndNeverOpensTheReplica(): void
    {
        $this->scope->storefrontRead = false;
        $adapter = $this->adapter();

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM store'));
        $this->assertSame(0, $this->replica->connects);
    }

    public function testAReadBeforeTheAreaIsKnownGoesToThePrimaryAndTheQuestionIsAskedAgainLater(): void
    {
        $this->scope->storefrontRead = null;
        $adapter = $this->adapter();

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM core_config_data'));

        $this->scope->storefrontRead = true;

        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM store'));
    }

    public function testOnceTheAnswerIsKnownItIsNotAskedForEveryStatement(): void
    {
        $adapter = $this->adapter();

        for ($read = 0; $read < 5; ++$read) {
            $this->answeredBy($adapter, 'SELECT * FROM store');
        }

        $this->assertSame(1, $this->scope->asked);
    }

    public function testTheCommandLineRoutesNothingAndRecordsNothing(): void
    {
        $this->scope->commandLine = true;
        $adapter = $this->adapter();

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM store'));
        $adapter->query('INSERT INTO quote (entity_id) VALUES (1)');

        $this->assertSame(0, $this->replica->connects);
        $this->assertFalse($this->ledger->hasWritten());
        $this->assertSame(0, $this->scope->asked);
    }

    public function testAReadInsideATransactionGoesToThePrimary(): void
    {
        $adapter = $this->adapter();
        $adapter->beginTransaction();

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM catalog_product WHERE entity_id = 3'));

        $adapter->commit();
    }

    /**
     * Opening a transaction also pins, so this checks the transaction rule on its own, with nothing pinned.
     */
    public function testAnOpenTransactionKeepsReadsOnThePrimaryEvenWithNothingPinned(): void
    {
        $adapter = $this->adapter();
        (new ReflectionProperty(Mysql::class, '_transactionLevel'))->setValue($adapter, 1);

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM catalog_product WHERE entity_id = 3'));

        (new ReflectionProperty(Mysql::class, '_transactionLevel'))->setValue($adapter, 0);

        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM catalog_product WHERE entity_id = 3'));
    }

    /**
     * Opening a transaction is a statement that is not a plain SELECT, so it pins even when nothing in it writes.
     */
    public function testATransactionPinsTheRestOfTheRequest(): void
    {
        $adapter = $this->adapter();
        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM store'));

        $adapter->beginTransaction();
        $adapter->commit();

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM store'));
        $this->assertFalse($this->ledger->hasWritten());
    }

    public function testReadsBeforeTheFirstWriteGoToTheReplicaAndEveryReadAfterItToThePrimary(): void
    {
        $adapter = $this->adapter();

        $before = [
            $this->answeredBy($adapter, 'SELECT * FROM store'),
            $this->answeredBy($adapter, 'SELECT * FROM catalog_product_entity'),
        ];
        $adapter->insert('search_query', ['query_text' => 'invented words']);
        $after = [
            $this->answeredBy($adapter, 'SELECT * FROM store'),
            $this->answeredBy($adapter, 'SELECT * FROM search_query'),
        ];

        $this->assertSame(['replica', 'replica'], $before);
        $this->assertSame(['primary', 'primary'], $after);
    }

    public function testResettingForTheNextRequestUnpinsIt(): void
    {
        $adapter = $this->adapter();
        $adapter->insert('search_query', ['query_text' => 'invented words']);

        $adapter->_resetState();
        $this->ledger->_resetState();
        (new ReflectionProperty(Zend_Db_Adapter_Abstract::class, '_connection'))->setValue($adapter, $this->primary);

        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM store'));
        $this->assertFalse($this->ledger->hasWritten());
    }
}
