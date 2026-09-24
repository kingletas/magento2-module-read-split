<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Request;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Remembers whether this request wrote anything through the split connection, so the response can carry a position.
 */
class WriteLedger implements ResetAfterRequestInterface
{
    private bool $written = false;

    public function recordWrite(): void
    {
        $this->written = true;
    }

    public function hasWritten(): bool
    {
        return $this->written;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->written = false;
    }
}
