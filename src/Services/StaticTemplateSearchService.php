<?php

namespace Modules\Glitter\SearchHub\Services;

use InvalidArgumentException;

/** Reads only explicitly declared, visible translation keys from an active template. */
class StaticTemplateSearchService
{
    private const MAX_LAYOUT_BYTES = 1048576;
    private const MAX_TRANSLATION_BYTES = 262144;
    private const MAX_ITEMS = 128;

    public function catalog(string $templateRoot, array $contract, string $locale): array
    {
        $this->validateContract($contract);
        $layout = $this->readJson($templateRoot, $contract['layout'], self::MAX_LAYOUT_BYTES);
        $translations = $this->readJson($templateRoot, $contract['translations'][$locale], self::MAX_TRANSLATION_BYTES);
        $namespace = $contract['translation_namespace'];
        $items = [];
        $seen = [];

        foreach ($contract['sections'] as $section) {
            $sectionKey = $section['title_key'];
            $sectionNode = $this->findTextNode($layout, '$t:'.$namespace.'.'.$sectionKey);
            $category = $this->translated($translations, $sectionKey);
            if ($sectionNode === null || $category === null) {
                throw new InvalidArgumentException('Declared search section is not visible or translated.');
            }

            foreach ($section['items'] as $itemKey) {
                if (isset($seen[$itemKey])) {
                    throw new InvalidArgumentException('Duplicate static search item.');
                }
                $seen[$itemKey] = true;
                $found = $this->findTextNode($layout, '$t:'.$namespace.'.'.$itemKey);
                $title = $this->translated($translations, $itemKey);
                if ($found === null || $title === null) {
                    throw new InvalidArgumentException('Declared search item is not visible or translated.');
                }

                $container = $found['list_item'] ?? $found['node'];
                $descriptionKey = str_ends_with($itemKey, '.label')
                    ? substr($itemKey, 0, -strlen('.label')).'.description'
                    : null;
                $summary = '';
                if ($descriptionKey !== null && $this->containsText($container, '$t:'.$namespace.'.'.$descriptionKey)) {
                    $summary = $this->translated($translations, $descriptionKey)
                        ?? throw new InvalidArgumentException('Visible static search description is not translated.');
                }

                $links = $this->links($container, $translations, $namespace);
                $items[] = [
                    'id' => $itemKey,
                    'provider_key' => null,
                    'content_type' => 'static_template',
                    'title' => $title,
                    'summary' => $summary,
                    'content_preview' => $summary,
                    'category' => $category,
                    'url' => $links[0]['url'] ?? $contract['fallback_url'],
                    'links' => $links,
                ];
            }
        }

        return $items;
    }

    public function validateContract(array $contract): void
    {
        if (! isset($contract['layout'], $contract['fallback_url'], $contract['translation_namespace'], $contract['translations'], $contract['sections'])
            || ! is_string($contract['layout']) || ! is_string($contract['translation_namespace'])
            || ! is_string($contract['fallback_url']) || ! $this->safeUrl($contract['fallback_url'])
            || ! preg_match('/^[a-z][a-z0-9_]*$/', $contract['translation_namespace'])
            || ! is_array($contract['translations']) || $contract['translations'] === [] || count($contract['translations']) > 16
            || ! is_array($contract['sections'])
            || $contract['sections'] === [] || count($contract['sections']) > 32) {
            throw new InvalidArgumentException('Invalid static search contract.');
        }

        foreach ($contract['translations'] as $locale => $translationPath) {
            if (! is_string($locale) || preg_match('/^[a-z]{2}(?:-[A-Za-z0-9]+)*$/', $locale) !== 1
                || ! is_string($translationPath)) {
                throw new InvalidArgumentException('Invalid static search translation declaration.');
            }
        }

        $count = 0;
        foreach ($contract['sections'] as $section) {
            if (! is_array($section) || ! isset($section['title_key'], $section['items'])
                || ! $this->validKey($section['title_key']) || ! is_array($section['items']) || $section['items'] === []) {
                throw new InvalidArgumentException('Invalid static search section.');
            }
            foreach ($section['items'] as $key) {
                $sectionPrefix = substr($section['title_key'], 0, -strlen('.title')).'.';
                if (! $this->validKey($key) || ! str_ends_with($section['title_key'], '.title')
                    || ! str_starts_with($key, $sectionPrefix)) {
                    throw new InvalidArgumentException('Invalid static search item key.');
                }
                $count++;
            }
        }
        if ($count > self::MAX_ITEMS) {
            throw new InvalidArgumentException('Too many static search items.');
        }
    }

