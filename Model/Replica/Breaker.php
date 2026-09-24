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

    private const int QUIET = 3600;

    private bool $probing = false;

    private string $replicaHost = '';

    /**
     * @param string $fallbackDirectory where markers go when var/ cannot be written, empty for the system temp dir
     */
    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly File $file,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly string $markerName = 'kingletas_read_split',
        private readonly string $fallbackDirectory = ''
    ) {
    }

    /**
     * The same breaker for one replica host, whose markers no other host or installation on the node shares.
     */
    public function withReplicaHost(string $host): self
    {
        $breaker = clone $this;
        $breaker->replicaHost = $host;
        $breaker->probing = false;

        return $breaker;
    }

    /**
     * True while the breaker is closed, and for the one request that claims the retry once it has been open 30 seconds.
     */
    public function allowsAttempt(): bool
    {
        $age = $this->age('breaker');

        if ($age === null) {
            return true;
        }

        if ($age < self::WINDOW || $this->touch('breaker') === null) {
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
        $wasOpen = $this->age('breaker') !== null;
        $this->probing = false;
        $where = $this->touch('breaker');

        if ($where === null) {
            $this->logger->warning(
                'Read split: neither var/ nor the system temp directory can be written, so there is no breaker '
                . 'and every request tries the replica: ' . $reason . '.'
            );

            return;
        }

        if (!$wasOpen) {
            $kept = $where === 'var' ? '' : ' The breaker is kept in the system temp directory: var/ is unwritable.';
            $this->logger->warning(
                'Read split: the replica is out of use on this node, and is retried every 30 seconds: ' . $reason . '.'
                . $kept
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

        foreach ($this->paths('breaker') as $path) {
            try {
                if ($this->file->isExists($path)) {
                    $this->file->deleteFile($path);
                }
            } catch (Throwable $e) {
                $this->logger->warning('Read split: the replica breaker could not be closed.', ['exception' => $e]);

                return;
            }
        }

        $this->logger->notice('Read split: the replica answered again and is back in use on this node.');
    }

    /**
     * True for the one request that should ask about replication, at most once every thirty seconds on this node.
     */
    public function claimHealthCheck(): bool
    {
        $age = $this->age('checked');

        return ($age === null || $age >= self::WINDOW) && $this->touch('checked') !== null;
    }

    /**
     * Logs a warning about the settings at most once an hour on this node, or every time when no marker can be kept.
     */
    public function warnOccasionally(string $kind, string $message): void
    {
        $age = $this->age($kind);

        if ($age === null || $age >= self::QUIET) {
            $this->touch($kind);
            $this->logger->warning($message);
        }
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->probing = false;
    }

    /**
     * The marker in var/ first, then in the fallback directory.
     *
     * @return array<string, string>
     */
    private function paths(string $kind): array
    {
        $name = $this->markerName . '-' . substr(
            hash('sha256', $this->directoryList->getRoot() . "\0" . $this->replicaHost),
            0,
            16
        ) . '.' . $kind;
        $fallback = $this->fallbackDirectory !== '' ? $this->fallbackDirectory : sys_get_temp_dir();

        return [
            'var' => rtrim((string) $this->directoryList->getPath(DirectoryList::VAR_DIR), '/') . '/' . $name,
            'temp' => rtrim($fallback, '/') . '/' . $name,
        ];
    }

    /**
     * Seconds since the newest of the marker's copies was touched, or null when there is none.
     */
    private function age(string $kind): ?int
    {
        $ages = [];

        foreach ($this->paths($kind) as $path) {
            try {
                if ($this->file->isExists($path)) {
                    $ages[] = $this->clock->now() - (int) ($this->file->stat($path)['mtime'] ?? 0);
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $ages === [] ? null : min($ages);
    }

    /**
     * Touches the marker where it can be written, and says where: "var", "temp", or null when nowhere.
     */
    private function touch(string $kind): ?string
    {
        foreach ($this->paths($kind) as $where => $path) {
            try {
                if ($this->file->touch($path, $this->clock->now())) {
                    return $where;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}
