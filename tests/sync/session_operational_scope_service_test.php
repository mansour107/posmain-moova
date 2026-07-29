<?php

require_once __DIR__ . '/../../classes/Security/SessionOperationalScopeService.php';
require_once __DIR__ . '/../../classes/Security/SecurityAuditLogger.php';

$service = new SessionOperationalScopeService();

$config = [
    'branch' => [
        'pos_tenant' => 11,
        'pos_branch' => 12,
    ],
];

$configured = $service->resolve(
    ['tenant' => 21, 'branch' => 22],
    [],
    $config
);
sessionOperationalScopeAssert(
    $configured === ['pos_tenant' => 11, 'pos_branch' => 12],
    'configured branch identity must win over legacy user scope'
);

$routed = $service->resolve(
    ['tenant' => 21, 'branch' => 22],
    ['pos_tenant' => 31, 'pos_branch' => 32],
    $config
);
sessionOperationalScopeAssert(
    $routed === ['pos_tenant' => 31, 'pos_branch' => 32],
    'trusted router-selected scope must win over branch configuration'
);

$legacy = $service->resolve(
    ['tenant' => 21, 'branch' => 22],
    [],
    ['branch' => ['pos_tenant' => null, 'pos_branch' => null]]
);
sessionOperationalScopeAssert(
    $legacy === ['pos_tenant' => 21, 'pos_branch' => 22],
    'legacy user columns must remain a compatibility fallback'
);

$unset = $service->resolve(
    ['tenant' => 0, 'branch' => -1],
    ['pos_tenant' => 0, 'pos_branch' => 0],
    ['branch' => []]
);
sessionOperationalScopeAssert(
    $unset === ['pos_tenant' => 0, 'pos_branch' => 0],
    'invalid or missing scopes must not be promoted'
);

$_SESSION = ['pos_tenant' => 91, 'pos_branch' => 92, 'userid' => 5];
$service->clear();
sessionOperationalScopeAssert(!isset($_SESSION['pos_tenant'], $_SESSION['pos_branch']), 'clear must remove branch scope');
sessionOperationalScopeAssert((int) ($_SESSION['userid'] ?? 0) === 5, 'clear must preserve unrelated session identity');

$auditSource = (string) file_get_contents(__DIR__ . '/../../classes/Security/SecurityAuditLogger.php');
sessionOperationalScopeAssert(
    strpos($auditSource, "\$_SESSION[\$alternate]") !== false,
    'security audit logger must inherit authenticated operational scope'
);

$loginSource = (string) file_get_contents(__DIR__ . '/../../index.php');
sessionOperationalScopeAssert(
    strpos($loginSource, "'tenant' => \$operationalScope['pos_tenant']") !== false
        && strpos($loginSource, "'branch' => \$operationalScope['pos_branch']") !== false,
    'password login success audit must carry the established operational scope'
);

echo "session-operational-scope-service-ok\n";

function sessionOperationalScopeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
