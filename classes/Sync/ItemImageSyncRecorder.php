<?php

require_once __DIR__ . '/ItemImageSyncQueueService.php';
require_once __DIR__ . '/OperationalSyncRecorder.php';
require_once __DIR__ . '/BranchIdentity.php';

function posmain_record_item_image_sync(mysqli $conn, int $imgsId, string $sourceSystem): ?array
{
    if ($imgsId <= 0) {
        return null;
    }

    try {
        $config = posmain_operational_sync_config();
        $config['sync']['image_sync_enabled'] = true;
        $stmt = $conn->prepare('
            SELECT id, iname, itemid, size, COALESCE(isdeleted, 0) AS isdeleted
            FROM imgs
            WHERE id = ?
            LIMIT 1
        ');
        $stmt->bind_param('i', $imgsId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || (int) ($row['itemid'] ?? 0) <= 0) {
            return null;
        }

        $identityClass = class_exists('SyncBranchIdentity') ? 'SyncBranchIdentity' : 'BranchIdentity';
        $identity = (new $identityClass())->ensure($conn, $config);
        $branchUuid = strtolower(trim((string) ($identity['branch_uuid'] ?? '')));
        if ($branchUuid !== '' && !empty($config['sync']['image_sync_enabled'])) {
            (new ItemImageSyncQueueService())->enqueueFromImgsRow($conn, $branchUuid, $row, 'branch_to_cloud');
        }

        if (!empty($row['isdeleted'])) {
            return posmain_record_operational_delete_sync($conn, 'item_image', $imgsId, $sourceSystem, 'item_image.deleted');
        }

        return posmain_record_operational_row_sync($conn, 'item_image', $imgsId, $sourceSystem, 'item_image.saved');
    } catch (Throwable $exception) {
        if (function_exists('posmain_log_exception') && function_exists('posmain_error_reference')) {
            posmain_log_exception($exception, posmain_error_reference(), 'item_image_sync');
        }

        return null;
    }
}

function posmain_queue_item_images_for_item(mysqli $conn, int $itemId, string $sourceSystem): void
{
    if ($itemId <= 0) {
        return;
    }

    $stmt = $conn->prepare('
        SELECT id
        FROM imgs
        WHERE itemid = ?
          AND COALESCE(clprofile, 0) = 0
          AND COALESCE(isdeleted, 0) = 0
        ORDER BY id ASC
    ');
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        posmain_record_item_image_sync($conn, (int) ($row['id'] ?? 0), $sourceSystem);
    }
    $stmt->close();
}
