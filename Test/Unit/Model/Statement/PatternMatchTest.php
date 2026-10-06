<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model\Statement;

use Kingletas\ReadSplit\Model\Statement\PatternMatch;
use PHPUnit\Framework\TestCase;

/**
 * The one question the classifier asks of SQL text, and what it answers when the question cannot be finished.
 */
class PatternMatchTest extends TestCase
{
    public function testAPatternIsFoundOrNot(): void
    {
        $match = new PatternMatch();

        $this->assertTrue($match->found('/\bFOR\s+UPDATE\b/i', 'SELECT * FROM store for update'));
        $this->assertFalse($match->found('/\bFOR\s+UPDATE\b/i', 'SELECT * FROM store'));
    }

    /**
     * A match that runs out of its limit answers neither yes nor no, and read as "no" it would let a statement
     * nobody could judge go to the replica.
     */
    public function testAMatchThatCouldNotFinishCountsAsFound(): void
    {
        $limits = [ini_get('pcre.backtrack_limit'), ini_get('pcre.jit')];
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');

        try {
            // A pattern nothing has compiled yet: one compiled earlier keeps the limits it was compiled under.
            $pattern = '/\bFOR\s+UPDATE\b|\bINTO\b|:=|\binvented' . random_int(1, PHP_INT_MAX) . '\s*\(/i';
            $found = (new PatternMatch())->found($pattern, 'SELECT * FROM catalog_product_entity WHERE id = 5');
            $error = preg_last_error();
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limits[0]);
            ini_set('pcre.jit', (string) $limits[1]);
        }

        $this->assertNotSame(PREG_NO_ERROR, $error, 'The match was meant to run out of its limit');
        $this->assertTrue($found);
    }
}
