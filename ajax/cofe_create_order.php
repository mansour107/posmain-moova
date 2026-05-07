<?php
/**
 * Cofe POS Widget — Order Creation Endpoint
 * يستقبل الأوردر من Cofe widget ويحفظه بنفس طريقة doadd_invoice.php
 */
ob_start(); // امسح أي output قبل الـ JSON
session_start();
include('../includes/connect.php');

// امسح أي output جاي من connect.php (warnings, notices, etc.)
ob_clean();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = file_get_contents('php://input');
$data  = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'code' => 'INVALID_PAYLOAD', 'message' => 'بيانات غير صحيحة']);
    exit;
}

// ===== استخراج بيانات Cofe =====
$cofeOrderId    = $data['cofeOrderId']    ?? '';
$idempotencyKey = $data['idempotencyKey'] ?? '';
$tableNumber    = $data['tableNumber']    ?? '';
$cofeItems      = $data['items']          ?? [];

if (empty($cofeItems)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'code' => 'NO_ITEMS', 'message' => 'لا توجد أصناف في الطلب']);
    exit;
}

// ===== منع التكرار بالـ idempotencyKey (اختياري - يتجاهل لو العمود مش موجود) =====
if (!empty($idempotencyKey)) {
    try {
        $chk = $conn->prepare("SELECT id FROM ot_head WHERE cofe_idempotency_key = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param("s", $idempotencyKey);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $chk->bind_result($existId);
                $chk->fetch();
                $chk->close();
                echo json_encode([
                    'success'             => true,
                    'orderId'             => $existId,
                    'providerOrderId'     => (string)$existId,
                    'providerReferenceId' => $idempotencyKey,
                    'providerStatus'      => 'created',
                ]);
                exit;
            }
            $chk->close();
        }
    } catch (Exception $e) {
        // العمود مش موجود — نكمل بدون idempotency check
        error_log('[Cofe] idempotency check skipped: ' . $e->getMessage());
    }
}

// ===== جلب الـ defaults من الـ settings =====
$stg = $conn->query("SELECT * FROM settings WHERE id = 1")->fetch_assoc();

// المخزن الافتراضي
$store_id = intval($stg['def_pos_store'] ?? 0);
if ($store_id == 0) {
    $r = $conn->query("SELECT id FROM stores WHERE isdeleted = 0 ORDER BY id LIMIT 1");
    if ($r && $r->num_rows > 0) $store_id = intval($r->fetch_assoc()['id']);
}

// الموظف الافتراضي
$emp_id = intval($stg['def_pos_employee'] ?? 0);
if ($emp_id == 0) {
    $r = $conn->query("SELECT id FROM acc_head WHERE parent_id = 35 AND is_basic = 0 AND isdeleted = 0 ORDER BY id LIMIT 1");
    if ($r && $r->num_rows > 0) $emp_id = intval($r->fetch_assoc()['id']);
}

// العميل الافتراضي
$acc2_id = intval($stg['def_pos_client'] ?? 0);
if ($acc2_id == 0) {
    $r = $conn->query("SELECT id FROM acc_head WHERE parent_id = 12 AND is_basic = 0 AND isdeleted = 0 ORDER BY id LIMIT 1");
    if ($r && $r->num_rows > 0) $acc2_id = intval($r->fetch_assoc()['id']);
}

// الصندوق الافتراضي
$fund_id = intval($stg['def_pos_fund'] ?? 0);
if ($fund_id == 0) {
    $r = $conn->query("SELECT id FROM acc_head WHERE is_fund = 1 AND is_basic = 0 AND isdeleted = 0 ORDER BY id LIMIT 1");
    if ($r && $r->num_rows > 0) $fund_id = intval($r->fetch_assoc()['id']);
}

if ($store_id == 0 || $emp_id == 0 || $acc2_id == 0 || $fund_id == 0) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'code'    => 'MISSING_DEFAULTS',
        'message' => 'إعدادات الـ POS غير مكتملة (مخزن/موظف/عميل/صندوق)',
    ]);
    exit;
}

// ===== تحويل أصناف Cofe لأصناف النظام =====
$orderItems = [];
$headtotal  = 0.0;

