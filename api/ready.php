<?php

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'ok' => true,
    'ready' => true,
    'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
