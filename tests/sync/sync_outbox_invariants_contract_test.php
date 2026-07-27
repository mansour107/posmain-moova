<?php

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/classes/Sync/SyncOutboxEventService.php');
$schema = file_get_contents($root . '/classes/Sync/SchemaManager.php');
$worker = file_get_contents($root . '/classes/Sync/OutboxWorker.php');

syncOutboxInvariantAssert(is_string($service), 'Unable to read SyncOutboxEventService.php.');
syncOutboxInvariantAssert(is_string($schema), 'Unable to read SchemaManager.php.');
syncOutboxInvariantAssert(is_string($worker), 'Unable to read OutboxWorker.php.');

foreach ([
    'source_transaction_id',
    'findOutboxByIdempotencyKey',
    'assertImmutableReplayMatches',
    'OUTBOX_IDEMPOTENCY_PAYLOAD_CONFLICT',
    "'replayed' => true",
] as $required) {
    syncOutboxInvariantAssert(
        strpos($service, $required) !== false,
        'Outbox event service must preserve immutable logical-transaction replay behavior: ' . $required
    );
}

syncOutboxInvariantAssert(
    strpos($service, 'ON DUPLICATE KEY UPDATE') === false,
    'Outbox inserts must not use an upsert that can mutate an existing event.'
);
foreach ([
    'payload_json = VALUES(payload_json)',
    'payload_hash = VALUES(payload_hash)',
    'updated_at = CURRENT_TIMESTAMP',
] as $forbiddenMutation) {
    syncOutboxInvariantAssert(
        strpos($service, $forbiddenMutation) === false,
        'Immutable outbox replay must not contain payload/timestamp mutation: ' . $forbiddenMutation
    );
}

syncOutboxInvariantAssert(
    substr_count($schema, "source_transaction_id VARCHAR(191) NOT NULL DEFAULT ''") >= 2,
    'Fresh and upgraded sync_outbox schemas must include source_transaction_id.'
);
syncOutboxInvariantAssert(
    substr_count($schema, "ENUM('held','pending','syncing','synced','failed','dead')") >= 2,
    'Fresh and upgraded sync_outbox schemas must support the held status.'
);
syncOutboxInvariantAssert(
    strpos($schema, 'UNIQUE KEY uq_sync_outbox_idempotency (branch_uuid, idempotency_key)') !== false,
    'sync_outbox must enforce one event per branch-scoped logical idempotency key.'
);

syncOutboxInvariantAssert(
    strpos($worker, "status IN ('pending', 'failed')") !== false,
    'Outbox worker claim eligibility must be explicit.'
);
syncOutboxInvariantAssert(
    strpos($worker, "status IN ('pending', 'failed', 'held')") === false,
    'Held events must never become worker-claimable.'
);

echo "sync-outbox-invariants-contract-ok\n";

function syncOutboxInvariantAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
