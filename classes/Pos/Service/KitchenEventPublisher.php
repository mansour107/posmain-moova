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

        $payload = null;
        try {
            $payload = (new OrderPrintPayloadService())->buildKotPayloadByOrderId($conn, $orderId);
        } catch (Throwable $exception) {
            error_log('KitchenEventPublisher payload build skipped: ' . $exception->getMessage());
        }

        return [
            'event_type' => $eventType,
            'order_id' => $orderId,
            'metadata' => $metadata,
            'kot_payload' => $payload,
        ];
    }

    private function isEnabled(): bool
    {
        if (!function_exists('posmain_config')) {
            return false;
        }

        $config = posmain_config();
        $features = is_array($config['features'] ?? null) ? $config['features'] : [];

        return !empty($features['kds']);
    }
}
