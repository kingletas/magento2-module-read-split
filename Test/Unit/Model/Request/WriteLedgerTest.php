<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model\Request;

use Kingletas\ReadSplit\Model\Request\WriteLedger;
use PHPUnit\Framework\TestCase;

class WriteLedgerTest extends TestCase
{
    public function testAWriteIsRememberedUntilTheNextRequest(): void
    {
        $ledger = new WriteLedger();
        $this->assertFalse($ledger->hasWritten());

        $ledger->recordWrite();
        $this->assertTrue($ledger->hasWritten());

        $ledger->_resetState();
        $this->assertFalse($ledger->hasWritten());
    }
}
