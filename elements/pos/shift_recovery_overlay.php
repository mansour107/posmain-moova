<?php
/**
 * Shift recovery gate: stale business day, cross-register transfer, or blocked drawer.
 *
 * Expects: $posmainShiftBlocked, $posmainShiftEntryState, $posmainShiftEntryMessage,
 *          $posmainIdentity (optional)
 */
if (empty($posmainShiftBlocked)) {
    return;
}

$state = (string) ($posmainShiftEntryState ?? '');
$message = trim((string) ($posmainShiftEntryMessage ?? ''));
$drawerSessionId = (int) ($posmainIdentity['drawer_session_id'] ?? $_SESSION['pos_drawer_session_id'] ?? 0);
$csrfTransfer = htmlspecialchars(csrf_token('shift_register_transfer'), ENT_QUOTES, 'UTF-8');
$csrfOverride = htmlspecialchars(csrf_token('pos_override'), ENT_QUOTES, 'UTF-8');

$title = match ($state) {
    'stale_shift' => 'وردية من يوم عمل سابق',
    'register_transfer_required' => 'الوردية على صندوق آخر',
    'branch_blocked' => 'الصندوق مشغول',
    'permission_denied' => 'لا توجد صلاحية',
    default => 'يلزم إجراء قبل البيع',
};
if ($message === '') {
    $message = match ($state) {
        'stale_shift' => 'يجب إغلاق وردية يوم العمل السابق قبل فتح وردية جديدة.',
        'register_transfer_required' => 'وردّيتك مفتوحة على جهاز/صندوق آخر. النقل يحتاج موافقة مدير.',
        'branch_blocked' => 'يوجد درج مفتوح لموظف آخر على هذا الصندوق.',
        default => 'لا يمكن البيع حتى تُحل حالة الوردية.',
    };
}
?>
<div id="posShiftRecoveryOverlay" class="pos-shift-recovery" role="dialog" aria-modal="true" aria-labelledby="posShiftRecoveryTitle">
    <div class="pos-shift-recovery__card">
        <div class="pos-shift-recovery__icon" aria-hidden="true"><i class="fas fa-exclamation-triangle"></i></div>
        <h2 id="posShiftRecoveryTitle" class="pos-shift-recovery__title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="pos-shift-recovery__msg"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>

        <?php if ($state === 'stale_shift'): ?>
            <p class="pos-shift-recovery__hint">استخدم إغلاق الشيفت لإتمام العدّ والتسوية، ثم سجّل الدخول مجدداً لفتح وردية اليوم.</p>
            <?php if (!empty($posmainCanCloseShift)): ?>
                <button type="button" class="pos-shift-recovery__btn" data-bs-toggle="modal" data-bs-target="#closeShiftModal">
                    إغلاق الوردية السابقة
                </button>
            <?php endif; ?>
        <?php elseif ($state === 'register_transfer_required'): ?>
            <p class="pos-shift-recovery__hint">اطلب من المدير إدخال رمزه للموافقة على نقل الوردية إلى هذا الجهاز.</p>
            <div class="pos-shift-recovery__pin">
                <label for="posTransferManagerPin">رمز المدير</label>
                <input id="posTransferManagerPin" type="password" inputmode="numeric" maxlength="4" pattern="\d{4}"
                       autocomplete="one-time-code" class="form-control text-center" placeholder="••••">
            </div>
            <button type="button" class="pos-shift-recovery__btn" id="posTransferRegisterBtn"
                    data-session-id="<?= $drawerSessionId ?>"
                    data-csrf="<?= $csrfTransfer ?>"
                    data-override-csrf="<?= $csrfOverride ?>">
                نقل الوردية إلى هذا الصندوق
            </button>
            <div class="pos-shift-recovery__error" id="posTransferError" hidden></div>
        <?php elseif ($state === 'branch_blocked'): ?>
            <p class="pos-shift-recovery__hint">يمكن للمدير استلام الدرج عبر شاشة العدّ الافتتاحي أو إغلاق الوردية بالقوة.</p>
        <?php endif; ?>

        <a class="pos-shift-recovery__link" href="do/do_logout.php">قفل / تسجيل الخروج</a>
    </div>
