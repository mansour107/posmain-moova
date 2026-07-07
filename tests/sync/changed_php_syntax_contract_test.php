<?php

$root = dirname(__DIR__, 2);
$script = $root . '/scripts/lint_changed_php.sh';
changedPhpSyntaxAssert(is_file($script), 'lint_changed_php.sh missing');

$phpBin = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
$env = array_merge($_ENV, [
    'LINT_PHP_BASE_REF' => 'HEAD',
    'PHP_BIN' => $phpBin,
]);
$descriptor = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open('bash ' . escapeshellarg($script), $descriptor, $pipes, $root, $env);
changedPhpSyntaxAssert(is_resource($process), 'unable to start lint_changed_php.sh');

fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

changedPhpSyntaxAssert($exitCode === 0, 'lint_changed_php.sh failed: ' . trim($stdout . "\n" . $stderr));
changedPhpSyntaxAssert(strpos((string) $stdout, 'lint-changed-php-ok') !== false, 'unexpected lint output');

echo "changed-php-syntax-contract-ok\n";

function changedPhpSyntaxAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
