<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Support;

use Kingletas\ReadSplit\Model\Adapter\ReadSplitMysql;
use Kingletas\ReadSplit\Model\Request\WriteLedger;
use Kingletas\ReadSplit\Model\Response\Cacheability;
use Kingletas\ReadSplit\Observer\CarryWritePosition;
use Laminas\Http\Header\GenericHeader;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\HTTP\PhpEnvironment\Request as PhpEnvironmentRequest;
use Magento\Framework\HTTP\PhpEnvironment\Response as PhpEnvironmentResponse;
use Magento\Framework\Webapi\Rest\Response as RestResponse;

/**
 * One visitor's requests in a row: each starts a fresh connection, as each PHP request does, and ends by sending its
 * response through the observer, while the cookies, the clock, the primary and the replica carry over.
 */
abstract class VisitorJourneyTestCase extends SplitAdapterTestCase
{
    /**
     * @var array<string, mixed>
     */
    protected array $readSplit = [];

    private ?ReadSplitMysql $current = null;

    private string $method = 'GET';

    protected function startRequest(string $method): ReadSplitMysql
    {
        $this->cookies->nextRequest();
        $this->ledger = new WriteLedger();
        $this->scope->storefrontRead = in_array($method, ['GET', 'HEAD'], true);
        $this->method = $method;
        $this->current = $this->adapter($this->readSplit);

        return $this->current;
    }

    /**
     * Sends the response with the given Cache-Control, which is when a request that wrote hands on its position.
     */
    protected function sendResponse(?string $cacheControl): void
    {
        $this->send($cacheControl, HttpRequest::class, HttpResponse::class);
    }

    /**
     * Sends a REST response: the webapi_rest area has its own request and response classes, and the same event.
     */
    protected function sendRestResponse(): void
    {
        $this->send(null, PhpEnvironmentRequest::class, RestResponse::class);
    }

    /**
     * @param class-string<PhpEnvironmentRequest> $requestClass
     * @param class-string<PhpEnvironmentResponse> $responseClass
     */
    private function send(?string $cacheControl, string $requestClass, string $responseClass): void
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->current);

        $request = $this->createStub($requestClass);
        $request->method('isGet')->willReturn($this->method === 'GET');
        $request->method('isHead')->willReturn($this->method === 'HEAD');
        $response = $this->createStub($responseClass);
        $response->method('getHeader')->willReturn(
            $cacheControl === null ? false : new GenericHeader('Cache-Control', $cacheControl)
        );

        (new CarryWritePosition($this->ledger, new Cacheability(), $resource, $this->positionCookie(), $this->logger))
            ->execute(new Observer(['event' => new Event(['request' => $request, 'response' => $response])]));
    }
}
