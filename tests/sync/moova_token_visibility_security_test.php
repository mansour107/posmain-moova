<?php

$page = moovaTokenSource('moova_integration.php');
$integration = moovaTokenSource('classes/MoovaPosIntegration.php');
$rotation = moovaTokenSource('docs/production/moova_token_rotation.md');

moovaTokenAssertContains("auth_guard_has_permission('moova.manage'", $page, 'Moova page should use named moova.manage permission');
moovaTokenAssertNotContains('moova_device_token_viewed', $page, 'Moova page must not reveal or audit full token views');
moovaTokenAssertNotContains('$visibleDeviceToken', $page, 'Full token must never be loaded into the integration page');
moovaTokenAssertContains('$maskedDeviceToken', $page, 'Integration page should display only the token suffix');

moovaTokenAssertContains("moova_device_token_hash", $integration, 'Token lookup should keep using token hash');
moovaTokenAssertContains("moova_device_token_last4", $integration, 'Token lookup should keep storing token last4');
moovaTokenAssertContains('moova_device_token_encrypted', $integration, 'Token should be stored encrypted at rest');
moovaTokenAssertContains('SyncRuntimeCrypto', $integration, 'Token encryption should use the shared runtime crypto service');

moovaTokenAssertContains('encrypted at rest', $rotation, 'Rotation doc should describe encrypted at-rest storage');
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

function moovaTokenAssertNotContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new RuntimeException($message);
    }
}
