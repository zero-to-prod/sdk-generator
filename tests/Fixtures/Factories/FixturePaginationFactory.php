<?php

namespace Tests\Fixtures\Factories;

use Tests\Fixtures\Models\FixturePagination;
use Zerotoprod\DataModelFactory\DataModelFactory;

class FixturePaginationFactory
{
    use DataModelFactory;

    protected $model = FixturePagination::class;

    protected function definition(): array
    {
        return [
            FixturePagination::current_page => 1,
            FixturePagination::last_page => 1,
            FixturePagination::per_page => 10,
            FixturePagination::total => 1,
            FixturePagination::next_page_url => null,
            FixturePagination::prev_page_url => null,
        ];
    }

    public function make(array $context = []): FixturePagination
    {
        return $this->instantiate($context);
    }
}