    private function validKey(mixed $key): bool
    {
        return is_string($key) && preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $key) === 1;
    }

    private function readJson(string $root, string $relative, int $maxBytes): array
    {
        if (! preg_match('#^(?:layouts|lang)/[a-zA-Z0-9_/-]+\.json$#', $relative)
            || str_contains($relative, '..') || str_contains($relative, '//')) {
            throw new InvalidArgumentException('Invalid static search file path.');
        }
        $base = realpath($root);
        $projectTemplates = realpath(base_path('templates'));
        $path = realpath($root.DIRECTORY_SEPARATOR.$relative);
        if ($base === false || $projectTemplates === false || $path === false
            || ! str_starts_with($base, $projectTemplates.DIRECTORY_SEPARATOR)
            || ! str_starts_with($path, $base.DIRECTORY_SEPARATOR)
            || ! is_file($path) || is_link($root.DIRECTORY_SEPARATOR.$relative)) {
            throw new InvalidArgumentException('Static search file is outside the active template.');
        }
        $size = filesize($path);
        if ($size === false || $size > $maxBytes) {
            throw new InvalidArgumentException('Static search file is too large.');
        }
        $decoded = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Static search file must be a JSON object.');
        }
        return $decoded;
    }

    private function translated(array $translations, string $key): ?string
    {
        $value = $translations;
        foreach (explode('.', $key) as $part) {
            if (! is_array($value) || ! array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @return array{node: array, list_item: ?array}|null */
    private function findTextNode(array $node, string $text, ?array $listItem = null): ?array
    {
        if (($node['name'] ?? null) === 'Li') {
            $listItem = $node;
        }
        if (($node['text'] ?? null) === $text && ! isset($node['if'])) {
            return ['node' => $node, 'list_item' => $listItem];
        }
        foreach ($node as $child) {
            if (! is_array($child)) {
                continue;
            }
            if (array_is_list($child)) {
                foreach ($child as $entry) {
                    if (is_array($entry) && ($found = $this->findTextNode($entry, $text, $listItem)) !== null) {
                        return $found;
                    }
                }
            } elseif (($found = $this->findTextNode($child, $text, $listItem)) !== null) {
                return $found;
            }
        }
        return null;
    }

    private function containsText(array $node, string $text): bool
    {
        return $this->findTextNode($node, $text) !== null;
    }

    private function links(array $node, array $translations, string $namespace): array
    {
        $links = [];
        if (($node['name'] ?? null) === 'A' && is_string($node['props']['href'] ?? null)) {
            $url = $node['props']['href'];
            if (! $this->safeUrl($url)) {
                throw new InvalidArgumentException('Unsafe static search result URL.');
            }
            $key = $node['text'] ?? '';
            $prefix = '$t:'.$namespace.'.';
            $label = is_string($key) && str_starts_with($key, $prefix)
                ? $this->translated($translations, substr($key, strlen($prefix))) : null;
            if ($label === null) {
                throw new InvalidArgumentException('Static search link label is not translated.');
            }
            $links[] = ['label' => $label, 'url' => $url];
        }
        foreach ($node as $child) {
            if (! is_array($child)) {
                continue;
            }
            foreach (array_is_list($child) ? $child : [$child] as $entry) {
                if (is_array($entry)) {
                    array_push($links, ...$this->links($entry, $translations, $namespace));
                }
            }
        }
        return $links;
    }

    private function safeUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return false;
        }
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && isset($parts['host']);
    }
}
