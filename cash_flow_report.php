<?php
require_once __DIR__ . '/includes/auth_guard.php';
include('includes/connect.php');
require_once __DIR__ . '/includes/page_guard.php';
require_once __DIR__ . '/includes/pos_cache_control.php';
page_guard('reports.cash_flow', $conn);
posmain_send_no_store_headers();

$dateFrom = $_GET['date_from'] ?? date('Y-m-d');
$dateTo = $_GET['date_to'] ?? $dateFrom;
$cashierId = (int) ($_GET['cashier_id'] ?? 0);
$movementType = trim((string) ($_GET['movement_type'] ?? ''));
$drawerSessionId = (int) ($_GET['drawer_session_id'] ?? 0);

require_once __DIR__ . '/classes/Pos/Service/CashFlowPeriodService.php';
$service = new CashFlowPeriodService();
$filters = [
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'cashier_id' => $cashierId,
    'movement_type' => $movementType,
    'drawer_session_id' => $drawerSessionId,
    'include_unassigned' => true,
    'tenant' => (int) ($_SESSION['pos_tenant'] ?? 0),
    'branch' => (int) ($_SESSION['pos_branch'] ?? 0),
];
$summary = $service->summary($conn, $filters);
$sessions = $service->sessions($conn, $filters);
$movements = $service->movements($conn, array_merge($filters, ['limit' => 200, 'offset' => 0]));
$payments = $service->paymentBreakdown($conn, $filters);

$cashiers = [];
$cashierRes = $conn->query('SELECT id, uname, display_name FROM users WHERE isdeleted = 0 ORDER BY uname');
if ($cashierRes) {
    while ($row = $cashierRes->fetch_assoc()) {
        $cashiers[] = $row;
    }
}

