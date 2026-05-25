<?php

require_once __DIR__ . '/db_bootstrap.php';

if (!function_exists('posmain_sync_header_value')) {
    function posmain_sync_header_value(array $headers, array $names): string
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = (string) $value;
        }

        foreach ($names as $name) {
            $name = strtolower((string) $name);
            if (array_key_exists($name, $normalized)) {
                return trim($normalized[$name]);
            }
        }

        return '';
    }
}

if (!function_exists('posmain_sync_branch_uuid_from_payload')) {
    function posmain_sync_branch_uuid_from_payload(array $headers, $payloadOrQuery): string
    {
        $branchUuid = posmain_sync_header_value($headers, ['x-posmain-branch-uuid', 'x-branch-uuid']);
        if ($branchUuid !== '') {
            return strtolower($branchUuid);
        }

        if (is_string($payloadOrQuery)) {
            $decoded = json_decode($payloadOrQuery, true);
            $payloadOrQuery = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($payloadOrQuery)) {
            return '';
        }

        return strtolower(trim((string) ($payloadOrQuery['branch_uuid'] ?? '')));
    }
}

if (!function_exists('posmain_sync_db_connect_for_payload')) {
    function posmain_sync_db_connect_for_payload(array $headers, $payloadOrQuery): mysqli
    {
        $config = posmain_app_config();
        if (!posmain_router_enabled($config)) {
            return posmain_db_connect();
        }

        $branchUuid = posmain_sync_branch_uuid_from_payload($headers, $payloadOrQuery);
        if ($branchUuid === '') {
            throw new InvalidArgumentException('branch_uuid_required');
        }

        try {
            return posmain_db_connect_for_branch_uuid($branchUuid, $config);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('unknown_branch_route');
        }
    }
}

if (!function_exists('posmain_sync_router_error')) {
    function posmain_sync_router_error(Throwable $e): void
    {
        $reason = $e->getMessage() === 'branch_uuid_required'
            ? 'branch_uuid_required'
            : 'unknown_branch_route';
        http_response_code($reason === 'branch_uuid_required' ? 400 : 404);
        echo json_encode(['ok' => false, 'reason' => $reason], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
