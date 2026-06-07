<?php

require_once __DIR__ . '/kody_merge_e2e_helpers.php';

if (($argv[1] ?? '') === '--child') {
    kody_merge_e2e_child_handler($argv[2] ?? '');
    exit(0);
}

$serverConn = kody_merge_e2e_connect_server();
if (!$serverConn) {
    echo "kody-merge-e2e-runtime-skipped-db-unavailable\n";
    exit(0);
}

$db = 'posmain_kody_merge_e2e_' . getmypid();
$testFile = __FILE__;

try {
    $serverConn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $serverConn->select_db($db);
    kody_merge_e2e_create_schema($serverConn);
    kody_merge_e2e_seed_rows($serverConn);

    // ─── Customer visits ───
    $addNoCsrf = kody_merge_e2e_run_child($testFile, $db, [
        'case' => 'customer_visit_add',
        'logged_in' => true,
        'csrf' => false,
    ]);
    kody_merge_e2e_assert(
        ($addNoCsrf['code'] ?? '') === 'CSRF_INVALID' || ($addNoCsrf['body'] ?? '') === 'CSRF_INVALID',
        'customer visit add without CSRF should be rejected'
    );
    kody_merge_e2e_assert(kody_merge_e2e_count($serverConn, 'SELECT COUNT(*) AS c FROM customer_visits WHERE isdeleted = 0') === 2, 'customer visit add without CSRF should not insert');

    $addOk = kody_merge_e2e_run_child($testFile, $db, [
        'case' => 'customer_visit_add',
        'logged_in' => true,
        'csrf' => true,
    ]);
    kody_merge_e2e_assert(($addOk['success'] ?? false) === true, 'customer visit add with CSRF should succeed');
    kody_merge_e2e_assert(kody_merge_e2e_count($serverConn, 'SELECT COUNT(*) AS c FROM customer_visits WHERE isdeleted = 0') === 3, 'customer visit add with CSRF should insert one row');

    kody_merge_e2e_run_child($testFile, $db, [
        'case' => 'customer_visit_delete',
        'method' => 'GET',
        'visit_id' => 1,
        'logged_in' => true,
        'csrf' => false,
    ]);
    kody_merge_e2e_assert(
        kody_merge_e2e_count($serverConn, 'SELECT COUNT(*) AS c FROM customer_visits WHERE id = 1 AND isdeleted = 0') === 1,
        'customer visit GET delete should not soft-delete'
    );

    $deleteNoCsrf = kody_merge_e2e_run_child($testFile, $db, [
        'case' => 'customer_visit_delete',
        'method' => 'POST',
        'visit_id' => 1,
        'logged_in' => true,
        'csrf' => false,
    ]);
    kody_merge_e2e_assert(
        ($deleteNoCsrf['code'] ?? '') === 'CSRF_INVALID' || ($deleteNoCsrf['body'] ?? '') === 'CSRF_INVALID',
        'customer visit POST delete without CSRF should be rejected'
    );
    kody_merge_e2e_assert(
        kody_merge_e2e_count($serverConn, 'SELECT COUNT(*) AS c FROM customer_visits WHERE id = 1 AND isdeleted = 0') === 1,
        'customer visit POST delete without CSRF should not soft-delete'
    );

    kody_merge_e2e_run_child($testFile, $db, [
        'case' => 'customer_visit_delete',
        'method' => 'POST',
        'visit_id' => 1,
        'logged_in' => true,
        'csrf' => true,
    ]);
    kody_merge_e2e_assert(
        kody_merge_e2e_count($serverConn, 'SELECT COUNT(*) AS c FROM customer_visits WHERE id = 1 AND isdeleted = 1') === 1,
        'customer visit POST delete with CSRF should soft-delete'
    );

    $endNoCsrf = kody_merge_e2e_run_child($testFile, $db, [
        'case' => 'customer_visit_end_time',
        'visit_id' => 2,
        'end_time' => '12:30',
        'logged_in' => true,
        'csrf' => false,
    ]);
    kody_merge_e2e_assert(($endNoCsrf['success'] ?? true) === false, 'customer visit end-time without CSRF should fail');
    kody_merge_e2e_assert(($endNoCsrf['code'] ?? '') === 'CSRF_INVALID', 'customer visit end-time without CSRF should return CSRF_INVALID');

    $endOk = kody_merge_e2e_run_child($testFile, $db, [
        'case' => 'customer_visit_end_time',
        'visit_id' => 2,
        'end_time' => '12:30',
        'logged_in' => true,
        'csrf' => true,
    ]);
    kody_merge_e2e_assert(($endOk['success'] ?? false) === true, 'customer visit end-time with CSRF should succeed');
    $endRow = $serverConn->query("SELECT end_time FROM customer_visits WHERE id = 2")->fetch_assoc();
    kody_merge_e2e_assert(substr((string) ($endRow['end_time'] ?? ''), 0, 5) === '12:30', 'customer visit end-time should persist');

    // ─── Supermarket auth + CSRF ───
    $barcodeAnon = kody_merge_e2e_run_child($testFile, $db, [
        'case' => 'supermarket_barcode',
        'barcode' => 'E2E-AVAIL-001',
        'logged_in' => false,
        'pos_authenticated' => false,
        'csrf' => false,
    ]);
    kody_merge_e2e_assert(($barcodeAnon['code'] ?? '') === 'AUTH_REQUIRED', 'supermarket barcode without login should require auth');

    $barcodeNoPos = kody_merge_e2e_run_child($testFile, $db, [
        'case' => 'supermarket_barcode',
        'barcode' => 'E2E-AVAIL-001',
        'logged_in' => true,
        'pos_authenticated' => false,
        'csrf' => true,
    ]);
    kody_merge_e2e_assert(($barcodeNoPos['code'] ?? '') === 'POS_AUTH_REQUIRED', 'supermarket barcode without POS auth should be blocked');

    $barcodeNoCsrf = kody_merge_e2e_run_child($testFile, $db, [
        'case' => 'supermarket_barcode',
        'barcode' => 'E2E-AVAIL-001',
        'logged_in' => true,
        'pos_authenticated' => true,
        'csrf' => false,
    ]);
    kody_merge_e2e_assert(($barcodeNoCsrf['code'] ?? '') === 'CSRF_INVALID', 'supermarket barcode without CSRF should be rejected');

    $barcodeOk = kody_merge_e2e_run_child($testFile, $db, [
        'case' => 'supermarket_barcode',
        'barcode' => 'E2E-AVAIL-001',
        'logged_in' => true,
        'pos_authenticated' => true,
        'csrf' => true,
    ]);
    kody_merge_e2e_assert(($barcodeOk['success'] ?? false) === true, 'supermarket barcode with POS auth + CSRF should succeed');
    kody_merge_e2e_assert((int) ($barcodeOk['item']['id'] ?? 0) === 1001, 'supermarket barcode should return available item');

    $barcodeInactive = kody_merge_e2e_run_child($testFile, $db, [
        'case' => 'supermarket_barcode',
        'barcode' => 'E2E-INACT-002',
        'logged_in' => true,
        'pos_authenticated' => true,
        'csrf' => true,
    ]);
    kody_merge_e2e_assert(($barcodeInactive['success'] ?? true) === false, 'inactive catalog item should not be returned by supermarket barcode lookup');

    $barcodeBlocked = kody_merge_e2e_run_child($testFile, $db, [
        'case' => 'supermarket_barcode',
        'barcode' => 'E2E-BLOCK-004',
        'logged_in' => true,
        'pos_authenticated' => true,
        'csrf' => true,
    ]);
    kody_merge_e2e_assert(($barcodeBlocked['success'] ?? true) === false, 'manually unavailable item should not be returned by supermarket barcode lookup');

    $autoNoPos = kody_merge_e2e_run_child($testFile, $db, [
        'case' => 'supermarket_autocomplete',
        'term' => 'E2E',
        'logged_in' => true,
        'pos_authenticated' => false,
    ]);
    kody_merge_e2e_assert(($autoNoPos['code'] ?? '') === 'POS_AUTH_REQUIRED', 'supermarket autocomplete without POS auth should be blocked');

    $autoOk = kody_merge_e2e_run_child($testFile, $db, [
        'case' => 'supermarket_autocomplete',
        'term' => 'E2E Available',
        'logged_in' => true,
        'pos_authenticated' => true,
    ]);
    $autoItems = kody_merge_e2e_autocomplete_items($autoOk);
    kody_merge_e2e_assert(is_array($autoItems), 'supermarket autocomplete should return items array');
    kody_merge_e2e_assert(count($autoItems) >= 1, 'supermarket autocomplete should include available sellable item');
    kody_merge_e2e_assert(kody_merge_e2e_autocomplete_has_item_id($autoItems, 1001), 'supermarket autocomplete should include item 1001');
    kody_merge_e2e_assert(!kody_merge_e2e_autocomplete_has_item_id($autoItems, 1002), 'supermarket autocomplete should exclude inactive item');
    kody_merge_e2e_assert(!kody_merge_e2e_autocomplete_has_item_id($autoItems, 1003), 'supermarket autocomplete should exclude ingredient item');
    kody_merge_e2e_assert(!kody_merge_e2e_autocomplete_has_item_id($autoItems, 1004), 'supermarket autocomplete should exclude manually blocked item');

    // ─── Direct helper availability rules ───
    $helper = kody_merge_e2e_run_child($testFile, $db, [
        'case' => 'supermarket_lookup_helper',
    ]);
    kody_merge_e2e_assert(($helper['available_id'] ?? 0) === 1001, 'lookup helper should find available sellable item');
    kody_merge_e2e_assert(($helper['inactive_id'] ?? -1) === 0, 'lookup helper should ignore inactive item');
    kody_merge_e2e_assert(($helper['ingredient_id'] ?? -1) === 0, 'lookup helper should ignore ingredient item');
    kody_merge_e2e_assert(($helper['blocked_id'] ?? -1) === 0, 'lookup helper should ignore manually blocked item');

    // ─── Pulse ───
    $pulseNoCsrf = kody_merge_e2e_run_child($testFile, $db, [
        'case' => 'pulse_save',
        'logged_in' => true,
        'csrf' => false,
    ]);
    kody_merge_e2e_assert(($pulseNoCsrf['error'] ?? '') !== '' || ($pulseNoCsrf['code'] ?? '') === 'CSRF_INVALID', 'pulse save without CSRF should fail');
    kody_merge_e2e_assert(kody_merge_e2e_count($serverConn, 'SELECT COUNT(*) AS c FROM pulse_logs') === 0, 'pulse save without CSRF should not insert');

    $pulseOk = kody_merge_e2e_run_child($testFile, $db, [
        'case' => 'pulse_save',
        'logged_in' => true,
        'csrf' => true,
    ]);
    kody_merge_e2e_assert(($pulseOk['success'] ?? false) === true, 'pulse save with CSRF should succeed');
    kody_merge_e2e_assert(kody_merge_e2e_count($serverConn, 'SELECT COUNT(*) AS c FROM pulse_logs') === 1, 'pulse save with CSRF should insert one log');

    echo "kody-merge-e2e-runtime-ok db={$db}\n";
} finally {
    $serverConn->query("DROP DATABASE IF EXISTS `{$db}`");
    $serverConn->close();
}

