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
use Kingletas\ReadSplit\Model\Replica\Breaker;
use Kingletas\ReadSplit\Model\Replica\ReplicationStatus;
use Kingletas\ReadSplit\Model\Request\WriteLedger;
use Kingletas\ReadSplit\Model\Routing\RouterFactory;
use Kingletas\ReadSplit\Model\SettingsReader;
use Kingletas\ReadSplit\Model\Statement\Classifier;
use Kingletas\ReadSplit\Model\Statement\SessionStateSet;
use Kingletas\ReadSplit\Model\Statement\SqlText;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\DB\Logger\Quiet;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\DB\SelectFactory;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Encryption\KeyValidator;
use Magento\Framework\Filesystem\Driver\File;
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

    /**
     * The file driver the breaker is given, left null for Magento's own.
     */
    protected ?File $fileDriver = null;

    /**
     * Magento's var/ directory for this test, where the breaker keeps its marker files.
     */
    protected string $varDir;

    /**
     * The system temp directory for this test, where the breaker falls back when var/ cannot be written.
     */
    protected string $tempDir;

    /**
     * The Magento installation's root, which with the replica host names the breaker's markers.
     */
    protected string $installRoot = '/invented/store';

    /**
     * @var string[]
     */
    private array $varDirs = [];

    protected function setUp(): void
    {
        $this->varDir = sys_get_temp_dir() . '/kingletas-read-split-test-' . bin2hex(random_bytes(6));
        mkdir($this->varDir);
        $this->varDirs[] = $this->varDir;
        $this->tempDir = $this->varDir . '-tmp';
        mkdir($this->tempDir);
        $this->varDirs[] = $this->tempDir;
        $this->scope = new FixedScope();
        $this->clock = new MovableClock();
        $this->cookies = new CookieJar();
        $this->replica = new ReplicaServer();
        $this->ledger = new WriteLedger();
        $this->primary = new PrimaryPdo();
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->classifier = new Classifier(new SqlText(), new SessionStateSet());
    }

    protected function tearDown(): void
    {
        foreach ($this->varDirs as $dir) {
            array_map('unlink', glob($dir . '/*') ?: []);

            if (is_dir($dir)) {
                rmdir($dir);
            }
        }

        $this->varDirs = [];
    }

    /**
     * The breaker a new request builds: its state is the marker files, so each request gets a fresh object.
     */
    protected function breaker(?File $file = null): Breaker
    {
        $directoryList = $this->createStub(DirectoryList::class);
        $directoryList->method('getPath')->willReturnCallback(
            fn (string $code): string => $code === DirectoryList::VAR_DIR ? $this->varDir : '/nowhere'
        );
        $directoryList->method('getRoot')->willReturn($this->installRoot);

        $breaker = new Breaker(
            $directoryList,
            $file ?? new File(),
            $this->clock,
            $this->logger,
            'kingletas_read_split',
            $this->tempDir
        );

        return $breaker->withReplicaHost('db-replica.example');
    }

    /**
     * Magento's var/ directory stops being writable, as on a store whose var/ belongs to another user.
     */
    protected function makeVarUnwritable(): void
    {
        array_map('unlink', glob($this->varDir . '/*') ?: []);
        rmdir($this->varDir);
    }

    /**
     * @return string[] the breaker's marker files in a directory
     */
    protected function markersIn(string $dir): array
    {
        return array_map('basename', glob($dir . '/kingletas_read_split*') ?: []);
    }

    /**
     * The default connection of a store whose env.php db/read_split block is the given one, with an invented replica
     * host unless it names another.
     *
     * @param array<string, mixed> $readSplit
     */
    protected function adapter(array $readSplit = [], string $tablePrefix = ''): ReadSplitMysql
    {
        return $this->adapterFor(
            $this->connectionConfig(),
            $this->deploymentConfig($tablePrefix, $this->readSplitBlock($readSplit))
        );
    }

    /**
     * The module's adapter built with the given connection config, as Magento's factory would hand it over.
     *
     * @param array<string, mixed> $config
     */
    protected function adapterFor(array $config, DeploymentConfig $deploymentConfig): ReadSplitMysql
    {
        $routerFactory = new RouterFactory(
            $this->classifier,
            $this->scope,
            $this->positionCookie($deploymentConfig),
            $this->ledger,
            $this->replica,
            $this->breaker($this->fileDriver),
            new ReplicationStatus()
        );

        $adapter = new ReadSplitMysql(
            new StringUtils(),
            new DateTime(),
            new Quiet(),
            new SelectFactory(new SelectRenderer([])),
            new SettingsReader($deploymentConfig),
            $routerFactory,
            $config,
            $this->createStub(SerializerInterface::class),
            $this->createStub(DtoFactoriesTable::class)
        );

        $this->injectPrimary($adapter);

        return $adapter;
    }

    /**
     * Hands the adapter the recording primary, as if it had just connected.
     */
    protected function injectPrimary(ReadSplitMysql $adapter): void
    {
        (new ReflectionProperty(Zend_Db_Adapter_Abstract::class, '_connection'))->setValue($adapter, $this->primary);
    }

    /**
     * The default connection as env.php holds it, which carries nothing of this module's.
     *
     * @return array<string, mixed>
     */
    protected function connectionConfig(): array
    {
        return [
            'host' => 'db-primary.example',
            'dbname' => 'invented_store',
            'username' => 'invented_user',
            'password' => 'invented-password',
        ];
    }

    /**
     * @param array<string, mixed> $readSplit
     * @return array<string, mixed>
     */
    protected function readSplitBlock(array $readSplit = []): array
    {
        return $readSplit + ['replica' => ['host' => 'db-replica.example']];
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

    /**
     * env.php as Magento reads it: the store key, the table prefix, the connections and the db/read_split block.
     *
     * @param array<string, mixed>|null $readSplit
     */
    protected function deploymentConfig(string $tablePrefix = '', ?array $readSplit = null): DeploymentConfig
    {
        $connection = $this->connectionConfig();
        $deploymentConfig = $this->createStub(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(
            static fn (string $path): mixed => match ($path) {
                'crypt/key' => self::STORE_KEY,
                'db/table_prefix' => $tablePrefix,
                'db/connection/default' => $connection,
                'db/read_split' => $readSplit,
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
        $this->positionCookie()->write($position, 30);
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
