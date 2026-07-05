<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doedit_item.php');

require_once __DIR__ . '/../classes/Items/ItemCatalogCode.php';
require_once __DIR__ . '/../classes/Items/ItemEditorFlash.php';
require_once __DIR__ . '/../classes/Items/ItemFormInput.php';
require_once __DIR__ . '/../classes/Items/ItemUnitPersistence.php';
require_once __DIR__ . '/../classes/Items/ItemRecipeCatalogService.php';
require_once __DIR__ . '/../classes/Pos/Service/ItemVariantService.php';
require_once __DIR__ . '/../classes/Sync/MenuItemSyncRecorder.php';
require_once __DIR__ . '/../classes/Sync/ItemImageSyncRecorder.php';

$item_id = (int) ($_GET['edit'] ?? 0);
$usid = (int) ($_SESSION['userid'] ?? 0);

// Ensure user is authenticated
if ($usid <= 0 || $item_id <= 0) {
    header('location:login.php');
    exit();
} 

// Barcode Handling
if (isset($_POST['barcode'])) {
    $barcode = $_POST['barcode'];
} else {
    // Get the last barcode from the database and generate a new one
    $last_barcode_result = $conn->query('SELECT barcode FROM myitems ORDER BY id DESC LIMIT 1');
    if ($last_barcode_result && $last_barcode_result->num_rows > 0) {
        $last_barcode = $last_barcode_result->fetch_assoc()['barcode'];
        $barcode = $last_barcode + 1;
    } else {
        $barcode = 1000001; // Starting point if no barcodes exist
    }
}

// Item Name Validation (Check for duplicate names)
$iname = trim((string) ($_POST['iname'] ?? ''));
if ($iname === '') {
    ItemEditorFlash::set('danger', 'save_failed');
    header('Location: ../add_item.php?edit=' . $item_id);
    exit;
}
$sqlchkname  = "SELECT * FROM myitems WHERE iname = ? AND id != ?";
$stmt = $conn->prepare($sqlchkname);
$stmt->bind_param('si', $iname, $item_id);
$stmt->execute();
$chkname = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($chkname !== null) {
    ItemEditorFlash::set('danger', 'duplicate_name');
    header('Location: ../add_item.php?edit=' . (int) $item_id);
    exit;
}

// Prepare to update the main item
$defaultUnitId = ItemFormInput::resolveDefaultUnitId($conn);
try {
    $payload = ItemFormInput::normalizeAddPayload($_POST, $usid, $defaultUnitId);
} catch (InvalidArgumentException $exception) {
    ItemEditorFlash::set('danger', 'save_failed');
    header('Location: ../add_item.php?edit=' . $item_id);
    exit;
}

$existingCodeStmt = $conn->prepare('SELECT code FROM myitems WHERE id = ? LIMIT 1');
$existingCodeStmt->bind_param('i', $item_id);
$existingCodeStmt->execute();
$existingCodeRow = $existingCodeStmt->get_result()->fetch_assoc();
$existingCodeStmt->close();
$code = ItemCatalogCode::resolveForInsert($conn, (int) ($existingCodeRow['code'] ?? 0) ?: null);
$name2 = $payload['name2'];
$group1 = $payload['group1'];
$group2 = $payload['group2'];
$info = $payload['info'];
$cost_price = $payload['cost_price'];
$price1 = $payload['price1'];
$price2 = $payload['price2'];
$price3 = $payload['price3'];
$marketPrice = $payload['market_price'];
$barcode = $payload['barcode'] !== '' ? $payload['barcode'] : (string) ($_POST['barcode'] ?? '');




