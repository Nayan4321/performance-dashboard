<?php

namespace App\Services\Integrations;

use App\Services\CallGear\CallGearSource;
use App\Services\Zenoti\ZenotiSource;
use InvalidArgumentException;

class IntegrationManager
{
    /** @return array<string, PerformanceSource> */
    public function all(): array
    {
        return [
            'zenoti' => app(ZenotiSource::class),
            'callgear' => app(CallGearSource::class),
        ];
    }

    public function get(string $key): PerformanceSource
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("Unknown integration [$key]");
    }
}
