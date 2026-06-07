<?php
require_once __DIR__ . '/csrf.php';
$pulse_csrf_token = csrf_token('pulse');
?>
<script>
(function () {
    window.POSMAIN_PULSE_CSRF_TOKEN = <?= json_encode($pulse_csrf_token, JSON_UNESCAPED_UNICODE) ?>;
    if (!window.jQuery || typeof window.jQuery.ajaxSetup !== 'function') {
        return;
    }
    window.jQuery.ajaxSetup({
        beforeSend: function (xhr, settings) {
            var method = ((settings && (settings.type || settings.method)) || 'GET').toUpperCase();
            if (!/^(POST|PUT|PATCH|DELETE)$/.test(method) || !window.POSMAIN_PULSE_CSRF_TOKEN) {
                return;
            }
            xhr.setRequestHeader('X-CSRF-Token', window.POSMAIN_PULSE_CSRF_TOKEN);
            xhr.setRequestHeader('X-POSMAIN-CSRF-Token', window.POSMAIN_PULSE_CSRF_TOKEN);
        }
    });
})();
</script>
