<?php

$source = (string) file_get_contents(dirname(__DIR__, 2) . '/register_pair.php');

function registerPairAuthorizationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

registerPairAuthorizationAssert(
    strpos($source, "return in_array(\$roleKey, ['owner', 'manager'], true);") !== false,
    'owner and manager role keys must be valid register approvers'
);
registerPairAuthorizationAssert(
    strpos($source, '$isManager = posmain_user_can_approve_register_pair($conn, $userId);') !== false,
    'first-register pairing must use the same manager/owner authorization as re-pairing'
);
registerPairAuthorizationAssert(
    strpos($source, '$canPair = $activeRegisters === [] ? $isManager : $canClaim;') !== false,
    'first-register pairing must remain manager/owner-only'
);

echo "register-pair-authorization-contract-ok\n";
