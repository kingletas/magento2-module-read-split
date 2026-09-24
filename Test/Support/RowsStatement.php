<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Support;

use Zend_Db_Statement_Interface;

/**
 * A result that answers with fixed rows, which is all a replica double needs to hand back.
 */
class RowsStatement implements Zend_Db_Statement_Interface
{
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(
        private readonly array $rows
    ) {
    }

    public function bindColumn($column, &$param, $type = null)
    {
        return true;
    }

    public function bindParam($parameter, &$variable, $type = null, $length = null, $options = null)
    {
        return true;
    }

    public function bindValue($parameter, $value, $type = null)
    {
        return true;
    }

    public function closeCursor()
    {
        return true;
    }

    public function columnCount()
    {
        return count($this->rows[0] ?? []);
    }

    public function errorCode()
    {
        return '00000';
    }

    public function errorInfo()
    {
        return [];
    }

    public function execute(array $params = [])
    {
        return true;
    }

    public function fetch($style = null, $cursor = null, $offset = null)
    {
        return $this->rows[0] ?? false;
    }

    public function fetchAll($style = null, $col = null)
    {
        return $this->rows;
    }

    public function fetchColumn($col = 0)
    {
        return array_values($this->rows[0] ?? [])[$col] ?? false;
    }

    public function fetchObject($class = 'stdClass', array $config = [])
    {
        return false;
    }

    public function getAttribute($key)
    {
        return null;
    }

    public function nextRowset()
    {
        return false;
    }

    public function rowCount()
    {
        return count($this->rows);
    }

    public function setAttribute($key, $val)
    {
        return true;
    }

    public function setFetchMode($mode)
    {
        return true;
    }
}
