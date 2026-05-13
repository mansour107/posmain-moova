<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SyncApplyMode.php';
require_once __DIR__ . '/../../classes/Sync/SyncDeliveryResultHandler.php';

class SyncApplyResponseTest extends TestCase
{
    public function testApplyModeKeepsReceiveOnlySeparateFromShadowApply(): void
    {
        $this->assertSame(SyncApplyMode::RECEIVE_ONLY, SyncApplyMode::fromFlags(false, false));
        $this->assertSame(SyncApplyMode::RECEIVE_ONLY, SyncApplyMode::fromFlags(false, true));
        $this->assertSame(SyncApplyMode::SHADOW_APPLY, SyncApplyMode::fromFlags(true, true));
        $this->assertSame(SyncApplyMode::LIVE_APPLY, SyncApplyMode::fromFlags(true, false));
    }

    public function testAcceptedShadowResponseShape(): void
    {
        $receiveOnly = SyncApplyMode::acceptedResult(
            SyncApplyMode::RECEIVE_ONLY,
            'event-1',
            'key-1',
            null,
            'stored only'
        );
        $shadow = SyncApplyMode::acceptedResult(
            SyncApplyMode::SHADOW_APPLY,
            'event-2',
            'key-2',
            'cloud-2',
            'shadow applied'
        );
        $live = SyncApplyMode::acceptedResult(
            SyncApplyMode::LIVE_APPLY,
            'event-3',
            'key-3',
            'cloud-3',
            'processed'
        );

        $this->assertSame('accepted_shadow', $receiveOnly['status']);
        $this->assertFalse($receiveOnly['applied']);
        $this->assertFalse($receiveOnly['report_trusted']);
        $this->assertSame('accepted_shadow', $shadow['status']);
        $this->assertTrue($shadow['applied']);
        $this->assertFalse($shadow['report_trusted']);
        $this->assertSame('processed', $live['status']);
        $this->assertTrue($live['applied']);
        $this->assertTrue($live['report_trusted']);
    }

    public function testAcceptedShadowIsSuccessfulOutboxDelivery(): void
    {
        $this->assertTrue(SyncDeliveryResultHandler::isSuccessfulStatus('accepted_shadow'));
        $this->assertSame('synced', SyncDeliveryResultHandler::outboxStatusForResult(['status' => 'accepted_shadow']));
        $this->assertSame('synced', SyncDeliveryResultHandler::outboxStatusForResult(['status' => 'processed']));
        $this->assertSame('synced', SyncDeliveryResultHandler::outboxStatusForResult(['status' => 'duplicate']));
        $this->assertSame('synced', SyncDeliveryResultHandler::outboxStatusForResult(['status' => 'stale']));
        $this->assertSame('dead', SyncDeliveryResultHandler::outboxStatusForResult(['status' => 'conflict']));
        $this->assertSame('failed', SyncDeliveryResultHandler::outboxStatusForResult(['status' => 'failed']));
    }
}

class sync_apply_response_test extends SyncApplyResponseTest
{
}
