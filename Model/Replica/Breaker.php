<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Replica;

use Kingletas\ReadSplit\Model\Clock;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Takes the replica out of use on this node for thirty seconds, with marker files under var/, never the cache.
 */
class Breaker implements ResetAfterRequestInterface
{
    private const int WINDOW = 30;

    private bool $probing = false;

    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly File $file,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly string $markerName = 'kingletas_read_split'
    ) {
    }

    /**
     * True while the breaker is closed, and for the one request that claims the retry once it has been open 30 seconds.
     */
    public function allowsAttempt(): bool
    {
        $age = $this->age($this->marker('breaker'));

        if ($age === null) {
            return true;
        }

        if ($age < self::WINDOW || !$this->touch($this->marker('breaker'))) {
            return false;
        }

        $this->probing = true;

        return true;
    }

    /**
     * Whether this request is the one retrying an open breaker.
     */
    public function isProbing(): bool
    {
        return $this->probing;
    }

    /**
     * Opens the breaker, or keeps it open for another thirty seconds, with one warning only when it was closed.
     */
    public function trip(string $reason): void
    {
        $path = $this->marker('breaker');
        $wasOpen = $this->age($path) !== null;
        $this->probing = false;

        if ($this->touch($path) && !$wasOpen) {
            $this->logger->warning(
                'Read split: the replica is out of use on this node, and is retried every 30 seconds: ' . $reason . '.'
            );
        }
    }

    /**
     * Closes the breaker after a successful retry, with one notice.
     */
    public function recordSuccess(): void
    {
        if (!$this->probing) {
            return;
        }

        $this->probing = false;

        try {
            $this->file->deleteFile($this->marker('breaker'));
            $this->logger->notice('Read split: the replica answered again and is back in use on this node.');
        } catch (Throwable $e) {
            $this->logger->warning('Read split: the replica breaker could not be closed.', ['exception' => $e]);
        }
    }

    /**
     * True for the one request that should ask about replication, at most once every thirty seconds on this node.
     */
    public function claimHealthCheck(): bool
    {
        $path = $this->marker('checked');
        $age = $this->age($path);

        return ($age === null || $age >= self::WINDOW) && $this->touch($path);
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->probing = false;
    }

    private function marker(string $kind): string
    {
        return rtrim((string) $this->directoryList->getPath(DirectoryList::VAR_DIR), '/')
            . '/' . $this->markerName . '.' . $kind;
    }

    /**
     * Seconds since the marker was last touched, or null when there is none.
     */
    private function age(string $path): ?int
    {
        try {
            if (!$this->file->isExists($path)) {
                return null;
            }

            return $this->clock->now() - (int) ($this->file->stat($path)['mtime'] ?? 0);
        } catch (Throwable) {
            return null;
        }
    }

    private function touch(string $path): bool
    {
        try {
            return (bool) $this->file->touch($path, $this->clock->now());
        } catch (Throwable $e) {
            $this->logger->warning('Read split: the replica breaker could not write under var/.', ['exception' => $e]);

            return false;
        }
    }
}
