<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model\Response;

use Kingletas\ReadSplit\Model\Response\Cacheability;
use Laminas\Http\Header\GenericHeader;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CacheabilityTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string|null, 2: bool}>
     */
    public static function responses(): array
    {
        return [
            'a public GET' => ['GET', 'public, max-age=86400, s-maxage=86400', true],
            'a public HEAD' => ['HEAD', 'public, max-age=86400', true],
            'a GET with no Cache-Control' => ['GET', null, true],
            'a private GET' => ['GET', 'private, max-age=0', false],
            'a no-store GET' => ['GET', 'no-store, no-cache, must-revalidate, max-age=0', false],
            'a no-cache GET' => ['GET', 'no-cache', false],
            'a POST, whatever its headers say' => ['POST', 'public, max-age=86400', false],
            'a PUT' => ['PUT', null, false],
        ];
    }

    #[DataProvider('responses')]
    public function testOnlyAGetOrHeadACacheMayStoreIsCacheable(string $method, ?string $header, bool $cacheable): void
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('isGet')->willReturn($method === 'GET');
        $request->method('isHead')->willReturn($method === 'HEAD');

        $response = $this->createStub(HttpResponse::class);
        $response->method('getHeader')->willReturn(
            $header === null ? false : new GenericHeader('Cache-Control', $header)
        );

        $this->assertSame($cacheable, (new Cacheability())->isCacheable($request, $response));
    }
}
