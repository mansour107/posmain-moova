<?php

require_once __DIR__ . '/../../classes/Pos/Service/BusinessDayService.php';

$service = new BusinessDayService();

businessDayUnitAssert($service->normalizeCutoffHour(-3) === 0, 'normalize clamps low');
businessDayUnitAssert($service->normalizeCutoffHour(30) === 23, 'normalize clamps high');
businessDayUnitAssert($service->normalizeCutoffHour(6) === 6, 'normalize keeps valid');

businessDayUnitAssert(
    $service->businessDayForTimestamp('2026-07-10 05:59:59', 6) === '2026-07-09',
    '05:59 with cutoff 6 belongs to previous day'
);
businessDayUnitAssert(
    $service->businessDayForTimestamp('2026-07-10 06:00:00', 6) === '2026-07-10',
    '06:00 with cutoff 6 belongs to same day'
);
businessDayUnitAssert(
    $service->businessDayForTimestamp('2026-07-10 03:00:00', 4) === '2026-07-09',
    '03:00 with cutoff 4 belongs to previous day'
);
businessDayUnitAssert(
    $service->businessDayForTimestamp('2026-07-10 04:00:00', 4) === '2026-07-10',
    '04:00 with cutoff 4 belongs to same day'
);

$bounds = $service->windowBounds('2026-07-09', 6);
businessDayUnitAssert($bounds['start_at'] === '2026-07-09 06:00:00', 'window start');
businessDayUnitAssert($bounds['end_at'] === '2026-07-10 06:00:00', 'window end');
businessDayUnitAssert($bounds['cutoff_hour'] === 6, 'window cutoff');

businessDayUnitAssert(
    $service->previousBusinessDay('2026-07-09') === '2026-07-08',
    'previous business day'
);

$expr = $service->timestampBusinessDayExpression('op.created_at', 'pbs');
businessDayUnitAssert(strpos($expr, 'DATE_SUB(op.created_at') !== false, 'timestamp expression uses DATE_SUB');
businessDayUnitAssert(
    $service->sessionBusinessDayExpression('ds') === $service->timestampBusinessDayExpression('ds.opened_at'),
    'session expression delegates to timestamp expression'
);

echo "business-day-service-unit-ok\n";

function businessDayUnitAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "business-day-service-unit-fail: {$message}\n");
        exit(1);
    }
}
