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
    /**
     * Seconds the breaker stays open before one retry, and the least between two questions about replication.
     */
    public const int WINDOW = 30;

    private const int QUIET = 3600;

    private bool $probing = false;

    private string $replicaHost = '';

    /**
     * This user's own directory under the fallback once it has been checked, so a request checks it once.
     */
    private string $ownFallbackDirectory = '';

    /**
     * @param string $fallbackDirectory under which markers go when var/ cannot be written, empty for the temp dir
     */
    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly File $file,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly OwnDirectory $ownDirectory,
        private readonly string $markerName = 'kingletas_read_split',
        private readonly string $fallbackDirectory = ''
    ) {
    }

    /**
     * The same breaker for one replica host, whose markers nothing else shares; one for no host never lets it be tried.
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
        if ($this->replicaHost === '') {
            return false;
        }

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
     * Seconds until the next retry and where the marker is kept, or null while the breaker is closed.
     *
     * @return array{remaining: int, keptIn: string}|null
     */
    public function openState(): ?array
    {
        $ages = $this->replicaHost === '' ? [] : $this->ages('breaker');

        if ($ages === []) {
            return null;
        }

        $age = min($ages);

        return ['remaining' => max(0, self::WINDOW - $age), 'keptIn' => (string) array_search($age, $ages, true)];
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
        if ($this->replicaHost === '') {
            return;
        }

        $wasOpen = $this->age('breaker') !== null;
        $this->probing = false;
        $where = $this->touch('breaker');

        if ($where === null) {
            $this->logger->warning(
                'Read split: neither var/ nor a directory of this user\'s own under the system temp directory can be '
                . 'written, so there is no breaker and every request tries the replica: ' . $reason . '.'
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
                $this->logger->warning(
                    'Read split: the replica answered again, but the marker ' . $path . ' could not be removed, so '
                    . 'the breaker stays open and one request retries every 30 seconds. Make its directory writable '
                    . 'again, or remove that file.',
                    ['exception' => $e]
                );

                return;
            }
        }

        $this->logger->notice('Read split: the replica answered again and is back in use on this node.');
    }

    /**
     * True for the request that should ask about replication: one every thirty seconds on this node, or every
     * request when no marker can be kept.
     */
    public function claimHealthCheck(): bool
    {
        if ($this->replicaHost === '') {
            return false;
        }

        $age = $this->age('checked');

        if ($age !== null && $age < self::WINDOW) {
            return false;
        }

        // With nowhere to record the claim, every request asks: a replica nobody asks could be stopped for good.
        $this->touch('checked');

        return true;
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
     * The marker in var/ first, then in this user's own directory under the fallback, when there is one to trust.
     *
     * @return array<string, string>
     */
    private function paths(string $kind, bool $toWrite = false): array
    {
        $name = $this->markerName . '-' . substr(
            hash('sha256', $this->directoryList->getRoot() . "\0" . $this->replicaHost),
            0,
            16
        ) . '.' . $kind;
        $paths = ['var' => rtrim((string) $this->directoryList->getPath(DirectoryList::VAR_DIR), '/') . '/' . $name];
        $own = $this->ownFallbackDirectory($toWrite);

        if ($own !== '') {
            $paths['temp'] = $own . '/' . $name;
        }

        return $paths;
    }

    /**
     * The directory under the fallback that only this user can write, made when a marker is about to be written:
     * any account on the host can write the system temp directory, so a marker is believed only where no other
     * account could have put it.
     */
    private function ownFallbackDirectory(bool $make): string
    {
        if ($this->ownFallbackDirectory === '') {
            $base = $this->fallbackDirectory !== '' ? $this->fallbackDirectory : sys_get_temp_dir();
            $this->ownFallbackDirectory = $this->ownDirectory->under($base, $this->markerName, $make);
        }

        return $this->ownFallbackDirectory;
    }

    /**
     * Seconds since the newest of the marker's copies was touched, or null when there is none.
     */
    private function age(string $kind): ?int
    {
        $ages = $this->ages($kind);

        return $ages === [] ? null : min($ages);
    }

    /**
     * Each existing copy's age, by where it is kept: "var" or "temp".
     *
     * @return array<string, int>
     */
    private function ages(string $kind): array
    {
        $ages = [];

        foreach ($this->paths($kind) as $where => $path) {
            try {
                if ($this->file->isExists($path)) {
                    $ages[$where] = $this->clock->now() - (int) ($this->file->stat($path)['mtime'] ?? 0);
                }
            } catch (Throwable) {
                continue;
            }
        }

        // A marker dated in the future would hold its place for as long as it says, so it counts as no marker.
        return array_filter($ages, static fn (int $age): bool => $age >= 0);
    }

    /**
     * Touches the marker in var/, or in the fallback once var/ has refused it, and says where: "var", "temp" or null.
     */
    private function touch(string $kind): ?string
    {
        if ($this->touched($this->paths($kind)['var'])) {
            return 'var';
        }

        $fallback = $this->paths($kind, true)['temp'] ?? '';

        return $fallback !== '' && $this->touched($fallback) ? 'temp' : null;
    }

    private function touched(string $path): bool
    {
        try {
            return (bool) $this->file->touch($path, $this->clock->now());
        } catch (Throwable) {
            return false;
        }
    }
}
