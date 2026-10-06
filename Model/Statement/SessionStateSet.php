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
     * A number, a string (emptied by now), NULL, TRUE or FALSE.
     */
    private const string LITERAL = "''|[-+]?\\d+(?:\\.\\d+)?|NULL|TRUE|FALSE";

    /**
     * A value bound when the statement runs, by position or by name.
     */
    private const string BOUND = '[?]|[:]\w+';

    /**
     * A user variable by its name, which a server variable's second @ is not.
     */
    private const string USER_VARIABLE = '@(?!@)[\w$.]+';

    /**
     * The whole of an assignment that gives a user variable a literal, a bound value or another user variable.
     */
    private const string PLAIN_VALUE = '/^' . self::USER_VARIABLE . '\s*:?=\s*(?:' . self::LITERAL . '|' . self::BOUND
        . '|' . self::USER_VARIABLE . ')\s*$/i';

    /**
     * True when every assignment in the SET is on the allow-list, and a user variable is given a plain value.
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

        if (preg_match('/^' . self::USER_VARIABLE . '\s*:?=/', $assignment) === 1) {
            // Anything computed can answer differently on each server, and a lock taken here is held on one.
            return preg_match(self::PLAIN_VALUE, $assignment) === 1;
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
