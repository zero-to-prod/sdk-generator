<?php

namespace Tests\Fixtures\Factories;

use Tests\Fixtures\Models\FixtureThingsResponse;
use Zerotoprod\DataModelFactory\DataModelFactory;

/**
 * Composes other factories through `context()` rather than `make()->toArray()`,
 * which is the pattern the README documents.
 */
class FixtureThingsResponseFactory
{
    use DataModelFactory;

    protected $model = FixtureThingsResponse::class;

    protected function definition(): array
    {
        return [
            FixtureThingsResponse::things => [
                FixtureThingFactory::factory()->context(),
            ],
            FixtureThingsResponse::Pagination => FixturePaginationFactory::factory()->context(),
        ];
    }

    public function make(array $context = []): FixtureThingsResponse
    {
        return $this->instantiate($context);
    }
}
