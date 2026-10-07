<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Request;

use Magento\Framework\App\Area;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;

/**
 * Answers from the application's area and the request's method: storefront pages and GraphQL, by GET or HEAD only.
 */
class RequestScope implements RequestScopeInterface
{
    private const array AREAS = [Area::AREA_FRONTEND, Area::AREA_GRAPHQL];

    private const array METHODS = ['GET', 'HEAD'];

    private readonly string $sapi;

    public function __construct(
        private readonly State $state,
        private readonly HttpRequest $request,
        ?string $sapi = null
    ) {
        // Read here and not as the argument's default: setup:di:compile runs on the command line and copies every
        // default into the store's generated metadata, so a default of PHP_SAPI would say "cli" to every request.
        $this->sapi = $sapi ?? PHP_SAPI;
    }

    public function isCommandLine(): bool
    {
        return $this->sapi === 'cli';
    }

    public function isStorefrontRead(): ?bool
    {
        try {
            $area = $this->state->getAreaCode();
        } catch (LocalizedException) {
            return null;
        }

        if ($this->state->isAreaCodeEmulated()) {
            return null;
        }

        return in_array($area, self::AREAS, true)
            && in_array(strtoupper((string) $this->request->getMethod()), self::METHODS, true);
    }
}
