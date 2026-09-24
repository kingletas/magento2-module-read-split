<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model;

/**
 * Which server and database a connection config points at, whatever else Magento has added to it.
 */
class ConnectionIdentity
{
    private const string DEFAULT_PORT = '3306';

    /**
     * Host in lower case, port (the default one when none is written, "socket" for a socket path), and database.
     *
     * @param array<string, mixed> $config
     * @return array{0: string, 1: string, 2: string}
     */
    public function of(array $config): array
    {
        $host = is_string($config['host'] ?? null) ? trim($config['host']) : '';
        $port = is_scalar($config['port'] ?? null) ? trim((string) $config['port']) : '';
        $dbname = is_string($config['dbname'] ?? null) ? trim($config['dbname']) : '';

        if (str_starts_with($host, '/')) {
            return [$host, 'socket', $dbname];
        }

        if (str_contains($host, ':')) {
            [$host, $port] = explode(':', $host, 2);
        }

        return [strtolower($host), $port === '' ? self::DEFAULT_PORT : $port, $dbname];
    }

    /**
     * @param array<string, mixed> $first
     * @param array<string, mixed> $second
     */
    public function same(array $first, array $second): bool
    {
        $identity = $this->of($first);

        return $identity[0] !== '' && $identity === $this->of($second);
    }
}
