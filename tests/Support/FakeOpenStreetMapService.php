<?php

namespace Tests\Support;

use App\Services\OpenStreetMapService;

/**
 * Type-safe test double: a real OpenStreetMapService subclass with the
 * network calls replaced by fixed results. Avoids Mockery's MockInterface,
 * which PHPStan cannot pass to the ConfidenceCalculator constructor.
 */
class FakeOpenStreetMapService extends OpenStreetMapService
{
    public function __construct(
        private ?array $reverseResult = null,
        private ?array $forwardResult = null,
    ) {}

    public function reverseGeocode(float $lat, float $lng): ?array
    {
        return $this->reverseResult;
    }

    public function forwardGeocode(string $landmark, ?string $city = null, ?string $country = null): ?array
    {
        return $this->forwardResult;
    }

    public function lastOutcomeCategory(): ?string
    {
        return 'ok';
    }
}
