<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Adapter;

use Kingletas\ReadSplit\Model\Routing\Route;
use Kingletas\ReadSplit\Model\Routing\Router;
use Kingletas\ReadSplit\Model\Routing\RouterFactory;
use Kingletas\ReadSplit\Model\Settings;
use Kingletas\ReadSplit\Model\SettingsReader;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\LoggerInterface;
use Magento\Framework\DB\SelectFactory;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Setup\Declaration\Schema\Dto\Factories\Table as DtoFactoriesTable;
use Magento\Framework\Stdlib\DateTime;
use Magento\Framework\Stdlib\StringUtils;
use Throwable;

/**
 * Magento's MySQL adapter, which stays the primary and runs as the core one does, beside a replica for plain reads.
 */
class ReadSplitMysql extends Mysql
{
    private readonly Router $router;

    private bool $connecting = false;

    private int $dispatchDepth = 0;

    /**
     * The collaborators this class adds come before `$config`, since Magento builds adapters by argument name.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(
        StringUtils $string,
        DateTime $dateTime,
        LoggerInterface $logger,
        SelectFactory $selectFactory,
        SettingsReader $settingsReader,
        RouterFactory $routerFactory,
        array $config = [],
        ?SerializerInterface $serializer = null,
        ?DtoFactoriesTable $dtoFactoriesTable = null
    ) {
        $settings = $settingsReader->read($config);

        parent::__construct(
            $string,
            $dateTime,
            $logger,
            $selectFactory,
            $settingsReader->withoutSettings($config),
            $serializer,
            $dtoFactoriesTable
        );

        $this->router = $routerFactory->create($settings, $logger, $selectFactory);
    }

    public function readSplitSettings(): Settings
    {
        return $this->router->settings();
    }

    /**
     * The primary's GTID position, or empty when it cannot say, which the cookie turns into a hold on the primary.
     */
    public function primaryPosition(): string
    {
        try {
            $statement = $this->onPrimary('SELECT @@gtid_binlog_pos', []);
            $value = $statement ? $statement->fetchColumn() : '';
        } catch (Throwable) {
            return '';
        }

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @inheritDoc
     */
    public function beginTransaction()
    {
        $this->router->pin();

        return parent::beginTransaction();
    }

    /**
     * A statement prepared outside the query path runs on the primary, so a write prepared here still pins the request.
     *
     * @inheritDoc
     */
    public function prepare($sql)
    {
        if ($this->dispatchDepth === 0 && !$this->connecting) {
            $this->router->notePrimaryStatement((string) $sql);
        }

        return parent::prepare($sql);
    }

    /**
     * @inheritDoc
     */
    public function exec($sql)
    {
        $this->router->notePrimaryStatement((string) $sql);

        return parent::exec($sql);
    }

    /**
     * @inheritDoc
     */
    public function closeConnection()
    {
        if (isset($this->router)) {
            $this->router->closeReplica();
        }

        parent::closeConnection();
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->router->reset();

        parent::_resetState();
    }

    /**
     * Connection setup runs on the primary as the core adapter runs it, outside routing; the replica runs its own.
     *
     * @inheritDoc
     */
    protected function _connect()
    {
        $wasConnecting = $this->connecting;
        $this->connecting = true;

        try {
            parent::_connect();
        } finally {
            $this->connecting = $wasConnecting;
        }
    }

    /**
     * @inheritDoc
     */
    protected function _query($sql, $bind = [])
    {
        if ($this->connecting || $this->_queryHook) {
            return $this->onPrimary($sql, $bind);
        }

        $sql = (string) $sql;
        $route = $this->router->route($sql, (int) $this->getTransactionLevel());

        if ($route === Route::Replica) {
            $answer = $this->router->askReplica($sql, $bind);

            if ($answer !== null) {
                return $answer;
            }
        }

        $result = $this->onPrimary($sql, $bind);

        if ($route === Route::Replica) {
            $this->router->primaryAnsweredWhatTheReplicaFailed();
        }

        if ($route === Route::PrimaryAndReplica) {
            $this->router->rememberSessionState($sql, $bind);
        }

        return $result;
    }

    private function onPrimary(mixed $sql, mixed $bind): mixed
    {
        ++$this->dispatchDepth;

        try {
            return parent::_query($sql, $bind);
        } finally {
            --$this->dispatchDepth;
        }
    }
}
