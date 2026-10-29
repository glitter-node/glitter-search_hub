# Glitter Search Hub

Current release: 0.1.6. The module-owned user search layout is the reusable search UI for active templates. Validated static template declarations continue to run through the core search hooks. Existing aggregate analytics rows are not recalculated.

Glitter Search Hub adds provider controls, aggregate search analytics, diagnostics, and a module-owned public search page to Gnuboard7. It uses the core `GET /api/search` endpoint and the documented `core.search.results` and `core.search.build_response` hooks.

## Requirements

- Gnuboard7 `>=7.1.1` and PHP `>=8.2` as declared in `module.json` and `composer.json`.
- Optional integrations: active `sirsoft-board` for post results and active `sirsoft-page` for page results. These providers are not hard dependencies; their own listeners perform visibility checks.
- The license identifier is intentionally unspecified pending the copyright holder's decision. Review the included copyright/license notices before redistribution.

## Installation

From the project root, install the bundled module with `php artisan module:install glitter-search_hub`, then activate it with `php artisan module:activate glitter-search_hub`. Use the project-approved PHP CLI path where required by the host. Installation registers permissions and menus and runs the module migrations. The logical table names are `glitter_search_hub_providers` and `glitter_search_hub_queries`; Laravel applies the configured `g7_` prefix.

## Provider discovery

Active modules may declare search providers by exposing a public `getSearchProviders(): array` method on their module class. Each declaration must include `provider_id`, `module_id`, `search_type`, `label`, `enabled_by_default`, and `default_order`; optional `description`, `capabilities`, `diagnostics`, `display_label_i18n`, and `provider_version` metadata is preserved for diagnostics and administration. The Search Hub validates declarations, rejects provider/type collisions, isolates invalid modules, and merges valid declarations with the built-in `posts` and `pages` providers.

Provider declarations are discovered from the active module manager, not by scanning arbitrary files. Existing provider settings are keyed by provider ID and remain authoritative when a provider is rediscovered. A module can therefore add a provider without changing Search Hub source, provided its own search listener is registered through the normal module lifecycle.

The registry records ownership (`module`, `template`, or `host_endpoint`) and discovery source (`builtin`, `module_declaration`, `template_declaration`, or `endpoint_discovery`). The existing `posts`, `pages`, `courses`, and `lessons` identifiers remain unchanged.

## User-template declarations

The active user template may opt into declarative discovery by shipping a static `search-providers.json` file at its template root. The file is read only from the active installed user-template directory; the active admin template and inactive templates are ignored. No PHP callbacks, class names, URLs, or arbitrary files are executed.

Example:

```json
{
  "version": "1.0.0",
  "providers": [
    {
      "provider_id": "academy_articles",
      "owner_type": "template",
      "owner_id": "vendor-template",
      "search_type": "academy_articles",
      "label": {"ko": "아티클", "en": "Articles"},
      "description": {"ko": "공개 아티클", "en": "Public articles"},
      "enabled_by_default": false,
      "default_order": 100,
      "execution_contract": null
    }
  ]
}
```

`owner_id` must equal the active template identifier. A declaration without a supported execution contract is retained for administration and diagnostics but is marked unavailable. The `static_layout_translation_v1` contract is supported: it names one layout, a safe fallback URL, locale JSON files, and sections of visible translation keys. Search Hub validates the declared keys against the active installed layout and both translations, then searches only the displayed strings. URL values are used for navigation, never keyword matching. No arbitrary PHP, scripts, network endpoints, or files are executed. Existing administrator settings are preserved by provider ID; rediscovery does not re-enable a disabled provider. Invalid declarations, duplicate IDs, and search-type collisions are isolated and reported in diagnostics. A template with no declaration file is a normal empty state.

Endpoint discovery is separate: Search Hub may inspect local GET routes and present verified candidates to an administrator, but it never auto-registers an unverified route or makes arbitrary network requests. An endpoint needs a trusted adapter and an explicit administrator action before it can become a provider.

The administrator provider screen also performs a read-only scan of the host route registry for local GET routes whose URI or route name is search-related. It records the route name, URI, methods, middleware, authentication restrictions, and known response contract without issuing HTTP requests. The core `/api/search` route is reported as already registered for the current providers. Administrative, internal, and contract-unverified routes are explicitly non-registrable until a module-owned provider declaration and execution adapter establish a safe search contract.

## Features and limitations

- The module still owns `/search-hub`. A user template can inherit `glitter-search_hub.user_search_hub` for its own `/search` route. Both use the existing `/api/search` API. The public provider catalog exposes only enabled, available provider IDs, search types, localized labels, and display order. Source modules remain responsible for access control.
- Provider controls are registry-driven. Built-in `posts` and `pages`, plus valid declarations from active modules such as Glitter Learning's `courses` and `lessons`, appear in the same administration view. Disabling a provider replaces its category payload with an empty payload after source listeners run; this suppresses response exposure but does not avoid the underlying provider query cost.
- Analytics store daily aggregate counters only. Search text, user identifiers, and IP addresses are not stored. Recording errors are caught and logged so they do not turn a search response into an error.
- Diagnostics are read-only. Settings report the fixed privacy behavior; raw search terms cannot be enabled for storage. Automatic analytics retention cleanup is not implemented in 0.1.0, so aggregate rows remain until module data is removed through the official lifecycle.
- The module does not integrate CRM, Help Desk, Tasks, or Media Manager because no compatible public search/access contract was established for those modules.

## Development assets

The committed distribution files are `dist/js/module.iife.js` and `dist/css/module.css`. Build only through the module-scoped official build procedure after reviewing its output target. The module does not modify the core search engine or template source.
