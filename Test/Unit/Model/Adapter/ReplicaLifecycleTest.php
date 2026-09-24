<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model\Adapter;

use Kingletas\ReadSplit\Test\Support\SplitAdapterTestCase;
use PDO;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Zend_Db_Adapter_Abstract;

/**
 * When the replica connection opens, what it is told before its first read, and what happens when it fails.
 */
class ReplicaLifecycleTest extends SplitAdapterTestCase
{
    public function testTheReplicaIsNotOpenedUntilAStatementMayGoThere(): void
    {
        $adapter = $this->adapter();
        $adapter->query("SET time_zone = '+00:00'");
        $this->answeredBy($adapter, 'SELECT * FROM session WHERE session_id = 1');

        $this->assertSame(0, $this->replica->connects);

        $this->answeredBy($adapter, 'SELECT * FROM store');

        $this->assertSame(1, $this->replica->connects);
    }

    public function testARequestThatWritesFirstNeverOpensTheReplica(): void
    {
        $adapter = $this->adapter();
        $adapter->insert('quote', ['entity_id' => 3]);

        for ($read = 0; $read < 3; ++$read) {
            $this->answeredBy($adapter, 'SELECT * FROM catalog_product_entity');
        }

        $this->assertSame(0, $this->replica->connects);
    }

    public function testTheReplicaIsOpenedOnceForManyReads(): void
    {
        $adapter = $this->adapter();

        for ($read = 0; $read < 20; ++$read) {
            $this->answeredBy($adapter, 'SELECT * FROM store');
        }

        $this->assertSame(1, $this->replica->connects);
    }

    public function testSessionStateSetBeforeTheReplicaOpensIsReplayedInOrderBeforeItsFirstRead(): void
    {
        $adapter = $this->adapter();
        $adapter->query('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        $adapter->query("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");
        $adapter->query('SET @invented_flag = ?', [7]);

        $this->answeredBy($adapter, 'SELECT * FROM store');

        $this->assertSame(
            [
                'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
                "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'",
                'SET @invented_flag = ?',
                'SELECT * FROM store',
            ],
            $this->replica->sql()
        );
        $this->assertSame([7], $this->replica->statements[2]['bind']);
    }

    public function testSessionStateSetAfterTheReplicaOpenedRunsOnBoth(): void
    {
        $adapter = $this->adapter();
        $this->answeredBy($adapter, 'SELECT * FROM store');

        $adapter->query("SET time_zone = '+02:00'");

        $this->assertContains("SET time_zone = '+02:00'", $this->primary->executed);
        $this->assertSame(['SELECT * FROM store', "SET time_zone = '+02:00'"], $this->replica->sql());
    }

    public function testAnyOtherSetIsNeverReplayed(): void
    {
        $adapter = $this->adapter();
        $this->answeredBy($adapter, 'SELECT * FROM store');

        $adapter->query('SET autocommit = 1');

        $this->assertSame(['SELECT * FROM store'], $this->replica->sql());
    }

    public function testTheReplicaInheritsThePrimarysConfigExceptWhatItsBlockNames(): void
    {
        $adapter = $this->adapter([
            'replica' => ['host' => 'db-replica.example:3307', 'username' => 'invented_reader'],
            'connect_timeout' => 3,
        ]);

        $this->answeredBy($adapter, 'SELECT * FROM store');

        $this->assertSame('db-replica.example:3307', $this->replica->lastConfig['host']);
        $this->assertSame('invented_reader', $this->replica->lastConfig['username']);
        $this->assertSame('invented_store', $this->replica->lastConfig['dbname']);
        $this->assertSame('invented-password', $this->replica->lastConfig['password']);
        $this->assertSame(3, $this->replica->lastConfig['driver_options'][PDO::ATTR_TIMEOUT]);
        $this->assertSame(5, $this->replica->lastReadTimeout);
        $this->assertArrayNotHasKey('read_split', $this->replica->lastConfig);
    }

    public function testARefusedConnectionSendsTheReadAndTheRestOfTheRequestToThePrimary(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning');
        $this->replica->refuseConnections = true;
        $adapter = $this->adapter();

        $answers = [
            $this->answeredBy($adapter, 'SELECT * FROM store'),
            $this->answeredBy($adapter, 'SELECT * FROM store_website'),
        ];

        $this->assertSame(['primary', 'primary'], $answers);
        $this->assertSame(1, $this->replica->connects, 'A replica that refused is not asked again this request');
    }

    public function testAStatementTheReplicaFailsIsAnsweredByThePrimary(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning');
        $this->replica->failOn = '/catalog_product_entity/';
        $adapter = $this->adapter();

        $answers = [
            $this->answeredBy($adapter, 'SELECT * FROM store'),
            $this->answeredBy($adapter, 'SELECT * FROM catalog_product_entity'),
            $this->answeredBy($adapter, 'SELECT * FROM store_website'),
        ];

        $this->assertSame(['replica', 'primary', 'primary'], $answers);
        $this->assertContains('SELECT * FROM catalog_product_entity', $this->primary->executed);
        $this->assertSame(1, $this->replica->closes);
    }

    public function testAFailedReplayGivesUpTheReplicaBeforeItAnswersAnything(): void
    {
        $this->replica->failOn = '/^SET NAMES/';
        $adapter = $this->adapter();
        $adapter->query('SET NAMES utf8mb4');

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM store'));
        $this->assertSame(['SET NAMES utf8mb4'], $this->replica->sql());
    }

    public function testASessionSetTheOpenReplicaFailsGivesItUp(): void
    {
        $adapter = $this->adapter();
        $this->answeredBy($adapter, 'SELECT * FROM store');
        $this->replica->failOn = '/^SET time_zone/';

        $adapter->query("SET time_zone = '+02:00'");

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM store'));
    }

    public function testClosingTheConnectionClosesTheReplicaAndTheNextReadReopensIt(): void
    {
        $adapter = $this->adapter();
        $adapter->query("SET time_zone = '+00:00'");
        $this->answeredBy($adapter, 'SELECT * FROM store');

        $adapter->closeConnection();
        $this->primary->executed = [];
        (new ReflectionProperty(Zend_Db_Adapter_Abstract::class, '_connection'))->setValue($adapter, $this->primary);
        $this->answeredBy($adapter, 'SELECT * FROM store');

        $this->assertSame(1, $this->replica->closes);
        $this->assertSame(2, $this->replica->connects);
        $this->assertSame(
            ["SET time_zone = '+00:00'", 'SELECT * FROM store', "SET time_zone = '+00:00'", 'SELECT * FROM store'],
            $this->replica->sql()
        );
    }
}