// Handle image upload
$new_kvr_name = null;
$img_size = 0;
if (isset($_FILES['imgs']) && !empty($_FILES['imgs']['name'][0])) {
    $imgs_name = $_FILES['imgs']['name'][0];
    $tmp_name = $_FILES['imgs']['tmp_name'][0];
    $img_size = (int) ($_FILES['imgs']['size'][0] ?? 0);

    $kvr_ext = strtolower(pathinfo((string) $imgs_name, PATHINFO_EXTENSION));

    $allow_ext = ['jpg', 'png', 'gif', 'jpeg', 'webp'];
    if (in_array($kvr_ext, $allow_ext, true)) {
        $baseName = pathinfo((string) $imgs_name, PATHINFO_FILENAME);
        $new_kvr_name = $baseName . rand(1, 1000000) . '.' . $kvr_ext;
        if (!move_uploaded_file($tmp_name, '../uploads/' . $new_kvr_name)) {
            $new_kvr_name = null;
            $img_size = 0;
        }
    } else {
        ItemEditorFlash::set('danger', 'invalid_image');
        header('Location: ../add_item.php?edit=' . $item_id);
        exit;
    }
}

// إضافة العمود إذا لم يكن موجوداً
$checkColumn = $conn->query("SHOW COLUMNS FROM myitems LIKE 'manual_price_edit'");
if ($checkColumn->num_rows == 0) {
    $conn->query("ALTER TABLE myitems ADD COLUMN manual_price_edit TINYINT(1) DEFAULT 0");
}

try {
    $variantService = new ItemVariantService();
    $variantService->ensureSchema($conn);
    $conn->begin_transaction();

    if ($new_kvr_name !== null) {
        $deleteImage = $conn->prepare('DELETE FROM imgs WHERE itemid = ?');
        $deleteImage->bind_param('i', $item_id);
        $deleteImage->execute();
        $deleteImage->close();

        $insertImage = $conn->prepare('INSERT INTO imgs (iname, itemid, size) VALUES (?, ?, ?)');
        $insertImage->bind_param('sii', $new_kvr_name, $item_id, $img_size);
        $insertImage->execute();
        $insertImage->close();
    }

    // تحديث الجدول الرئيسي
    $stmt = $conn->prepare("
        UPDATE myitems
        SET iname = ?,
            name2 = ?,
            code = ?,
            barcode = ?,
            info = ?,
            cost_price = ?,
            market_price = ?,
            group1 = ?,
            group2 = ?,
            price1 = ?,
            price2 = ?,
            price3 = ?,
            manual_price_edit = 1
        WHERE id = ?
    ");
    $stmt->bind_param(
        'ssissdiiidddi',
        $iname,
        $name2,
        $code,
        $barcode,
        $info,
        $cost_price,
        $marketPrice,
        $group1,
        $group2,
        $price1,
        $price2,
        $price3,
        $item_id
    );
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to update item');
    }
    $stmt->close();
    (new ItemRecipeCatalogService())->saveMetadata($conn, $item_id, $payload);

    ItemUnitPersistence::saveForItem($conn, $item_id, $payload['units'], (int) ($payload['purchase_unit_id'] ?? 0));

    $changedItemIds = [$item_id];
    if (array_key_exists('item_variants_payload_present', $_POST)) {
        $changedItemIds = $variantService->saveVariantsFromPost($conn, $item_id, $_POST, ['user_id' => $usid]);
    }
    foreach ($changedItemIds as $changedItemId) {
        posmain_record_menu_item_sync($conn, (int) $changedItemId, 'item_form');
        posmain_queue_item_images_for_item($conn, (int) $changedItemId, 'item_form');
    }
    $conn->commit();
} catch (InvalidArgumentException $exception) {
    if ($conn instanceof mysqli) {
        $conn->rollback();
    }
    $error = 'save_failed';
    if ($exception->getMessage() === 'duplicate_item_name') {
        $error = 'duplicate_name';
    } elseif ($exception->getMessage() === 'duplicate_item_barcode') {
        $error = 'duplicate_barcode';
    }
    ItemEditorFlash::set('danger', $error);
    header('Location: ../add_item.php?edit=' . (int) $item_id);
    exit;
} catch (Throwable $exception) {
    if ($conn instanceof mysqli) {
        $conn->rollback();
    }
    if (function_exists('posmain_log_exception') && function_exists('posmain_error_reference')) {
        posmain_log_exception($exception, posmain_error_reference(), 'edit_item_save');
    }
    ItemEditorFlash::set('danger', 'save_failed');
    header('Location: ../add_item.php?edit=' . (int) $item_id);
    exit;
}

ItemEditorFlash::set('success', 'saved');
header('Location: ../add_item.php?edit=' . (int) $item_id);
exit;

