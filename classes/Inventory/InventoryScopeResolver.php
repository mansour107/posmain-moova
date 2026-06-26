<?php

class InventoryScopeResolver
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
    }

    public function resolve(array $context = []): array
    {
        $branchConfig = is_array($this->config['branch'] ?? null) ? $this->config['branch'] : [];

        return [
            'pos_tenant' => $this->nonNegativeInt($context['pos_tenant'] ?? $context['tenant'] ?? $branchConfig['pos_tenant'] ?? 0),
            'pos_branch' => $this->nonNegativeInt($context['pos_branch'] ?? $context['branch'] ?? $branchConfig['pos_branch'] ?? 0),
            'branch_uuid' => $this->nullableString($context['branch_uuid'] ?? $branchConfig['uuid'] ?? null),
            'store_id' => $this->nonNegativeInt($context['store_id'] ?? $context['destination_store_id'] ?? $context['det_store'] ?? 0),
            'channel' => $this->normalizeToken((string) ($context['channel'] ?? 'pos'), 'pos'),
            'order_type' => $this->normalizeToken((string) ($context['order_type'] ?? 'takeaway'), 'takeaway'),
            'source' => $this->normalizeToken((string) ($context['source'] ?? $context['source_system'] ?? 'pos'), 'pos'),
        ];
    }

    /**
     * Resolve inventory scope and apply single-store operational enforcement at the service boundary.
     */
    public function resolveForConn(mysqli $conn, array $context = [], string $mode = 'write'): array
    {
        $scope = $this->resolve($context);
        if (!function_exists('posmain_resolve_store_scope_for_read')) {
            require_once __DIR__ . '/../../includes/pos_default_accounts.php';
        }

        if (!function_exists('posmain_single_store_mode_enabled') || !posmain_single_store_mode_enabled()) {
            if ((int) ($scope['store_id'] ?? 0) < 1 && function_exists('posmain_operational_store_id')) {
                $operational = posmain_operational_store_id($conn);
                if ($operational > 0) {
                    $scope['store_id'] = $operational;
                }
            }

            return $scope;
        }

        if ($mode === 'read') {
            return posmain_resolve_store_scope_for_read($conn, $scope);
        }

        return posmain_resolve_store_scope_for_write($conn, $scope);
    }

    private function nonNegativeInt($value): int
    {
        $int = (int) $value;

        return $int < 0 ? 0 : $int;
    }

    private function nullableString($value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function normalizeToken(string $value, string $default): string
    {
        $token = strtolower(trim($value));
        $token = str_replace(['-', ' '], '_', $token);

        return preg_match('/^[a-z0-9_]{1,40}$/', $token) === 1 ? $token : $default;
    }
}
