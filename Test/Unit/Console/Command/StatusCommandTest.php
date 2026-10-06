<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Console\Command;

use Kingletas\ReadSplit\Console\Command\StatusCommand;
use Kingletas\ReadSplit\Model\SettingsReader;
use Kingletas\ReadSplit\Test\Support\SplitAdapterTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The deploy check: it says what the store resolved, and fails when a block is configured and doing nothing.
 */
class StatusCommandTest extends SplitAdapterTestCase
{
    public function testAnActiveSplitPrintsTheReplicaItResolvedAndPasses(): void
    {
        $replica = ['host' => 'db-replica.example:3307', 'password' => 'invented-reader-secret'];
        $tester = $this->runCommand(['replica' => $replica]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('active', $display);
        $this->assertStringContainsString('db-replica.example', $display);
        $this->assertStringContainsString('3307', $display);
        $this->assertStringContainsString('invented_store', $display);
        $this->assertStringContainsString('closed', $display);
        $this->assertStringContainsString(
            'Settings: max_lag 30 s, position lifetime 60 s, pooled no, read timeout 5 s, connect timeout 2 s',
            $display
        );
        $this->assertStringNotContainsString('invented-reader-secret', $display);
        $this->assertStringNotContainsString('invented-password', $display);
    }

    public function testAnOpenBreakerIsShownWithTheTimeLeftAndWhereItIsKept(): void
    {
        $this->breaker()->withReplicaHost('db-replica.example:3306')->trip('invented reason');
        $this->clock->advance(10);

        $display = $this->runCommand(['replica' => ['host' => 'db-replica.example']])->getDisplay();

        $this->assertStringContainsString('open', $display);
        $this->assertStringContainsString('20', $display);
        $this->assertStringContainsString('var/', $display);
    }

    /**
     * Run by a deploy user who cannot write var/, it once printed "closed" while the web server's breaker was open.
     */
    public function testWhenVarIsNotTheCallersTheBreakerIsSaidToBeUnknownFromHere(): void
    {
        $this->makeVarUnwritable();

        $tester = $this->runCommand(['replica' => ['host' => 'db-replica.example']]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('active', $tester->getDisplay());
        $this->assertStringContainsString('Breaker: unknown from here', $tester->getDisplay());
        $this->assertStringNotContainsString('closed', $tester->getDisplay());
    }

    public function testAConfiguredBlockThatIsNotInUseFailsWithTheReason(): void
    {
        $tester = $this->runCommand(['replica' => ['username' => 'invented_reader']]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('not in use', $tester->getDisplay());
        $this->assertStringContainsString('replica host', $tester->getDisplay());
    }

    public function testABlockNamingAnotherConnectionFailsAndSaysOnlyTheDefaultCanBeSplit(): void
    {
        $tester = $this->runCommand(['connection' => 'checkout', 'replica' => ['host' => 'db-replica.example']]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('not in use', $tester->getDisplay());
        $this->assertStringContainsString('only the default connection can be split', $tester->getDisplay());
    }

    public function testAStoreWithoutTheBlockPasses(): void
    {
        $tester = $this->runCommand(null);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('not configured', $tester->getDisplay());
    }

    /**
     * The kill switch is how an incident is handled, and a deploy that fixes the incident must still pass.
     */
    public function testTheKillSwitchPassesAndSaysSo(): void
    {
        $tester = $this->runCommand(['enabled' => false, 'replica' => ['host' => 'db-replica.example']]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('switched off', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed>|null $block
     */
    private function runCommand(?array $block): CommandTester
    {
        $command = new StatusCommand(
            new SettingsReader($this->deploymentConfig('', $block)),
            $this->breaker(),
            'kingletas:read-split:status'
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }
}
