<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model\Adapter;

use Kingletas\ReadSplit\Test\Support\SplitAdapterTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every statement rule, in both directions: what must reach the primary does, and what may reach the replica does.
 */
class StatementRoutingTest extends SplitAdapterTestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function plainReads(): array
    {
        return [
            'a select' => ['SELECT `e`.* FROM `catalog_product_entity` AS `e` WHERE (`e`.`entity_id` = 7)'],
            'lower case, with a trailing semicolon' => ['select sku from catalog_product_entity where entity_id = 7;'],
            'a union in parentheses' => ['(SELECT 1 AS a FROM store) UNION ALL (SELECT 2 AS a FROM store_website)'],
            'a lock phrase inside a string value' => ["SELECT * FROM search_query WHERE query_text = 'FOR UPDATE'"],
            'a lock phrase inside a comment' => ['SELECT * FROM store /* FOR UPDATE */ WHERE store_id = 1'],
            'a column named after a listed table' => ['SELECT session_id FROM customer_visitor WHERE visitor_id = 3'],
            'a user variable, read' => ['SELECT @invented_counter FROM store'],
        ];
    }

    #[DataProvider('plainReads')]
    public function testAPlainSelectGoesToTheReplica(string $sql): void
    {
        $this->assertSame('replica', $this->answeredBy($this->adapter(), $sql));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function primaryOnlySelects(): array
    {
        return [
            'FOR UPDATE' => ['SELECT * FROM sequence_order_1 WHERE sequence_value = 5 FOR UPDATE'],
            'FOR UPDATE NOWAIT' => ['SELECT * FROM quote WHERE entity_id = 5 FOR UPDATE NOWAIT'],
            'LOCK IN SHARE MODE' => ['SELECT * FROM quote WHERE entity_id = 5 LOCK IN SHARE MODE'],
            'FOR SHARE' => ['SELECT * FROM quote WHERE entity_id = 5 FOR SHARE'],
            'GET_LOCK' => ["SELECT GET_LOCK('invented_lock', 5);"],
            'RELEASE_LOCK' => ["SELECT RELEASE_LOCK('invented_lock');"],
            'IS_USED_LOCK' => ["SELECT IS_USED_LOCK('invented_lock');"],
            'LAST_INSERT_ID()' => ['SELECT LAST_INSERT_ID()'],
            'FOUND_ROWS()' => ['SELECT FOUND_ROWS()'],
            'SQL_CALC_FOUND_ROWS' => ['SELECT SQL_CALC_FOUND_ROWS * FROM review LIMIT 10'],
            'a lock inside an executable comment' => ['SELECT * FROM quote /*!50000 FOR UPDATE */'],
            'INTO a variable' => ['SELECT MAX(entity_id) INTO @invented_max FROM quote'],
            'a variable assigned in a select' => ['SELECT @invented_row := entity_id FROM quote'],
            'a server variable' => ['SELECT @@version'],
            'SHOW' => ["SHOW TABLE STATUS LIKE 'quote'"],
            'DESCRIBE' => ['DESCRIBE `quote`'],
            'EXPLAIN' => ['EXPLAIN SELECT * FROM quote'],
        ];
    }

    #[DataProvider('primaryOnlySelects')]
    public function testASelectThatIsNotPlainGoesToThePrimaryAndPinsTheRequest(string $sql): void
    {
        $adapter = $this->adapter();

        $this->assertSame('primary', $this->answeredBy($adapter, $sql));
        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM store'));
        $this->assertSame([], $this->replica->sql());
        $this->assertFalse($this->ledger->hasWritten(), 'A read that pins is not a write, so it carries no position');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function writes(): array
    {
        return [
            'INSERT' => ['INSERT INTO `quote_id_mask` (`quote_id`, `masked_id`) VALUES (?, ?)'],
            'INSERT ... ON DUPLICATE KEY UPDATE' => [
                'INSERT INTO `search_query` (`query_text`) VALUES (?) ON DUPLICATE KEY UPDATE `popularity` = 2',
            ],
            'UPDATE' => ['UPDATE `customer_visitor` SET `last_visit_at` = ? WHERE (visitor_id = 3)'],
            'DELETE' => ['DELETE FROM `inventory_pickup_location_quote_address` WHERE (address_id = 9)'],
            'REPLACE' => ['REPLACE INTO `invented_table` (`id`) VALUES (1)'],
            'a temporary table' => ['CREATE TEMPORARY TABLE `invented_tmp` (`id` int)'],
            'TRUNCATE' => ['TRUNCATE TABLE `invented_tmp`'],
            'a sequence moved on by a select' => ['SELECT NEXTVAL(invented_sequence)'],
            'a common table expression' => ['WITH x AS (SELECT 1) SELECT * FROM x'],
            'an unknown statement' => ["DO SLEEP(0)"],
            'SET autocommit' => ['SET autocommit = 0'],
            'SET TRANSACTION' => ['SET TRANSACTION ISOLATION LEVEL READ COMMITTED'],
            'SET GLOBAL' => ["SET GLOBAL sql_mode = ''"],
            'SET @@global.' => ["SET @@global.time_zone = '+00:00'"],
            'SET STATEMENT ... FOR' => ['SET STATEMENT max_statement_time = 1 FOR UPDATE quote SET is_active = 0'],
            'SET CHARACTER SET' => ['SET CHARACTER SET utf8mb4'],
            'a user variable read from a table' => ['SET @invented_id = (SELECT MAX(entity_id) FROM quote)'],
        ];
    }

    #[DataProvider('writes')]
    public function testAWriteGoesToThePrimaryPinsTheRequestAndIsRecorded(string $sql): void
    {
        $adapter = $this->adapter();
        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM store'));

        $adapter->query($sql);

        $this->assertContains($sql, $this->primary->executed);
        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM store'));
        $this->assertSame(['SELECT * FROM store'], $this->replica->sql());
        $this->assertTrue($this->ledger->hasWritten());
    }

    /**
     * The core adapter refuses two statements in query(), but its deprecated multiQuery() still passes them through.
     */
    public function testASecondStatementBehindASelectIsTreatedAsAWrite(): void
    {
        $adapter = $this->adapter();

        $adapter->multiQuery('SELECT 1 FROM store; DELETE FROM quote WHERE entity_id = 3');

        $this->assertSame([], $this->replica->sql());
        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM store'));
        $this->assertTrue($this->ledger->hasWritten());
    }

    public function testTheInsertIdIsAlwaysThePrimarys(): void
    {
        $adapter = $this->adapter();
        $this->primary->insertId = '8207';
        $this->answeredBy($adapter, 'SELECT * FROM store');

        $adapter->insert('quote_id_mask', ['quote_id' => 3, 'masked_id' => 'invented-mask']);

        $this->assertSame('8207', (string) $adapter->lastInsertId());
        $this->assertSame(['SELECT * FROM store'], $this->replica->sql());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sessionStateSets(): array
    {
        return [
            'NAMES' => ['SET NAMES utf8mb4 COLLATE utf8mb4_general_ci'],
            'SQL_MODE' => ["SET SQL_MODE=''"],
            'time_zone' => ["SET time_zone = '+00:00'"],
            'time_zone by its session name' => ["SET @@session.time_zone = '+00:00'"],
            'SESSION sql_mode' => ["SET SESSION sql_mode = 'STRICT_ALL_TABLES'"],
            'a character_set_ variable' => ['SET character_set_client = utf8mb4'],
            'a collation_ variable' => ['SET collation_connection = utf8mb4_general_ci'],
            'a user variable' => ['SET @invented_flag = 1'],
            'a user variable with :=' => ['SET @invented_flag := 2'],
            'several at once' => ["SET @invented_flag = 1, sql_mode = '', @@local.time_zone = '+00:00'"],
        ];
    }

    #[DataProvider('sessionStateSets')]
    public function testAnAllowListedSetRunsOnThePrimaryWithoutPinningTheRequest(string $sql): void
    {
        $adapter = $this->adapter();

        $adapter->query($sql);

        $this->assertSame([$sql], $this->primary->executed);
        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM store'));
        $this->assertFalse($this->ledger->hasWritten());
    }

    public function testAReadOfAPrimaryOnlyTableGoesToThePrimaryWithoutPinningTheRequest(): void
    {
        $adapter = $this->adapter();

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT `session`.* FROM `session` WHERE (id = 1)'));
        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM store'));
    }

    public function testATableAddedToTheListIsReadFromThePrimaryAndSessionStaysOnIt(): void
    {
        $adapter = $this->adapter(['primary_only_tables' => ['quote_id_mask']]);

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM quote_id_mask WHERE quote_id = 3'));
        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM session WHERE session_id = 1'));
        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM quote WHERE entity_id = 3'));
    }

    public function testThePrimaryOnlyListFollowsTheTablePrefix(): void
    {
        $adapter = $this->adapter([], 'invented_');

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM `invented_session` WHERE id = 1'));
        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM invented_store'));
    }

    public function testAWriteToAPrimaryOnlyTableStillPinsTheRequest(): void
    {
        $adapter = $this->adapter();

        $adapter->query('UPDATE `session` SET `session_data` = ? WHERE (session_id = ?)', ['invented', 'x']);

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM store'));
    }

    public function testAQueryHookKeepsEveryStatementOnThePrimary(): void
    {
        $adapter = $this->adapter();
        $adapter->setQueryHook(['object' => new class {
            public function observe(string $sql, array $bind): void
            {
            }
        }, 'method' => 'observe']);

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM store'));
    }

    public function testAWritePreparedOutsideTheQueryPathStillPinsTheRequest(): void
    {
        $adapter = $this->adapter();

        $adapter->prepare('UPDATE quote SET is_active = 0 WHERE entity_id = 3')->execute();

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM store'));
        $this->assertTrue($this->ledger->hasWritten());
    }

    public function testAReadPreparedOutsideTheQueryPathRunsOnThePrimaryWithoutPinning(): void
    {
        $adapter = $this->adapter();

        $adapter->prepare('SELECT * FROM quote WHERE entity_id = 3')->execute();

        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM store'));
        $this->assertFalse($this->ledger->hasWritten());
    }

    public function testAWriteRunThroughExecPinsTheRequest(): void
    {
        $adapter = $this->adapter();

        $adapter->exec('DELETE FROM quote WHERE entity_id = 3');

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM store'));
        $this->assertTrue($this->ledger->hasWritten());
    }
}
