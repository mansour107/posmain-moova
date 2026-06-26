<?php

require_once __DIR__ . '/DTO/RecipeScope.php';

class RecipeScopeResolver
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
    }

    public function resolve(array $context = []): RecipeScope
    {
        $branchConfig = is_array($this->config['branch'] ?? null) ? $this->config['branch'] : [];

        return new RecipeScope(
            $this->nonNegativeInt($context['pos_tenant'] ?? $context['tenant'] ?? $branchConfig['pos_tenant'] ?? 0),
            $this->nonNegativeInt($context['pos_branch'] ?? $context['branch'] ?? $branchConfig['pos_branch'] ?? 0),
            $this->nullableString($context['branch_uuid'] ?? $branchConfig['uuid'] ?? null),
            $this->nonNegativeInt($context['store_id'] ?? $context['det_store'] ?? 0),
            (string) ($context['channel'] ?? 'pos'),
            (string) ($context['order_type'] ?? 'takeaway'),
            (string) ($context['source'] ?? $context['source_system'] ?? 'pos')
        );
    }

    /**
     * Resolve recipe scope and apply single-store operational enforcement at the service boundary.
     */
    public function resolveForConn(mysqli $conn, array $context = [], string $mode = 'write'): RecipeScope
    {
        $scope = $this->resolve($context);
        if (!function_exists('posmain_resolve_store_scope_for_read')) {
            require_once __DIR__ . '/../../includes/pos_operational_store.php';
        }

        if (!function_exists('posmain_single_store_mode_enabled') || !posmain_single_store_mode_enabled()) {
            if ($scope->storeId < 1 && function_exists('posmain_operational_store_id')) {
                $operational = posmain_operational_store_id($conn);
                if ($operational > 0) {
                    $scope->storeId = $operational;
                }
            }

            return $scope;
        }

        $scopeContext = [
            'pos_tenant' => $scope->posTenant,
            'pos_branch' => $scope->posBranch,
            'branch_uuid' => $scope->branchUuid,
            'store_id' => $scope->storeId,
            'channel' => $scope->channel,
            'order_type' => $scope->orderType,
            'source' => $scope->source,
        ];

        if ($mode === 'read') {
            $scoped = posmain_resolve_store_scope_for_read($conn, $scopeContext);
        } else {
            $scoped = posmain_resolve_store_scope_for_write($conn, $scopeContext);
        }

        return new RecipeScope(
            (int) ($scoped['pos_tenant'] ?? $scope->posTenant),
            (int) ($scoped['pos_branch'] ?? $scope->posBranch),
            isset($scoped['branch_uuid']) ? (string) $scoped['branch_uuid'] : $scope->branchUuid,
            (int) ($scoped['store_id'] ?? $scope->storeId),
            (string) ($scoped['channel'] ?? $scope->channel),
            (string) ($scoped['order_type'] ?? $scope->orderType),
            (string) ($scoped['source'] ?? $scope->source)
        );
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
}

