<?php
/**
 * Shift recovery gate: stale business day, cross-register transfer, or blocked drawer.
 *
 * Expects: $posmainShiftBlocked, $posmainShiftEntryState, $posmainShiftEntryMessage,
 *          $posmainIdentity (optional), $posmainBlockingSession (optional)
 */
if (empty($posmainShiftBlocked)) {
    return;
}

$state = (string) ($posmainShiftEntryState ?? '');
$message = trim((string) ($posmainShiftEntryMessage ?? ''));
$blocking = is_array($posmainBlockingSession ?? null)
    ? $posmainBlockingSession
    : (is_array($_SESSION['posmain_shift_blocking'] ?? null) ? $_SESSION['posmain_shift_blocking'] : []);
// Always prefer the blocking drawer for branch_blocked actions. The operator may still
// have a stale pos_drawer_session_id from a previous (now closed) attempt.
$drawerSessionId = 0;
if ($state === 'branch_blocked') {
    $drawerSessionId = (int) ($blocking['id'] ?? 0);
}
if ($drawerSessionId < 1) {
    $drawerSessionId = (int) ($posmainIdentity['drawer_session_id'] ?? $_SESSION['pos_drawer_session_id'] ?? 0);
}
$csrfTransfer = htmlspecialchars(csrf_token('shift_register_transfer'), ENT_QUOTES, 'UTF-8');
$csrfOverrideAuth = htmlspecialchars(csrf_token('pos_override'), ENT_QUOTES, 'UTF-8');
$csrfShiftOverride = htmlspecialchars(csrf_token('shift_override'), ENT_QUOTES, 'UTF-8');
$csrfTakeover = htmlspecialchars(csrf_token('shift_takeover'), ENT_QUOTES, 'UTF-8');

$ownerName = trim((string) ($blocking['owner_name'] ?? ''));
$openedAt = trim((string) ($blocking['opened_at'] ?? ''));
$businessDay = trim((string) ($blocking['business_day'] ?? ''));
$registerName = trim((string) ($blocking['register_name'] ?? ''));
$isStaleDay = !empty($blocking['is_stale_business_day']);
$canOverride = !empty($blocking['can_override']);
$canForceClose = !empty($blocking['can_force_close']);

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

