<?php

require_once __DIR__ . '/../../classes/Security/TeamHubService.php';

$sourceTeam = (string) file_get_contents(__DIR__ . '/../../team.php');
$sourceService = (string) file_get_contents(__DIR__ . '/../../classes/Security/TeamHubService.php');

teamHubPinRevealAssert(strpos($sourceTeam, 'auth_guard_is_admin_session()') !== false, 'team.php must gate reveal on admin session');
teamHubPinRevealAssert(strpos($sourceTeam, 'staffList($showDeactivated, $isAdminSession)') !== false, 'team.php must pass admin flag to staffList');
teamHubPinRevealAssert(strpos($sourceService, 'adminRevealOnly') !== false, 'TeamHubService must enforce adminRevealOnly');
teamHubPinRevealAssert(strpos($sourceService, 'auth_guard_is_admin_session()') !== false, 'TeamHubService must check admin session for reveal');

echo "team-hub-pin-reveal-contract-ok\n";

function teamHubPinRevealAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
