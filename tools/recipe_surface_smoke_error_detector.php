<?php

function recipeSurfaceSmokeFatalText(string $body): bool
{
    if (preg_match('/<b>\s*(?:Fatal error|Parse error|Warning|Notice)\s*<\/b>/i', $body)) {
        return true;
    }

    if (preg_match('/(?:^|[\r\n<])\s*(?:Fatal error|Parse error|Warning|Notice)\s*:/i', $body)) {
        return true;
    }

    foreach (['SQLSTATE[', 'mysqli_sql_exception', 'sql syntax', 'uncaught throwable', 'Page Not Found'] as $needle) {
        if (stripos($body, $needle) !== false) {
            return true;
        }
    }

    return false;
}
