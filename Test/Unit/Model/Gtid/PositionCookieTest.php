<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model\Gtid;

use Kingletas\ReadSplit\Model\Gtid\GtidPosition;
use Kingletas\ReadSplit\Model\Gtid\PositionCookie;
use Kingletas\ReadSplit\Model\Gtid\PositionState;
use Kingletas\ReadSplit\Test\Support\SplitAdapterTestCase;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use RuntimeException;

/**
 * Built on the real encryptor with an invented store key, so a forgery is refused by the cipher itself.
 */
class PositionCookieTest extends SplitAdapterTestCase
{
    private const string NAME = 'kingletas_read_split';

    public function testNoCookieMeansNothingIsPending(): void
    {
        $this->assertSame(PositionState::None, $this->positionCookie()->read(10)->state());
    }

    public function testAPositionWrittenIsReadBackOnTheNextRequest(): void
    {
        $cookie = $this->positionCookie();
        $cookie->write('0-1-996,1-2-17', 10);
        $this->cookies->nextRequest();

        $pending = $cookie->read(10);

        $this->assertSame(PositionState::Pending, $pending->state());
        $this->assertSame('0-1-996,1-2-17', $pending->position());
    }

    public function testTheCookieIsHttpOnlySecureSameSiteLaxAndShortLived(): void
    {
        $this->positionCookie()->write('0-1-996', 10);

        $metadata = $this->cookies->set[self::NAME]['metadata'];

        $this->assertTrue($metadata['http_only']);
        $this->assertTrue($metadata['secure']);
        $this->assertSame('Lax', $metadata['samesite']);
        $this->assertSame(10, $metadata['duration']);
        $this->assertSame('/', $metadata['path']);
    }

    public function testTheValueDoesNotShowThePosition(): void
    {
        $this->positionCookie()->write('0-1-996', 10);

        $value = $this->cookies->set[self::NAME]['value'];

        $this->assertStringNotContainsString('996', $value);
        $this->assertMatchesRegularExpression('/^\d+:3:/', $value);
    }

    public function testAnInvalidPositionIsWrittenAsAHold(): void
    {
        $cookie = $this->positionCookie();
        $cookie->write('not a position', 10);
        $this->cookies->nextRequest();

        $this->assertSame(PositionState::Hold, $cookie->read(10)->state());
        $this->assertSame('', $cookie->read(10)->position());
    }

    public function testAPositionOlderThanItsLifetimeHasExpired(): void
    {
        $cookie = $this->positionCookie();
        $cookie->write('0-1-996', 10);
        $this->cookies->nextRequest();
        $this->clock->advance(11);

        $this->assertSame(PositionState::None, $cookie->read(10)->state());
    }

    public function testAPositionAtTheEndOfItsLifetimeStillCounts(): void
    {
        $cookie = $this->positionCookie();
        $cookie->write('0-1-996', 10);
        $this->cookies->nextRequest();
        $this->clock->advance(10);

        $this->assertSame(PositionState::Pending, $cookie->read(10)->state());
    }

    public function testAPositionFromTooFarInTheFutureIsRejected(): void
    {
        $cookie = $this->positionCookie();
        $this->clock->advance(60);
        $cookie->write('0-1-996', 10);
        $this->clock->advance(-60);
        $this->cookies->nextRequest();

        $this->assertSame(PositionState::Rejected, $cookie->read(10)->state());
    }

    public function testAValidCiphertextOfAMalformedPayloadIsRejected(): void
    {
        $this->cookies->incoming[self::NAME] = $this->encryptor($this->deploymentConfig())
            ->encrypt("rs1|1800000000|0-1-5'); DROP TABLE invented_table; --");

        $this->assertSame(PositionState::Rejected, $this->positionCookie()->read(10)->state());
    }

    public function testAValidCiphertextOfAnOutOfRangePositionIsRejected(): void
    {
        $this->cookies->incoming[self::NAME] = $this->encryptor($this->deploymentConfig())
            ->encrypt('rs1|1800000000|0-1-18446744073709551616');

        $this->assertSame(PositionState::Rejected, $this->positionCookie()->read(10)->state());
    }

    public function testATamperedCiphertextIsRejected(): void
    {
        $cookie = $this->positionCookie();
        $cookie->write('0-1-996', 10);
        $value = $this->cookies->set[self::NAME]['value'];
        $flipped = $value[10] === 'A' ? 'B' : 'A';
        $this->cookies->incoming[self::NAME] = substr_replace($value, $flipped, 10, 1);

        $this->assertSame(PositionState::Rejected, $cookie->read(10)->state());
    }

    public function testAnEncryptorThatThrowsCountsAsRejected(): void
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willThrowException(new RuntimeException('invented failure'));
        $this->cookies->incoming[self::NAME] = '1:3:' . str_repeat('QUJD', 20);

        $cookie = new PositionCookie(
            $this->cookies,
            $this->cookies,
            $this->createStub(CookieMetadataFactory::class),
            $encryptor,
            new GtidPosition(),
            $this->clock
        );

        $this->assertSame(PositionState::Rejected, $cookie->read(10)->state());
    }
}
