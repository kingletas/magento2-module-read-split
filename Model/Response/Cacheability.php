<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Response;

use Magento\Framework\HTTP\PhpEnvironment\Request as HttpRequest;
use Magento\Framework\HTTP\PhpEnvironment\Response as HttpResponse;

/**
 * Decides whether a response could be stored by a full-page cache, and so must never gain a cookie.
 */
class Cacheability
{
    private const string PRIVATE_DIRECTIVES = '/\b(?:private|no-store|no-cache)\b/i';

    /**
     * Only a GET or HEAD can be cached, and only when Cache-Control allows it; a missing header counts as cacheable.
     */
    public function isCacheable(HttpRequest $request, HttpResponse $response): bool
    {
        if (!$request->isGet() && !$request->isHead()) {
            return false;
        }

        $header = $response->getHeader('Cache-Control');
        $value = $header === false ? '' : (string) $header->getFieldValue();

        return preg_match(self::PRIVATE_DIRECTIVES, $value) !== 1;
    }
}
