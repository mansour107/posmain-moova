<?php if (!empty($success_message)): ?>
<!-- Order-saved success modal: same Bootstrap mechanism as #paymentModal
     (fade + translucent backdrop, opacity-only transition, no transform).
     POS_SUCCESS_HOLD pauses the lazy product-grid loading while this is on
     screen so nothing repaints behind the translucent backdrop. -->
<div class="modal fade pos-success-modal-fade" id="posOrderSuccessModal" tabindex="-1"
    aria-labelledby="posOrderSuccessTitle" aria-hidden="true" style="display:none">
    <div class="modal-dialog modal-dialog-centered pos-success-dialog">
        <div class="modal-content pos-success-content">
            <div class="pos-success-body">
                <div class="pos-success-icon" aria-hidden="true">&#10003;</div>
                <h3 class="pos-success-title" id="posOrderSuccessTitle">تم بنجاح!</h3>
                <p class="pos-success-text" id="posOrderSuccessText"><?= htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    // Hold the lazy item-grid loader until the modal is dismissed.
    window.POS_SUCCESS_HOLD = true;

    var modalEl = document.getElementById('posOrderSuccessModal');
    if (!modalEl) {
        window.POS_SUCCESS_HOLD = false;
        return;
    }

    var durationMs = 1500;
    var hideTimer = null;
    var shown = false;

    function clearHideTimer() {
        if (hideTimer) {
            window.clearTimeout(hideTimer);
            hideTimer = null;
        }
    }

    function releaseHold() {
        window.POS_SUCCESS_HOLD = false;
        if (modalEl.parentNode) {
            modalEl.parentNode.removeChild(modalEl);
        }
    }

    function show() {
        if (shown) {
            return;
        }
        if (!window.bootstrap || typeof window.bootstrap.Modal !== 'function') {
            // Bootstrap bundle loads after the header; retry shortly.
            window.setTimeout(show, 30);
            return;
        }

        shown = true;
        modalEl.style.display = ''; // hand control to Bootstrap's .modal/.modal.show CSS

        var instance = window.bootstrap.Modal.getOrCreateInstance(modalEl);
        modalEl.addEventListener('hidden.bs.modal', function() {
            clearHideTimer();
            releaseHold();
        });

        instance.show();
        hideTimer = window.setTimeout(function() {
            var current = window.bootstrap.Modal.getInstance(modalEl);
            if (current) {
                current.hide();
            } else {
                releaseHold();
            }
        }, durationMs);
    }

    show();
})();
</script>
<?php endif; ?>
