<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\ConfigOptionsListConstants;
use PDO;

/**
 * Reads the `db/read_split` block of env.php, which sits beside the connections so no adapter is ever handed it.
 */
class SettingsReader
{
    public const string PATH = 'db/read_split';

    private const array REPLICA_KEYS = ['host', 'dbname', 'username', 'password'];

    /**
     * Tables a stale read of which can lead Magento to write something wrong, so they are always read from the primary.
     */
    private const array DEFAULT_PRIMARY_ONLY = ['session', 'quote*', 'quote_id_mask', 'sales_order*'];

    private readonly ConnectionIdentity $connectionIdentity;

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
        $this->connectionIdentity = new ConnectionIdentity();
    }

    /**
     * The settings for one connection, active only when the block splits that connection and is complete.
     *
     * @param array<string, mixed> $connectionConfig
     */
    public function read(array $connectionConfig): Settings
    {
        $block = $this->deploymentConfig->get(self::PATH);

        if ($block === null) {
            return new Settings(SettingsState::NotConfigured);
        }

        if (!is_array($block)) {
            return new Settings(SettingsState::Refused, 'db/read_split is not a list of settings');
        }

        if (!$this->isSwitchedOn($block)) {
            return new Settings(SettingsState::SwitchedOff);
        }

        $name = is_string($block['connection'] ?? null) ? $block['connection'] : 'default';
        $target = $this->deploymentConfig->get('db/connection/' . $name);

        if (!is_array($target)) {
            return new Settings(
                SettingsState::Refused,
                'db/connection/' . $name . ', the connection db/read_split splits, does not exist'
            );
        }

        if (!$this->connectionIdentity->same($target, $connectionConfig)) {
            return new Settings(SettingsState::OtherConnection);
        }

        return $this->forConnection($connectionConfig, $block);
    }

    /**
     * The settings for the connection the block splits, as the status command reports them.
     */
    public function forTarget(): Settings
    {
        $block = $this->deploymentConfig->get(self::PATH);
        $name = is_array($block) && is_string($block['connection'] ?? null) ? $block['connection'] : 'default';
        $target = $this->deploymentConfig->get('db/connection/' . $name);

        return $this->read(is_array($target) ? $target : []);
    }

    /**
     * @param array<string, mixed> $connectionConfig
     * @param array<mixed> $block
     */
    private function forConnection(array $connectionConfig, array $block): Settings
    {
        if (!$this->hasReplicaHost($block)) {
            return new Settings(SettingsState::Refused, 'db/read_split has no replica host');
        }

        $replica = $this->replicaConfig($connectionConfig, $block);

        if ($this->connectionIdentity->of($replica)[2] === '') {
            return new Settings(
                SettingsState::Refused,
                'the replica has no database name: set replica.dbname, or dbname on the connection'
            );
        }

        $maxLag = $this->bounded($block['max_lag'] ?? null, 30, 1, 86400);
        $lifetime = $this->number($block['position_lifetime'] ?? null);

        return new Settings(
            state: SettingsState::Active,
            replicaConfig: $replica,
            pooled: ($block['pooled'] ?? false) === true,
            primaryOnlyTables: $this->primaryOnlyTables($block['primary_only_tables'] ?? []),
            positionLifetime: $lifetime === null || $lifetime < $maxLag ? $maxLag : min($lifetime, 300),
            maxLag: $maxLag,
            readTimeout: $this->bounded($block['read_timeout'] ?? null, 5, 1, 60),
            positionLifetimeWasRaised: $lifetime !== null && $lifetime < $maxLag
        );
    }

    /**
     * @param array<mixed> $block
     */
    private function isSwitchedOn(array $block): bool
    {
        $enabled = $block['enabled'] ?? true;

        return !in_array($enabled, [false, 0, '0', 'false', ''], true);
    }

    /**
     * @param array<mixed> $block
     */
    private function hasReplicaHost(array $block): bool
    {
        $replica = $block['replica'] ?? null;

        return is_array($replica) && is_string($replica['host'] ?? null) && trim($replica['host']) !== '';
    }

    /**
     * The replica inherits everything from the primary except where the block names its own host or credentials.
     *
     * @param array<string, mixed> $primary
     * @param array<mixed> $block
     * @return array<string, mixed>
     */
    private function replicaConfig(array $primary, array $block): array
    {
        $config = $primary;

        foreach (self::REPLICA_KEYS as $key) {
            if (is_string($block['replica'][$key] ?? null)) {
                $config[$key] = $block['replica'][$key];
            }
        }

        $driverOptions = is_array($config['driver_options'] ?? null) ? $config['driver_options'] : [];
        $driverOptions[PDO::ATTR_TIMEOUT] ??= $this->bounded($block['connect_timeout'] ?? null, 2, 1, 30);
        $config['driver_options'] = $driverOptions;

        return $config;
    }

    /**
     * @return string[]
     */
    private function primaryOnlyTables(mixed $configured): array
    {
        $prefix = (string) $this->deploymentConfig->get(ConfigOptionsListConstants::CONFIG_PATH_DB_PREFIX);
        $names = self::DEFAULT_PRIMARY_ONLY;

        foreach (is_array($configured) ? $configured : [] as $table) {
            if (is_string($table) && preg_match('/^\w+\*?$/D', $table) === 1) {
                $names[] = $table;
            }
        }

        return array_values(array_unique(array_map(
            static fn (string $table): string => strtolower($prefix . $table),
            $names
        )));
    }

    private function bounded(mixed $value, int $default, int $min, int $max): int
    {
        return max($min, min($max, $this->number($value) ?? $default));
    }

    /**
     * A whole number of seconds as env.php may write it, or null when the value is not one.
     */
    private function number(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
