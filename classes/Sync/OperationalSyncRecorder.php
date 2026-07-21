<?php

require_once __DIR__ . '/OperationalSyncEventService.php';
require_once __DIR__ . '/../MoovaPosIntegration.php';

function posmain_operational_sync_config(): array
{
    if (function_exists('posmain_app_config')) {
        return posmain_app_config([
            'sync' => [
                'outbox_enabled' => true,
                'branch_sync_enabled' => true,
                'operational_sync_enabled' => true,
            ],
        ]);
    }

    return [
        'role' => 'branch',
        'sync' => [
            'outbox_enabled' => true,
            'branch_sync_enabled' => true,
            'operational_sync_enabled' => true,
        ],
    ];
}

function posmain_record_operational_row_sync(
    mysqli $conn,
    string $domain,
    int $rowId,
    string $sourceSystem,
    string $eventType = ''
): ?array {
    if ($rowId <= 0) {
        return null;
    }

    try {
        $options = [
            'source_system' => $sourceSystem,
            'config' => posmain_operational_sync_config(),
        ];
        if ($eventType !== '') {
            $options['event_type'] = $eventType;
        }

        $event = (new OperationalSyncEventService())->recordRowSnapshot($conn, $domain, $rowId, $options);
        if (in_array($domain, ['item_group', 'tables', 'modifier_groups', 'modifier_options', 'item_modifier_groups', 'item_variants'], true)) {
            MoovaPosIntegration::markAllCatalogLinksDirty($conn);
        }
        return $event;
    } catch (Throwable $exception) {
        if (function_exists('posmain_log_exception') && function_exists('posmain_error_reference')) {
            posmain_log_exception($exception, posmain_error_reference(), 'operational_sync_outbox');
        } else {
            error_log('[POS Sync] Failed to record operational row snapshot: ' . $exception->getMessage());
        }

        return null;
    }
}

function posmain_record_operational_delete_sync(
    mysqli $conn,
    string $domain,
    int $rowId,
    string $sourceSystem,
    string $eventType = ''
): ?array {
    if ($rowId <= 0) {
        return null;
    }

    try {
        $options = [
            'source_system' => $sourceSystem,
            'config' => posmain_operational_sync_config(),
        ];
        if ($eventType !== '') {
            $options['event_type'] = $eventType;
        }

        $event = (new OperationalSyncEventService())->recordRowDelete($conn, $domain, $rowId, $options);
        if (in_array($domain, ['item_group', 'tables', 'modifier_groups', 'modifier_options', 'item_modifier_groups', 'item_variants'], true)) {
            MoovaPosIntegration::markAllCatalogLinksDirty($conn);
        }
        return $event;
    } catch (Throwable $exception) {
        if (function_exists('posmain_log_exception') && function_exists('posmain_error_reference')) {
            posmain_log_exception($exception, posmain_error_reference(), 'operational_sync_delete');
        } else {
            error_log('[POS Sync] Failed to record operational delete snapshot: ' . $exception->getMessage());
        }

        return null;
    }
}

function posmain_record_recipe_sync(
    mysqli $conn,
    int $recipeId,
    string $sourceSystem,
    string $eventType = 'recipe.saved'
): ?array {
    if ($recipeId <= 0) {
        return null;
    }

    try {
        return (new OperationalSyncEventService())->recordRecipeSnapshot($conn, $recipeId, [
            'event_type' => $eventType,
            'source_system' => $sourceSystem,
            'config' => posmain_operational_sync_config(),
        ]);
    } catch (Throwable $exception) {
        if (function_exists('posmain_log_exception') && function_exists('posmain_error_reference')) {
            posmain_log_exception($exception, posmain_error_reference(), 'recipe_sync_outbox');
        } else {
            error_log('[POS Sync] Failed to record recipe snapshot: ' . $exception->getMessage());
        }

        return null;
    }
}
