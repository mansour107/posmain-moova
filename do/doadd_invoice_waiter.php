<?php

http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => false,
    'error' => 'LEGACY_WAITER_AUTH_DISABLED',
    'message' => 'Legacy waiter invoice endpoint is quarantined. Use main login and POS table flows.',
], JSON_UNESCAPED_UNICODE);
exit;
