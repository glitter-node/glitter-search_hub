<?php

namespace Modules\Glitter\SearchHub\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Search\SearchCategoryPayload;
use Illuminate\Support\Facades\Log;
use Modules\Glitter\SearchHub\Services\SearchHubService;
use Modules\Glitter\SearchHub\Services\SearchTemplateProviderDiscoveryService;
use Modules\Glitter\SearchHub\Services\StaticTemplateSearchService;

class StaticTemplateSearchListener implements HookListenerInterface
{
    public function __construct(
        private readonly SearchTemplateProviderDiscoveryService $discovery,
        private readonly StaticTemplateSearchService $staticSearch,
        private readonly SearchHubService $hub,
    ) {}

    public static function getSubscribedHooks(): array
    {
        return [
            'core.search.index_validation_rules' => ['method' => 'validationRules', 'priority' => 10, 'type' => 'filter'],
            'core.search.results' => ['method' => 'search', 'priority' => 15, 'type' => 'filter'],
            'core.search.build_response' => ['method' => 'buildResponse', 'priority' => 10, 'type' => 'filter'],
        ];
    }

    public function handle(...$args): void {}

    public function validationRules(array $rules, $request): array
    {
        $settings = collect($this->hub->providers())->keyBy('key');
        foreach ($this->discovery->discover() as $provider) {
            $setting = $settings->get($provider['provider_id']);
            if ($this->isUsable($provider, $setting) && $request->input('type') === $provider['search_type']) {
                $rules['sort'] = ['nullable', 'string', 'in:relevance,latest,oldest'];
                break;
            }
        }
        return $rules;
    }

    public function search(array $results, array $context): array
    {
        $type = (string) ($context['type'] ?? 'all');
        $settings = collect($this->hub->providers())->keyBy('key');
        foreach ($this->discovery->discover() as $provider) {
            $key = $provider['provider_id'];
            $setting = $settings->get($key);
            if (! $this->isUsable($provider, $setting)) {
                continue;
            }
            $full = $type === 'all' || $type === $provider['search_type'];
            if (! $full && ! ($context['include_inactive_counts'] ?? true)) {
                continue;
            }

            try {
                $locale = app()->getLocale();
                $translations = $provider['static_search']['translations'];
                if (! isset($translations[$locale])) {
                    $locale = isset($translations['en']) ? 'en' : array_key_first($translations);
                }
                $catalog = $this->staticSearch->catalog($provider['template_root'], $provider['static_search'], $locale);
                $matched = $this->matched($catalog, (string) ($context['q'] ?? ''));
                $total = count($matched);
                if (! $full) {
                    $results[$key] = ['total' => $total, 'total_relation' => 'exact', 'total_is_exact' => true, 'result_cap' => null, 'items' => [], 'failed' => false];
                    continue;
                }

                $sort = (string) ($context['sort'] ?? 'relevance');
                if ($sort === 'oldest') {
                    $matched = array_reverse($matched);
                } elseif ($sort === 'relevance') {
                    usort($matched, fn (array $a, array $b): int => $b['_score'] <=> $a['_score'] ?: $a['_position'] <=> $b['_position']);
                }

                $perPage = $type === 'all' ? (int) ($context['all_tab_limit'] ?? 5) : (int) ($context['per_page'] ?? 10);
                $page = $type === 'all' ? 1 : (int) ($context['page'] ?? 1);
                $perPage = min(100, max(1, $perPage));
                $page = max(1, $page);
                $items = array_slice($matched, ($page - 1) * $perPage, $perPage);
                foreach ($items as &$item) {
                    unset($item['_score'], $item['_position']);
                    $item['provider_key'] = $key;
                }
                unset($item);
                $results[$key] = [
                    'total' => $total,
                    'total_relation' => 'exact',
                    'total_is_exact' => true,
                    'result_cap' => null,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                    'has_more_pages' => $page * $perPage < $total,
                    'next_cursor' => null,
                    'prev_cursor' => null,
                    'items' => $items,
                    'failed' => false,
                ];
            } catch (\Throwable $exception) {
                Log::warning('Static template search failed', ['provider_id' => $key, 'exception_class' => $exception::class]);
                $results[$key] = SearchCategoryPayload::failed();
            }
        }
        return $results;
    }

    public function buildResponse(array $response, array $results, array $context): array
    {
        $settings = collect($this->hub->providers())->keyBy('key');
        foreach ($this->discovery->discover() as $provider) {
            $key = $provider['provider_id'];
            if (! $this->isUsable($provider, $settings->get($key)) || ! isset($results[$key])) {
                continue;
            }
            $payload = $results[$key];
            $response[$key.'_count'] = (int) ($payload['total'] ?? 0);
            $response[$key] = [
                'total' => (int) ($payload['total'] ?? 0),
                'total_relation' => $payload['total_relation'] ?? 'exact',
                'total_is_exact' => $payload['total_is_exact'] ?? true,
                'result_cap' => $payload['result_cap'] ?? null,
                'items' => $payload['items'] ?? [],
            ];
            if (($context['type'] ?? 'all') === $provider['search_type']) {
                $response['current_page'] = (int) ($context['page'] ?? 1);
                $response['per_page'] = (int) ($context['per_page'] ?? 10);
                $response['last_page'] = $payload['last_page'] ?? null;
                $response['has_more_pages'] = $payload['has_more_pages'] ?? false;
                $response['next_cursor'] = null;
                $response['prev_cursor'] = null;
            }
        }
        return $response;
    }

    private function isUsable(array $provider, ?array $setting): bool
    {
        return ($provider['available'] ?? false) === true
            && $setting !== null
            && ($setting['owner_type'] ?? null) === 'template'
            && ($setting['owner_id'] ?? null) === ($provider['owner_id'] ?? null)
            && ($setting['enabled'] ?? false) === true
            && ($setting['available'] ?? false) === true
            && in_array($provider['search_type'], $setting['supported_types'] ?? [], true);
    }

    private function matched(array $catalog, string $query): array
    {
        $terms = preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [];
        $matched = [];
        foreach ($catalog as $position => $item) {
            $linkLabels = implode(' ', array_column($item['links'], 'label'));
            $text = mb_strtolower(implode(' ', [$item['id'], $item['title'], $item['summary'], $item['category'], $linkLabels]));
            foreach ($terms as $term) {
                if ($term === '' || ! str_contains($text, $term)) {
                    continue 2;
                }
            }
            $score = 0;
            foreach ($terms as $term) {
                $score += (int) str_contains(mb_strtolower($item['title']), $term) * 5;
                $score += (int) str_contains(mb_strtolower($item['summary']), $term) * 2;
                $score += (int) str_contains(mb_strtolower($item['category']), $term);
            }
            $item['_score'] = $score;
            $item['_position'] = $position;
            $matched[] = $item;
        }
        return $matched;
    }
}