function kody_merge_e2e_count(mysqli $conn, string $sql): int
{
    $row = $conn->query($sql)->fetch_assoc();
    return (int) ($row['c'] ?? 0);
}

function kody_merge_e2e_autocomplete_items(array $payload): array
{
    if (isset($payload['code'])) {
        return [];
    }

    if (isset($payload['items']) && is_array($payload['items'])) {
        return $payload['items'];
    }

    return array_is_list($payload) ? $payload : [];
}

function kody_merge_e2e_autocomplete_has_item_id(array $items, int $itemId): bool
{
    foreach ($items as $entry) {
        $candidate = $entry['item']['id'] ?? $entry['id'] ?? null;
        if ((int) $candidate === $itemId) {
            return true;
        }
    }

    return false;
}

function kody_merge_e2e_child_handler(string $json): void
{
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        fwrite(STDERR, "Invalid child payload.\n");
        exit(9);
    }

    $root = dirname(__DIR__, 2);
    kody_merge_e2e_reset_request_state();
    session_id('kodymergee2e' . getmypid() . substr(md5($json), 0, 8));
    require_once $root . '/includes/session_bootstrap.php';

    $loggedIn = !empty($payload['logged_in']);
    $posAuthenticated = !empty($payload['pos_authenticated']);
    $useCsrf = !empty($payload['csrf']);

    if ($loggedIn) {
        kody_merge_e2e_bootstrap_session([
            'csrf_namespaces' => ['customer_visits', 'pos_browser', 'pulse'],
            'pos_authenticated' => $posAuthenticated,
        ]);
    } else {
        $_SESSION = [];
    }

    switch ((string) ($payload['case'] ?? '')) {
        case 'customer_visit_add':
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['PHP_SELF'] = 'do/doadd_customer_visit.php';
            $_SERVER['SCRIPT_NAME'] = 'do/doadd_customer_visit.php';
            $_SERVER['HTTP_ACCEPT'] = 'application/json';
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
            $_POST = [
                'gender' => 'male',
                'age_group' => '18_25',
                'mode' => 'solo',
                'order_value' => 'under60',
                'type' => 'new',
            ];
            if ($useCsrf) {
                $_POST['csrf_token'] = $_SESSION['posmain_csrf_tokens']['customer_visits'];
            }
            chdir($root . '/do');
            require $root . '/do/doadd_customer_visit.php';
            break;

        case 'customer_visit_delete':
            $_SERVER['REQUEST_METHOD'] = strtoupper((string) ($payload['method'] ?? 'POST'));
            $_SERVER['PHP_SELF'] = 'do/dodel_customer_visit.php';
            $_SERVER['SCRIPT_NAME'] = 'do/dodel_customer_visit.php';
            $_SERVER['HTTP_ACCEPT'] = 'application/json';
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
            $_POST = [];
            $_GET = [];
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $_POST['id'] = (int) ($payload['visit_id'] ?? 0);
                if ($useCsrf) {
                    $_POST['csrf_token'] = $_SESSION['posmain_csrf_tokens']['customer_visits'];
                }
            } else {
                $_GET['id'] = (int) ($payload['visit_id'] ?? 0);
            }
            chdir($root . '/do');
            require $root . '/do/dodel_customer_visit.php';
            break;

        case 'customer_visit_end_time':
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['PHP_SELF'] = 'ajax/update_customer_visit_end_time.php';
            $_SERVER['SCRIPT_NAME'] = 'ajax/update_customer_visit_end_time.php';
            $_SERVER['HTTP_ACCEPT'] = 'application/json';
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
            $_POST = [
                'id' => (int) ($payload['visit_id'] ?? 0),
                'end_time' => (string) ($payload['end_time'] ?? ''),
            ];
            if ($useCsrf) {
                $_POST['csrf_token'] = $_SESSION['posmain_csrf_tokens']['customer_visits'];
            }
            chdir($root . '/ajax');
            require $root . '/ajax/update_customer_visit_end_time.php';
            break;

        case 'supermarket_barcode':
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['PHP_SELF'] = 'ajax/search_item_supermarket.php';
            $_SERVER['SCRIPT_NAME'] = 'ajax/search_item_supermarket.php';
            $_SERVER['HTTP_ACCEPT'] = 'application/json';
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
            $_POST = ['barcode' => (string) ($payload['barcode'] ?? '')];
            if ($useCsrf) {
                $_POST['csrf_token'] = $_SESSION['posmain_csrf_tokens']['pos_browser'];
                $_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['posmain_csrf_tokens']['pos_browser'];
                $_SERVER['HTTP_X_POSMAIN_CSRF_TOKEN'] = $_SESSION['posmain_csrf_tokens']['pos_browser'];
            }
            chdir($root . '/ajax');
            require $root . '/ajax/search_item_supermarket.php';
            break;

        case 'supermarket_autocomplete':
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['PHP_SELF'] = 'ajax/search_items_autocomplete.php';
            $_SERVER['SCRIPT_NAME'] = 'ajax/search_items_autocomplete.php';
            $_SERVER['HTTP_ACCEPT'] = 'application/json';
            $_GET = ['term' => (string) ($payload['term'] ?? '')];
            $_POST = [];
            chdir($root . '/ajax');
            require $root . '/ajax/search_items_autocomplete.php';
            break;

        case 'supermarket_lookup_helper':
            chdir($root);
            require_once $root . '/includes/connect.php';
            require_once $root . '/includes/supermarket_item_lookup.php';
            $available = posmain_supermarket_lookup_item($conn, 'E2E-AVAIL-001');
            $inactive = posmain_supermarket_lookup_item($conn, 'E2E-INACT-002');
            $ingredient = posmain_supermarket_lookup_item($conn, 'E2E-INGR-003');
            $blocked = posmain_supermarket_lookup_item($conn, 'E2E-BLOCK-004');
            kody_merge_e2e_child_finish([
                'available_id' => (int) ($available['id'] ?? 0),
                'inactive_id' => (int) ($inactive['id'] ?? 0),
                'ingredient_id' => (int) ($ingredient['id'] ?? 0),
                'blocked_id' => (int) ($blocked['id'] ?? 0),
            ]);
            break;

        case 'pulse_save':
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['PHP_SELF'] = 'ajax/pulse_ajax.php';
            $_SERVER['SCRIPT_NAME'] = 'ajax/pulse_ajax.php';
            $_SERVER['HTTP_ACCEPT'] = 'application/json';
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
            $_POST = [
                'action' => 'save_log',
                'employee_id' => 1,
                'type_id' => 1,
                'category' => 'positive',
                'rating' => 5,
                'notes' => 'e2e',
            ];
            if ($useCsrf) {
                $_POST['csrf_token'] = $_SESSION['posmain_csrf_tokens']['pulse'];
                $_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['posmain_csrf_tokens']['pulse'];
            }
            chdir($root . '/ajax');
            require $root . '/ajax/pulse_ajax.php';
            break;

        default:
            fwrite(STDERR, "Unknown child case.\n");
            exit(9);
    }
}
