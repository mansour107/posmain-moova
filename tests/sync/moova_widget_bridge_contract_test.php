<?php

use PHPUnit\Framework\TestCase;

class MoovaWidgetBridgeContractTest extends TestCase
{
    public function testConfirmEndpointReturnsDirectWidgetSyncMetadata(): void
    {
        $source = $this->source('ajax/moova_confirm_order.php');

        $this->assertStringContainsString("require_once('../classes/Moova/MoovaNewOrderApplyService.php')", $source);
        $this->assertStringContainsString('new MoovaNewOrderApplyService()', $source);
        $this->assertStringContainsString("'response_mode' => 'direct'", $source);
        $this->assertStringContainsString("moova_json_response(200, \$result['response'])", $source);
        $this->assertStringNotContainsString('function moova_attach_direct_apply_metadata', $source);
    }

    public function testChangeEndpointReturnsDirectWidgetSyncMetadataForTerminalResults(): void
    {
        $source = $this->source('ajax/moova_change_order.php');

        $this->assertStringContainsString("require_once('../classes/Moova/MoovaChangeOrderApplyService.php')", $source);
        $this->assertStringContainsString('new MoovaChangeOrderApplyService()', $source);
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