</div>
<style>
.pos-shift-recovery {
    position: fixed; inset: 0; z-index: 2000;
    display: flex; align-items: center; justify-content: center;
    background: rgba(15, 23, 42, .72); backdrop-filter: blur(6px);
    padding: 1rem;
}
.pos-shift-recovery__card {
    width: min(440px, 96vw);
    background: #fff; border-radius: 18px; padding: 1.75rem 1.5rem;
    text-align: center; box-shadow: 0 24px 60px rgba(0,0,0,.35);
    font-family: 'IBM Plex Sans Arabic', 'Inter', sans-serif;
}
.pos-shift-recovery__icon { color: #d97706; font-size: 2rem; margin-bottom: .5rem; }
.pos-shift-recovery__title { font-size: 1.35rem; font-weight: 700; margin: 0 0 .5rem; color: #0f172a; }
.pos-shift-recovery__msg, .pos-shift-recovery__hint { color: #475569; margin: 0 0 .85rem; line-height: 1.55; }
.pos-shift-recovery__pin { margin: 0 0 1rem; text-align: right; }
.pos-shift-recovery__pin label { display: block; margin-bottom: .35rem; font-weight: 600; color: #334155; }
.pos-shift-recovery__btn {
    width: 100%; border: 0; border-radius: 12px; padding: .9rem 1rem;
    background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; font-weight: 700;
    margin-bottom: .75rem;
}
.pos-shift-recovery__link { color: #64748b; text-decoration: none; font-size: .95rem; }
.pos-shift-recovery__error {
    background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;
    border-radius: 10px; padding: .65rem; margin-bottom: .75rem; font-size: .9rem;
}
</style>
<script>
(function () {
    var btn = document.getElementById('posTransferRegisterBtn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var err = document.getElementById('posTransferError');
        var pinInput = document.getElementById('posTransferManagerPin');
        var pin = pinInput ? String(pinInput.value || '').trim() : '';
        if (!/^\d{4}$/.test(pin)) {
            if (err) { err.hidden = false; err.textContent = 'أدخل رمز مدير مكوّن من 4 أرقام'; }
            return;
        }
        btn.disabled = true;
        var overrideCsrf = btn.getAttribute('data-override-csrf') || '';
        var transferCsrf = btn.getAttribute('data-csrf') || '';
        var sessionId = btn.getAttribute('data-session-id') || '0';

        function fail(msg) {
            btn.disabled = false;
            if (err) { err.hidden = false; err.textContent = msg || 'تعذر النقل'; }
        }

        fetch('ajax/pos_override_auth.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': overrideCsrf },
            body: new URLSearchParams({
                csrf_token: overrideCsrf,
                pin: pin,
                permission: 'pos.shift.force_close',
                action_type: 'pos.shift.force_close',
                target_type: 'drawer_session',
                target_id: sessionId
            })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            if (!res.ok || !res.j || !res.j.success) {
                throw new Error((res.j && (res.j.code || res.j.error)) || 'OVERRIDE_FAILED');
            }
            var approvalId = res.j.approval_id || res.j.manager_approval_id || 0;
            return fetch('do/do_transfer_drawer_register.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': transferCsrf },
                body: new URLSearchParams({
                    csrf_token: transferCsrf,
                    drawer_session_id: sessionId,
                    manager_approval_id: String(approvalId)
                })
            });
          })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            if (!res.ok || !res.j || !res.j.success) {
                throw new Error((res.j && res.j.error) || 'TRANSFER_FAILED');
            }
            window.location.href = 'pos_barcode.php';
          })
          .catch(function (e) {
            fail(e && e.message ? e.message : 'تعذر النقل');
          });
    });
})();
</script>
