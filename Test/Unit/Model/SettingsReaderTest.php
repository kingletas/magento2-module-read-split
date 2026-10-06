<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model;

use Kingletas\ReadSplit\Model\Settings;
use Kingletas\ReadSplit\Model\SettingsReader;
use Kingletas\ReadSplit\Model\SettingsState;
use Magento\Framework\App\DeploymentConfig;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The block lives at db/read_split, beside the connections, where no adapter ever reads it.
 */
class SettingsReaderTest extends TestCase
{
    private const array DEFAULT_CONNECTION = [
        'host' => 'db-primary.example',
        'dbname' => 'invented_store',
        'username' => 'invented_user',
        'password' => 'invented-password',
        'active' => '1',
    ];

    public function testAStoreWithNoBlockIsNotSplit(): void
    {
        $this->assertFalse($this->reader(null)->read(self::DEFAULT_CONNECTION)->isActive());
    }

    public function testTheBlockSplitsTheDefaultConnection(): void
    {
        $settings = $this->reader(['replica' => ['host' => 'db-replica.example']])->read(self::DEFAULT_CONNECTION);

        $this->assertTrue($settings->isActive());
        $this->assertSame('db-replica.example', $settings->replicaConfig()['host']);
    }

    /**
     * Magento's connection type adds these before its adapter sees the config, which the whole-array comparison missed.
     */
    public function testTheConfigMagentosConnectionTypeAddsStillMatches(): void
    {
        $validated = self::DEFAULT_CONNECTION + ['type' => 'pdo_mysql'];
        $validated['active'] = true;

        $settings = $this->reader(['replica' => ['host' => 'db-replica.example']])->read($validated);

        $this->assertTrue($settings->isActive());
        $this->assertSame(SettingsState::Active, $settings->state());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool}>
     */
    public static function connectionIdentities(): array
    {
        $base = ['dbname' => 'invented_store', 'username' => 'invented_user', 'password' => 'invented-password'];

        return [
            'the port written into the host, and the default port' => [
                ['host' => 'db:3306'] + $base, ['host' => 'db'] + $base, true,
            ],
            'the host and a separate port key' => [
                ['host' => 'db:3307'] + $base, ['host' => 'db', 'port' => 3307] + $base, true,
            ],
            'the host in another case' => [['host' => 'DB.example'] + $base, ['host' => 'db.example'] + $base, true],
            'other credentials, same server and database' => [
                ['host' => 'db'] + $base, ['host' => 'db', 'username' => 'invented_other'] + $base, true,
            ],
            'the same socket' => [['host' => '/run/a.sock'] + $base, ['host' => '/run/a.sock'] + $base, true],
            'another port' => [['host' => 'db:3307'] + $base, ['host' => 'db'] + $base, false],
            'another host' => [['host' => 'db-a'] + $base, ['host' => 'db-b'] + $base, false],
            'another database' => [['host' => 'db'] + $base, ['host' => 'db', 'dbname' => 'other_db'] + $base, false],
            'another socket' => [['host' => '/run/a.sock'] + $base, ['host' => '/run/b.sock'] + $base, false],
        ];
    }

    /**
     * @param array<string, mixed> $inEnv the connection as env.php holds it
     * @param array<string, mixed> $handed the connection config an adapter is given
     */
    #[DataProvider('connectionIdentities')]
    public function testConnectionsAreMatchedByServerAndDatabase(array $inEnv, array $handed, bool $splits): void
    {
        $reader = $this->reader(['replica' => ['host' => 'db-replica.example']], $inEnv);

        $this->assertSame($splits, $reader->read($handed)->isActive());
    }

    public function testAnotherConnectionIsNotSplit(): void
    {
        $indexer = ['host' => 'db-indexer.example'] + self::DEFAULT_CONNECTION;

        $this->assertFalse($this->reader(['replica' => ['host' => 'db-replica.example']])->read($indexer)->isActive());
    }

    /**
     * The position a write hands on is read from the default connection, so a block naming another would split
     * reads and never protect a visitor's own write.
     */
    public function testABlockNamingAnotherConnectionIsRefusedForEveryConnection(): void
    {
        $checkout = ['host' => 'db-checkout.example'] + self::DEFAULT_CONNECTION;
        $reader = $this->reader(['connection' => 'checkout', 'replica' => ['host' => 'db-replica.example']], $checkout);

        foreach ([$checkout, self::DEFAULT_CONNECTION] as $connection) {
            $settings = $reader->read($connection);

            $this->assertSame(SettingsState::Refused, $settings->state());
            $this->assertStringContainsString('only the default connection can be split', $settings->reason());
        }

        $this->assertSame(SettingsState::Refused, $reader->forTarget()->state());
    }

