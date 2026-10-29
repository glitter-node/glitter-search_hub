<?php

namespace Modules\Glitter\SearchHub\Http\Controllers;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Glitter\SearchHub\Services\SearchHubService;

class PublicSearchHubController extends PublicBaseController
{
    public function providers(SearchHubService $service): JsonResponse
    {
        return $this->success('glitter-search_hub::messages.ok', $service->publicProviders());
    }
}
