<?php

require_once __DIR__ . '/../../classes/Release/ReleaseArtifactPolicy.php';

function releasePolicyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$config = [
    'version' => 7,
    'endpoint_directories' => ['ajax', 'api', 'do', 'get', 'print'],
    'endpoint_internal_files' => ['api/internal.php'],
    'root_runtime_files' => ['.htaccess', 'version.txt'],
    'runtime_prefixes' => ['assets/', 'classes/', 'config/'],
    'runtime_exact_files' => [],
    'runtime_library_prefixes' => [],
    'dependency_manifests' => [
        'composer.json' => 'composer.lock',
        'package.json' => 'package-lock.json',
    ],
    'prohibited_prefixes' => [
        '.git/',
        'backup/',
        'docs/',
        'logs/',
        'tests/',
        'tools/',
    ],
    'prohibited_basename_patterns' => [
        '/(^|[._-])(debug|fix|repair|setup|test)([._-]|$)/i',
        '/\.(bak|log|sql|xlsx)$/i',
        '/(^|\/)\.env($|\.)/i',
    ],
];
$pages = [
    'index.php' => ['public' => true],
    'dashboard.php' => ['permission' => 'reports.view'],
    'fix_passwords.php' => ['quarantined' => true],
];
$routes = [
    'ajax/orders.php' => ['permission' => 'pos.open'],
    'api/health.php' => ['endpoint_auth' => true],
    'do/legacy.php' => ['quarantined' => true],
    'get/item.php' => ['permission' => 'menu.edit'],
];

$policy = new ReleaseArtifactPolicy($config, $pages, $routes);
releasePolicyAssert($policy->version() === 7, 'policy version must be explicit');

$tracked = [
    '.env.production',
    '.htaccess',
    'backup/shop.sql',
    'classes/Service.php',
    'composer.json',
    'composer.lock',
    'dashboard.php',
    'debug_credentials.txt',
    'do/legacy.php',
    'fix_passwords.php',
    'index.php',
    'api/health.php',
    'api/internal.php',
    'api/unclassified.php',
    'ajax/orders.php',
    'get/item.php',
    'logs/error.log',
    'package.json',
    'package-lock.json',
    'screenshot.png',
    'tests/fixture.php',
    'tools/repair.php',
    'version.txt',
];
$result = $policy->evaluate($tracked);

foreach ([
    '.htaccess',
    'classes/Service.php',
    'dashboard.php',
    'index.php',
    'api/health.php',
    'api/internal.php',
    'ajax/orders.php',
    'get/item.php',
    'version.txt',
] as $expected) {
    releasePolicyAssert(
        in_array($expected, $result['included'], true),
        'expected allowlisted runtime file: ' . $expected
    );
}

foreach ([
    '.env.production',
    'backup/shop.sql',
    'debug_credentials.txt',
    'do/legacy.php',
    'fix_passwords.php',
    'logs/error.log',
    'screenshot.png',
    'tests/fixture.php',
    'tools/repair.php',
] as $forbidden) {
    releasePolicyAssert(
        !in_array($forbidden, $result['included'], true),
        'forbidden file entered artifact: ' . $forbidden
    );
}

$blockerCodes = [];
foreach ($result['blockers'] as $blocker) {
    $blockerCodes[$blocker['code'] . ':' . $blocker['path']] = true;
}
releasePolicyAssert(
    isset($blockerCodes['unclassified_entrypoint:api/unclassified.php']),
    'unclassified endpoint must block release'
);
releasePolicyAssert(
    !isset($blockerCodes['dependency_lock_missing:composer.lock']),
    'present Composer lock must satisfy dependency gate'
);

$missingLock = $policy->evaluate(['composer.json', 'index.php']);
$missingLockCodes = array_column($missingLock['blockers'], 'code');
releasePolicyAssert(
    in_array('dependency_lock_missing', $missingLockCodes, true),
    'missing dependency lock must block release'
);

echo "release-artifact-policy-contract-ok\n";
