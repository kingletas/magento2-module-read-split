<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Replica;

use Magento\Framework\Filesystem\Driver\File;
use Throwable;

/**
 * Finds, under a directory every account on the host can write, the one directory only this user can.
 */
class OwnDirectory
{
    public function __construct(
        private readonly File $file
    ) {
    }

    /**
     * This user's directory under the base, made first when asked, or empty when it is a link, somebody else's,
     * open to others, or when PHP has no posix extension to say whose it is.
     */
    public function under(string $base, string $name, bool $make): string
    {
        if (!function_exists('posix_geteuid')) {
            return '';
        }

        $user = posix_geteuid();
        $directory = rtrim($base, '/') . '/' . $name . '-' . $user;

        try {
            if ($make && is_dir($base) && !is_link($directory) && !is_dir($directory)) {
                $this->file->createDirectory($directory, 0700);
            }

            return $this->isThisUsersAlone($directory, $user) ? $directory : '';
        } catch (Throwable) {
            return '';
        }
    }

    private function isThisUsersAlone(string $directory, int $user): bool
    {
        return !is_link($directory)
            && is_dir($directory)
            && fileowner($directory) === $user
            && (fileperms($directory) & 0077) === 0;
    }
}
