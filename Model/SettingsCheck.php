<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model;

/**
 * Finds what in the `db/read_split` block is not what its key takes, so a mistyped setting is refused by name
 * and never read as a default nobody chose.
 */
class SettingsCheck
{
    private const array KEYS = [
        'enabled',
        'replica',
        'pooled',
        'connection',
        'primary_only_tables',
        'position_lifetime',
        'connect_timeout',
        'read_timeout',
        'max_lag',
    ];

    private const array REPLICA_KEYS = ['host', 'dbname', 'username', 'password'];

    private const array NUMBERS = ['position_lifetime', 'connect_timeout', 'read_timeout', 'max_lag'];

    /**
     * The first mistake in the block, as a sentence naming its key, or null when there is none.
     *
     * @param array<mixed> $block
     */
    public function mistake(array $block): ?string
    {
        foreach (array_keys($block) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                return 'db/read_split has a key this version does not know: ' . $this->shown($key);
            }
        }

        return $this->flagMistake($block)
            ?? $this->numberMistake($block)
            ?? $this->replicaMistake($block['replica'] ?? [])
            ?? $this->tablesMistake($block['primary_only_tables'] ?? []);
    }

    /**
     * True or false as env.php may write it, or null for anything that is neither.
     */
    public function flag(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_string($value)) {
            return ['1' => true, 'true' => true, '0' => false, 'false' => false][strtolower((string) $value)] ?? null;
        }

        return null;
    }

    /**
     * A whole number of seconds as env.php may write it, or null when the value is not one.
     */
    public function number(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    /**
     * @param array<mixed> $block
     */
    private function flagMistake(array $block): ?string
    {
        foreach (['enabled', 'pooled'] as $key) {
            if (array_key_exists($key, $block) && $this->flag($block[$key]) === null) {
                return $key . ' takes true or false, and got ' . $this->shown($block[$key]);
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $block
     */
    private function numberMistake(array $block): ?string
    {
        foreach (self::NUMBERS as $key) {
            if (array_key_exists($key, $block) && $this->number($block[$key]) === null) {
                return $key . ' takes a whole number of seconds, and got ' . $this->shown($block[$key]);
            }
        }

        return null;
    }

    private function replicaMistake(mixed $replica): ?string
    {
        if (!is_array($replica)) {
            return 'replica takes a list with the replica\'s host, and got ' . $this->shown($replica);
        }

        foreach ($replica as $key => $value) {
            if (!in_array($key, self::REPLICA_KEYS, true)) {
                return 'replica has a key this version does not know: ' . $this->shown($key)
                    . '. A port goes in the host, as host:port';
            }

            if (!is_string($value)) {
                return 'replica.' . $key . ' takes text, and got ' . $this->shown($value);
            }
        }

        return null;
    }

    private function tablesMistake(mixed $tables): ?string
    {
        if (!is_array($tables)) {
            return 'primary_only_tables takes a list of table names, and got ' . $this->shown($tables);
        }

        foreach ($tables as $table) {
            if (!is_string($table) || preg_match('/^\w+\*?$/D', $table) !== 1) {
                return 'primary_only_tables takes table names, each a name or a name ending in *, and got '
                    . $this->shown($table);
            }
        }

        return null;
    }

    /**
     * A value as a refusal shows it, cut short. A replica's password that is text is no mistake, so never shown.
     */
    private function shown(mixed $value): string
    {
        if (is_string($value)) {
            return '"' . substr($value, 0, 40) . '"';
        }

        return is_scalar($value) ? var_export($value, true) : 'something that is neither text nor a number';
    }
}
