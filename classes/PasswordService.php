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
}
