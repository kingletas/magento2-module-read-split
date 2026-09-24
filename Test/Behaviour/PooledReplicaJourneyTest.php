<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Behaviour;

use Kingletas\ReadSplit\Test\Support\ReplicaServer;
use Kingletas\ReadSplit\Test\Support\VisitorJourneyTestCase;

/**
 * A replica host that is a pooling endpoint, answering each statement from whichever of two backends is next.
 */
class PooledReplicaJourneyTest extends VisitorJourneyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->replica = new ReplicaServer(['backend-a', 'backend-b']);
        $this->replica->caughtUp = ['backend-a' => true, 'backend-b' => false];
    }

    /**
     * Why the setting exists: without it, one backend answers the check and the other serves the read.
     */
    public function testUnpooledAgainstTwoBackendsTheCheckAndTheReadLandApart(): void
    {
        $this->writeInOneRequest();

        $page = $this->startRequest('GET');
        $answer = $this->answeredBy($page, 'SELECT * FROM quote WHERE entity_id = 3');

        $check = $this->replica->statements[0];

        $this->assertSame(['MASTER_GTID_WAIT', 'backend-a'], [$check['sql'], $check['backend']]);
        $this->assertSame('backend-b', $answer, 'The backend that is behind served the read');
    }

    public function testPooledKeepsTheVisitorOnThePrimaryWithoutAskingEitherBackend(): void
    {
        $this->readSplit = ['pooled' => true];
        $this->writeInOneRequest();

        $page = $this->startRequest('GET');
        $answers = [
            $this->answeredBy($page, 'SELECT * FROM quote WHERE entity_id = 3'),
            $this->answeredBy($page, 'SELECT * FROM quote_item WHERE quote_id = 3'),
        ];

        $this->assertSame(['primary', 'primary'], $answers);
        $this->assertSame([], $this->replica->statements);
    }

    public function testPooledReleasesTheVisitorToThePoolWhenThePositionExpires(): void
    {
        $this->readSplit = ['pooled' => true];
        $this->writeInOneRequest();
        $this->clock->advance(11);

        $page = $this->startRequest('GET');
        $answers = [
            $this->answeredBy($page, 'SELECT * FROM quote WHERE entity_id = 3'),
            $this->answeredBy($page, 'SELECT * FROM quote_item WHERE quote_id = 3'),
        ];

        $this->assertSame(['backend-a', 'backend-b'], $answers);
        $this->assertSame(0, $this->replica->gtidChecks());
    }

    public function testPooledServesAVisitorWhoNeverWroteFromThePool(): void
    {
        $this->readSplit = ['pooled' => true];

        $page = $this->startRequest('GET');

        $this->assertSame('backend-a', $this->answeredBy($page, 'SELECT * FROM store'));
        $this->assertSame(0, $this->replica->gtidChecks());
    }

    private function writeInOneRequest(): void
    {
        $this->primary->position = '0-1-700';
        $addToCart = $this->startRequest('POST');
        $addToCart->insert('quote', ['entity_id' => 3]);
        $this->sendResponse(null);
    }
}
