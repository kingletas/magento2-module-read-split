<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Support;

use Kingletas\ReadSplit\Model\Statement\Classification;
use Kingletas\ReadSplit\Model\Statement\Classifier;
use Kingletas\ReadSplit\Model\Statement\SessionStateSet;
use Kingletas\ReadSplit\Model\Statement\SqlText;

/**
 * The real classifier, counting how many statements it was asked about.
 */
class CountingClassifier extends Classifier
{
    public int $classified = 0;

    public function __construct()
    {
        parent::__construct(new SqlText(), new SessionStateSet());
    }

    public function classify(string $sql): Classification
    {
        ++$this->classified;

        return parent::classify($sql);
    }
}
