<?php

$root = dirname(__DIR__, 2);

$teamPhp = file_get_contents($root . '/team.php');
$teamJs = file_get_contents($root . '/js/team-hub.js');
$hubService = file_get_contents($root . '/classes/Security/TeamHubService.php');

teamLoginContractAssert(
    strpos($teamPhp, 'tab=logins') !== false || strpos($teamPhp, "'logins'") !== false,
    'team.php supports logins tab'
);
teamLoginContractAssert(
    strpos($teamPhp, 'id="tabLogins"') !== false,
    'team.php exposes tabLogins button'
);
teamLoginContractAssert(
    strpos($teamPhp, 'data-testid="team-login-activity"') !== false,
    'login activity section has test id'
);
teamLoginContractAssert(
    strpos($teamPhp, 'users.manage') !== false || strpos($teamPhp, '$canUsers') !== false,
    'login activity gated by users.manage capability'
);
teamLoginContractAssert(
    strpos($hubService, 'function loginActivitySummary') !== false,
    'TeamHubService::loginActivitySummary exists'
);
teamLoginContractAssert(
    strpos($hubService, 'function recentLogins') !== false,
    'TeamHubService::recentLogins exists'
);
teamLoginContractAssert(
    strpos($teamJs, "setTab('logins')") !== false || strpos($teamJs, "tab === 'logins'") !== false,
    'team-hub.js handles logins tab'
);
teamLoginContractAssert(
    strpos($teamJs, 'tabLogins') !== false,
    'team-hub.js references tabLogins'
);

echo "team-hub-login-activity-contract-ok\n";

function teamLoginContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "team-hub-login-activity-contract-fail: {$message}\n");
        exit(1);
    }
}
