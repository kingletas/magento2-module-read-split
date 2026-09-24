<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model\Request;

use Kingletas\ReadSplit\Model\Request\RequestScope;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RequestScopeTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function requests(): array
    {
        return [
            'a storefront GET' => ['frontend', 'GET', true],
            'a storefront HEAD' => ['frontend', 'HEAD', true],
            'a GraphQL GET' => ['graphql', 'GET', true],
            'a lower-case method' => ['frontend', 'get', true],
            'a storefront POST' => ['frontend', 'POST', false],
            'a GraphQL POST' => ['graphql', 'POST', false],
            'a REST GET' => ['webapi_rest', 'GET', false],
            'a SOAP GET' => ['webapi_soap', 'GET', false],
            'an admin GET' => ['adminhtml', 'GET', false],
            'cron' => ['crontab', 'GET', false],
        ];
    }

    #[DataProvider('requests')]
    public function testOnlyAStorefrontOrGraphQlGetOrHeadCounts(string $area, string $method, bool $read): void
    {
        $this->assertSame($read, $this->scope($area, $method)->isStorefrontRead());
    }

    public function testBeforeTheAreaIsSetTheAnswerIsNotYetKnown(): void
    {
        $state = $this->createStub(State::class);
        $state->method('getAreaCode')->willThrowException(new LocalizedException(new Phrase('Area code is not set')));

        $this->assertNull((new RequestScope($state, $this->createStub(HttpRequest::class)))->isStorefrontRead());
    }

    /**
     * An admin request rendering an email as the storefront would otherwise look like a storefront read.
     */
    public function testAnEmulatedAreaIsNotTrusted(): void
    {
        $state = $this->createStub(State::class);
        $state->method('getAreaCode')->willReturn('frontend');
        $state->method('isAreaCodeEmulated')->willReturn(true);
        $request = $this->createStub(HttpRequest::class);
        $request->method('getMethod')->willReturn('GET');

        $this->assertNull((new RequestScope($state, $request))->isStorefrontRead());
    }

    public function testTheCommandLineIsKnownFromTheServerApi(): void
    {
        $state = $this->createStub(State::class);
        $request = $this->createStub(HttpRequest::class);

        $this->assertTrue((new RequestScope($state, $request, 'cli'))->isCommandLine());
        $this->assertFalse((new RequestScope($state, $request, 'fpm-fcgi'))->isCommandLine());
    }

    private function scope(string $area, string $method): RequestScope
    {
        $state = $this->createStub(State::class);
        $state->method('getAreaCode')->willReturn($area);
        $state->method('isAreaCodeEmulated')->willReturn(false);
        $request = $this->createStub(HttpRequest::class);
        $request->method('getMethod')->willReturn($method);

        return new RequestScope($state, $request);
    }
}
