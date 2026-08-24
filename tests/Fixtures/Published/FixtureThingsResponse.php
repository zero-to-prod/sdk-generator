<?php

declare(strict_types=1);

namespace Tests\Fixtures\Published;

use Tests\Fixtures\Models\FixturePagination;
use Tests\Fixtures\Models\FixtureThing;
use Zerotoprod\DataModel\Describe;
use Zerotoprod\Sdk\Internal\DataModel;

/**
 * A "published" override of {@see \Tests\Fixtures\Models\FixtureThingsResponse}.
 *
 * `Tests\Fixtures\Published` deliberately publishes only two of the fixture
 * models, so pointing `SdkConfig::model_namespace` at it exercises both halves
 * of the per-class resolution: this class wins, while `FixtureThing` and
 * `Errors` fall back to their declared classes.
 */
class FixtureThingsResponse
{
    use DataModel;

    public const things = 'things';
    /** @var array<int, FixtureThing> */
    #[Describe([
        'cast' => [self::class, 'mapOf'],
        'type' => FixtureThing::class,
        'default' => [],
    ])]
    public array $things;

    public const Pagination = 'Pagination';
    #[Describe(['nullable' => true])]
    public ?FixturePagination $Pagination = null;

    public function publishedMarker(): string
    {
        return 'published';
    }
}
