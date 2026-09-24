<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Statement;

/**
 * Takes out the parts of SQL text that cannot change what a statement does, so a keyword is found only where it counts.
 */
class SqlText
{
    /**
     * Backtick identifiers, string values and the three comment forms, in one pass so none hides inside another.
     */
    private const string LEXEME = '/`(?:[^`]|``)*`'
        . '|\'(?:[^\'\\\\]|\\\\.|\'\')*\''
        . '|"(?:[^"\\\\]|\\\\.|"")*"'
        . '|\/\*.*?(?:\*\/|$)'
        . '|(?:--(?=\s)|#)[^\n]*/s';

    /**
     * The statement with string values emptied and comments removed, except the executable comments the server runs.
     */
    public function executable(string $sql): string
    {
        $text = preg_replace_callback(self::LEXEME, fn (array $match): string => $this->replace($match), $sql);

        return rtrim(trim((string) $text), "; \t\n\r\0");
    }

    /**
     * The statement's first keyword in upper case, looking past any opening parentheses.
     */
    public function verb(string $executable): string
    {
        return preg_match('/^[\s(]*([A-Za-z]+)/', $executable, $match) === 1 ? strtoupper($match[1]) : '';
    }

    /**
     * Whether a second statement follows the first.
     */
    public function hasSeparator(string $executable): bool
    {
        return str_contains($executable, ';');
    }

    /**
     * @param string[] $match
     */
    private function replace(array $match): string
    {
        $lexeme = $match[0];

        if ($lexeme[0] === '`') {
            return $lexeme;
        }

        if ($lexeme[0] === '\'' || $lexeme[0] === '"') {
            return "''";
        }

        if (preg_match('/^\/\*M?!\d*(.*?)(?:\*\/)?$/s', $lexeme, $body) === 1) {
            return ' ' . $body[1] . ' ';
        }

        return ' ';
    }
}
