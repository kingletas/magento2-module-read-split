<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Statement;

/**
 * Sorts a statement into the kinds routing cares about, and calls anything it does not recognise a write.
 */
class Classifier
{
    /**
     * A SELECT that locks, reads a value only its own connection holds, or sets a variable, is not a plain read.
     */
    private const string NOT_PLAIN = '/\bFOR\s+UPDATE\b|\bFOR\s+SHARE\b|\bLOCK\s+IN\s+SHARE\s+MODE\b'
        . '|\bINTO\b|:=|@@|\bSQL_CALC_FOUND_ROWS\b|\bPREVIOUS\s+VALUE\s+FOR\b'
        . '|\b(?:GET_LOCK|RELEASE_LOCK|RELEASE_ALL_LOCKS|IS_FREE_LOCK|IS_USED_LOCK|LAST_INSERT_ID|FOUND_ROWS'
        . '|ROW_COUNT|LASTVAL|MASTER_GTID_WAIT|MASTER_POS_WAIT|SLEEP|BENCHMARK'
        . '|CONNECTION_ID|CURRENT_USER|SESSION_USER|SYSTEM_USER|USER|DATABASE|SCHEMA)`?\s*\(|\bCURRENT_USER\b/i';

    /**
     * A SELECT that moves a sequence on writes to it.
     */
    private const string SEQUENCE_WRITE = '/\b(?:NEXTVAL|SETVAL)`?\s*\(|\bNEXT\s+VALUE\s+FOR\b/i';

    private const array METADATA_READS = ['SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'];

    public function __construct(
        private readonly SqlText $sqlText,
        private readonly SessionStateSet $sessionStateSet
    ) {
    }

    public function classify(string $sql): Classification
    {
        $text = $this->sqlText->executable($sql);

        return new Classification($this->kindOf($text), $text);
    }

    private function kindOf(string $text): StatementKind
    {
        // A comment still open after the comments were taken out is one nested where the lexer could not follow.
        if ($this->sqlText->hasSeparator($text) || str_contains($text, '/*') || str_contains($text, '*/')) {
            return StatementKind::Write;
        }

        $verb = $this->sqlText->verb($text);

        if ($verb === 'SELECT') {
            return $this->selectKind($text);
        }

        if ($verb === 'SET') {
            return $this->sessionStateSet->isSessionState($text) ? StatementKind::SessionState : StatementKind::Write;
        }

        return in_array($verb, self::METADATA_READS, true) ? StatementKind::PrimaryRead : StatementKind::Write;
    }

    private function selectKind(string $text): StatementKind
    {
        $match = new PatternMatch();

        if ($match->found(self::SEQUENCE_WRITE, $text)) {
            return StatementKind::Write;
        }

        return $match->found(self::NOT_PLAIN, $text) ? StatementKind::PinningRead : StatementKind::Read;
    }
}
