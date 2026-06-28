<?php

class SideEffectPolicy
{
    public const MODE_SHADOW = 'shadow';
    public const MODE_LIVE = 'live';

    public static function mode(): string
    {
        if (function_exists('production_guard_env_bool') && production_guard_env_bool('POSMAIN_PRODUCTION_MODE', false)) {
            return self::MODE_LIVE;
        }

        $configured = getenv('POSMAIN_SIDE_EFFECT_MODE');
        if (is_string($configured) && $configured !== '') {
            return strtolower(trim($configured)) === self::MODE_LIVE ? self::MODE_LIVE : self::MODE_SHADOW;
        }

        return self::MODE_SHADOW;
    }

    public static function inventoryBridgeShouldRollback(Throwable $exception, array $result = []): bool
    {
        if (self::mode() !== self::MODE_LIVE) {
            return false;
        }

        return !empty($result['errors']);
    }

    public static function orderEventShouldRollback(Throwable $exception): bool
    {
        return self::mode() === self::MODE_LIVE;
    }

    public static function inventoryBridgeDiagnostic(array $result, ?Throwable $exception = null): array
    {
        return [
            'mode' => self::mode(),
            'success' => !empty($result['success']),
            'errors' => array_values($result['errors'] ?? []),
            'exception' => $exception ? $exception->getMessage() : null,
        ];
    }

    public static function orderEventDiagnostic(?Throwable $exception = null): array
    {
        return [
            'mode' => self::mode(),
            'exception' => $exception ? $exception->getMessage() : null,
        ];
    }
}
