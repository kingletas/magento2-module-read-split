<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Support;

use PDO;
use PDOException;
use PDOStatement;

/**
 * One statement on the primary, recorded when it runs.
 */
class PrimaryStatement extends PDOStatement
{
    private bool $isPosition;

    public function __construct(
        private readonly PrimaryPdo $primary,
        private readonly string $sql
    ) {
        $this->isPosition = str_contains($sql, '@@gtid_binlog_pos');
    }

    public function execute(?array $params = null): bool
    {
        if ($this->primary->failOn !== '' && preg_match($this->primary->failOn, $this->sql) === 1) {
            throw new PDOException('The invented primary refused a statement.');
        }

        if ($this->isPosition && $this->primary->refusePosition) {
            throw new PDOException('Unknown system variable, from the invented primary.');
        }

        $this->primary->record($this->sql);

        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [$this->row()];
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        return $mode === PDO::FETCH_NUM ? array_values($this->row()) : $this->row();
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return array_values($this->row())[$column] ?? false;
    }

    #[\ReturnTypeWillChange]
    public function setFetchMode(int $mode, mixed ...$args): bool
    {
        return true;
    }

    public function closeCursor(): bool
    {
        return true;
    }

    public function rowCount(): int
    {
        return 1;
    }

    public function columnCount(): int
    {
        return 1;
    }

    public function bindParam(
        int|string $param,
        mixed &$var,
        int $type = PDO::PARAM_STR,
        int $maxLength = 0,
        mixed $driverOptions = null
    ): bool {
        return true;
    }

    public function bindValue(int|string $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    private function row(): array
    {
        return $this->isPosition ? ['position' => $this->primary->position] : ['source' => 'primary'];
    }
}
