<?php

require_once __DIR__ . '/../../Sync/SyncOutboxEventService.php';
require_once __DIR__ . '/OrderEventService.php';
require_once __DIR__ . '/KitchenEventPublisher.php';
require_once __DIR__ . '/KitchenTicketRevisionService.php';
require_once __DIR__ . '/KdsTicketService.php';
require_once __DIR__ . '/SideEffectPolicy.php';

class OrderMutationSideEffectsService
{
    private $syncOutbox;
    private $orderEvents;
    private $kitchenPublisher;
    private $kitchenTicketRevisions;
    private $kdsTickets;

    public function __construct(
        ?SyncOutboxEventService $syncOutbox = null,
        ?OrderEventService $orderEvents = null,
        ?KitchenEventPublisher $kitchenPublisher = null,
        ?KitchenTicketRevisionService $kitchenTicketRevisions = null,
        ?KdsTicketService $kdsTickets = null
    ) {
        $this->syncOutbox = $syncOutbox ?: new SyncOutboxEventService();
        $this->orderEvents = $orderEvents ?: new OrderEventService();
        $this->kitchenPublisher = $kitchenPublisher ?: new KitchenEventPublisher();
        $this->kitchenTicketRevisions = $kitchenTicketRevisions ?: new KitchenTicketRevisionService();
        $this->kdsTickets = $kdsTickets ?: new KdsTicketService();
    }

    public function preflightSyncIdentity(mysqli $conn): void
    {
        try {
            $this->syncOutbox->prepareBranchIdentity($conn);
        } catch (Throwable $exception) {
            if (SideEffectPolicy::orderEventShouldRollback($exception)) {
                throw $exception;
            }
            error_log('POS sync identity preflight skipped: ' . $exception->getMessage());
        }
    }

    public function recordTableSave(
        mysqli $conn,
        int $orderId,
        int $tableId,
        bool $isUpdate,
        string $orderStatus,
        int $userId,
        string $sourceSystem = 'pos_table',
        string $eventSource = 'pos_table_save',
        int $kitchenRevision = 0
    ): void {
        if ($orderId < 1) {
            return;
        }

        $this->publishKitchenRevision($conn, $orderId, $kitchenRevision, $isUpdate, [
            'table_id' => $tableId,
            'is_full_refresh' => true,
        ]);

        $this->syncKitchenDisplay($conn, $orderId, $isUpdate ? 'updated' : 'new', $userId);

        $eventType = $isUpdate ? 'order.updated' : 'order.saved';
        $this->recordOrderLifecycle($conn, $orderId, $eventType, $eventSource, $userId, [
            'table_id' => $tableId,
            'order_status' => $orderStatus,
            'is_update' => $isUpdate,
            'channel' => 'table',
            'kitchen_revision' => $kitchenRevision,
        ], $sourceSystem, $tableId > 0 ? $orderId : null, $tableId);
    }

    public function recordCashierMutation(
        mysqli $conn,
        int $orderId,
        string $channel,
        bool $isUpdate,
        int $userId,
        string $orderStatus,
        string $sourceSystem,
        string $eventSource,
        array $metadata = [],
        int $kitchenRevision = 0
    ): void {
        if ($orderId < 1) {
            return;
        }

        $this->publishKitchenRevision($conn, $orderId, $kitchenRevision, $isUpdate, array_merge([
            'channel' => $channel,
            'is_full_refresh' => true,
        ], $metadata));

        $this->syncKitchenDisplay($conn, $orderId, $isUpdate ? 'updated' : 'new', $userId, $metadata);

        $eventType = $isUpdate ? 'order.updated' : 'order.saved';
        $this->recordOrderLifecycle($conn, $orderId, $eventType, $eventSource, $userId, array_merge([
            'order_status' => $orderStatus,
            'is_update' => $isUpdate,
            'channel' => $channel,
            'kitchen_revision' => $kitchenRevision,
        ], $metadata), $sourceSystem);
    }

    public function recordTablePayment(
        mysqli $conn,
        int $orderId,
        int $tableId,
        int $userId,
        bool $fullyPaid,
        string $sourceSystem = 'pos_table_payment',
        string $eventSource = 'pos_table_payment'
    ): void {
        if ($orderId < 1) {
            return;
        }

        $this->recordOrderLifecycle($conn, $orderId, 'order.payment_recorded', $eventSource, $userId, [
            'table_id' => $tableId,
            'fully_paid' => $fullyPaid,
            'channel' => 'table',
        ], $sourceSystem, $fullyPaid ? null : $orderId, $tableId);

        if ($fullyPaid) {
            $this->kitchenPublisher->publishForOrder($conn, $orderId, 'kitchen.ticket.paid', [
                'table_id' => $tableId,
            ]);
        }

        $this->syncKitchenDisplay($conn, $orderId, 'paid', $userId);
    }

