<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/db_bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/page_guard.php';
require_once __DIR__ . '/config/app_config.php';

try {
    $conn = posmain_db_connect();
} catch (Throwable $e) {
    header('Location: index.php');
    exit;
}

if (!auth_guard_is_logged_in()) {
    header('Location: index.php');
    exit;
}
// Authenticated self-service / bootstrap PIN change (manifest permission null).
page_guard(null, $conn);

$isBootstrap = !empty($_SESSION['posmain_bootstrap_pending']) || isset($_GET['bootstrap']);
$mustChange = !empty($_SESSION['posmain_pin_must_change']) || $isBootstrap;
if (!$mustChange && empty($_GET['force'])) {
    // Optional self-service change still allowed when logged in.
}

$csrf = csrf_token('change_pin');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>تغيير رمز الدخول</title>
  <link rel="icon" href="assets/favicon/favicon.png" type="image/png">
  <link rel="stylesheet" href="assets/fonts/fonts.css">
  <?php include __DIR__ . '/includes/pin_pad_styles.php'; ?>
  <?= csrf_meta_tag('change_pin', 'change-pin-csrf-token') ?>
  <style>
    .ppm-steps { display:flex; justify-content:center; gap:.5rem; margin:0 0 1rem; }
    .ppm-step {
      width:10px; height:10px; border-radius:50%; background:#cbd5e1;
    }
    .ppm-step.is-active { background:#942C21; }
    .ppm-hint { text-align:center; color:#64748b; font-size:.9rem; margin-top:1rem; }
  </style>
</head>
<body class="ppm-page">
  <div class="ppm-shell">
    <?php if ($isBootstrap): ?>
      <div class="ppm-banner" style="position:relative;border-radius:16px;margin-bottom:1rem;">
        <div>لأمان متجرك: أنشئ رمز المالك الجديد الآن. الرمز الابتدائي 0000 لن يعمل بعد التغيير.</div>
      </div>
    <?php endif; ?>
    <div class="ppm-card">
      <h1 class="ppm-title" id="changePinTitle">تغيير الرمز</h1>
      <p class="ppm-sub" id="changePinSub">أدخل الرمز الحالي</p>
      <div class="ppm-steps" aria-hidden="true">
        <span class="ppm-step is-active" id="step1"></span>
        <span class="ppm-step" id="step2"></span>
        <span class="ppm-step" id="step3"></span>
      </div>
      <div class="ppm-error is-hidden" id="changePinError" role="alert"></div>
      <div class="ppm-dots" id="changePinDots" dir="ltr" aria-hidden="true">
        <span class="ppm-dot"></span><span class="ppm-dot"></span><span class="ppm-dot"></span><span class="ppm-dot"></span>
      </div>
      <div class="ppm-grid" id="changePinGrid">
        <?php foreach (['1','2','3','4','5','6','7','8','9','مسح','0','دخول'] as $key):
          $class = 'ppm-key' . ($key === 'مسح' ? ' action' : ($key === 'دخول' ? ' enter' : ''));
        ?>
          <button type="button" class="<?= $class ?>" data-key="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?></button>
        <?php endforeach; ?>
      </div>
      <p class="ppm-hint">استخدم 4 أرقام غير متسلسلة أو مكررة</p>
    </div>
  </div>
  <script>
  (function () {
    var csrf = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;
    var stage = 0; // 0 current, 1 new, 2 confirm
    var currentPin = '', newPin = '', buf = '';
    var title = document.getElementById('changePinTitle');
    var sub = document.getElementById('changePinSub');
    var err = document.getElementById('changePinError');
    var dots = document.querySelectorAll('#changePinDots .ppm-dot');
    var steps = [document.getElementById('step1'), document.getElementById('step2'), document.getElementById('step3')];

    function setError(msg) {
      if (!msg) { err.textContent=''; err.classList.add('is-hidden'); return; }
      err.textContent = msg; err.classList.remove('is-hidden');
    }
    function render() {
      for (var i=0;i<dots.length;i++) dots[i].classList.toggle('filled', i < buf.length);
      for (var s=0;s<steps.length;s++) steps[s].classList.toggle('is-active', s === stage);
      if (stage === 0) { title.textContent='تغيير الرمز'; sub.textContent='أدخل الرمز الحالي'; }
      if (stage === 1) { title.textContent='رمز جديد'; sub.textContent='اختر رمزاً جديداً من 4 أرقام'; }
      if (stage === 2) { title.textContent='تأكيد الرمز'; sub.textContent='أعد إدخال الرمز الجديد'; }
    }
    function mapError(code) {
      var m = {
        CURRENT_PIN_INVALID:'الرمز الحالي غير صحيح',
        PIN_CONFIRM_MISMATCH:'الرمزان غير متطابقين',
        PIN_BLACKLISTED:'هذا الرمز ضعيف وغير مسموح',
        PIN_ALREADY_IN_USE:'هذا الرمز مستخدم بالفعل',
        PIN_UNCHANGED:'اختر رمزاً مختلفاً عن الحالي',
        PIN_FORMAT_INVALID:'الرمز يجب أن يكون 4 أرقام'
      };
      return m[code] || 'تعذر حفظ الرمز';
    }
    function submitChange() {
      var body = new FormData();
      body.append('csrf_token', csrf);
      body.append('current_pin', currentPin);
      body.append('new_pin', newPin);
      body.append('confirm_pin', buf);
      fetch('do/do_change_pin.php', {
        method:'POST', body:body, credentials:'same-origin',
        headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}
      }).then(function(r){ return r.json().then(function(j){ return {ok:r.ok,j:j}; }); })
        .then(function(res){
          if (res.j && res.j.success) {
            window.location.href = res.j.redirect || 'dashboard.php';
            return;
          }
          setError(mapError((res.j && res.j.code) || 'SERVER_ERROR'));
          stage = 0; currentPin=''; newPin=''; buf=''; render();
        }).catch(function(){ setError('تعذر الاتصال'); });
    }
    function advance() {
      if (buf.length !== 4) return;
      if (stage === 0) { currentPin = buf; buf=''; stage=1; setError(''); render(); return; }
      if (stage === 1) { newPin = buf; buf=''; stage=2; setError(''); render(); return; }
      if (buf !== newPin) { setError(mapError('PIN_CONFIRM_MISMATCH')); buf=''; render(); return; }
      submitChange();
    }
    function push(key) {
      if (key === 'مسح') { buf=''; setError(''); render(); return; }
      if (key === 'دخول') { advance(); return; }
      if (/^\d$/.test(key) && buf.length < 4) { buf += key; setError(''); render(); if (buf.length===4) advance(); }
    }
    document.getElementById('changePinGrid').addEventListener('click', function(e){
      var b = e.target.closest('[data-key]'); if (!b) return; push(b.getAttribute('data-key'));
    });
    window.addEventListener('keydown', function(e){
      if (e.key === 'Enter') { e.preventDefault(); push('دخول'); return; }
      if (e.key === 'Backspace' || e.key === 'Delete') { e.preventDefault(); push('مسح'); return; }
      if (e.key >= '0' && e.key <= '9') { e.preventDefault(); push(e.key); }
    });
    render();
  })();
  </script>
</body>
</html>
