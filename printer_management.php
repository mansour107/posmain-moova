<?php

require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/config/app_config.php';
require_once __DIR__ . '/classes/Pos/Service/PrintJobService.php';
require_once __DIR__ . '/classes/Pos/Service/PrinterRoutingService.php';
require_once __DIR__ . '/classes/Pos/Service/SilentPrintDispatchService.php';
require_once __DIR__ . '/classes/Pos/Service/PrintBridgeClient.php';
require_once __DIR__ . '/classes/Pos/Service/PrintUserMessageService.php';

require_permission('printers.manage', $conn);

$appConfig = posmain_app_config();
$scope = [
    'tenant' => max(0, (int) ($_SESSION['pos_tenant'] ?? $appConfig['branch']['pos_tenant'] ?? 0)),
    'branch' => max(0, (int) ($_SESSION['pos_branch'] ?? $appConfig['branch']['pos_branch'] ?? 0)),
];
$userId = isset($_SESSION['userid']) ? (int) $_SESSION['userid'] : null;
$jobs = new PrintJobService();
$routing = new PrinterRoutingService($jobs);
$dispatch = new SilentPrintDispatchService(null, $jobs, $routing);
$notice = null;
$error = null;

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    try {
        require_csrf('printer_manage');
        $action = trim((string) ($_POST['action'] ?? 'save'));
        if ($action === 'save') {
            $connectionType = strtolower(trim((string) ($_POST['connection_type'] ?? 'network')));
            if (!in_array($connectionType, ['network', 'usb'], true)) {
                throw new InvalidArgumentException('PRINT_CONNECTION_INVALID');
            }
            $config = $routing->buildPrinterConfig([
                'connection_type' => $connectionType,
                'paper_width' => $_POST['paper_width'] ?? 80,
                'functions' => $_POST['functions'] ?? [],
                'all_categories' => isset($_POST['all_categories']),
                'category_ids' => $_POST['category_ids'] ?? [],
                'host' => $_POST['host'] ?? '',
                'port' => $_POST['port'] ?? 9100,
                'queue_name' => $_POST['queue_name'] ?? '',
            ]);
            $saved = $jobs->savePrinter($conn, [
                'id' => $_POST['id'] ?? null,
                'name' => $_POST['name'] ?? '',
                'printer_type' => $_POST['printer_type'] ?? 'other',
                'connection_type' => $connectionType,
                'config' => $config,
                'tenant' => $scope['tenant'],
                'branch' => $scope['branch'],
                'is_active' => isset($_POST['is_active']),
            ]);
            $notice = 'تم حفظ إعدادات الطابعة «' . $saved['name'] . '».';
        } elseif ($action === 'test') {
            $result = $dispatch->testPrinter(
                $conn,
                (int) ($_POST['printer_id'] ?? 0),
                'admin-test:' . bin2hex(random_bytes(12)),
                $scope,
                $userId
            );
            $notice = $result['status'] === 'printed'
                ? 'نجح اختبار التسليم وحُفظ إثبات الطباعة.'
                : 'حُفظ الاختبار، لكنه يحتاج متابعة من قائمة المهام.';
        } elseif ($action === 'retry') {
            $retried = $dispatch->retryFailedJob(
                $conn,
                (int) ($_POST['job_id'] ?? 0),
                $scope,
                isset($_POST['physical_output_checked'])
            );
            $notice = $retried['status'] === 'printed'
                ? 'نجحت إعادة المحاولة.'
                : 'أُعيدت المهمة إلى مسار التسليم، وما زالت تحتاج متابعة.';
        } else {
            throw new InvalidArgumentException('PRINT_ACTION_INVALID');
        }
    } catch (Throwable $exception) {
        PrintUserMessageService::log($exception, 'printer-management-action');
        $error = PrintUserMessageService::forException($exception);
    }
}

$printers = [];
$recentJobs = [];
try {
    $printers = $jobs->listPrinters($conn, $scope);
    if ($jobs->supportsReliableDelivery($conn)) {
        $recentJobs = $jobs->recentJobs($conn, $scope, 30);
    }
} catch (Throwable $loadError) {
    PrintUserMessageService::log($loadError, 'printer-management-load');
    $error = $error ?: PrintUserMessageService::forException($loadError);
}

