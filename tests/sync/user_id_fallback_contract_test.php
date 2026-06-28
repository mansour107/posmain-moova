<?php

$root = realpath(__DIR__ . '/../..');
$dispatch = file_get_contents($root . '/includes/pos_api_dispatch.php');
$mutation = file_get_contents($root . '/classes/Pos/Service/PosOrderMutationService.php');
$userContext = file_get_contents($root . '/includes/pos_user_context.php');
$posRequest = file_get_contents($root . '/classes/Pos/Http/PosRequest.php');

userIdFallbackAssert(strpos($dispatch, 'posmain_resolve_pos_user_id') !== false || strpos($posRequest, 'userId') !== false, 'API dispatch should resolve user id from session/request');
userIdFallbackAssert(strpos($mutation, 'posmain_resolve_pos_user_id') !== false, 'mutation service should use posmain_resolve_pos_user_id');
userIdFallbackAssert(strpos($userContext, 'return $userId > 0 ? $userId : 0') !== false, 'missing user should fail closed instead of defaulting to system user 1');

echo "user-id-fallback-contract-ok\n";

function userIdFallbackAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
