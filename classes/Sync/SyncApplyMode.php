<?php

class SyncApplyMode
{
    const RECEIVE_ONLY = 'receive_only';
    const SHADOW_APPLY = 'shadow_apply';
    const LIVE_APPLY = 'live_apply';

    public static function fromFlags(bool $cloudApplyEnabled, bool $shadowMode): string
    {
        if (!$cloudApplyEnabled) {
            return self::RECEIVE_ONLY;
        }

        return $shadowMode ? self::SHADOW_APPLY : self::LIVE_APPLY;
    }

    public static function acceptedResult(
        string $mode,
        string $eventUuid,
        string $idempotencyKey,
        ?string $cloudEntityId = null,
        string $message = ''
    ): array {
        $shadow = $mode === self::RECEIVE_ONLY || $mode === self::SHADOW_APPLY;

        return [
            'event_uuid' => $eventUuid,
            'idempotency_key' => $idempotencyKey,
            'status' => $shadow ? 'accepted_shadow' : 'processed',
            'stored' => true,
            'applied' => $mode !== self::RECEIVE_ONLY,
            'report_trusted' => $mode === self::LIVE_APPLY,
            'cloud_entity_id' => $cloudEntityId,
            'message' => $message,
        ];
    }

    public static function response(string $mode, array $results): array
    {
        return [
            'ok' => true,
            'mode' => $mode,
            'results' => $results,
        ];
    }
}
