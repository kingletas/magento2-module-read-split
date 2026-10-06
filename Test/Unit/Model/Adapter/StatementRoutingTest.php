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
            'a column named after a listed table' => ['SELECT session_id FROM invented_visit_log WHERE visit_id = 3'],
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
            'FOR UPDATE NOWAIT' => ['SELECT * FROM catalog_product_entity WHERE entity_id = 5 FOR UPDATE NOWAIT'],
            'LOCK IN SHARE MODE' => ['SELECT * FROM catalog_product_entity WHERE entity_id = 5 LOCK IN SHARE MODE'],
            'FOR SHARE' => ['SELECT * FROM catalog_product_entity WHERE entity_id = 5 FOR SHARE'],
            'GET_LOCK' => ["SELECT GET_LOCK('invented_lock', 5);"],
            'GET_LOCK with its name quoted' => ["SELECT `GET_LOCK`('invented_lock', 5)"],
            'RELEASE_LOCK' => ["SELECT RELEASE_LOCK('invented_lock');"],
            'IS_USED_LOCK' => ["SELECT IS_USED_LOCK('invented_lock');"],
            'LAST_INSERT_ID()' => ['SELECT LAST_INSERT_ID()'],
            'FOUND_ROWS()' => ['SELECT FOUND_ROWS()'],
            'SQL_CALC_FOUND_ROWS' => ['SELECT SQL_CALC_FOUND_ROWS * FROM review LIMIT 10'],
            'a lock inside an executable comment' => ['SELECT * FROM quote /*!50000 FOR UPDATE */'],
            'INTO a variable' => ['SELECT MAX(entity_id) INTO @invented_max FROM catalog_product_entity'],
            'a variable assigned in a select' => ['SELECT @invented_row := entity_id FROM catalog_product_entity'],
            'a server variable' => ['SELECT @@version'],
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
    public static function metadataReads(): array
    {
        return [
            'SHOW TABLE STATUS' => ["SHOW TABLE STATUS LIKE 'quote'"],
            'SHOW CREATE TABLE' => ['SHOW CREATE TABLE `quote`'],
            'DESCRIBE' => ['DESCRIBE `quote`'],
            'DESC' => ['DESC `quote`'],
            'EXPLAIN' => ['EXPLAIN SELECT * FROM catalog_product_entity'],
        ];
    }

    /**
     * After a deploy or a cache flush nearly every request describes tables, and pinning them would end the offload.
     */
    #[DataProvider('metadataReads')]
    public function testAMetadataReadGoesToThePrimaryWithoutPinningTheRequest(string $sql): void
    {
        $adapter = $this->adapter();

        $this->assertSame('primary', $this->answeredBy($adapter, $sql));
        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM store'));
        $this->assertSame(['SELECT * FROM store'], $this->replica->sql());
        $this->assertFalse($this->ledger->hasWritten());
    }

    public function testAMetadataReadPreparedOutsideTheQueryPathDoesNotPinEither(): void
    {
        $adapter = $this->adapter();

        $adapter->prepare('DESCRIBE `quote`')->execute();

        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM store'));
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
            'a user variable read from a table' => ['SET @invented_id = (SELECT MAX(entity_id) FROM cms_page)'],
            'a user variable set from a function' => ['SET @invented_id = UUID()'],
            'a lock taken into a user variable' => ["SET @invented_lock = GET_LOCK('invented_lock', 5)"],
            'a function beside an allowed variable' => ["SET sql_mode = '', @invented_id = UUID()"],
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

        $adapter->multiQuery('SELECT 1 FROM store; DELETE FROM catalog_product WHERE entity_id = 3');

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

    public function testATableAddedToTheListIsReadFromThePrimaryAndTheDefaultsStay(): void
    {
        $adapter = $this->adapter(['primary_only_tables' => ['invented_log', 'invented_audit*']]);

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM invented_log WHERE id = 3'));
        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM invented_audit_entry WHERE id = 3'));
        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM session WHERE session_id = 1'));
        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM invented_logbook WHERE id = 3'));
        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM quote WHERE entity_id = 3'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function readsThatDecideWhetherASessionOrTokenStillStands(): array
    {
        return [
            'the customer, with the cutoff a password change writes' => [
                'SELECT `customer_entity`.* FROM `customer_entity` WHERE (entity_id = 12)',
            ],
            'the visitor row a session is checked against' => ['SELECT * FROM customer_visitor WHERE visitor_id = 4'],
            'an integration or customer token' => ['SELECT * FROM `oauth_token` WHERE (token = ?)'],
            'a revoked web token' => ['SELECT * FROM jwt_auth_revoked WHERE user_type_id = 3 AND user_id = 12'],
            'a persistent session' => ['SELECT * FROM persistent_session WHERE `key` = ?'],
            'an admin logged in as a customer' => ['SELECT COUNT(*) FROM login_as_customer WHERE customer_id = ?'],
            'how often a bought link was downloaded' => [
                'SELECT * FROM `downloadable_link_purchased_item` WHERE (link_hash = ?)',
            ],
            'the purchase a bought link belongs to' => [
                'SELECT * FROM downloadable_link_purchased WHERE order_id = 82',
            ],
            'a join onto the customer' => [
                'SELECT r.* FROM review AS r INNER JOIN customer_entity AS c ON c.entity_id = r.customer_id',
            ],
        ];
    }

    /**
     * Read from a lagging replica, these would accept a session or token the primary has already ended.
     */
    #[DataProvider('readsThatDecideWhetherASessionOrTokenStillStands')]
    public function testAReadThatDecidesWhetherASessionStillStandsGoesToThePrimaryWithoutPinning(string $sql): void
    {
        $adapter = $this->adapter();

        $this->assertSame('primary', $this->answeredBy($adapter, $sql));
        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM catalog_product_entity'));
    }

    public function testTheCustomerAttributeTablesAreStillReadFromTheReplica(): void
    {
        $adapter = $this->adapter();
        $tables = [
            'customer_entity_varchar',
            'customer_entity_int',
            'customer_address_entity',
            'oauth_token_request_log',
        ];

        foreach ($tables as $table) {
            $sql = 'SELECT * FROM ' . $table . ' WHERE entity_id = 12';

            $this->assertSame('replica', $this->answeredBy($adapter, $sql), $table);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function readsThatCouldMakeMagentoWriteSomethingWrong(): array
    {
        return [
            'the order' => ['SELECT * FROM `sales_order` WHERE (entity_id = 82)'],
            'order items' => ['SELECT * FROM sales_order_item WHERE order_id = 82'],
            'the order grid' => ['SELECT * FROM sales_order_grid WHERE entity_id = 82'],
            'a customer' => ['SELECT * FROM customer_entity WHERE entity_id = 4'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function readsOfTheCart(): array
    {
        return [
            'the quote' => ['SELECT `main_table`.* FROM `quote` AS `main_table` WHERE (entity_id = 707)'],
            'quote items' => ['SELECT * FROM `quote_item` WHERE (quote_id = 707)'],
            'quote addresses' => ['SELECT * FROM quote_address WHERE quote_id = 707'],
            'a quote item option' => ['SELECT * FROM quote_item_option WHERE item_id = 9'],
            'shipping rates' => ['SELECT * FROM quote_shipping_rate WHERE address_id = 9'],
            'the masked cart id' => ['SELECT * FROM quote_id_mask WHERE masked_id = ?'],
            'a join onto the quote' => ['SELECT p.* FROM cms_page AS p INNER JOIN quote_item AS q ON q.item_id = p.id'],
        ];
    }

    /**
     * A cart read from the primary beside prices and stock read from a replica that is behind was once totalled
     * from the two, and the totals were saved.
     */
    #[DataProvider('readsOfTheCart')]
    public function testAReadOfTheCartSendsTheRestOfTheRequestToThePrimary(string $sql): void
    {
        $adapter = $this->adapter();

        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM store'), 'Before the cart is read');
        $this->assertSame('primary', $this->answeredBy($adapter, $sql));
        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM catalog_product_entity'));
        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM cataloginventory_stock_item'));
        $this->assertSame('replica', $this->answeredBy($this->adapter(), 'SELECT * FROM store'), 'The next request');
    }

    public function testTheCartsTablesFollowTheTablePrefix(): void
    {
        $adapter = $this->adapter([], 'invented_');

        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM quote_item'), 'No such table here');
        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM invented_quote_item'));
        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM invented_store'));
    }

    public function testATableTheStoreAddsToTheListDoesNotPin(): void
    {
        $adapter = $this->adapter(['primary_only_tables' => ['invented_quote*']]);

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM invented_quote_log'));
        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM store'));
    }

    /**
     * A stale read of these can make Magento write something wrong, as an empty quote read did when it dropped a cart.
     */
    #[DataProvider('readsThatCouldMakeMagentoWriteSomethingWrong')]
    public function testAReadThatCouldMakeMagentoWriteSomethingWrongGoesToThePrimaryWithoutPinning(string $sql): void
    {
        $adapter = $this->adapter();

        $this->assertSame('primary', $this->answeredBy($adapter, $sql));
        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM catalog_product_entity'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function tablesThePatternsDoNotReach(): array
    {
        return [
            'cart price rules' => ['SELECT * FROM salesrule WHERE rule_id = 1'],
            'invoices' => ['SELECT * FROM sales_invoice WHERE entity_id = 1'],
            'a sequence table for orders' => ['SELECT * FROM sequence_order_1 WHERE sequence_value = 1'],
            'a table ending in quote' => ['SELECT * FROM invented_quote WHERE id = 1'],
        ];
    }

    #[DataProvider('tablesThePatternsDoNotReach')]
    public function testTablesThePatternsDoNotReachStillGoToTheReplica(string $sql): void
    {
        $this->assertSame('replica', $this->answeredBy($this->adapter(), $sql));
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

    /**
     * A statement run under a query hook reaches the primary unrouted, and a write among them still has to pin.
     */
    public function testAWriteUnderAQueryHookPinsTheRequestAndIsRecorded(): void
    {
        $adapter = $this->adapter();
        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM store'));
        $adapter->setQueryHook(['object' => new class {
            public function observe(string $sql, array $bind): void
            {
            }
        }, 'method' => 'observe']);
        $adapter->query('UPDATE invented_table SET flag = 1');
        $adapter->setQueryHook(null);

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM store'));
        $this->assertTrue($this->ledger->hasWritten());
    }

    /**
     * A stock env.php gives the indexer connection the same server, so one request can hold two split connections.
     */
    public function testAWriteThroughOneSplitConnectionPinsTheRequestsOtherOne(): void
    {
        $default = $this->adapter();
        $indexer = $this->adapter();
        $this->assertSame('replica', $this->answeredBy($indexer, 'SELECT * FROM store'));

        $default->insert('invented_table', ['id' => 1]);

        $this->assertSame('primary', $this->answeredBy($indexer, 'SELECT * FROM store'));
        $this->assertSame('primary', $this->answeredBy($default, 'SELECT * FROM store'));
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

        $adapter->prepare('SELECT * FROM catalog_product WHERE entity_id = 3')->execute();

        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM store'));
        $this->assertFalse($this->ledger->hasWritten());
    }

    /**
     * A cart read that reaches the primary outside the query path is still a cart read.
     */
    public function testACartReadPreparedOutsideTheQueryPathPinsTheRequestWithoutCountingAsAWrite(): void
    {
        $adapter = $this->adapter();

        $adapter->prepare('SELECT * FROM quote WHERE entity_id = 3')->execute();

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM store'));
        $this->assertFalse($this->ledger->hasWritten());
    }

    public function testACartReadUnderAQueryHookPinsTheRequest(): void
    {
        $adapter = $this->adapter();
        $hook = ['object' => new class {
            public function observe(string $sql, array $bind): void
            {
            }
        }, 'method' => 'observe'];
        $adapter->setQueryHook($hook);
        $this->answeredBy($adapter, 'SELECT * FROM quote_item WHERE quote_id = 3');
        $adapter->setQueryHook(null);

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM store'));
    }

    public function testAWriteRunThroughExecPinsTheRequest(): void
    {
        $adapter = $this->adapter();

        $adapter->exec('DELETE FROM catalog_product WHERE entity_id = 3');

        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM store'));
        $this->assertTrue($this->ledger->hasWritten());
    }
}
