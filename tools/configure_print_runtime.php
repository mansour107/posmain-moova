#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$options = getopt('', ['apply', 'env-file:', 'secret-file:', 'bridge-url::', 'help']);
if (isset($options['help']) || empty($options['env-file']) || empty($options['secret-file'])) {
    fwrite(STDOUT, "Usage: php tools/configure_print_runtime.php --env-file=PATH --secret-file=PATH [--bridge-url=http://127.0.0.1:17981] [--apply]\n");
    exit(isset($options['help']) ? 0 : 2);
}
$envFile = (string) $options['env-file'];
$secretFile = (string) $options['secret-file'];
$bridgeUrl = trim((string) ($options['bridge-url'] ?? 'http://127.0.0.1:17981'));
if (filter_var($bridgeUrl, FILTER_VALIDATE_URL) === false || !in_array(parse_url($bridgeUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
    fwrite(STDERR, "عنوان خدمة الطباعة غير صالح.\n");
    exit(2);
}
$secret = is_file($secretFile) ? trim((string) file_get_contents($secretFile)) : bin2hex(random_bytes(32));
if (strlen($secret) < 32) {
    fwrite(STDERR, "ملف حماية خدمة الطباعة غير صالح. احذفه ثم أعد الإعداد.\n");
    exit(2);
}
$changes = [
    'POSMAIN_PRINT_BRIDGE_URL' => $bridgeUrl,
    'POSMAIN_PRINT_BRIDGE_SECRET' => $secret,
];
if (!isset($options['apply'])) {
    echo json_encode(['ok' => true, 'apply_required' => true, 'env_file' => $envFile, 'secret_file' => $secretFile, 'bridge_url' => $bridgeUrl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}
foreach ([dirname($envFile), dirname($secretFile)] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        fwrite(STDERR, "تعذر إنشاء مجلد إعداد الطباعة.\n");
        exit(2);
    }
}
if (!is_file($secretFile)) {
    if (file_put_contents($secretFile, $secret . PHP_EOL, LOCK_EX) === false) {
        fwrite(STDERR, "تعذر حفظ مفتاح حماية خدمة الطباعة.\n");
        exit(2);
    }
    @chmod($secretFile, 0600);
}
$lines = is_file($envFile) ? (file($envFile, FILE_IGNORE_NEW_LINES) ?: []) : [];
$remaining = $changes;
foreach ($lines as &$line) {
    foreach ($changes as $key => $value) {
        if (preg_match('/^\s*' . preg_quote($key, '/') . '=/', $line) === 1) {
            $line = $key . '=' . $value;
            unset($remaining[$key]);
            break;
        }
    }
}
unset($line);
foreach ($remaining as $key => $value) {
    $lines[] = $key . '=' . $value;
}
$contents = implode(PHP_EOL, $lines) . PHP_EOL;
$temporary = $envFile . '.tmp.' . getmypid();
if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
    fwrite(STDERR, "تعذر تجهيز إعداد التطبيق للطباعة.\n");
    exit(2);
}
@chmod($temporary, 0600);
if (is_file($envFile) && !copy($envFile, $envFile . '.before-printing')) {
    @unlink($temporary);
    fwrite(STDERR, "تعذر أخذ نسخة احتياطية من إعداد التطبيق.\n");
    exit(2);
}
if (!rename($temporary, $envFile)) {
    @unlink($temporary);
    fwrite(STDERR, "تعذر تفعيل إعداد الطباعة.\n");
    exit(2);
}
echo json_encode(['ok' => true, 'configured' => true, 'env_file' => $envFile, 'secret_file' => $secretFile, 'bridge_url' => $bridgeUrl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
