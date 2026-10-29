<?php

namespace Modules\Glitter\SearchHub\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Glitter\SearchHub\Services\SearchHubService;

class SearchHubListener implements HookListenerInterface
{
    public function __construct(private readonly SearchHubService $service) {}

    public static function getSubscribedHooks(): array
    {
        return [
            'core.search.results' => ['method' => 'filterResults', 'priority' => 20, 'type' => 'filter'],
            'core.search.build_response' => ['method' => 'recordSearchAndReturnResponse', 'priority' => 1000, 'type' => 'filter'],
        ];
    }

    public function handle(...$args): void {}

    public function filterResults(array $results, array $context): array
    {
        try {
            return $this->service->filterResults($results, $context);
        } catch (\Throwable $exception) {
            Log::warning('Glitter Search Hub provider filtering failed', [
                'stage' => 'provider_filtering',
                'exception_class' => $exception::class,
            ]);

            return $this->service->failClosedResults($results);
        }
    }

    public function recordSearchAndReturnResponse(array $response, array $results, array $context): array
    {
        $this->service->recordSearch($results, $context);

        return $response;
    }
}
