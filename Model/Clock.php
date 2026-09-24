<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model;

/**
 * The current time in seconds, as a service so a test can move it.
 */
class Clock
{
    public function now(): int
    {
        return time();
    }
}
