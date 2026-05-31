<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/config/app_config.php';
require_once __DIR__ . '/classes/Inventory/InventoryLegacyMirrorService.php';
if (!isset($_SESSION['login'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit;
}

include('includes/connect.php');
header('Content-Type: application/json');

$save_start_balance_config_for_guard = function_exists('posmain_app_config') ? posmain_app_config() : [];
if (strtolower((string) ($save_start_balance_config_for_guard['inventory']['ledger_mode'] ?? 'off')) === 'live') {
    echo json_encode([
        'success' => false,
        'message' => 'تم إيقاف شاشة الرصيد الافتتاحي القديمة بعد تفعيل نظام المخزون الجديد',
        'code' => 'opening_balance_legacy_workflow_retired_in_live_inventory_mode',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

const POSMAIN_OPENING_BALANCE_PRO_TYPE = 14;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طلب غير صحيح']);
    exit;
}

$new_qty   = $_POST['new_qty']   ?? [];
$new_price = $_POST['new_price'] ?? [];

if (empty($new_qty)) {
    echo json_encode(['success' => false, 'message' => 'لا توجد بيانات للحفظ']);
    exit;
}

$errors = [];
$conn->begin_transaction();

try {
    $store_id = posmain_start_balance_default_store_id($conn);
    $opening_fatid = null;

    foreach ($new_qty as $item_id => $qty) {
        $item_id = intval($item_id);
        $qty     = floatval($qty);
        $price   = floatval($new_price[$item_id] ?? 0);
        if ($item_id <= 0) {
            $errors[] = 'صنف غير صحيح';
            continue;
        }

        if (!posmain_start_balance_item_exists($conn, $item_id)) {
            $errors[] = "خطأ في تحديث الصنف رقم $item_id";
            continue;
        }

        $non_opening_qty = posmain_start_balance_non_opening_qty($conn, $item_id);
        $opening_qty = $qty - $non_opening_qty;
        $qty_in = $opening_qty > 0 ? $opening_qty : 0.0;
        $qty_out = $opening_qty < 0 ? abs($opening_qty) : 0.0;
        $det_value = abs($opening_qty) * $price;

        if (!posmain_start_balance_save_opening_row(
            $conn,
            $item_id,
            $store_id,
            $qty_in,
            $qty_out,
            $price,
            $det_value,
            $opening_fatid
        )) {
            $errors[] = "خطأ في تحديث الصنف رقم $item_id";
            continue;
        }

        if (!posmain_start_balance_save_recipe_ledger($conn, $item_id, $store_id, $qty, $price)) {
            $errors[] = "خطأ في تحديث رصيد الوصفة للصنف رقم $item_id";
            continue;
        }

        $ledger_qty = posmain_start_balance_ledger_qty($conn, $item_id);
        if (!posmain_start_balance_update_item_summary($conn, $item_id, $ledger_qty, $price)) {
            $errors[] = "خطأ في تحديث الصنف رقم $item_id";
        }
    }

    if (empty($errors)) {
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'تم الحفظ بنجاح']);
    } else {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => implode('<br>', $errors)]);
    }
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function posmain_start_balance_default_store_id(mysqli $conn): int
{
    $row = $conn->query("SELECT cur_value FROM myoptions WHERE oname = 'def_store' LIMIT 1");
    if ($row && $row->num_rows > 0) {
        $store_id = (int) ($row->fetch_assoc()['cur_value'] ?? 0);
        if ($store_id > 0) {
            return $store_id;
        }
    }

    $row = $conn->query('SELECT id FROM acc_head WHERE is_stock = 1 AND isdeleted = 0 ORDER BY id LIMIT 1');
    if ($row && $row->num_rows > 0) {
        $store_id = (int) ($row->fetch_assoc()['id'] ?? 0);
        if ($store_id > 0) {
            return $store_id;
        }
    }

    return 1;
}

function posmain_start_balance_item_exists(mysqli $conn, int $item_id): bool
{
    $stmt = $conn->prepare('SELECT id FROM myitems WHERE id = ? AND isdeleted = 0 LIMIT 1');
    $stmt->bind_param('i', $item_id);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $exists;
}

function posmain_start_balance_non_opening_qty(mysqli $conn, int $item_id): float
{
    $stmt = $conn->prepare('
        SELECT COALESCE(SUM(qty_in), 0) - COALESCE(SUM(qty_out), 0) AS qty
        FROM fat_details
        WHERE item_id = ?
          AND isdeleted = 0
          AND NOT (pro_tybe = ? AND fat_tybe = ?)
    ');
    $pro_type = POSMAIN_OPENING_BALANCE_PRO_TYPE;
    $stmt->bind_param('iii', $item_id, $pro_type, $pro_type);
    $stmt->execute();
    $qty = (float) ($stmt->get_result()->fetch_assoc()['qty'] ?? 0);
    $stmt->close();

    return $qty;
}

function posmain_start_balance_ledger_qty(mysqli $conn, int $item_id): float
{
    $stmt = $conn->prepare('
        SELECT COALESCE(SUM(qty_in), 0) - COALESCE(SUM(qty_out), 0) AS qty
        FROM fat_details
        WHERE item_id = ?
          AND isdeleted = 0
    ');
    $stmt->bind_param('i', $item_id);
    $stmt->execute();
    $qty = (float) ($stmt->get_result()->fetch_assoc()['qty'] ?? 0);
    $stmt->close();

    return $qty;
}

function posmain_start_balance_save_opening_row(
    mysqli $conn,
    int $item_id,
    int $store_id,
    float $qty_in,
    float $qty_out,
    float $price,
    float $det_value,
    ?int &$opening_fatid
): bool {
    $rows = [];
    $stmt = $conn->prepare('
        SELECT id
        FROM fat_details
        WHERE item_id = ?
          AND pro_tybe = ?
          AND fat_tybe = ?
          AND isdeleted = 0
        ORDER BY id ASC
    ');
    $pro_type = POSMAIN_OPENING_BALANCE_PRO_TYPE;
    $stmt->bind_param('iii', $item_id, $pro_type, $pro_type);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = (int) $row['id'];
    }
    $stmt->close();

    $primary_id = $rows[0] ?? 0;
    if ($primary_id > 0) {
        $stmt = $conn->prepare('
            UPDATE fat_details
            SET det_store = ?,
                u_val = 1,
                qty_in = ?,
                qty_out = ?,
                price = ?,
                cost_price = ?,
                stock_value = ?,
                discount = 0,
                det_value = ?,
                fatid = COALESCE(fatid, pro_id),
                pro_id = COALESCE(pro_id, fatid),
                isdeleted = 0
            WHERE id = ?
        ');
        $stmt->bind_param('iddddddi', $store_id, $qty_in, $qty_out, $price, $price, $det_value, $det_value, $primary_id);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            return false;
        }

        if (count($rows) > 1) {
            $extra_ids = array_slice($rows, 1);
            $extra_ids = array_map('intval', $extra_ids);
            if (!$conn->query('UPDATE fat_details SET isdeleted = 1 WHERE id IN (' . implode(',', $extra_ids) . ')')) {
                return false;
            }
        }

        return true;
    }

    if (abs($qty_in - $qty_out) < 0.0000001) {
        return true;
    }

    if ($opening_fatid === null) {
        $opening_fatid = posmain_start_balance_create_header($conn, $store_id);
    }

    $stmt = $conn->prepare('
        INSERT INTO fat_details
            (pro_tybe, det_store, pro_id, item_id, u_val, qty_in, qty_out, price, cost_price, stock_value, discount, det_value, fatid, fat_tybe, isdeleted)
        VALUES
            (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, 0, ?, ?, ?, 0)
    ');
    $stmt->bind_param(
        'iiiiddddddii',
        $pro_type,
        $store_id,
        $opening_fatid,
        $item_id,
        $qty_in,
        $qty_out,
        $price,
        $price,
        $det_value,
        $det_value,
        $opening_fatid,
        $pro_type
    );
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function posmain_start_balance_create_header(mysqli $conn, int $store_id): int
{
    $row = $conn->query('SELECT COALESCE(MAX(pro_id), 0) + 1 AS next_id FROM ot_head')->fetch_assoc();
    $pro_id = (int) ($row['next_id'] ?? 1);
    $user_id = (int) ($_SESSION['userid'] ?? 1);
    $today = date('Y-m-d');
    $info = 'رصيد افتتاحي مخازن';
    $serial = 'OPEN-' . date('YmdHis');
    $pro_type = POSMAIN_OPENING_BALANCE_PRO_TYPE;

    $stmt = $conn->prepare('
        INSERT INTO ot_head
            (pro_id, pro_tybe, is_stock, is_journal, journal_tybe, info, pro_date, accural_date, pro_serial, store_id, emp_id, emp2_id, acc1, acc2, pro_value, user, isdeleted)
        VALUES
            (?, ?, 1, 0, 0, ?, ?, ?, ?, ?, 0, 0, ?, 0, 0, ?, 0)
    ');
    $stmt->bind_param('iissssiii', $pro_id, $pro_type, $info, $today, $today, $serial, $store_id, $store_id, $user_id);
    if (!$stmt->execute()) {
        $message = $stmt->error;
        $stmt->close();
        throw new RuntimeException($message);
    }
    $stmt->close();

    return $pro_id;
}

function posmain_start_balance_update_item_summary(mysqli $conn, int $item_id, float $qty, float $price): bool
{
    return (new InventoryLegacyMirrorService())->refreshItemOpeningBalanceSummary(
        $conn,
        $item_id,
        number_format($qty, 6, '.', ''),
        number_format($price, 6, '.', '')
    );
}

function posmain_start_balance_save_recipe_ledger(mysqli $conn, int $item_id, int $store_id, float $desired_qty, float $price): bool
{
    if (!posmain_start_balance_table_exists($conn, 'inventory_movements')
        || !posmain_start_balance_table_exists($conn, 'inventory_item_balances')) {
        return true;
    }

    $config = function_exists('posmain_app_config') ? posmain_app_config() : [];
    $branch = is_array($config['branch'] ?? null) ? $config['branch'] : [];
    $pos_tenant = (int) ($branch['pos_tenant'] ?? 0);
    $pos_branch = (int) ($branch['pos_branch'] ?? 0);
    $branch_uuid = trim((string) ($branch['uuid'] ?? ''));
    $branch_uuid_value = $branch_uuid !== '' ? $branch_uuid : null;
    $created_by = (int) ($_SESSION['userid'] ?? 1);

    $non_opening_qty = posmain_start_balance_recipe_non_opening_qty($conn, $pos_tenant, $pos_branch, $store_id, $item_id);
    $opening_qty = $desired_qty - $non_opening_qty;
    $qty_in = $opening_qty > 0 ? $opening_qty : 0.0;
    $qty_out = $opening_qty < 0 ? abs($opening_qty) : 0.0;
    $movement_qty = abs($opening_qty);
    $total_cost = $movement_qty * $price;
    $idempotency_key = "items-start-balance:tenant:$pos_tenant:branch:$pos_branch:store:$store_id:item:$item_id";
    $source_uuid = "items-start-balance:$pos_tenant:$pos_branch:$store_id:$item_id";
    $movement_integrity = posmain_start_balance_recipe_movement_integrity(
        $pos_tenant,
        $pos_branch,
        $store_id,
        $item_id,
        $source_uuid,
        $qty_in,
        $qty_out,
        $price,
        $total_cost,
        $desired_qty,
        $non_opening_qty,
        $opening_qty
    );
    $has_integrity_columns = posmain_start_balance_column_exists($conn, 'inventory_movements', 'payload_hash')
        && posmain_start_balance_column_exists($conn, 'inventory_movements', 'metadata_json');

    $movement_id = posmain_start_balance_find_recipe_opening_movement($conn, $pos_tenant, $pos_branch, $store_id, $idempotency_key);
    if ($movement_id > 0) {
        if ($has_integrity_columns) {
            $stmt = $conn->prepare('
            UPDATE inventory_movements
            SET branch_uuid = ?,
                qty_in = ?,
                qty_out = ?,
                unit_cost = ?,
                total_cost = ?,
                source_uuid = ?,
                payload_hash = ?,
                metadata_json = ?,
                created_by = ?
            WHERE id = ?
        ');
        } else {
            $stmt = $conn->prepare('
            UPDATE inventory_movements
            SET branch_uuid = ?,
                qty_in = ?,
                qty_out = ?,
                unit_cost = ?,
                total_cost = ?,
                source_uuid = ?,
                created_by = ?
            WHERE id = ?
        ');
        }
        $qty_in_text = posmain_start_balance_decimal($qty_in);
        $qty_out_text = posmain_start_balance_decimal($qty_out);
        $price_text = posmain_start_balance_decimal($price);
        $total_cost_text = posmain_start_balance_decimal($total_cost);
        if ($has_integrity_columns) {
            $stmt->bind_param(
                'ssssssssii',
                $branch_uuid_value,
                $qty_in_text,
                $qty_out_text,
                $price_text,
                $total_cost_text,
                $source_uuid,
                $movement_integrity['payload_hash'],
                $movement_integrity['metadata_json'],
                $created_by,
                $movement_id
            );
        } else {
            $stmt->bind_param(
                'ssssssii',
                $branch_uuid_value,
                $qty_in_text,
                $qty_out_text,
                $price_text,
                $total_cost_text,
                $source_uuid,
                $created_by,
                $movement_id
            );
        }
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            return false;
        }
    } elseif ($movement_qty > 0.0000001) {
        $movement_uuid = posmain_start_balance_uuid();
        $qty_in_text = posmain_start_balance_decimal($qty_in);
        $qty_out_text = posmain_start_balance_decimal($qty_out);
        $price_text = posmain_start_balance_decimal($price);
        $total_cost_text = posmain_start_balance_decimal($total_cost);
        if ($has_integrity_columns) {
            $stmt = $conn->prepare('
            INSERT INTO inventory_movements
                (movement_uuid, pos_tenant, pos_branch, branch_uuid, store_id, item_id, movement_type, source_type, source_uuid, qty_in, qty_out, unit_cost, total_cost, idempotency_key, payload_hash, metadata_json, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        } else {
            $stmt = $conn->prepare('
            INSERT INTO inventory_movements
                (movement_uuid, pos_tenant, pos_branch, branch_uuid, store_id, item_id, movement_type, source_type, source_uuid, qty_in, qty_out, unit_cost, total_cost, idempotency_key, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        }
        $movement_type = 'opening_balance';
        $source_type = 'manual';
        if ($has_integrity_columns) {
            $stmt->bind_param(
                'siisiissssssssssi',
                $movement_uuid,
                $pos_tenant,
                $pos_branch,
                $branch_uuid_value,
                $store_id,
                $item_id,
                $movement_type,
                $source_type,
                $source_uuid,
                $qty_in_text,
                $qty_out_text,
                $price_text,
                $total_cost_text,
                $idempotency_key,
                $movement_integrity['payload_hash'],
                $movement_integrity['metadata_json'],
                $created_by
            );
        } else {
            $stmt->bind_param(
                'siisiissssssssi',
                $movement_uuid,
                $pos_tenant,
                $pos_branch,
                $branch_uuid_value,
                $store_id,
                $item_id,
                $movement_type,
                $source_type,
                $source_uuid,
                $qty_in_text,
                $qty_out_text,
                $price_text,
                $total_cost_text,
                $idempotency_key,
                $created_by
            );
        }
        $ok = $stmt->execute();
        $movement_id = (int) $conn->insert_id;
        $stmt->close();
        if (!$ok) {
            return false;
        }
    }

    return posmain_start_balance_put_recipe_balance(
        $conn,
        $pos_tenant,
        $pos_branch,
        $branch_uuid_value,
        $store_id,
        $item_id,
        $desired_qty,
        $price,
        $movement_id > 0 ? $movement_id : null
    );
}

function posmain_start_balance_table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare('
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $exists;
}

function posmain_start_balance_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare('
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $exists;
}

function posmain_start_balance_recipe_movement_integrity(
    int $pos_tenant,
    int $pos_branch,
    int $store_id,
    int $item_id,
    string $source_uuid,
    float $qty_in,
    float $qty_out,
    float $unit_cost,
    float $total_cost,
    float $desired_qty,
    float $non_opening_qty,
    float $opening_qty
): array {
    $payload = [
        'scope' => [
            'pos_tenant' => $pos_tenant,
            'pos_branch' => $pos_branch,
            'store_id' => $store_id,
        ],
        'item_id' => $item_id,
        'movement_type' => 'opening_balance',
        'source_type' => 'manual',
        'source_uuid' => $source_uuid,
        'qty_in' => posmain_start_balance_decimal($qty_in),
        'qty_out' => posmain_start_balance_decimal($qty_out),
        'unit_cost' => posmain_start_balance_decimal($unit_cost),
        'total_cost' => posmain_start_balance_decimal($total_cost),
        'metadata' => [
            'source' => 'items_start_balance',
            'desired_qty' => posmain_start_balance_decimal($desired_qty),
            'non_opening_qty' => posmain_start_balance_decimal($non_opening_qty),
            'opening_qty' => posmain_start_balance_decimal($opening_qty),
        ],
    ];

    $metadata_json = json_encode($payload['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($metadata_json)) {
        throw new RuntimeException('Failed to encode opening balance metadata: ' . json_last_error_msg());
    }

    return [
        'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        'metadata_json' => $metadata_json,
    ];
}

function posmain_start_balance_recipe_non_opening_qty(
    mysqli $conn,
    int $pos_tenant,
    int $pos_branch,
    int $store_id,
    int $item_id
): float {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(qty_in), 0) - COALESCE(SUM(qty_out), 0) AS qty
        FROM inventory_movements
        WHERE pos_tenant = ?
          AND pos_branch = ?
          AND store_id = ?
          AND item_id = ?
          AND movement_type <> 'opening_balance'
    ");
    $stmt->bind_param('iiii', $pos_tenant, $pos_branch, $store_id, $item_id);
    $stmt->execute();
    $qty = (float) ($stmt->get_result()->fetch_assoc()['qty'] ?? 0);
    $stmt->close();

    return $qty;
}

function posmain_start_balance_find_recipe_opening_movement(
    mysqli $conn,
    int $pos_tenant,
    int $pos_branch,
    int $store_id,
    string $idempotency_key
): int {
    $stmt = $conn->prepare('
        SELECT id
        FROM inventory_movements
        WHERE pos_tenant = ?
          AND pos_branch = ?
          AND store_id = ?
          AND idempotency_key = ?
        ORDER BY id ASC
        LIMIT 1
    ');
    $stmt->bind_param('iiis', $pos_tenant, $pos_branch, $store_id, $idempotency_key);
    $stmt->execute();
    $id = (int) ($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    $stmt->close();

    return $id;
}

function posmain_start_balance_put_recipe_balance(
    mysqli $conn,
    int $pos_tenant,
    int $pos_branch,
    ?string $branch_uuid,
    int $store_id,
    int $item_id,
    float $desired_qty,
    float $price,
    ?int $movement_id
): bool {
    $stmt = $conn->prepare('
        SELECT qty_reserved
        FROM inventory_item_balances
        WHERE pos_tenant = ?
          AND pos_branch = ?
          AND store_id = ?
          AND item_id = ?
        LIMIT 1
        FOR UPDATE
    ');
    $stmt->bind_param('iiii', $pos_tenant, $pos_branch, $store_id, $item_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $reserved = (float) ($row['qty_reserved'] ?? 0);
    $available = $desired_qty - $reserved;
    $desired_text = posmain_start_balance_decimal($desired_qty);
    $reserved_text = posmain_start_balance_decimal($reserved);
    $available_text = posmain_start_balance_decimal($available);
    $price_text = posmain_start_balance_decimal($price);

    $stmt = $conn->prepare('
        INSERT INTO inventory_item_balances
            (pos_tenant, pos_branch, branch_uuid, store_id, item_id, qty_on_hand, qty_reserved, qty_available, moving_average_cost, last_movement_id)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            branch_uuid = VALUES(branch_uuid),
            qty_on_hand = VALUES(qty_on_hand),
            qty_reserved = VALUES(qty_reserved),
            qty_available = VALUES(qty_available),
            moving_average_cost = VALUES(moving_average_cost),
            last_movement_id = VALUES(last_movement_id)
    ');
    $stmt->bind_param(
        'iisiissssi',
        $pos_tenant,
        $pos_branch,
        $branch_uuid,
        $store_id,
        $item_id,
        $desired_text,
        $reserved_text,
        $available_text,
        $price_text,
        $movement_id
    );
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function posmain_start_balance_decimal(float $value): string
{
    return number_format($value, 6, '.', '');
}

function posmain_start_balance_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20)
    );
}
