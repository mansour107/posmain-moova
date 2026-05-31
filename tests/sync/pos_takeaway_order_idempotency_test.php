<?php

$test = __DIR__ . '/pos_takeaway_order_service_test.php';
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($test) . ' 2>&1';
exec($command, $lines, $exitCode);

if ($exitCode !== 0) {
    throw new RuntimeException("takeaway idempotency service regression failed:\n" . implode("\n", $lines));
}

$output = implode("\n", $lines);
if (strpos($output, 'pos-takeaway-order-service-skipped-db-unavailable') !== false) {
    echo "pos-takeaway-order-idempotency-skipped-db-unavailable\n";
    exit(0);
}
if (strpos($output, 'pos-takeaway-order-service-ok') === false) {
    throw new RuntimeException("takeaway idempotency service regression did not report success:\n" . $output);
}

echo "pos-takeaway-order-idempotency-ok\n";