$metaBits = [];
if ($ownerName !== '') {
    $metaBits[] = $ownerName;
}
if ($openedAt !== '') {
    $metaBits[] = $openedAt;
} elseif ($businessDay !== '') {
    $metaBits[] = $businessDay . ($isStaleDay ? ' (سابق)' : '');
}
$condensedMeta = implode(' · ', $metaBits);
?>
<div id="posShiftRecoveryOverlay" class="pos-shift-recovery" role="dialog" aria-modal="true" aria-labelledby="posShiftRecoveryTitle">
    <div class="pos-shift-recovery__card">
        <?php if ($state === 'stale_shift'): ?>
            <div class="pos-shift-recovery__icon" aria-hidden="true"><i class="fas fa-exclamation-triangle"></i></div>
            <h2 id="posShiftRecoveryTitle" class="pos-shift-recovery__title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="pos-shift-recovery__msg"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
            <p class="pos-shift-recovery__hint">استخدم إغلاق الشيفت لإتمام العدّ والتسوية، ثم سجّل الدخول مجدداً لفتح وردية اليوم.</p>
            <?php if (!empty($posmainCanCloseShift)): ?>
                <button type="button" class="pos-shift-recovery__btn" data-bs-toggle="modal" data-bs-target="#closeShiftModal">
                    إغلاق الوردية السابقة
                </button>
            <?php endif; ?>
            <a class="pos-shift-recovery__link" id="posShiftRecoveryLeave" href="do/do_logout.php" data-posmain-leave-page>مغادرة الصفحة</a>

        <?php elseif ($state === 'register_transfer_required'): ?>
            <div class="pos-shift-recovery__icon" aria-hidden="true"><i class="fas fa-exclamation-triangle"></i></div>
            <h2 id="posShiftRecoveryTitle" class="pos-shift-recovery__title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="pos-shift-recovery__msg"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
            <p class="pos-shift-recovery__hint">اطلب من المدير إدخال رمزه للموافقة على نقل الوردية إلى هذا الجهاز.</p>
            <button type="button" class="pos-shift-recovery__btn" id="posTransferRegisterBtn"
                    data-session-id="<?= $drawerSessionId ?>"
                    data-csrf="<?= $csrfTransfer ?>"
                    data-override-csrf="<?= $csrfOverrideAuth ?>">
                نقل الوردية إلى هذا الصندوق
            </button>
            <div class="pos-shift-recovery__error" id="posTransferError" hidden></div>
            <a class="pos-shift-recovery__link" id="posShiftRecoveryLeave" href="do/do_logout.php" data-posmain-leave-page>مغادرة الصفحة</a>

        <?php elseif ($state === 'branch_blocked'): ?>
            <?php if ($canOverride || $canForceClose): ?>
                <div class="pos-shift-recovery__steps" id="posShiftRecoverySteps" data-current-step="choice">
                    <!-- Step 1: choice -->
                    <div class="pos-shift-recovery__step" data-step="choice">
                        <div class="pos-shift-recovery__icon" aria-hidden="true"><i class="fas fa-exclamation-triangle"></i></div>
                        <h2 id="posShiftRecoveryTitle" class="pos-shift-recovery__title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h2>
                        <?php if ($condensedMeta !== ''): ?>
                            <p class="pos-shift-recovery__meta-line" data-testid="pos-shift-blocking-meta">
                                <?= htmlspecialchars($condensedMeta, ENT_QUOTES, 'UTF-8') ?>
                                <?php if ($registerName !== ''): ?>
                                    <span class="pos-shift-recovery__meta-soft"> · <?= htmlspecialchars($registerName, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </p>
                        <?php else: ?>
                            <p class="pos-shift-recovery__msg"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>

                        <div class="pos-shift-recovery__choices">
                            <?php if ($canOverride): ?>
                                <button type="button" class="pos-shift-recovery__choice pos-shift-recovery__choice--join"
                                        data-goto-step="join" data-testid="pos-choice-join">
                                    <span class="pos-shift-recovery__choice-title">دخول وردية الموظف</span>
                                    <span class="pos-shift-recovery__choice-sub">عمل مؤقت داخل ورديته — تبقى مفتوحة</span>
                                </button>
                            <?php endif; ?>
                            <?php if ($canForceClose): ?>
                                <button type="button" class="pos-shift-recovery__choice pos-shift-recovery__choice--takeover"
                                        data-goto-step="takeover" data-testid="pos-choice-takeover">
                                    <span class="pos-shift-recovery__choice-title">إغلاق ورديته وفتح ورديتي</span>
                                    <span class="pos-shift-recovery__choice-sub">عدّ النقد وإغلاق نهائي ثم وردية جديدة باسمي</span>
                                </button>
                            <?php endif; ?>
                        </div>
                        <a class="pos-shift-recovery__link" id="posShiftRecoveryLeave" href="do/do_logout.php" data-posmain-leave-page>مغادرة الصفحة</a>
                    </div>

                    <?php if ($canOverride): ?>
                        <!-- Step 2a: join -->
                        <div class="pos-shift-recovery__step" data-step="join" hidden>
                            <button type="button" class="pos-shift-recovery__back" data-goto-step="choice" aria-label="رجوع">
                                <i class="fas fa-arrow-right" aria-hidden="true"></i>
                                رجوع
                            </button>
                            <h2 class="pos-shift-recovery__title">دخول وردية الموظف</h2>
                            <p class="pos-shift-recovery__hint">أدخل السبب ثم رمز المدير للمتابعة.</p>
                            <div class="pos-shift-recovery__field">
                                <label for="posOverrideReason">سبب الدخول المؤقت</label>
                                <textarea id="posOverrideReason" class="pos-shift-recovery__textarea" rows="3" maxlength="500"
                                          placeholder="مثال: مساعدة الكاشير أثناء الاستراحة"
                                          data-testid="pos-join-shift-path"></textarea>
                            </div>
                            <button type="button" class="pos-shift-recovery__btn" id="posStartOverrideBtn"
                                    data-testid="pos-start-override"
                                    data-session-id="<?= $drawerSessionId ?>"
                                    data-csrf="<?= $csrfShiftOverride ?>"
                                    data-override-csrf="<?= $csrfOverrideAuth ?>">
                                متابعة وإدخال رمز المدير
                            </button>
                            <div class="pos-shift-recovery__error" id="posOverrideError" hidden></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($canForceClose): ?>
                        <!-- Step 2b: takeover -->
                        <div class="pos-shift-recovery__step" data-step="takeover" hidden>
                            <button type="button" class="pos-shift-recovery__back" data-goto-step="choice" aria-label="رجوع">
                                <i class="fas fa-arrow-right" aria-hidden="true"></i>
                                رجوع
                            </button>
                            <h2 class="pos-shift-recovery__title">إغلاق وردية الموظف</h2>
                            <p class="pos-shift-recovery__hint" id="posTakeoverHint">عدّ النقد في الدرج (مثل إغلاق أي وردية).</p>
                            <p class="pos-shift-recovery__attempt" id="posTakeoverAttemptLabel" hidden></p>
                            <div class="pos-shift-recovery__field pos-shift-recovery__field--amount" id="posTakeoverAmountWrap">
                                <label for="posTakeoverAmount">المبلغ المعدود في الدرج</label>
                                <input type="text" id="posTakeoverAmount" class="psh-amount-input"
                                       inputmode="decimal" autocomplete="off" placeholder="0.00"
                                       data-testid="pos-takeover-amount">
                            </div>
                            <div class="pos-shift-recovery__variance" id="posTakeoverVariance" hidden>
                                <p class="pos-shift-recovery__variance-label" id="posTakeoverVarianceLabel"></p>
                                <p class="pos-shift-recovery__variance-amount" id="posTakeoverVarianceAmount"></p>
                                <p class="pos-shift-recovery__variance-note">سيتم تسجيل الفرق في السجلات والمتابعة بفتح ورديتك.</p>
                            </div>
                            <div class="pos-shift-recovery__field" id="posTakeoverReasonWrap" hidden>
                                <label for="posTakeoverReason">سبب الإغلاق والاستلام</label>
                                <textarea id="posTakeoverReason" class="pos-shift-recovery__textarea" rows="2" maxlength="500"
                                          placeholder="مثال: الكاشير غادر دون إغلاق الوردية"
                                          data-testid="pos-takeover-reason"></textarea>
                            </div>
                            <button type="button" class="pos-shift-recovery__btn pos-shift-recovery__btn--takeover"
                                    id="posTakeoverShiftBtn"
                                    data-testid="pos-takeover-shift"
                                    data-session-id="<?= $drawerSessionId ?>"
                                    data-csrf="<?= $csrfTakeover ?>"
                                    data-override-csrf="<?= $csrfOverrideAuth ?>"
                                    data-phase="count">
                                تأكيد العد
                            </button>
                            <div class="pos-shift-recovery__error" id="posTakeoverError" hidden></div>
                            <div class="pos-shift-recovery__info" id="posTakeoverInfo" hidden></div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="pos-shift-recovery__icon" aria-hidden="true"><i class="fas fa-exclamation-triangle"></i></div>
                <h2 id="posShiftRecoveryTitle" class="pos-shift-recovery__title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="pos-shift-recovery__msg"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
                <p class="pos-shift-recovery__hint">
                    الدرج مفتوح بالفعل ولا يمكن فتح وردية ثانية. غادر الصفحة وانتظر عودة صاحب الوردية، أو اطلب من المدير المساعدة.
                </p>
                <a class="pos-shift-recovery__link" id="posShiftRecoveryLeave" href="do/do_logout.php" data-posmain-leave-page>مغادرة الصفحة</a>
            <?php endif; ?>

        <?php else: ?>
            <div class="pos-shift-recovery__icon" aria-hidden="true"><i class="fas fa-exclamation-triangle"></i></div>
            <h2 id="posShiftRecoveryTitle" class="pos-shift-recovery__title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="pos-shift-recovery__msg"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
            <a class="pos-shift-recovery__link" id="posShiftRecoveryLeave" href="do/do_logout.php" data-posmain-leave-page>مغادرة الصفحة</a>
        <?php endif; ?>
    </div>
</div>
<style>
.pos-shift-recovery {
    position: fixed; inset: 0; z-index: 2000;
    display: flex; align-items: center; justify-content: center;
    background: rgba(15, 23, 42, .72); backdrop-filter: blur(6px);
    padding: 1rem;
}
.pos-shift-recovery.is-deferred-for-close { display: none !important; }
.pos-shift-recovery__card {
    width: min(460px, 96vw);
    background: #fff; border-radius: 18px; padding: 1.75rem 1.5rem;
    text-align: center; box-shadow: 0 24px 60px rgba(0,0,0,.35);
    font-family: 'IBM Plex Sans Arabic', 'Inter', sans-serif;
}
.pos-shift-recovery__icon { color: #d97706; font-size: 2rem; margin-bottom: .5rem; }
.pos-shift-recovery__title { font-size: 1.35rem; font-weight: 700; margin: 0 0 .5rem; color: #0f172a; }
.pos-shift-recovery__msg, .pos-shift-recovery__hint { color: #475569; margin: 0 0 .85rem; line-height: 1.55; }
.pos-shift-recovery__meta-line {
    margin: 0 0 1.15rem; color: #334155; font-size: .95rem; font-weight: 600; line-height: 1.45;
}
.pos-shift-recovery__meta-soft { color: #64748b; font-weight: 500; }
.pos-shift-recovery__choices { display: flex; flex-direction: column; gap: .75rem; margin-bottom: .35rem; }
.pos-shift-recovery__choice {
    width: 100%; border: 1px solid #e2e8f0; border-radius: 14px;
    padding: 1rem 1.1rem; text-align: right; cursor: pointer;
    background: #fff; transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease;
    display: flex; flex-direction: column; gap: .3rem;
    min-height: 4.5rem; justify-content: center;
}
.pos-shift-recovery__choice:hover,
.pos-shift-recovery__choice:focus-visible {
    transform: translateY(-1px);
    outline: none;
}
.pos-shift-recovery__choice--join {
    border-color: #c7d2fe;
    background: linear-gradient(180deg, #f8f7ff 0%, #fff 80%);
}
.pos-shift-recovery__choice--join:hover,
.pos-shift-recovery__choice--join:focus-visible {
    box-shadow: 0 10px 24px rgba(99, 102, 241, .2);
    border-color: #a5b4fc;
}
.pos-shift-recovery__choice--takeover {
    border-color: #fde68a;
    background: linear-gradient(180deg, #fffbeb 0%, #fff 80%);
}
.pos-shift-recovery__choice--takeover:hover,
.pos-shift-recovery__choice--takeover:focus-visible {
    box-shadow: 0 10px 24px rgba(217, 119, 6, .18);
    border-color: #fcd34d;
}
.pos-shift-recovery__choice-title {
    font-size: 1.05rem; font-weight: 700; color: #0f172a;
}
.pos-shift-recovery__choice-sub {
    font-size: .88rem; color: #64748b; line-height: 1.4; font-weight: 500;
}
.pos-shift-recovery__back {
    display: inline-flex; align-items: center; gap: .4rem;
    border: 0; background: transparent; color: #64748b;
    font-size: .92rem; font-weight: 600; padding: 0; margin: 0 0 .75rem;
    cursor: pointer;
}
.pos-shift-recovery__back:hover { color: #334155; }
.pos-shift-recovery__field { margin: 0 0 .85rem; text-align: right; }
.pos-shift-recovery__field label {
    display: block; margin-bottom: .35rem; font-weight: 600; color: #334155; font-size: .9rem;
}
.pos-shift-recovery__field--amount { text-align: center; }
.pos-shift-recovery__field--amount label { text-align: right; }
/* Match open/close shift amount look even if handover CSS loads later */
.pos-shift-recovery .psh-amount-input {
    width: 100%;
    text-align: center;
    font-size: 2rem;
    font-weight: 700;
    padding: 0.85rem 1rem;
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    color: #0f172a;
    background: #fff;
    transition: border-color 0.15s, box-shadow 0.15s;
    -moz-appearance: textfield;
    appearance: textfield;
}
.pos-shift-recovery .psh-amount-input::-webkit-outer-spin-button,
.pos-shift-recovery .psh-amount-input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.pos-shift-recovery .psh-amount-input:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15);
}
/* Keep light inputs even when body.pos-premium-dark styles .form-control */
.pos-shift-recovery__textarea,
.pos-shift-recovery .psh-takeover-reason {
    width: 100%;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.65rem 0.85rem;
    font-size: 0.92rem;
    color: #0f172a;
    background: #fff;
    resize: vertical;
    min-height: 64px;
}
.pos-shift-recovery__textarea::placeholder,
.pos-shift-recovery .psh-takeover-reason::placeholder {
    color: #94a3b8;
}
.pos-shift-recovery__textarea:focus,
.pos-shift-recovery .psh-takeover-reason:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15);
    background: #fff;
    color: #0f172a;
}
.pos-shift-recovery__btn {
    width: 100%; border: 0; border-radius: 12px; padding: .9rem 1rem;
    background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; font-weight: 700;
    margin-bottom: .55rem; transition: transform .12s ease, box-shadow .12s ease, opacity .12s ease;
}
.pos-shift-recovery__btn:hover:not(:disabled) {
    box-shadow: 0 10px 24px rgba(99, 102, 241, .28);
    transform: translateY(-1px);
}
.pos-shift-recovery__btn:disabled { opacity: .65; cursor: not-allowed; transform: none; box-shadow: none; }
.pos-shift-recovery__btn--takeover {
    background: linear-gradient(135deg, #d97706, #ea580c);
}
.pos-shift-recovery__btn--takeover:hover:not(:disabled) {
    box-shadow: 0 10px 24px rgba(217, 119, 6, .28);
}
.pos-shift-recovery__link {
    display: inline-block; margin-top: .65rem;
    color: #64748b; text-decoration: none; font-size: .95rem;
}
.pos-shift-recovery__error {
    background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;
    border-radius: 10px; padding: .65rem; margin-bottom: .35rem; font-size: .9rem;
    text-align: center;
}
.pos-shift-recovery__info {
    background: #fffbeb; color: #b45309; border: 1px solid #fde68a;
    border-radius: 10px; padding: .65rem; margin-bottom: .35rem; font-size: .9rem;
    text-align: center;
}
.pos-shift-recovery__attempt {
    margin: 0 0 .65rem; color: #6366f1; font-size: .9rem; font-weight: 600;
}
.pos-shift-recovery__variance {
    text-align: center;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 14px;
    padding: 1rem;
    margin: 0 0 .85rem;
}
.pos-shift-recovery__variance.is-over { background: #fef2f2; border-color: #fecaca; }
.pos-shift-recovery__variance.is-under { background: #fff7ed; border-color: #fed7aa; }
.pos-shift-recovery__variance.is-balanced { background: #ecfdf5; border-color: #a7f3d0; }
.pos-shift-recovery__variance-label { margin: 0; font-weight: 700; color: #92400e; }
.pos-shift-recovery__variance.is-over .pos-shift-recovery__variance-label { color: #991b1b; }
.pos-shift-recovery__variance.is-under .pos-shift-recovery__variance-label { color: #c2410c; }
.pos-shift-recovery__variance.is-balanced .pos-shift-recovery__variance-label { color: #047857; }
.pos-shift-recovery__variance-amount {
    margin: .35rem 0; font-size: 1.6rem; font-weight: 800; color: #0f172a;
}
.pos-shift-recovery__variance-note { margin: 0; font-size: .85rem; color: #64748b; }
</style>
<script>
(function () {
    // Recovery is z-index 2000; Bootstrap #closeShiftModal is ~1055 and loads later in the page.
    var recoveryOverlay = document.getElementById('posShiftRecoveryOverlay');
    if (recoveryOverlay) {
        document.addEventListener('show.bs.modal', function (e) {
            if (e.target && e.target.id === 'closeShiftModal') {
                recoveryOverlay.classList.add('is-deferred-for-close');
            }
        });
        document.addEventListener('hidden.bs.modal', function (e) {
            if (e.target && e.target.id === 'closeShiftModal') {
                recoveryOverlay.classList.remove('is-deferred-for-close');
            }
        });
    }

    var RECOVERY_ERROR_AR = {
        DRAWER_SESSION_NOT_OPEN: 'الوردية غير متاحة — حدّث الصفحة وأعد المحاولة',
        DRAWER_SESSION_REQUIRED: 'لا توجد وردية مفتوحة',
        DRAWER_OWNER_REQUIRED: 'تعذر تحديد صاحب الوردية',
        CANNOT_OVERRIDE_OWN_SESSION: 'لا يمكن الدخول على ورديتك أنت',
        CANNOT_TAKEOVER_OWN_SESSION: 'لا يمكن استلام ورديتك أنت',
        OVERRIDE_REASON_REQUIRED: 'أدخل سبب الدخول المؤقت',
        FORCE_CLOSE_REASON_REQUIRED: 'أدخل سبب الإغلاق',
        MANAGER_APPROVAL_REQUIRED: 'يلزم رمز المدير',
        MANAGER_APPROVAL_NOT_APPROVED: 'رمز المدير غير صحيح',
        MANAGER_PIN_INVALID: 'رمز المدير غير صحيح',
        OVERRIDE_AUTH_FAILED: 'رمز المدير غير صحيح',
        OVERRIDE_FAILED: 'تعذر إتمام الاعتماد',
        OVERRIDE_START_FAILED: 'تعذر بدء الدخول المؤقت',
        OVERRIDE_ALREADY_ACTIVE: 'الدخول المؤقت مفعّل بالفعل',
        TRANSFER_FAILED: 'تعذر نقل الوردية',
        TAKEOVER_COUNT_BEGIN_FAILED: 'تعذر بدء عد الإغلاق',
        TAKEOVER_FAILED: 'تعذر إغلاق الوردية',
        BLOCKING_SESSION_MISMATCH: 'الوردية تغيّرت — حدّث الصفحة',
        BRANCH_DRAWER_ALREADY_OPEN: 'الدرج مفتوح لكاشير آخر',
        HANDOVER_NOT_ENABLED: 'تسليم الدرج غير مفعّل',
        CSRF_INVALID: 'انتهت الجلسة — حدّث الصفحة',
        AUTH_REQUIRED: 'يلزم تسجيل الدخول',
        PERMISSION_DENIED: 'ليس لديك صلاحية',
        COUNTED_AMOUNT_REQUIRED: 'أدخل المبلغ المعدود',
        COUNTED_AMOUNT_INVALID: 'المبلغ غير صالح'
    };

    function humanizeRecoveryError(raw, fallback) {
        var code = String(raw || '').trim();
        if (!code) {
            return fallback || 'حدث خطأ — أعد المحاولة';
        }
        if (RECOVERY_ERROR_AR[code]) {
            return RECOVERY_ERROR_AR[code];
        }
        // Already a human message (Arabic / mixed), keep it.
        if (/[^\x00-\x7F]/.test(code) || code.indexOf(' ') !== -1) {
            return code;
        }
        return fallback || 'حدث خطأ — أعد المحاولة';
    }

    function showErr(el, msg) {
        if (!el) return;
        var text = msg ? humanizeRecoveryError(msg, msg) : '';
        el.hidden = !text;
        el.textContent = text;
    }

    var stepsRoot = document.getElementById('posShiftRecoverySteps');
    var takeoverCountState = {
        started: false,
        handover: true,
        finalized: false,
        countedCash: null,
        matched: true
    };

    function showInfo(el, msg) {
        if (!el) return;
        el.hidden = !msg;
        el.textContent = msg || '';
    }

    function resetTakeoverUi() {
        takeoverCountState = { started: false, handover: true, finalized: false, countedCash: null, matched: true };
        var attemptLabel = document.getElementById('posTakeoverAttemptLabel');
        var variance = document.getElementById('posTakeoverVariance');
        var reasonWrap = document.getElementById('posTakeoverReasonWrap');
        var amountWrap = document.getElementById('posTakeoverAmountWrap');
        var amountInput = document.getElementById('posTakeoverAmount');
        var reasonInput = document.getElementById('posTakeoverReason');
        var info = document.getElementById('posTakeoverInfo');
        var err = document.getElementById('posTakeoverError');
        var btn = document.getElementById('posTakeoverShiftBtn');
        if (attemptLabel) { attemptLabel.hidden = true; attemptLabel.textContent = ''; }
        if (variance) {
            variance.hidden = true;
            variance.classList.remove('is-over', 'is-under', 'is-balanced');
        }
        if (reasonWrap) reasonWrap.hidden = true;
        if (amountWrap) amountWrap.hidden = false;
        if (amountInput) { amountInput.value = ''; amountInput.disabled = false; }
        if (reasonInput) reasonInput.value = '';
        showInfo(info, '');
        showErr(err, '');
        if (btn) {
            btn.disabled = false;
            btn.setAttribute('data-phase', 'count');
            btn.textContent = 'تأكيد العد';
        }
    }

    function beginTakeoverCloseCount(sessionId) {
        return fetch('do/do_begin_takeover_close_count.php?drawer_session_id=' + encodeURIComponent(sessionId), {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            if (res.ok && res.j && res.j.success) {
                takeoverCountState.started = true;
                takeoverCountState.handover = true;
                return res.j.data || {};
            }
            var code = res.j && (res.j.error || res.j.code);
            if (code === 'HANDOVER_NOT_ENABLED') {
                takeoverCountState.started = true;
                takeoverCountState.handover = false;
                return { skipped: true };
            }
            throw new Error(code || 'TAKEOVER_COUNT_BEGIN_FAILED');
          });
    }

    function showTakeoverVariance(data) {
        var box = document.getElementById('posTakeoverVariance');
        var label = document.getElementById('posTakeoverVarianceLabel');
        var amountEl = document.getElementById('posTakeoverVarianceAmount');
        if (!box) return;
        var direction = data.variance_direction || 'balanced';
        var labels = { over: 'زيادة في الدرج', under: 'عجز في الدرج', balanced: 'العد متطابق' };
        box.hidden = false;
        box.classList.remove('is-over', 'is-under', 'is-balanced');
        box.classList.add('is-' + direction);
        if (label) label.textContent = labels[direction] || labels.balanced;
        if (amountEl) {
            amountEl.textContent = direction === 'balanced'
                ? '0.00'
                : (Math.abs(Number(data.variance || 0)).toFixed(2) + ' ج.م');
        }
    }

    function enterTakeoverConfirmPhase(data) {
        takeoverCountState.finalized = true;
        takeoverCountState.countedCash = data && data.counted_cash != null
            ? data.counted_cash
            : null;
        takeoverCountState.matched = !!(data && data.matched);
        var amountWrap = document.getElementById('posTakeoverAmountWrap');
        var reasonWrap = document.getElementById('posTakeoverReasonWrap');
        var amountInput = document.getElementById('posTakeoverAmount');
        var btn = document.getElementById('posTakeoverShiftBtn');
        var attemptLabel = document.getElementById('posTakeoverAttemptLabel');
        var info = document.getElementById('posTakeoverInfo');
        if (attemptLabel) attemptLabel.hidden = true;
        showInfo(info, '');
        if (data && data.status === 'takeover_with_variance') {
            showTakeoverVariance(data);
            if (amountWrap) amountWrap.hidden = true;
        } else if (data && data.status === 'ready_to_takeover') {
            showTakeoverVariance(data);
            if (amountWrap) amountWrap.hidden = true;
        }
        if (reasonWrap) reasonWrap.hidden = false;
        if (amountInput) amountInput.disabled = true;
        if (btn) {
            btn.setAttribute('data-phase', 'confirm');
            btn.textContent = 'متابعة وإدخال رمز المدير';
            btn.disabled = false;
        }
        var reasonInput = document.getElementById('posTakeoverReason');
        if (reasonInput) setTimeout(function () { reasonInput.focus(); }, 50);
    }

    if (stepsRoot) {
        function goToStep(step) {
            var panels = stepsRoot.querySelectorAll('[data-step]');
            for (var i = 0; i < panels.length; i++) {
                var panel = panels[i];
                var match = panel.getAttribute('data-step') === step;
                panel.hidden = !match;
            }
            stepsRoot.setAttribute('data-current-step', step);
            if (step === 'join') {
                var reason = document.getElementById('posOverrideReason');
                if (reason) setTimeout(function () { reason.focus(); }, 50);
            } else if (step === 'takeover') {
                resetTakeoverUi();
                var btn = document.getElementById('posTakeoverShiftBtn');
                var sessionId = btn ? (btn.getAttribute('data-session-id') || '0') : '0';
                var err = document.getElementById('posTakeoverError');
                beginTakeoverCloseCount(sessionId).then(function () {
                    var amount = document.getElementById('posTakeoverAmount');
                    if (amount) setTimeout(function () { amount.focus(); }, 50);
                }).catch(function (e) {
                    showErr(err, e && e.message ? e.message : 'تعذر بدء عد الإغلاق');
                });
            }
        }

        stepsRoot.addEventListener('click', function (e) {
            var nav = e.target.closest('[data-goto-step]');
            if (!nav || !stepsRoot.contains(nav)) return;
            var step = nav.getAttribute('data-goto-step');
            if (step) goToStep(step);
        });
    }

    function requestManagerPin(permissionKey, options) {
        options = options || {};
        if (window.POSMAIN && typeof window.POSMAIN.requestManagerOverride === 'function') {
            return Promise.resolve(window.POSMAIN.requestManagerOverride(permissionKey, {
                action_type: options.action_type || permissionKey,
                target_type: options.target_type || 'drawer_session',
                target_id: options.target_id || '',
                reason: options.reason || '',
                message: options.message || 'أدخل رمزك المكوّن من 4 أرقام للتأكيد',
                require_same_user: options.require_same_user !== false
            }));
        }

        // Same first-login pad when POSMAIN helpers are not on the page yet.
        return new Promise(function (resolve, reject) {
            if (!window.PosmainPinPad || typeof window.PosmainPinPad.openModal !== 'function') {
                reject({ code: 'PIN_PAD_UNAVAILABLE' });
                return;
            }
            window.PosmainPinPad.openModal({
                title: 'تأكيد الهوية',
                subtitle: options.message || 'أدخل رمزك المكوّن من 4 أرقام للتأكيد',
                roleHint: (window.POSMAIN && typeof window.POSMAIN.formatApproverRoleHint === 'function')
                    ? window.POSMAIN.formatApproverRoleHint(permissionKey, Object.assign({}, options, { require_same_user: options.require_same_user !== false }))
                    : 'أدخل رمزك الشخصي لتأكيد العملية',
                autoSubmit: true,
                onCancel: function () { reject({ code: 'OVERRIDE_CANCELLED' }); },
                onSubmit: function (pin) {
                    var csrf = options.overrideCsrf || window.POSMAIN_POS_OVERRIDE_CSRF_TOKEN || '';
                    var body = new URLSearchParams({
                        csrf_token: csrf,
                        pin: pin,
                        manager_pin: pin,
                        permission: permissionKey,
                        permission_key: permissionKey,
                        action_type: options.action_type || permissionKey,
                        target_type: options.target_type || 'drawer_session',
                        target_id: String(options.target_id || ''),
                        reason: options.reason || '',
                        require_same_user: '1'
                    });
                    return fetch('ajax/pos_override_auth.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
                        body: body
                    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                      .then(function (res) {
                        if (!res.ok || !res.j || !res.j.success) {
                            return {
                                ok: false,
                                code: (res.j && (res.j.code || res.j.error)) || 'MANAGER_PIN_INVALID'
                            };
                        }
                        resolve(res.j);
                        return { ok: true, close: true };
                      });
                }
            });
        });
    }

    function approvalIdFrom(res) {
        if (!res) return 0;
        return res.approval_id || res.manager_approval_id || 0;
    }

    var transferBtn = document.getElementById('posTransferRegisterBtn');
    if (transferBtn) {
        transferBtn.addEventListener('click', function () {
            var err = document.getElementById('posTransferError');
            showErr(err, '');
            transferBtn.disabled = true;
            var overrideCsrf = transferBtn.getAttribute('data-override-csrf') || '';
            var transferCsrf = transferBtn.getAttribute('data-csrf') || '';
            var sessionId = transferBtn.getAttribute('data-session-id') || '0';

            function fail(msg) {
                transferBtn.disabled = false;
                showErr(err, msg || 'تعذر النقل');
            }

            requestManagerPin('pos.shift.force_close', {
                action_type: 'pos.shift.force_close',
                target_type: 'drawer_session',
                target_id: sessionId,
                reason: 'register_transfer',
                message: 'أدخل رمزك المكوّن من 4 أرقام لنقل الوردية',
                overrideCsrf: overrideCsrf
            }).then(function (approval) {
                var approvalId = approvalIdFrom(approval);
                if (!approvalId) throw new Error('OVERRIDE_FAILED');
                return fetch('do/do_transfer_drawer_register.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': transferCsrf },
                    body: new URLSearchParams({
                        csrf_token: transferCsrf,
                        drawer_session_id: sessionId,
                        manager_approval_id: String(approvalId)
                    })
                });
            }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
              .then(function (res) {
                if (!res.ok || !res.j || !res.j.success) {
                    throw new Error((res.j && res.j.error) || 'TRANSFER_FAILED');
                }
                window.location.href = 'pos_barcode.php';
              })
              .catch(function (e) {
                if (e && e.code === 'OVERRIDE_CANCELLED') {
                    transferBtn.disabled = false;
                    return;
                }
                fail(e && e.message ? e.message : 'تعذر النقل');
              });
        });
    }

    var overrideBtn = document.getElementById('posStartOverrideBtn');
    if (overrideBtn) {
        overrideBtn.addEventListener('click', function () {
            var err = document.getElementById('posOverrideError');
            var reasonInput = document.getElementById('posOverrideReason');
            var reason = reasonInput ? String(reasonInput.value || '').trim() : '';
            showErr(err, '');
            if (reason.length < 3) {
                showErr(err, 'أدخل سبب الدخول المؤقت (3 أحرف على الأقل)');
                if (reasonInput) reasonInput.focus();
                return;
            }
            overrideBtn.disabled = true;
            var overrideCsrf = overrideBtn.getAttribute('data-override-csrf') || '';
            var startCsrf = overrideBtn.getAttribute('data-csrf') || '';
            var sessionId = overrideBtn.getAttribute('data-session-id') || '0';

            function fail(msg) {
                overrideBtn.disabled = false;
                var text = humanizeRecoveryError(msg, 'تعذر بدء الدخول المؤقت');
                showErr(err, text);
                var code = String(msg || '').trim();
                if (code === 'DRAWER_SESSION_NOT_OPEN') {
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 900);
                }
            }

            requestManagerPin('pos.shift.override', {
                action_type: 'pos.shift.override',
                target_type: 'drawer_session',
                target_id: sessionId,
                reason: reason,
                message: 'أدخل رمزك المكوّن من 4 أرقام لدخول وردية الموظف',
                overrideCsrf: overrideCsrf
            }).then(function (approval) {
                var approvalId = approvalIdFrom(approval);
                if (!approvalId) throw new Error('OVERRIDE_AUTH_FAILED');
                return fetch('do/do_start_drawer_override.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': startCsrf },
                    body: new URLSearchParams({
                        csrf_token: startCsrf,
                        drawer_session_id: sessionId,
                        manager_approval_id: String(approvalId),
                        reason: reason
                    })
                });
            }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
              .then(function (res) {
                if (!res.ok || !res.j || !res.j.success) {
                    throw new Error((res.j && res.j.error) || 'OVERRIDE_START_FAILED');
                }
                window.location.href = (res.j.redirect || 'pos_barcode.php');
              })
              .catch(function (e) {
                if (e && e.code === 'OVERRIDE_CANCELLED') {
                    overrideBtn.disabled = false;
                    return;
                }
                fail(e && e.message ? e.message : 'تعذر بدء الدخول المؤقت');
              });
        });
    }

    var takeoverBtn = document.getElementById('posTakeoverShiftBtn');
    if (takeoverBtn) {
        takeoverBtn.addEventListener('click', function () {
            var err = document.getElementById('posTakeoverError');
            var info = document.getElementById('posTakeoverInfo');
            var amountInput = document.getElementById('posTakeoverAmount');
            var reasonInput = document.getElementById('posTakeoverReason');
            var amount = amountInput ? String(amountInput.value || '').trim() : '';
            var reason = reasonInput ? String(reasonInput.value || '').trim() : '';
            var sessionId = takeoverBtn.getAttribute('data-session-id') || '0';
            var phase = takeoverBtn.getAttribute('data-phase') || 'count';
            var takeoverCsrf = takeoverBtn.getAttribute('data-csrf') ||
                (window.POSMAIN_SHIFT_TAKEOVER_CSRF_TOKEN || '');
            var countCsrf = window.POSMAIN_SHIFT_TAKEOVER_COUNT_CSRF_TOKEN || '';
            var overrideCsrf = takeoverBtn.getAttribute('data-override-csrf') || '';
            showErr(err, '');
            showInfo(info, '');

            function fail(msg) {
                takeoverBtn.disabled = false;
                showErr(err, msg || 'تعذر إغلاق الوردية');
            }

            function runTakeoverPinAndClose(finalAmount) {
                if (reason.length < 3) {
                    showErr(err, 'أدخل سبب الإغلاق والاستلام (3 أحرف على الأقل)');
                    if (reasonInput) reasonInput.focus();
                    takeoverBtn.disabled = false;
                    return;
                }
                takeoverBtn.disabled = true;
                requestManagerPin('pos.shift.force_close', {
                    action_type: 'pos.shift.force_close',
                    target_type: 'drawer_session',
                    target_id: sessionId,
                    reason: reason,
                    message: 'أدخل رمزك المكوّن من 4 أرقام لإغلاق وردية الموظف وفتح ورديتك',
                    overrideCsrf: overrideCsrf
                }).then(function (approval) {
                    var approvalId = approvalIdFrom(approval);
                    if (!approvalId) throw new Error('MANAGER_APPROVAL_REQUIRED');
                    var idem = 'recovery-takeover:' + sessionId + ':' + Date.now();
                    return fetch('do/do_takeover_drawer_session.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': takeoverCsrf },
                        body: new URLSearchParams({
                            csrf_token: takeoverCsrf,
                            drawer_session_id: sessionId,
                            counted_amount: String(finalAmount),
                            reason: reason,
                            manager_approval_id: String(approvalId),
                            idempotency_key: idem
                        })
                    });
                }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                  .then(function (res) {
                    if (!res.ok || !res.j || !res.j.success) {
                        throw new Error((res.j && (res.j.error || res.j.code)) || 'TAKEOVER_FAILED');
                    }
                    var redirect = (res.j.data && res.j.data.redirect) || 'pos_barcode.php';
                    window.location.href = redirect;
                  })
                  .catch(function (e) {
                    if (e && e.code === 'OVERRIDE_CANCELLED') {
                        takeoverBtn.disabled = false;
                        return;
                    }
                    fail(e && e.message ? e.message : 'تعذر إغلاق الوردية');
                  });
            }

            if (phase === 'confirm' && takeoverCountState.finalized) {
                var finalAmt = takeoverCountState.countedCash != null
                    ? takeoverCountState.countedCash
                    : amount;
                runTakeoverPinAndClose(finalAmt);
                return;
            }

            // Handover disabled: single amount then confirm.
            if (!takeoverCountState.handover) {
                if (amount === '' || isNaN(Number(amount)) || Number(amount) < 0) {
                    showErr(err, 'أدخل المبلغ المعدود في الدرج');
                    if (amountInput) amountInput.focus();
                    return;
                }
                enterTakeoverConfirmPhase({
                    status: 'ready_to_takeover',
                    matched: true,
                    counted_cash: Number(amount),
                    variance: 0,
                    variance_direction: 'balanced'
                });
                return;
            }

            if (amount === '' || isNaN(Number(amount)) || Number(amount) < 0) {
                showErr(err, 'أدخل المبلغ المعدود في الدرج');
                if (amountInput) amountInput.focus();
                return;
            }

            takeoverBtn.disabled = true;
            var start = takeoverCountState.started
                ? Promise.resolve()
                : beginTakeoverCloseCount(sessionId);

            start.then(function () {
                if (!takeoverCountState.handover) {
                    enterTakeoverConfirmPhase({
                        status: 'ready_to_takeover',
                        matched: true,
                        counted_cash: Number(amount),
                        variance: 0,
                        variance_direction: 'balanced'
                    });
                    return null;
                }
                var idem = 'takeover-close-count:' + sessionId + ':' + Date.now();
                return fetch('do/do_submit_takeover_close_count.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': countCsrf },
                    body: new URLSearchParams({
                        csrf_token: countCsrf,
                        counted_amount: amount,
                        idempotency_key: idem
                    })
                }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); });
            }).then(function (res) {
                if (!res) return;
                if (!res.ok || !res.j || !res.j.success) {
                    throw new Error((res.j && (res.j.error || res.j.code)) || 'TAKEOVER_COUNT_FAILED');
                }
                var data = res.j.data || {};
                if (data.status === 'recount') {
                    var attemptLabel = document.getElementById('posTakeoverAttemptLabel');
                    if (attemptLabel) {
                        attemptLabel.hidden = false;
                        attemptLabel.textContent = 'محاولة ' + (data.attempt_number || 1) +
                            ' من ' + (data.max_attempts || 2);
                    }
                    showInfo(info, data.message || 'الرجاء إعادة العد بعناية');
                    if (amountInput) {
                        amountInput.value = '';
                        amountInput.focus();
                    }
                    takeoverBtn.disabled = false;
                    return;
                }
                enterTakeoverConfirmPhase(data);
            }).catch(function (e) {
                fail(e && e.message ? e.message : 'تعذر التحقق من العد');
            });
        });
    }
})();
</script>
