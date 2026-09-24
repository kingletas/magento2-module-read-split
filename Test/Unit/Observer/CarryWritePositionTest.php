<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Observer;

use Kingletas\ReadSplit\Model\Adapter\ReadSplitMysql;
use Kingletas\ReadSplit\Model\Gtid\PositionState;
use Kingletas\ReadSplit\Model\Response\Cacheability;
use Kingletas\ReadSplit\Observer\CarryWritePosition;
use Kingletas\ReadSplit\Test\Support\SplitAdapterTestCase;
use Laminas\Http\Header\GenericHeader;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Psr\Log\LoggerInterface;
use RuntimeException;

class CarryWritePositionTest extends SplitAdapterTestCase
{
    private const string NAME = 'kingletas_read_split';

    public function testARequestThatWroteAndIsNotCacheableCarriesThePrimarysPosition(): void
    {
        $this->primary->position = '0-1-2048';
        $adapter = $this->adapter(['position_lifetime' => 45]);
        $adapter->insert('quote_id_mask', ['quote_id' => 3]);

        $this->observer($adapter)->execute($this->event('GET', 'no-store, no-cache, must-revalidate, max-age=0'));
        $this->assertSame(45, $this->cookies->set[self::NAME]['metadata']['duration']);
        $this->cookies->nextRequest();

        $pending = $this->positionCookie()->read(45);
        $this->assertSame(PositionState::Pending, $pending->state());
        $this->assertSame('0-1-2048', $pending->position());
    }

    public function testAPostThatWroteCarriesAPosition(): void
    {
        $adapter = $this->adapter();
        $adapter->insert('quote', ['entity_id' => 3]);

        $this->observer($adapter)->execute($this->event('POST', null));

        $this->assertArrayHasKey(self::NAME, $this->cookies->set);
    }

    public function testACacheableResponseNeverGetsTheCookieEvenWhenItsRequestWrote(): void
    {
        $adapter = $this->adapter();
        $adapter->insert('search_query', ['query_text' => 'invented words']);

        $this->observer($adapter)->execute($this->event('GET', 'public, max-age=86400, s-maxage=86400'));
        $this->observer($adapter)->execute($this->event('GET', null));

        $this->assertSame([], $this->cookies->set);
    }

    public function testARequestThatDidNotWriteCarriesNothing(): void
    {
        $adapter = $this->adapter();
        $this->answeredBy($adapter, 'SELECT * FROM catalog_product_entity');
        $adapter->query("SET time_zone = '+00:00'");

        $this->observer($adapter)->execute($this->event('POST', 'no-store'));

        $this->assertSame([], $this->cookies->set);
    }

    public function testAPrimaryWithNoBinaryLogPositionGivesAHold(): void
    {
        $this->primary->position = '';
        $adapter = $this->adapter();
        $adapter->insert('quote', ['entity_id' => 3]);

        $this->observer($adapter)->execute($this->event('POST', null));
        $this->cookies->nextRequest();

        $this->assertSame(PositionState::Hold, $this->positionCookie()->read(10)->state());
    }

    public function testAPrimaryThatCannotGiveAPositionGivesAHold(): void
    {
        $this->primary->refusePosition = true;
        $adapter = $this->adapter();
        $adapter->insert('quote', ['entity_id' => 3]);

        $this->observer($adapter)->execute($this->event('POST', null));
        $this->cookies->nextRequest();

        $this->assertSame(PositionState::Hold, $this->positionCookie()->read(10)->state());
    }

    public function testAStoreWhoseConnectionIsNotSplitGetsNothing(): void
    {
        $this->ledger->recordWrite();
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->createStub(Mysql::class));

        $this->observerWith($resource, $this->logger)->execute($this->event('POST', null));

        $this->assertSame([], $this->cookies->set);
    }

    public function testAFailureIsLoggedAndTheResponseStillGoesOut(): void
    {
        $this->ledger->recordWrite();
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willThrowException(new RuntimeException('invented failure'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $this->observerWith($resource, $logger)->execute($this->event('POST', null));

        $this->assertSame([], $this->cookies->set);
    }

    public function testAnEventWithoutAnHttpRequestAndResponseIsLeftAlone(): void
    {
        $adapter = $this->adapter();
        $adapter->insert('quote', ['entity_id' => 3]);

        $this->observer($adapter)->execute(new Observer(['event' => new Event(['request' => null])]));

        $this->assertSame([], $this->cookies->set);
    }

    private function observer(ReadSplitMysql $adapter): CarryWritePosition
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);

        return $this->observerWith($resource, $this->logger);
    }

    private function observerWith(ResourceConnection $resource, LoggerInterface $logger): CarryWritePosition
    {
        return new CarryWritePosition($this->ledger, new Cacheability(), $resource, $this->positionCookie(), $logger);
    }

    private function event(string $method, ?string $cacheControl): Observer
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('isGet')->willReturn($method === 'GET');
        $request->method('isHead')->willReturn(false);

        $response = $this->createStub(HttpResponse::class);
        $response->method('getHeader')->willReturn(
            $cacheControl === null ? false : new GenericHeader('Cache-Control', $cacheControl)
        );

        return new Observer(['event' => new Event(['request' => $request, 'response' => $response])]);
    }
}
