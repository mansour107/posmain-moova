<?php

class PasswordService
{
    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public static function verifyPassword(string $plain, string $stored): bool
    {
        if (self::isLegacyMd5Hash($stored)) {
            if (self::denyLegacyPasswordAuth()) {
                return false;
            }

            return hash_equals(strtolower($stored), md5($plain));
        }

        return password_verify($plain, $stored);
    }

    public static function needsRehash(string $stored): bool
    {
        if (self::isLegacyMd5Hash($stored)) {
            return true;
        }

        return password_needs_rehash($stored, PASSWORD_DEFAULT);
    }

    public static function isLegacyMd5Hash(string $stored): bool
    {
        return preg_match('/^[a-f0-9]{32}$/i', $stored) === 1;
    }

    /**
     * Commercial V1: legacy MD5 password auth is denied in production by default.
     * Local/dev may keep temporary compatibility unless POSMAIN_DENY_LEGACY_PASSWORD_AUTH=1.
     */
    public static function denyLegacyPasswordAuth(): bool
    {
        if (!function_exists('production_guard_is_production')) {
            $guard = dirname(__DIR__) . '/includes/production_guard.php';
            if (is_file($guard)) {
                require_once $guard;
            }
        }
        if (function_exists('production_guard_is_production') && production_guard_is_production()) {
            return true;
        }

        $env = getenv('POSMAIN_DENY_LEGACY_PASSWORD_AUTH');
        if ($env === false || $env === '') {
            if (function_exists('posmain_env')) {
                $env = posmain_env('POSMAIN_DENY_LEGACY_PASSWORD_AUTH', null, true);
            }
        }
        if ($env !== null && $env !== false && $env !== '') {
            $normalized = strtolower(trim((string) $env));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return false;
    }
}
