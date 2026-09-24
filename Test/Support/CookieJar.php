<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Support;

use Magento\Framework\Stdlib\Cookie\CookieMetadata;
use Magento\Framework\Stdlib\Cookie\CookieReaderInterface;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\Cookie\SensitiveCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;

/**
 * A browser's cookies for one visitor: what a response set arrives with the next request.
 */
class CookieJar implements CookieReaderInterface, CookieManagerInterface
{
    /**
     * @var array<string, string> what the current request arrived with
     */
    public array $incoming = [];

    /**
     * @var array<string, array{value: string, metadata: array<string, mixed>}> what the current response set
     */
    public array $set = [];

    public int $reads = 0;

    /**
     * The browser sends back everything the last response set, alongside what it already held.
     */
    public function nextRequest(): void
    {
        foreach ($this->set as $name => $cookie) {
            $this->incoming[$name] = $cookie['value'];
        }

        $this->set = [];
    }

    public function getCookie($name, $default = null)
    {
        ++$this->reads;

        return $this->incoming[$name] ?? $default;
    }

    public function setPublicCookie($name, $value, ?PublicCookieMetadata $metadata = null)
    {
        $this->set[(string) $name] = ['value' => (string) $value, 'metadata' => $metadata?->__toArray() ?? []];
    }

    public function setSensitiveCookie($name, $value, ?SensitiveCookieMetadata $metadata = null)
    {
        $this->set[(string) $name] = ['value' => (string) $value, 'metadata' => $metadata?->__toArray() ?? []];
    }

    public function deleteCookie($name, ?CookieMetadata $metadata = null)
    {
        unset($this->incoming[(string) $name], $this->set[(string) $name]);
    }
}
