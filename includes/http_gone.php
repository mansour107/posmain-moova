<?php

/**
 * Hard web removal for prohibited utilities.
 * Always returns 404 for non-CLI SAPIs — never connects to the database.
 */
if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
    fwrite(STDERR, "This path is web-prohibited and has no CLI entrypoint.\n");
    exit(1);
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

if (!headers_sent()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow', true);
}

echo 'Not Found';
exit;
