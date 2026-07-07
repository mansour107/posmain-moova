<?php

$source = (string) file_get_contents(__DIR__ . '/../../js/team-hub.js');

teamHubPanelUrlAssert(strpos($source, 'function clearPanelUrlParams()') !== false, 'team-hub.js must clear panel URL params');
teamHubPanelUrlAssert(strpos($source, 'clearPanelUrlParams()') !== false, 'team-hub.js must call clearPanelUrlParams on close');
teamHubPanelUrlAssert(strpos($source, 'reloadTeamHubPage()') !== false, 'team-hub.js must reload without stale panel params');
teamHubPanelUrlAssert(strpos($source, 'showLifecycleError') !== false, 'team-hub.js must show lifecycle errors in modal');
teamHubPanelUrlAssert(strpos($source, 'staff_lifecycle_blockers') !== false, 'team-hub.js must preflight lifecycle blockers');

echo "team-hub-panel-url-contract-ok\n";

function teamHubPanelUrlAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
