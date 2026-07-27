<?php

require_once __DIR__ . '/../../../config/app_config.php';
require_once __DIR__ . '/OrderPrintPayloadService.php';

class KitchenEventPublisher
{
    public function publishForOrder(mysqli $conn, int $orderId, string $eventType, array $metadata = []): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        if ($orderId < 1) {
            return null;
        }

        $payload = (new OrderPrintPayloadService())->buildKotPayloadByOrderId($conn, $orderId);

        return [
            'event_type' => $eventType,
            'order_id' => $orderId,
            'metadata' => $metadata,
            'kot_payload' => $payload,
        ];
    }

    public function publishRevision(mysqli $conn, int $orderId, array $revisionEnvelope, array $metadata = []): ?array
    {
        if ($orderId < 1) {
            return null;
        }

        $revision = (int) ($revisionEnvelope['revision'] ?? 0);
        $event = [
            'event_type' => 'kitchen.ticket.revised',
            'order_id' => $orderId,
            'revision' => $revision,
            'supersedes_revision' => $revisionEnvelope['supersedes_revision'] ?? null,
            'is_current' => (bool) ($revisionEnvelope['is_current'] ?? true),
            'metadata' => $metadata,
            'kot_payload' => $revisionEnvelope['kot_payload'] ?? null,
        ];

        if (!$this->isEnabled()) {
            return $event;
        }

        return $event;
    }

    private function isEnabled(): bool
    {
        // KDS is a first-class, always-on production capability. It is
        // configured through the admin KDS settings (stations) rather than
        // a system feature flag, so the legacy gate is intentionally open.
        return true;
    }
}
