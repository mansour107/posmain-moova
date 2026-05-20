<?php

$root = dirname(__DIR__, 2);

function nixpacksRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$railwayConfig = json_decode(file_get_contents($root . '/railway.json'), true);
nixpacksRuntimeAssert(is_array($railwayConfig), 'railway.json should be valid JSON');
nixpacksRuntimeAssert(($railwayConfig['build']['builder'] ?? null) === 'NIXPACKS', 'Railway should use Nixpacks, not Dockerfile or Railpack');
nixpacksRuntimeAssert(!isset($railwayConfig['build']['dockerfilePath']), 'Railway config should not point at a Dockerfile');
nixpacksRuntimeAssert(($railwayConfig['deploy']['restartPolicyType'] ?? null) === 'ON_FAILURE', 'Railway restart policy should remain conservative');

nixpacksRuntimeAssert(!file_exists($root . '/Dockerfile'), 'Root Dockerfile should not exist because Railway auto-detects it ahead of source builders');
nixpacksRuntimeAssert(file_exists($root . '/Dockerfile.posmain-php'), 'Local Docker test stack should keep Dockerfile.posmain-php');
nixpacksRuntimeAssert(!file_exists($root . '/package.json'), 'Root package.json should not exist because it makes source builders install Node');
nixpacksRuntimeAssert(!file_exists($root . '/package-lock.json'), 'Root package-lock.json should not exist because it makes source builders run npm');
nixpacksRuntimeAssert(!file_exists($root . '/railpack.json'), 'Railpack config should not remain when Railway is configured for Nixpacks');

$nixpacksConfig = file_get_contents($root . '/nixpacks.toml');
nixpacksRuntimeAssert(strpos($nixpacksConfig, 'providers = ["php"]') !== false, 'Nixpacks should be pinned to PHP provider only');

$composerConfig = json_decode(file_get_contents($root . '/composer.json'), true);
nixpacksRuntimeAssert(($composerConfig['require']['php'] ?? null) === '^8.2', 'Nixpacks PHP runtime should stay on PHP 8.2');
nixpacksRuntimeAssert(isset($composerConfig['require']['ext-mysqli']), 'Nixpacks must install mysqli for POS database access');
nixpacksRuntimeAssert(isset($composerConfig['require']['ext-mbstring']), 'Nixpacks must keep mbstring for existing string handling');

$dockerignore = file_get_contents($root . '/.dockerignore');
foreach (['/Dockerfile', '/docker/', '/package.json', '/package-lock.json', 'package.json', 'package-lock.json', 'uploads/*', '!uploads/.htaccess', 'var/'] as $pattern) {
    nixpacksRuntimeAssert(strpos($dockerignore, $pattern) !== false, '.dockerignore should include ' . $pattern);
}

$nginx = file_get_contents($root . '/nginx.template.conf');
foreach (['listen ${PORT};', 'root /app;', 'fastcgi_pass 127.0.0.1:9000;', 'return 403;', '.env', 'Cache-Control "public, max-age=2592000"'] as $needle) {
    nixpacksRuntimeAssert(strpos($nginx, $needle) !== false, 'nginx.template.conf should include ' . $needle);
}
nixpacksRuntimeAssert(preg_match('/location ~ \^\\/\\([^)]*\\bdb\\b[^)]*\\)/', $nginx) === 1, 'nginx.template.conf should block /db');
nixpacksRuntimeAssert(preg_match('/location ~ \^\\/\\([^)]*\\blogs\\b[^)]*\\)/', $nginx) === 1, 'nginx.template.conf should block /logs');

echo "railway-nixpacks-runtime-ok\n";
