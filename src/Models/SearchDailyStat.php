<?php

namespace Modules\Glitter\SearchHub\Models;

use Illuminate\Database\Eloquent\Model;

class SearchDailyStat extends Model
{
    protected $table = 'glitter_search_hub_queries';

    protected $fillable = ['stat_date', 'provider_key', 'request_count', 'success_count', 'failure_count', 'empty_count'];

    protected function casts(): array
    {
        return [
            'stat_date' => 'date',
            'request_count' => 'integer',
            'success_count' => 'integer',
            'failure_count' => 'integer',
            'empty_count' => 'integer',
        ];
    }
}
