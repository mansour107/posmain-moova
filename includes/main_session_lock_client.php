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
$skipInactivityLock = !empty($posmainSkipInactivityLock);
$heartbeatIntervalSeconds = 25;
$lockCsrf = csrf_token('main_lock');
?>
<script>
(function () {
    'use strict';

    window.POSMAIN_FULL_SESSION_LOCK_ACTIVE = true;
    var timeoutMs = <?= (int) $inactivitySeconds ?> * 1000;
    var skipInactivityLock = <?= $skipInactivityLock ? 'true' : 'false' ?>;
    var heartbeatIntervalMs = <?= (int) $heartbeatIntervalSeconds ?> * 1000;
    var timer = null;
    var heartbeatTimer = null;
    var locking = false;
    var csrf = <?= json_encode($lockCsrf, JSON_UNESCAPED_SLASHES) ?>;

    function parkCart() {
        if (window.POSMAIN && typeof window.POSMAIN.parkCartForActingUser === 'function') {
            try {
                window.POSMAIN.parkCartForActingUser();
            } catch (e) {}
        }
    }

    function navigateAway(url) {
        window.location.replace(url || 'index.php');
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
        if (heartbeatTimer) {
            window.clearInterval(heartbeatTimer);
            heartbeatTimer = null;
        }
        parkCart();

        var settled = false;
        function finish(url) {
            if (settled) {
                return;
            }
            settled = true;
            navigateAway(url);
        }

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
            if (!payload || payload.success !== true) {
                throw new Error((payload && (payload.code || payload.message)) || 'LOCK_FAILED');
            }
            finish((payload.redirect) || 'index.php');
        }).catch(function () {
            // Hard logout if soft-lock cannot complete.
            finish('do/do_logout.php');
        });
    }

    function resetTimer() {
        if (locking || skipInactivityLock) {
            return;
        }
        if (timer) {
            window.clearTimeout(timer);
        }
        timer = window.setTimeout(lockSession, timeoutMs);
    }

    function sendHeartbeat() {
        if (locking) {
            return;
        }
        fetch('ajax/main_session_heartbeat.php', {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            // Only bounce on true auth loss. Classification/CSRF mistakes must not
            // create a login↔POS reload loop.
            if (response.status === 401) {
                navigateAway('index.php');
                return null;
            }
            if (response.status === 403) {
                return response.json().then(function (payload) {
                    var code = payload && (payload.code || payload.error);
                    if (code === 'AUTH_REQUIRED' || code === 'AUTH_VERSION_STALE') {
                        navigateAway('index.php');
                    }
                }).catch(function () {});
            }
            return null;
        }).catch(function () {
            // Network blips are tolerated; grace window covers short gaps.
        });
    }

    function startHeartbeat() {
        if (locking) {
            return;
        }
        sendHeartbeat();
        if (heartbeatTimer) {
            window.clearInterval(heartbeatTimer);
        }
        heartbeatTimer = window.setInterval(sendHeartbeat, heartbeatIntervalMs);
    }

    if (!skipInactivityLock) {
        ['pointerdown', 'keydown', 'touchstart', 'mousemove'].forEach(function (eventName) {
            document.addEventListener(eventName, resetTimer, { passive: true });
        });
        window.addEventListener('pageshow', resetTimer);
        resetTimer();
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            target = target && target.parentElement ? target.parentElement : null;
        }
        if (!target || typeof target.closest !== 'function') {
            return;
        }
        var explicitLock = target.closest('[data-posmain-main-lock], #posHeaderLockBtn, [data-posmain-leave-page]');
        var logoutLink = target.closest(
            'a[href="do/do_logout.php"], a[href="./do/do_logout.php"], a[href="../do/do_logout.php"]'
        );
        if (!explicitLock && !logoutLink) {
            return;
        }
        event.preventDefault();
        lockSession();
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            sendHeartbeat();
            resetTimer();
        }
    });

    window.addEventListener('pageshow', startHeartbeat);
    startHeartbeat();
})();
</script>
