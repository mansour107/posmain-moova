<?php

require_once __DIR__ . '/../includes/pos_update_git.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This update check must be run from the command line.\n");
    exit(1);
}
if ($argc !== 1) {
    fwrite(STDERR, "This update check accepts no arguments.\n");
    exit(64);
}

try {
    $result = posmainUpdateGitSyncState(dirname(__DIR__));
    fwrite(
        STDOUT,
        json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
    );
    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
