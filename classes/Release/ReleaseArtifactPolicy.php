<?php

final class ReleaseArtifactPolicy
{
    private array $config;
    private array $pageManifest;
    private array $routeManifest;

    public function __construct(array $config, array $pageManifest, array $routeManifest)
    {
        $this->config = $config;
        $this->pageManifest = $pageManifest;
        $this->routeManifest = $routeManifest;
    }

    public static function fromRepository(string $root): self
    {
        $root = rtrim($root, '/\\');
        $config = require $root . '/config/release_artifact_policy.php';
        $pages = require $root . '/config/rbac_page_manifest.php';
        $routes = require $root . '/config/rbac_route_manifest.php';

        if (!is_array($config) || !is_array($pages) || !is_array($routes)) {
            throw new RuntimeException('Release policy and RBAC manifests must return arrays.');
        }

        return new self($config, $pages, $routes);
    }

    public function version(): int
    {
        return (int) ($this->config['version'] ?? 0);
    }

    /**
     * @param list<string> $trackedPaths
     * @return array{
     *   included:list<string>,
     *   excluded:array<string,string>,
     *   blockers:list<array{code:string,path:string,message:string}>
     * }
     */
    public function evaluate(array $trackedPaths): array
    {
        $tracked = array_fill_keys($trackedPaths, true);
        $included = [];
        $excluded = [];
        $blockers = [];

        foreach ($this->dependencyBlockers($tracked) as $blocker) {
            $blockers[] = $blocker;
        }

        foreach ($trackedPaths as $rawPath) {
            $path = $this->normalizePath($rawPath);
            if ($path === '') {
                $blockers[] = [
                    'code' => 'invalid_path',
                    'path' => (string) $rawPath,
                    'message' => 'Git tree contains an invalid release path.',
                ];
                continue;
            }

            $decision = $this->classify($path);
            if ($decision['blocker'] !== null) {
                $blockers[] = $decision['blocker'];
            }
            if ($decision['include']) {
                $included[] = $path;
            } else {
                $excluded[$path] = $decision['reason'];
            }
        }

        sort($included, SORT_STRING);
        ksort($excluded, SORT_STRING);
        usort($blockers, static function (array $left, array $right): int {
            return [$left['code'], $left['path']] <=> [$right['code'], $right['path']];
        });

        return [
            'included' => array_values(array_unique($included)),
            'excluded' => $excluded,
            'blockers' => $blockers,
        ];
    }

    /**
     * @return array{
     *   include:bool,
     *   reason:string,
     *   blocker:?array{code:string,path:string,message:string}
     * }
     */
    public function classify(string $path): array
    {
        $path = $this->normalizePath($path);
        if ($path === '') {
            return $this->blocked('invalid_path', $path, 'Invalid release path.');
        }

        foreach ((array) ($this->config['prohibited_prefixes'] ?? []) as $prefix) {
            if (str_starts_with($path, (string) $prefix)) {
                return $this->excluded('prohibited_prefix');
            }
        }

        foreach ((array) ($this->config['prohibited_basename_patterns'] ?? []) as $pattern) {
            if (@preg_match((string) $pattern, $path) === 1) {
                return $this->excluded('prohibited_name');
            }
        }

        if ($this->isEndpointPhp($path)) {
            return $this->classifyEndpoint($path);
        }

        if (!str_contains($path, '/') && str_ends_with(strtolower($path), '.php')) {
            if (in_array($path, (array) ($this->config['root_internal_files'] ?? []), true)) {
                return $this->excluded('root_internal');
            }
            return $this->classifyRootPhp($path);
        }

        if (in_array($path, (array) ($this->config['root_runtime_files'] ?? []), true)) {
            return $this->included('root_runtime');
        }
        if (in_array($path, (array) ($this->config['runtime_exact_files'] ?? []), true)) {
            return $this->included('runtime_exact');
        }
        foreach (array_merge(
            (array) ($this->config['runtime_prefixes'] ?? []),
            (array) ($this->config['runtime_library_prefixes'] ?? [])
        ) as $prefix) {
            if (str_starts_with($path, (string) $prefix)) {
                return $this->included('runtime_prefix');
            }
        }

        if (!str_contains($path, '/')) {
            return $this->excluded('unknown_root_file');
        }

        return $this->excluded('not_allowlisted');
    }

    private function classifyRootPhp(string $path): array
    {
        $entry = $this->pageManifest[$path] ?? null;
        if (!is_array($entry)) {
            return $this->blocked(
                'unclassified_entrypoint',
                $path,
                'Root PHP entry point is absent from the page manifest.'
            );
        }
        if (!empty($entry['quarantined'])) {
            return $this->excluded('quarantined_entrypoint');
        }

        return $this->included('classified_page');
    }

    private function classifyEndpoint(string $path): array
    {
        if (in_array($path, (array) ($this->config['endpoint_internal_files'] ?? []), true)) {
            return $this->included('endpoint_internal');
        }

        $entry = $this->routeManifest[$path] ?? null;
        if (!is_array($entry)) {
            return $this->blocked(
                'unclassified_entrypoint',
                $path,
                'PHP endpoint is absent from the route manifest or internal-file allowlist.'
            );
        }
        if (!empty($entry['quarantined'])) {
            return $this->excluded('quarantined_entrypoint');
        }

        return $this->included('classified_route');
    }

    private function isEndpointPhp(string $path): bool
    {
        if (!str_ends_with(strtolower($path), '.php')) {
            return false;
        }
        foreach ((array) ($this->config['endpoint_directories'] ?? []) as $directory) {
            if (str_starts_with($path, trim((string) $directory, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,bool> $tracked
     * @return list<array{code:string,path:string,message:string}>
     */
    private function dependencyBlockers(array $tracked): array
    {
        $blockers = [];
        foreach ((array) ($this->config['dependency_manifests'] ?? []) as $manifest => $lock) {
            $manifest = (string) $manifest;
            $lock = (string) $lock;
            if (isset($tracked[$manifest]) && !isset($tracked[$lock])) {
                $blockers[] = [
                    'code' => 'dependency_lock_missing',
                    'path' => $lock,
                    'message' => $manifest . ' is tracked but its required dependency lock is missing.',
                ];
            }
        }

        return $blockers;
    }

    private function normalizePath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        if (
            $path === ''
            || str_contains($path, "\0")
            || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1
        ) {
            return '';
        }

        return $path;
    }

    private function included(string $reason): array
    {
        return ['include' => true, 'reason' => $reason, 'blocker' => null];
    }

    private function excluded(string $reason): array
    {
        return ['include' => false, 'reason' => $reason, 'blocker' => null];
    }

    private function blocked(string $code, string $path, string $message): array
    {
        return [
            'include' => false,
            'reason' => $code,
            'blocker' => ['code' => $code, 'path' => $path, 'message' => $message],
        ];
    }
}
