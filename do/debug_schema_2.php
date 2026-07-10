<?php

http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => false,
    'error' => 'ENDPOINT_QUARANTINED',
], JSON_UNESCAPED_UNICODE);
exit;
