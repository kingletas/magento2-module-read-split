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

    public function testAnotherConnectionIsNotSplit(): void
    {
        $indexer = ['host' => 'db-indexer.example'] + self::DEFAULT_CONNECTION;

        $this->assertFalse($this->reader(['replica' => ['host' => 'db-replica.example']])->read($indexer)->isActive());
    }

    public function testTheBlockCanNameTheConnectionItSplits(): void
    {
        $checkout = ['host' => 'db-checkout.example'] + self::DEFAULT_CONNECTION;
        $reader = $this->reader(['connection' => 'checkout', 'replica' => ['host' => 'db-replica.example']], $checkout);

        $this->assertTrue($reader->read($checkout)->isActive());
        $this->assertFalse($reader->read(self::DEFAULT_CONNECTION)->isActive());
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
    public function testWithoutAReplicaHostItIsOff(mixed $block): void
    {
        $this->assertFalse($this->reader($block)->read(self::DEFAULT_CONNECTION)->isActive());
    }

    public function testTheDefaults(): void
    {
        $settings = $this->reader(['replica' => ['host' => 'db-replica.example']])->read(self::DEFAULT_CONNECTION);

        $this->assertFalse($settings->isPooled());
        $this->assertSame(30, $settings->maxLag());
        $this->assertSame(30, $settings->positionLifetime(), 'A position lives as long as the replica may lag');
        $this->assertFalse($settings->positionLifetimeWasRaised());
        $this->assertSame(2, $settings->replicaConfig()['driver_options'][PDO::ATTR_TIMEOUT]);
        $this->assertSame(5, $settings->readTimeout());
        $this->assertSame(
            ['session', 'quote*', 'quote_id_mask', 'sales_order*'],
            $settings->primaryOnlyTables()
        );
    }

    public function testAPositionLifetimeShorterThanMaxLagIsRaisedToIt(): void
    {
        $settings = $this->read(['position_lifetime' => 10, 'max_lag' => 30]);

        $this->assertSame(30, $settings->positionLifetime());
        $this->assertTrue($settings->positionLifetimeWasRaised());
    }

    public function testAPositionLifetimeAtOrAboveMaxLagIsKept(): void
    {
        $this->assertSame(30, $this->read(['position_lifetime' => 30, 'max_lag' => 30])->positionLifetime());
        $this->assertSame(45, $this->read(['position_lifetime' => 45, 'max_lag' => 30])->positionLifetime());
        $this->assertFalse($this->read(['position_lifetime' => 45, 'max_lag' => 30])->positionLifetimeWasRaised());
    }

    public function testALongMaxLagRaisesThePositionPastItsUsualCeiling(): void
    {
        $this->assertSame(600, $this->read(['max_lag' => 600])->positionLifetime());
        $this->assertSame(300, $this->read(['max_lag' => 5, 'position_lifetime' => 9000])->positionLifetime());
    }

    public function testOnlyTheBooleanTrueMakesItPooled(): void
    {
        $this->assertTrue($this->read(['pooled' => true])->isPooled());
        $this->assertFalse($this->read(['pooled' => 'yes'])->isPooled());
    }

    public function testConfiguredTablesAndPatternsAreAddedToTheDefaultsWithThePrefix(): void
    {
        $settings = $this->read(
            ['primary_only_tables' => ['invented_log', 'invented_audit*', 'Session', 'bad name; --', 'x*y', 7]],
            'pfx_'
        );

        $this->assertSame(
            [
                'pfx_session',
                'pfx_quote*',
                'pfx_quote_id_mask',
                'pfx_sales_order*',
                'pfx_invented_log',
                'pfx_invented_audit*',
            ],
            $settings->primaryOnlyTables()
        );
    }

    public function testNumbersAreKeptWithinTheirBounds(): void
    {
        $this->assertSame(5, $this->read(['max_lag' => 5])->maxLag());
        $this->assertSame(1, $this->read(['max_lag' => 0])->maxLag());
        $this->assertSame(86400, $this->read(['max_lag' => 999999])->maxLag());
        $this->assertSame(30, $this->read(['max_lag' => 'soon'])->maxLag());
        $this->assertSame(1, $this->read(['read_timeout' => 0])->readTimeout());
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
            'initStatements' => 'SET NAMES latin1',
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
