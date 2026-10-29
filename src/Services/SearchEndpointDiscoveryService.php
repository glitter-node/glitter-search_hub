<?php

namespace Modules\Glitter\SearchHub\Services;

use App\Extension\ModuleManager;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;

class SearchEndpointDiscoveryService
{
    public function __construct(private readonly SearchHubService $searchHub)
    {
    }

    /**
     * Discover local GET routes that explicitly look search-related.
     * No network request or route execution is performed here.
     */
    public function discover(): array
    {
        $registered = collect($this->searchHub->providers())->keyBy('key');
        $routes = app('router')->getRoutes();
        $candidates = [];

        foreach ($routes as $route) {
            if (! $route instanceof Route || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = '/'.ltrim($route->uri(), '/');
            $name = (string) ($route->getName() ?? '');
            $action = (string) $route->getActionName();
            if (! $this->looksSearchRelated($uri, $name, $action)) {
                continue;
            }

            $candidateId = hash('sha256', $uri.'|'.$name.'|GET');
            $providerKeys = $this->providerKeysForRoute($uri, $registered);
            $middleware = array_values($route->middleware());
            $adminOnly = str_contains($uri, '/admin/') || str_contains($name, 'admin.') || $this->hasAdminMiddleware($middleware);
            $moduleId = $this->moduleIdForAction($action);
            $registeredProvider = $providerKeys !== [];
            $contract = $registeredProvider ? 'registered' : ($adminOnly ? 'unsupported' : 'contract_unverified');

            $candidates[] = [
                'candidate_id' => $candidateId,
                'owner_type' => 'host_endpoint',
                'owner_id' => $candidateId,
                'discovery_source' => 'endpoint_discovery',
                'uri' => $uri,
                'name' => $name,
                'methods' => ['GET'],
                'module_id' => $moduleId,
                'action' => $action,
                'middleware' => $middleware,
                'authentication_required' => $this->hasAuthenticationMiddleware($middleware),
                'admin_only' => $adminOnly,
                'provider_keys' => $providerKeys,
                'search_parameters' => $registeredProvider ? ['q', 'type', 'page', 'per_page'] : [],
                'response_contract' => $registeredProvider ? 'core.search.build_response' : 'unknown',
                'status' => $contract,
                'registration_allowed' => false,
                'unavailable_reason' => $contract === 'registered' ? null : $this->reasonFor($contract),
                'reason' => $this->reasonFor($contract),
            ];
        }

        return collect($candidates)->sortBy(['status', 'uri'])->values()->all();
    }

    public function register(string $candidateId): array
    {
        $candidate = collect($this->discover())->firstWhere('candidate_id', $candidateId);
        abort_unless(is_array($candidate), 404, 'Unknown search endpoint candidate.');
        abort_unless($candidate['registration_allowed'] === true, 422, 'Search contract verification is required before registration.');

        return $candidate;
    }

    private function looksSearchRelated(string $uri, string $name, string $action): bool
    {
        $haystack = strtolower($uri.' '.$name.' '.$action);

        return $uri === '/api/search' || str_contains($haystack, 'search');
    }

    private function providerKeysForRoute(string $uri, $registered): array
    {
        if ($uri !== '/api/search') {
            return [];
        }

        return $registered->keys()->values()->all();
    }

    private function moduleIdForAction(string $action): ?string
    {
        if (! str_starts_with($action, 'Modules\\')) {
            return null;
        }

        foreach (app(ModuleManager::class)->getActiveModules() as $module) {
            $identifier = $module->getIdentifier();
            if (str_contains($action, '\\'.str_replace('-', '', ucwords($identifier, '-')).'\\')) {
                return $identifier;
            }
        }

        return null;
    }

    private function hasAuthenticationMiddleware(array $middleware): bool
    {
        return collect($middleware)->contains(fn (string $item): bool => str_contains($item, 'auth'));
    }

    private function hasAdminMiddleware(array $middleware): bool
    {
        return collect($middleware)->contains(fn (string $item): bool => str_contains($item, 'permission:admin'));
    }

    private function reasonFor(string $status): string
    {
        return match ($status) {
            'registered' => 'registered_provider',
            'unsupported' => 'admin_or_internal_endpoint',
            default => 'no_explicit_search_provider_contract',
        };
    }
}
