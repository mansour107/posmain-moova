<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/pos_default_accounts.php';
require_once __DIR__ . '/classes/Sync/SchemaManager.php';

require_login();
$canWorkers = auth_guard_has_permission('delivery.workers.manage', $conn);
$canPlans = auth_guard_has_permission('delivery.compensation.manage', $conn);
$canSettlements = auth_guard_has_permission('delivery.settlements.manage', $conn);
$canReports = auth_guard_has_permission('delivery.reports.view', $conn);
$canReverse = auth_guard_has_permission('delivery.settlements.reverse', $conn);
if (!$canWorkers && !$canPlans && !$canSettlements && !$canReports) {
    deny_json_or_redirect('PERMISSION_DENIED', 403);
}
$pendingDeliverySchema = (new SyncSchemaManager())->pendingDeliveryStatements($conn);
$deliveryCsrf = csrf_token('delivery_operations');
$tenant = max(0, (int) ($_SESSION['pos_tenant'] ?? 0));
$branch = max(0, (int) ($_SESSION['pos_branch'] ?? 0));
$funds = [];
$fundSql = 'SELECT id, aname, code FROM acc_head WHERE isdeleted = 0 AND is_fund = 1';
if (posmain_acc_head_has_column($conn, 'tenant')) {
    $fundSql .= " AND tenant IN (0, {$tenant})";
}
if (posmain_acc_head_has_column($conn, 'branch')) {
    $fundSql .= " AND branch IN (0, {$branch})";
}
$fundResult = $conn->query($fundSql . ' ORDER BY aname');
if ($fundResult) while ($row = $fundResult->fetch_assoc()) $funds[] = $row;
$drawerSessionId = 0;
$userId = (int) ($_SESSION['userid'] ?? 0);
if (!$pendingDeliverySchema) {
    $drawerStmt = $conn->prepare("SELECT id FROM drawer_sessions WHERE user_id = ? AND tenant = ? AND branch = ? AND status = 'open' ORDER BY opened_at DESC LIMIT 1");
    $drawerStmt->bind_param('iii', $userId, $tenant, $branch);
    $drawerStmt->execute();
    $drawerSessionId = (int) ($drawerStmt->get_result()->fetch_assoc()['id'] ?? 0);
    $drawerStmt->close();
}
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/navbar.php'; ?>
<?php include 'includes/sidebar.php'; ?>
<link rel="stylesheet" href="css/delivery-operations.css?v=<?= (int) (@filemtime(__DIR__ . '/css/delivery-operations.css') ?: 1) ?>">
<div class="content-wrapper delivery-shell">
  <section class="content"><div class="container-fluid">
    <div class="delivery-hero">
      <div><div class="delivery-eyebrow">العمال والمستحقات</div><h1>العمال والحسابات</h1><p>إدارة عمال التوصيل وخطط مستحقاتهم وتسوياتهم المالية.</p></div>
      <div class="delivery-hero__actions"><a class="delivery-button" href="delivery_board.php"><i class="fas fa-arrow-right"></i> طلبات التوصيل</a></div>
    </div>
    <?php if ($pendingDeliverySchema): ?>
      <div class="delivery-alert">يلزم تطبيق تحديث قاعدة بيانات التوصيل قبل استخدام هذه الشاشة. التحديثات المعلقة: <?= htmlspecialchars(implode(', ', array_keys($pendingDeliverySchema)), ENT_QUOTES, 'UTF-8') ?></div>
    <?php else: ?>
      <div id="deliveryManagementAlert" class="delivery-alert d-none"></div>
      <nav class="delivery-tabs" aria-label="أقسام إدارة التوصيل">
        <button class="delivery-tab is-active" data-tab="overview">نظرة عامة</button>
        <?php if ($canWorkers): ?><button class="delivery-tab" data-tab="workers">العمال</button><?php endif; ?>
        <?php if ($canPlans): ?><button class="delivery-tab" data-tab="plans">خطط المستحقات</button><?php endif; ?>
        <?php if ($canSettlements): ?><button class="delivery-tab" data-tab="settlements">التسويات</button><?php endif; ?>
      </nav>
      <section class="delivery-panel is-active" data-panel="overview">
        <div class="delivery-kpis" id="deliveryKpis"></div>
        <div class="delivery-card"><h3>آخر التسويات</h3><div class="table-responsive"><table class="delivery-table"><thead><tr><th>العامل</th><th>الفترة</th><th>نتيجة التسوية</th><th>الحالة</th></tr></thead><tbody id="deliveryRecentSettlements"></tbody></table></div></div>
      </section>
      <?php if ($canWorkers): ?><section class="delivery-panel" data-panel="workers"><div class="delivery-split">
        <form id="deliveryWorkerForm" class="delivery-card"><h3>بيانات العامل</h3><input type="hidden" name="id" value="0"><div class="delivery-form-grid"><label>الاسم<input name="name" required></label><label>الهاتف<input name="phone"></label><label>خطة المستحقات<select name="compensation_plan_id" id="workerPlanSelect"><option value="">بدون خطة</option></select></label><label>الحالة<select name="is_active"><option value="1">نشط</option><option value="0">موقوف</option></select></label><label>التوفر<select name="is_available"><option value="1">متاح</option><option value="0">غير متاح</option></select></label><label>ملاحظات<textarea name="notes" rows="2"></textarea></label></div><button class="delivery-button delivery-button--primary mt-3" type="submit">حفظ العامل</button></form>
        <div class="delivery-card"><h3>عمال التوصيل</h3><div class="table-responsive"><table class="delivery-table"><thead><tr><th>العامل</th><th>الخطة</th><th>طلبات جارية</th><th>طلبات متعثرة</th><th>مستحقات العامل</th><th>الحالة</th><th></th></tr></thead><tbody id="deliveryWorkersTable"></tbody></table></div></div>
      </div></section><?php endif; ?>
      <?php if ($canPlans): ?><section class="delivery-panel" data-panel="plans"><div class="delivery-split">
        <form id="deliveryPlanForm" class="delivery-card"><h3>خطة مستحقات</h3><input type="hidden" name="id" value="0"><div class="delivery-form-grid"><label>اسم الخطة<input name="name" required></label><label>سريان من<input name="effective_from" type="date" value="<?= date('Y-m-d') ?>" required></label><label>الأجر الأساسي<select name="base_period"><option value="none">بدون أساسي</option><option value="daily">يومي</option><option value="weekly">أسبوعي</option><option value="monthly">شهري</option></select></label><label>قيمة الأساسي<input name="base_amount" type="number" min="0" step="0.001" value="0" disabled aria-describedby="deliveryBaseAmountHint"><small id="deliveryBaseAmountHint" class="delivery-field-hint">تُفعّل عند اختيار أجر أساسي.</small></label><label>حساب كل طلب<select name="per_delivery_method"><option value="customer_fee">نفس رسوم العميل</option><option value="fixed">مبلغ ثابت</option><option value="percentage">نسبة من رسوم العميل</option><option value="zone_rate">حسب المنطقة</option><option value="none">بدون مبلغ للطلب</option></select></label><label>القيمة / النسبة<input name="per_delivery_value" type="number" min="0" step="0.001" value="0"></label><label>الإكرامية<select name="tips_mode"><option value="none">لا تضاف</option><option value="pass_through">تضاف للعامل</option></select></label><label>الحالة<select name="is_active"><option value="1">نشطة</option><option value="0">موقوفة</option></select></label></div><p class="delivery-form-note">الأجر الأسبوعي والشهري يُحتسب تلقائياً بنسبة أيام فترة التسوية، لذلك لا يضيع حق العامل عند تسوية جزء من أسبوع أو شهر.</p><div id="deliveryZoneRates" class="mt-3"></div><button class="delivery-button delivery-button--primary mt-3" type="submit">حفظ الخطة</button></form>
        <div class="delivery-card"><h3>الخطط الحالية</h3><div class="table-responsive"><table class="delivery-table"><thead><tr><th>الخطة</th><th>الأساسي</th><th>لكل طلب</th><th>السريان</th><th></th></tr></thead><tbody id="deliveryPlansTable"></tbody></table></div></div>
      </div></section><?php endif; ?>
      <?php if ($canSettlements): ?><section class="delivery-panel" data-panel="settlements"><div class="delivery-split">
        <form id="deliverySettlementForm" class="delivery-card"><h3>تسوية عامل</h3><div class="delivery-form-grid"><label>العامل<select name="worker_id" id="settlementWorkerSelect" required></select></label><label>من<input name="period_start" type="date" value="<?= date('Y-m-01') ?>" required></label><label>إلى<input name="period_end" type="date" value="<?= date('Y-m-d') ?>" required></label><label>مكافآت<input name="bonuses" type="number" min="0" step="0.001" value="0"></label><label>خصومات<input name="deductions" type="number" min="0" step="0.001" value="0"></label><label>طريقة التسوية<select name="payment_method"><option value="cash">نقدي</option><option value="bank">بنكي</option><option value="offset">مقاصة فقط</option></select></label><label>الحساب<select name="fund_account_id"><option value="">اختر الحساب</option><?php foreach ($funds as $fund): ?><option value="<?= (int) $fund['id'] ?>"><?= htmlspecialchars((string) $fund['aname'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label><label>ملاحظات<textarea name="notes" rows="2"></textarea></label></div><input type="hidden" name="drawer_session_id" value="<?= $drawerSessionId ?>"><div class="mt-3 d-flex gap-2"><button class="delivery-button" type="button" id="deliveryPreviewSettlement">معاينة</button><button class="delivery-button delivery-button--primary" type="button" id="deliveryFinalizeSettlement" disabled>اعتماد التسوية</button></div></form>
        <div><div id="deliverySettlementPreview" class="delivery-card mb-3"><p class="text-muted mb-0">اختر العامل والفترة لمراجعة الطلبات والمبالغ قبل الاعتماد.</p></div><div class="delivery-card"><h3>سجل التسويات</h3><div class="table-responsive"><table class="delivery-table"><thead><tr><th>العامل</th><th>الفترة</th><th>طلبات</th><th>نتيجة التسوية</th><th>الطريقة</th></tr></thead><tbody id="deliverySettlementsTable"></tbody></table></div></div></div>
      </div></section><?php endif; ?>
    <?php endif; ?>
  </div></section>
</div>
<script>
window.DELIVERY_MANAGEMENT = <?= json_encode(['csrf' => $deliveryCsrf, 'canWorkers' => $canWorkers, 'canPlans' => $canPlans, 'canSettlements' => $canSettlements, 'canReverse' => $canReverse, 'drawerSessionId' => $drawerSessionId], JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php if (!$pendingDeliverySchema): ?><script src="js/delivery_management.js?v=<?= (int) (@filemtime(__DIR__ . '/js/delivery_management.js') ?: 1) ?>"></script><?php endif; ?>
<?php include 'includes/footer.php'; ?>
