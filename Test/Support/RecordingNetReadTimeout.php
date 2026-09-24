<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Support;

use Kingletas\ReadSplit\Model\Replica\NetReadTimeout;

/**
 * The read timeout switch, recording each change instead of making it.
 */
class RecordingNetReadTimeout extends NetReadTimeout
{
    /**
     * @var string[]
     */
    public array $changes = [];

    public function apply(int $seconds): string|false
    {
        $this->changes[] = 'apply ' . $seconds;

        return '86400';
    }

    public function restore(string|false $previous): void
    {
        $this->changes[] = 'restore ' . $previous;
    }
}
