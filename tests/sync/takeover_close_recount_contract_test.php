<?php

$root = dirname(__DIR__, 2);

function takeoverCloseRecountContractAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$countService = (string) file_get_contents($root . '/classes/Pos/Service/ShiftCountService.php');
$begin = (string) file_get_contents($root . '/do/do_begin_takeover_close_count.php');
$submit = (string) file_get_contents($root . '/do/do_submit_takeover_close_count.php');
$takeover = (string) file_get_contents($root . '/do/do_takeover_drawer_session.php');
$manifest = (string) file_get_contents($root . '/config/rbac_route_manifest.php');
$recovery = (string) file_get_contents($root . '/elements/pos/shift_recovery_overlay.php');

takeoverCloseRecountContractAssert(
    strpos($countService, 'beginTakeoverCloseCount') !== false,
    'ShiftCountService must expose beginTakeoverCloseCount'
);
takeoverCloseRecountContractAssert(
    strpos($countService, 'submitTakeoverCloseCount') !== false,
    'ShiftCountService must expose submitTakeoverCloseCount'
);
takeoverCloseRecountContractAssert(
    strpos($countService, 'peekTakeoverCloseCount') !== false
        && strpos($countService, 'clearTakeoverCloseCount') !== false,
    'ShiftCountService must expose peek/clear takeover close-count'
);
takeoverCloseRecountContractAssert(
    strpos($takeover, 'peekTakeoverCloseCount') !== false
        && strpos($takeover, 'clearTakeoverCloseCount') !== false,
    'takeover endpoint must peek then clear finalized close-count'
);
takeoverCloseRecountContractAssert(
    strpos($countService, "'status' => 'recount'") !== false
        && strpos($countService, 'takeover_with_variance') !== false,
    'takeover close count must support recount and variance statuses'
);
takeoverCloseRecountContractAssert(is_file($root . '/do/do_begin_takeover_close_count.php'), 'begin endpoint missing');
takeoverCloseRecountContractAssert(is_file($root . '/do/do_submit_takeover_close_count.php'), 'submit endpoint missing');
takeoverCloseRecountContractAssert(
    strpos($manifest, 'do/do_begin_takeover_close_count.php') !== false
        && strpos($manifest, 'do/do_submit_takeover_close_count.php') !== false,
    'RBAC manifest must register takeover close-count routes'
);
takeoverCloseRecountContractAssert(
    strpos($recovery, 'do_submit_takeover_close_count.php') !== false
        && strpos($recovery, 'posTakeoverVariance') !== false,
    'recovery overlay must wire recount + variance UI'
);
takeoverCloseRecountContractAssert(
    strpos($begin, 'pos.shift.force_close') !== false,
    'begin takeover close count requires force_close'
);
takeoverCloseRecountContractAssert(
    strpos($submit, 'shift_takeover_count') !== false,
    'submit takeover close count must use dedicated CSRF'
);

echo "takeover-close-recount-contract-ok\n";
