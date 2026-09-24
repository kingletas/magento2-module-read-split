<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Support;

use Kingletas\ReadSplit\Model\Request\RequestScopeInterface;

/**
 * The request being served, set by the test: its area and method reduced to the one answer routing asks for.
 */
class FixedScope implements RequestScopeInterface
{
    public bool $commandLine = false;

    public ?bool $storefrontRead = true;

    public int $asked = 0;

    public function isCommandLine(): bool
    {
        return $this->commandLine;
    }

    public function isStorefrontRead(): ?bool
    {
        ++$this->asked;

        return $this->storefrontRead;
    }
}
