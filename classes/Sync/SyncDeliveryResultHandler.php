<?php

class SyncDeliveryResultHandler
{
    public static function successfulStatuses(): array
    {
        return ['processed', 'duplicate', 'stale', 'accepted_shadow'];
    }

    public static function isSuccessfulStatus(string $status): bool
    {
        return in_array($status, self::successfulStatuses(), true);
    }

    public static function outboxStatusForResult(array $result): string
    {
        $status = (string) ($result['status'] ?? '');
        if (self::isSuccessfulStatus($status)) {
            return 'synced';
        }

        if ($status === 'conflict') {
            return 'dead';
        }

        return 'failed';
    }
}