function posmain_edit_item_needs_default_unit(array $post): bool
{
    $unitIds = isset($post['unit_id']) && is_array($post['unit_id']) ? $post['unit_id'] : [];
    if (!$unitIds) {
        return true;
    }

    foreach ($unitIds as $unitId) {
        if ((int) $unitId > 0) {
            return false;
        }
    }

    return true;
}

function posmain_edit_item_default_unit_id(mysqli $conn): int
{
    $row = $conn->query("SELECT id FROM myunits WHERE COALESCE(isdeleted, 0) = 0 ORDER BY id LIMIT 1")->fetch_assoc();
    if ($row !== null) {
        return (int) $row['id'];
    }

    $unitName = 'قطعة';
    $stmt = $conn->prepare('INSERT INTO myunits (uname) VALUES (?)');
    $stmt->bind_param('s', $unitName);
    $stmt->execute();
    $unitId = (int) $conn->insert_id;
    $stmt->close();

    return $unitId;
}

function posmain_edit_item_save_units(mysqli $conn, int $itemId, array $units): void
{
    if ($itemId < 1 || !$units) {
        throw new InvalidArgumentException('missing item units');
    }

    $submittedUnitIds = [];
    $updateStmt = $conn->prepare("
        UPDATE item_units
        SET cost_price = ?,
            price1 = ?,
            price2 = ?,
            price3 = ?,
            u_val = ?,
            unit_barcode = ?
        WHERE item_id = ? AND unit_id = ?
    ");
    $insertStmt = $conn->prepare("
        INSERT INTO item_units(item_id, unit_id, u_val, unit_barcode, cost_price, price1, price2, price3)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($units as $unit) {
        $unitId = (int) ($unit['unit_id'] ?? 0);
        if ($unitId < 1) {
            continue;
        }
        $submittedUnitIds[] = $unitId;

        $uVal = (float) ($unit['u_val'] ?? 1);
        $unitBarcode = (string) ($unit['unit_barcode'] ?? '');
        $costPrice = (float) ($unit['cost_price'] ?? 0);
        $price1 = (float) ($unit['price1'] ?? 0);
        $price2 = (float) ($unit['price2'] ?? 0);
        $price3 = (float) ($unit['price3'] ?? 0);

        $updateStmt->bind_param('dddddsii', $costPrice, $price1, $price2, $price3, $uVal, $unitBarcode, $itemId, $unitId);
        $updateStmt->execute();
        if ($updateStmt->affected_rows > 0 || posmain_edit_item_unit_exists($conn, $itemId, $unitId)) {
            continue;
        }

        $insertStmt->bind_param('iidsdddd', $itemId, $unitId, $uVal, $unitBarcode, $costPrice, $price1, $price2, $price3);
        $insertStmt->execute();
    }

    $updateStmt->close();
    $insertStmt->close();

    $submittedUnitIds = array_values(array_unique(array_filter($submittedUnitIds)));
    if (!$submittedUnitIds) {
        throw new InvalidArgumentException('missing item units');
    }

    $placeholders = implode(',', array_fill(0, count($submittedUnitIds), '?'));
    $types = 'i' . str_repeat('i', count($submittedUnitIds));
    $params = array_merge([$itemId], $submittedUnitIds);
    $deleteStmt = $conn->prepare("DELETE FROM item_units WHERE item_id = ? AND unit_id NOT IN ({$placeholders})");
    posmain_edit_item_bind_params($deleteStmt, $types, $params);
    $deleteStmt->execute();
    $deleteStmt->close();
}

function posmain_edit_item_unit_exists(mysqli $conn, int $itemId, int $unitId): bool
{
    $stmt = $conn->prepare('SELECT id FROM item_units WHERE item_id = ? AND unit_id = ? LIMIT 1');
    $stmt->bind_param('ii', $itemId, $unitId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($row);
}

function posmain_edit_item_bind_params(mysqli_stmt $stmt, string $types, array $params): void
{
    $refs = [];
    foreach ($params as $index => $value) {
        $refs[$index] = $value;
    }

    $bind = [$types];
    foreach ($refs as $index => $_) {
        $bind[] = &$refs[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}
