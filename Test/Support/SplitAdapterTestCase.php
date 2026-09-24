<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Support;

use Kingletas\ReadSplit\Model\Adapter\ReadSplitMysql;
use Kingletas\ReadSplit\Model\Gtid\GtidPosition;
use Kingletas\ReadSplit\Model\Gtid\PositionCookie;
use Kingletas\ReadSplit\Model\Request\WriteLedger;
use Kingletas\ReadSplit\Model\Routing\RouterFactory;
use Kingletas\ReadSplit\Model\SettingsReader;
use Kingletas\ReadSplit\Model\Statement\Classifier;
use Kingletas\ReadSplit\Model\Statement\SessionStateSet;
use Kingletas\ReadSplit\Model\Statement\SqlText;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\DB\Logger\Quiet;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\DB\SelectFactory;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Encryption\KeyValidator;
use Magento\Framework\Math\Random;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Setup\Declaration\Schema\Dto\Factories\Table as DtoFactoriesTable;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\DateTime;
use Magento\Framework\Stdlib\StringUtils;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Zend_Db_Adapter_Abstract;

/**
 * A split connection wired as di.xml wires it, with the primary, the replica host, the visitor's cookies, the
 * request and the clock replaced by doubles the test controls.
 */
abstract class SplitAdapterTestCase extends TestCase
{
    /**
     * An invented store key, the length Magento's encryptor expects.
     */
    protected const string STORE_KEY = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    protected FixedScope $scope;

    protected MovableClock $clock;

    protected CookieJar $cookies;

    protected ReplicaServer $replica;

    protected WriteLedger $ledger;

    protected PrimaryPdo $primary;

    protected LoggerInterface $logger;

    protected Classifier $classifier;

    protected function setUp(): void
    {
        $this->scope = new FixedScope();
        $this->clock = new MovableClock();
        $this->cookies = new CookieJar();
        $this->replica = new ReplicaServer();
        $this->ledger = new WriteLedger();
        $this->primary = new PrimaryPdo();
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->classifier = new Classifier(new SqlText(), new SessionStateSet());
    }

    /**
     * A connection whose env.php block is the given one, with an invented replica host unless it names another.
     *
     * @param array<string, mixed> $readSplit
     */
    protected function adapter(array $readSplit = [], string $tablePrefix = ''): ReadSplitMysql
    {
        $deploymentConfig = $this->deploymentConfig($tablePrefix);
        $routerFactory = new RouterFactory(
            $this->classifier,
            $this->scope,
            $this->positionCookie($deploymentConfig),
            $this->ledger,
            $this->replica,
            $this->logger
        );

        $adapter = new ReadSplitMysql(
            new StringUtils(),
            new DateTime(),
            new Quiet(),
            new SelectFactory(new SelectRenderer([])),
            new SettingsReader($deploymentConfig),
            $routerFactory,
            $this->connectionConfig($readSplit),
            $this->createStub(SerializerInterface::class),
            $this->createStub(DtoFactoriesTable::class)
        );

        (new ReflectionProperty(Zend_Db_Adapter_Abstract::class, '_connection'))->setValue($adapter, $this->primary);

        return $adapter;
    }

    /**
     * @param array<string, mixed> $readSplit
     * @return array<string, mixed>
     */
    protected function connectionConfig(array $readSplit = []): array
    {
        return [
            'host' => 'db-primary.example',
            'dbname' => 'invented_store',
            'username' => 'invented_user',
            'password' => 'invented-password',
            'read_split' => $readSplit + ['replica' => ['host' => 'db-replica.example']],
        ];
    }

    protected function positionCookie(?DeploymentConfig $deploymentConfig = null): PositionCookie
    {
        $metadataFactory = $this->createStub(CookieMetadataFactory::class);
        $metadataFactory->method('createPublicCookieMetadata')
            ->willReturnCallback(static fn (): PublicCookieMetadata => new PublicCookieMetadata());

        return new PositionCookie(
            $this->cookies,
            $this->cookies,
            $metadataFactory,
            $this->encryptor($deploymentConfig ?? $this->deploymentConfig()),
            new GtidPosition(),
            $this->clock
        );
    }

    protected function encryptor(DeploymentConfig $deploymentConfig): Encryptor
    {
        return new Encryptor(new Random(), $deploymentConfig, new KeyValidator());
    }

    protected function deploymentConfig(string $tablePrefix = ''): DeploymentConfig
    {
        $deploymentConfig = $this->createStub(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(
            static fn (string $path): ?string => match ($path) {
                'crypt/key' => self::STORE_KEY,
                'db/table_prefix' => $tablePrefix,
                default => null,
            }
        );

        return $deploymentConfig;
    }

    /**
     * The visitor arrives carrying the position an earlier response of this store set, issued the given seconds ago.
     */
    protected function arriveWithPosition(string $position, int $secondsAgo = 1): void
    {
        $this->clock->advance(-$secondsAgo);
        $this->positionCookie()->write($position, 10);
        $this->clock->advance($secondsAgo);
        $this->cookies->nextRequest();
    }

    /**
     * Which server answered: "primary", or the name of the replica backend.
     */
    protected function answeredBy(ReadSplitMysql $adapter, string $sql): string
    {
        return (string) $adapter->fetchOne($sql);
    }
}
