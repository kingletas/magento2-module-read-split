<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Support;

use Kingletas\ReadSplit\Model\Clock;

/**
 * A clock the test moves by hand.
 */
class MovableClock extends Clock
{
    public function __construct(
        public int $time = 1800000000
    ) {
    }

    public function now(): int
    {
        return $this->time;
    }

    public function advance(int $seconds): void
    {
        $this->time += $seconds;
    }
}
