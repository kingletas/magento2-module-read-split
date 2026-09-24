<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model;

use Kingletas\ReadSplit\Model\Adapter\ReadSplitMysql;
use Kingletas\ReadSplit\Model\Replica\Breaker;
use Magento\Framework\DB\Adapter\Pdo\MysqlFactory;
use Magento\Framework\Model\ResourceModel\Type\Db\Pdo\Mysql as CoreConnectionType;

/**
 * Builds a connection with this module's adapter only when its env.php config names a replica and the switch is on.
 */
class ConnectionType extends CoreConnectionType
{
    private readonly bool $split;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        array $config,
        MysqlFactory $mysqlFactory,
        SettingsReader $settingsReader,
        Breaker $breaker
    ) {
        $settings = $settingsReader->read($config);
        $this->split = $settings->isActive();

        if ($settings->state() === SettingsState::Refused) {
            $breaker->warnOccasionally(
                'inactive',
                'Read split: db/read_split is configured but not in use, so every read goes to the primary: '
                . $settings->reason() . '.'
            );
        }

        parent::__construct($config, $mysqlFactory);
    }

    /**
     * @inheritDoc
     */
    protected function getDbConnectionClassName()
    {
        return $this->split ? ReadSplitMysql::class : parent::getDbConnectionClassName();
    }
}
