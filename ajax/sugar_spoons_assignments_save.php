<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('ajax/sugar_spoons_assignments_save.php');

require_once __DIR__ . '/../classes/Pos/Service/PreparationSelectionService.php';
require_once __DIR__ . '/../classes/Sync/MenuItemSyncRecorder.php';
require_once __DIR__ . '/../classes/Sync/OperationalSyncRecorder.php';

header('Content-Type: application/json; charset=utf-8');

$categoryIds = is_array($_POST['category_ids'] ?? null) ? $_POST['category_ids'] : [];
$itemIds = is_array($_POST['item_ids'] ?? null) ? $_POST['item_ids'] : [];
$transactionStarted = false;

try {
    // Identity rotation migrates existing sync references and must finish
    // before the assignment transaction begins.
    posmain_preflight_operational_sync($conn);
    $conn->begin_transaction();
    $transactionStarted = true;
    $result = (new PreparationSelectionService())->replaceSugarAssignments(
        $conn,
        $categoryIds,
        $itemIds,
        current_user_id()
    );

    foreach ($result['category_config_ids'] as $configId) {
        posmain_record_operational_row_sync(
            $conn,
            'item_group_preparation_config',
            (int) $configId,
            'sugar_assignment_bulk'
        );
    }
    foreach ($result['item_config_ids'] as $configId) {
        posmain_record_operational_row_sync(
            $conn,
            'item_preparation_config',
            (int) $configId,
            'sugar_assignment_bulk'
        );
    }

    $affectedItemIds = array_map('intval', $result['changed_item_ids']);
    if ($result['changed_category_ids']) {
        $categoryList = implode(',', array_map('intval', $result['changed_category_ids']));
        $items = $conn->query(
            'SELECT id FROM myitems WHERE COALESCE(isdeleted, 0) = 0 AND group1 IN (' . $categoryList . ')'
        );
        while ($items && ($item = $items->fetch_assoc())) {
            $affectedItemIds[] = (int) $item['id'];
        }
    }
    $affectedItemIds = array_values(array_unique(array_filter($affectedItemIds)));
    foreach ($affectedItemIds as $itemId) {
        posmain_record_menu_item_sync($conn, $itemId, 'sugar_assignment_bulk');
    }

    $conn->commit();
    $transactionStarted = false;
    echo json_encode([
        'success' => true,
        'selected_categories' => count($result['selected_category_ids']),
        'selected_items' => count($result['selected_item_ids']),
        'affected_items' => count($affectedItemIds),
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $exception) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'تحتوي الاختيارات على صنف أو تصنيف غير صالح. حدّث الصفحة وحاول مرة أخرى.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    $errorReference = function_exists('posmain_error_reference')
        ? posmain_error_reference()
        : '';
    if ($errorReference !== '' && function_exists('posmain_log_exception')) {
        posmain_log_exception($exception, $errorReference, 'sugar_assignment_bulk_save');
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'تعذر حفظ إعداد السكر. لم يتم تطبيق أي تغيير.',
        'reference' => $errorReference,
    ], JSON_UNESCAPED_UNICODE);
}
