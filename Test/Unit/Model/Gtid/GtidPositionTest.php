<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model\Gtid;

use Kingletas\ReadSplit\Model\Gtid\GtidPosition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GtidPositionTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function valid(): array
    {
        return [
            'one domain' => ['0-1-996'],
            'two domains' => ['0-1-996,1-2-17'],
            'the largest ids and sequence' => ['4294967295-4294967295-18446744073709551615'],
            'leading zeros within range' => ['0-0001-00042'],
        ];
    }

    #[DataProvider('valid')]
    public function testAMariaDbPositionIsAccepted(string $position): void
    {
        $this->assertTrue((new GtidPosition())->isValid($position));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformed(): array
    {
        return [
            'empty' => [''],
            'two parts' => ['0-1'],
            'four parts' => ['0-1-2-3'],
            'letters' => ['0-1-abc'],
            'a negative sequence' => ['0--1-5'],
            'a space' => ['0-1-5, 1-2-3'],
            'a trailing comma' => ['0-1-5,'],
            'a trailing newline' => ["0-1-5\n"],
            'SQL after a position' => ["0-1-5', 0); DROP TABLE invented_table; --"],
            'a MySQL GTID set' => ['3E11FA47-71CA-11E1-9E33-C80AA9429562:1-5'],
            'a domain id past 32 bits' => ['4294967296-1-5'],
            'a server id past 32 bits' => ['0-4294967296-5'],
            'a sequence past 64 bits' => ['0-1-18446744073709551616'],
            'more domains than a server has' => [implode(',', array_fill(0, 65, '0-1-5'))],
        ];
    }

    #[DataProvider('malformed')]
    public function testAnythingElseIsRefused(string $position): void
    {
        $this->assertFalse((new GtidPosition())->isValid($position));
    }
}
