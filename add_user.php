<?php
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
include('includes/connect.php');
require_admin_or_permission('users.manage', $conn);
require_once __DIR__ . '/classes/Security/RolePermissionSyncService.php';
RolePermissionSyncService::seedPresetRoles($conn);
$presetRoles = [];
$roleStmt = $conn->query("SELECT id, rollname, role_key, is_system FROM usr_pwrs WHERE COALESCE(isdeleted,0)!=1 AND role_key IN ('owner','manager','cashier','waiter','kitchen') ORDER BY FIELD(role_key,'owner','manager','cashier','waiter','kitchen')");
if ($roleStmt) {
    while ($r = $roleStmt->fetch_assoc()) {
        $presetRoles[] = $r;
    }
}
$customRoles = [];
$customStmt = $conn->query("SELECT id, rollname FROM usr_pwrs WHERE COALESCE(isdeleted,0)!=1 AND (role_key IS NULL OR role_key = '' OR role_key NOT IN ('owner','manager','cashier','waiter','kitchen')) ORDER BY id");
if ($customStmt) {
    while ($r = $customStmt->fetch_assoc()) {
        $customRoles[] = $r;
    }
}
include('includes/header.php');
include('includes/sidebar.php');
include('includes/navbar.php');
$pinReveal = $_SESSION['posmain_one_time_pin_reveal'] ?? null;
?>
<style>
.role-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: .75rem; }
.role-card { border: 2px solid #e2e8f0; border-radius: 12px; padding: .75rem; cursor: pointer; text-align: center; transition: .15s; }
.role-card.selected { border-color: #6366f1; background: #eef2ff; }
.role-card input { display: none; }
.pin-reveal-box { background: #fef3c7; border: 1px solid #fcd34d; border-radius: 10px; padding: 1rem; }
</style>
<div class="content-wrapper p-4" dir="rtl">
  <?php if (is_array($pinReveal) && (int) ($pinReveal['expires'] ?? 0) > time()): ?>
  <div class="alert alert-warning pin-reveal-box mb-3">
    <strong>رمز PIN لمرة واحدة:</strong>
    <span dir="ltr" style="font-size:1.4rem;letter-spacing:.2em"><?= htmlspecialchars((string) ($pinReveal['pin'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
    <div class="small text-muted mt-1">انسخ الرمز الآن — لن يُعرض مرة أخرى.</div>
  </div>
  <?php unset($_SESSION['posmain_one_time_pin_reveal']); endif; ?>
  <div class="card card-primary col-lg-6">
    <div class="card-header"><h3 class="card-title"><?= $lang_add_new_user ?? 'إضافة مستخدم' ?></h3></div>
    <form role="form" action="do/doadd_user.php" method="post" autocomplete="off" enctype="multipart/form-data" id="addUserForm">
      <?= csrf_input('users_write') ?>
      <div class="card-body">
        <div class="form-group">
          <label><?= $lang_username ?? 'اسم المستخدم' ?></label>
          <input required name="uname" type="text" class="form-control" placeholder="<?= $lang_pbholder_uname ?? '' ?>">
        </div>
        <div class="form-group">
          <label>الاسم المعروض</label>
          <input name="display_name" type="text" class="form-control" placeholder="اسم الموظف على الشاشة">
        </div>
        <div class="form-group">
          <label>الهاتف</label>
          <input name="phone" type="text" class="form-control" placeholder="05xxxxxxxx">
        </div>
        <div class="form-group">
          <label>الدور</label>
          <div class="role-cards" id="roleCards">
            <?php foreach ($presetRoles as $pr): ?>
              <label class="role-card" data-role-id="<?= (int) $pr['id'] ?>">
                <input type="radio" name="userrole" value="<?= (int) $pr['id'] ?>" <?= ($pr['role_key'] ?? '') === 'cashier' ? 'checked' : '' ?>>
                <strong><?= htmlspecialchars($pr['rollname']) ?></strong>
                <div class="small text-muted"><?= htmlspecialchars((string) ($pr['role_key'] ?? '')) ?></div>
              </label>
            <?php endforeach; ?>
          </div>
          <details class="mt-2">
            <summary class="text-muted">دور مخصص</summary>
            <select name="userrole_custom" class="form-control mt-2" id="customRoleSelect">
              <option value="">— اختر —</option>
              <?php foreach ($customRoles as $cr): ?>
                <option value="<?= (int) $cr['id'] ?>"><?= htmlspecialchars($cr['rollname']) ?></option>
              <?php endforeach; ?>
            </select>
          </details>
        </div>
        <div class="form-group">
          <label>رمز PIN</label>
          <div class="input-group">
            <input name="pin" id="userPinInput" type="password" inputmode="numeric" class="form-control" maxlength="6" autocomplete="new-password" placeholder="4-6 أرقام">
            <div class="input-group-append">
              <button type="button" class="btn btn-outline-secondary" id="regeneratePinBtn">توليد</button>
            </div>
          </div>
          <input type="hidden" name="generate_pin" id="generatePinFlag" value="0">
          <small id="pinAvailabilityMsg" class="form-text"></small>
        </div>
        <div class="form-group">
          <label><?= $lang_password ?? 'كلمة المرور' ?></label>
          <input name="password" type="password" class="form-control" placeholder="<?= $lang_pbholder_password ?? '' ?>">
        </div>
        <div class="custom-control custom-switch">
          <input type="checkbox" class="custom-control-input" id="is_waiter" name="is_waiter" value="1">
          <label class="custom-control-label" for="is_waiter">ويتر (باركود POS)</label>
        </div>
        <details class="mt-3">
          <summary class="text-muted">صلاحيات متقدمة (بعد الإنشاء)</summary>
          <p class="small text-muted mt-2 mb-0">بعد حفظ المستخدم يمكن ضبط تجاوزات الصلاحيات من صفحة التعديل أو <a href="role_permissions.php">مصفوفة الأدوار</a>.</p>
        </details>
        <div class="mt-3">
          <label class="btn btn-outline-secondary">صورة<input hidden type="file" name="img"></label>
        </div>
      </div>
      <div class="card-footer">
        <button type="submit" class="btn btn-primary"><?= $lang_publicconfirm ?? 'حفظ' ?></button>
      </div>
    </form>
  </div>
</div>
<script>
(function () {
  const cards = document.querySelectorAll('.role-card');
  const customSelect = document.getElementById('customRoleSelect');
  function selectCard(card) {
    cards.forEach(function (c) { c.classList.remove('selected'); });
    if (card) { card.classList.add('selected'); }
    if (customSelect) { customSelect.value = ''; }
  }
  cards.forEach(function (card) {
    if (card.querySelector('input:checked')) { card.classList.add('selected'); }
    card.addEventListener('click', function () {
      const radio = card.querySelector('input[type=radio]');
      if (radio) { radio.checked = true; selectCard(card); }
    });
  });
  if (customSelect) {
    customSelect.addEventListener('change', function () {
      if (!customSelect.value) { return; }
      cards.forEach(function (c) {
        c.classList.remove('selected');
        c.querySelector('input').checked = false;
      });
      let hidden = document.getElementById('customRoleRadio');
      if (!hidden) {
        hidden = document.createElement('input');
        hidden.type = 'radio';
        hidden.name = 'userrole';
        hidden.id = 'customRoleRadio';
        hidden.style.display = 'none';
        document.getElementById('addUserForm').appendChild(hidden);
      }
      hidden.value = customSelect.value;
      hidden.checked = true;
    });
  }
  function randomPin() {
    let p = String(Math.floor(1000 + Math.random() * 9000));
    while (['1234','0000','1111'].indexOf(p) >= 0) {
      p = String(Math.floor(1000 + Math.random() * 9000));
    }
    return p;
  }
  const pinInput = document.getElementById('userPinInput');
  const pinMsg = document.getElementById('pinAvailabilityMsg');
  const genFlag = document.getElementById('generatePinFlag');
  document.getElementById('regeneratePinBtn').addEventListener('click', function () {
    const p = randomPin();
    pinInput.value = p;
    pinInput.type = 'text';
    genFlag.value = '1';
    checkPinAvailable(p);
  });
  let pinTimer = null;
  function checkPinAvailable(pin) {
    if (!pin || pin.length < 4) { pinMsg.textContent = ''; return; }
    clearTimeout(pinTimer);
    pinTimer = setTimeout(function () {
      fetch('ajax/pin_available.php?pin=' + encodeURIComponent(pin), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j.available === true) {
            pinMsg.textContent = '✓ الرمز متاح';
            pinMsg.className = 'form-text text-success';
          } else if (j.available === false) {
            pinMsg.textContent = '✗ الرمز مستخدم أو غير صالح';
            pinMsg.className = 'form-text text-danger';
          }
        });
    }, 300);
  }
  pinInput.addEventListener('input', function () { checkPinAvailable(pinInput.value); genFlag.value = '0'; });
})();
</script>
<?php include('includes/footer.php'); ?>