foreach ($cofeItems as $cofeItem) {
    $cofeItemId = (string)($cofeItem['itemId'] ?? '');
    $qty        = floatval($cofeItem['qty'] ?? 1);
    if ($qty <= 0) continue;

    // البحث بـ cofe_item_id أو barcode أو id
    $stmt = $conn->prepare(
        "SELECT id, iname, price1, cost_price, itmqty, barcode
         FROM myitems
         WHERE (cofe_item_id = ? OR barcode = ? OR id = ?)
           AND (isdeleted = 0 OR isdeleted IS NULL)
         LIMIT 1"
    );
    $cofeItemIdInt = intval($cofeItemId);
    $stmt->bind_param("ssi", $cofeItemId, $cofeItemId, $cofeItemIdInt);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$item) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'code'    => 'ITEM_NOT_FOUND',
            'message' => "الصنف رقم {$cofeItemId} غير موجود في النظام",
        ]);
        exit;
    }

    $price      = floatval($item['price1']);
    $headtotal += $price * $qty;

    $orderItems[] = [
        'id'         => intval($item['id']),
        'name'       => $item['iname'],
        'qty'        => $qty,
        'price'      => $price,
        'cost_price' => floatval($item['cost_price']),
        'old_qty'    => intval($item['itmqty']),
    ];
}

if (empty($orderItems)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'code' => 'NO_VALID_ITEMS', 'message' => 'لا توجد أصناف صالحة']);
    exit;
}

// ===== الثوابت =====
$pro_tybe  = 9; // POS / كاشير
$headdisc  = 0.0;
$headnet   = $headtotal;
$pro_date  = date('Y-m-d');
$usid      = $_SESSION['userid'] ?? 1;
$info      = "Cofe Order" . ($tableNumber ? " - طاولة {$tableNumber}" : '') . " - نوع الطلب: طاولة";

// الحسابات المحاسبية لـ POS
$acc1 = $fund_id;  // الصندوق مدين
$acc2 = $acc2_id;  // العميل دائن

// ===== بدء المعاملة =====
$conn->begin_transaction();

