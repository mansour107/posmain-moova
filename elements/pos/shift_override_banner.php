<?php
/**
 * Persistent banner while a temporary manager/owner override is active.
 *
 * Expects: $posmainIdentity with is_override / override_* fields.
 */
if (empty($posmainIdentity['is_override'])) {
    return;
}

$ownerName = trim((string) ($posmainIdentity['override_owner_name'] ?? $posmainIdentity['cashier_name'] ?? 'الكاشير'));
$operatorName = trim((string) ($posmainIdentity['override_operator_name'] ?? $posmainIdentity['terminal_name'] ?? 'المدير'));
$startedAt = trim((string) ($posmainIdentity['override_started_at'] ?? ''));
$periodId = (int) ($posmainIdentity['override_period_id'] ?? $_SESSION['pos_override_period_id'] ?? 0);
$csrf = htmlspecialchars(csrf_token('shift_override'), ENT_QUOTES, 'UTF-8');
?>
<div id="posOverrideBanner" class="pos-override-banner" role="status" data-testid="pos-override-banner"
     data-period-id="<?= $periodId ?>"
     data-started-at="<?= htmlspecialchars($startedAt, ENT_QUOTES, 'UTF-8') ?>">
    <div class="pos-override-banner__text">
        <strong>دخول مؤقت لوردية موظف</strong>
        <span>
            تعمل كـ <?= htmlspecialchars($operatorName, ENT_QUOTES, 'UTF-8') ?>
            داخل وردية <?= htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8') ?>
            <span id="posOverrideElapsed" class="pos-override-banner__elapsed"></span>
        </span>
    </div>
    <button type="button" class="pos-override-banner__btn" id="posEndOverrideBtn"
            data-testid="pos-end-override"
            data-csrf="<?= $csrf ?>"
            data-period-id="<?= $periodId ?>">
        إنهاء الدخول المؤقت
    </button>
</div>

<div class="modal fade" id="posEndOverrideModal" tabindex="-1" aria-labelledby="posEndOverrideModalTitle"
     aria-hidden="true" data-testid="pos-end-override-modal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content pos-end-override-modal__content">
            <div class="modal-header pos-end-override-modal__header">
                <button type="button" class="btn-close pos-end-override-modal__close" data-bs-dismiss="modal"
                        aria-label="إغلاق"></button>
                <h5 class="modal-title" id="posEndOverrideModalTitle">إنهاء الدخول المؤقت؟</h5>
            </div>
            <div class="modal-body pos-end-override-modal__body">
                ستتم العودة إلى شاشة قفل نقطة البيع، وستبقى وردية الكاشير مفتوحة.
            </div>
            <div class="modal-footer pos-end-override-modal__footer">
                <button type="button" class="btn pos-end-override-modal__cancel" data-bs-dismiss="modal"
                        data-testid="pos-end-override-cancel">إلغاء</button>
                <button type="button" class="btn pos-end-override-modal__confirm" id="posEndOverrideConfirmBtn"
                        data-testid="pos-end-override-confirm">إنهاء الدخول</button>
            </div>
        </div>
    </div>
</div>

