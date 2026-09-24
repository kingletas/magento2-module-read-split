<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Support;

use Magento\Framework\Filesystem\Driver\File;

/**
 * Magento's file driver, counting how often the breaker looks at its markers.
 */
class CountingFile extends File
{
    public int $looks = 0;

    public function isExists($path)
    {
        ++$this->looks;

        return parent::isExists($path);
    }

    public function stat($path)
    {
        ++$this->looks;

        return parent::stat($path);
    }
}
