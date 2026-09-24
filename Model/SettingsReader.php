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
 * Reads the `read_split` block of a connection's env.php config, and refuses anything it cannot use.
 */
class SettingsReader
{
    public const string KEY = 'read_split';

    private const array REPLICA_KEYS = ['host', 'dbname', 'username', 'password'];

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly string $alwaysPrimaryTable = 'session'
    ) {
    }

    /**
     * @param array<string, mixed> $connectionConfig
     */
    public function read(array $connectionConfig): Settings
    {
        $block = $connectionConfig[self::KEY] ?? null;

        if (!is_array($block) || !$this->isSwitchedOn($block) || !$this->hasReplicaHost($block)) {
            return new Settings(active: false);
        }

        return new Settings(
            active: true,
            replicaConfig: $this->replicaConfig($this->withoutSettings($connectionConfig), $block),
            pooled: ($block['pooled'] ?? false) === true,
            primaryOnlyTables: $this->primaryOnlyTables($block['primary_only_tables'] ?? []),
            positionLifetime: $this->bounded($block['position_lifetime'] ?? null, 10, 1, 300),
            maxLag: $this->bounded($block['max_lag'] ?? null, 30, 1, 86400)
        );
    }

    /**
     * The config the core adapter is given, which never carries this module's block.
     *
     * @param array<string, mixed> $connectionConfig
     * @return array<string, mixed>
     */
    public function withoutSettings(array $connectionConfig): array
    {
        unset($connectionConfig[self::KEY]);

        return $connectionConfig;
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
        $names = [$this->alwaysPrimaryTable];

        foreach (is_array($configured) ? $configured : [] as $table) {
            if (is_string($table) && preg_match('/^\w+$/D', $table) === 1) {
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
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
