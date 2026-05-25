<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
require_once __DIR__ . '/../classes/Items/ItemFormInput.php';
require_once __DIR__ . '/../classes/Items/ItemRecipeCatalogService.php';
require_once __DIR__ . '/../classes/Pos/Service/ItemVariantService.php';
require_once __DIR__ . '/../classes/Sync/MenuItemSyncRecorder.php';

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
    header('Location: ../add_item.php?edit=' . $item_id . '&error=save_failed');
    exit;
}
$sqlchkname  = "SELECT * FROM myitems WHERE iname = ? AND id != ?";
$stmt = $conn->prepare($sqlchkname);
$stmt->bind_param('si', $iname, $item_id);
$stmt->execute();
$chkname = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($chkname !== null) {
    header('Location: ../add_item.php?edit=' . (int) $item_id . '&error=duplicate_name');
    exit;
}

// Prepare to update the main item
$defaultUnitId = posmain_edit_item_needs_default_unit($_POST) ? posmain_edit_item_default_unit_id($conn) : 0;
try {
    $payload = ItemFormInput::normalizeAddPayload($_POST, $usid, $defaultUnitId);
} catch (InvalidArgumentException $exception) {
    header('Location: ../add_item.php?edit=' . $item_id . '&error=save_failed');
    exit;
}

$code = $payload['code'];
$name2 = $payload['name2'];
$group1 = $payload['group1'];
$group2 = $payload['group2'];
$info = $payload['info'];
$cost_price = $payload['cost_price'];
$price1 = $payload['price1'];
$price2 = $payload['price2'];




// Handle image upload
$new_kvr_name = null;
if (isset($_FILES['imgs']) && !empty($_FILES['imgs']['name'][0])) {
    $imgs_name = $_FILES['imgs']['name'][0];
    $tmp_name = $_FILES['imgs']['tmp_name'][0];
    
    $arrkvr = explode(".", $imgs_name);
    $kvr_ext = strtolower((string) end($arrkvr));
    
    $allow_ext = ["jpg", "png", "gif", "jpeg", "webp"];
    if (in_array($kvr_ext, $allow_ext, true)) {
        $new_kvr_name = $arrkvr[0] . rand(1, 1000000) . "." . $kvr_ext;
        if (!move_uploaded_file($tmp_name, "../uploads/$new_kvr_name")) {
            $new_kvr_name = null;
        }
    } else {
        header('Location: ../add_item.php?edit=' . $item_id . '&error=invalid_image');
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

        $insertImage = $conn->prepare('INSERT INTO imgs (iname, itemid) VALUES (?, ?)');
        $insertImage->bind_param('si', $new_kvr_name, $item_id);
        $insertImage->execute();
        $insertImage->close();
    }

    // تحديث الجدول الرئيسي
    $stmt = $conn->prepare("
        UPDATE myitems
        SET iname = ?,
            name2 = ?,
            code = ?,
            info = ?,
            cost_price = ?,
            group1 = ?,
            group2 = ?,
            price1 = ?,
            price2 = ?,
            manual_price_edit = 1
        WHERE id = ?
    ");
    $stmt->bind_param('ssisdiiddi', $iname, $name2, $code, $info, $cost_price, $group1, $group2, $price1, $price2, $item_id);
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to update item');
    }
    $stmt->close();
    (new ItemRecipeCatalogService())->saveMetadata($conn, $item_id, $payload);

    // تحديث وحدات الصنف
    $unitStmt = $conn->prepare("
        UPDATE item_units
        SET cost_price = ?,
            price1 = ?,
            price2 = ?,
            price3 = ?,
            u_val = ?,
            unit_barcode = ?
        WHERE item_id = ? AND unit_id = ?
    ");
    foreach ($_POST['unit_id'] as $index => $unit_id) {
        $unit_id = (int) $unit_id;
        $u_val = (float) ($_POST['u_val'][$index] ?? 1);
        $unit_barcode = !empty($_POST['unit_barcode'][$index]) ? (string) $_POST['unit_barcode'][$index] : "99" . $index . ($_POST['unit_barcode'][0] ?? '');
        $cost_price_unit = (float) ($_POST['cost_price'][$index] ?? 0);
        $price1_unit = (float) ($_POST['price1'][$index] ?? 0);
        $price2_unit = (float) ($_POST['price2'][$index] ?? 0);
        $market_price_unit = isset($_POST['price3'][$index]) ? (float) $_POST['price3'][$index] : (isset($_POST['market_price'][$index]) ? (float) $_POST['market_price'][$index] : 0);
        $unitStmt->bind_param('dddddsii', $cost_price_unit, $price1_unit, $price2_unit, $market_price_unit, $u_val, $unit_barcode, $item_id, $unit_id);
        $unitStmt->execute();
    }
    $unitStmt->close();

    $changedItemIds = [$item_id];
    if (array_key_exists('item_variants_payload_present', $_POST)) {
        $changedItemIds = $variantService->saveVariantsFromPost($conn, $item_id, $_POST, ['user_id' => $usid]);
    }
    foreach ($changedItemIds as $changedItemId) {
        posmain_record_menu_item_sync($conn, (int) $changedItemId, 'item_form');
    }
    $conn->commit();
} catch (Throwable $exception) {
    if ($conn instanceof mysqli) {
        $conn->rollback();
    }
    if (function_exists('posmain_log_exception') && function_exists('posmain_error_reference')) {
        posmain_log_exception($exception, posmain_error_reference(), 'edit_item_save');
    }
    header('Location: ../add_item.php?edit=' . (int) $item_id . '&error=save_failed');
    exit;
}

header('Location: ../add_item.php?edit=' . (int) $item_id . '&saved=1');
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
