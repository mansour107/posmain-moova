<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/MoovaPosIntegration.php';

class MoovaPosIntegrationChangePayloadTest extends TestCase
{
    public function test_change_payload_hash_tracks_action_order_event_and_replacement_items(): void
    {
        $payload = [
            'action' => 'edit',
            'moovaOrderId' => 'order-123',
            'requestEventId' => 'event-456',
            'providerOrderId' => '77',
            'providerReferenceId' => 'ref-77',
            'items' => [
                ['itemId' => '10', 'qty' => 2],
                ['itemId' => '11', 'qty' => 1],
            ],
            'ignored' => 'not part of the contract',
        ];

        $sameContract = $payload;
        $sameContract['ignored'] = 'different ignored value';

        $this->assertSame(
            MoovaPosIntegration::changePayloadHash($payload),
            MoovaPosIntegration::changePayloadHash($sameContract)
        );

        $cancelPayload = $payload;
        $cancelPayload['action'] = 'cancel';

        $this->assertNotSame(
            MoovaPosIntegration::changePayloadHash($payload),
            MoovaPosIntegration::changePayloadHash($cancelPayload)
        );
    }
}
