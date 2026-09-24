<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Gtid;

/**
 * Checks a value against MariaDB's GTID position format: one domain-server-sequence triple per replication domain.
 */
class GtidPosition
{
    private const int MAX_DOMAINS = 64;

    private const string UNSIGNED_32 = '4294967295';

    private const string UNSIGNED_64 = '18446744073709551615';

    public function isValid(string $position): bool
    {
        if ($position === '' || strlen($position) > self::MAX_DOMAINS * 44) {
            return false;
        }

        $triples = explode(',', $position);

        if (count($triples) > self::MAX_DOMAINS) {
            return false;
        }

        foreach ($triples as $triple) {
            if (!$this->isTriple($triple)) {
                return false;
            }
        }

        return true;
    }

    private function isTriple(string $triple): bool
    {
        if (preg_match('/^(\d{1,10})-(\d{1,10})-(\d{1,20})$/D', $triple, $parts) !== 1) {
            return false;
        }

        return $this->fits($parts[1], self::UNSIGNED_32)
            && $this->fits($parts[2], self::UNSIGNED_32)
            && $this->fits($parts[3], self::UNSIGNED_64);
    }

    /**
     * Compares two unsigned decimal strings without converting them, since a 64-bit sequence overflows PHP's integer.
     */
    private function fits(string $number, string $limit): bool
    {
        $number = ltrim($number, '0') ?: '0';

        return strlen($number) < strlen($limit) || (strlen($number) === strlen($limit) && strcmp($number, $limit) <= 0);
    }
}
