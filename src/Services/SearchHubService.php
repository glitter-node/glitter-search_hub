<?php

namespace Modules\Glitter\SearchHub\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Extension\ModuleManager;
use Modules\Glitter\SearchHub\Models\SearchDailyStat;
use Modules\Glitter\SearchHub\Models\SearchProvider;
use Modules\Glitter\SearchHub\Services\SearchTemplateProviderDiscoveryService;

class SearchHubService
{
    private const BUILTIN_PROVIDERS = [
        'posts' => ['types' => ['posts'], 'order' => 10],
        'pages' => ['types' => ['pages'], 'order' => 20],
    ];

    private array $availability = [];
    private ?array $definitions = null;
    private array $discoveryErrors = [];

    public function __construct(private readonly SearchTemplateProviderDiscoveryService $templateDiscovery)
    {
    }

    public function providers(): array
    {
        $saved = SearchProvider::query()->get()->keyBy('provider_key');
        $definitions = $this->providerDefinitions();

        return collect($definitions)->map(function (array $definition, string $key) use ($saved): array {
            $row = $saved->get($key);
            $label = $definition['label'];
            $locale = app()->getLocale();

            return [
                'key' => $key,
                'module_id' => $definition['module_id'],
                'owner_type' => $definition['owner_type'],
                'owner_id' => $definition['owner_id'],
                'discovery_source' => $definition['discovery_source'],
                'execution_contract' => $definition['execution_contract'],
                'unavailable_reason' => $definition['unavailable_reason'],
                'label' => is_array($label) ? ($label[$locale] ?? $label['en'] ?? reset($label)) : (string) $label,
                'label_i18n' => is_array($label) ? $label : [],
                'description' => $definition['description'],
                'enabled' => $row?->enabled ?? ($definition['enabled_by_default'] ?? true),
                'sort_order' => $row?->sort_order ?? $definition['order'],
                'supported_types' => $row?->supported_types ?? $definition['types'],
                'available' => $this->isProviderAvailable($key),
            ];
        })->sortBy('sort_order')->values()->all();
    }

    /** Public metadata only; never expose diagnostics or disabled providers. */
    public function publicProviders(): array
    {
        return collect($this->providers())
            ->filter(fn (array $provider): bool => $provider['enabled'] && $provider['available'])
            ->map(fn (array $provider): array => [
                'key' => $provider['key'],
                'provider_id' => $provider['key'],
                'search_type' => $provider['supported_types'][0] ?? $provider['key'],
                'label' => $provider['label'],
                'sort_order' => $provider['sort_order'],
                'display_order' => $provider['sort_order'],
            ])
            ->values()->all();
    }

    public function updateProvider(string $key, bool $enabled, int $sortOrder, array $types): array
    {
        $definitions = $this->providerDefinitions();
        abort_unless(isset($definitions[$key]), 422, 'Unsupported search provider.');
        $allowedTypes = $definitions[$key]['types'];
        abort_unless($types !== [] && array_diff($types, $allowedTypes) === [], 422, 'Unsupported provider search type.');

        $provider = SearchProvider::query()->updateOrCreate(
            ['provider_key' => $key],
            ['enabled' => $enabled, 'sort_order' => $sortOrder, 'supported_types' => $types]
        );

        return [
            'key' => $provider->provider_key,
            'module_id' => $definitions[$key]['module_id'],
            'owner_type' => $definitions[$key]['owner_type'],
            'owner_id' => $definitions[$key]['owner_id'],
            'discovery_source' => $definitions[$key]['discovery_source'],
            'execution_contract' => $definitions[$key]['execution_contract'],
            'unavailable_reason' => $definitions[$key]['unavailable_reason'],
            'label' => is_array($definitions[$key]['label'])
                ? ($definitions[$key]['label'][app()->getLocale()] ?? $definitions[$key]['label']['en'] ?? reset($definitions[$key]['label']))
                : (string) $definitions[$key]['label'],
            'label_i18n' => is_array($definitions[$key]['label']) ? $definitions[$key]['label'] : [],
            'description' => $definitions[$key]['description'],
            'enabled' => $provider->enabled,
            'sort_order' => $provider->sort_order,
            'supported_types' => $provider->supported_types,
            'available' => $this->isProviderAvailable($key),
        ];
    }

