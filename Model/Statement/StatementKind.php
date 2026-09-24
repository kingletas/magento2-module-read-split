<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Statement;

/**
 * What a statement is, as far as where it may run is concerned.
 */
enum StatementKind
{
    /** A plain SELECT, which the replica may answer. */
    case Read;

    /** A SELECT or a metadata read that must run on the primary and pins the request there, but writes nothing. */
    case PinningRead;

    /** An allow-listed SET of session state, which runs on the primary and is replayed on the replica. */
    case SessionState;

    /** Anything else, which runs on the primary, pins the request and counts as a write. */
    case Write;
}
