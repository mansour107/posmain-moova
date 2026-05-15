<?php

$page = moovaTokenSource('moova_integration.php');
$integration = moovaTokenSource('classes/MoovaPosIntegration.php');
$rotation = moovaTokenSource('docs/production/moova_token_rotation.md');

moovaTokenAssertContains("auth_guard_has_permission('moova.manage'", $page, 'Moova page should use named moova.manage permission');
moovaTokenAssertContains('moova_device_token_viewed', $page, 'Moova page should audit full token views');
moovaTokenAssertContains("'target_type' => 'moova_pos_shop_link'", $page, 'Token view audit should identify the Moova link');
moovaTokenAssertContains("'device_token_last4'", $page, 'Token view audit should keep token metadata limited to last4');
moovaTokenAssertContains('$visibleDeviceToken = ($canManageMoova && $activeMoovaLink)', $page, 'Full token should be gated by Moova management permission');

moovaTokenAssertContains('COALESCE(r.edit_sales, 0) AS edit_sales', $integration, 'Moova manager check should include edit_sales bridge');
moovaTokenAssertContains('COALESCE(r.sid_sales, 0) AS sid_sales', $integration, 'Moova manager check should include sid_sales bridge');
moovaTokenAssertContains("moova_device_token_hash", $integration, 'Token lookup should keep using token hash');
moovaTokenAssertContains("moova_device_token_last4", $integration, 'Token lookup should keep storing token last4');

moovaTokenAssertContains('The current Phase 5 slice does not encrypt', $rotation, 'Rotation doc should disclose at-rest token risk');
moovaTokenAssertContains('Rotate immediately', $rotation, 'Rotation doc should include incident rotation guidance');
moovaTokenAssertContains('Do not commit', $rotation, 'Rotation doc should warn against committing real tokens');

echo "moova-token-visibility-security-ok\n";

function moovaTokenSource(string $path): string
{
    $source = file_get_contents(__DIR__ . '/../../' . $path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function moovaTokenAssertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message);
    }
}
