<?php

require_once __DIR__ . '/../../../config/app_config.php';
require_once __DIR__ . '/../../../includes/production_guard.php';

class PosIntegrationAuth
{
    public static function requireCofeSignature(array $payload, array $server = [], ?mysqli $conn = null): void
    {
        $secret = self::resolveCofeSecret($conn);
        if ($secret === '') {
            if (self::shouldFailClosedWithoutSecret()) {
                throw new RuntimeException('INTEGRATION_DISABLED');
            }

            return;
        }

        $provided = trim((string) ($server['HTTP_X_POSMAIN_INTEGRATION_SIGNATURE'] ?? $server['HTTP_X_COFE_SIGNATURE'] ?? ''));
        if ($provided === '') {
            throw new RuntimeException('INTEGRATION_SIGNATURE_REQUIRED');
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $expected = hash_hmac('sha256', (string) $body, $secret);
        if (!hash_equals($expected, $provided)) {
            throw new RuntimeException('INTEGRATION_SIGNATURE_INVALID');
        }
    }

    public static function shouldFailClosedWithoutSecret(): bool
    {
        if (production_guard_is_production()) {
            return true;
        }

        return !production_guard_env_bool('POSMAIN_ALLOW_OPEN_INTEGRATIONS', false);
    }

    private static function resolveCofeSecret(?mysqli $conn): string
    {
        if ($conn instanceof mysqli) {
            require_once __DIR__ . '/../../../includes/pos_default_accounts.php';
        }
        if ($conn instanceof mysqli && function_exists('posmain_load_pos_settings_row')) {
            $settings = posmain_load_pos_settings_row($conn);
            $fromSettings = trim((string) ($settings['cofe_integration_secret'] ?? ''));
            if ($fromSettings !== '') {
                return $fromSettings;
            }
        }

        return '';
    }
}
