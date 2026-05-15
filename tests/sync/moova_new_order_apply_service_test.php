<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Moova/MoovaNewOrderApplyService.php';

class MoovaNewOrderApplyServiceTest extends TestCase
{
    public function testSharedServiceOwnsNewOrderPersistenceAndResponseContract(): void
    {
        $source = $this->source('classes/Moova/MoovaNewOrderApplyService.php');

        $this->assertStringContainsString('class MoovaNewOrderApplyService', $source);
        $this->assertStringContainsString('public function applyInTransaction', $source);
        $this->assertStringContainsString('FROM moova_pos_order_links', $source);
        $this->assertStringContainsString('INSERT INTO moova_pos_order_links', $source);
        $this->assertStringContainsString('UPDATE moova_pos_order_links', $source);
        $this->assertStringContainsString('createOrMergeMoovaTableOrder', $source);
        $this->assertStringContainsString("throw new RuntimeException('IDEMPOTENCY_PAYLOAD_CONFLICT')", $source);
        $this->assertStringContainsString('MoovaApplyResponse::directWidget', $source);
        $this->assertStringContainsString('MoovaApplyResponse::queuedWorker', $source);
    }

    public function testDirectConfirmEndpointDelegatesNewOrderApply(): void
    {
        $source = $this->source('ajax/moova_confirm_order.php');

        $this->assertStringContainsString("require_once('../classes/Moova/MoovaLocalIngestService.php')", $source);
        $this->assertStringContainsString("require_once('../classes/Pos/Service/PosOrderMutationService.php')", $source);
        $this->assertStringContainsString('new MoovaLocalIngestService()', $source);
        $this->assertStringContainsString('normalizeNewOrderForPos($payload)', $source);
        $this->assertStringContainsString('->confirmMoovaOrder($conn', $source);
        $this->assertStringContainsString("'response_mode' => 'direct'", $source);
        $this->assertStringContainsString("'request_hash' => \$requestHash", $source);
        $this->assertStringNotContainsString('function moova_fetch_order_link_for_update', $source);
        $this->assertStringNotContainsString('function moova_create_order_link', $source);
        $this->assertStringNotContainsString('function moova_update_order_link_success', $source);
    }

    public function testQueuedApplyWorkerDelegatesNewOrderApply(): void
    {
        $source = $this->source('classes/Sync/BranchMoovaApplyWorker.php');

        $this->assertStringContainsString("require_once __DIR__ . '/../Moova/MoovaNewOrderApplyService.php'", $source);
        $this->assertStringContainsString('private MoovaNewOrderApplyService $newOrderApply', $source);
        $this->assertStringContainsString('$this->newOrderApply->applyInTransaction', $source);
        $this->assertStringContainsString("'response_mode' => 'queued'", $source);
        $this->assertStringNotContainsString('private function createOrderLink(', $source);
        $this->assertStringNotContainsString('private function responseFromOrder(', $source);
        $this->assertStringNotContainsString('private function responseFromExistingLink(', $source);
    }

    private function source(string $path): string
    {
        $absolute = __DIR__ . '/../../' . $path;
        $source = file_get_contents($absolute);
        $this->assertIsString($source);

        return $source;
    }
}

class moova_new_order_apply_service_test extends MoovaNewOrderApplyServiceTest
{
}
