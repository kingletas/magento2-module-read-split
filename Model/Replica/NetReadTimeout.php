<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Replica;

/**
 * Sets mysqlnd's read timeout, which each connection takes when it is made and keeps for its life.
 */
class NetReadTimeout
{
    private const string SETTING = 'mysqlnd.net_read_timeout';

    /**
     * @return string|false the value it replaced, for restore()
     */
    public function apply(int $seconds): string|false
    {
        $previous = ini_get(self::SETTING);
        ini_set(self::SETTING, (string) $seconds);

        return $previous;
    }

    public function restore(string|false $previous): void
    {
        if ($previous !== false) {
            ini_set(self::SETTING, $previous);
        }
    }
}
