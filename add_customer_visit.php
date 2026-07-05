<?php
require_once __DIR__ . '/includes/auth_guard.php';
include __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/page_guard.php';
page_guard('reports.view', $conn);
?>
<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php require_once __DIR__ . '/includes/csrf.php'; ?>

<style>
.visit-form {
  direction: rtl;
  max-width: 680px;
  margin: 20px auto;
  padding: 0 12px;
}
.visit-form .section-label {
  font-size: 0.8rem;
  font-weight: 700;
  color: #6b7280;
  text-transform: uppercase;
  letter-spacing: .05em;
  margin-bottom: 8px;
}
.btn-group-touch {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 20px;
}
.btn-touch {
  flex: 1 1 calc(50% - 10px);
  min-width: 120px;
  padding: 18px 10px;
  font-size: 1.05rem;
  font-weight: 600;
  border-radius: 14px;
  border: 2.5px solid #d1d5db;
  background: #fff;
  color: #374151;
  cursor: pointer;
  transition: all .15s ease;
  text-align: center;
  user-select: none;
  -webkit-tap-highlight-color: transparent;
}
.btn-touch i {
  display: block;
  font-size: 1.5rem;
  margin-bottom: 6px;
  color: #9ca3af;
  transition: color .15s;
}
.btn-touch.selected {
  border-color: #3b82f6;
  background: #eff6ff;
  color: #1d4ed8;
}
.btn-touch.selected i {
  color: #3b82f6;
}
.btn-touch:active {
  transform: scale(0.97);
}
/* 4-col for age */
.btn-group-touch.four .btn-touch {
  flex: 1 1 calc(25% - 10px);
  min-width: 100px;
}
/* submit */
.btn-submit-touch {
  width: 100%;
  padding: 20px;
  font-size: 1.2rem;
  font-weight: 700;
  border-radius: 16px;
  border: none;
  background: #3b82f6;
  color: #fff;
  cursor: pointer;
  margin-top: 8px;
  transition: background .15s, transform .1s;
  -webkit-tap-highlight-color: transparent;
}
.btn-submit-touch:active { transform: scale(0.98); background: #2563eb; }
.btn-submit-touch:disabled { background: #93c5fd; cursor: not-allowed; }

/* success toast */
#toast {
  display: none;
  position: fixed;
  bottom: 30px;
  left: 50%;
  transform: translateX(-50%);
  background: #22c55e;
  color: #fff;
  padding: 16px 36px;
  border-radius: 50px;
  font-size: 1.1rem;
  font-weight: 600;
  z-index: 9999;
  box-shadow: 0 4px 20px rgba(0,0,0,.15);
  white-space: nowrap;
}
</style>

<div class="visit-form">
  <h4 style="font-weight:700; margin-bottom:24px;">
    <i class="fas fa-user-check me-2" style="color:#3b82f6;"></i> تسجيل زيارة عميل
  </h4>

  <!-- Gender -->
  <div class="section-label">الجنس</div>
  <div class="btn-group-touch">
    <button type="button" class="btn-touch" data-group="gender" data-value="male">
      <i class="fas fa-mars"></i> ذكر
    </button>
    <button type="button" class="btn-touch" data-group="gender" data-value="female">
      <i class="fas fa-venus"></i> أنثى
    </button>
  </div>

  <!-- Age -->
  <div class="section-label">الفئة العمرية</div>
  <div class="btn-group-touch four">
    <button type="button" class="btn-touch" data-group="age_group" data-value="under18">
      <i class="fas fa-child"></i> &lt;18
    </button>
    <button type="button" class="btn-touch" data-group="age_group" data-value="18_25">
      <i class="fas fa-user"></i> 18-25
    </button>
    <button type="button" class="btn-touch" data-group="age_group" data-value="25_40">
      <i class="fas fa-user-tie"></i> 25-40
    </button>
    <button type="button" class="btn-touch" data-group="age_group" data-value="over40">
      <i class="fas fa-user-clock"></i> &gt;40
    </button>
  </div>

  <!-- Mode -->
  <div class="section-label">نوع الزيارة</div>
  <div class="btn-group-touch">
    <button type="button" class="btn-touch" data-group="mode" data-value="solo">
      <i class="fas fa-user"></i> فردي
    </button>
    <button type="button" class="btn-touch" data-group="mode" data-value="group">
      <i class="fas fa-users"></i> مجموعة
    </button>
  </div>

  <!-- Order Value -->
  <div class="section-label">قيمة الطلب</div>
  <div class="btn-group-touch">
    <button type="button" class="btn-touch" data-group="order_value" data-value="under60">
      <i class="fas fa-tag"></i> أقل من 60 جنيه
    </button>
    <button type="button" class="btn-touch" data-group="order_value" data-value="over60">
      <i class="fas fa-tags"></i> أكثر من 60 جنيه
    </button>
  </div>

  <!-- Type -->
  <div class="section-label">نوع العميل</div>
  <div class="btn-group-touch">
    <button type="button" class="btn-touch" data-group="type" data-value="new">
      <i class="fas fa-star"></i> جديد
    </button>
    <button type="button" class="btn-touch" data-group="type" data-value="returning">
      <i class="fas fa-redo"></i> عائد
    </button>
    <button type="button" class="btn-touch" data-group="type" data-value="regular">
      <i class="fas fa-heart"></i> منتظم
    </button>
  </div>

  <button class="btn-submit-touch" id="submitBtn" disabled>
    <i class="fas fa-save me-2"></i> حفظ الزيارة
  </button>
</div>

<div id="toast"><i class="fas fa-check-circle me-2"></i> تم تسجيل الزيارة بنجاح</div>

<script>
(function () {
  const selections = { gender: null, age_group: null, mode: null, order_value: null, type: null };
  const required   = Object.keys(selections);

  document.querySelectorAll('.btn-touch').forEach(btn => {
    btn.addEventListener('click', function () {
      const group = this.dataset.group;
      // deselect siblings
      document.querySelectorAll(`.btn-touch[data-group="${group}"]`).forEach(b => b.classList.remove('selected'));
      this.classList.add('selected');
      selections[group] = this.dataset.value;
      checkReady();
    });
  });

  function checkReady() {
    const ready = required.every(k => selections[k] !== null);
    document.getElementById('submitBtn').disabled = !ready;
  }

  document.getElementById('submitBtn').addEventListener('click', function () {
    this.disabled = true;
    const fd = new FormData();
    required.forEach(k => fd.append(k, selections[k]));
    fd.append('csrf_token', <?= json_encode(csrf_token('customer_visits'), JSON_UNESCAPED_UNICODE) ?>);

    fetch('do/doadd_customer_visit.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          showToast();
          // reset
          document.querySelectorAll('.btn-touch').forEach(b => b.classList.remove('selected'));
          required.forEach(k => selections[k] = null);
        }
      })
      .catch(() => { this.disabled = false; });
  });

  function showToast() {
    const t = document.getElementById('toast');
    t.style.display = 'block';
    setTimeout(() => { t.style.display = 'none'; }, 2500);
  }
})();
</script>

<?php include('includes/footer.php') ?>
