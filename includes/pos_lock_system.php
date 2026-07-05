<!-- POS auto-lock + navigation lock -->
<script>
(function () {
    function posmainIsSellingSurface() {
        return !!document.getElementById('posForm');
    }

    var autolockSeconds = 90;
    var autolockTimer = null;
    var csrfPin = '';

    function lockTerminal() {
        if (!posmainIsSellingSurface()) {
            return;
        }
        if (window.POSMAIN && typeof window.POSMAIN.parkCartForActingUser === 'function') {
            window.POSMAIN.parkCartForActingUser();
        }
        var body = new URLSearchParams();
        if (csrfPin) {
            body.set('csrf_token', csrfPin);
        }
        fetch('ajax/pos_lock.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfPin || '',
            },
            credentials: 'same-origin',
            body: body.toString(),
        }).finally(function () {
            try {
                sessionStorage.setItem('pos_locked', '1');
            } catch (e) {}
            window.location.href = 'pos_barcode.php?logout=1';
        });
    }

    function resetAutolock() {
        if (!posmainIsSellingSurface() || autolockSeconds < 1) {
            return;
        }
        if (autolockTimer) {
            clearTimeout(autolockTimer);
        }
        autolockTimer = setTimeout(lockTerminal, autolockSeconds * 1000);
    }

    if (posmainIsSellingSurface()) {
        fetch('ajax/pin_available.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : {}; })
            .then(function (data) {
                if (data && data.pos_autolock_seconds) {
                    autolockSeconds = parseInt(data.pos_autolock_seconds, 10) || 90;
                }
                resetAutolock();
            })
            .catch(function () { resetAutolock(); });

        ['click', 'keydown', 'touchstart', 'mousemove'].forEach(function (evt) {
            document.addEventListener(evt, resetAutolock, { passive: true });
        });

        var pinMeta = document.querySelector('meta[name="pos-pin-csrf-token"]');
        if (pinMeta) {
            csrfPin = pinMeta.getAttribute('content') || '';
        }

        var lockBtn = document.getElementById('posHeaderLockBtn');
        if (lockBtn) {
            lockBtn.addEventListener('click', function (e) {
                e.preventDefault();
                lockTerminal();
            });
        }
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest('a');
        if (link && link.href && !link.href.includes('pos_barcode.php') && link.target !== '_blank') {
            sessionStorage.setItem('pos_locked', '1');
        }
    });

    if (sessionStorage.getItem('pos_locked') === '1') {
        sessionStorage.removeItem('pos_locked');
        if (posmainIsSellingSurface()) {
            window.location.href = 'pos_barcode.php?logout=1';
        }
    }
})();
</script>
