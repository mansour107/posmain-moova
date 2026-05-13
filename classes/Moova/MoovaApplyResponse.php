<?php

class MoovaApplyResponse
{
    public static function eventTypeForAction(string $action): string
    {
        return strtolower(trim($action)) === 'cancel' ? 'cancel_order' : 'edit_order';
    }

    public static function directWidget(array $response, string $eventType, ?string $syncStatus = null): array
    {
        return self::withMetadata($response, 'widget', 'direct_widget', $eventType, $syncStatus);
    }

    public static function directWidgetChange(array $response, string $action, ?string $syncStatus = null): array
    {
        return self::directWidget($response, self::eventTypeForAction($action), $syncStatus);
    }

    public static function queuedWorker(array $response, string $eventType, ?string $syncStatus = null): array
    {
        return self::withMetadata($response, 'poller', 'queued_worker', $eventType, $syncStatus);
    }

    public static function queuedWorkerChange(array $response, string $action, ?string $syncStatus = null): array
    {
        return self::queuedWorker($response, self::eventTypeForAction($action), $syncStatus);
    }

    public static function withMetadata(
        array $response,
        string $deliveryPath,
        string $applyPath,
        string $eventType,
        ?string $syncStatus = null
    ): array {
        if ($syncStatus === null || $syncStatus === '') {
            $syncStatus = self::statusFromResponse($response);
        }

        return array_merge($response, [
            'deliveryPath' => $deliveryPath,
            'applyPath' => $applyPath,
            'syncEventType' => $eventType,
            'syncStatus' => $syncStatus,
        ]);
    }

    public static function declineMessage(string $code): string
    {
        $messages = [
            'POS_ORDER_LINK_NOT_FOUND' => 'Order is not linked to the connected POS.',
            'POS_PROVIDER_ORDER_MISMATCH' => 'POS order identifier does not match the linked order.',
            'POS_STATE_UNKNOWN' => 'POS order state was not captured when the order was accepted.',
            'POS_ORDER_NOT_FOUND' => 'POS order was not found.',
            'POS_ORDER_DELETED' => 'POS order is already deleted.',
            'POS_ORDER_NOT_TABLE' => 'POS order is not an active table order.',
            'POS_ORDER_PAID' => 'POS order is already paid.',
            'POS_ORDER_NOT_ACTIVE' => 'POS order is no longer active.',
            'POS_ORDER_CHANGED' => 'POS order changed in the POS after the last Moova sync.',
            'POS_ORDER_LINES_CHANGED' => 'This Moova order lines changed in the POS after the last Moova sync.',
            'POS_ORDER_LINES_UNMAPPED' => 'This Moova order does not have POS line ownership mapping.',
            'ITEM_NOT_FOUND' => 'One or more edited order items are not available in the POS.',
            'NO_VALID_ITEMS' => 'Edited order has no valid POS items.',
            'TABLE_NOT_FOUND' => 'POS table was not found.',
            'IDEMPOTENCY_PAYLOAD_CONFLICT' => 'Idempotency key was reused with a different payload.',
        ];

        return $messages[$code] ?? 'POS declined the order change.';
    }

    private static function statusFromResponse(array $response): string
    {
        $providerStatus = strtolower(trim((string) ($response['providerStatus'] ?? $response['provider_status'] ?? '')));
        if ($providerStatus === 'declined') {
            return 'declined';
        }
        if ($providerStatus === 'failed') {
            return 'failed';
        }

        if (array_key_exists('applied', $response)) {
            return !empty($response['applied']) ? 'applied' : 'declined';
        }

        return 'applied';
    }
}
