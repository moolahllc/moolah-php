<?php

declare(strict_types=1);

namespace Moolah\Poynt\Resource;

use Moolah\Poynt\Http\HttpCore;
use Moolah\Poynt\Model\Device;

final class Devices
{
    /** @internal */
    public function __construct(private readonly HttpCore $core)
    {
    }

    /**
     * Terminals registered to the business. GET /api/devices.
     * @return list<Device>
     */
    public function list(?float $timeout = null, ?int $maxRetries = null): array
    {
        $rows = $this->core->request('GET', '/api/devices', timeout: $timeout, maxRetries: $maxRetries);
        if (!\is_array($rows)) {
            return [];
        }
        return \array_values(\array_map(static fn (array $d) => Device::fromArray($d), \array_filter($rows, 'is_array')));
    }
}
