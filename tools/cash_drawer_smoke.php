<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../classes/Pos/Service/CashDrawerHardwareService.php';

$action = 'status';
putenv('POSMAIN_CASH_DRAWER_MODE=disabled');

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === 'open' || $arg === 'status') {
        $action = $arg;
        continue;
    }
    if (strpos($arg, '--mode=') === 0) {
        putenv('POSMAIN_CASH_DRAWER_MODE=' . substr($arg, 7));
        continue;
    }
    if (strpos($arg, '--host=') === 0) {
        putenv('POSMAIN_CASH_DRAWER_HOST=' . substr($arg, 7));
        putenv('POSMAIN_CASH_DRAWER_MODE=network');
        continue;
    }
}

$service = new CashDrawerHardwareService();
$config = $service->resolveDriverConfig(null, ['tenant' => 0, 'branch' => 0]);

fwrite(STDOUT, 'driver=' . ($config['driver'] ?? 'unknown') . PHP_EOL);

if ($action === 'open') {
    try {
        $result = $service->open($config);
        fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
        exit(($result['opened'] ?? false) ? 0 : 1);
    } catch (RuntimeException $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

$result = $service->readStatus($config);
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
exit(0);
