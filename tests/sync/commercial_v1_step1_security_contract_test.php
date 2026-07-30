<?php

/**
 * Commercial V1 Step 1 contract: prohibited web utilities and release packaging.
 */

$root = dirname(__DIR__, 2);

function step1ContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$prohibited = require $root . '/config/prohibited_web_routes.php';
step1ContractAssert(is_array($prohibited) && $prohibited !== [], 'prohibited route inventory required');
step1ContractAssert(in_array('fix_passwords.php', $prohibited, true), 'fix_passwords.php must be prohibited');
foreach (['do/dodel_invoice.php', 'do/dodel_pro.php'] as $unsafeInventoryDelete) {
    step1ContractAssert(
        in_array($unsafeInventoryDelete, $prohibited, true),
        $unsafeInventoryDelete . ' must be excluded from the commercial release artifact'
    );
}

$routeManifest = require $root . '/config/rbac_route_manifest.php';
foreach (['do/dodel_invoice.php', 'do/dodel_pro.php'] as $unsafeInventoryDelete) {
    step1ContractAssert(
        !empty($routeManifest[$unsafeInventoryDelete]['quarantined']),
        $unsafeInventoryDelete . ' must fail closed in source runtimes'
    );
}

$operationsSummary = (string) file_get_contents($root . '/operations_summary.php');
step1ContractAssert(
    !str_contains($operationsSummary, 'action="do/dodel_invoice.php')
        && str_contains($operationsSummary, 'الحذف متوقف — يلزم عكس أو تسوية معتمدة'),
    'operations summary must not advertise the quarantined physical-delete flow'
);

$fixPasswords = (string) file_get_contents($root . '/fix_passwords.php');
step1ContractAssert(
    str_contains($fixPasswords, 'http_gone.php'),
    'fix_passwords.php must be a gone stub'
);
step1ContractAssert(
    !str_contains($fixPasswords, 'HORSTEC_SECURE'),
    'fix_passwords.php must not contain the hardcoded reset key'
);
step1ContractAssert(
    !str_contains($fixPasswords, 'username . "123"') && !str_contains($fixPasswords, "username . '123'"),
    'fix_passwords.php must not derive predictable passwords'
);

$httpGone = (string) file_get_contents($root . '/includes/http_gone.php');
step1ContractAssert(str_contains($httpGone, '404'), 'http_gone must emit 404');

$policy = require $root . '/config/release_artifact_policy.php';
step1ContractAssert(isset($policy['deny_path_exact']), 'release policy must deny exact prohibited paths');
step1ContractAssert(
    in_array('tools/', $policy['deny_path_prefixes'], true),
    'release policy must exclude tools/ from the web artifact'
);
step1ContractAssert(
    in_array('uploads/', $policy['deny_path_prefixes'], true)
        && !in_array('uploads', $policy['allow_directories'], true),
    'runtime uploads must never be packaged into the release artifact'
);

require_once $root . '/classes/PasswordService.php';
putenv('POSMAIN_DENY_LEGACY_PASSWORD_AUTH=1');
step1ContractAssert(PasswordService::denyLegacyPasswordAuth() === true, 'deny flag must enable legacy denial');
step1ContractAssert(
    PasswordService::verifyPassword('admin123', md5('admin123')) === false,
    'legacy MD5 auth must fail when denial is enabled'
);
putenv('POSMAIN_DENY_LEGACY_PASSWORD_AUTH=0');
step1ContractAssert(
    PasswordService::verifyPassword('admin123', md5('admin123')) === true,
    'legacy MD5 auth may remain available only when explicitly allowed outside production'
);
putenv('POSMAIN_PRODUCTION_MODE=1');
step1ContractAssert(
    PasswordService::verifyPassword('admin123', md5('admin123')) === false,
    'production must deny legacy MD5 even when a compatibility override is false'
);
putenv('POSMAIN_PRODUCTION_MODE');
putenv('POSMAIN_DENY_LEGACY_PASSWORD_AUTH');

$artifactBuilder = (string) file_get_contents($root . '/tools/build_release_artifact.php');
step1ContractAssert(
    str_contains($artifactBuilder, 'git -C ')
        && str_contains($artifactBuilder, 'ls-files -z --cached')
        && str_contains($artifactBuilder, 'source_tree_clean'),
    'artifact builder must package tracked source and bind cleanliness'
);

foreach ([
    'classes/Security/PasswordResetService.php',
    'tools/issue_password_reset.php',
    'tools/complete_password_reset.php',
    'tools/invalidate_legacy_password_hashes.php',
    'tools/build_release_artifact.php',
    'tools/commercial_v1_step1_gate.php',
] as $relative) {
    step1ContractAssert(is_file($root . '/' . $relative), $relative . ' must exist');
}

$completeResetTool = (string) file_get_contents($root . '/tools/complete_password_reset.php');
step1ContractAssert(
    !str_contains($completeResetTool, "'token:'")
        && !str_contains($completeResetTool, "'new-password:'")
        && str_contains($completeResetTool, 'fgets(STDIN)'),
    'password reset secrets must be read from stdin, not process arguments'
);

$htaccess = (string) file_get_contents($root . '/.htaccess');
step1ContractAssert(
    str_contains($htaccess, 'fix_passwords'),
    '.htaccess must deny fix_passwords at the edge'
);
step1ContractAssert(
    str_contains($htaccess, 'scripts'),
    '.htaccess must block scripts/ from public web access'
);

$router = (string) file_get_contents($root . '/router.php');
step1ContractAssert(
    str_contains($router, "str_starts_with(\$pathOnly, 'uploads/')")
        && str_contains($router, 'phtml')
        && str_contains($router, 'phar'),
    'the built-in runtime router must deny executable upload extensions'
);
$dockerfile = (string) file_get_contents($root . '/Dockerfile.posmain-php');
step1ContractAssert(
    !str_contains($dockerfile, 'chmod -R 777'),
    'runtime writable directories must not be world-writable'
);

$tableWorkspace = (string) file_get_contents($root . '/tables.php');
step1ContractAssert(
    str_contains($tableWorkspace, 'posTablePageEscapeHtml(item.name)')
        && !str_contains($tableWorkspace, '<td>${item.name}</td>'),
    'dine-in split-payment rows must escape persisted catalog text'
);
foreach ([
    'ajax/get_items.php',
    'ajax/get_item_variants.php',
    'ajax/get_table_amount.php',
    'ajax/get_table_items.php',
    'ajax/get_table_order.php',
] as $relative) {
    $readEndpoint = (string) file_get_contents($root . '/' . $relative);
    step1ContractAssert(
        str_contains($readEndpoint, "rbac_guard_route('{$relative}')"),
        $relative . ' must enforce the POS lane and pos.table.open permission'
    );
}

echo "commercial-v1-step1-security-contract-ok\n";
