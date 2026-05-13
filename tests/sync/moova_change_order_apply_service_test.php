<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Moova/MoovaChangeOrderApplyService.php';

class MoovaChangeOrderApplyServiceTest extends TestCase
{
    public function testSharedServiceOwnsChangePersistenceStateChecksAndResponses(): void
    {
        $source = $this->source('classes/Moova/MoovaChangeOrderApplyService.php');

        $this->assertStringContainsString('class MoovaChangeOrderApplyService', $source);
        $this->assertStringContainsString('public function applyInTransaction', $source);
        $this->assertStringContainsString('FROM moova_pos_order_change_links', $source);
        $this->assertStringContainsString('INSERT INTO moova_pos_order_change_links', $source);
        $this->assertStringContainsString('UPDATE moova_pos_order_change_links', $source);
        $this->assertStringContainsString('UPDATE moova_pos_order_links', $source);
        $this->assertStringContainsString('getMoovaOrderStateSnapshot', $source);
        $this->assertStringContainsString('getMoovaOrderLineStateSnapshot', $source);
        $this->assertStringContainsString('replaceMoovaTableOrder', $source);
        $this->assertStringContainsString('cancelMoovaTableOrder', $source);
        $this->assertStringContainsString('MoovaApplyResponse::directWidgetChange', $source);
        $this->assertStringContainsString('MoovaApplyResponse::queuedWorkerChange', $source);
    }

    public function testDirectChangeEndpointDelegatesApplyButKeepsCashierReviewGate(): void
    {
        $source = $this->source('ajax/moova_change_order.php');

        $this->assertStringContainsString("require_once('../classes/Moova/MoovaChangeOrderApplyService.php')", $source);
        $this->assertStringContainsString('moova_change_is_cashier_confirmed($payload)', $source);
        $this->assertStringContainsString('new MoovaChangeOrderApplyService()', $source);
        $this->assertStringContainsString("'response_mode' => 'direct'", $source);
        $this->assertStringNotContainsString('function moova_change_fetch_order_link_for_update', $source);
        $this->assertStringNotContainsString('function moova_change_create_action', $source);
        $this->assertStringNotContainsString('function moova_change_update_action', $source);
    }

    public function testQueuedApplyWorkerDelegatesChangeApply(): void
    {
        $source = $this->source('classes/Sync/BranchMoovaApplyWorker.php');

        $this->assertStringContainsString("require_once __DIR__ . '/../Moova/MoovaChangeOrderApplyService.php'", $source);
        $this->assertStringContainsString('private MoovaChangeOrderApplyService $changeOrderApply', $source);
        $this->assertStringContainsString('$this->changeOrderApply->applyInTransaction', $source);
        $this->assertStringContainsString("'response_mode' => 'queued'", $source);
        $this->assertStringNotContainsString('private function createChangeLink(', $source);
        $this->assertStringNotContainsString('private function updateChangeAction(', $source);
        $this->assertStringNotContainsString('private function changeHeadDeclineCode(', $source);
    }

    private function source(string $path): string
    {
        $absolute = __DIR__ . '/../../' . $path;
        $source = file_get_contents($absolute);
        $this->assertIsString($source);

        return $source;
    }
}

class moova_change_order_apply_service_test extends MoovaChangeOrderApplyServiceTest
{
}
