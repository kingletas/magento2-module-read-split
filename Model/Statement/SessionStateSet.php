<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Statement;

/**
 * Decides whether a SET changes only the session state that can be replayed on the replica.
 */
class SessionStateSet
{
    private const array VARIABLES = ['sql_mode', 'time_zone'];

    private const array PREFIXES = ['character_set_', 'collation_'];

    /**
     * True when every assignment in the SET is on the allow-list and none reads a table, calls a function or reads
     * a server variable for its value.
     */
    public function isSessionState(string $executable): bool
    {
        $body = trim((string) preg_replace('/^\s*SET\b/i', '', $executable, 1));

        if ($body === '') {
            return false;
        }

        foreach ($this->assignments($body) as $assignment) {
            if (!$this->isAllowed(trim($assignment))) {
                return false;
            }
        }

        return true;
    }

    private function isAllowed(string $assignment): bool
    {
        if (preg_match('/\bSELECT\b/i', $assignment) === 1) {
            return false;
        }

        if (preg_match('/^NAMES\s/i', $assignment) === 1) {
            return true;
        }

        if (preg_match('/^@(?!@)[\w$.]+\s*:?=/', $assignment) === 1) {
            // A function or a server variable can answer differently on each server, and a lock is held on one.
            return !str_contains($assignment, '(') && !str_contains($assignment, '@@');
        }

        $variable = '/^(?:(?:SESSION|LOCAL)\s+|@@(?:SESSION\.|LOCAL\.)?)?`?([a-z_]+)`?\s*:?=/i';

        if (preg_match($variable, $assignment, $match) !== 1) {
            return false;
        }

        $name = strtolower($match[1]);

        if (in_array($name, self::VARIABLES, true)) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The comma-separated assignments, splitting only outside parentheses.
     *
     * @return string[]
     */
    private function assignments(string $body): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (str_split($body) as $character) {
            $depth += match ($character) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            if ($character === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $character;
        }

        $parts[] = $current;

        return $parts;
    }
}
