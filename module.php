<?php

namespace Modules\Glitter\SearchHub;

use App\Extension\AbstractModule;
use Modules\Glitter\SearchHub\Listeners\SearchHubListener;
use Modules\Glitter\SearchHub\Listeners\StaticTemplateSearchListener;

class Module extends AbstractModule
{
    public function getPermissions(): array
    {
        return [
            'name' => ['ko' => '검색 허브', 'en' => 'Search Hub'],
            'description' => ['ko' => '통합 검색 허브 관리 권한', 'en' => 'Search Hub administration permissions'],
            'categories' => [
                [
                    'identifier' => 'search_hub',
                    'name' => ['ko' => '검색 허브', 'en' => 'Search Hub'],
                    'description' => ['ko' => '검색 제공자와 통계를 관리합니다.', 'en' => 'Manage search providers and analytics.'],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => ['ko' => '조회', 'en' => 'Read'],
                            'description' => ['ko' => '검색 허브 관리 화면을 조회합니다.', 'en' => 'View Search Hub administration.'],
                            'type' => 'admin',
                            'roles' => ['admin', 'manager'],
                        ],
                        [
                            'action' => 'update',
                            'name' => ['ko' => '수정', 'en' => 'Update'],
                            'description' => ['ko' => '검색 제공자 설정을 수정합니다.', 'en' => 'Update search provider settings.'],
                            'type' => 'admin',
                            'roles' => ['admin', 'manager'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function getHookListeners(): array
    {
        return [StaticTemplateSearchListener::class, SearchHubListener::class];
    }

    public function getAdminMenus(): array
    {
        return [[
            'name' => ['ko' => '통합 검색', 'en' => 'Integrated Search'],
            'slug' => 'glitter-search_hub',
            'url' => null,
            'icon' => 'fas fa-search',
            'order' => 80,
            'permission' => 'glitter-search_hub.search_hub.read',
            'children' => [
                ['name' => ['ko' => '대시보드', 'en' => 'Dashboard'], 'slug' => 'glitter-search_hub-dashboard', 'url' => '/admin/search-hub/dashboard', 'icon' => 'fas fa-chart-line', 'order' => 1, 'permission' => 'glitter-search_hub.search_hub.read'],
                ['name' => ['ko' => '검색 제공자', 'en' => 'Search Providers'], 'slug' => 'glitter-search_hub-providers', 'url' => '/admin/search-hub/providers', 'icon' => 'fas fa-plug', 'order' => 2, 'permission' => 'glitter-search_hub.search_hub.read'],
                ['name' => ['ko' => '검색 통계', 'en' => 'Search Analytics'], 'slug' => 'glitter-search_hub-analytics', 'url' => '/admin/search-hub/analytics', 'icon' => 'fas fa-chart-bar', 'order' => 3, 'permission' => 'glitter-search_hub.search_hub.read'],
                ['name' => ['ko' => '진단', 'en' => 'Diagnostics'], 'slug' => 'glitter-search_hub-diagnostics', 'url' => '/admin/search-hub/diagnostics', 'icon' => 'fas fa-stethoscope', 'order' => 4, 'permission' => 'glitter-search_hub.search_hub.read'],
                ['name' => ['ko' => '설정', 'en' => 'Settings'], 'slug' => 'glitter-search_hub-settings', 'url' => '/admin/search-hub/settings', 'icon' => 'fas fa-cog', 'order' => 5, 'permission' => 'glitter-search_hub.search_hub.read'],
            ],
        ]];
    }
}
