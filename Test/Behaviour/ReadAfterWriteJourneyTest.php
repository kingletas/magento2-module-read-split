<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Behaviour;

use Kingletas\ReadSplit\Test\Support\VisitorJourneyTestCase;

/**
 * His warning as a visitor meets it: add to cart writes, and the pages after it must show the cart, lagging or not.
 */
class ReadAfterWriteJourneyTest extends VisitorJourneyTestCase
{
    private const string PRIVATE = 'no-store, no-cache, must-revalidate, max-age=0';

    public function testTheCartIsReadFromThePrimaryUntilTheReplicaHasTheWrite(): void
    {
        $this->replica->caughtUp['replica'] = false;

        // Add to cart: a POST, so every statement is the primary's, and its response carries the position.
        $this->primary->position = '0-1-700';
        $addToCart = $this->startRequest('POST');
        $this->assertSame('primary', $this->answeredBy($addToCart, 'SELECT * FROM catalog_product_entity'));
        $addToCart->insert('quote', ['entity_id' => 3]);
        $this->sendResponse(null);

        // The section load that follows: the replica is behind, so the primary answers, and it writes quote_id_mask.
        $this->primary->position = '0-1-701';
        $sectionLoad = $this->startRequest('GET');
        $this->assertSame('primary', $this->answeredBy($sectionLoad, 'SELECT * FROM quote WHERE entity_id = 3'));
        $sectionLoad->insert('quote_id_mask', ['quote_id' => 3, 'masked_id' => 'invented-mask']);
        $this->sendResponse(self::PRIVATE);

        // The cart page: the replica has caught up with the section load's write, so it answers.
        $this->replica->caughtUp['replica'] = true;
        $cart = $this->startRequest('GET');
        $this->assertSame('replica', $this->answeredBy($cart, 'SELECT * FROM quote_id_mask WHERE quote_id = 3'));
        $this->sendResponse(self::PRIVATE);

        $checks = array_values(array_filter(
            $this->replica->statements,
            static fn (array $statement): bool => $statement['sql'] === 'MASTER_GTID_WAIT'
        ));
        $this->assertSame([['0-1-700'], ['0-1-701']], array_column($checks, 'bind'));
    }

    public function testOnceThePositionExpiresTheReplicaAnswersWithoutACheck(): void
    {
        $this->primary->position = '0-1-700';
        $addToCart = $this->startRequest('POST');
        $addToCart->insert('quote', ['entity_id' => 3]);
        $this->sendResponse(null);

        $this->clock->advance(11);
        $page = $this->startRequest('GET');

        $this->assertSame('replica', $this->answeredBy($page, 'SELECT * FROM quote WHERE entity_id = 3'));
        $this->assertSame(0, $this->replica->gtidChecks());
    }

    /**
     * A cacheable page that logs something keeps its own request on the primary after the write, and hands nothing on.
     */
    public function testACacheablePageThatWritesCarriesNothingToTheNextRequest(): void
    {
        $search = $this->startRequest('GET');
        $this->assertSame('replica', $this->answeredBy($search, 'SELECT * FROM catalogsearch_fulltext'));
        $search->insert('search_query', ['query_text' => 'invented words']);
        $this->assertSame('primary', $this->answeredBy($search, 'SELECT * FROM search_query'));
        $this->sendResponse('public, max-age=86400, s-maxage=86400');

        $this->assertSame([], $this->cookies->set);

        $next = $this->startRequest('GET');
        $this->assertSame('replica', $this->answeredBy($next, 'SELECT * FROM store'));
        $this->assertSame(0, $this->replica->gtidChecks());
    }

    public function testAPageThatOnlyReadsLeavesNoCookieBehind(): void
    {
        $page = $this->startRequest('GET');
        $this->answeredBy($page, 'SELECT * FROM store');
        $this->sendResponse(self::PRIVATE);

        $this->assertSame([], $this->cookies->set);
    }

    public function testAForgedCookieCostsOnlyTheRequestThatCarriedIt(): void
    {
        $this->cookies->set['kingletas_read_split'] = ['value' => '1:3:' . str_repeat('QUJD', 20), 'metadata' => []];
        $forged = $this->startRequest('GET');
        $this->assertSame('primary', $this->answeredBy($forged, 'SELECT * FROM store'));
        $this->sendResponse(self::PRIVATE);

        unset($this->cookies->incoming['kingletas_read_split']);
        $next = $this->startRequest('GET');

        $this->assertSame('replica', $this->answeredBy($next, 'SELECT * FROM store'));
    }
}