$cablePrinters = [];
$bridgeAvailable = false;
try {
    $bridge = new PrintBridgeClient();
    if ($bridge->isConfigured()) {
        $cablePrinters = $bridge->printers();
        $bridgeAvailable = true;
    }
} catch (Throwable $bridgeError) {
    PrintUserMessageService::log($bridgeError, 'printer-management-bridge-list');
}

$categories = [];
$categoryResult = $conn->query("
    SELECT id, gname
    FROM item_group
    WHERE COALESCE(isdeleted, 0) = 0
    ORDER BY gname, id
");
if ($categoryResult) {
    while ($row = $categoryResult->fetch_assoc()) {
        $categories[] = ['id' => (int) $row['id'], 'name' => (string) $row['gname']];
    }
}

$functionLabels = [
    'receipt' => 'إيصالات العملاء',
    'kot' => 'طلبات المطبخ حسب التصنيف',
    'report' => 'تقارير الورديات والمبيعات',
    'label' => 'الملصقات والباركود',
    'document' => 'مستندات أخرى',
];
$connectionLabels = ['network' => 'طابعة شبكة', 'usb' => 'طابعة متصلة بالكابل', 'browser' => 'إعداد متصفح قديم', 'file' => 'إعداد اختبار قديم'];
$printerTypeLabels = ['receipt' => 'إيصالات', 'kitchen' => 'مطبخ', 'label' => 'ملصقات', 'other' => 'أخرى'];
$jobTypeLabels = ['receipt' => 'إيصال عميل', 'kot' => 'طلب مطبخ', 'kitchen' => 'طلب مطبخ', 'report' => 'تقرير', 'z_report' => 'تقرير إغلاق', 'x_report' => 'تقرير متابعة', 'label' => 'ملصق', 'document' => 'مستند'];
$jobStatusLabels = ['queued' => 'في الانتظار', 'processing' => 'جارٍ الإرسال', 'printed' => 'تمت الطباعة', 'failed' => 'تعذر التسليم', 'cancelled' => 'ملغاة'];
$csrfInput = csrf_input('printer_manage');
$mode = (string) ($appConfig['printing']['mode'] ?? 'legacy');

function printerManagementH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

include('includes/header.php');
include('includes/navbar.php');
include('includes/sidebar.php');
?>
<link rel="stylesheet" href="css/printer-management.css?v=<?= (int) (@filemtime(__DIR__ . '/css/printer-management.css') ?: 1) ?>">
<main class="content-wrapper printer-shell">
  <section class="content">
    <div class="container-fluid">
      <header class="printer-hero">
        <div>
          <span class="printer-kicker">مركز الطباعة</span>
          <h1>الطابعات ومسارات الطلبات</h1>
          <p>اربط كل وظيفة بالطابعة المناسبة، وقسّم طلب المطبخ حسب تصنيفات الطعام والمشروبات.</p>
        </div>
        <div class="printer-mode <?= $mode === 'silent' ? 'is-live' : '' ?>">
          <span>وضع التشغيل الحالي</span>
          <strong><?= $mode === 'silent' ? 'طباعة صامتة' : 'طباعة المتصفح القديمة' ?></strong>
          <small>يُحدد أثناء إعداد النظام ويمكن الرجوع للوضع القديم عند الحاجة.</small>
        </div>
      </header>

      <?php if ($notice): ?>
        <div class="printer-alert is-success"><i class="fas fa-check-circle"></i><?= printerManagementH($notice) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="printer-alert is-error"><i class="fas fa-exclamation-circle"></i><?= printerManagementH($error) ?></div>
      <?php endif; ?>

      <div class="printer-layout">
        <section class="printer-panel printer-editor">
          <div class="printer-panel-head">
            <div><span>إعداد سريع</span><h2 id="printerFormTitle">إضافة طابعة</h2></div>
            <button type="button" class="printer-link is-hidden" id="printerFormReset">إلغاء التعديل</button>
          </div>
          <form method="post" id="printerForm" class="printer-form">
            <?= $csrfInput ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="printerId" value="">

            <label>اسم واضح للطابعة
              <input type="text" name="name" id="printerName" maxlength="120" placeholder="مثال: مطبخ المشروبات" required>
            </label>

            <div class="printer-two">
              <label>الدور
                <select name="printer_type" id="printerType">
                  <option value="receipt">إيصالات</option>
                  <option value="kitchen">مطبخ</option>
                  <option value="label">ملصقات</option>
                  <option value="other">أخرى</option>
                </select>
              </label>
              <label>طريقة الاتصال
                <select name="connection_type" id="printerConnection">
                  <option value="network">طابعة على الشبكة</option>
                  <option value="usb">طابعة متصلة بالكابل</option>
                </select>
              </label>
            </div>

            <div class="printer-transport printer-two is-visible" data-transport="network">
              <label>عنوان الطابعة
                <input type="text" name="host" id="printerHost" maxlength="253" placeholder="192.168.1.50">
              </label>
              <label>المنفذ
                <input type="number" name="port" id="printerPort" min="1" max="65535" value="9100">
              </label>
            </div>
            <div class="printer-transport" data-transport="usb">
              <label>الطابعة المثبتة على الجهاز
                <select name="queue_name" id="printerQueue">
                  <option value="">اختر طابعة</option>
                  <?php foreach ($cablePrinters as $cablePrinter): ?>
                    <option value="<?= printerManagementH($cablePrinter['queue'] ?? '') ?>"><?= printerManagementH($cablePrinter['name'] ?? $cablePrinter['queue'] ?? '') ?><?= empty($cablePrinter['connected']) ? ' — غير متصلة' : ' — متصلة' ?></option>
                  <?php endforeach; ?>
                </select>
                <small><?= $bridgeAvailable ? 'تعرض القائمة الطابعات المثبتة فعلياً في إعدادات هذا الجهاز.' : 'خدمة الطباعة المحلية متوقفة. شغّلها لتظهر الطابعات المتصلة بالجهاز.' ?></small>
              </label>
            </div>

            <fieldset>
              <legend>تُستخدم هذه الطابعة لـ</legend>
              <div class="printer-check-grid">
                <?php foreach ($functionLabels as $value => $label): ?>
                  <label class="printer-check"><input type="checkbox" name="functions[]" value="<?= $value ?>"><span><?= printerManagementH($label) ?></span></label>
                <?php endforeach; ?>
              </div>
            </fieldset>

            <fieldset class="printer-categories" id="printerCategories">
              <legend>تصنيفات المطبخ</legend>
              <label class="printer-check printer-check-all"><input type="checkbox" name="all_categories" id="printerAllCategories" value="1"><span>كل التصنيفات</span></label>
              <div class="printer-category-grid">
                <?php foreach ($categories as $category): ?>
                  <label class="printer-check">
                    <input type="checkbox" name="category_ids[]" value="<?= $category['id'] ?>">
                    <span><?= printerManagementH($category['name']) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <small>كل سطر مطبخ يجب أن يطابق مساراً. النظام يرفض الطباعة إذا بقي صنف بلا طابعة.</small>
            </fieldset>

            <div class="printer-two">
              <label>عرض الورق
                <select name="paper_width" id="printerPaperWidth"><option value="80">80 مم</option><option value="58">58 مم</option></select>
              </label>
              <label class="printer-toggle"><span><strong>نشطة</strong><small>تستقبل المهام الجديدة</small></span><input type="checkbox" name="is_active" id="printerActive" value="1" checked><i></i></label>
            </div>

            <button class="printer-primary" type="submit"><i class="fas fa-check"></i> حفظ الطابعة والمسارات</button>
          </form>
        </section>

        <section class="printer-panel">
          <div class="printer-panel-head">
            <div><span>الأجهزة الحالية</span><h2><?= count($printers) ?> طابعة</h2></div>
          </div>
          <div class="printer-list">
            <?php if (!$printers): ?>
              <div class="printer-empty"><i class="fas fa-print"></i><h3>لا توجد طابعات بعد</h3><p>أضف طابعة الإيصالات، ثم أضف طابعات المطبخ واربط كل واحدة بتصنيفاتها.</p></div>
            <?php else: foreach ($printers as $printer):
              $printerRouting = $routing->normalizeRouting($printer['config']['routing'] ?? []);
              $encoded = base64_encode(json_encode($printer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            ?>
              <article class="printer-card <?= $printer['is_active'] ? '' : 'is-disabled' ?>" data-health-url="ajax/printer_health.php?printer_id=<?= (int) $printer['id'] ?>">
                <div class="printer-card-top">
                  <div class="printer-icon"><i class="fas fa-print"></i></div>
                  <div><h3><?= printerManagementH($printer['name']) ?></h3><p><?= printerManagementH($connectionLabels[$printer['connection_type']] ?? 'إعداد قديم') ?> · <?= printerManagementH($printerTypeLabels[$printer['printer_type']] ?? 'أخرى') ?></p></div>
                  <span class="printer-status <?= $printer['is_active'] ? 'is-checking' : 'is-offline' ?>"><?= $printer['is_active'] ? 'جارٍ التحقق' : 'متوقفة' ?></span>
                </div>
                <p class="printer-health-guidance"><?= $printer['is_active'] ? 'يتم الآن التحقق من الاتصال.' : 'هذه الطابعة لا تستقبل مهام جديدة.' ?></p>
                <div class="printer-tags">
                  <?php foreach ($printerRouting['functions'] as $function): ?><span><?= printerManagementH($functionLabels[$function] ?? $function) ?></span><?php endforeach; ?>
                </div>
                <div class="printer-card-actions">
                  <button type="button" class="printer-secondary printer-edit" data-printer="<?= printerManagementH($encoded) ?>"><i class="fas fa-pen"></i> تعديل</button>
                  <?php if ($printer['is_active'] && in_array($printer['connection_type'], ['network', 'usb'], true)): ?>
                    <form method="post"><?= $csrfInput ?><input type="hidden" name="action" value="test"><input type="hidden" name="printer_id" value="<?= $printer['id'] ?>"><button class="printer-secondary" type="submit"><i class="fas fa-vial"></i> اختبار</button></form>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; endif; ?>
          </div>
        </section>
      </div>

      <section class="printer-panel printer-jobs">
        <div class="printer-panel-head"><div><span>آخر عمليات التسليم</span><h2>سجل الطباعة</h2></div></div>
        <div class="table-responsive">
          <table>
            <thead><tr><th>المهمة</th><th>النوع</th><th>الحالة</th><th>المحاولات</th><th>الوقت</th><th></th></tr></thead>
            <tbody>
              <?php if (!$recentJobs): ?><tr><td colspan="6" class="printer-table-empty">لا توجد مهام مسجلة.</td></tr>
              <?php else: foreach ($recentJobs as $job): ?>
                <tr>
                  <td>#<?= $job['id'] ?></td>
                  <td><?= printerManagementH($jobTypeLabels[$job['job_type']] ?? 'مستند') ?></td>
                  <td><span class="job-status is-<?= printerManagementH($job['status']) ?>"><?= printerManagementH($jobStatusLabels[$job['status']] ?? 'حالة غير معروفة') ?></span><?php if ($job['last_error']): ?><small><?= printerManagementH(PrintUserMessageService::forCode((string) $job['last_error'])) ?></small><?php endif; ?></td>
                  <td><?= $job['attempts'] ?> / <?= $job['max_attempts'] ?></td>
                  <td><?= printerManagementH($job['created_at']) ?></td>
                  <td><?php if ($job['status'] === 'failed'):
                    $diagnosticCode = PrintUserMessageService::code((string) ($job['last_error'] ?? ''));
                    $uncertainDelivery = in_array($diagnosticCode, ['PRINT_NETWORK_DELIVERY_UNCERTAIN', 'PRINT_BRIDGE_DELIVERY_UNCERTAIN'], true);
                  ?><form method="post"><?= $csrfInput ?><input type="hidden" name="action" value="retry"><input type="hidden" name="job_id" value="<?= $job['id'] ?>"><?php if ($uncertainDelivery): ?><label class="printer-retry-check"><input type="checkbox" name="physical_output_checked" value="1" required><span>راجعت الورق ولم تُطبع</span></label><?php endif; ?><button class="printer-link" type="submit">إعادة المحاولة</button></form><?php endif; ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </section>
</main>
<script>
(function () {
  'use strict';
  var form = document.getElementById('printerForm');
  var connection = document.getElementById('printerConnection');
  var reset = document.getElementById('printerFormReset');
  var title = document.getElementById('printerFormTitle');

  function decodePrinter(encoded) {
    var bytes = Uint8Array.from(atob(encoded), function (character) {
      return character.charCodeAt(0);
    });
    return JSON.parse(new TextDecoder('utf-8').decode(bytes));
  }

  function updateTransport() {
    document.querySelectorAll('.printer-transport').forEach(function (panel) {
      panel.classList.toggle('is-visible', panel.dataset.transport === connection.value);
    });
  }
  function resetForm() {
    form.reset();
    document.getElementById('printerId').value = '';
    document.getElementById('printerActive').checked = true;
    title.textContent = 'إضافة طابعة';
    reset.classList.add('is-hidden');
    updateTransport();
  }
  connection.addEventListener('change', updateTransport);
  reset.addEventListener('click', resetForm);
  document.addEventListener('click', function (event) {
    var button = event.target.closest('.printer-edit');
    if (!button) return;
    var printer = decodePrinter(button.dataset.printer);
    var config = printer.config || {};
    var route = config.routing || {};
    form.reset();
    document.getElementById('printerId').value = printer.id;
    document.getElementById('printerName').value = printer.name || '';
    document.getElementById('printerType').value = printer.printer_type || 'other';
    connection.value = ['network', 'usb'].indexOf(printer.connection_type) !== -1 ? printer.connection_type : 'network';
    document.getElementById('printerHost').value = config.host || '';
    document.getElementById('printerPort').value = config.port || 9100;
    var queue = document.getElementById('printerQueue');
    var queueName = config.queue_name || '';
    if (queueName && !Array.from(queue.options).some(function (option) { return option.value === queueName; })) {
      queue.add(new Option(queueName + ' — غير متاحة حالياً', queueName));
    }
    queue.value = queueName;
    document.getElementById('printerPaperWidth').value = String(config.paper_width || 80);
    document.getElementById('printerActive').checked = printer.is_active === true;
    document.getElementById('printerAllCategories').checked = route.all_categories === true;
    form.querySelectorAll('[name="functions[]"]').forEach(function (input) {
      input.checked = (route.functions || []).indexOf(input.value) !== -1;
    });
    form.querySelectorAll('[name="category_ids[]"]').forEach(function (input) {
      input.checked = (route.category_ids || []).map(String).indexOf(input.value) !== -1;
    });
    title.textContent = 'تعديل ' + printer.name;
    reset.classList.remove('is-hidden');
    updateTransport();
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
  document.querySelectorAll('.printer-card[data-health-url]').forEach(function (card) {
    var status = card.querySelector('.printer-status');
    var guidance = card.querySelector('.printer-health-guidance');
    if (!status || status.textContent.trim() === 'متوقفة') return;
    window.fetch(card.dataset.healthUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (!payload.success || !payload.health) throw new Error(payload.message || 'تعذر التحقق من حالة الطابعة.');
        status.textContent = payload.health.label;
        status.classList.remove('is-checking', 'is-connected', 'is-offline');
        status.classList.add(payload.health.connected ? 'is-connected' : 'is-offline');
        guidance.textContent = payload.health.guidance;
      })
      .catch(function (error) {
        status.textContent = 'تعذر التحقق';
        status.classList.remove('is-checking', 'is-connected');
        status.classList.add('is-offline');
        guidance.textContent = error.message || 'حدّث الصفحة، وإذا استمرت المشكلة تواصل مع المسؤول.';
      });
  });
  updateTransport();
}());
</script>
<?php include('includes/footer.php'); ?>