try {
    // رقم الفاتورة التالي
    $r = $conn->prepare("SELECT MAX(CAST(pro_id AS UNSIGNED)) as max_id FROM ot_head WHERE pro_tybe = ?");
    $r->bind_param("i", $pro_tybe);
    $r->execute();
    $row = $r->get_result()->fetch_assoc();
    $r->close();
    $pro_id = $row && $row['max_id'] ? ($row['max_id'] + 1) : 1;

    // ===== إدراج رأس الفاتورة =====
    // الأعمدة: pro_id(1) pro_tybe(2) is_stock is_journal journal_tybe(3) info(4) pro_date(5)
    //          accural_date(6) pro_pattren pro_serial price_list store_id(7) emp_id(8)
    //          emp2_id(9) acc1(10) acc2(11) pro_value(12) fat_cost cost_center profit
    //          fat_total(13) fat_disc(14) fat_disc_per fat_plus fat_plus_per
    //          fat_tax fat_tax_per fat_net(15) user(16)
    // إجمالي الـ ? = 16
    $stmt = $conn->prepare(
        "INSERT INTO ot_head (
            pro_id, pro_tybe, is_stock, is_journal, journal_tybe, info, pro_date,
            accural_date, pro_pattren, pro_serial, price_list, store_id, emp_id,
            emp2_id, acc1, acc2, pro_value, fat_cost, cost_center, profit,
            fat_total, fat_disc, fat_disc_per, fat_plus, fat_plus_per,
            fat_tax, fat_tax_per, fat_net, user
        ) VALUES (
            ?, ?, 1, 1, ?, ?, ?,
            ?, 1, 0, 1, ?, ?,
            ?, ?, ?, ?,  0, 1, 0,
            ?, ?,        0, 0, 0,
            0, 0,        ?, ?
        )"
    );
    // types: s s s s s  s i i  i i i d  d d  d i
    $stmt->bind_param(
        "ssssssiiiiiddddis",
        $pro_id,    // 1  s
        $pro_tybe,  // 2  s
        $pro_tybe,  // 3  s  (journal_tybe = pro_tybe)
        $info,      // 4  s
        $pro_date,  // 5  s
        $pro_date,  // 6  s  (accural_date)
        $store_id,  // 7  i
        $emp_id,    // 8  i
        $emp_id,    // 9  i  (emp2_id)
        $acc1,      // 10 i
        $acc2,      // 11 i
        $headtotal, // 12 d  (pro_value)
        $headtotal, // 13 d  (fat_total)
        $headdisc,  // 14 d  (fat_disc)
        $headnet,   // 15 d  (fat_net)
        $usid       // 16 i
    );

    if (!$stmt->execute()) {
        throw new Exception('فشل في إدخال رأس الفاتورة: ' . $stmt->error);
    }
    $last_op = $conn->insert_id;
    $stmt->close();

    // ===== القيود المحاسبية =====
    // رقم القيد التالي
    $r = $conn->prepare("SELECT MAX(journal_id) as max_id FROM journal_heads");
    $r->execute();
    $row = $r->get_result()->fetch_assoc();
    $r->close();
    $journal_id = $row && $row['max_id'] ? ($row['max_id'] + 1) : 1;

    // رأس القيد
    $details = "فاتورة ريسيت _ {$last_op}";
    $stmt = $conn->prepare(
        "INSERT INTO journal_heads (journal_id, total, jdate, details, user, op_id)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("sdssss", $journal_id, $headnet, $pro_date, $details, $usid, $last_op);
    if (!$stmt->execute()) throw new Exception('فشل في إدخال رأس القيد');
    $journal_lastid = $conn->insert_id;
    $stmt->close();

    // مدين: العميل
    $stmt = $conn->prepare(
        "INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op_id)
         VALUES (?, ?, ?, 0, 0, ?)"
    );
    $stmt->bind_param("ssds", $journal_lastid, $acc2_id, $headnet, $last_op);
    if (!$stmt->execute()) throw new Exception('فشل في إدخال القيد المدين');
    $stmt->close();

    // دائن: المبيعات (91)
    $stmt = $conn->prepare(
        "INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op_id)
         VALUES (?, ?, 0, ?, 1, ?)"
    );
    $sales_acc = 91;
    $stmt->bind_param("ssds", $journal_lastid, $sales_acc, $headnet, $last_op);
    if (!$stmt->execute()) throw new Exception('فشل في إدخال القيد الدائن');
    $stmt->close();

    // ===== سند القبض (الدفع الكاش) =====
    $r = $conn->prepare("SELECT MAX(CAST(pro_id AS UNSIGNED)) as max_id FROM ot_head WHERE pro_tybe = 1");
    $r->execute();
    $row = $r->get_result()->fetch_assoc();
    $r->close();
    $cash_op_id = $row && $row['max_id'] ? ($row['max_id'] + 1) : 1;

    $cash_info = $info . " - دفع كاش";
    $stmt = $conn->prepare(
        "INSERT INTO ot_head (
            pro_id, pro_tybe, is_journal, journal_tybe, info, pro_date,
            emp_id, acc1, acc2, pro_value, cost_center, profit, user, op2
        ) VALUES (?, 1, 1, 1, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?)"
    );
    // pro_id(1) info(2) pro_date(3) emp_id(4) acc1/fund(5) acc2/client(6) pro_value(7) user(8) op2(9)
    // types:    s       s           s          i             i              i             d       i    i
    $stmt->bind_param("sssiiidii",
        $cash_op_id,  // 1 s
        $cash_info,   // 2 s
        $pro_date,    // 3 s
        $emp_id,      // 4 i
        $fund_id,     // 5 i
        $acc2_id,     // 6 i
        $headnet,     // 7 d
        $usid,        // 8 i
        $last_op      // 9 i
    );
    if (!$stmt->execute()) throw new Exception('فشل في إدخال سند القبض');
    $last_cash_paid = $conn->insert_id;
    $stmt->close();

    // قيد سند القبض
    $r = $conn->prepare("SELECT MAX(journal_id) as max_id FROM journal_heads");
    $r->execute();
    $row = $r->get_result()->fetch_assoc();
    $r->close();
    $journal_id2 = $row && $row['max_id'] ? ($row['max_id'] + 1) : 1;

    $cash_details = "سند قبض كاش _ {$pro_id}";
    $stmt = $conn->prepare(
        "INSERT INTO journal_heads (journal_id, op_id, total, jdate, details, user, op2)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("ssdssss", $journal_id2, $last_cash_paid, $headnet, $pro_date, $cash_details, $usid, $last_op);
    if (!$stmt->execute()) throw new Exception('فشل في إدخال رأس قيد القبض');
    $journal_lastid2 = $conn->insert_id;
    $stmt->close();

    // مدين: الصندوق
    $stmt = $conn->prepare(
        "INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2)
         VALUES (?, ?, ?, 0, 0, ?)"
    );
    $stmt->bind_param("ssds", $journal_lastid2, $fund_id, $headnet, $last_op);
    if (!$stmt->execute()) throw new Exception('فشل في قيد الصندوق');
    $stmt->close();

    // دائن: العميل
    $stmt = $conn->prepare(
        "INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2)
         VALUES (?, ?, 0, ?, 1, ?)"
    );
    $stmt->bind_param("ssds", $journal_lastid2, $acc2_id, $headnet, $last_op);
    if (!$stmt->execute()) throw new Exception('فشل في قيد العميل للقبض');
    $stmt->close();

    // ===== إدراج تفاصيل الأصناف =====
    // الأعمدة: pro_tybe(1) pro_id(2) item_id(3) u_val qty_in qty_out(4) price(5)
    //          discount det_value(6) fatid(7) fat_tybe(8) det_store(9) cost_price(10) profit(11)
    $stmt_details = $conn->prepare(
        "INSERT INTO fat_details (
            pro_tybe, pro_id, item_id, u_val, qty_in, qty_out, price,
            discount, det_value, fatid, fat_tybe, det_store, cost_price, profit
        ) VALUES (
            ?, ?, ?, 1, 0, ?, ?,
            0, ?, ?, ?, ?, ?, ?
        )"
    );
    // types: i i i  d d  d i i i  d d
    foreach ($orderItems as $item) {
        $qty_out    = $item['qty'];
        $det_value  = $item['qty'] * $item['price'];
        $unit_price = $item['price'];
        $itmprofit  = $item['qty'] * ($unit_price - $item['cost_price']);

        $stmt_details->bind_param(
            "iiidddiiidd",
            $pro_tybe,           // 1 i
            $last_op,            // 2 i  (pro_id = fatid = last_op)
            $item['id'],         // 3 i
            $qty_out,            // 4 d
            $unit_price,         // 5 d
            $det_value,          // 6 d
            $last_op,            // 7 i  (fatid)
            $pro_tybe,           // 8 i  (fat_tybe)
            $store_id,           // 9 i
            $item['cost_price'], // 10 d
            $itmprofit           // 11 d
        );
        if (!$stmt_details->execute()) {
            throw new Exception('فشل في إدخال الصنف: ' . $item['id'] . ' - ' . $stmt_details->error);
        }
    }
    $stmt_details->close();

    // ===== تحديث الربح الإجمالي =====
    $r = $conn->prepare("SELECT SUM(profit) AS tprofit FROM fat_details WHERE fatid = ?");
    $r->bind_param("i", $last_op);
    $r->execute();
    $row = $r->get_result()->fetch_assoc();
    $r->close();
    $ot_profit = $row['tprofit'] ?? 0;

    $stmt = $conn->prepare("UPDATE ot_head SET profit = ? WHERE id = ?");
    $stmt->bind_param("ss", $ot_profit, $last_op);
    $stmt->execute();
    $stmt->close();

    // ===== تحديث حالة الطاولة =====
    if (!empty($tableNumber)) {
        $tnum = intval($tableNumber);
        if ($tnum > 0) {
            $conn->query("UPDATE tables SET table_case = 1 WHERE id = {$tnum}");
        }
    }

    // ===== تسجيل العملية =====
    $process_type = 'add cash';
    $stmt = $conn->prepare("INSERT INTO process (type) VALUES (?)");
    $stmt->bind_param("s", $process_type);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    echo json_encode([
        'success'             => true,
        'orderId'             => $last_op,
        'providerOrderId'     => (string)$last_op,
        'providerReferenceId' => $idempotencyKey ?: (string)$last_op,
        'providerStatus'      => 'created',
        'invoiceNumber'       => $pro_id,
        'message'             => 'تم إنشاء الطلب بنجاح',
    ]);

} catch (Exception $e) {
    $conn->rollback();
    error_log('[Cofe] Order creation failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'code'    => 'DB_ERROR',
        'message' => 'خطأ في حفظ الطلب: ' . $e->getMessage(),
    ]);
}