<?php

$root = dirname(__DIR__, 2);

function railpackRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$railwayConfig = json_decode(file_get_contents($root . '/railway.json'), true);
railpackRuntimeAssert(is_array($railwayConfig), 'railway.json should be valid JSON');
railpackRuntimeAssert(($railwayConfig['build']['builder'] ?? null) === 'RAILPACK', 'Railway should use Railpack, not a custom Dockerfile');
railpackRuntimeAssert(!isset($railwayConfig['build']['dockerfilePath']), 'Railway config should not point at a Dockerfile');
railpackRuntimeAssert(($railwayConfig['deploy']['restartPolicyType'] ?? null) === 'ON_FAILURE', 'Railway restart policy should remain conservative');

railpackRuntimeAssert(!file_exists($root . '/Dockerfile'), 'Root Dockerfile should not exist because Railway auto-detects it ahead of Railpack');
railpackRuntimeAssert(file_exists($root . '/Dockerfile.posmain-php'), 'Local Docker test stack should keep Dockerfile.posmain-php');
railpackRuntimeAssert(!file_exists($root . '/package.json'), 'Root package.json should not exist because it makes Railpack install Node');
railpackRuntimeAssert(!file_exists($root . '/package-lock.json'), 'Root package-lock.json should not exist because it makes Railpack run npm');

$railpackConfig = json_decode(file_get_contents($root . '/railpack.json'), true);
railpackRuntimeAssert(($railpackConfig['provider'] ?? null) === 'php', 'Railpack should be pinned to the PHP provider');

$composerConfig = json_decode(file_get_contents($root . '/composer.json'), true);
railpackRuntimeAssert(($composerConfig['require']['php'] ?? null) === '^8.2', 'Railpack PHP runtime should stay on PHP 8.2');
railpackRuntimeAssert(isset($composerConfig['require']['ext-mysqli']), 'Railpack must install mysqli for POS database access');
railpackRuntimeAssert(isset($composerConfig['require']['ext-mbstring']), 'Railpack must keep mbstring for existing string handling');

$dockerignore = file_get_contents($root . '/.dockerignore');
foreach (['/Dockerfile', '/docker/', '/package.json', '/package-lock.json', 'package.json', 'package-lock.json', 'uploads/*', '!uploads/.htaccess', 'var/'] as $pattern) {
    railpackRuntimeAssert(strpos($dockerignore, $pattern) !== false, '.dockerignore should include ' . $pattern);
}

$railpackignore = file_get_contents($root . '/.railpackignore');
foreach (['/Dockerfile', '/docker/', '/package.json', '/package-lock.json', 'package.json', 'package-lock.json', 'uploads/*', '!uploads/.htaccess', 'var/'] as $pattern) {
    railpackRuntimeAssert(strpos($railpackignore, $pattern) !== false, '.railpackignore should include ' . $pattern);
}

$caddyfile = file_get_contents($root . '/Caddyfile');
foreach ([':{$PORT:80}', 'root * /app', 'php_server', 'respond @private 403', '/db*', '/logs*', '/.env*'] as $needle) {
    railpackRuntimeAssert(strpos($caddyfile, $needle) !== false, 'Caddyfile should include ' . $needle);
}

$phpIni = file_get_contents($root . '/php.ini');
foreach (['display_errors=Off', 'expose_php=Off', 'opcache.enable=1', 'upload_max_filesize=64M'] as $needle) {
    railpackRuntimeAssert(strpos($phpIni, $needle) !== false, 'php.ini should include ' . $needle);
}

echo "railway-railpack-runtime-ok\n";
