<?php

require_once __DIR__ . '/InventoryFeatureFlags.php';

class InventoryAuditService
{
    private InventoryFeatureFlags $flags;

    public function __construct(?InventoryFeatureFlags $flags = null)
    {
        $this->flags = $flags ?: new InventoryFeatureFlags();
    }

    public function record(array $event): array
    {
        return [
            'success' => true,
            'noop' => !$this->flags->canWriteQuantityLedger(),
            'mode' => $this->flags->mode(),
            'intended_action' => 'record_audit',
            'event' => $event,
            'writes' => [],
        ];
    }
}
