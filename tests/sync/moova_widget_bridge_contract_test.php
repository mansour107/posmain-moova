<?php

use PHPUnit\Framework\TestCase;

class MoovaWidgetBridgeContractTest extends TestCase
{
    public function testConfirmEndpointReturnsDirectWidgetSyncMetadata(): void
    {
        $source = $this->source('ajax/moova_confirm_order.php');
        $serviceSource = $this->source('classes/Pos/Service/PosOrderMutationService.php');

        $this->assertStringContainsString("require_once('../classes/Pos/Service/PosOrderMutationService.php')", $source);
        $this->assertStringContainsString('confirmMoovaOrder($conn', $source);
        $this->assertStringContainsString("require_once __DIR__ . '/../../Moova/MoovaNewOrderApplyService.php'", $serviceSource);
        $this->assertStringContainsString('new MoovaNewOrderApplyService()', $serviceSource);
        $this->assertStringContainsString("'response_mode' => 'direct'", $source);
        $this->assertStringContainsString("moova_json_response(200, \$result['response'])", $source);
        $this->assertStringNotContainsString('function moova_attach_direct_apply_metadata', $source);
    }

    public function testChangeEndpointReturnsDirectWidgetSyncMetadataForTerminalResults(): void
    {
        $source = $this->source('ajax/moova_change_order.php');
        $serviceSource = $this->source('classes/Pos/Service/PosOrderMutationService.php');

        $this->assertStringContainsString("require_once('../classes/Pos/Service/PosOrderMutationService.php')", $source);
        $this->assertStringContainsString('changeMoovaOrder($conn', $source);
        $this->assertStringContainsString("require_once __DIR__ . '/../../Moova/MoovaChangeOrderApplyService.php'", $serviceSource);
        $this->assertStringContainsString('new MoovaChangeOrderApplyService()', $serviceSource);
        $this->assertStringContainsString("'response_mode' => 'direct'", $source);
        $this->assertStringContainsString("moova_change_json_response(200, \$result['response'])", $source);
        $this->assertStringContainsString('moova_change_is_cashier_confirmed($payload)', $source);
        $this->assertStringNotContainsString('function moova_change_attach_direct_apply_metadata', $source);
    }

    public function testParentWidgetBridgeAdvertisesAndForwardsApplyMetadata(): void
    {
        $source = $this->source('elements/pos/cofe_widget.php');

        $this->assertStringContainsString('const HOST_CAPABILITIES = {', $source);
        $this->assertStringContainsString("bridgeVersion: 2", $source);
        $this->assertStringContainsString("applyPath: 'direct_widget'", $source);
        $this->assertStringContainsString("eventTypes: ['edit_order', 'cancel_order']", $source);
        $this->assertStringContainsString('hostCapabilities: HOST_CAPABILITIES', $source);
        $this->assertStringContainsString('function bridgeMetadata(result, fallbackEventType, fallbackStatus)', $source);
        $this->assertGreaterThanOrEqual(4, substr_count($source, 'syncStatus:'));
        $this->assertGreaterThanOrEqual(4, substr_count($source, 'syncEventType:'));
    }

    public function testParentWidgetBridgeSupportsTokenBoundMenuSync(): void
    {
        $source = $this->source('elements/pos/cofe_widget.php');
        $endpointSource = $this->source('ajax/moova_menu_sync_payload.php');
        $widgetSource = $this->source('assets/moova-pos-widget/pos-widget.js');

        $this->assertStringContainsString('menuSync: {', $source);
        $this->assertStringContainsString("autoFingerprint: true", $source);
        $this->assertStringContainsString('ajax/moova_menu_sync_payload.php', $source);
        $this->assertStringContainsString('cofe.host.menu-fingerprint', $source);
        $this->assertStringContainsString('cofe.menu-sync.requested', $source);
        $this->assertStringContainsString('cofe.host.menu-sync-result', $source);
        $this->assertStringContainsString("msgType === 'cofe.widget.connected'", $source);
        $this->assertStringContainsString("'X-Moova-Device-Token': DEVICE_TOKEN", $source);

        $this->assertStringContainsString('function moova_menu_sync_fingerprint(mysqli $conn)', $endpointSource);
        $this->assertStringContainsString('X-Moova-Device-Token', $endpointSource);
        $this->assertStringContainsString('Authorization', $endpointSource);
        $this->assertStringContainsString('findActiveLinkByToken($conn', $endpointSource);
        $this->assertStringContainsString('findActiveLinkByTokenAndBranch($conn', $endpointSource);
        $this->assertStringContainsString('findActiveLinkForUser($conn', $endpointSource);
        $this->assertStringContainsString("'priceUnit' => 'minor'", $endpointSource);
        $this->assertStringContainsString("'priceUnitScale' => 100", $endpointSource);
        $this->assertStringContainsString("'posPriceUnit' => 'major'", $endpointSource);
        $this->assertStringContainsString("'pos-cat-' . (int) \$row['id']", $endpointSource);
        $this->assertStringContainsString("'pos-item-' . \$itemId", $endpointSource);

        $this->assertStringContainsString("payload.type === 'cofe.host.menu-sync-result'", $widgetSource);
        $this->assertStringContainsString("payload.type === 'cofe.host.menu-fingerprint'", $widgetSource);
        $this->assertStringContainsString("type === 'menu_sync'", $widgetSource);
        $this->assertStringContainsString('async function syncMenuCommand(commandId)', $widgetSource);
        $this->assertStringContainsString("type: 'cofe.menu-sync.requested'", $widgetSource);
        $this->assertStringContainsString('pendingHostMenuSyncResults: new Map()', $widgetSource);
        $this->assertStringContainsString('/local-bridge/commands/${encodeURIComponent(normalizedCommandId)}/complete', $widgetSource);
    }

    public function testItemDeleteRecordsMenuSnapshotForSyncConsumers(): void
    {
        $source = $this->source('do/dodel_item.php');

        $this->assertStringContainsString("require_once('../classes/Sync/MenuItemSyncRecorder.php')", $source);
        $this->assertStringContainsString('UPDATE myitems SET isdeleted = 1 WHERE id = ?', $source);
        $this->assertStringContainsString('posmain_record_menu_item_sync($conn, $id', $source);
        $this->assertStringContainsString("'item_delete'", $source);
    }

    private function source(string $path): string
    {
        $absolute = __DIR__ . '/../../' . $path;
        $source = file_get_contents($absolute);
        $this->assertIsString($source);

        return $source;
    }
}

class moova_widget_bridge_contract_test extends MoovaWidgetBridgeContractTest
{
}
