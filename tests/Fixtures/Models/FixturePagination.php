<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use Zerotoprod\DataModel\Describe;
use Zerotoprod\Sdk\Internal\DataModel;

/**
 * Pagination metadata for {@see FixtureThingsResponse}.
 *
 * A mirror of the shipped `Zerotoprod\Sdk\Models\Pagination`, and deliberately
 * not that class. `Pagination` is example content: a derived package whose own
 * document paginates differently drops it from `retain_models`, and the next run
 * sweeps it. Naming it from the shared suite would fail there on fields the
 * template happened to ship.
 */
class FixturePagination
{
    use DataModel;

    /** @see $current_page */
    public const current_page = 'current_page';
    /** @see $last_page */
    public const last_page = 'last_page';
    /** @see $per_page */
    public const per_page = 'per_page';
    /** @see $total */
    public const total = 'total';
    /** @see $next_page_url */
    public const next_page_url = 'next_page_url';
    /** @see $prev_page_url */
    public const prev_page_url = 'prev_page_url';

    #[Describe(['nullable' => true])]
    public ?int $current_page = null;
    #[Describe(['nullable' => true])]
    public ?int $last_page = null;
    #[Describe(['nullable' => true])]
    public ?int $per_page = null;
    #[Describe(['nullable' => true])]
    public ?int $total = null;
    #[Describe(['nullable' => true])]
    public ?string $next_page_url = null;
    #[Describe(['nullable' => true])]
    public ?string $prev_page_url = null;
}
