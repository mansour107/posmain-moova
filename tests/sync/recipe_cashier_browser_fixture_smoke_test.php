<?php

$root = dirname(__DIR__, 2);
$tool = $root . '/tools/recipe_cashier_browser_fixture.php';

$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ' --smoke --json 2>&1';
$lines = [];
exec($command, $lines, $exitCode);
$output = implode("\n", $lines);
$payload = json_decode($output, true);

recipeCashierBrowserFixtureSmokeAssert($exitCode === 0, "cashier browser fixture smoke should exit cleanly:\n" . $output);
recipeCashierBrowserFixtureSmokeAssert(is_array($payload), 'cashier browser fixture smoke should emit JSON');
recipeCashierBrowserFixtureSmokeAssert(($payload['ok'] ?? false) === true, 'cashier browser fixture smoke should pass');
recipeCashierBrowserFixtureSmokeAssert(($payload['local_temp_db_only'] ?? false) === true, 'cashier browser fixture should declare temp DB isolation');
recipeCashierBrowserFixtureSmokeAssert(($payload['read_only'] ?? true) === false, 'cashier browser fixture should honestly report that it creates temp fixture data');
recipeCashierBrowserFixtureSmokeAssert((int) ($payload['pilot_item_id'] ?? 0) === 10, 'cashier browser fixture should expose the seeded pilot item id');
recipeCashierBrowserFixtureSmokeAssert((int) ($payload['ingredient_item_id'] ?? 0) === 12, 'cashier browser fixture should expose the seeded ingredient item id');
recipeCashierBrowserFixtureSmokeAssert(($payload['ingredient_start_qty'] ?? '') === '10.000000', 'cashier browser fixture should expose the starting ingredient balance');

$smoke = $payload['smoke'] ?? null;
recipeCashierBrowserFixtureSmokeAssert(is_array($smoke), 'cashier browser fixture should include smoke details');
recipeCashierBrowserFixtureSmokeAssert(($smoke['ok'] ?? false) === true, 'cashier browser fixture POS page render smoke should pass');
recipeCashierBrowserFixtureSmokeAssert((int) ($smoke['status'] ?? 0) === 200, 'cashier browser fixture POS page should render HTTP 200');
recipeCashierBrowserFixtureSmokeAssert(($smoke['missing_snippets'] ?? []) === [], 'cashier browser fixture POS page should include expected cashier snippets');
recipeCashierBrowserFixtureSmokeAssert(($smoke['fatal_or_sql_text'] ?? true) === false, 'cashier browser fixture POS page should not expose fatal or SQL text');

