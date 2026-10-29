<?php

namespace Modules\Glitter\SearchHub\Models;

use Illuminate\Database\Eloquent\Model;

class SearchProvider extends Model
{
    protected $table = 'glitter_search_hub_providers';

    protected $fillable = ['provider_key', 'enabled', 'sort_order', 'supported_types'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'sort_order' => 'integer',
            'supported_types' => 'array',
        ];
    }
}
