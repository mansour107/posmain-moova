<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/PasswordService.php';

if (PHP_SAPI !== 'cli') {
    require __DIR__ . '/../includes/http_gone.php';
}

$conn = posmain_db_connect();
$result = $conn->query("SELECT id, uname, password FROM users WHERE isdeleted != 1 ORDER BY id ASC");
$legacy = [];

while ($row = $result->fetch_assoc()) {
    if (PasswordService::isLegacyMd5Hash((string) $row['password'])) {
        $legacy[] = [
            'id' => (int) $row['id'],
            'uname' => (string) $row['uname'],
        ];
    }
}

echo json_encode([
    'legacy_md5_user_count' => count($legacy),
    'users' => $legacy,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
