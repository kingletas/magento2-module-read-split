<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model;

use Kingletas\ReadSplit\Test\Support\SplitAdapterTestCase;
use ErrorException;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Logger\Quiet;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\DB\SelectFactory;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Setup\Declaration\Schema\Dto\Factories\Table as DtoFactoriesTable;
use Magento\Framework\Stdlib\DateTime;
use Magento\Framework\Stdlib\StringUtils;
use ReflectionMethod;

/**
 * Setup builds its adapter with `new Mysql(...)` from the raw connection config, past every preference, so that
 * config must never carry this module's settings.
 */
class SetupConnectionTest extends SplitAdapterTestCase
{
    public function testTheDefaultConnectionSetupReadsCarriesNoReadSplitKey(): void
    {
        $default = $this->deploymentConfig('', $this->readSplitBlock())->get('db/connection/default');

        $this->assertIsArray($default);
        $this->assertArrayNotHasKey('read_split', $default);
    }

    public function testACoreAdapterBuiltAsSetupBuildsItMakesItsDsnCleanly(): void
    {
        $default = $this->deploymentConfig('', $this->readSplitBlock())->get('db/connection/default');

        $this->assertIsString($this->dsnOf($default));
    }

    /**
     * The failure the store proof found, kept so the reason for the move stays visible.
     */
    public function testTheOldPlaceInsideTheConnectionBrokeTheDsn(): void
    {
        $nested = $this->connectionConfig() + ['read_split' => $this->readSplitBlock()];

        set_error_handler(
            static fn (int $level, string $message): bool => throw new ErrorException($message, 0, $level)
        );

        try {
            $this->dsnOf($nested);
            $this->fail('An array in the connection config should break the DSN');
        } catch (ErrorException $e) {
            $this->assertStringContainsString('Array to string conversion', $e->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function dsnOf(array $config): string
    {
        $adapter = new Mysql(
            new StringUtils(),
            new DateTime(),
            new Quiet(),
            new SelectFactory(new SelectRenderer([])),
            $config,
            $this->createStub(SerializerInterface::class),
            $this->createStub(DtoFactoriesTable::class)
        );

        return (string) (new ReflectionMethod($adapter, '_dsn'))->invoke($adapter);
    }
}
