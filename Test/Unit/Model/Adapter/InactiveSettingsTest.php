<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model\Adapter;

use Kingletas\ReadSplit\Test\Support\SplitAdapterTestCase;

/**
 * An adapter whose settings are not active behaves as the core adapter: it never touches the replica or its breaker.
 */
class InactiveSettingsTest extends SplitAdapterTestCase
{
    public function testInactiveSettingsSendEveryReadToThePrimaryAndNeverOpenTheReplica(): void
    {
        $indexer = ['host' => 'db-indexer.example'] + $this->connectionConfig();
        $adapter = $this->adapterFor($indexer, $this->deploymentConfig('', $this->readSplitBlock()));
        $this->injectPrimary($adapter);

        $this->assertFalse($adapter->readSplitSettings()->isActive());
        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM store'));
        $this->assertSame(0, $this->replica->connects);
        $this->assertSame([], $this->markersIn($this->varDir), 'No breaker is keyed on an empty host');
        $this->assertSame([], $this->markersIn($this->tempDir));
    }

    /**
     * A block naming another connection would split reads and hand no position on, so nothing is split at all.
     */
    public function testABlockNamingAnotherConnectionSplitsNothingAndRecordsNoWrite(): void
    {
        $adapter = $this->adapter(['connection' => 'checkout']);

        $this->assertFalse($adapter->readSplitSettings()->isActive());
        $this->assertSame('primary', $this->answeredBy($adapter, 'SELECT * FROM store'));
        $adapter->insert('invented_table', ['id' => 1]);
        $this->assertSame(0, $this->replica->connects);
        $this->assertFalse($this->ledger->hasWritten());
    }

    public function testActiveSettingsStillSendPlainReadsToTheReplica(): void
    {
        $adapter = $this->adapter();

        $this->assertTrue($adapter->readSplitSettings()->isActive());
        $this->assertSame('replica', $this->answeredBy($adapter, 'SELECT * FROM store'));
    }

    public function testInactiveSettingsRecordNoWriteAndPinNothing(): void
    {
        $adapter = $this->adapterFor(
            ['host' => 'db-indexer.example'] + $this->connectionConfig(),
            $this->deploymentConfig('', $this->readSplitBlock())
        );
        $this->injectPrimary($adapter);

        $adapter->insert('invented_table', ['id' => 1]);

        $this->assertFalse($this->ledger->hasWritten(), 'Only a split connection hands on a position');
    }
}
