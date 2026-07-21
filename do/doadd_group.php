<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doadd_group.php');

$gname = trim((string) ($_POST['gname'] ?? ''));
$returnTo = 'mygroups.php';

if ($gname === '') {
    header('location:../' . $returnTo);
    exit;
}

$stmt = $conn->prepare("SELECT id FROM item_group WHERE gname = ? AND isdeleted = 0 LIMIT 1");
$stmt->bind_param("s", $gname);
$stmt->execute();
$exists = $stmt->get_result()->num_rows > 0;
$stmt->close();

if ($exists) {
    header('location:../' . $returnTo . '?error=duplicate');
    exit;
}

$stmt = $conn->prepare("INSERT INTO item_group (gname) VALUES (?)");
$stmt->bind_param("s", $gname);
$stmt->execute();
$groupId = (int) $stmt->insert_id;
$stmt->close();

$preparationConfigId = 0;
if ($groupId > 0 && function_exists('posmain_app_config') && !empty(posmain_app_config()['features']['preparation_fields'])) {
    require_once __DIR__ . '/../classes/Pos/Service/PreparationSelectionService.php';
    $preparationConfigId = (new PreparationSelectionService())->setCategorySugarAllowed(
        $conn,
        $groupId,
        !empty($_POST['sugar_spoons_enabled']),
        current_user_id()
    );
}

require_once __DIR__ . '/../classes/Sync/OperationalSyncRecorder.php';
if ($groupId > 0) {
    posmain_record_operational_row_sync($conn, 'item_category', $groupId, 'item_group_form');
}
if ($preparationConfigId > 0) {
    posmain_record_operational_row_sync($conn, 'item_group_preparation_config', $preparationConfigId, 'item_group_form');
}

$conn->query("INSERT INTO `process`(`type`) VALUES ('add group')");

header('location:../' . $returnTo);
