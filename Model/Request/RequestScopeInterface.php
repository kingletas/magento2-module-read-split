<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Request;

/**
 * Whether the request being served may read from the replica at all.
 */
interface RequestScopeInterface
{
    /**
     * True for the command line, where nothing is routed and nothing is classified.
     */
    public function isCommandLine(): bool;

    /**
     * True for a storefront GET or HEAD, false for anything else, and null while the area is not yet known.
     */
    public function isStorefrontRead(): ?bool;
}
