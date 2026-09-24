<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Gtid;

/**
 * What the visitor's read-after-write cookie said, once it was checked.
 */
enum PositionState
{
    /** No cookie, or an authentic one that has expired: nothing to wait for. */
    case None;

    /** An authentic cookie carrying the position the replica must reach first. */
    case Pending;

    /** An authentic cookie from a write whose position was unreadable, so the primary serves until it expires. */
    case Hold;

    /** A value that is not one this store wrote: forged, damaged or malformed. */
    case Rejected;
}
