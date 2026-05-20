<?php

$root = realpath(__DIR__ . '/../..');
productionDockerAssert($root !== false, 'unable to resolve project root');

$dockerfile = productionDockerRead($root . '/Dockerfile');
productionDockerAssert(strpos($dockerfile, 'FROM php:8.2-apache') !== false, 'production Dockerfile should use Apache PHP image');
productionDockerAssert(strpos($dockerfile, 'php -d display_errors=0 -S') === false, 'production Dockerfile should not use the PHP built-in server');
productionDockerAssert(strpos($dockerfile, 'a2enmod rewrite headers expires deflate remoteip') !== false, 'production Dockerfile should enable Apache modules used by .htaccess and proxy routing');
productionDockerAssert(strpos($dockerfile, 'docker/php/production.ini') !== false, 'production Dockerfile should install production PHP settings');
productionDockerAssert(strpos($dockerfile, 'docker/apache/ports.conf') !== false, 'production Dockerfile should install dynamic Apache port config');

$ports = productionDockerRead($root . '/docker/apache/ports.conf');
productionDockerAssert(strpos($ports, 'Listen ${PORT}') !== false, 'Apache should listen on the Railway-provided PORT');

$vhost = productionDockerRead($root . '/docker/apache/000-default.conf');
productionDockerAssert(strpos($vhost, '<VirtualHost *:${PORT}>') !== false, 'Apache vhost should bind to the Railway-provided PORT');
productionDockerAssert(strpos($vhost, 'AllowOverride All') !== false, 'Apache should honor the repo .htaccess guardrails');
productionDockerAssert(strpos($vhost, 'DocumentRoot /var/www/html') !== false, 'Apache should serve the copied app root');

$phpIni = productionDockerRead($root . '/docker/php/production.ini');
productionDockerAssert(strpos($phpIni, 'display_errors=Off') !== false, 'production PHP should not display errors');
productionDockerAssert(strpos($phpIni, 'opcache.enable=1') !== false, 'production PHP should enable OPcache');

$dockerignore = productionDockerRead($root . '/.dockerignore');
productionDockerAssert(strpos($dockerignore, "uploads/*\n!uploads/.htaccess") !== false, 'Docker build should keep uploads/.htaccess while excluding uploaded files');
productionDockerAssert(strpos($dockerignore, "var/\n") !== false, 'Docker build should not copy local session/runtime var data');

echo "production-docker-runtime-ok\n";

function productionDockerRead(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('unable to read ' . $path);
    }

    return $source;
}

function productionDockerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
