<?php

use PHPUnit\Framework\TestCase;

class MoovaReachabilitySmokeContractTest extends TestCase
{
    public function testSmokeHarnessDefinesTwoMockServicesAndDropRecoveryScenarios(): void
    {
        $source = $this->source('tools/moova_reachability_smoke.php');

        $this->assertStringContainsString('startPosServer', $source);
        $this->assertStringContainsString('startMoovaServer', $source);
        $this->assertStringContainsString('online_order', $source);
        $this->assertStringContainsString('queued_new_order', $source);
        $this->assertStringContainsString('queued_edit_order', $source);
        $this->assertStringContainsString('queued_cancel_order', $source);
        $this->assertStringContainsString('pos_drop', $source);
        $this->assertStringContainsString('pos_recovery', $source);
        $this->assertStringContainsString('moova_drop', $source);
        $this->assertStringContainsString('moova_recovery', $source);
        $this->assertStringContainsString('\'eventType\' => $eventType', $source);
    }

    public function testSmokeHarnessUsesProductionFacingStatusNames(): void
    {
        $source = $this->source('tools/moova_reachability_smoke.php');

        $this->assertStringContainsString('moova_unreachable', $source);
        $this->assertStringContainsString('MOOVA_UNREACHABLE', $source);
        $this->assertStringContainsString('pos_unreachable', $source);
        $this->assertStringContainsString('POS_UNREACHABLE', $source);
        $this->assertStringContainsString('syncStatus', $source);
        $this->assertStringContainsString('direct_widget', $source);
    }

    public function testSmokeHarnessAvoidsRealLocalTopologyPortsByDefault(): void
    {
        $source = $this->source('tools/moova_reachability_smoke.php');

        $this->assertStringContainsString('moova_smoke_free_port()', $source);
        $this->assertStringNotContainsString("'3001'", $source);
        $this->assertStringNotContainsString("'8010'", $source);
        $this->assertStringNotContainsString("'8020'", $source);
    }

    private function source(string $path): string
    {
        $absolute = __DIR__ . '/../../' . $path;
        $source = file_get_contents($absolute);
        $this->assertIsString($source);

        return $source;
    }
}

class moova_reachability_smoke_contract_test extends MoovaReachabilitySmokeContractTest
{
}
