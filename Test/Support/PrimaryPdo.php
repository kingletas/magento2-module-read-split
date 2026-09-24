<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Support;

use PDO;
use PDOStatement;

/**
 * The primary, as the core adapter sees it: every statement it executes is recorded, and every read answers "primary".
 */
class PrimaryPdo extends PDO
{
    /**
     * @var string[]
     */
    public array $executed = [];

    public string $insertId = '41';

    public string $position = '0-1-100';

    public int $transactions = 0;

    /**
     * Set to make reading the position fail, as it does on a server with no GTID variables.
     */
    public bool $refusePosition = false;

    /**
     * A statement matching this pattern fails on the primary too.
     */
    public string $failOn = '';

    /**
     * No connection is opened; the adapter is handed this object as if it had just connected.
     */
    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new PrimaryStatement($this, $query);
    }

    public function record(string $sql): void
    {
        $this->executed[] = $sql;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->insertId;
    }

    public function beginTransaction(): bool
    {
        ++$this->transactions;
        $this->record('BEGIN');

        return true;
    }

    public function commit(): bool
    {
        $this->record('COMMIT');

        return true;
    }

    public function rollBack(): bool
    {
        $this->record('ROLLBACK');

        return true;
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function exec(string $statement): int|false
    {
        $this->record($statement);

        return 1;
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return "'" . addslashes($string) . "'";
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return true;
    }

    public function getAttribute(int $attribute): mixed
    {
        return null;
    }
}
