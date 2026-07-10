<?php

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../config/app_config.php';

if (!function_exists('posmain_is_pin_main_auth') || !posmain_is_pin_main_auth()) {
    return;
}

$configuredSeconds = (int) (
    getenv('POSMAIN_INACTIVITY_LOCK_SECONDS')
    ?: ($_ENV['POSMAIN_INACTIVITY_LOCK_SECONDS'] ?? 300)
);
$inactivitySeconds = max(30, min(3600, $configuredSeconds));
$lockCsrf = csrf_token('main_lock');
?>
<script>
(function () {
    'use strict';

    window.POSMAIN_FULL_SESSION_LOCK_ACTIVE = true;
    var timeoutMs = <?= (int) $inactivitySeconds ?> * 1000;
    var timer = null;
    var locking = false;
    var csrf = <?= json_encode($lockCsrf, JSON_UNESCAPED_SLASHES) ?>;

    function parkCart() {
        if (window.POSMAIN && typeof window.POSMAIN.parkCartForActingUser === 'function') {
            try {
                window.POSMAIN.parkCartForActingUser();
            } catch (e) {}
        }
    }

    function lockSession() {
        if (locking) {
            return;
        }
        locking = true;
        if (timer) {
            window.clearTimeout(timer);
            timer = null;
        }
        parkCart();

        var body = new URLSearchParams();
        body.set('csrf_token', csrf);
        fetch('ajax/main_session_lock.php', {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrf
            },
            body: body.toString()
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('LOCK_FAILED');
            }
            return response.json();
        }).then(function (payload) {
            window.location.replace((payload && payload.redirect) || 'index.php');
        }).catch(function () {
            // Fail closed if the lock endpoint is unavailable.
            window.location.replace('do/do_logout.php');
        });
    }

    function resetTimer() {
        if (locking) {
            return;
        }
        if (timer) {
            window.clearTimeout(timer);
        }
        timer = window.setTimeout(lockSession, timeoutMs);
    }

    ['pointerdown', 'keydown', 'touchstart', 'mousemove'].forEach(function (eventName) {
        document.addEventListener(eventName, resetTimer, { passive: true });
    });

    document.addEventListener('click', function (event) {
        var explicitLock = event.target.closest('[data-posmain-main-lock], #posHeaderLockBtn');
        var logoutLink = event.target.closest(
            'a[href="do/do_logout.php"], a[href="./do/do_logout.php"], a[href="../do/do_logout.php"]'
        );
        if (!explicitLock && !logoutLink) {
            return;
        }
        event.preventDefault();
        lockSession();
    });

    window.addEventListener('pageshow', resetTimer);
    resetTimer();
})();
</script>
