<?php

require_once __DIR__ . '/../../classes/MoovaPosIntegration.php';
require_once __DIR__ . '/../../classes/PosOrderService.php';
require_once __DIR__ . '/../../classes/Moova/MoovaLocalIngestService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';

function moovaStructuralRuntimeAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: (file_exists('/.dockerenv') ? 'mysql' : '127.0.0.1');
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: (file_exists('/.dockerenv') ? 3306 : 3307));
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2';

$conn = @new mysqli($host, $user, $pass, $db, $port);
if ($conn->connect_error) {
    fwrite(STDERR, "MySQL unavailable: {$conn->connect_error}\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

MoovaPosIntegration::ensureSchema($conn);

$link = $conn->query("
    SELECT *
    FROM moova_pos_shop_links
    WHERE status = 'active'
    ORDER BY id DESC
    LIMIT 1
")->fetch_assoc();
moovaStructuralRuntimeAssert(is_array($link), 'active moova link missing');

$item = $conn->query("
    SELECT id
    FROM myitems
    WHERE isdeleted = 0 AND tenant = 0 AND branch = 0
    ORDER BY id ASC
    LIMIT 1
")->fetch_assoc();
$table = $conn->query("
    SELECT id, tname
    FROM tables
    WHERE isdeleted = 0
    ORDER BY id ASC
    LIMIT 1
")->fetch_assoc();
moovaStructuralRuntimeAssert($item && $table, 'fixture items/tables missing');

$prefix = 'structural-runtime-' . bin2hex(random_bytes(4));
$scope = ['tenant' => 0, 'branch' => 0, 'user_id' => 1];
$service = new PosOrderService();
$ingest = new MoovaLocalIngestService();

$conn->query("
    DELETE FROM moova_pos_table_links
    WHERE moova_branch_id = '{$conn->real_escape_string((string) $link['moova_branch_id'])}'
      AND moova_table_id = '1'
      AND pos_tenant = 0
      AND pos_branch = 0
");

$conn->begin_transaction();
try {
    $result = $service->createOrMergeMoovaTableOrder($conn, $scope, [
        'cofeOrderId' => $prefix . '-prefixed-item',
        'branchId' => (string) $link['moova_branch_id'],
        'tableNumber' => '1',
        'items' => [
            ['itemId' => 'pos-item-' . (int) $item['id'], 'qty' => 1],
        ],
    ]);
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'prefixed item order failed: ' . $e->getMessage() . "\n");
    exit(1);
}

moovaStructuralRuntimeAssert((int) ($result['order_id'] ?? 0) > 0, 'prefixed item order should create POS order');

$learnedLink = $conn->query("
    SELECT pos_table_id
    FROM moova_pos_table_links
    WHERE moova_branch_id = '{$conn->real_escape_string((string) $link['moova_branch_id'])}'
      AND moova_table_id = '1'
      AND pos_tenant = 0
      AND pos_branch = 0
      AND status = 'active'
    LIMIT 1
")->fetch_assoc();
moovaStructuralRuntimeAssert(
    $learnedLink && (int) $learnedLink['pos_table_id'] === (int) $table['id'],
    'table link should be learned after exact table match'
);

$widgetPayload = [
    'cofeOrderId' => $prefix . '-mutation-prefixed',
    'branchId' => (string) $link['moova_branch_id'],
    'tableNumber' => '1',
    'idempotencyKey' => $prefix . ':mutation',
    'items' => [
        ['itemId' => 'pos-item-' . (int) $item['id'], 'qty' => 1],
    ],
];
$idempotencyKey = $ingest->normalizeIdempotencyKey($widgetPayload, 'new_order');
$requestHash = $ingest->normalizePayloadHash($widgetPayload);
$posPayload = $ingest->normalizeNewOrderForPos($widgetPayload);
moovaStructuralRuntimeAssert(
    ($posPayload['items'][0]['itemId'] ?? '') === (string) (int) $item['id'],
    'ingest should normalize pos-item prefix before apply'
);

$conn->begin_transaction();
try {
    $mutation = (new PosOrderMutationService())->confirmMoovaOrder($conn, [
        'link' => $link,
        'payload' => $posPayload,
    ], [
        'idempotency_key' => $idempotencyKey,
        'request_hash' => $requestHash,
        'request_json' => json_encode($widgetPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'moova_order_id' => (string) $widgetPayload['cofeOrderId'],
        'moova_branch_id' => (string) $widgetPayload['branchId'],
        'user_id' => 1,
        'response_mode' => 'direct',
    ]);
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'confirmMoovaOrder prefixed item failed: ' . $e->getMessage() . "\n");
    exit(1);
}

moovaStructuralRuntimeAssert(!empty($mutation['response']['success']), 'confirmMoovaOrder should succeed for prefixed item id');

$proxySource = file_get_contents(__DIR__ . '/../../moova_pos_proxy.php');
moovaStructuralRuntimeAssert(
    strpos($proxySource, 'function moova_proxy_local_passive_bridge_payload') !== false,
    'proxy fallback helper should exist for degraded Moova state'
);
moovaStructuralRuntimeAssert(
    strpos($proxySource, "'remoteReachable' => false") !== false,
    'proxy fallback payload should expose remoteReachable=false'
);

$topology = null;
if ($host !== 'mysql') {
    $topology = json_decode((string) shell_exec('php ' . escapeshellarg(__DIR__ . '/../../tools/moova_local_topology_check.php') . ' 2>/dev/null'), true);
    moovaStructuralRuntimeAssert(!empty($topology['ok']), 'live topology check should pass with POS + Moova up');
}

echo "moova-structural-fixes-runtime-ok\n";
echo json_encode([
    'prefixed_item_order_id' => (int) ($result['order_id'] ?? 0),
    'mutation_order_id' => (int) ($mutation['order_id'] ?? 0),
    'learned_table_id' => (int) ($learnedLink['pos_table_id'] ?? 0),
    'topology_ok' => is_array($topology) ? !empty($topology['ok']) : null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