$paidReversal = $payload['paid_reversal_smoke'] ?? null;
recipeCashierBrowserFixtureSmokeAssert(is_array($paidReversal), 'cashier browser fixture should include paid reversal smoke details');
recipeCashierBrowserFixtureSmokeAssert(($paidReversal['ok'] ?? false) === true, 'cashier browser fixture paid reversal smoke should pass');
recipeCashierBrowserFixtureSmokeAssert((int) ($paidReversal['recent_orders_status'] ?? 0) === 200, 'cashier browser fixture recent orders endpoint should render HTTP 200');
recipeCashierBrowserFixtureSmokeAssert((int) ($paidReversal['recent_orders_count'] ?? 0) >= 1, 'cashier browser fixture should seed a recent paid order');
recipeCashierBrowserFixtureSmokeAssert(($paidReversal['paid_reversible_order_seen'] ?? false) === true, 'cashier browser fixture should expose a paid reversible order');
recipeCashierBrowserFixtureSmokeAssert(($paidReversal['missing_capability_fields'] ?? []) === [], 'cashier browser fixture recent order should expose reversal capability fields');
recipeCashierBrowserFixtureSmokeAssert(($paidReversal['method_guard_code'] ?? '') === 'METHOD_NOT_ALLOWED', 'cashier browser fixture refund endpoint GET method guard should hold');
recipeCashierBrowserFixtureSmokeAssert(($paidReversal['method_guard_ok'] ?? false) === true, 'cashier browser fixture paid reversal method guard should pass');
recipeCashierBrowserFixtureSmokeAssert(($paidReversal['refund_post_success'] ?? false) === true, 'cashier browser fixture refund POST should succeed');
recipeCashierBrowserFixtureSmokeAssert(($paidReversal['refund_post_code'] ?? '') === 'OK', 'cashier browser fixture refund POST should return OK');
recipeCashierBrowserFixtureSmokeAssert(($paidReversal['refund_payment_status'] ?? '') === 'refunded', 'cashier browser fixture refund POST should return refunded status');
recipeCashierBrowserFixtureSmokeAssert(($paidReversal['refund_replay_success'] ?? false) === true, 'cashier browser fixture refund replay should succeed');
recipeCashierBrowserFixtureSmokeAssert(($paidReversal['refund_replay_request_id'] ?? '') === 'fixture-http-refund-9001-fixed', 'cashier browser fixture refund replay should return the original request id');
recipeCashierBrowserFixtureSmokeAssert(($paidReversal['refund_db_payment_status'] ?? '') === 'refunded', 'cashier browser fixture refund POST should mutate the temp DB order');
recipeCashierBrowserFixtureSmokeAssert((int) ($paidReversal['refund_db_isdeleted'] ?? 1) === 0, 'cashier browser fixture refund should keep the order visible for audit');
recipeCashierBrowserFixtureSmokeAssert((int) ($paidReversal['refund_order_event_count'] ?? 0) === 1, 'cashier browser fixture refund replay should not duplicate order events');
recipeCashierBrowserFixtureSmokeAssert((int) ($paidReversal['refund_idempotency_completed_count'] ?? 0) === 1, 'cashier browser fixture refund replay should keep one completed idempotency row');
recipeCashierBrowserFixtureSmokeAssert(($paidReversal['refund_mutation_ok'] ?? false) === true, 'cashier browser fixture paid refund mutation should pass');
recipeCashierBrowserFixtureSmokeAssert(($paidReversal['void_post_success'] ?? false) === true, 'cashier browser fixture void POST should succeed');
recipeCashierBrowserFixtureSmokeAssert(($paidReversal['void_post_code'] ?? '') === 'OK', 'cashier browser fixture void POST should return OK');
recipeCashierBrowserFixtureSmokeAssert(($paidReversal['void_payment_status'] ?? '') === 'voided', 'cashier browser fixture void POST should return voided status');
recipeCashierBrowserFixtureSmokeAssert(($paidReversal['void_replay_success'] ?? false) === true, 'cashier browser fixture void replay should succeed');
recipeCashierBrowserFixtureSmokeAssert(($paidReversal['void_replay_request_id'] ?? '') === 'fixture-http-void-9002-fixed', 'cashier browser fixture void replay should return the original request id');
recipeCashierBrowserFixtureSmokeAssert(($paidReversal['void_db_payment_status'] ?? '') === 'voided', 'cashier browser fixture void POST should mutate the temp DB order');
recipeCashierBrowserFixtureSmokeAssert((int) ($paidReversal['void_db_isdeleted'] ?? 0) === 1, 'cashier browser fixture void should hide the temp order from active lists');
recipeCashierBrowserFixtureSmokeAssert((int) ($paidReversal['void_table_case'] ?? 1) === 0, 'cashier browser fixture void should free the temp table');
recipeCashierBrowserFixtureSmokeAssert((int) ($paidReversal['void_order_event_count'] ?? 0) === 1, 'cashier browser fixture void replay should not duplicate order events');
recipeCashierBrowserFixtureSmokeAssert((int) ($paidReversal['void_idempotency_completed_count'] ?? 0) === 1, 'cashier browser fixture void replay should keep one completed idempotency row');
recipeCashierBrowserFixtureSmokeAssert(($paidReversal['void_mutation_ok'] ?? false) === true, 'cashier browser fixture paid void mutation should pass');

echo "recipe-cashier-browser-fixture-smoke-ok\n";

function recipeCashierBrowserFixtureSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
