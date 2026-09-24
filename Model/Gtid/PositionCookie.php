<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Gtid;

use Kingletas\ReadSplit\Model\Clock;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\CookieReaderInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Throwable;

/**
 * Carries the primary's GTID position to the visitor's next request, encrypted with the store's key.
 */
class PositionCookie
{
    private const string VERSION = 'rs1';

    /**
     * The only shape the store's encryptor writes today: key version, the authenticated cipher, the ciphertext.
     */
    private const string CIPHERTEXT = '/^\d{1,3}:3:[A-Za-z0-9+\/]{40,4000}={0,2}$/D';

    /**
     * Seconds a position may appear to come from the future, for web servers whose clocks disagree slightly.
     */
    private const int CLOCK_SKEW = 5;

    public function __construct(
        private readonly CookieReaderInterface $cookieReader,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $metadataFactory,
        private readonly EncryptorInterface $encryptor,
        private readonly GtidPosition $gtidPosition,
        private readonly Clock $clock,
        private readonly string $cookieName = 'kingletas_read_split'
    ) {
    }

    public function read(int $lifetime): PendingPosition
    {
        $value = $this->cookieReader->getCookie($this->cookieName);

        if (!is_string($value) || $value === '') {
            return new PendingPosition(PositionState::None);
        }

        if (preg_match(self::CIPHERTEXT, $value) !== 1) {
            return new PendingPosition(PositionState::Rejected);
        }

        return $this->fromPayload($this->decrypt($value), $lifetime);
    }

    /**
     * Sets the cookie; a position that is not a valid GTID is written as a hold, keeping the visitor on the primary.
     */
    public function write(string $position, int $lifetime): void
    {
        $position = $this->gtidPosition->isValid($position) ? $position : '';
        $payload = implode('|', [self::VERSION, (string) $this->clock->now(), $position]);

        $metadata = $this->metadataFactory->createPublicCookieMetadata()
            ->setDuration($lifetime)
            ->setPath('/')
            ->setHttpOnly(true)
            ->setSecure(true)
            ->setSameSite('Lax');

        $this->cookieManager->setPublicCookie($this->cookieName, $this->encryptor->encrypt($payload), $metadata);
    }

    private function decrypt(string $value): string
    {
        try {
            return (string) $this->encryptor->decrypt($value);
        } catch (Throwable) {
            return '';
        }
    }

    private function fromPayload(string $payload, int $lifetime): PendingPosition
    {
        $pattern = '/^' . self::VERSION . '\|(\d{1,12})\|([0-9,\-]*)$/D';

        if (preg_match($pattern, $payload, $parts) !== 1) {
            return new PendingPosition(PositionState::Rejected);
        }

        $age = $this->clock->now() - (int) $parts[1];

        if ($age < -self::CLOCK_SKEW) {
            return new PendingPosition(PositionState::Rejected);
        }

        if ($age > $lifetime) {
            return new PendingPosition(PositionState::None);
        }

        if ($parts[2] === '') {
            return new PendingPosition(PositionState::Hold);
        }

        return $this->gtidPosition->isValid($parts[2])
            ? new PendingPosition(PositionState::Pending, $parts[2])
            : new PendingPosition(PositionState::Rejected);
    }
}
