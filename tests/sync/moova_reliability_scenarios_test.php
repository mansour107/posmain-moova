<?php

$root = dirname(__DIR__, 2);
$doc = phase5ScenarioSource('docs/production/moova_reliability_scenarios.md');

foreach ([
    'No live Moova credentials',
    'direct-widget first',
    'direct widget and queued poller',
    'Stale edit/cancel',
    'Device tokens are write-only, encrypted at rest',
    'structured channel, fulfillment, customer, and delivery fields',
    'pending badge, mute state, customer info, required decline reason',
    'Pilot Blockers Still Outside Local Simulation',
] as $needle) {
    phase5ScenarioAssertContains($needle, $doc, "scenario doc missing {$needle}");
}

$requiredArtifacts = [
    'docs/production/moova_mode_decision.md' => ['direct_widget', 'Queued worker apply remains disabled by default'],
    'docs/production/moova_token_rotation.md' => ['write-only secrets', 'Rotate immediately'],
    'docs/production/moova_delivery_foundation.md' => ['order_fulfillment', 'moova_delivery'],
    'docs/production/moova_cashier_ux_evidence.md' => ['Decline reason', 'Stale edit/cancel'],
    'tests/sync/moova_mode_config_test.php' => ['direct widget mode', 'queued worker mode'],
    'tests/sync/moova_direct_queued_convergence_test.php' => ['normalizeIdempotencyKey', 'normalizePayloadHash'],
    'tests/sync/moova_pos_mutation_convergence_test.php' => ['MoovaChangeOrderApplyService', 'POS_ORDER_LINES_CHANGED'],
    'tests/sync/moova_token_visibility_security_test.php' => ['moova_device_token_encrypted', 'auth_guard_has_permission'],
    'tests/sync/moova_delivery_foundation_test.php' => ['order_fulfillment', 'moova_delivery'],
    'tests/sync/phase5_order_fulfillment_service_test.php' => ['upsertMoovaFulfillment', 'moova_delivery'],
    'tests/sync/moova_cashier_ux_contract_test.php' => ['declineReasonRequired', 'POS_ORDER_LINES_CHANGED'],
    'tools/moova_reachability_smoke.php' => ['pos_drop', 'moova_drop'],
];

foreach ($requiredArtifacts as $path => $needles) {
    $source = phase5ScenarioSource($path);
    foreach ($needles as $needle) {
        phase5ScenarioAssertContains($needle, $source, "{$path} missing {$needle}");
    }
}

$modeConfig = phase5ScenarioSource('config/app_config.php');
phase5ScenarioAssertContains("'queued_worker_requires_acceptance' => true", $modeConfig, 'queued worker acceptance guard missing');
phase5ScenarioAssertContains('POSMAIN_MOOVA_MODE', phase5ScenarioSource('.env.example'), 'mode env example missing');

$cashierUx = phase5ScenarioSource('assets/moova-pos-widget/pos-widget.js');
foreach ([
    'messageForPosBridgeCode',
    'POS_ORDER_LINES_CHANGED',
    'DEVICE_TOKEN_REQUIRED',
    'MOOVA_UNREACHABLE',
    'POS_UNREACHABLE',
] as $needle) {
    phase5ScenarioAssertContains($needle, $cashierUx, "widget scenario mapping missing {$needle}");
}

echo "moova-reliability-scenarios-ok\n";

function phase5ScenarioSource(string $path): string
{
    $absolute = dirname(__DIR__, 2) . '/' . $path;
    if (!is_file($absolute)) {
        throw new RuntimeException('Missing required artifact: ' . $path);
    }

    $source = file_get_contents($absolute);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function phase5ScenarioAssertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message);
    }
}
