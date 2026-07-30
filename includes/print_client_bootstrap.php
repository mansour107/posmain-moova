<?php

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/csrf.php';

if (!function_exists('posmain_render_print_client_bootstrap')) {
    function posmain_render_print_client_bootstrap(string $assetPrefix = ''): string
    {
        $config = posmain_app_config();
        $mode = strtolower((string) ($config['printing']['mode'] ?? 'legacy'));
        if (!in_array($mode, ['legacy', 'silent'], true)) {
            $mode = 'legacy';
        }
        $prefix = rtrim($assetPrefix, '/');
        $endpoint = ($prefix !== '' ? $prefix . '/' : '') . 'ajax/print_dispatch.php';
        $script = ($prefix !== '' ? $prefix . '/' : '') . 'js/posmain_print.js';
        $clientConfig = [
            'mode' => $mode,
            'endpoint' => $endpoint,
            'csrfToken' => csrf_token('print_dispatch'),
        ];
        $json = json_encode(
            $clientConfig,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        );

        return '<script>window.POSMAIN_PRINT_CONFIG=' . $json . ';</script>'
            . '<script src="' . htmlspecialchars($script, ENT_QUOTES, 'UTF-8') . '"></script>';
    }
}
