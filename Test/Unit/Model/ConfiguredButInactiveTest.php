<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model;

use Kingletas\ReadSplit\Model\ConnectionType;
use Kingletas\ReadSplit\Model\SettingsReader;
use Kingletas\ReadSplit\Test\Support\SplitAdapterTestCase;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Adapter\Pdo\MysqlFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;

/**
 * A block that is configured and refused is said once an hour per node, loudly, and never once per request.
 */
class ConfiguredButInactiveTest extends SplitAdapterTestCase
{
    public function testARefusedBlockWarnsOnceAcrossRequestsWithTheReason(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('warning')->with($this->stringContains('replica host'));

        for ($request = 0; $request < 3; ++$request) {
            $this->connectionType(['replica' => ['username' => 'invented_reader']]);
            $this->clock->advance(60);
        }
    }

    public function testTheWarningComesBackAfterAnHour(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->exactly(2))->method('warning');

        $this->connectionType(['replica' => []]);
        $this->clock->advance(3600);
        $this->connectionType(['replica' => []]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>|null, 1: array<string, mixed>}>
     */
    public static function silentStates(): array
    {
        $block = ['replica' => ['host' => 'db-replica.example']];

        return [
            'not configured' => [null, []],
            'switched off' => [['enabled' => false] + $block, []],
            'another connection' => [$block, ['host' => 'db-indexer.example']],
            'active' => [$block, []],
        ];
    }

    /**
     * @param array<string, mixed>|null $block
     * @param array<string, mixed> $connection
     */
    #[DataProvider('silentStates')]
    public function testNothingIsSaidWhenTheBlockIsAbsentOffForAnotherConnectionOrActive(
        ?array $block,
        array $connection
    ): void {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->never())->method('warning');

        $this->connectionType($block, $connection);
    }

    /**
     * @param array<string, mixed>|null $block
     * @param array<string, mixed> $connection
     */
    private function connectionType(?array $block, array $connection = []): void
    {
        $factory = $this->createStub(MysqlFactory::class);
        $factory->method('create')->willReturn($this->createStub(Mysql::class));

        new ConnectionType(
            $connection + $this->connectionConfig(),
            $factory,
            new SettingsReader($this->deploymentConfig('', $block)),
            $this->breaker()
        );
    }
}
