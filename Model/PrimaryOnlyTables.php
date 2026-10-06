<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model;

/**
 * The tables always read from the primary, named as the database knows them, prefix included.
 */
class PrimaryOnlyTables
{
    /**
     * @param string[] $names every table or name* pattern on the list
     * @param string[] $pinning those of them a read of which sends the rest of the request to the primary
     */
    public function __construct(
        private readonly array $names = [],
        private readonly array $pinning = []
    ) {
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        return $this->names;
    }

    /**
     * @return string[]
     */
    public function pinning(): array
    {
        return $this->pinning;
    }
}
