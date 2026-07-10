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
    strpos($dockerfile, 'docker-php-ext-install mysqli bcmath') !== false,
    'Local POS PHP container should install bcmath because active recipe decimal math requires it'
);
posLocalDockerAssert(
    strpos($dockerfile, 'git') !== false,
    'Local POS PHP container should install git so update jobs can pull code'
);
posLocalDockerAssert(
    strpos($dockerfile, 'default-mysql-client') !== false,
    'Local POS PHP container should install mysql client so update backups can run'
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
    preg_match('/POSMAIN_MOOVA_MODE:\s*(disabled|direct_widget)/', $compose) === 1,
    'Local POS web container should set an explicit Moova mode (disabled for PIN E2E stability, or direct_widget)'
);
posLocalDockerAssert(
    strpos($compose, '- .:/app') !== false && strpos($compose, '- .:/app:ro') === false,
    'Local POS compose should mount the app tree writable so update jobs can pull code'
);
posLocalDockerAssert(
    strpos($compose, 'network_mode: service:mysql') === false,
    'Local POS PHP container should have its own network so outbound update checks work'
);
posLocalDockerAssert(
    strpos($compose, '"127.0.0.1:8010:8000"') !== false,
    'Local POS PHP server should publish port 8010 from the PHP container'
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