$movementLabels = [
    'sale_cash' => 'مبيعات نقدية',
    'refund_cash' => 'مرتجعات نقدية',
    'paid_in' => 'إيداعات',
    'paid_out' => 'مصروفات',
    'safe_drop' => 'تحويل للخزنة',
    'opening' => 'افتتاح',
    'closing_adjustment' => 'تسوية إغلاق',
    'no_sale' => 'فتح بدون بيع',
];
?>
<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <h1>تقرير التدفق النقدي</h1>
    </div>
  </section>
  <section class="content">
    <div class="container-fluid">
      <div class="card">
        <div class="card-body">
          <form method="get" class="row g-2 align-items-end">
            <div class="col-md-2">
              <label class="form-label">من تاريخ</label>
              <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label">إلى تاريخ</label>
              <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label">الكاشير</label>
              <select name="cashier_id" class="form-control">
                <option value="0">الكل</option>
                <?php foreach ($cashiers as $cashier): ?>
                  <option value="<?= (int) $cashier['id'] ?>" <?= $cashierId === (int) $cashier['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) ($cashier['display_name'] ?: $cashier['uname'])) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">نوع الحركة</label>
              <select name="movement_type" class="form-control">
                <option value="">الكل</option>
                <?php foreach ($movementLabels as $key => $label): ?>
                  <option value="<?= htmlspecialchars($key) ?>" <?= $movementType === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">جلسة الدرج</label>
              <input type="number" name="drawer_session_id" class="form-control" value="<?= $drawerSessionId > 0 ? $drawerSessionId : '' ?>" placeholder="ID">
            </div>
            <div class="col-md-2">
              <button type="submit" class="btn btn-primary w-100">عرض</button>
            </div>
          </form>
        </div>
      </div>

      <?php if ((float) ($summary['unassigned_total'] ?? 0) != 0.0 || (int) ($summary['unassigned_count'] ?? 0) > 0): ?>
      <div class="alert alert-warning">
        حركات غير مربوطة بجلسة درج: <?= htmlspecialchars((string) $summary['unassigned_count']) ?>
        (صافي <?= number_format((float) $summary['unassigned_total'], 3) ?>).
        هذه النقدية موجودة فعلياً في الدرج لكنها غير محسوبة ضمن المتوقع لكل جلسة،
        لذلك تظهر كزيادة في فرق الإغلاق حتى يتم ربطها بجلسة.
      </div>
      <?php endif; ?>

      <div class="row">
        <?php foreach (($summary['movement_totals'] ?? []) as $type => $total): ?>
          <?php if ((float) $total == 0.0 && !in_array($type, ['sale_cash', 'paid_out', 'opening'], true)) continue; ?>
          <div class="col-md-3 col-sm-6">
            <div class="small-box bg-info">
              <div class="inner">
                <h3><?= number_format((float) $total, 2) ?></h3>
                <p><?= htmlspecialchars($movementLabels[$type] ?? $type) ?></p>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="row">
        <div class="col-md-4">
          <div class="card"><div class="card-body">
            <strong>المصدر:</strong> <?= htmlspecialchars((string) ($summary['source'] ?? '')) ?><br>
            <strong>متوقع قبل الإغلاق (مجموع):</strong> <?= number_format((float) ($summary['expected_cash_rollup'] ?? 0), 3) ?><br>
            <strong>معد (مجموع):</strong> <?= number_format((float) ($summary['counted_cash_rollup'] ?? 0), 3) ?><br>
            <strong>فرق الإغلاق (مجموع):</strong> <?= number_format((float) ($summary['close_variance_rollup'] ?? $summary['difference_rollup'] ?? 0), 3) ?>
          </div></div>
        </div>
        <div class="col-md-4">
          <div class="card"><div class="card-body">
            <strong>مدفوعات نقدية (order_payments):</strong> <?= htmlspecialchars((string) ($payments['cash_net'] ?? '0')) ?><br>
            <strong>درج (بيع - مرتجع):</strong> <?= htmlspecialchars((string) ($payments['drawer_cash_net'] ?? '0')) ?><br>
            <strong>فرق التسوية:</strong> <?= htmlspecialchars((string) ($payments['cash_reconciliation_diff'] ?? '0')) ?>
          </div></div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h3 class="card-title">جلسات الدرج</h3></div>
        <div class="card-body table-responsive">
          <table class="table table-sm table-bordered">
            <thead>
              <tr>
                <th>ID</th><th>كاشير</th><th>افتتاح</th><th>إغلاق</th><th>حالة</th>
                <th>افتتاحي</th><th>متوقع قبل الإغلاق</th><th>معد</th><th>فرق الإغلاق</th><th>يوم العمل</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sessions as $session): ?>
              <tr>
                <td><a href="?<?= http_build_query(array_merge($_GET, ['drawer_session_id' => (int) $session['id']])) ?>"><?= (int) $session['id'] ?></a></td>
                <td><?= htmlspecialchars((string) $session['user_name']) ?></td>
                <td><?= htmlspecialchars((string) $session['opened_at']) ?></td>
                <td><?= htmlspecialchars((string) ($session['closed_at'] ?? '-')) ?></td>
                <td><?= htmlspecialchars((string) $session['status']) ?></td>
                <td><?= number_format((float) $session['opening_cash'], 3) ?></td>
                <td><?= number_format((float) $session['expected_cash'], 3) ?></td>
                <td><?= number_format((float) $session['counted_cash'], 3) ?></td>
                <td><?= number_format((float) ($session['close_variance'] ?? $session['difference'] ?? 0), 3) ?></td>
                <td><?= htmlspecialchars((string) ($session['business_day'] ?? '-')) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h3 class="card-title">حركات التدفق النقدي</h3></div>
        <div class="card-body table-responsive">
          <table class="table table-sm table-striped table-bordered">
            <thead>
              <tr>
                <th>الوقت</th><th>النوع</th><th>المبلغ</th><th>جلسة</th><th>طلب</th>
                <th>سند</th><th>سبب</th><th>كاشير</th><th>غير مربوط</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($movements['rows'] as $row): ?>
              <tr class="<?= !empty($row['is_unassigned']) ? 'table-warning' : '' ?>">
                <td><?= htmlspecialchars((string) $row['created_at']) ?></td>
                <td><?= htmlspecialchars($movementLabels[$row['movement_type']] ?? $row['movement_type']) ?></td>
                <td><?= number_format((float) $row['amount'], 3) ?></td>
                <td><?= $row['drawer_session_id'] !== null ? (int) $row['drawer_session_id'] : '-' ?></td>
                <td><?= $row['order_id'] !== null ? (int) $row['order_id'] : '-' ?></td>
                <td>
                  <?php if (!empty($row['ref_ot_head_id'])): ?>
                    #<?= (int) $row['ref_ot_head_id'] ?>
                    <?php if (!empty($row['voucher_pro_id'])): ?>(<?= (int) $row['voucher_pro_id'] ?>)<?php endif; ?>
                  <?php else: ?>-<?php endif; ?>
                </td>
                <td><?= htmlspecialchars((string) ($row['reason'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($row['created_by_name'] ?? '')) ?></td>
                <td><?= !empty($row['is_unassigned']) ? 'نعم' : 'لا' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>
</div>
<?php include('includes/footer.php') ?>
