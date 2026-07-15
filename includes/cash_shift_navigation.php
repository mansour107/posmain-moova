<?php

require_once __DIR__ . '/../classes/Pos/Service/CashShiftWorkspaceService.php';

if (!function_exists('posmain_cash_shift_workspace_url')) {
    function posmain_cash_shift_workspace_url(array $params = []): string
    {
        $allowed = [
            'tab', 'date_from', 'date_to', 'cashier_id', 'override_operator_id',
            'movement_type', 'status', 'scope', 'page', 'has_override',
        ];
        $safe = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $params) || is_array($params[$key])) {
                continue;
            }
            $value = trim((string) $params[$key]);
            if ($value !== '' && $value !== '0') {
                $safe[$key] = $value;
            }
        }
        $query = http_build_query($safe);

        return 'cash_flow_report.php' . ($query !== '' ? '?' . $query : '');
    }
}

if (!function_exists('posmain_cash_shift_safe_return_to')) {
    function posmain_cash_shift_safe_return_to(?string $candidate, ?string $fallback = null): string
    {
        $fallback ??= posmain_cash_shift_workspace_url([
            'tab' => CashShiftWorkspaceService::TAB_SHIFTS,
        ]);
        $candidate = html_entity_decode(trim((string) $candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($candidate === '' || str_contains($candidate, "\n") || str_contains($candidate, "\r")) {
            return $fallback;
        }
        $parts = parse_url($candidate);
        if (!is_array($parts)
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['port'])) {
            return $fallback;
        }
        $path = ltrim((string) ($parts['path'] ?? ''), '/');
        if ($path !== 'cash_flow_report.php') {
            return $fallback;
        }
        parse_str((string) ($parts['query'] ?? ''), $query);

        return posmain_cash_shift_workspace_url(is_array($query) ? $query : []);
    }
}

if (!function_exists('posmain_cash_shift_detail_url')) {
    function posmain_cash_shift_detail_url(int $sessionId, string $returnTo): string
    {
        return 'drawer_session.php?' . http_build_query([
            'id' => $sessionId,
            'return_to' => posmain_cash_shift_safe_return_to($returnTo),
        ]);
    }
}
