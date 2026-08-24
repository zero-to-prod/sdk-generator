<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Zerotoprod\DataModel\Describe;
use Zerotoprod\Sdk\Internal\DataModel;

/**
 * Collection response. Nests {@see FixturePagination}, a fixture rather than the
 * shipped `Zerotoprod\Sdk\Models\Pagination`, so a document that declares its
 * own `Pagination` schema cannot reshape what the shared suite asserts.
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
}
