<?php

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/api/health.php');

foreach ([
    "role = strtolower(trim((string) (\$config['role'] ?? 'branch')))",
    'branchIdentityRequired = $isProduction && $role !== \'cloud\'',
    "'required' => \$branchIdentityRequired",
    "'role' => \$role",
    'posmainHealthMainAuthCheck',
    "'main_auth_mode'",
    "'deployment_role'",
    "'pin_secret_ready'",
    'MAIN_AUTH_MODE_UNSAFE',
] as $needle) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "health-endpoint-contract-FAIL: missing {$needle}\n");
        exit(1);
    }
}

echo "health-endpoint-contract-ok\n";
