<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Gtid;

/**
 * The checked state of a visitor's cookie, and the GTID position when there is one.
 */
class PendingPosition
{
    public function __construct(
        private readonly PositionState $state,
        private readonly string $position = ''
    ) {
    }

    public function state(): PositionState
    {
        return $this->state;
    }

    /**
     * The validated position, empty unless the state is Pending.
     */
    public function position(): string
    {
        return $this->state === PositionState::Pending ? $this->position : '';
    }
}
