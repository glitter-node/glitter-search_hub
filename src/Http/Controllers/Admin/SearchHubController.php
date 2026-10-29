<?php

namespace Modules\Glitter\SearchHub\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Modules\Glitter\SearchHub\Http\Requests\Admin\UpdateProviderRequest;
use Modules\Glitter\SearchHub\Services\SearchHubService;
use Modules\Glitter\SearchHub\Services\SearchEndpointDiscoveryService;

class SearchHubController extends AdminBaseController
{
    public function __construct(
        private readonly SearchHubService $service,
        private readonly SearchEndpointDiscoveryService $endpointDiscovery,
    )
    {
        parent::__construct();
    }

    public function dashboard(): JsonResponse
    {
        return $this->success('glitter-search_hub::messages.ok', [
            'providers' => $this->service->providers(),
            'analytics' => $this->service->analytics(7),
        ]);
    }

    public function providers(): JsonResponse
    {
        return $this->success('glitter-search_hub::messages.ok', $this->service->providers());
    }

    public function updateProvider(UpdateProviderRequest $request, string $provider): JsonResponse
    {
        return $this->success('glitter-search_hub::messages.saved', $this->service->updateProvider(
            $provider,
            (bool) $request->validated('enabled'),
            (int) $request->validated('sort_order'),
            $request->validated('supported_types')
        ));
    }

    public function analytics(Request $request): JsonResponse
    {
        $days = min(90, max(1, (int) $request->query('days', 30)));

        return $this->success('glitter-search_hub::messages.ok', $this->service->analytics($days));
    }

    public function diagnostics(): JsonResponse
    {
        $endpointCandidates = $this->endpointDiscovery->discover();
        $providers = $this->service->providers();

        return $this->success('glitter-search_hub::messages.ok', [
            'database' => 'connected',
            'providers' => $providers,
            'search_endpoint' => url('/api/search'),
            'tables' => [
                'glitter_search_hub_providers' => Schema::hasTable('glitter_search_hub_providers'),
                'glitter_search_hub_queries' => Schema::hasTable('glitter_search_hub_queries'),
            ],
            'endpoint_discovery' => [
                'candidates' => count($endpointCandidates),
                'registered' => count(array_filter($endpointCandidates, fn (array $candidate): bool => $candidate['status'] === 'registered')),
                'registrable' => count(array_filter($endpointCandidates, fn (array $candidate): bool => $candidate['registration_allowed'] === true)),
            ],
            'provider_discovery' => [
                'modules' => count(array_filter($providers, fn (array $provider): bool => $provider['owner_type'] === 'module')),
                'templates' => count(array_filter($providers, fn (array $provider): bool => $provider['owner_type'] === 'template')),
                'host_endpoints' => count(array_filter($endpointCandidates, fn (array $candidate): bool => $candidate['status'] === 'registered')),
            ],
            'discovery_errors' => $this->service->discoveryDiagnostics(),
        ]);
    }

    public function settings(): JsonResponse
    {
        return $this->success('glitter-search_hub::messages.ok', [
            'query_text_storage' => false,
            'automatic_analytics_retention' => false,
            'supported_providers' => array_column($this->service->providers(), 'key'),
        ]);
    }

    public function discoverEndpoints(): JsonResponse
    {
        return $this->success('glitter-search_hub::messages.ok', [
            'candidates' => $this->endpointDiscovery->discover(),
        ]);
    }

    public function registerEndpoint(string $candidate): JsonResponse
    {
        return $this->success('glitter-search_hub::messages.saved', $this->endpointDiscovery->register($candidate));
    }
}
