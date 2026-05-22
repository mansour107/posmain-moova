<?php

$root = dirname(__DIR__, 2);

function posLocalDockerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$dockerfile = file_get_contents($root . '/Dockerfile.posmain-php');
$compose = file_get_contents($root . '/docker-compose.posmain-test.yml');

posLocalDockerAssert(is_string($dockerfile), 'Unable to read local POS Dockerfile');
posLocalDockerAssert(is_string($compose), 'Unable to read local POS docker compose file');

posLocalDockerAssert(
    strpos($dockerfile, 'mkdir -p /app/logs /app/uploads /app/var/sessions') !== false,
    'Local POS container should create writable runtime directories before starting PHP'
);
posLocalDockerAssert(
    strpos($compose, 'PHP_CLI_SERVER_WORKERS: "4"') !== false,
    'Local POS PHP server should use multiple workers so offline sync calls cannot block local AJAX'
);
posLocalDockerAssert(
    strpos($compose, 'POSMAIN_BRANCH_WORKER_ENV_FILE: /app/.env.branch-worker') !== false,
    'Local POS web container should know where to read branch sync settings when process env is missing'
);
posLocalDockerAssert(
    strpos($compose, 'POSMAIN_MOOVA_MODE: direct_widget') !== false,
    'Local POS web container should default Moova to direct widget mode'
);
posLocalDockerAssert(
    strpos($compose, 'POSMAIN_ENABLE_MOOVA_DIRECT_APPLY: "1"') !== false,
    'Local POS web container should enable direct Moova apply by default'
);

foreach ([
    'posmain_php_logs:/app/logs',
    'posmain_php_uploads:/app/uploads',
    'posmain_php_var:/app/var',
] as $runtimeMount) {
    posLocalDockerAssert(
        strpos($compose, $runtimeMount) !== false,
        'Local POS compose should mount writable runtime path: ' . $runtimeMount
    );
}

foreach ([
    'posmain_php_logs:',
    'posmain_php_uploads:',
    'posmain_php_var:',
] as $runtimeVolume) {
    posLocalDockerAssert(
        strpos($compose, $runtimeVolume) !== false,
        'Local POS compose should declare runtime volume: ' . $runtimeVolume
    );
}

echo "pos-local-docker-runtime-ok\n";
