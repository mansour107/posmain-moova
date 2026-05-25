<?php

$root = dirname(__DIR__, 2);
$endpoint = paidReversalContractSource($root . '/ajax/refund_order.php');
$mutation = paidReversalContractSource($root . '/classes/Pos/Service/PosOrderMutationService.php');
$lifecycle = paidReversalContractSource($root . '/classes/Recipe/RecipeOrderLifecycleService.php');
$recentOrders = paidReversalContractSource($root . '/ajax/get_recent_orders.php');
$posContent = paidReversalContractSource($root . '/includes/pos_content.php');
$posBarcodeJs = paidReversalContractSource($root . '/js/pos_barcode.js');
$runtimeTest = paidReversalContractSource($root . '/tests/sync/recipe_paid_reversal_endpoint_runtime_test.php');

paidReversalContractAssertContains("require_csrf('pos_browser')", $endpoint, 'paid reversal endpoint must use POS CSRF protection');
paidReversalContractAssertContains("require_permission(\$action === 'void' ? 'pos.void.paid' : 'pos.refund'", $endpoint, 'paid reversal endpoint must enforce refund/void permissions');
paidReversalContractAssertContains('reversePaidOrder', $endpoint, 'paid reversal endpoint should delegate to the POS mutation service');
paidReversalContractAssertContains('recordOrderSnapshot', $endpoint, 'paid reversal endpoint should emit order sync snapshot');
paidReversalContractAssertNotContains('new RecipeOrderLifecycleService', $endpoint, 'endpoint must not instantiate recipe lifecycle directly');
paidReversalContractAssertNotContains('explodeOrderLine', $endpoint, 'endpoint must not calculate recipe requirements directly');
paidReversalContractAssertNotContains('recordRecipeConsumption', $endpoint, 'endpoint must not write recipe stock directly');

paidReversalContractAssertContains('public function reversePaidOrder', $mutation, 'POS mutation service should own paid reversal workflow');
paidReversalContractAssertContains('onOrderRefunded', $mutation, 'paid refund should call recipe refund lifecycle centrally');
paidReversalContractAssertContains('onOrderVoided', $mutation, 'paid void should call recipe void lifecycle centrally');
paidReversalContractAssertContains('resolveRecipeRefundPolicy', $mutation, 'service should resolve configured/manager-choice recipe stock policy');
paidReversalContractAssertContains('requireApprovedIfNeeded', $mutation, 'paid reversal should honor manager approval gate when enabled');

paidReversalContractAssertContains('RecipeSettingsService', $lifecycle, 'recipe lifecycle should own fallback refund policy settings');
paidReversalContractAssertContains('resolveRefundPolicy', $lifecycle, 'recipe lifecycle should normalize configured and explicit refund stock policies');
paidReversalContractAssertContains("'manager_choice'", $lifecycle, 'recipe lifecycle should handle manager-choice fallback safely');
paidReversalContractAssertContains("!== 'consumed'", $lifecycle, 'recipe lifecycle should not reverse already refunded/wasted/voided usage rows');

paidReversalContractAssertContains('can_refund', $recentOrders, 'recent orders payload should expose paid refund capability');
paidReversalContractAssertContains('can_void', $recentOrders, 'recent orders payload should expose paid void capability');
paidReversalContractAssertContains("auth_guard_has_permission('pos.refund'", $recentOrders, 'recent orders capability should use named refund permission');
paidReversalContractAssertContains("auth_guard_has_permission('pos.void.paid'", $recentOrders, 'recent orders capability should use named void permission');

paidReversalContractAssertContains('reversePaidOrder', $posContent, 'POS recent-orders UI should expose paid reversal action');
paidReversalContractAssertContains('ajax/refund_order.php', $posContent, 'POS UI should call the paid reversal endpoint');
paidReversalContractAssertContains('refund_stock_policy', $posContent, 'POS UI should send selected recipe stock policy');

paidReversalContractAssertContains('can_refund', $posBarcodeJs, 'active recent-orders JS renderer should consume refund capability');
paidReversalContractAssertContains('can_void', $posBarcodeJs, 'active recent-orders JS renderer should consume void capability');
paidReversalContractAssertContains('reverse-paid-order', $posBarcodeJs, 'active recent-orders JS renderer should expose paid reversal action');
paidReversalContractAssertContains('reversePaidOrder(${order.id}', $posBarcodeJs, 'active recent-orders JS renderer should call the paid reversal dialog');

paidReversalContractAssertContains('ajax/refund_order.php', $runtimeTest, 'runtime test should execute the real paid reversal endpoint');
paidReversalContractAssertContains('CREATE DATABASE', $runtimeTest, 'runtime test should isolate endpoint writes in a temporary database');
paidReversalContractAssertContains('DROP DATABASE IF EXISTS', $runtimeTest, 'runtime test should clean up its temporary database');
paidReversalContractAssertContains('HTTP_X_CSRF_TOKEN', $runtimeTest, 'runtime test should provide endpoint CSRF header');
paidReversalContractAssertContains("'pos_browser' => \$csrf", $runtimeTest, 'runtime test should seed the POS browser CSRF namespace');
paidReversalContractAssertContains('pos_request_keys', $runtimeTest, 'runtime test should verify endpoint idempotency storage');
paidReversalContractAssertContains('order_events', $runtimeTest, 'runtime test should verify endpoint order event writes');
paidReversalContractAssertContains("'POSMAIN_SYNC_OUTBOX_ENABLED' => '0'", $runtimeTest, 'runtime test should disable sync outbox for isolated endpoint smoke');

echo "recipe-paid-reversal-endpoint-contract-ok\n";

function paidReversalContractSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function paidReversalContractAssertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message);
    }
}

function paidReversalContractAssertNotContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new RuntimeException($message);
    }
}