    public function testABlockNamingTheDefaultConnectionSplitsIt(): void
    {
        $reader = $this->reader(['connection' => 'default', 'replica' => ['host' => 'db-replica.example']]);

        $this->assertTrue($reader->read(self::DEFAULT_CONNECTION)->isActive());
    }

    /**
     * The block's old place is not read at all, since setup hands that config to a raw adapter.
     */
    public function testABlockLeftInsideTheConnectionIsIgnored(): void
    {
        $nested = self::DEFAULT_CONNECTION + ['read_split' => ['replica' => ['host' => 'db-replica.example']]];

        $this->assertFalse($this->reader(null, $nested)->read($nested)->isActive());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function switchedOff(): array
    {
        return [
            'false' => [false],
            'zero' => [0],
            'the string zero' => ['0'],
            'the string false' => ['false'],
            'empty' => [''],
        ];
    }

    #[DataProvider('switchedOff')]
    public function testTheKillSwitchTurnsItOff(mixed $enabled): void
    {
        $reader = $this->reader(['enabled' => $enabled, 'replica' => ['host' => 'db-replica.example']]);

        $this->assertFalse($reader->read(self::DEFAULT_CONNECTION)->isActive());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function noReplicaHost(): array
    {
        return [
            'no replica' => [[]],
            'a replica that is not a block' => [['replica' => 'db-replica.example']],
            'no host' => [['replica' => ['username' => 'invented_reader']]],
            'a blank host' => [['replica' => ['host' => '  ']]],
            'a host that is not text' => [['replica' => ['host' => 5]]],
            'a block that is not an array' => ['yes'],
        ];
    }

    #[DataProvider('noReplicaHost')]
    public function testWithoutAReplicaHostItIsRefusedWithTheReason(mixed $block): void
    {
        $settings = $this->reader($block)->read(self::DEFAULT_CONNECTION);

        $this->assertFalse($settings->isActive());
        $this->assertSame(SettingsState::Refused, $settings->state());
        $this->assertStringContainsString(is_array($block) ? 'replica' : 'db/read_split', $settings->reason());
    }

    public function testAReplicaWithNoDatabaseNameIsRefusedWhenTheSettingsAreRead(): void
    {
        $connection = self::DEFAULT_CONNECTION;
        unset($connection['dbname']);
        $settings = $this->reader(['replica' => ['host' => 'db-replica.example']], $connection)->read($connection);

        $this->assertSame(SettingsState::Refused, $settings->state());
        $this->assertStringContainsString('database name', $settings->reason());
    }

    public function testAReplicaNamingItsOwnDatabaseNeedsNoneOnTheConnection(): void
    {
        $connection = self::DEFAULT_CONNECTION;
        unset($connection['dbname']);
        $block = ['replica' => ['host' => 'db-replica.example', 'dbname' => 'invented_copy']];
        $reader = $this->reader($block, $connection);

        $this->assertTrue($reader->read($connection)->isActive());
    }

    public function testAConnectionNameThatIsNotAStringIsRefusedToo(): void
    {
        $settings = $this->reader(['connection' => 7, 'replica' => ['host' => 'db-replica.example']])
            ->read(self::DEFAULT_CONNECTION);

        $this->assertSame(SettingsState::Refused, $settings->state());
        $this->assertStringContainsString('only the default connection can be split', $settings->reason());
    }

    public function testADefaultConnectionWithNoHostIsRefusedWithTheReason(): void
    {
        $noHost = ['host' => '', 'dbname' => 'invented_store'];
        $reader = $this->reader(['replica' => ['host' => 'db-replica.example']], $noHost);

        foreach ([$reader->forTarget(), $reader->read(self::DEFAULT_CONNECTION)] as $settings) {
            $this->assertSame(SettingsState::Refused, $settings->state());
            $this->assertStringContainsString('has no host', $settings->reason());
        }
    }

    public function testTheStatesThatAreNotRefusals(): void
    {
        $block = ['replica' => ['host' => 'db-replica.example']];
        $indexer = ['host' => 'db-indexer.example'] + self::DEFAULT_CONNECTION;

        $this->assertSame(SettingsState::NotConfigured, $this->reader(null)->read(self::DEFAULT_CONNECTION)->state());
        $this->assertSame(
            SettingsState::SwitchedOff,
            $this->reader(['enabled' => false] + $block)->read(self::DEFAULT_CONNECTION)->state()
        );
        $this->assertSame(SettingsState::OtherConnection, $this->reader($block)->read($indexer)->state());
    }

    public function testTheTargetConnectionIsReadForTheStatusCommand(): void
    {
        $settings = $this->reader(['replica' => ['host' => 'db-replica.example:3307']])->forTarget();

        $this->assertTrue($settings->isActive());
        $this->assertSame(['db-replica.example', '3307', 'invented_store'], $settings->replicaIdentity());
    }

    public function testTheDefaults(): void
    {
        $settings = $this->reader(['replica' => ['host' => 'db-replica.example']])->read(self::DEFAULT_CONNECTION);

        $this->assertFalse($settings->isPooled());
        $this->assertSame(30, $settings->maxLag());
        $this->assertSame(60, $settings->positionLifetime(), 'As long as the replica may lag, and one check more');
        $this->assertFalse($settings->positionLifetimeWasRaised());
        $this->assertSame(2, $settings->replicaConfig()['driver_options'][PDO::ATTR_TIMEOUT]);
        $this->assertSame(5, $settings->readTimeout());
        $this->assertSame(
            [
                'session',
                'quote*',
                'quote_id_mask',
                'sales_order*',
                'customer_entity',
                'customer_visitor',
                'oauth_token',
                'jwt_auth_revoked',
                'persistent_session',
                'login_as_customer',
                'downloadable_link_purchased*',
            ],
            $settings->primaryOnlyTables()->names()
        );
    }

    /**
     * Lag is asked once every thirty seconds, so a replica in use can be max_lag and thirty seconds behind.
     */
    public function testAPositionLifetimeShorterThanMaxLagAndOneCheckIsRaisedToIt(): void
    {
        foreach ([10, 30, 59] as $configured) {
            $settings = $this->read(['position_lifetime' => $configured, 'max_lag' => 30]);

            $this->assertSame(60, $settings->positionLifetime());
            $this->assertTrue($settings->positionLifetimeWasRaised());
        }
    }

    public function testAPositionLifetimeAtOrAboveMaxLagAndOneCheckIsKept(): void
    {
        $this->assertSame(60, $this->read(['position_lifetime' => 60, 'max_lag' => 30])->positionLifetime());
        $this->assertSame(75, $this->read(['position_lifetime' => 75, 'max_lag' => 30])->positionLifetime());
        $this->assertFalse($this->read(['position_lifetime' => 75, 'max_lag' => 30])->positionLifetimeWasRaised());
        $this->assertFalse($this->read(['max_lag' => 30])->positionLifetimeWasRaised(), 'Nothing was configured');
    }

    public function testALongMaxLagRaisesThePositionPastItsUsualCeiling(): void
    {
        $this->assertSame(630, $this->read(['max_lag' => 600])->positionLifetime());
        $this->assertSame(300, $this->read(['max_lag' => 5, 'position_lifetime' => 9000])->positionLifetime());
    }

    /**
     * The ceiling once put a long lifetime below a long max_lag, with nothing said.
     */
    public function testTheUsualCeilingNeverPutsThePositionBelowMaxLagAndOneCheck(): void
    {
        $settings = $this->read(['max_lag' => 600, 'position_lifetime' => 9000]);

        $this->assertSame(630, $settings->positionLifetime());
        $this->assertFalse($settings->positionLifetimeWasRaised(), 'The store asked for longer, not shorter');
    }

    public function testPooledTakesTrueOrFalseAsEnvPhpMayWriteThem(): void
    {
        foreach ([true, 1, '1', 'true', 'TRUE'] as $pooled) {
            $this->assertTrue($this->read(['pooled' => $pooled])->isPooled());
        }

        foreach ([false, 0, '0', 'false'] as $notPooled) {
            $settings = $this->read(['pooled' => $notPooled]);
            $this->assertTrue($settings->isActive());
            $this->assertFalse($settings->isPooled());
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function mistypedSettings(): array
    {
        $replica = ['host' => 'db-replica.example'];

        return [
            'pooled written as a word' => [['pooled' => 'yes'], 'pooled takes true or false, and got "yes"'],
            'pooled as a number that is neither' => [['pooled' => 2], 'pooled takes true or false, and got 2'],
            'the kill switch written as off' => [['enabled' => 'off'], 'enabled takes true or false, and got "off"'],
            'the kill switch left empty' => [['enabled' => ''], 'enabled takes true or false, and got ""'],
            'max_lag with a unit' => [['max_lag' => '2s'], 'max_lag takes a whole number of seconds, and got "2s"'],
            'max_lag with a fraction' => [['max_lag' => 1.5], 'max_lag takes a whole number of seconds, and got 1.5'],
            'a read timeout that is a word' => [['read_timeout' => 'soon'], 'read_timeout takes a whole number'],
            'a connect timeout that is a list' => [
                ['connect_timeout' => [2]],
                'connect_timeout takes a whole number of seconds, and got something that is neither text nor a number',
            ],
            'a lifetime that is true' => [['position_lifetime' => true], 'position_lifetime takes a whole number'],
            'a key nobody knows' => [['max_lagg' => 5], 'a key this version does not know: "max_lagg"'],
            'a port beside the host' => [
                ['replica' => $replica + ['port' => '3307']],
                'replica has a key this version does not know: "port". A port goes in the host, as host:port',
            ],
            'a replica password that is not text' => [
                ['replica' => $replica + ['password' => 12345]],
                'replica.password takes text, and got something that is not text',
            ],
            'a replica user that is not text' => [
                ['replica' => $replica + ['username' => 7]],
                'replica.username takes text, and got 7',
            ],
            'tables that are not a list' => [['primary_only_tables' => 'session'], 'primary_only_tables takes a list'],
            'a table name with a typo' => [
                ['primary_only_tables' => ['invented_log', 'bad name; --']],
                'each a name or a name ending in *, and got "bad name; --"',
            ],
            'a star in the middle of a name' => [['primary_only_tables' => ['x*y']], 'and got "x*y"'],
            'a table that is a number' => [['primary_only_tables' => [7]], 'and got 7'],
        ];
    }

    /**
     * A value of the wrong kind was once read as the default, so a mistyped kill switch left the split on and a
     * mistyped pooled left the GTID check trusted behind a balancer. Each is now refused, with its key.
     *
     * @param array<string, mixed> $block
     */
    #[DataProvider('mistypedSettings')]
    public function testAMistypedSettingIsRefusedByNameAndNeverReadAsADefault(array $block, string $reason): void
    {
        $settings = $this->read($block);

        $this->assertSame(SettingsState::Refused, $settings->state());
        $this->assertFalse($settings->isActive());
        $this->assertStringContainsString($reason, $settings->reason());
    }

    public function testTheKillSwitchWorksWhateverElseInTheBlockIsWrong(): void
    {
        $settings = $this->read(['enabled' => false, 'max_lag' => 'soon', 'nonsense' => 1, 'pooled' => 'yes']);

        $this->assertSame(SettingsState::SwitchedOff, $settings->state());
    }

    public function testAReplicaPasswordIsNeverShownInARefusal(): void
    {
        $block = ['replica' => ['host' => 'db-replica.example', 'password' => 'invented-secret'], 'pooled' => 'yes'];

        $this->assertStringNotContainsString('invented-secret', $this->read($block)->reason());
    }

    /**
     * A password written without its quotes is a number, and the refusal once printed it into the log.
     */
    public function testAReplicaPasswordOfTheWrongKindIsRefusedWithoutBeingShown(): void
    {
        foreach ([90210417, 9021.0417, ['invented-secret']] as $password) {
            $settings = $this->read(['replica' => ['host' => 'db-replica.example', 'password' => $password]]);

            $this->assertSame(SettingsState::Refused, $settings->state());
            $this->assertStringContainsString('replica.password takes text', $settings->reason());
            $this->assertStringNotContainsString('9021', $settings->reason());
            $this->assertStringNotContainsString('invented-secret', $settings->reason());
        }
    }

    public function testConfiguredTablesAndPatternsAreAddedToTheDefaultsWithThePrefix(): void
    {
        $settings = $this->read(
            ['primary_only_tables' => ['invented_log', 'invented_audit*', 'Session']],
            'pfx_'
        );

        $this->assertSame(
            [
                'pfx_session',
                'pfx_quote*',
                'pfx_quote_id_mask',
                'pfx_sales_order*',
                'pfx_customer_entity',
                'pfx_customer_visitor',
                'pfx_oauth_token',
                'pfx_jwt_auth_revoked',
                'pfx_persistent_session',
                'pfx_login_as_customer',
                'pfx_downloadable_link_purchased*',
                'pfx_invented_log',
                'pfx_invented_audit*',
            ],
            $settings->primaryOnlyTables()->names()
        );
        $this->assertSame(['pfx_quote*'], $settings->primaryOnlyTables()->pinning(), 'Only the cart pins');
    }

    public function testNumbersAreKeptWithinTheirBounds(): void
    {
        $this->assertSame(5, $this->read(['max_lag' => 5])->maxLag());
        $this->assertSame(1, $this->read(['max_lag' => 0])->maxLag());
        $this->assertSame(86400, $this->read(['max_lag' => 999999])->maxLag());
        $this->assertSame(2, $this->read(['read_timeout' => 0])->readTimeout());
        $this->assertSame(2, $this->read(['read_timeout' => 1])->readTimeout(), 'The server stops a query first');
        $this->assertSame(60, $this->read(['read_timeout' => 600])->readTimeout());
        $options = $this->read(['connect_timeout' => 99])->replicaConfig()['driver_options'];
        $this->assertSame(30, $options[PDO::ATTR_TIMEOUT]);
    }

    public function testTheReplicaKeepsATimeoutTheStoreAlreadySet(): void
    {
        $driverOptions = [PDO::ATTR_TIMEOUT => 7, PDO::ATTR_PERSISTENT => false];
        $connection = self::DEFAULT_CONNECTION + ['driver_options' => $driverOptions];
        $reader = $this->reader(['replica' => ['host' => 'db-replica.example']], $connection);

        $options = $reader->read($connection)->replicaConfig()['driver_options'];

        $this->assertSame([PDO::ATTR_TIMEOUT => 7, PDO::ATTR_PERSISTENT => false], $options);
    }

    public function testTheReplicaTakesOnlyHostAndCredentialsFromTheBlock(): void
    {
        $connection = self::DEFAULT_CONNECTION + ['initStatements' => 'SET NAMES utf8mb4'];
        $reader = $this->reader(['replica' => [
            'host' => 'db-replica.example',
            'dbname' => 'invented_copy',
            'password' => 'invented-reader-password',
        ]], $connection);

        $replica = $reader->read($connection)->replicaConfig();

        $this->assertSame('db-replica.example', $replica['host']);
        $this->assertSame('invented_copy', $replica['dbname']);
        $this->assertSame('invented_user', $replica['username']);
        $this->assertSame('invented-reader-password', $replica['password']);
        $this->assertSame('SET NAMES utf8mb4', $replica['initStatements'], 'Connection setup matches the primary');
        $this->assertSame(['driver_options'], array_keys(array_filter($replica, 'is_array')), 'No array reaches a DSN');
    }

    /**
     * @param array<string, mixed> $block
     */
    private function read(array $block, string $tablePrefix = ''): Settings
    {
        return $this->reader($block + ['replica' => ['host' => 'db-replica.example']], null, $tablePrefix)
            ->read(self::DEFAULT_CONNECTION);
    }

    /**
     * @param array<string, mixed>|null $default the default connection as env.php holds it
     */
    private function reader(mixed $block, ?array $connection = null, string $tablePrefix = ''): SettingsReader
    {
        $connections = ['default' => $connection ?? self::DEFAULT_CONNECTION];

        if (($block['connection'] ?? null) === 'checkout') {
            $connections = ['default' => self::DEFAULT_CONNECTION, 'checkout' => $connection];
        }

        $deploymentConfig = $this->createStub(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(
            static fn (string $path): mixed => match (true) {
                $path === 'db/read_split' => $block,
                $path === 'db/table_prefix' => $tablePrefix,
                str_starts_with($path, 'db/connection/') => $connections[substr($path, 14)] ?? null,
                default => null,
            }
        );

        return new SettingsReader($deploymentConfig);
    }
}
