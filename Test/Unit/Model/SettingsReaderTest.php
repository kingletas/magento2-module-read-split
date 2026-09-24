<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model;

use Kingletas\ReadSplit\Model\SettingsReader;
use Magento\Framework\App\DeploymentConfig;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SettingsReaderTest extends TestCase
{
    public function testAConnectionWithNoBlockIsNotSplit(): void
    {
        $this->assertFalse($this->reader()->read($this->primary())->isActive());
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
        $config = $this->primary(['enabled' => $enabled, 'replica' => ['host' => 'db-replica.example']]);

        $this->assertFalse($this->reader()->read($config)->isActive());
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
        $config = $this->primary();
        $config['read_split'] = $block;

        $this->assertFalse($this->reader()->read($config)->isActive());
    }

    public function testAReplicaHostTurnsItOnWithTheDefaults(): void
    {
        $settings = $this->reader()->read($this->primary(['replica' => ['host' => 'db-replica.example']]));

        $this->assertTrue($settings->isActive());
        $this->assertFalse($settings->isPooled());
        $this->assertSame(['session'], $settings->primaryOnlyTables());
        $this->assertSame(10, $settings->positionLifetime());
        $this->assertSame(2, $settings->replicaConfig()['driver_options'][PDO::ATTR_TIMEOUT]);
    }

    public function testOnlyTheBooleanTrueMakesItPooled(): void
    {
        $block = ['replica' => ['host' => 'db-replica.example']];

        $this->assertTrue($this->reader()->read($this->primary($block + ['pooled' => true]))->isPooled());
        $this->assertFalse($this->reader()->read($this->primary($block + ['pooled' => 'yes']))->isPooled());
    }

    public function testConfiguredTablesAreAddedToSessionWithThePrefixAndBadNamesAreDropped(): void
    {
        $settings = $this->reader('invented_')->read($this->primary([
            'replica' => ['host' => 'db-replica.example'],
            'primary_only_tables' => ['quote_id_mask', 'Session', 'bad name; --', 7],
        ]));

        $this->assertSame(['invented_session', 'invented_quote_id_mask'], $settings->primaryOnlyTables());
    }

    public function testNumbersAreKeptWithinTheirBounds(): void
    {
        $block = ['replica' => ['host' => 'db-replica.example']];

        $read = fn (array $extra) => $this->reader()->read($this->primary($block + $extra));

        $this->assertSame(300, $read(['position_lifetime' => 9000])->positionLifetime());
        $this->assertSame(1, $read(['position_lifetime' => '0'])->positionLifetime());
        $this->assertSame(10, $read(['position_lifetime' => 'soon'])->positionLifetime());
        $this->assertSame(30, $read(['connect_timeout' => 99])->replicaConfig()['driver_options'][PDO::ATTR_TIMEOUT]);
    }

    public function testTheReplicaKeepsATimeoutTheStoreAlreadySet(): void
    {
        $config = $this->primary(['replica' => ['host' => 'db-replica.example']]);
        $config['driver_options'] = [PDO::ATTR_TIMEOUT => 7, PDO::ATTR_PERSISTENT => false];

        $options = $this->reader()->read($config)->replicaConfig()['driver_options'];

        $this->assertSame([PDO::ATTR_TIMEOUT => 7, PDO::ATTR_PERSISTENT => false], $options);
    }

    public function testTheReplicaTakesOnlyHostAndCredentialsFromItsBlock(): void
    {
        $config = $this->primary(['replica' => [
            'host' => 'db-replica.example',
            'dbname' => 'invented_copy',
            'password' => 'invented-reader-password',
            'initStatements' => 'SET NAMES latin1',
        ]]);
        $config['initStatements'] = 'SET NAMES utf8mb4';

        $replica = $this->reader()->read($config)->replicaConfig();

        $this->assertSame('db-replica.example', $replica['host']);
        $this->assertSame('invented_copy', $replica['dbname']);
        $this->assertSame('invented_user', $replica['username']);
        $this->assertSame('invented-reader-password', $replica['password']);
        $this->assertSame('SET NAMES utf8mb4', $replica['initStatements'], 'Connection setup matches the primary');
        $this->assertArrayNotHasKey('read_split', $replica);
    }

    public function testTheCoreAdapterIsGivenTheConfigWithoutTheBlock(): void
    {
        $config = $this->primary(['replica' => ['host' => 'db-replica.example']]);

        $this->assertSame($this->primary(), $this->reader()->withoutSettings($config));
    }

    private function reader(string $tablePrefix = ''): SettingsReader
    {
        $deploymentConfig = $this->createStub(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturn($tablePrefix);

        return new SettingsReader($deploymentConfig);
    }

    /**
     * @param array<string, mixed>|null $block
     * @return array<string, mixed>
     */
    private function primary(?array $block = null): array
    {
        $config = [
            'host' => 'db-primary.example',
            'dbname' => 'invented_store',
            'username' => 'invented_user',
            'password' => 'invented-password',
            'active' => '1',
        ];

        if ($block !== null) {
            $config['read_split'] = $block;
        }

        return $config;
    }
}
