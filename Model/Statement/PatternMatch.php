<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Statement;

/**
 * Asks whether a pattern is found in SQL text, and counts a match that could not finish as found.
 */
class PatternMatch
{
    /**
     * Only a clean "no" is a no: an error is no answer, and what it would have ruled out stays on the primary.
     */
    public function found(string $pattern, string $text): bool
    {
        return preg_match($pattern, $text) !== 0;
    }
}
