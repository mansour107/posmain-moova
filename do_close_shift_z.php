<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
include('includes/connect.php');
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/pos_cache_control.php';
require_once __DIR__ . '/classes/Pos/Service/ShiftDrawerReconciliationService.php';
require_once __DIR__ . '/classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/classes/Pos/Service/ShiftCountService.php';
require_once __DIR__ . '/classes/Pos/Service/ShiftSessionService.php';
require_once __DIR__ . '/classes/Pos/Service/DrawerSessionCloseSummaryService.php';
require_once __DIR__ . '/classes/Pos/Service/BusinessDayService.php';
require_once __DIR__ . '/includes/shift_handover_idempotency.php';
require_once __DIR__ . '/includes/business_day.php';

posmain_send_no_store_headers();

// التحقق من تسجيل الدخول
if (PHP_SAPI !== 'cli') {
    require_pos_authenticated();
    require_permission('pos.shift.close', $conn);
} elseif (!isset($_SESSION['userid'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Invalid Request');
}
if (PHP_SAPI !== 'cli') {
    require_csrf('shift_close_z');
}

// حارس ضد الإغلاق المزدوج: إذا أُغلق الشيفت مسبقاً لهذه الجلسة لا تُدرج سجلاً جديداً.
if (PHP_SAPI !== 'cli' && !empty($_SESSION['pos_shift_closed_for_session'])) {
    $_SESSION['error_message'] = 'تم إغلاق هذا الشيفت مسبقاً. أعد فتح نقطة البيع لبدء شيفت جديد.';
    header('Location: pos_barcode.php?logout=1');
    exit;
}

$user_id = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) $_SESSION['userid'];
$tenant = (int) ($_SESSION['pos_tenant'] ?? 0);
$branch = (int) ($_SESSION['pos_branch'] ?? 0);
$businessDays = new BusinessDayService();
$shift_date = posmain_current_business_day($conn, $tenant, $branch);
$shift_time = date('H:i:s');

// استلام البيانات من النموذج
$sys_total_sales = floatval($_POST['sys_total_sales']);
$sys_total_cash = floatval($_POST['sys_total_cash']);
$sys_total_visa = floatval($_POST['sys_total_visa']);
$expenses = floatval($_POST['sys_expenses']);
$notes = isset($_POST['notes']) ? trim((string) $_POST['notes']) : '';
$info_notes = function_exists('mb_substr') ? mb_substr($notes, 0, 50) : substr($notes, 0, 50);
$shift_session_token = trim((string) ($_SESSION['pos_shift_session_token'] ?? ''));

$actual_cash = floatval($_POST['actual_cash']);
$actual_visa = floatval($_POST['actual_visa']);
$requested_drawer_session_id = intval($_POST['drawer_session_id'] ?? 0);
$drawer_reconciliation = null;
$drawer_session_id = 0;
$has_server_reconciliation = false;

try {
    $reconciliation_scope = [
        'user_id' => $user_id,
        'tenant' => $tenant,
        'branch' => $branch,
        'date' => $shift_date,
    ];
    if ($requested_drawer_session_id > 0) {
        $reconciliation_scope['drawer_session_id'] = $requested_drawer_session_id;
    } elseif (!empty($_SESSION['pos_drawer_session_id'])) {
        $reconciliation_scope['drawer_session_id'] = (int) $_SESSION['pos_drawer_session_id'];
    }

    $drawer_reconciliation = (new ShiftDrawerReconciliationService())->buildForUser($conn, $reconciliation_scope);
    $drawer_session_id = intval($drawer_reconciliation['drawer_session']['id'] ?? 0);
    if ($drawer_session_id > 0) {
        $sessionBusinessDay = (string) ($drawer_reconciliation['drawer_session']['business_day'] ?? '');
        if ($sessionBusinessDay === '' && !empty($drawer_reconciliation['drawer_session']['opened_at'])) {
            $cutoffHour = $businessDays->cutoffHourForBranch($conn, $tenant, $branch);
            $sessionBusinessDay = $businessDays->businessDayForTimestamp(
                (string) $drawer_reconciliation['drawer_session']['opened_at'],
                $cutoffHour
            );
        }
        if ($sessionBusinessDay !== '') {
            $shift_date = $sessionBusinessDay;
            $reconciliation_scope['date'] = $shift_date;
        }
    }
    $has_server_reconciliation = $drawer_session_id > 0 || floatval($drawer_reconciliation['payments']['total'] ?? 0) > 0;
    if ($has_server_reconciliation) {
        $sys_total_cash = floatval($drawer_reconciliation['payments']['cash'] ?? $sys_total_cash);
        $sys_total_visa = floatval($drawer_reconciliation['payments']['non_cash'] ?? $sys_total_visa);
    }
} catch (Throwable $e) {
    error_log('Shift drawer reconciliation failed: ' . $e->getMessage());
}

// الحسابات
$expected_cash = $drawer_session_id > 0 ? floatval($drawer_reconciliation['reconciliation']['expected_cash'] ?? 0) : $sys_total_cash - $expenses;
$cash_deficit = $actual_cash - $expected_cash;

$expected_visa = $sys_total_visa;
$visa_deficit = $actual_visa - $expected_visa;

$total_deficit = $cash_deficit + $visa_deficit;

// إعداد بيانات JSON للتفاصيل الإضافية (مثل الدفرنس لكل نوع)
$details_array = [
    'sys_cash' => $sys_total_cash,
    'sys_visa' => $sys_total_visa,
    'sys_expenses' => $expenses,
    'actual_cash' => $actual_cash,
    'actual_visa' => $actual_visa,
    'cash_diff' => $cash_deficit,
    'visa_diff' => $visa_deficit,
    'drawer_session_id' => $drawer_session_id,
    'drawer_expected_cash' => $expected_cash,
    'drawer_cash_difference' => floatval($drawer_reconciliation['reconciliation']['cash_difference'] ?? 0),
    'server_recomputed' => $has_server_reconciliation
];
if ($shift_session_token !== '') {
    $details_array['shift_session_token'] = $shift_session_token;
}
if ($notes !== '') {
    $details_array['cashier_notes'] = $notes;
}
$json_details = json_encode($details_array, JSON_UNESCAPED_UNICODE);

// جلب اسم المستخدم من نفس جدول تسجيل الدخول
$user_query = "SELECT uname FROM users WHERE id = ? LIMIT 1";
$user_stmt = $conn->prepare($user_query);
$user_stmt->bind_param('i', $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_row = $user_result ? $user_result->fetch_assoc() : null;
$user_stmt->close();
$username = $user_row['uname'] ?? ($_SESSION['login'] ?? 'Unknown');

// رقم الشيفت
$shift_number = date('Ymd') . '_' . $user_id;

$countService = new ShiftCountService();
$handoverEnabled = $countService->handoverEnabled($conn);
$branchAdoptedDrawers = false;
if ($handoverEnabled && $drawer_session_id < 1) {
    $tenantScope = (int) ($_SESSION['pos_tenant'] ?? 0);
    $branchScope = (int) ($_SESSION['pos_branch'] ?? 0);
    $branchAdoptedDrawers = (new ShiftSessionService())->drawerSubsystemActive($conn, $tenantScope, $branchScope);
    if ($branchAdoptedDrawers) {
        $_SESSION['error_message'] = 'لا توجد جلسة درج مفتوحة — افتح الدرج أو أكمل العد من نقطة البيع قبل تقرير Z';
        header('Location: z_report.php');
        exit;
    }
}
if ($drawer_session_id > 0) {
    $closeToken = trim((string) ($_POST['close_token'] ?? ''));
    if ($handoverEnabled && $closeToken === '') {
        $_SESSION['error_message'] = 'يجب إجراء عدّ النقد وإغلاق الدرج من نقطة البيع قبل تقرير Z';
        header('Location: z_report.php');
        exit;
    }

    $zRow = [
        'shift' => $shift_number,
        'date' => $shift_date,
        'user' => $username,
        'endtime' => $shift_time,
        'total_sales' => $sys_total_sales,
        'expenses' => $expenses,
        'cash' => $actual_cash,
        'fund_after' => $actual_cash,
        'info' => $info_notes,
        'total_cash' => $sys_total_cash,
        'total_visa' => $sys_total_visa,
        'actual_cash' => $actual_cash,
        'actual_visa' => $actual_visa,
        'deficit' => $total_deficit,
        'json_details' => $json_details,
        'drawer_session_id' => $drawer_session_id,
    ];

    try {
        // Keep deploy-before-migrate windows from blocking a cashier close.
        // This runs before the idempotency transaction to preserve atomicity.
        (new DrawerSessionCloseSummaryService())->ensureSchema($conn);
        $response = pos_shift_handover_idempotent(
            $conn,
            'pos.shift.close',
            $_POST,
            $_SERVER,
            $user_id,
            static function (array $txContext = []) use ($conn, $user_id, $closeToken, $actual_cash, $notes, $drawer_session_id, $zRow, $details_array): array {
                $result = (new ShiftSessionService())->closeSimpleShift($conn, $user_id, [
                    'close_token' => $closeToken,
                    'counted_cash' => $actual_cash,
                    'fund_after' => $actual_cash,
                    'cash' => $actual_cash,
                    'notes' => $notes,
                    'matched' => !empty($_POST['matched']),
                    'drawer_session_id' => $drawer_session_id,
                    'close_path' => 'do_close_shift_z.php',
                    'z_row' => $zRow,
                    'z_details' => $details_array,
                ], $txContext);

                return ['success' => true, 'result' => $result];
            }
        );

        if (($response['code'] ?? '') === 'IDEMPOTENCY_CONFLICT') {
            $_SESSION['error_message'] = 'تم إرسال طلب الإغلاق مسبقاً ببيانات مختلفة';
            header('Location: z_report.php');
            exit;
        }

        $result = $response['result'] ?? [];
        if (($result['status'] ?? '') === 'counted_pending_review') {
            $_SESSION['error_message'] = 'تم تسجيل فرق الدرج، ولا يمكن إنشاء تقرير Z النهائي قبل اعتماد السبب من مستخدم لديه صلاحية مراجعة فروق الشيفت.';
            header('Location: z_report.php');
            exit;
        }
        $variance = round((float) ($result['variance'] ?? $total_deficit), 3);
        $matched = !empty($result['matched']) || abs($variance) <= 0.010;
        if ($matched) {
            $title = 'تم إغلاق الشيفت بنجاح';
            $detail = 'تم تسجيل الإغلاق من تقرير Z';
            $tone = 'success';
        } elseif ($variance > 0) {
            $title = 'تم إغلاق الشيفت — زيادة في الدرج';
            $detail = 'الفرق: +' . number_format(abs($variance), 2) . ' ج.م (سيتم مراجعته من الإدارة)';
            $tone = 'over';
        } else {
            $title = 'تم إغلاق الشيفت — عجز في الدرج';
            $detail = 'الفرق: −' . number_format(abs($variance), 2) . ' ج.م (سيتم مراجعته من الإدارة)';
            $tone = 'under';
        }
        $_SESSION['pos_shift_close_result'] = [
            'tone' => $tone,
            'title' => $title,
            'detail' => $detail,
            'variance' => $variance,
            'matched' => $matched,
        ];
        $_SESSION['success_message'] = $title . ' — ' . $detail;
        header('Location: pos_barcode.php?logout=1');
        exit;
    } catch (RuntimeException $exception) {
        error_log('Shift Z close rejected: ' . $exception->getMessage());
        $_SESSION['error_message'] = $exception->getMessage() === 'CLOSE_TOKEN_INVALID'
            ? 'رمز إغلاق الدرج غير صالح — أعد العد من نقطة البيع'
            : 'حدث خطأ أثناء إغلاق الشيفت';
        header('Location: z_report.php');
        exit;
    } catch (Throwable $exception) {
        error_log('Shift Z close exception: ' . $exception->getMessage());
        $_SESSION['error_message'] = 'حدث خطأ أثناء إغلاق الشيفت';
        header('Location: z_report.php');
        exit;
    }
}

$_SESSION['error_message'] = 'لا توجد جلسة درج مفتوحة لهذا المستخدم. افتح الدرج قبل إنشاء تقرير Z.';
header('Location: z_report.php');
exit;
