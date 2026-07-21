<?php

require_once __DIR__ . '/SyncOutboxEventService.php';
require_once __DIR__ . '/../MoovaPosIntegration.php';

function posmain_record_menu_item_sync(mysqli $conn, int $itemId, string $sourceSystem, string $eventType = 'menu.item_saved'): ?array
{
    if ($itemId <= 0) {
        return null;
    }

    try {
        $config = function_exists('posmain_app_config')
            ? posmain_app_config([
                'sync' => [
                    'outbox_enabled' => true,
                    'menu_sync_enabled' => true,
                ],
            ])
            : [
                'sync' => [
                    'outbox_enabled' => true,
                    'menu_sync_enabled' => true,
                ],
            ];

        $event = (new SyncOutboxEventService())->recordMenuItemSnapshot($conn, $itemId, [
            'event_type' => $eventType,
            'source_system' => $sourceSystem,
            'config' => $config,
        ]);
        MoovaPosIntegration::markAllCatalogLinksDirty($conn);
        return $event;
    } catch (Throwable $exception) {
        if (function_exists('posmain_log_exception') && function_exists('posmain_error_reference')) {
            posmain_log_exception($exception, posmain_error_reference(), 'menu_item_sync_outbox');
        } else {
            error_log('[POS Sync] Failed to record menu item snapshot: ' . $exception->getMessage());
        }
        return null;
    }
}
