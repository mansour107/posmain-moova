<?php

$root = dirname(__DIR__, 2);

function moovaWidgetPosOpenAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$pageManifest = require $root . '/config/rbac_page_manifest.php';
$routeManifest = require $root . '/config/rbac_route_manifest.php';
$widgetHost = file_get_contents($root . '/elements/pos/cofe_widget.php');
$roleSync = file_get_contents($root . '/classes/Security/RolePermissionSyncService.php');

moovaWidgetPosOpenAssert(is_array($pageManifest), 'page manifest must load');
moovaWidgetPosOpenAssert(
    ($pageManifest['moova_pos_widget.php']['permission'] ?? null) === 'pos.open',
    'moova_pos_widget.php must be gated by pos.open so every POS account can load speaker/bell'
);
moovaWidgetPosOpenAssert(
    ($pageManifest['moova_pos_proxy.php']['permission'] ?? null) === 'pos.open',
    'moova_pos_proxy.php must be gated by pos.open for cashier widget bootstrap'
);
moovaWidgetPosOpenAssert(
    ($routeManifest['ajax/moova_confirm_order.php']['permission'] ?? null) === 'moova.accept',
    'confirm order remains moova.accept'
);
moovaWidgetPosOpenAssert(
    ($routeManifest['ajax/moova_confirm_order.php']['lane'] ?? null) === 'pos',
    'confirm order must run on POS lane for unlocked cashiers'
);
moovaWidgetPosOpenAssert(
    ($routeManifest['ajax/moova_change_order.php']['permission'] ?? null) === 'moova.accept',
    'change order must use moova.accept not moova.manage'
);
moovaWidgetPosOpenAssert(
    ($routeManifest['ajax/moova_menu_sync_payload.php']['permission'] ?? null) === 'pos.open',
    'menu sync payload must be available to POS open users'
);
moovaWidgetPosOpenAssert(
    strpos($widgetHost, 'POSMAIN_MOOVA_WIDGET_RENDERED') !== false,
    'widget host must render once to avoid duplicate iframe ids'
);
moovaWidgetPosOpenAssert(
    strpos($widgetHost, 'moova-host-controls') !== false,
    'widget host must expose offline speaker/bell chrome when not linked'
);
moovaWidgetPosOpenAssert(
    strpos($widgetHost, '$moovaConnected') !== false,
    'widget host must branch on connection without early-returning an empty slot'
);
moovaWidgetPosOpenAssert(
    strpos($roleSync, "'moova.accept'") !== false
        && preg_match("/'cashier'\\s*=>\\s*\\[[\\s\\S]*?'moova\\.accept'/", $roleSync) === 1,
    'cashier preset must include moova.accept'
);

echo "moova-widget-pos-open-access-contract-ok\n";
