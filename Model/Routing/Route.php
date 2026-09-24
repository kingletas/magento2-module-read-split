<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Routing;

/**
 * Where one statement runs.
 */
enum Route
{
    case Primary;

    case Replica;

    /** Session state: the primary runs it, and the replica gets it now if open and on opening otherwise. */
    case PrimaryAndReplica;
}