<style>
.pos-override-banner {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1500;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .75rem;
    padding: .55rem 1rem;
    min-height: 3.25rem;
    box-sizing: border-box;
    background: linear-gradient(135deg, #7c3aed, #4f46e5);
    color: #fff;
    font-family: 'IBM Plex Sans Arabic', 'Inter', sans-serif;
}
.pos-override-banner__text { display: flex; flex-direction: column; gap: .15rem; text-align: right; min-width: 0; }
.pos-override-banner__elapsed { opacity: .9; margin-inline-start: .35rem; }
.pos-override-banner__btn {
    border: 0; border-radius: 10px; padding: .55rem .9rem;
    background: rgba(255,255,255,.18); color: #fff; font-weight: 700; white-space: nowrap;
    flex-shrink: 0;
}
.pos-override-banner__btn:hover { background: rgba(255,255,255,.28); }

/* Reserve banner space so POS shell + corner controls stay fully in viewport */
body.pos-override-active {
    --pos-override-banner-height: 3.5rem;
}
body.pos-premium-dark.pos-override-active .pos-shell {
    height: calc(100vh - var(--pos-override-banner-height)) !important;
    max-height: calc(100vh - var(--pos-override-banner-height)) !important;
    margin-top: var(--pos-override-banner-height);
    box-sizing: border-box;
    overflow: hidden;
}
body.pos-premium-dark.pos-override-active .pos-corner-menu {
    top: calc(var(--pos-override-banner-height) + 0.75rem);
}
body.pos-override-active.supermarket-theme {
    padding-top: var(--pos-override-banner-height);
    box-sizing: border-box;
}

/* In-theme end-override confirm (matches premium dark POS) */
#posEndOverrideModal .modal-dialog {
    max-width: 420px;
}
#posEndOverrideModal .pos-end-override-modal__content {
    background: var(--pos-panel, #1a222d);
    border: 1px solid var(--pos-border, #2d3748);
    border-radius: 14px;
    color: var(--pos-text, #e8edf4);
    box-shadow: 0 24px 48px rgba(0, 0, 0, 0.45);
}
#posEndOverrideModal .pos-end-override-modal__header {
    position: relative;
    display: block;
    border-bottom: 1px solid var(--pos-border, #2d3748);
    background: var(--pos-panel-elevated, #232d3b);
    padding: 1rem 1.1rem 0.85rem;
    padding-inline-end: 3rem;
}
#posEndOverrideModal .pos-end-override-modal__header .modal-title {
    color: var(--pos-text, #e8edf4);
    font-weight: 700;
    font-size: 1.05rem;
    margin: 0;
}
#posEndOverrideModal .pos-end-override-modal__close {
    position: absolute;
    top: 0.85rem;
    inset-inline-end: 0.85rem;
    margin: 0;
    opacity: 0.75;
    z-index: 2;
}
#posEndOverrideModal .pos-end-override-modal__body {
    color: var(--pos-text-muted, #8b98a8);
    line-height: 1.55;
    font-size: 0.95rem;
}
#posEndOverrideModal .pos-end-override-modal__footer {
    border-top: 1px solid var(--pos-border, #2d3748);
    gap: 0.5rem;
}
#posEndOverrideModal .pos-end-override-modal__cancel {
    border: 1px solid var(--pos-border, #2d3748);
    background: transparent;
    color: var(--pos-text, #e8edf4);
    font-weight: 700;
    border-radius: 10px;
    min-width: 96px;
}
#posEndOverrideModal .pos-end-override-modal__confirm {
    background: var(--pos-accent, #e8a020);
    border: 0;
    color: #1a1408;
    font-weight: 700;
    border-radius: 10px;
    min-width: 96px;
}
#posEndOverrideModal .pos-end-override-modal__confirm:hover {
    background: var(--pos-accent-hover, #f0b030);
}
#posEndOverrideModal .pos-end-override-modal__confirm:disabled {
    opacity: 0.7;
}
#posEndOverrideModal .pos-end-override-modal__close {
    filter: invert(1) grayscale(1) brightness(1.5);
}
</style>
<script>
(function () {
    var banner = document.getElementById('posOverrideBanner');
    if (!banner) return;

    document.body.classList.add('pos-override-active');

    function syncBannerHeight() {
        var h = Math.ceil(banner.getBoundingClientRect().height || banner.offsetHeight || 56);
        document.documentElement.style.setProperty('--pos-override-banner-height', h + 'px');
    }
    syncBannerHeight();
    window.addEventListener('resize', syncBannerHeight);
    if (typeof ResizeObserver !== 'undefined') {
        try { new ResizeObserver(syncBannerHeight).observe(banner); } catch (e) {}
    }

    var elapsedEl = document.getElementById('posOverrideElapsed');
    var startedAt = banner.getAttribute('data-started-at') || '';
    var startedMs = startedAt ? Date.parse(startedAt.replace(' ', 'T')) : NaN;

    function renderElapsed() {
        if (!elapsedEl || !isFinite(startedMs)) return;
        var sec = Math.max(0, Math.floor((Date.now() - startedMs) / 1000));
        var h = Math.floor(sec / 3600);
        var m = Math.floor((sec % 3600) / 60);
        var s = sec % 60;
        var label = (h > 0 ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        elapsedEl.textContent = '• منذ ' + label;
    }
    renderElapsed();
    setInterval(renderElapsed, 1000);

    var btn = document.getElementById('posEndOverrideBtn');
    var confirmBtn = document.getElementById('posEndOverrideConfirmBtn');
    var modalEl = document.getElementById('posEndOverrideModal');
    if (!btn || !confirmBtn || !modalEl) return;

    function showEndError(message) {
        var text = message || 'تعذر إنهاء الدخول المؤقت';
        if (window.Swal && typeof window.Swal.fire === 'function') {
            var opts = {
                icon: 'error',
                title: 'تعذر الإنهاء',
                text: text,
                confirmButtonText: 'حسناً',
                buttonsStyling: false,
                customClass: {
                    popup: 'pos-swal-premium',
                    title: 'pos-swal-premium__title',
                    htmlContainer: 'pos-swal-premium__text',
                    actions: 'pos-swal-premium__actions',
                    confirmButton: 'pos-swal-premium__confirm',
                },
            };
            window.Swal.fire(opts);
            return;
        }
        var errEl = modalEl.querySelector('[data-end-override-error]');
        if (!errEl) {
            errEl = document.createElement('div');
            errEl.setAttribute('data-end-override-error', '1');
            errEl.className = 'text-danger small mt-2';
            var body = modalEl.querySelector('.pos-end-override-modal__body');
            if (body) body.appendChild(errEl);
        }
        errEl.textContent = text;
        if (window.bootstrap && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    }

    function endOverride() {
        confirmBtn.disabled = true;
        btn.disabled = true;
        var csrf = btn.getAttribute('data-csrf') || '';
        var periodId = btn.getAttribute('data-period-id') || '0';
        fetch('do/do_end_drawer_override.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
            body: new URLSearchParams({
                csrf_token: csrf,
                override_period_id: periodId,
                end_reason: 'explicit_end'
            })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            if (!res.ok || !res.j || !res.j.success) {
                throw new Error((res.j && res.j.error) || 'OVERRIDE_END_FAILED');
            }
            window.location.href = res.j.redirect || 'pos_barcode.php';
          })
          .catch(function (e) {
            confirmBtn.disabled = false;
            btn.disabled = false;
            if (window.bootstrap && bootstrap.Modal) {
                bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            }
            showEndError(e && e.message ? e.message : 'تعذر إنهاء الدخول المؤقت');
          });
    }

    btn.addEventListener('click', function () {
        if (window.bootstrap && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
            return;
        }
        // Last-resort themed fallback without native browser dialogs
        modalEl.style.display = 'block';
        modalEl.classList.add('show');
        modalEl.setAttribute('aria-hidden', 'false');
    });

    confirmBtn.addEventListener('click', endOverride);
})();
</script>