    public function filterResults(array $results, array $context = []): array
    {
        $definitions = $this->providerDefinitions();
        $providers = collect($this->providers())->keyBy('key')->sortBy('sort_order');
        $ordered = [];
        $requestedType = $context['type'] ?? 'all';

        foreach ($providers as $key => $provider) {
            $typeEnabled = $requestedType === 'all' || in_array($requestedType, $provider['supported_types'], true);
            if (isset($results[$key]) && $provider['enabled'] && $provider['available'] && $typeEnabled) {
                $ordered[$key] = $results[$key];
            } elseif (isset($results[$key]) && $provider['enabled'] && $provider['available']) {
                // Other tabs may supply authorized count-only badges, never their items.
                $ordered[$key] = array_merge($results[$key], ['items' => []]);
            } elseif (isset($results[$key])) {
                $ordered[$key] = ['total' => 0, 'items' => [], 'total_is_exact' => true, 'failed' => false];
            }
        }

        return $ordered;
    }

    /**
     * 제공자 상태를 확인할 수 없을 때 검색 결과를 안전한 실패 페이로드로 축소합니다.
     * 원본 결과를 되돌리면 비활성 제공자 결과가 노출될 수 있으므로 사용하지 않습니다.
     */
    public function failClosedResults(array $results): array
    {
        $safe = [];

        foreach (array_keys($results) as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $safe[$key] = [
                'total' => 0,
                'items' => [],
                'total_is_exact' => true,
                'failed' => true,
            ];
        }

        return $safe;
    }

