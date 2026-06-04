<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
require_once __DIR__ . '/../classes/Items/ItemEditorFlash.php';
require_once __DIR__ . '/../classes/Items/ItemFormInput.php';
require_once __DIR__ . '/../classes/Items/ItemRecipeCatalogService.php';
require_once __DIR__ . '/../classes/Pos/Service/ItemVariantService.php';
require_once __DIR__ . '/../classes/Sync/MenuItemSyncRecorder.php';

$usid = (int) ($_SESSION['userid'] ?? 1);

try {
    if (!isset($_POST['barcode']) || trim((string) $_POST['barcode']) === '') {
        $lastBarcodeRow = $conn->query("SELECT MAX(CAST(barcode AS UNSIGNED)) AS max_barcode FROM myitems WHERE barcode REGEXP '^[0-9]+$'")->fetch_assoc();
        $_POST['barcode'] = ((int) ($lastBarcodeRow['max_barcode'] ?? 0)) + 1;
    }

    $submittedName = trim((string) ($_POST['iname'] ?? ''));
    if ($submittedName === '') {
        throw new InvalidArgumentException('iname is required');
    }

    $stmt = $conn->prepare('SELECT id FROM myitems WHERE iname = ? LIMIT 1');
    $stmt->bind_param('s', $submittedName);
    $stmt->execute();
    $chkname = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($chkname !== null) {
        ItemEditorFlash::set('danger', 'duplicate_name');
        header('Location: ../add_item.php');
        exit;
    }

    $defaultUnitId = posmain_add_item_needs_default_unit($_POST) ? posmain_add_item_default_unit_id($conn) : 0;
    $payload = ItemFormInput::normalizeAddPayload($_POST, $usid, $defaultUnitId);
} catch (InvalidArgumentException $exception) {
    ItemEditorFlash::set('danger', 'save_failed');
    header('Location: ../add_item.php');
    exit;
}

$imageFiles = $_FILES['imgs'] ?? ['name' => []];
if (!posmain_add_item_images_are_valid($imageFiles)) {
    ItemEditorFlash::set('danger', 'invalid_image');
    header('Location: ../add_item.php');
    exit;
}

try {
    $variantService = new ItemVariantService();
    $variantService->ensureSchema($conn);
    $conn->begin_transaction();

    $stmt = $conn->prepare(
        'INSERT INTO myitems(iname, name2, code, barcode, info, market_price, cost_price, price1, price2, price3, group1, group2, user)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $iname = $payload['iname'];
    $name2 = $payload['name2'];
    $code = $payload['code'];
    $barcode = $payload['barcode'];
    $info = $payload['info'];
    $marketPrice = $payload['market_price'];
    $costPrice = $payload['cost_price'];
    $price1 = $payload['price1'];
    $price2 = $payload['price2'];
    $price3 = $payload['price3'];
    $group1 = $payload['group1'];
    $group2 = $payload['group2'];
    $user = $payload['user'];

    $stmt->bind_param(
        'ssissdddddiii',
        $iname,
        $name2,
        $code,
        $barcode,
        $info,
        $marketPrice,
        $costPrice,
        $price1,
        $price2,
        $price3,
        $group1,
        $group2,
        $user
    );
    $stmt->execute();
    $last_id = $conn->insert_id;
    $stmt->close();
    (new ItemRecipeCatalogService())->saveMetadata($conn, (int) $last_id, $payload);

    // إدخال بيانات الوحدات إلى جدول item_units
    $unitStmt = $conn->prepare(
        'INSERT INTO item_units(item_id, unit_id, u_val, unit_barcode, cost_price, price1, price2, price3)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($payload['units'] as $unit) {
        $unitId = $unit['unit_id'];
        $uVal = $unit['u_val'];
        $unitBarcode = $unit['unit_barcode'];
        $unitCostPrice = $unit['cost_price'];
        $unitPrice1 = $unit['price1'];
        $unitPrice2 = $unit['price2'];
        $unitPrice3 = $unit['price3'];

        $unitStmt->bind_param(
            'iidsdddd',
            $last_id,
            $unitId,
            $uVal,
            $unitBarcode,
            $unitCostPrice,
            $unitPrice1,
            $unitPrice2,
            $unitPrice3
        );
        $unitStmt->execute();
    }
    $unitStmt->close();

    // معالجة رفع الصور
    $imgs_name = $imageFiles['name'];
    if (!empty($imgs_name['0'])) {
        $imageStmt = $conn->prepare('INSERT INTO imgs (iname, itemid) VALUES (?, ?)');
        for ($i = 0; $i < count($imageFiles['name']); $i++) {
            $imgs_name = $imageFiles['name'][$i];
            $tmp_name = $imageFiles['tmp_name'][$i];

            $arrkvr = explode(".", $imgs_name);
            $kvr_ext = end($arrkvr);

            $new_kvr_name = $arrkvr[0] . rand(1, 1000000) . "." . $kvr_ext;
            if (move_uploaded_file($tmp_name, "../uploads/$new_kvr_name")) {
                $imageStmt->bind_param('si', $new_kvr_name, $last_id);
                $imageStmt->execute();
            }
        }
        $imageStmt->close();
    }

    // إضافة سجل في جدول process
    $conn->query("INSERT INTO process(type) VALUES ('add item')");
    $changedItemIds = [(int) $last_id];
    if (array_key_exists('item_variants_payload_present', $_POST)) {
        $changedItemIds = $variantService->saveVariantsFromPost($conn, (int) $last_id, $_POST, ['user_id' => $usid]);
    }
    foreach ($changedItemIds as $changedItemId) {
        posmain_record_menu_item_sync($conn, (int) $changedItemId, 'item_form');
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
    header('Location: ../add_item.php');
    exit;
} catch (Throwable $exception) {
    if ($conn instanceof mysqli) {
        $conn->rollback();
    }

    if (function_exists('posmain_log_exception') && function_exists('posmain_error_reference')) {
        posmain_log_exception($exception, posmain_error_reference(), 'add_item_save');
    }

    ItemEditorFlash::set('danger', 'save_failed');
    header('Location: ../add_item.php');
    exit;
}

$saveIntent = (string) ($_POST['save_intent'] ?? 'stay');
if ($saveIntent === 'close') {
    header('Location: ../myitems.php');
} else {
    ItemEditorFlash::set('success', 'saved');
    header('Location: ../add_item.php');
}
exit;

function posmain_add_item_images_are_valid(array $files): bool
{
    $names = $files['name'] ?? [];
    if (!is_array($names)) {
        return true;
    }

    $allowExt = ['jpg', 'png', 'gif', 'jpeg', 'webp'];
    foreach ($names as $name) {
        if (trim((string) $name) === '') {
            continue;
        }

        $extension = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowExt, true)) {
            return false;
        }
    }

    return true;
}

function posmain_add_item_needs_default_unit(array $post): bool
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

function posmain_add_item_default_unit_id(mysqli $conn): int
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
?>
