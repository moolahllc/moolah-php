<?php

declare(strict_types=1);

namespace Moolah\Poynt\Resource;

use Moolah\Poynt\Http\HttpCore;
use Moolah\Poynt\Model\AchStatus;

final class Business
{
    /** @internal */
    public function __construct(private readonly HttpCore $core)
    {
    }

    /** Whether the business accepts ACH. GET /api/business/ach-status. */
    public function achStatus(?float $timeout = null, ?int $maxRetries = null): AchStatus
    {
        return AchStatus::fromArray(Support::obj($this->core->request('GET', '/api/business/ach-status', timeout: $timeout, maxRetries: $maxRetries)));
    }
}
