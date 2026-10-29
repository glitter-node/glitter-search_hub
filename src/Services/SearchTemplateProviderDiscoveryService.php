<?php

namespace Modules\Glitter\SearchHub\Services;

use App\Contracts\Extension\TemplateManagerInterface;
use Illuminate\Support\Facades\Log;

/**
 * Discovers declarative providers from the active user template.
 *
 * Templates are intentionally limited to static JSON metadata. Executable
 * search contracts must be supplied by a separately trusted integration and
 * are not inferred from arbitrary template code.
 */
class SearchTemplateProviderDiscoveryService
{
    private const DECLARATION_FILE = 'search-providers.json';
    private const MAX_FILE_BYTES = 65536;
    private const MAX_PROVIDERS = 32;

    private array $errors = [];
    private ?array $result = null;

    public function __construct(
        private readonly TemplateManagerInterface $templateManager,
        private readonly StaticTemplateSearchService $staticSearch,
    )
    {
    }

    public function discover(): array
    {
        if ($this->result !== null) {
            return $this->result;
        }

        $this->errors = [];
        $template = $this->templateManager->getActiveTemplate('user');
        if (! is_array($template)) {
            return $this->result = [];
        }

        $identifier = (string) ($template['identifier'] ?? '');
        $root = (string) ($template['_paths']['root'] ?? '');
        if ($identifier === '' || $root === '') {
            $this->errors[] = ['owner_type' => 'template', 'error' => 'active_template_metadata_missing'];

            return $this->result = [];
        }

        $templatesRoot = realpath(base_path('templates'));
        $templateRoot = realpath($root);
        if ($templatesRoot === false || $templateRoot === false || ! $this->isWithin($templateRoot, $templatesRoot)) {
            $this->errors[] = ['owner_type' => 'template', 'owner_id' => $identifier, 'error' => 'template_path_invalid'];

            return $this->result = [];
        }

        $declarationPath = $templateRoot.DIRECTORY_SEPARATOR.self::DECLARATION_FILE;
        if (! is_file($declarationPath) || is_link($declarationPath)) {
            return $this->result = [];
        }
        $realDeclarationPath = realpath($declarationPath);
        if ($realDeclarationPath === false || ! $this->isWithin($realDeclarationPath, $templateRoot)) {
            $this->errors[] = ['owner_type' => 'template', 'owner_id' => $identifier, 'error' => 'declaration_path_invalid'];

            return $this->result = [];
        }

        $size = filesize($realDeclarationPath);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            $this->errors[] = ['owner_type' => 'template', 'owner_id' => $identifier, 'error' => 'declaration_too_large'];

            return $this->result = [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($realDeclarationPath), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            $this->errors[] = ['owner_type' => 'template', 'owner_id' => $identifier, 'error' => 'invalid_declaration_json'];
            Log::warning('Template search provider declaration could not be decoded', ['template' => $identifier, 'message' => $exception->getMessage()]);

            return $this->result = [];
        }

        $declarations = $decoded['providers'] ?? null;
        if (! is_array($declarations) || count($declarations) > self::MAX_PROVIDERS) {
            $this->errors[] = ['owner_type' => 'template', 'owner_id' => $identifier, 'error' => 'invalid_provider_list'];

            return $this->result = [];
        }

        $providers = [];
        foreach ($declarations as $declaration) {
            if (! is_array($declaration) || ! $this->isValidDeclaration($declaration, $identifier)) {
                $this->errors[] = ['owner_type' => 'template', 'owner_id' => $identifier, 'error' => 'invalid_provider_declaration'];
                continue;
            }

            $providerId = (string) $declaration['provider_id'];
            $executionContract = $declaration['execution_contract'] ?? null;
            $available = false;
            $unavailableReason = $executionContract === null ? 'missing_execution_contract' : 'unsupported_execution_contract';
            if ($executionContract === 'static_layout_translation_v1') {
                try {
                    $contract = $declaration['static_search'] ?? null;
                    if (! is_array($contract)) {
                        throw new \InvalidArgumentException('Missing static search contract.');
                    }
                    $this->staticSearch->validateContract($contract);
                    // Every declared locale must be usable before public search.
                    foreach (array_keys($contract['translations']) as $locale) {
                        $this->staticSearch->catalog($templateRoot, $contract, $locale);
                    }
                    $available = true;
                    $unavailableReason = null;
                } catch (\Throwable $exception) {
                    $unavailableReason = 'invalid_static_search_contract';
                    $this->errors[] = ['owner_type' => 'template', 'owner_id' => $identifier, 'provider_id' => $providerId, 'error' => $unavailableReason];
                    Log::warning('Static template search declaration failed validation', ['template' => $identifier, 'provider_id' => $providerId, 'exception_class' => $exception::class]);
                }
            }
            $providers[] = [
                'provider_id' => $providerId,
                'owner_type' => 'template',
                'owner_id' => $identifier,
                'module_id' => null,
                'search_type' => (string) $declaration['search_type'],
                'label' => $declaration['label'],
                'description' => $declaration['description'] ?? ['ko' => '', 'en' => ''],
                'types' => [(string) $declaration['search_type']],
                'enabled_by_default' => (bool) $declaration['enabled_by_default'],
                'order' => (int) $declaration['default_order'],
                'discovery_source' => 'template_declaration',
                'execution_contract' => $executionContract,
                'available' => $available,
                'unavailable_reason' => $unavailableReason,
                'template_root' => $templateRoot,
                'static_search' => $available ? $declaration['static_search'] : null,
            ];
        }

        return $this->result = $providers;
    }

    public function diagnostics(): array
    {
        $this->discover();

        return $this->errors;
    }

    private function isValidDeclaration(array $declaration, string $identifier): bool
    {
        foreach (['provider_id', 'owner_type', 'owner_id', 'search_type', 'label', 'enabled_by_default', 'default_order'] as $key) {
            if (! array_key_exists($key, $declaration)) {
                return false;
            }
        }

        $executionContract = $declaration['execution_contract'] ?? null;

        return $declaration['owner_type'] === 'template'
            && $declaration['owner_id'] === $identifier
            && preg_match('/^[a-z][a-z0-9_-]{1,63}$/', (string) $declaration['provider_id']) === 1
            && preg_match('/^[a-z][a-z0-9_-]{1,63}$/', (string) $declaration['search_type']) === 1
            && is_array($declaration['label'])
            && is_bool($declaration['enabled_by_default'])
            && is_numeric($declaration['default_order'])
            && ($executionContract === null || is_string($executionContract));
    }

    private function isWithin(string $path, string $root): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root) || rtrim($path, DIRECTORY_SEPARATOR) === rtrim($root, DIRECTORY_SEPARATOR);
    }
}