    public function recordSearch(array $results, array $context): void
    {
        try {
            $date = now()->toDateString();
            $failed = collect($results)->contains(fn ($result) => ($result['failed'] ?? false) === true);
            $total = (int) collect($results)->sum(fn ($result) => (int) ($result['total'] ?? 0));
            $this->incrementStat($date, '*', ! $failed, $failed, $total === 0);

            foreach ($this->recordableProviderKeys($results, $context) as $key) {
                $providerResult = $results[$key];
                $providerFailed = ($providerResult['failed'] ?? false) === true;
                $providerTotal = (int) ($providerResult['total'] ?? 0);
                $this->incrementStat($date, $key, ! $providerFailed, $providerFailed, $providerTotal === 0);
            }
        } catch (\Throwable $exception) {
            Log::warning('Glitter Search Hub analytics recording failed', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * 현재 검색 요청에서 실제 제공자 실행으로 간주할 결과 키만 반환합니다.
     *
     * 코어 훅에는 별도의 실행 목록 메타데이터가 없으므로, 코어가 새 배열에서
     * 제공자 결과를 추가한다는 계약과 요청 type을 함께 사용합니다. 다른 탭의
     * 배지용 count-only 결과는 요청 type과 일치하지 않아 실행 횟수에 포함하지
     * 않습니다. 결과가 없는 제공자나 비활성·사용 불가 제공자도 집계하지 않습니다.
     */
    private function recordableProviderKeys(array $results, array $context): array
    {
        $definitions = $this->providerDefinitions();
        $providers = collect($this->providers())->keyBy('key');
        $requestedType = (string) ($context['type'] ?? 'all');
        $keys = [];

        foreach (array_keys($results) as $key) {
            if (! is_string($key) || ! isset($definitions[$key], $providers[$key]) || ! is_array($results[$key])) {
                continue;
            }

            $provider = $providers[$key];
            $supportedTypes = $provider['supported_types'] ?? $definitions[$key]['types'];
            if ($requestedType !== 'all' && ! in_array($requestedType, $supportedTypes, true)) {
                continue;
            }
            if (! $provider['enabled'] || ! $provider['available']) {
                continue;
            }

            $keys[] = $key;
        }

        return array_values(array_unique($keys));
    }

    public function analytics(int $days = 30): array
    {
        $from = now()->subDays($days - 1)->toDateString();

        return SearchDailyStat::query()
            ->whereDate('stat_date', '>=', $from)
            ->orderBy('stat_date')
            ->get(['stat_date', 'provider_key', 'request_count', 'success_count', 'failure_count', 'empty_count'])
            ->toArray();
    }

    public function discoveryDiagnostics(): array
    {
        $this->providerDefinitions();

        return $this->discoveryErrors;
    }

    private function providerDefinitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $definitions = [];
        foreach (self::BUILTIN_PROVIDERS as $key => $definition) {
            $definitions[$key] = [
                'module_id' => $key === 'posts' ? 'sirsoft-board' : 'sirsoft-page',
                'owner_type' => 'module',
                'owner_id' => $key === 'posts' ? 'sirsoft-board' : 'sirsoft-page',
                'discovery_source' => 'builtin',
                'execution_contract' => 'core.search.results',
                'unavailable_reason' => null,
                'label' => ['ko' => $key === 'posts' ? '게시글' : '페이지', 'en' => ucfirst($key)],
                'description' => ['ko' => '공식 검색 제공자', 'en' => 'Official search provider'],
                'types' => $definition['types'],
                'enabled_by_default' => true,
                'order' => $definition['order'],
            ];
        }

        $usedTypes = collect($definitions)->flatMap(fn (array $definition) => $definition['types'])->all();
        foreach (app(ModuleManager::class)->getActiveModules() as $module) {
            if (! method_exists($module, 'getSearchProviders')) {
                continue;
            }

            try {
                foreach ((array) $module->getSearchProviders() as $declaration) {
                    if (! is_array($declaration) || ! $this->isValidDeclaration($declaration, $module->getIdentifier())) {
                        $this->discoveryErrors[] = ['module_id' => $module->getIdentifier(), 'error' => 'invalid_declaration'];
                        continue;
                    }
                    $providerId = $declaration['provider_id'];
                    $searchType = $declaration['search_type'];
                    if (isset($definitions[$providerId]) || in_array($searchType, $usedTypes, true)) {
                        $this->discoveryErrors[] = ['module_id' => $module->getIdentifier(), 'provider_id' => $providerId, 'error' => 'duplicate_provider'];
                        continue;
                    }
                    $definitions[$providerId] = [
                        'module_id' => $declaration['module_id'],
                        'owner_type' => 'module',
                        'owner_id' => $declaration['module_id'],
                        'discovery_source' => 'module_declaration',
                        'execution_contract' => 'core.search.results',
                        'unavailable_reason' => null,
                        'label' => $declaration['label'],
                        'description' => $declaration['description'] ?? ['ko' => '', 'en' => ''],
                        'types' => [$searchType],
                        'enabled_by_default' => (bool) $declaration['enabled_by_default'],
                        'order' => (int) ($declaration['default_order'] ?? ((count($definitions) + 1) * 10)),
                    ];
                    $usedTypes[] = $searchType;
                }
            } catch (\Throwable $exception) {
                $this->discoveryErrors[] = ['module_id' => $module->getIdentifier(), 'error' => 'discovery_exception'];
                Log::warning('Search provider discovery failed', ['module_id' => $module->getIdentifier(), 'message' => $exception->getMessage()]);
            }
        }

        foreach ($this->templateDiscovery->discover() as $declaration) {
            $providerId = $declaration['provider_id'];
            $searchType = $declaration['search_type'];
            if (isset($definitions[$providerId]) || in_array($searchType, $usedTypes, true)) {
                $this->discoveryErrors[] = [
                    'owner_type' => 'template',
                    'owner_id' => $declaration['owner_id'],
                    'provider_id' => $providerId,
                    'error' => 'duplicate_provider',
                ];
                continue;
            }
            $definitions[$providerId] = $declaration;
            $usedTypes[] = $searchType;
        }

        foreach ($this->templateDiscovery->diagnostics() as $error) {
            $this->discoveryErrors[] = $error;
        }

        return $this->definitions = $definitions;
    }

    private function isValidDeclaration(array $declaration, string $moduleId): bool
    {
        foreach (['provider_id', 'module_id', 'search_type', 'label', 'enabled_by_default', 'default_order'] as $key) {
            if (! array_key_exists($key, $declaration)) {
                return false;
            }
        }

        return $declaration['module_id'] === $moduleId
            && preg_match('/^[a-z][a-z0-9_-]{1,63}$/', (string) $declaration['provider_id']) === 1
            && preg_match('/^[a-z][a-z0-9_-]{1,63}$/', (string) $declaration['search_type']) === 1
            && is_array($declaration['label'])
            && is_bool($declaration['enabled_by_default'])
            && is_numeric($declaration['default_order']);
    }

    private function incrementStat(string $date, string $provider, bool $success, bool $failure, bool $empty): void
    {
        DB::table('glitter_search_hub_queries')->upsert(
            [[
                'stat_date' => $date,
                'provider_key' => $provider,
                'request_count' => 1,
                'success_count' => $success ? 1 : 0,
                'failure_count' => $failure ? 1 : 0,
                'empty_count' => $empty ? 1 : 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['stat_date', 'provider_key'],
            [
                'request_count' => DB::raw('request_count + 1'),
                'success_count' => DB::raw('success_count + '.(int) $success),
                'failure_count' => DB::raw('failure_count + '.(int) $failure),
                'empty_count' => DB::raw('empty_count + '.(int) $empty),
                'updated_at',
            ]
        );
    }

    private function isProviderAvailable(string $key): bool
    {
        if (array_key_exists($key, $this->availability)) {
            return $this->availability[$key];
        }

        $identifier = $this->providerDefinitions()[$key]['module_id'] ?? null;

        if (($this->providerDefinitions()[$key]['owner_type'] ?? null) === 'template') {
            return $this->availability[$key] = (bool) ($this->providerDefinitions()[$key]['available'] ?? false);
        }

        if ($identifier === null) {
            return $this->availability[$key] = false;
        }

        return $this->availability[$key] = DB::table('modules')->where('identifier', $identifier)->where('status', 'active')->exists();
    }
}
