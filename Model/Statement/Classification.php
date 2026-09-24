<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Statement;

/**
 * One statement's kind, and its text with string values and comments taken out.
 */
class Classification
{
    public function __construct(
        private readonly StatementKind $kind,
        private readonly string $text
    ) {
    }

    public function kind(): StatementKind
    {
        return $this->kind;
    }

    /**
     * Whether the statement names a table the pattern matches, outside any string value or comment.
     */
    public function mentions(string $tablePattern): bool
    {
        return $tablePattern !== '' && preg_match($tablePattern, $this->text) === 1;
    }
}
