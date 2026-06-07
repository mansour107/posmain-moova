<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php require_once __DIR__ . '/includes/csrf.php'; ?>

<?php
// Label maps for display
$gender_labels = ['male' => 'ذكر', 'female' => 'أنثى'];
$age_labels    = ['under18' => 'أقل من 18', '18_25' => '18-25', '25_40' => '25-40', 'over40' => 'أكبر من 40'];
$mode_labels   = ['solo' => 'فردي', 'group' => 'مجموعة'];
$value_labels  = ['under60' => '< 60 جنيه', 'over60' => '> 60 جنيه'];
$type_labels   = ['new' => 'جديد', 'returning' => 'عائد', 'regular' => 'منتظم'];

// Filters
$filter_date   = trim((string)($_GET['date']   ?? ''));
$filter_gender = trim((string)($_GET['gender'] ?? ''));
$filter_type   = trim((string)($_GET['type']   ?? ''));

$where  = "WHERE isdeleted = 0";
$params = [];
$types  = '';

if ($filter_date !== '') {
    $where   .= " AND DATE(created_at) = ?";
    $params[] = $filter_date;
    $types   .= 's';
}
if ($filter_gender !== '' && in_array($filter_gender, ['male', 'female'], true)) {
    $where   .= " AND gender = ?";
    $params[] = $filter_gender;
    $types   .= 's';
}
if ($filter_type !== '' && in_array($filter_type, ['new', 'returning', 'regular'], true)) {
    $where   .= " AND visit_type = ?";
    $params[] = $filter_type;
    $types   .= 's';
}

$sql  = "SELECT * FROM customer_visits $where ORDER BY created_at DESC";
$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$visits = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<div class="container">
  
<div class="card" style="direction:rtl; margin:20px;">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h3 class="card-title">
      <i class="fas fa-user-check me-2"></i>
      سجل زيارات العملاء
    </h3>
    <a href="add_customer_visit.php" class="btn btn-primary btn-sm">
      <i class="fas fa-plus me-1"></i> زيارة جديدة
    </a>
  </div>

  <!-- Filters -->
  <div class="card-body border-bottom pb-3">
    <form method="GET" class="form-inline flex-wrap gap-2">
      <input type="date" name="date" value="<?= htmlspecialchars($filter_date) ?>"
             class="form-control form-control-sm" style="width:160px;">

      <select name="gender" class="form-control form-control-sm" style="width:130px;">
        <option value="">كل الجنس</option>
        <option value="male"   <?= $filter_gender === 'male'   ? 'selected' : '' ?>>ذكر</option>
        <option value="female" <?= $filter_gender === 'female' ? 'selected' : '' ?>>أنثى</option>
      </select>

      <select name="type" class="form-control form-control-sm" style="width:140px;">
        <option value="">كل الأنواع</option>
        <option value="new"       <?= $filter_type === 'new'       ? 'selected' : '' ?>>جديد</option>
        <option value="returning" <?= $filter_type === 'returning' ? 'selected' : '' ?>>عائد</option>
        <option value="regular"   <?= $filter_type === 'regular'   ? 'selected' : '' ?>>منتظم</option>
      </select>

      <button type="submit" class="btn btn-secondary btn-sm">
        <i class="fas fa-filter me-1"></i> فلتر
      </button>
      <a href="customer_visits.php" class="btn btn-outline-secondary btn-sm">مسح</a>
    </form>
  </div>

  <div class="card-body p-0">
    <?php if (isset($_GET['success'])): ?>
      <div class="alert alert-success m-3">
        <?= $_GET['success'] === 'deleted' ? 'تم حذف الزيارة.' : 'تم تسجيل الزيارة بنجاح.' ?>
      </div>
    <?php endif; ?>

    <div class="table-responsive">
      <table class="table table-bordered table-hover table-sm text-center mb-0">
        <thead class="thead-light">
          <tr>
            <th>#</th>
            <th>الجنس</th>
            <th>الفئة العمرية</th>
            <th>نوع الزيارة</th>
            <th>وقت البداية</th>
            <th>وقت النهاية</th>
            <th>قيمة الطلب</th>
            <th>نوع العميل</th>
            <th>التاريخ</th>
            <th>حذف</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($visits)): ?>
            <tr><td colspan="10" class="text-muted py-4">لا توجد زيارات مسجلة.</td></tr>
          <?php else: ?>
            <?php foreach ($visits as $v): ?>
              <tr>
                <td><?= (int)$v['id'] ?></td>
                <td><?= htmlspecialchars($gender_labels[$v['gender']] ?? $v['gender']) ?></td>
                <td><?= htmlspecialchars($age_labels[$v['age_group']] ?? $v['age_group']) ?></td>
                <td><?= htmlspecialchars($mode_labels[$v['mode']] ?? $v['mode']) ?></td>
                <td><?= htmlspecialchars(substr($v['start_time'], 0, 5)) ?></td>
                <td>
                  <input type="time" class="form-control form-control-sm update-end-time" data-id="<?= (int)$v['id'] ?>" value="<?= htmlspecialchars(substr($v['end_time'], 0, 5)) ?>" style="width: 100px; display: inline-block;">
                </td>
                <td><?= htmlspecialchars($value_labels[$v['order_value']] ?? $v['order_value']) ?></td>
                <td><?= htmlspecialchars($type_labels[$v['visit_type']] ?? $v['visit_type']) ?></td>
                <td><?= htmlspecialchars(date('Y-m-d', strtotime($v['created_at']))) ?></td>
                <td>
                  <button type="button"
                          class="btn btn-danger btn-xs delete-visit-btn"
                          data-id="<?= (int)$v['id'] ?>"
                          title="حذف">
                    <i class="fas fa-trash"></i>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
  </div>

  <script>
    $(document).ready(function() {
      const customerVisitCsrf = <?= json_encode(csrf_token('customer_visits'), JSON_UNESCAPED_UNICODE) ?>;

      $('.update-end-time').on('change', function() {
        var id = $(this).data('id');
        var endTime = $(this).val();
        
        $.ajax({
          url: 'ajax/update_customer_visit_end_time.php',
          type: 'POST',
          data: { id: id, end_time: endTime, csrf_token: customerVisitCsrf },
          success: function(response) {
            try {
              var res = typeof response === 'string' ? JSON.parse(response) : response;
              if(!res.success) {
                alert('حدث خطأ أثناء التحديث');
              }
            } catch(e) {
              console.error(e);
            }
          },
          error: function() {
            alert('حدث خطأ أثناء الاتصال بالخادم');
          }
        });
      });

      $('.delete-visit-btn').on('click', function() {
        const id = $(this).data('id');
        if (!id || !confirm('هل تريد حذف هذه الزيارة؟')) {
          return;
        }

        const form = $('<form>', { method: 'POST', action: 'do/dodel_customer_visit.php' });
        form.append($('<input>', { type: 'hidden', name: 'id', value: id }));
        form.append($('<input>', { type: 'hidden', name: 'csrf_token', value: customerVisitCsrf }));
        $('body').append(form);
        form.trigger('submit');
      });
    });
  </script>

<?php include('includes/footer.php') ?>
