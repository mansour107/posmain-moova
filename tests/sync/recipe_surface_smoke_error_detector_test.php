<?php

require_once dirname(__DIR__, 2) . '/tools/recipe_surface_smoke_error_detector.php';

recipeSurfaceSmokeErrorDetectorAssert(
    !recipeSurfaceSmokeFatalText("console.error('Parse error in dynamic search:', e);\nconsole.log('Parse error:', e);"),
    'client-side parse-error log text must not fail server smoke checks'
);

recipeSurfaceSmokeErrorDetectorAssert(
    !recipeSurfaceSmokeFatalText("Swal.fire({ icon: 'warning', title: 'تنبيه' });"),
    'client-side warning labels must not fail server smoke checks'
);

recipeSurfaceSmokeErrorDetectorAssert(
    recipeSurfaceSmokeFatalText("<br />\n<b>Parse error</b>: syntax error, unexpected token in /app/page.php on line 10"),
    'PHP parse errors rendered by the server must be detected'
);

recipeSurfaceSmokeErrorDetectorAssert(
    recipeSurfaceSmokeFatalText("Fatal error: Uncaught Throwable in /app/page.php:12"),
    'plain fatal errors rendered by the server must be detected'
);

recipeSurfaceSmokeErrorDetectorAssert(
    recipeSurfaceSmokeFatalText('SQLSTATE[42000]: Syntax error or access violation'),
    'SQLSTATE failures must be detected'
);

recipeSurfaceSmokeErrorDetectorAssert(
    recipeSurfaceSmokeFatalText('mysqli_sql_exception: Table not found'),
    'mysqli SQL exceptions must be detected'
);

echo "recipe-surface-smoke-error-detector-ok\n";

function recipeSurfaceSmokeErrorDetectorAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
