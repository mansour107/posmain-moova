<!-- POS session guard: invalidate bfcache/back navigation after shift close or lock -->
<script>
(function () {
    var POS_LOGOUT_URL = 'pos_barcode.php?logout=1';
    var reauthInFlight = false;

    function isPosSellingSurface() {
        return !!document.getElementById('posForm');
    }

    function forcePosReauth() {
        try {
            sessionStorage.setItem('pos_locked', '1');
            sessionStorage.setItem('pos_shift_closed', '1');
        } catch (e) {
            // ignore storage failures
        }
        window.location.replace(POS_LOGOUT_URL);
    }

    function clearShiftClosedFlagIfLockedOut() {
        if (isPosSellingSurface()) {
            return;
        }

        try {
            sessionStorage.removeItem('pos_shift_closed');
        } catch (e) {
            // ignore storage failures
        }
    }

    // Detect that the current document was restored from history (back/forward),
    // which is exactly the case where the server-side gate did NOT re-run.
    function isBackForwardNavigation(event) {
        if (event && event.persisted) {
            return true; // restored from bfcache
        }
        try {
            var entries = performance.getEntriesByType('navigation');
            if (entries && entries.length && entries[0].type === 'back_forward') {
                return true;
            }
        } catch (e) {
            // ignore
        }
        try {
            // Legacy fallback for older browsers.
            if (performance.navigation && performance.navigation.type === 2) {
                return true;
            }
        } catch (e) {
            // ignore
        }
        return false;
    }

    // Server-authoritative check: if the selling surface was restored from history,
    // ask the server whether this session may still sell. Redirect to re-auth when
    // the shift is closed/locked, regardless of browser cache behaviour. This does
    // NOT lock a genuinely-open shift, so normal back navigation keeps working.
    function verifyServerSessionThenMaybeReauth() {
        if (reauthInFlight || !isPosSellingSurface()) {
            return;
        }
        reauthInFlight = true;
        fetch('pos_session_status.php', {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (response) {
                return response.ok ? response.json() : { authenticated: false };
            })
            .then(function (data) {
                if (!data || data.authenticated !== true || data.shift_open !== true) {
                    forcePosReauth();
                } else {
                    reauthInFlight = false;
                }
            })
            .catch(function () {
                // Network error: leave the page as-is. Server write endpoints still
                // enforce authorization (403), so no order/close can succeed.
                reauthInFlight = false;
            });
    }

    clearShiftClosedFlagIfLockedOut();

    window.addEventListener('pageshow', function (event) {
        // Fast path: known-closed flag → immediate re-auth without a round trip.
        try {
            if (sessionStorage.getItem('pos_shift_closed') === '1' && isPosSellingSurface()) {
                forcePosReauth();
                return;
            }
        } catch (e) {
            // ignore storage failures
        }

        // Authoritative path: any history restore of the selling surface is verified
        // against the server so a closed shift can never be revived from cache.
        if (isBackForwardNavigation(event) && isPosSellingSurface()) {
            verifyServerSessionThenMaybeReauth();
            return;
        }

        clearShiftClosedFlagIfLockedOut();
    });

    window.addEventListener('popstate', function () {
        if (!isPosSellingSurface()) {
            return;
        }
        try {
            if (sessionStorage.getItem('pos_shift_closed') === '1') {
                forcePosReauth();
                return;
            }
        } catch (e) {
            // ignore storage failures
        }
        verifyServerSessionThenMaybeReauth();
    });

    window.posmainMarkShiftClosing = function () {
        try {
            sessionStorage.setItem('pos_shift_closed', '1');
            sessionStorage.setItem('pos_locked', '1');
        } catch (e) {
            // ignore storage failures
        }
    };
})();
</script>