    public function recordSplitPayment(
        mysqli $conn,
        int $originalOrderId,
        int $newOrderId,
        int $tableId,
        int $userId,
        $activeTableOrderId = null,
        string $sourceSystem = 'pos_split_payment',
        string $eventSource = 'pos_split_payment'
    ): void {
        if ($originalOrderId > 0) {
            $this->recordOrderLifecycle($conn, $originalOrderId, 'order.updated', $eventSource, $userId, [
                'table_id' => $tableId,
                'channel' => 'table',
                'split' => true,
            ], $sourceSystem, is_numeric($activeTableOrderId) ? (int) $activeTableOrderId : null, $tableId);
        }

        if ($newOrderId > 0) {
            $this->recordOrderLifecycle($conn, $newOrderId, 'order.split_paid', $eventSource, $userId, [
                'table_id' => $tableId,
                'channel' => 'table',
                'split' => true,
            ], $sourceSystem, is_numeric($activeTableOrderId) ? (int) $activeTableOrderId : null, $tableId);
        }
    }

    public function recordTableFreed(
        mysqli $conn,
        int $tableId,
        int $userId,
        string $sourceSystem = 'pos_cashier_empty_table',
        string $eventSource = 'pos_table_free'
    ): void {
        if ($tableId < 1) {
            return;
        }

        try {
            $this->syncOutbox->recordTableSnapshot($conn, $tableId, [
                'event_type' => 'table.updated',
                'source_system' => $sourceSystem,
                'active_order_id' => null,
            ]);
        } catch (Throwable $exception) {
            error_log('POS table free outbox skipped: ' . $exception->getMessage());
        }

        $this->orderEvents->recordIfAvailable($conn, 0, 'table.freed', $eventSource, [
            'actor_user_id' => $userId,
            'metadata' => ['table_id' => $tableId],
        ]);
    }

    /** Persist the exact kitchen snapshot or fail the enclosing send visibly. */
    private function syncKitchenDisplay(
        mysqli $conn,
        int $orderId,
        string $eventType,
        int $userId,
        array $eventMetadata = []
    ): void
    {
        if ($orderId < 1) {
            return;
        }

        $this->kdsTickets->syncForOrder($conn, $orderId, $eventType, $userId, $eventMetadata);
    }

    private function publishKitchenRevision(
        mysqli $conn,
        int $orderId,
        int $kitchenRevision,
        bool $isUpdate,
        array $metadata = []
    ): void {
        if ($kitchenRevision < 1) {
            $legacyEvent = $isUpdate ? 'kitchen.ticket.updated' : 'kitchen.ticket.new';
            $this->kitchenPublisher->publishForOrder($conn, $orderId, $legacyEvent, $metadata);

            return;
        }

        $revisionEnvelope = $this->kitchenTicketRevisions->recordRevision($conn, $orderId, $kitchenRevision);
        $this->kitchenPublisher->publishRevision($conn, $orderId, $revisionEnvelope, array_merge($metadata, [
            'is_update' => $isUpdate,
        ]));
    }

    private function recordOrderLifecycle(
        mysqli $conn,
        int $orderId,
        string $eventType,
        string $eventSource,
        int $userId,
        array $metadata,
        string $sourceSystem,
        ?int $activeOrderId = null,
        int $tableId = 0
    ): void {
        try {
            $this->syncOutbox->recordOrderSnapshot($conn, $orderId, [
                'event_type' => $eventType,
                'source_system' => $sourceSystem,
            ]);
        } catch (Throwable $exception) {
            if (SideEffectPolicy::orderEventShouldRollback($exception)) {
                throw $exception;
            }
            error_log('POS order outbox skipped: ' . $exception->getMessage());
        }

        if ($tableId > 0) {
            try {
                $this->syncOutbox->recordTableSnapshot($conn, $tableId, [
                    'event_type' => 'table.updated',
                    'source_system' => $sourceSystem,
                    'active_order_id' => $activeOrderId,
                ]);
            } catch (Throwable $exception) {
                if (SideEffectPolicy::orderEventShouldRollback($exception)) {
                    throw $exception;
                }
                error_log('POS table outbox skipped: ' . $exception->getMessage());
            }
        }

        // PosOrderMutationService owns the durable order_events write inside the
        // same business transaction. This coordinator is responsible only for
        // outbox/table snapshots and kitchen/KDS projections; writing the same
        // lifecycle event here creates duplicate audit facts for one mutation.
    }
}
