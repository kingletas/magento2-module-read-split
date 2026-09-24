<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model;

/**
 * What env.php's db/read_split block means for one connection.
 */
enum SettingsState
{
    /** No block: the store runs Magento's own adapter, as intended. */
    case NotConfigured;

    /** The kill switch is off, deliberately. */
    case SwitchedOff;

    /** The block splits a different connection. */
    case OtherConnection;

    /** The block is there but cannot be used, and the reason says why; the store is doing nothing it was asked to. */
    case Refused;

    case Active;
}
