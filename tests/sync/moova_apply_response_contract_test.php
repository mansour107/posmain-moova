<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Moova/MoovaApplyResponse.php';

class MoovaApplyResponseContractTest extends TestCase
{
    public function testEventTypeForChangeAction(): void
    {
        $this->assertSame('cancel_order', MoovaApplyResponse::eventTypeForAction('cancel'));
        $this->assertSame('cancel_order', MoovaApplyResponse::eventTypeForAction(' CANCEL '));
        $this->assertSame('edit_order', MoovaApplyResponse::eventTypeForAction('edit'));
    }

    public function testDirectWidgetResponseContract(): void
    {
        $response = MoovaApplyResponse::directWidget([
            'success' => true,
            'applied' => true,
            'orderId' => 44,
        ], 'new_order');

        $this->assertSame(44, $response['orderId']);
        $this->assertSame('widget', $response['deliveryPath']);
        $this->assertSame('direct_widget', $response['applyPath']);
        $this->assertSame('new_order', $response['syncEventType']);
        $this->assertSame('applied', $response['syncStatus']);
    }

    public function testQueuedWorkerChangeResponseContract(): void
    {
        $response = MoovaApplyResponse::queuedWorkerChange([
            'success' => true,
            'applied' => false,
            'providerStatus' => 'declined',
            'code' => 'POS_ORDER_LINK_NOT_FOUND',
        ], 'cancel');

        $this->assertSame('poller', $response['deliveryPath']);
        $this->assertSame('queued_worker', $response['applyPath']);
        $this->assertSame('cancel_order', $response['syncEventType']);
        $this->assertSame('declined', $response['syncStatus']);
    }

    public function testHelperRestampsCurrentPathAndFailedStatus(): void
    {
        $response = MoovaApplyResponse::queuedWorker([
            'success' => true,
            'applied' => false,
            'providerStatus' => 'failed',
            'deliveryPath' => 'widget',
            'applyPath' => 'direct_widget',
            'syncEventType' => 'edit_order',
            'syncStatus' => 'declined',
        ], 'new_order');

        $this->assertSame('poller', $response['deliveryPath']);
        $this->assertSame('queued_worker', $response['applyPath']);
        $this->assertSame('new_order', $response['syncEventType']);
        $this->assertSame('failed', $response['syncStatus']);
    }

    public function testSharedDeclineMessageContract(): void
    {
        $this->assertSame(
            'This Moova order lines changed in the POS after the last Moova sync.',
            MoovaApplyResponse::declineMessage('POS_ORDER_LINES_CHANGED')
        );
        $this->assertSame(
            'POS declined the order change.',
            MoovaApplyResponse::declineMessage('UNKNOWN_CODE')
        );
    }
}

class moova_apply_response_contract_test extends MoovaApplyResponseContractTest
{
}
