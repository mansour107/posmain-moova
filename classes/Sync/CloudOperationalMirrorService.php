<?php

require_once __DIR__ . '/OperationalSyncDomains.php';
require_once __DIR__ . '/PosOrderSnapshotBuilder.php';
require_once __DIR__ . '/BranchIdentity.php';
require_once __DIR__ . '/PosCustomerSyncPayloadService.php';
require_once __DIR__ . '/InventoryAccountingSyncPayloadService.php';
require_once __DIR__ . '/InventoryCountSyncPayloadService.php';
require_once __DIR__ . '/ProductionBatchSyncPayloadService.php';
require_once __DIR__ . '/PurchaseReceiptSyncPayloadService.php';
require_once __DIR__ . '/PurchaseOrderSyncPayloadService.php';

class CloudOperationalMirrorService
{
    private array $columnCache = [];

    public function applyFromBranchEvent(mysqli $conn, string $branchUuid, array $event): ?array
    {
        $payload = $this->payload($event);
        $snapshotType = (string) ($payload['snapshot_type'] ?? '');

        if ($snapshotType === 'operational_row') {
            return $this->mirrorOperationalRow($conn, $payload);
        }

        if ($snapshotType === 'operational_delete') {
            return $this->mirrorOperationalDelete($conn, $payload);
        }

        if ($snapshotType === 'recipe_bundle') {
            return $this->mirrorRecipeBundle($conn, $payload);
        }

        if ($snapshotType === 'shop_settings') {
            return $this->mirrorShopSettings($conn, $payload);
        }

        if ($snapshotType === 'modifier_group_bundle') {
            return $this->mirrorModifierGroupBundle($conn, $payload);
        }

        if ($snapshotType === 'moova_shop_link') {
            return $this->mirrorMoovaShopLink($conn, $payload);
        }

        if ($snapshotType === 'shift_close') {
            return $this->mirrorShiftClose($conn, $branchUuid, $payload);
        }

        if ($snapshotType === 'drawer_session_snapshot') {
            return $this->mirrorDrawerSessionSnapshot($conn, $payload);
        }

        if ($snapshotType === 'drawer_movement_bundle') {
            return $this->mirrorDrawerMovementBundle($conn, $payload);
        }

        if ($snapshotType === 'financial_refund_bundle') {
            return $this->mirrorFinancialRefundBundle($conn, $payload);
        }

        if ($snapshotType === 'customer_bundle') {
            return $this->mirrorCustomerBundle($conn, $branchUuid, $event, $payload);
        }

        if ($snapshotType === 'inventory_journal_bundle') {
            return $this->mirrorInventoryJournalBundle($conn, $branchUuid, $event, $payload);
        }

        if ($snapshotType === 'inventory_count_bundle') {
            return $this->mirrorInventoryCountBundle($conn, $branchUuid, $event, $payload);
        }

        if ($snapshotType === 'production_batch_bundle') {
            return $this->mirrorProductionBatchBundle($conn, $branchUuid, $event, $payload);
        }

        if ($snapshotType === 'purchase_receipt_bundle') {
            return $this->mirrorPurchaseReceiptBundle($conn, $branchUuid, $event, $payload);
        }

        if ($snapshotType === 'purchase_order_bundle') {
            return $this->mirrorPurchaseOrderBundle($conn, $branchUuid, $event, $payload);
        }

        return null;
    }

    private function mirrorPurchaseOrderBundle(mysqli $conn,string $branchUuid,array $event,array $payload): array
    {
        $service=new PurchaseOrderSyncPayloadService();$service->assertValid($payload,$branchUuid,$event);
        foreach(['inventory_purchase_orders','inventory_purchase_order_lines'] as $table)if(!$this->tableExists($conn,$table))throw new RuntimeException('PURCHASE_ORDER_SYNC_SCHEMA_REQUIRED');
        if(!in_array('sync_revision',$this->tableColumns($conn,'inventory_purchase_orders'),true))throw new RuntimeException('PURCHASE_ORDER_SYNC_SCHEMA_REQUIRED');
        $order=$payload['purchase_order'];$id=(int)$order['id'];$uuid=strtolower((string)$order['purchase_order_uuid']);$revision=(int)$order['sync_revision'];$existing=$this->rowForUpdate($conn,'inventory_purchase_orders',$id);
        $stmt=$conn->prepare('SELECT * FROM inventory_purchase_orders WHERE purchase_order_uuid = ? LIMIT 1 FOR UPDATE');$stmt->bind_param('s',$uuid);$stmt->execute();$byUuid=$stmt->get_result()->fetch_assoc();$stmt->close();
        if($byUuid&&(int)$byUuid['id']!==$id)throw new RuntimeException('PURCHASE_ORDER_SYNC_PARENT_IDENTITY_CONFLICT');
        if($existing){
            foreach(['purchase_order_uuid','pos_tenant','pos_branch','branch_uuid','supplier_account_id','destination_store_id','created_by','created_at'] as $field)if((string)($existing[$field]??'')!==(string)($order[$field]??''))throw new RuntimeException('PURCHASE_ORDER_SYNC_PARENT_IDENTITY_CONFLICT');
            $stored=(int)($existing['sync_revision']??0);if($stored>$revision)throw new RuntimeException('PURCHASE_ORDER_SYNC_STALE_REVISION');
            if($stored===$revision){$built=$service->build($conn,$branchUuid,$id);if(!hash_equals((string)$built['payload_hash'],(string)$payload['payload_hash']))throw new RuntimeException('PURCHASE_ORDER_SYNC_SAME_VERSION_CONFLICT');return ['entity_id'=>'inventory_purchase_orders:'.$id,'order_id'=>$id,'line_count'=>count($payload['purchase_order_lines']),'idempotent_replay'=>true];}
        }
        $incoming=[];foreach($payload['purchase_order_lines'] as $line)$incoming[(int)$line['id']]=$line;
        $stmt=$conn->prepare('SELECT * FROM inventory_purchase_order_lines WHERE purchase_order_id = ? ORDER BY id ASC FOR UPDATE');$stmt->bind_param('i',$id);$stmt->execute();$result=$stmt->get_result();while($storedLine=$result->fetch_assoc()){ $lineId=(int)$storedLine['id'];if(!isset($incoming[$lineId])){$stmt->close();throw new RuntimeException('PURCHASE_ORDER_SYNC_MISSING_LINE_CONFLICT');}foreach(['purchase_order_id','item_id','unit_id','ordered_qty','unit_cost','total_cost','created_at'] as $field)if((string)($storedLine[$field]??'')!==(string)($incoming[$lineId][$field]??'')){$stmt->close();throw new RuntimeException('PURCHASE_ORDER_SYNC_LINE_IDENTITY_CONFLICT');}}$stmt->close();
        $this->upsertRow($conn,'inventory_purchase_orders',$order);foreach($payload['purchase_order_lines'] as $line){$existingLine=$this->rowForUpdate($conn,'inventory_purchase_order_lines',(int)$line['id']);if($existingLine&&(int)$existingLine['purchase_order_id']!==$id)throw new RuntimeException('PURCHASE_ORDER_SYNC_LINE_IDENTITY_CONFLICT');$this->upsertRow($conn,'inventory_purchase_order_lines',$line);}
        return ['entity_id'=>'inventory_purchase_orders:'.$id,'order_id'=>$id,'line_count'=>count($payload['purchase_order_lines']),'idempotent_replay'=>false];
    }

    private function mirrorPurchaseReceiptBundle(
        mysqli $conn,
        string $branchUuid,
        array $event,
        array $payload
    ): array {
        $payloadService = new PurchaseReceiptSyncPayloadService();
        $payloadService->assertValid($payload, $branchUuid, $event);
        foreach (['inventory_purchase_receipts', 'inventory_purchase_receipt_lines', 'inventory_movements'] as $table) {
            if (!$this->tableExists($conn, $table)) {
                throw new RuntimeException('PURCHASE_RECEIPT_SYNC_SCHEMA_REQUIRED');
            }
        }

        $receipt = $payload['purchase_receipt'];
        $receiptId = (int) $receipt['id'];
        $receiptUuid = strtolower((string) $receipt['purchase_receipt_uuid']);
        $existing = $this->rowForUpdate($conn, 'inventory_purchase_receipts', $receiptId);

        $uuidLock = $conn->prepare('SELECT * FROM inventory_purchase_receipts WHERE purchase_receipt_uuid = ? LIMIT 1 FOR UPDATE');
        $uuidLock->bind_param('s', $receiptUuid);
        $uuidLock->execute();
        $existingByUuid = $uuidLock->get_result()->fetch_assoc();
        $uuidLock->close();
        if ($existingByUuid && (int) $existingByUuid['id'] !== $receiptId) {
            throw new RuntimeException('PURCHASE_RECEIPT_SYNC_PARENT_IDENTITY_CONFLICT');
        }
        if ($existing) {
            foreach (array_keys($receipt) as $field) {
                if ((string) ($existing[$field] ?? '') !== (string) ($receipt[$field] ?? '')) {
                    throw new RuntimeException('PURCHASE_RECEIPT_SYNC_PARENT_IDENTITY_CONFLICT');
                }
            }
        }

        $incomingLineIds = [];
        foreach ($payload['purchase_receipt_lines'] as $line) {
            $incomingLineIds[(int) $line['id']] = true;
        }
        $storedStmt = $conn->prepare('SELECT * FROM inventory_purchase_receipt_lines WHERE purchase_receipt_id = ? ORDER BY id ASC FOR UPDATE');
        $storedStmt->bind_param('i', $receiptId);
        $storedStmt->execute();
        $storedResult = $storedStmt->get_result();
        while ($storedLine = $storedResult->fetch_assoc()) {
            if (!isset($incomingLineIds[(int) $storedLine['id']])) {
                $storedStmt->close();
                throw new RuntimeException('PURCHASE_RECEIPT_SYNC_MISSING_LINE_CONFLICT');
            }
        }
        $storedStmt->close();

        $payloadService->assertMovementOwnership($conn, $receiptId, $receipt, $payload['purchase_receipt_lines']);
        $this->upsertRow($conn, 'inventory_purchase_receipts', $receipt);
        foreach ($payload['purchase_receipt_lines'] as $line) {
            $lineId = (int) $line['id'];
            $existingLine = $this->rowForUpdate($conn, 'inventory_purchase_receipt_lines', $lineId);
            if ($existingLine) {
                foreach (array_keys($line) as $field) {
                    if ((string) ($existingLine[$field] ?? '') !== (string) ($line[$field] ?? '')) {
                        throw new RuntimeException('PURCHASE_RECEIPT_SYNC_LINE_IDENTITY_CONFLICT');
                    }
                }
            }
            $this->upsertRow($conn, 'inventory_purchase_receipt_lines', $line);
        }

        return [
            'entity_id' => 'inventory_purchase_receipts:' . $receiptId,
            'receipt_id' => $receiptId,
            'line_count' => count($payload['purchase_receipt_lines']),
            'idempotent_replay' => $existing !== null,
        ];
    }

    private function mirrorProductionBatchBundle(
        mysqli $conn,
        string $branchUuid,
        array $event,
        array $payload
    ): array {
        $payloadService = new ProductionBatchSyncPayloadService();
        $payloadService->assertValid($payload, $branchUuid, $event);
        foreach (['production_batches', 'production_batch_lines', 'inventory_movements'] as $table) {
            if (!$this->tableExists($conn, $table)) {
                throw new RuntimeException('PRODUCTION_BATCH_SYNC_SCHEMA_REQUIRED');
            }
        }
        if (!in_array('sync_revision', $this->tableColumns($conn, 'production_batches'), true)) {
            throw new RuntimeException('PRODUCTION_BATCH_SYNC_SCHEMA_REQUIRED');
        }

        $batch = $payload['production_batch'];
        $batchId = (int) $batch['id'];
        $batchUuid = strtolower((string) $batch['batch_uuid']);
        $incomingRevision = (int) $batch['sync_revision'];
        $existing = $this->rowForUpdate($conn, 'production_batches', $batchId);

        $uuidLock = $conn->prepare('SELECT * FROM production_batches WHERE batch_uuid = ? LIMIT 1 FOR UPDATE');
        $uuidLock->bind_param('s', $batchUuid);
        $uuidLock->execute();
        $existingByUuid = $uuidLock->get_result()->fetch_assoc();
        $uuidLock->close();
        if ($existingByUuid && (int) $existingByUuid['id'] !== $batchId) {
            throw new RuntimeException('PRODUCTION_BATCH_SYNC_PARENT_IDENTITY_CONFLICT');
        }

        if ($existing) {
            foreach ([
                'batch_uuid', 'pos_tenant', 'pos_branch', 'branch_uuid', 'store_id',
                'recipe_id', 'output_item_id', 'planned_output_qty', 'created_by', 'created_at',
            ] as $field) {
                if ((string) ($existing[$field] ?? '') !== (string) ($batch[$field] ?? '')) {
                    throw new RuntimeException('PRODUCTION_BATCH_SYNC_PARENT_IDENTITY_CONFLICT');
                }
            }

            $storedRevision = (int) ($existing['sync_revision'] ?? 0);
            if ($storedRevision > $incomingRevision) {
                throw new RuntimeException('PRODUCTION_BATCH_SYNC_STALE_REVISION');
            }
            if ($storedRevision === $incomingRevision) {
                $storedPayload = $payloadService->build($conn, $branchUuid, $batchId);
                if (!hash_equals((string) $storedPayload['payload_hash'], (string) $payload['payload_hash'])) {
                    throw new RuntimeException('PRODUCTION_BATCH_SYNC_SAME_VERSION_CONFLICT');
                }

                return [
                    'entity_id' => 'production_batches:' . $batchId,
                    'batch_id' => $batchId,
                    'line_count' => count($payload['production_batch_lines']),
                    'idempotent_replay' => true,
                ];
            }
        }

        $incomingLineIds = [];
        foreach ($payload['production_batch_lines'] as $line) {
            $incomingLineIds[(int) $line['id']] = true;
        }
        $storedLines = [];
        $storedStmt = $conn->prepare('SELECT * FROM production_batch_lines WHERE batch_id = ? ORDER BY id ASC FOR UPDATE');
        $storedStmt->bind_param('i', $batchId);
        $storedStmt->execute();
        $storedResult = $storedStmt->get_result();
        while ($storedLine = $storedResult->fetch_assoc()) {
            $storedLines[(int) $storedLine['id']] = $storedLine;
        }
        $storedStmt->close();
        foreach (array_keys($storedLines) as $storedLineId) {
            if (!isset($incomingLineIds[$storedLineId])) {
                throw new RuntimeException('PRODUCTION_BATCH_SYNC_MISSING_LINE_CONFLICT');
            }
        }

        $payloadService->assertMovementOwnership($conn, $batchId, $payload['production_batch_lines']);
        $this->upsertRow($conn, 'production_batches', $batch);
        foreach ($payload['production_batch_lines'] as $line) {
            $lineId = (int) $line['id'];
            $existingLine = $this->rowForUpdate($conn, 'production_batch_lines', $lineId);
            if ($existingLine) {
                foreach (array_keys($line) as $field) {
                    if ((string) ($existingLine[$field] ?? '') !== (string) ($line[$field] ?? '')) {
                        throw new RuntimeException('PRODUCTION_BATCH_SYNC_LINE_IDENTITY_CONFLICT');
                    }
                }
            }
            $this->upsertRow($conn, 'production_batch_lines', $line);
        }

        return [
            'entity_id' => 'production_batches:' . $batchId,
            'batch_id' => $batchId,
            'line_count' => count($payload['production_batch_lines']),
            'idempotent_replay' => false,
        ];
    }

    private function mirrorInventoryCountBundle(
        mysqli $conn,
        string $branchUuid,
        array $event,
        array $payload
    ): array {
        $payloadService = new InventoryCountSyncPayloadService();
        $payloadService->assertValid($payload, $branchUuid, $event);
        foreach (['inventory_counts', 'inventory_count_lines'] as $table) {
            if (!$this->tableExists($conn, $table)) {
                throw new RuntimeException('INVENTORY_COUNT_SYNC_SCHEMA_REQUIRED');
            }
        }
        if (!in_array('sync_revision', $this->tableColumns($conn, 'inventory_counts'), true)) {
            throw new RuntimeException('INVENTORY_COUNT_SYNC_SCHEMA_REQUIRED');
        }

        $count = $payload['inventory_count'];
        $countId = (int) $count['id'];
        $countUuid = strtolower((string) $count['count_uuid']);
        $incomingRevision = (int) $count['sync_revision'];
        $existing = $this->rowForUpdate($conn, 'inventory_counts', $countId);

        $uuidLock = $conn->prepare('SELECT * FROM inventory_counts WHERE count_uuid = ? LIMIT 1 FOR UPDATE');
        $uuidLock->bind_param('s', $countUuid);
        $uuidLock->execute();
        $existingByUuid = $uuidLock->get_result()->fetch_assoc();
        $uuidLock->close();
        if ($existingByUuid && (int) $existingByUuid['id'] !== $countId) {
            throw new RuntimeException('INVENTORY_COUNT_SYNC_PARENT_IDENTITY_CONFLICT');
        }

        if ($existing) {
            foreach ([
                'count_uuid', 'pos_tenant', 'pos_branch', 'branch_uuid', 'store_id',
                'count_type', 'hide_expected_qty', 'include_zero_stock',
                'assigned_user_id', 'created_by', 'created_at',
            ] as $field) {
                if ((string) ($existing[$field] ?? '') !== (string) ($count[$field] ?? '')) {
                    throw new RuntimeException('INVENTORY_COUNT_SYNC_PARENT_IDENTITY_CONFLICT');
                }
            }

            $storedRevision = (int) ($existing['sync_revision'] ?? 0);
            if ($storedRevision > $incomingRevision) {
                throw new RuntimeException('INVENTORY_COUNT_SYNC_STALE_REVISION');
            }
            if ($storedRevision === $incomingRevision) {
                $storedPayload = $payloadService->build($conn, $branchUuid, $countId);
                if (!hash_equals((string) $storedPayload['payload_hash'], (string) $payload['payload_hash'])) {
                    throw new RuntimeException('INVENTORY_COUNT_SYNC_SAME_VERSION_CONFLICT');
                }

                return [
                    'entity_id' => 'inventory_counts:' . $countId,
                    'count_id' => $countId,
                    'line_count' => count($payload['inventory_count_lines']),
                    'idempotent_replay' => true,
                ];
            }
        }

        $incomingLineIds = [];
        foreach ($payload['inventory_count_lines'] as $line) {
            $incomingLineIds[(int) $line['id']] = true;
        }
        $storedLines = [];
        $storedStmt = $conn->prepare('SELECT * FROM inventory_count_lines WHERE count_id = ? ORDER BY id ASC FOR UPDATE');
        $storedStmt->bind_param('i', $countId);
        $storedStmt->execute();
        $storedResult = $storedStmt->get_result();
        while ($storedLine = $storedResult->fetch_assoc()) {
            $storedLines[(int) $storedLine['id']] = $storedLine;
        }
        $storedStmt->close();
        foreach (array_keys($storedLines) as $storedLineId) {
            if (!isset($incomingLineIds[$storedLineId])) {
                throw new RuntimeException('INVENTORY_COUNT_SYNC_MISSING_LINE_CONFLICT');
            }
        }

        $this->upsertRow($conn, 'inventory_counts', $count);
        foreach ($payload['inventory_count_lines'] as $line) {
            $lineId = (int) $line['id'];
            $itemId = (int) $line['item_id'];
            $existingLine = $this->rowForUpdate($conn, 'inventory_count_lines', $lineId);
            if ($existingLine) {
                foreach ([
                    'count_id', 'item_id', 'unit_id', 'unit_conversion_to_base',
                    'snapshot_qty', 'snapshot_last_movement_id', 'created_at',
                ] as $field) {
                    if ((string) ($existingLine[$field] ?? '') !== (string) ($line[$field] ?? '')) {
                        throw new RuntimeException('INVENTORY_COUNT_SYNC_LINE_IDENTITY_CONFLICT');
                    }
                }
            }

            $itemLock = $conn->prepare('SELECT id FROM inventory_count_lines WHERE count_id = ? AND item_id = ? LIMIT 1 FOR UPDATE');
            $itemLock->bind_param('ii', $countId, $itemId);
            $itemLock->execute();
            $existingForItem = $itemLock->get_result()->fetch_assoc();
            $itemLock->close();
            if ($existingForItem && (int) $existingForItem['id'] !== $lineId) {
                throw new RuntimeException('INVENTORY_COUNT_SYNC_LINE_IDENTITY_CONFLICT');
            }

            $this->upsertRow($conn, 'inventory_count_lines', $line);
        }

        return [
            'entity_id' => 'inventory_counts:' . $countId,
            'count_id' => $countId,
            'line_count' => count($payload['inventory_count_lines']),
            'idempotent_replay' => false,
        ];
    }

    private function mirrorInventoryJournalBundle(
        mysqli $conn,
        string $branchUuid,
        array $event,
        array $payload
    ): array {
        (new InventoryAccountingSyncPayloadService())->assertValid($payload, $branchUuid, $event);
        foreach (['acc_head', 'journal_heads', 'journal_entries', 'inventory_movements'] as $table) {
            if (!$this->tableExists($conn, $table)) {
                throw new RuntimeException('INVENTORY_JOURNAL_SYNC_SCHEMA_REQUIRED');
            }
        }

        $head = $payload['journal_head'];
        $journalHeadId = (int) $head['id'];
        foreach ($payload['accounts'] as $account) {
            $this->insertOrAssertAccountIdentity($conn, $account);
        }
        $this->insertOrAssertInventoryJournalHead($conn, $head);
        foreach ($payload['journal_entries'] as $entry) {
            $this->insertOrAssertInventoryJournalEntry($conn, $entry);
        }
        foreach ($payload['movements'] as $movement) {
            $this->linkInventoryJournalMovement($conn, $branchUuid, $journalHeadId, $movement);
        }

        return [
            'entity_id' => 'journal_heads:' . $journalHeadId,
            'journal_head_id' => $journalHeadId,
            'entry_count' => count($payload['journal_entries']),
            'movement_count' => count($payload['movements']),
        ];
    }

    private function insertOrAssertAccountIdentity(mysqli $conn, array $account): void
    {
        $accountId = (int) $account['id'];
        $existing = $this->rowForUpdate($conn, 'acc_head', $accountId);
        if (!$existing) {
            $this->upsertRow($conn, 'acc_head', $account);
            return;
        }

        foreach (['code', 'parent_id', 'tenant', 'branch'] as $field) {
            if (!array_key_exists($field, $account)) {
                continue;
            }
            $incoming = $account[$field];
            $stored = $existing[$field] ?? null;
            if ((string) $incoming !== (string) $stored) {
                throw new RuntimeException('INVENTORY_JOURNAL_SYNC_ACCOUNT_IDENTITY_CONFLICT');
            }
        }
    }

    private function insertOrAssertInventoryJournalHead(mysqli $conn, array $head): void
    {
        $headId = (int) $head['id'];
        $existing = $this->rowForUpdate($conn, 'journal_heads', $headId);
        if (!$existing) {
            $this->upsertRow($conn, 'journal_heads', $head);
            return;
        }

        foreach ([
            'journal_id', 'jdate', 'op_id', 'pro_tybe', 'details', 'op2', 'isdeleted',
            'user', 'tenant', 'branch', 'source_type', 'source_id', 'posting_kind',
            'idempotency_key', 'reversal_of_journal_id',
        ] as $field) {
            if (array_key_exists($field, $head) && (string) $head[$field] !== (string) ($existing[$field] ?? null)) {
                throw new RuntimeException('INVENTORY_JOURNAL_SYNC_HEAD_IDENTITY_CONFLICT');
            }
        }
        if (bccomp((string) ($head['total'] ?? '0'), (string) ($existing['total'] ?? '0'), 6) !== 0) {
            throw new RuntimeException('INVENTORY_JOURNAL_SYNC_HEAD_IDENTITY_CONFLICT');
        }
    }

    private function insertOrAssertInventoryJournalEntry(mysqli $conn, array $entry): void
    {
        $entryId = (int) $entry['id'];
        $existing = $this->rowForUpdate($conn, 'journal_entries', $entryId);
        if (!$existing) {
            $this->upsertRow($conn, 'journal_entries', $entry);
            return;
        }

        foreach (['journal_id', 'account_id', 'tybe', 'op_id', 'op2', 'isdeleted', 'tenant', 'branch'] as $field) {
            if (array_key_exists($field, $entry) && (string) $entry[$field] !== (string) ($existing[$field] ?? null)) {
                throw new RuntimeException('INVENTORY_JOURNAL_SYNC_ENTRY_IDENTITY_CONFLICT');
            }
        }
        foreach (['debit', 'credit'] as $field) {
            if (bccomp((string) ($entry[$field] ?? '0'), (string) ($existing[$field] ?? '0'), 6) !== 0) {
                throw new RuntimeException('INVENTORY_JOURNAL_SYNC_ENTRY_IDENTITY_CONFLICT');
            }
        }
    }

    private function linkInventoryJournalMovement(
        mysqli $conn,
        string $branchUuid,
        int $journalHeadId,
        array $movement
    ): void {
        $movementId = (int) $movement['id'];
        $existing = $this->rowForUpdate($conn, 'inventory_movements', $movementId);
        if (!$existing
            || strtolower(trim((string) ($existing['movement_uuid'] ?? ''))) !== strtolower((string) $movement['movement_uuid'])
            || strtolower(trim((string) ($existing['branch_uuid'] ?? ''))) !== strtolower(trim($branchUuid))
            || (int) ($existing['pos_tenant'] ?? 0) !== (int) ($movement['pos_tenant'] ?? 0)
            || (int) ($existing['pos_branch'] ?? 0) !== (int) ($movement['pos_branch'] ?? 0)
            || (string) ($existing['source_type'] ?? '') !== (string) ($movement['source_type'] ?? '')
            || (int) ($existing['source_id'] ?? 0) !== (int) ($movement['source_id'] ?? 0)
        ) {
            throw new RuntimeException('INVENTORY_JOURNAL_SYNC_MOVEMENT_IDENTITY_CONFLICT');
        }
        $storedJournalId = (int) ($existing['accounting_journal_id'] ?? 0);
        if ($storedJournalId > 0 && $storedJournalId !== $journalHeadId) {
            throw new RuntimeException('INVENTORY_JOURNAL_SYNC_MOVEMENT_LINK_CONFLICT');
        }

        $stmt = $conn->prepare(
            'UPDATE inventory_movements SET accounting_journal_id = ? WHERE id = ? AND (accounting_journal_id IS NULL OR accounting_journal_id = 0 OR accounting_journal_id = ?)'
        );
        $stmt->bind_param('iii', $journalHeadId, $movementId, $journalHeadId);
        $stmt->execute();
        $stmt->close();
    }

    private function mirrorCustomerBundle(
        mysqli $conn,
        string $branchUuid,
        array $event,
        array $payload
    ): array {
        (new PosCustomerSyncPayloadService())->assertValid($payload, $branchUuid, $event);
        foreach (['pos_customers', 'pos_customer_phones', 'pos_customer_addresses'] as $table) {
            if (!$this->tableExists($conn, $table)) {
                throw new RuntimeException('CUSTOMER_SYNC_SCHEMA_REQUIRED');
            }
        }

        $customer = $payload['customer'];
        $customerId = (int) $customer['id'];
        $lock = $conn->prepare('SELECT id FROM pos_customers WHERE id = ? LIMIT 1 FOR UPDATE');
        $lock->bind_param('i', $customerId);
        $lock->execute();
        $lock->get_result()->fetch_assoc();
        $lock->close();

        $parentWithoutPrimary = $customer;
        $parentWithoutPrimary['primary_phone_id'] = null;
        $this->upsertRow($conn, 'pos_customers', $parentWithoutPrimary);

        foreach ($payload['phones'] as $phone) {
            $this->assertCustomerPhoneIdentity($conn, $phone);
            $this->upsertRow($conn, 'pos_customer_phones', $phone);
        }
        $this->softDeleteMissingCustomerChildren(
            $conn,
            'pos_customer_phones',
            $customerId,
            array_column($payload['phones'], 'id')
        );

        foreach ($payload['addresses'] as $address) {
            $this->lockCustomerAddressIdentity($conn, $address);
            $this->upsertRow($conn, 'pos_customer_addresses', $address);
        }
        $this->softDeleteMissingCustomerChildren(
            $conn,
            'pos_customer_addresses',
            $customerId,
            array_column($payload['addresses'], 'id')
        );

        $this->upsertRow($conn, 'pos_customers', $customer);

        return [
            'entity_id' => 'pos_customers:' . $customerId,
            'customer_id' => $customerId,
            'phones' => count($payload['phones']),
            'addresses' => count($payload['addresses']),
        ];
    }

    private function assertCustomerPhoneIdentity(mysqli $conn, array $phone): void
    {
        $phoneId = (int) $phone['id'];
        $normalized = (string) $phone['phone_normalized'];
        $stmt = $conn->prepare(
            'SELECT id, phone_normalized FROM pos_customer_phones'
            . ' WHERE id = ? OR phone_normalized = ? FOR UPDATE'
        );
        $stmt->bind_param('is', $phoneId, $normalized);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($existing = $result->fetch_assoc()) {
            if ((int) $existing['id'] !== $phoneId
                || !hash_equals((string) $existing['phone_normalized'], $normalized)
            ) {
                $stmt->close();
                throw new RuntimeException('CUSTOMER_SYNC_PHONE_IDENTITY_CONFLICT');
            }
        }
        $stmt->close();
    }

    private function lockCustomerAddressIdentity(mysqli $conn, array $address): void
    {
        $addressId = (int) $address['id'];
        $stmt = $conn->prepare('SELECT id FROM pos_customer_addresses WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $addressId);
        $stmt->execute();
        $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    private function softDeleteMissingCustomerChildren(
        mysqli $conn,
        string $table,
        int $customerId,
        array $incomingIds
    ): void {
        $incomingIds = array_values(array_unique(array_filter(array_map('intval', $incomingIds), static fn (int $id): bool => $id > 0)));
        $sql = "UPDATE `{$table}` SET isdeleted = 1, updated_at = CURRENT_TIMESTAMP WHERE customer_id = ?";
        if ($incomingIds !== []) {
            $sql .= ' AND id NOT IN (' . implode(',', $incomingIds) . ')';
        }
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $stmt->close();
    }

    private function mirrorDrawerSessionSnapshot(mysqli $conn, array $payload): ?array
    {
        $session = $payload['drawer_session'] ?? null;
        if (!is_array($session) || (int) ($session['id'] ?? 0) <= 0) {
            throw new RuntimeException('DRAWER_SESSION_PAYLOAD_INVALID');
        }
        foreach (['close_token_hash', 'open_branch_lock', 'open_register_lock', 'open_user_lock', 'preceding_session_id'] as $forbiddenField) {
            if (array_key_exists($forbiddenField, $session)) {
                throw new RuntimeException('DRAWER_SESSION_FIELD_INVALID');
            }
        }
        $revision = (int) ($payload['sync_revision'] ?? 0);
        if ($revision < 1 || $revision !== (int) ($session['sync_revision'] ?? 0)) {
            throw new RuntimeException('DRAWER_SESSION_SYNC_REVISION_INVALID');
        }

        $result = $this->mirrorDrawerSessionRow($conn, $session) ?: [];
        $hostedSession = $this->findDrawerByUuid($conn, trim((string) $session['uuid']), true);
        if (!$hostedSession || (int) ($hostedSession['id'] ?? 0) <= 0) {
            throw new RuntimeException('DRAWER_SESSION_AUDIT_REMAP_FAILED');
        }

        $auditCounts = $this->mirrorDrawerSessionAuditRows(
            $conn,
            $payload,
            (int) $session['id'],
            (int) $hostedSession['id']
        );

        return $result + $auditCounts;
    }

    /** @return array{count_attempts:int,resolutions:int} */
    private function mirrorDrawerSessionAuditRows(
        mysqli $conn,
        array $payload,
        int $sourceSessionId,
        int $hostedSessionId
    ): array {
        $counts = ['count_attempts' => 0, 'resolutions' => 0];

        if (array_key_exists('count_attempts', $payload)) {
            if (!is_array($payload['count_attempts'])) {
                throw new RuntimeException('DRAWER_COUNT_ATTEMPTS_PAYLOAD_INVALID');
            }
            if ($payload['count_attempts'] !== [] && !$this->tableExists($conn, 'drawer_count_attempts')) {
                throw new RuntimeException('DRAWER_COUNT_ATTEMPTS_SCHEMA_REQUIRED');
            }
            foreach ($payload['count_attempts'] as $attempt) {
                if (!is_array($attempt)
                    || (int) ($attempt['id'] ?? 0) <= 0
                    || (int) ($attempt['drawer_session_id'] ?? 0) !== $sourceSessionId
                    || !in_array((string) ($attempt['count_phase'] ?? ''), ['open', 'close'], true)
                    || (int) ($attempt['attempt_number'] ?? 0) <= 0
                ) {
                    throw new RuntimeException('DRAWER_COUNT_ATTEMPT_PAYLOAD_INVALID');
                }
                if (array_diff(array_keys($attempt), $this->tableColumns($conn, 'drawer_count_attempts')) !== []) {
                    throw new RuntimeException('DRAWER_COUNT_ATTEMPT_FIELD_INVALID');
                }

                $phase = (string) $attempt['count_phase'];
                $attemptNumber = (int) $attempt['attempt_number'];
                $lookup = $conn->prepare(
                    'SELECT id FROM drawer_count_attempts'
                    . ' WHERE drawer_session_id = ? AND count_phase = ? AND attempt_number = ?'
                    . ' LIMIT 1 FOR UPDATE'
                );
                $lookup->bind_param('isi', $hostedSessionId, $phase, $attemptNumber);
                $lookup->execute();
                $existing = $lookup->get_result()->fetch_assoc();
                $lookup->close();

                unset($attempt['id']);
                $attempt['drawer_session_id'] = $hostedSessionId;
                if ($existing) {
                    $attempt['id'] = (int) $existing['id'];
                    $this->upsertRow($conn, 'drawer_count_attempts', $attempt);
                } else {
                    $this->upsertRow($conn, 'drawer_count_attempts', $attempt, true);
                }
                $counts['count_attempts']++;
            }
        }

        if (array_key_exists('resolutions', $payload)) {
            if (!is_array($payload['resolutions'])) {
                throw new RuntimeException('DRAWER_RESOLUTIONS_PAYLOAD_INVALID');
            }
            if ($payload['resolutions'] !== [] && !$this->tableExists($conn, 'drawer_session_resolutions')) {
                throw new RuntimeException('DRAWER_RESOLUTIONS_SCHEMA_REQUIRED');
            }
            foreach ($payload['resolutions'] as $resolution) {
                if (!is_array($resolution)) {
                    throw new RuntimeException('DRAWER_RESOLUTION_PAYLOAD_INVALID');
                }
                $varianceType = (string) ($resolution['variance_type'] ?? '');
                $resolvedAt = trim((string) ($resolution['resolved_at'] ?? ''));
                $resolvedBy = (int) ($resolution['resolved_by'] ?? -1);
                if ((int) ($resolution['id'] ?? 0) <= 0
                    || (int) ($resolution['drawer_session_id'] ?? 0) !== $sourceSessionId
                    || !in_array($varianceType, ['opening', 'closing', 'both', 'force_close'], true)
                    || $resolvedAt === ''
                    || $resolvedBy < 0
                ) {
                    throw new RuntimeException('DRAWER_RESOLUTION_PAYLOAD_INVALID');
                }
                if (array_diff(array_keys($resolution), $this->tableColumns($conn, 'drawer_session_resolutions')) !== []) {
                    throw new RuntimeException('DRAWER_RESOLUTION_FIELD_INVALID');
                }

                $lookup = $conn->prepare(
                    'SELECT id FROM drawer_session_resolutions'
                    . ' WHERE drawer_session_id = ? AND resolved_at = ? AND resolved_by = ? AND variance_type = ?'
                    . ' LIMIT 1 FOR UPDATE'
                );
                $lookup->bind_param('isis', $hostedSessionId, $resolvedAt, $resolvedBy, $varianceType);
                $lookup->execute();
                $existing = $lookup->get_result()->fetch_assoc();
                $lookup->close();

                unset($resolution['id']);
                $resolution['drawer_session_id'] = $hostedSessionId;
                if ($existing) {
                    $resolution['id'] = (int) $existing['id'];
                    $this->upsertRow($conn, 'drawer_session_resolutions', $resolution);
                } else {
                    $this->upsertRow($conn, 'drawer_session_resolutions', $resolution, true);
                }
                $counts['resolutions']++;
            }
        }

        return $counts;
    }

    private function mirrorDrawerMovementBundle(mysqli $conn, array $payload): ?array
    {
        $movement = $payload['movement'] ?? null;
        if (!is_array($movement) || (int) ($movement['id'] ?? 0) <= 0) {
            throw new RuntimeException('DRAWER_MOVEMENT_PAYLOAD_INVALID');
        }
        if (!$this->tableExists($conn, 'drawer_movements')) {
            throw new RuntimeException('DRAWER_MOVEMENT_SCHEMA_REQUIRED');
        }

        $movementId = (int) $movement['id'];
        $localDrawerId = (int) ($movement['drawer_session_id'] ?? 0);
        $drawerSession = $payload['drawer_session'] ?? null;
        $drawerUuid = trim((string) ($payload['drawer_session_uuid'] ?? ''));

        if ($localDrawerId > 0) {
            if (!is_array($drawerSession) || (int) ($drawerSession['id'] ?? 0) !== $localDrawerId) {
                throw new RuntimeException('DRAWER_MOVEMENT_SESSION_SCOPE_INVALID');
            }
            $allowedSessionFields = [
                'id',
                'uuid',
                'user_id',
                'tenant',
                'branch',
                'fund_account_id',
                'opened_at',
                'opened_by',
                'opening_cash',
                'business_day',
            ];
            if (array_diff(array_keys($drawerSession), $allowedSessionFields) !== []) {
                throw new RuntimeException('DRAWER_MOVEMENT_SESSION_FIELD_INVALID');
            }
            if (!$this->isUuid($drawerUuid) || !hash_equals($drawerUuid, trim((string) ($drawerSession['uuid'] ?? '')))) {
                throw new RuntimeException('DRAWER_MOVEMENT_SESSION_UUID_INVALID');
            }
            foreach (['tenant', 'branch'] as $scopeField) {
                if ((int) ($movement[$scopeField] ?? 0) !== (int) ($drawerSession[$scopeField] ?? 0)) {
                    throw new RuntimeException('DRAWER_MOVEMENT_SESSION_SCOPE_INVALID');
                }
            }

            // These values are local coordination state, never portable cloud state.
            $drawerSession['close_token_hash'] = null;
            $drawerSession['open_branch_lock'] = null;
            $drawerSession['open_register_lock'] = null;
            $drawerSession['open_user_lock'] = null;
            $drawerSession['preceding_session_id'] = null;

            $this->mirrorDrawerSessionRow($conn, $drawerSession);
            $hostedDrawer = $this->findDrawerByUuid($conn, $drawerUuid, true);
            if (!$hostedDrawer || (int) ($hostedDrawer['id'] ?? 0) <= 0) {
                throw new RuntimeException('DRAWER_MOVEMENT_SESSION_REMAP_FAILED');
            }
            $movement['drawer_session_id'] = (int) $hostedDrawer['id'];
        } else {
            if (is_array($drawerSession) || $drawerUuid !== '') {
                throw new RuntimeException('DRAWER_MOVEMENT_UNASSIGNED_SCOPE_INVALID');
            }
            $movement['drawer_session_id'] = null;
        }

        $this->upsertRow($conn, 'drawer_movements', $movement);

        return ['entity_id' => 'drawer_movements:' . $movementId];
    }

    private function mirrorFinancialRefundBundle(mysqli $conn, array $payload): ?array
    {
        $creditNote = $payload['credit_note'] ?? null;
        if (!is_array($creditNote) || empty($creditNote['id'])) {
            return null;
        }
        if (!SyncBranchIdentity::isUuid(trim((string) ($creditNote['uuid'] ?? '')))) {
            throw new RuntimeException('FINANCIAL_REFUND_UUID_INVALID');
        }

        $creditNoteId = (int) $creditNote['id'];
        $referencedJournalIds = [];
        $creditJournalId = (int) ($creditNote['journal_head_id'] ?? 0);
        if ($creditJournalId > 0) {
            $referencedJournalIds[$creditJournalId] = $creditJournalId;
        }
        foreach ($payload['credit_note_lines'] ?? [] as $line) {
            if (!is_array($line) || empty($line['id']) || (int) ($line['credit_note_id'] ?? 0) !== $creditNoteId) {
                throw new RuntimeException('FINANCIAL_REFUND_LINE_SCOPE_INVALID');
            }
        }
        foreach ($payload['payment_refunds'] ?? [] as $refund) {
            if (!is_array($refund) || empty($refund['id']) || (int) ($refund['credit_note_id'] ?? 0) !== $creditNoteId) {
                throw new RuntimeException('FINANCIAL_REFUND_TENDER_SCOPE_INVALID');
            }
            $journalId = (int) ($refund['journal_head_id'] ?? 0);
            if ($journalId > 0) {
                $referencedJournalIds[$journalId] = $journalId;
            }
        }
        ksort($referencedJournalIds, SORT_NUMERIC);
        $this->assertFinancialRefundJournals(
            array_values($referencedJournalIds),
            $payload['journal_heads'] ?? [],
            $payload['journal_entries'] ?? []
        );

        foreach (['journal_heads', 'journal_entries'] as $collection) {
            foreach ($payload[$collection] ?? [] as $row) {
                if (is_array($row) && !empty($row['id'])) {
                    $this->upsertRow($conn, $collection, $row);
                }
            }
        }

        $this->upsertRow($conn, 'credit_notes', $creditNote);
        foreach (['credit_note_lines', 'payment_refunds'] as $collection) {
            foreach ($payload[$collection] ?? [] as $row) {
                if (is_array($row) && !empty($row['id'])) {
                    $this->upsertRow($conn, $collection, $row);
                }
            }
        }

        return ['entity_id' => 'credit_notes:' . $creditNoteId];
    }

    private function assertFinancialRefundJournals(array $referencedIds, $heads, $entries): void
    {
        if (!is_array($heads) || !is_array($entries)) {
            throw new RuntimeException('FINANCIAL_REFUND_JOURNAL_PAYLOAD_INVALID');
        }

        $headIds = [];
        foreach ($heads as $head) {
            if (!is_array($head) || empty($head['id'])) {
                throw new RuntimeException('FINANCIAL_REFUND_JOURNAL_HEAD_INVALID');
            }
            $headIds[(int) $head['id']] = (int) $head['id'];
        }
        ksort($headIds, SORT_NUMERIC);
        if (array_values($headIds) !== array_values($referencedIds)) {
            throw new RuntimeException('FINANCIAL_REFUND_JOURNAL_SCOPE_INVALID');
        }

        $totals = [];
        foreach ($referencedIds as $journalId) {
            $totals[$journalId] = ['debit' => '0.000000', 'credit' => '0.000000', 'entries' => 0];
        }
        foreach ($entries as $entry) {
            $journalId = is_array($entry) ? (int) ($entry['journal_id'] ?? 0) : 0;
            if (!is_array($entry) || empty($entry['id']) || !isset($totals[$journalId])) {
                throw new RuntimeException('FINANCIAL_REFUND_JOURNAL_ENTRY_SCOPE_INVALID');
            }
            $totals[$journalId]['debit'] = bcadd($totals[$journalId]['debit'], (string) ($entry['debit'] ?? '0'), 6);
            $totals[$journalId]['credit'] = bcadd($totals[$journalId]['credit'], (string) ($entry['credit'] ?? '0'), 6);
            $totals[$journalId]['entries']++;
        }
        foreach ($totals as $total) {
            if ($total['entries'] < 1 || bccomp($total['debit'], $total['credit'], 6) !== 0) {
                throw new RuntimeException('FINANCIAL_REFUND_JOURNAL_UNBALANCED');
            }
        }
    }

    private function mirrorOperationalRow(mysqli $conn, array $payload): ?array
    {
        $table = (string) ($payload['table'] ?? '');
        $row = $payload['row'] ?? null;
        if ($table === 'drawer_sessions' && is_array($row)) {
            return $this->mirrorDrawerSessionRow($conn, $row);
        }
        if ($table === '' || !is_array($row) || empty($row['id'])) {
            return null;
        }

        $domain = (string) ($payload['domain'] ?? '');
        $definition = $domain !== '' ? OperationalSyncDomains::get($domain) : null;
        if (!$definition || !empty($definition['composite'])) {
            throw new RuntimeException('OPERATIONAL_SYNC_DOMAIN_NOT_REGISTERED');
        }
        if (!hash_equals((string) $definition['table'], $table)) {
            throw new RuntimeException('OPERATIONAL_SYNC_DOMAIN_TABLE_MISMATCH');
        }
        $row = $this->sanitizeRow($row, $definition['exclude_columns'] ?? []);

        $this->upsertRow($conn, $table, $row);

        return ['entity_id' => $table . ':' . (int) $row['id']];
    }

    private function mirrorOperationalDelete(mysqli $conn, array $payload): ?array
    {
        $table = (string) ($payload['table'] ?? '');
        $rowId = (int) ($payload['row_id'] ?? 0);
        if ($table === '' || $rowId <= 0 || !$this->tableExists($conn, $table)) {
            return null;
        }

        $stmt = $conn->prepare("DELETE FROM `{$table}` WHERE id = ?");
        $stmt->bind_param('i', $rowId);
        $stmt->execute();
        $stmt->close();

        return ['entity_id' => $table . ':' . $rowId, 'deleted' => true];
    }

    private function mirrorRecipeBundle(mysqli $conn, array $payload): ?array
    {
        $header = $payload['header'] ?? null;
        if (!is_array($header) || empty($header['id'])) {
            return null;
        }

        $recipeId = (int) $header['id'];
        $this->upsertRow($conn, 'recipe_headers', $header);

        if ($this->tableExists($conn, 'recipe_lines')) {
            $conn->query('DELETE FROM recipe_lines WHERE recipe_id = ' . $recipeId);
            foreach ($payload['lines'] ?? [] as $line) {
                if (!is_array($line) || empty($line['id'])) {
                    continue;
                }
                $this->upsertRow($conn, 'recipe_lines', $line);
            }
        }

        if ($this->tableExists($conn, 'recipe_variant_lines')) {
            $conn->query('DELETE FROM recipe_variant_lines WHERE recipe_id = ' . $recipeId);
            foreach ($payload['variant_lines'] ?? [] as $line) {
                if (!is_array($line) || empty($line['id'])) {
                    continue;
                }
                $this->upsertRow($conn, 'recipe_variant_lines', $line);
            }
        }

        if ($this->tableExists($conn, 'recipe_cost_snapshots')) {
            foreach ($payload['cost_snapshots'] ?? [] as $snapshot) {
                if (!is_array($snapshot) || empty($snapshot['id'])) {
                    continue;
                }
                $this->upsertRow($conn, 'recipe_cost_snapshots', $snapshot);
            }
        }

        return ['entity_id' => 'recipe_headers:' . $recipeId];
    }

    private function mirrorShopSettings(mysqli $conn, array $payload): ?array
    {
        $settings = $payload['settings'] ?? null;
        if (!is_array($settings)) {
            return null;
        }

        $settings['id'] = 1;
        $this->upsertRow($conn, 'settings', $settings);

        return ['entity_id' => 'settings:1'];
    }

    private function mirrorModifierGroupBundle(mysqli $conn, array $payload): ?array
    {
        $group = $payload['group'] ?? null;
        if (!is_array($group) || empty($group['id'])) {
            return null;
        }

        $groupId = (int) $group['id'];
        $this->upsertRow($conn, 'modifier_groups', $group);

        if ($this->tableExists($conn, 'modifier_options')) {
            foreach ($payload['options'] ?? [] as $option) {
                if (!is_array($option) || empty($option['id'])) {
                    continue;
                }
                $this->upsertRow($conn, 'modifier_options', $option);
            }
        }

        if ($this->tableExists($conn, 'item_modifier_groups')) {
            foreach ($payload['item_links'] ?? [] as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $this->upsertJunctionRow($conn, 'item_modifier_groups', $link, ['item_id', 'group_id']);
            }
        }

        return ['entity_id' => 'modifier_groups:' . $groupId];
    }

    private function mirrorMoovaShopLink(mysqli $conn, array $payload): ?array
    {
        $link = $payload['link'] ?? null;
        if (!is_array($link) || empty($link['id'])) {
            return null;
        }

        // Device-token hashes are credentials, not recoverable business data.
        // An empty value keeps link identity visible while forcing secure
        // operator re-pairing after a branch recovery.
        $link['moova_device_token_hash'] = '';

        $this->upsertRow($conn, 'moova_pos_shop_links', $link);

        return ['entity_id' => 'moova_pos_shop_links:' . (int) $link['id']];
    }

    private function mirrorShiftClose(mysqli $conn, string $branchUuid, array $payload): ?array
    {
        $shift = $payload['shift'] ?? null;
        if (!is_array($shift) || !$this->tableExists($conn, 'drawer_session_close_summaries')) {
            return null;
        }

        $schemaVersion = (int) ($payload['schema_version'] ?? 1);
        $legacy = is_array($shift['legacy'] ?? null) ? $shift['legacy'] : [];
        $drawerUuid = trim((string) ($payload['drawer_session_uuid'] ?? $shift['drawer_session_uuid'] ?? ''));
        $recoveredLegacyDrawer = false;
        if ($schemaVersion >= 2) {
            $drawerSessionId = $this->upsertClosedDrawerSession($conn, $drawerUuid, $payload, $shift);
        } else {
            $drawerSessionId = (int) ($shift['local_drawer_session_id'] ?? $legacy['drawer_session_id'] ?? 0);
            if ($drawerUuid !== '') {
                $found = $this->findDrawerByUuid($conn, $drawerUuid, true);
                if ($found) {
                    $drawerSessionId = (int) $found['id'];
                }
            }
            if ($drawerSessionId > 0 && !$this->drawerSessionExists($conn, $drawerSessionId)) {
                $drawerSessionId = 0;
            }
            if ($drawerSessionId < 1) {
                $drawerSessionId = $this->recoverLegacyShiftDrawerSession($conn, $branchUuid, $payload, $shift, $legacy);
                $recoveredLegacyDrawer = $drawerSessionId > 0;
            }
            if ($drawerSessionId < 1) {
                throw new RuntimeException('V1_SHIFT_CLOSE_DRAWER_RECOVERY_FAILED');
            }
        }

        $summary = $payload['close_summary'] ?? null;
        if (!is_array($summary)) {
            // v1 restore compatibility: convert its embedded legacy close row
            // into the canonical summary after resolving or recovering a drawer.
            $summary = [
                'id' => (int) ($shift['close_summary_id'] ?? $shift['local_closed_order_id'] ?? $legacy['id'] ?? 0),
                'uuid' => (string) ($payload['close_uuid'] ?? $shift['close_uuid'] ?? ''),
                'drawer_session_id' => $drawerSessionId,
                'shift_number' => (string) ($shift['shift_number'] ?? $legacy['shift'] ?? ''),
                'total_orders' => (int) ($legacy['total_orders'] ?? 0),
                'total_sales' => $shift['total_sales'] ?? $legacy['total_sales'] ?? 0,
                'cash_sales' => $shift['total_cash'] ?? $legacy['total_cash'] ?? $legacy['cash'] ?? 0,
                'non_cash_sales' => $shift['total_card'] ?? $legacy['total_visa'] ?? 0,
                'discount_total' => $legacy['total_discount'] ?? 0,
                'return_total' => $legacy['total_returns'] ?? 0,
                'expense_total' => $legacy['expenses'] ?? 0,
                'expense_notes' => $legacy['exp_notes'] ?? null,
                'expected_non_cash' => $legacy['total_visa'] ?? null,
                'counted_non_cash' => $legacy['actual_visa'] ?? null,
                'non_cash_difference' => isset($legacy['actual_visa'], $legacy['total_visa'])
                    ? (float) $legacy['actual_visa'] - (float) $legacy['total_visa']
                    : null,
                'close_path' => 'sync_v1_restore',
                'report_snapshot_json' => $legacy['json_details'] ?? null,
                'payment_summary_json' => null,
                'created_at' => $legacy['created_at'] ?? null,
            ];
        }

        $summary['drawer_session_id'] = $drawerSessionId;
        if (empty($summary['uuid'])) {
            $summary['uuid'] = (string) ($payload['close_uuid'] ?? $shift['close_uuid'] ?? '');
        }
        if (trim((string) $summary['uuid']) === '') {
            $legacyIdentity = (int) ($shift['local_closed_order_id'] ?? $legacy['id'] ?? 0);
            $summary['uuid'] = PosOrderSnapshotBuilder::deterministicUuid(
                $branchUuid,
                $legacyIdentity > 0
                    ? 'closed_orders:' . $legacyIdentity
                    : 'drawer_sessions:' . $drawerSessionId . ':close'
            );
        }
        if ($schemaVersion < 2 && empty($summary['id'])) {
            // A source id is not required after legacy recovery; UUID and the
            // one-to-one drawer relation are the portable identities.
            unset($summary['id']);
        }
        if (empty($summary['created_at'])) {
            unset($summary['created_at']);
        }
        $this->assertCloseSummaryIdentity($conn, (string) $summary['uuid'], $drawerSessionId);
        if ($schemaVersion >= 2) {
            // V2 is UUID-linked. A source auto-increment ID is not portable and
            // may collide with an unrelated local close during restore.
            unset($summary['id']);
            $this->upsertRow($conn, 'drawer_session_close_summaries', $summary, true);
        } elseif (!empty($summary['id'])) {
            $this->upsertRow($conn, 'drawer_session_close_summaries', $summary);
        } else {
            $this->upsertRow($conn, 'drawer_session_close_summaries', $summary, true);
        }

        return [
            'entity_id' => 'drawer_session_close_summaries:' . $drawerSessionId,
            'recovered_legacy_shift_close' => $recoveredLegacyDrawer,
        ];
    }

    private function upsertClosedDrawerSession(
        mysqli $conn,
        string $drawerUuid,
        array $payload,
        array $shift
    ): int {
        if (!$this->tableExists($conn, 'drawer_sessions')) {
            throw new RuntimeException('SCHEMA_MIGRATIONS_PENDING');
        }
        if (!$this->isUuid($drawerUuid)) {
            throw new RuntimeException('SHIFT_CLOSE_DRAWER_UUID_INVALID');
        }

        $snapshot = is_array($payload['drawer_session'] ?? null) ? $payload['drawer_session'] : [];
        $closedAt = $this->nullableDateTime($snapshot['closed_at'] ?? $shift['closed_at'] ?? null);
        if ($closedAt === null) {
            throw new RuntimeException('SHIFT_CLOSE_CLOSED_AT_REQUIRED');
        }

        $status = (string) ($snapshot['status'] ?? $shift['status'] ?? 'closed');
        if (!in_array($status, ['closed', 'forced_closed'], true)) {
            throw new RuntimeException('SHIFT_CLOSE_STATUS_NOT_TERMINAL');
        }

        $existing = $this->findDrawerByUuid($conn, $drawerUuid, true);
        $this->assertDrawerScopeCompatible($existing, $snapshot, $shift);
        if (($existing['status'] ?? '') === 'forced_closed') {
            $status = 'forced_closed';
        }

        $userId = max(0, (int) ($snapshot['user_id'] ?? $shift['cashier_user_id'] ?? 0));
        $openedAt = $this->nullableDateTime($snapshot['opened_at'] ?? $shift['opened_at'] ?? null) ?: $closedAt;
        if (strtotime($openedAt) > strtotime($closedAt)) {
            throw new RuntimeException('SHIFT_CLOSE_TIME_RANGE_INVALID');
        }

        $varianceStatus = (string) ($snapshot['variance_status'] ?? $shift['variance_status'] ?? 'none');
        if (($existing['variance_status'] ?? '') === 'resolved') {
            // Variance resolution is a later manager action than the close.
            // A delayed/replayed shift-close bundle must not reopen that work.
            $varianceStatus = 'resolved';
        }

        $row = array_merge($snapshot, [
            'uuid' => $drawerUuid,
            'user_id' => $userId,
            'tenant' => max(0, (int) ($snapshot['tenant'] ?? $shift['tenant'] ?? 0)),
            'branch' => max(0, (int) ($snapshot['branch'] ?? $shift['branch'] ?? 0)),
            'opened_at' => $openedAt,
            'business_day' => (string) ($snapshot['business_day'] ?? substr($openedAt, 0, 10)),
            'opened_by' => max(0, (int) ($snapshot['opened_by'] ?? $userId)),
            'opening_cash' => $snapshot['opening_cash'] ?? 0,
            'closed_at' => $closedAt,
            'closed_by' => max(0, (int) ($snapshot['closed_by'] ?? $userId)),
            'expected_cash' => $snapshot['expected_cash'] ?? $this->expectedCashFromShift($shift),
            'counted_cash' => $snapshot['counted_cash'] ?? $shift['actual_cash'] ?? null,
            'difference' => $snapshot['difference'] ?? $shift['cash_deficit'] ?? null,
            'status' => $status,
            'variance_status' => $varianceStatus,
            'variance_type' => (string) ($snapshot['variance_type'] ?? $shift['variance_type'] ?? 'none'),
            'open_branch_lock' => null,
            'open_register_lock' => null,
            'open_user_lock' => null,
            'close_token_hash' => null,
        ]);
        unset($row['id']);
        $this->upsertRow($conn, 'drawer_sessions', $row, true);

        $saved = $this->findDrawerByUuid($conn, $drawerUuid, true);
        if (!$saved) {
            throw new RuntimeException('SHIFT_CLOSE_DRAWER_UPSERT_FAILED');
        }

        return (int) $saved['id'];
    }

    private function mirrorDrawerSessionRow(mysqli $conn, array $row): ?array
    {
        $uuid = trim((string) ($row['uuid'] ?? ''));
        if (!$this->isUuid($uuid)) {
            throw new RuntimeException('DRAWER_SESSION_UUID_INVALID');
        }

        $existing = $this->findDrawerByUuid($conn, $uuid, true);
        $this->assertDrawerScopeCompatible($existing, $row, []);
        $incomingStatus = (string) ($row['status'] ?? 'open');
        if ($existing && $this->isTerminalDrawerStatus((string) ($existing['status'] ?? '')) && !$this->isTerminalDrawerStatus($incomingStatus)) {
            return ['entity_id' => 'drawer_sessions:' . (int) $existing['id'], 'stale_open_ignored' => true];
        }
        if ($existing && ($existing['status'] ?? '') === 'forced_closed' && $incomingStatus === 'closed') {
            $row['status'] = 'forced_closed';
        }
        if ($this->isTerminalDrawerStatus((string) ($row['status'] ?? ''))) {
            $row['open_branch_lock'] = null;
            $row['open_register_lock'] = null;
            $row['open_user_lock'] = null;
            $row['close_token_hash'] = null;
        }

        unset($row['id']);
        $this->upsertRow($conn, 'drawer_sessions', $row, true);
        $saved = $this->findDrawerByUuid($conn, $uuid, false);

        return $saved ? ['entity_id' => 'drawer_sessions:' . (int) $saved['id']] : null;
    }

    private function assertCloseSummaryIdentity(mysqli $conn, string $summaryUuid, int $drawerSessionId): void
    {
        $stmt = $conn->prepare(
            'SELECT uuid, drawer_session_id FROM drawer_session_close_summaries '
            . 'WHERE uuid = ? OR drawer_session_id = ? FOR UPDATE'
        );
        $stmt->bind_param('si', $summaryUuid, $drawerSessionId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if ((string) $row['uuid'] !== $summaryUuid || (int) $row['drawer_session_id'] !== $drawerSessionId) {
                $stmt->close();
                throw new RuntimeException('DRAWER_CLOSE_SUMMARY_IDENTITY_CONFLICT');
            }
        }
        $stmt->close();
    }

    private function assertDrawerScopeCompatible(?array $existing, array $snapshot, array $shift): void
    {
        if (!$existing) {
            return;
        }
        foreach (['tenant', 'branch'] as $field) {
            $incoming = (int) ($snapshot[$field] ?? $shift[$field] ?? 0);
            $current = (int) ($existing[$field] ?? 0);
            if ($incoming > 0 && $current > 0 && $incoming !== $current) {
                throw new RuntimeException('DRAWER_SESSION_UUID_SCOPE_CONFLICT');
            }
        }
    }

    private function findDrawerByUuid(mysqli $conn, string $uuid, bool $forUpdate): ?array
    {
        if (!$this->tableExists($conn, 'drawer_sessions')) {
            return null;
        }
        $sql = 'SELECT * FROM drawer_sessions WHERE uuid = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function expectedCashFromShift(array $shift)
    {
        if (!isset($shift['actual_cash'], $shift['cash_deficit'])) {
            return null;
        }

        return (float) $shift['actual_cash'] - (float) $shift['cash_deficit'];
    }

    private function nullableDateTime($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private function isTerminalDrawerStatus(string $status): bool
    {
        return in_array($status, ['closed', 'forced_closed'], true);
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function recoverLegacyShiftDrawerSession(
        mysqli $conn,
        string $branchUuid,
        array $payload,
        array $shift,
        array $legacy
    ): int {
        if (!$this->tableExists($conn, 'drawer_sessions')) {
            return 0;
        }

        $legacyCloseId = (int) (
            $shift['local_closed_order_id']
            ?? $legacy['id']
            ?? $payload['local_closed_order_id']
            ?? 0
        );
        $identity = $legacyCloseId > 0
            ? 'closed_orders:' . $legacyCloseId
            : 'shift_close:' . hash('sha256', json_encode([$shift, $legacy], JSON_UNESCAPED_SLASHES) ?: 'unknown');
        $drawerUuid = PosOrderSnapshotBuilder::deterministicUuid($branchUuid, 'restored_drawer:' . $identity);

        $existing = $conn->prepare('SELECT id FROM drawer_sessions WHERE uuid = ? LIMIT 1');
        $existing->bind_param('s', $drawerUuid);
        $existing->execute();
        $found = $existing->get_result()->fetch_assoc();
        $existing->close();
        if ($found) {
            return (int) $found['id'];
        }

        $closedAt = $this->normalizedDateTime(
            $shift['closed_at'] ?? $legacy['endtime'] ?? $legacy['crtime'] ?? $legacy['date'] ?? null,
            date('Y-m-d H:i:s')
        );
        $openedAt = $this->normalizedDateTime(
            $shift['opened_at'] ?? $legacy['strttime'] ?? null,
            $closedAt
        );
        if (strtotime($openedAt) > strtotime($closedAt)) {
            $openedAt = $closedAt;
        }
        $counted = (float) ($shift['actual_cash'] ?? $legacy['actual_cash'] ?? $legacy['cash'] ?? 0);
        $difference = (float) ($shift['cash_deficit'] ?? $legacy['deficit'] ?? 0);
        $expected = $counted - $difference;
        $userId = max(0, (int) ($shift['cashier_user_id'] ?? $legacy['user_id'] ?? $legacy['user'] ?? 0));

        $this->upsertRow($conn, 'drawer_sessions', [
            'uuid' => $drawerUuid,
            'user_id' => $userId,
            'tenant' => max(0, (int) ($shift['tenant'] ?? $legacy['tenant'] ?? 0)),
            'branch' => max(0, (int) ($shift['branch'] ?? $legacy['branch'] ?? 0)),
            'opened_at' => $openedAt,
            'business_day' => substr($openedAt, 0, 10),
            'opened_by' => $userId,
            'opening_cash' => '0.000',
            'closed_at' => $closedAt,
            'closed_by' => $userId,
            'expected_cash' => number_format($expected, 3, '.', ''),
            'counted_cash' => number_format($counted, 3, '.', ''),
            'difference' => number_format($difference, 3, '.', ''),
            'status' => 'closed',
            'variance_status' => abs($difference) > 0.0001 ? 'unresolved' : 'none',
            'variance_type' => abs($difference) > 0.0001 ? 'closing' : 'none',
            'notes' => 'Recovered from unlinked v1 shift-close backup',
        ], true);

        $lookup = $conn->prepare('SELECT id FROM drawer_sessions WHERE uuid = ? LIMIT 1');
        $lookup->bind_param('s', $drawerUuid);
        $lookup->execute();
        $row = $lookup->get_result()->fetch_assoc();
        $lookup->close();

        return (int) ($row['id'] ?? 0);
    }

    private function drawerSessionExists(mysqli $conn, int $sessionId): bool
    {
        if ($sessionId < 1 || !$this->tableExists($conn, 'drawer_sessions')) {
            return false;
        }
        $stmt = $conn->prepare('SELECT 1 FROM drawer_sessions WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $found = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();

        return $found;
    }

    private function normalizedDateTime($value, string $fallback): string
    {
        $timestamp = strtotime(trim((string) $value));
        if ($timestamp === false) {
            return $fallback;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function upsertJunctionRow(mysqli $conn, string $table, array $row, array $keyColumns): void
    {
        if (!$this->tableExists($conn, $table)) {
            return;
        }

        foreach ($keyColumns as $column) {
            if (empty($row[$column])) {
                return;
            }
        }

        $columns = $this->tableColumns($conn, $table);
        $fields = [];
        $values = [];
        foreach ($row as $column => $value) {
            if (!in_array($column, $columns, true)) {
                continue;
            }
            $fields[] = '`' . $column . '`';
            $values[] = $value;
        }

        if ($fields === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $updates = [];
        foreach ($fields as $field) {
            $updates[] = $field . ' = VALUES(' . $field . ')';
        }

        $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')';
        if ($updates !== []) {
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
        }

        $stmt = $conn->prepare($sql);
        $types = str_repeat('s', count($values));
        $this->bindParams($stmt, $types, $values);
        $stmt->execute();
        $stmt->close();
    }

    private function rowForUpdate(mysqli $conn, string $table, int $id): ?array
    {
        if (!$this->tableExists($conn, $table) || $id < 1) {
            return null;
        }
        $safeTable = str_replace('`', '``', $table);
        $stmt = $conn->prepare("SELECT * FROM `{$safeTable}` WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function upsertRow(mysqli $conn, string $table, array $row, bool $allowAutoId = false): void
    {
        if (!$this->tableExists($conn, $table) || $row === [] || (!$allowAutoId && empty($row['id']))) {
            return;
        }

        $columns = $this->tableColumns($conn, $table);
        $fields = [];
        $values = [];
        foreach ($row as $column => $value) {
            if (($allowAutoId && $column === 'id') || !in_array($column, $columns, true)) {
                continue;
            }
            $fields[] = '`' . $column . '`';
            $values[] = $value;
        }

        if ($fields === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $updates = [];
        foreach ($fields as $field) {
            if (!$allowAutoId && $field === '`id`') {
                continue;
            }
            $updates[] = $field . ' = VALUES(' . $field . ')';
        }

        $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')';
        if ($updates !== []) {
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
        }

        $stmt = $conn->prepare($sql);
        $types = str_repeat('s', count($values));
        $this->bindParams($stmt, $types, $values);
        $stmt->execute();
        $stmt->close();
    }

    private function sanitizeRow(array $row, array $excludeColumns): array
    {
        foreach ($excludeColumns as $column) {
            unset($row[$column]);
        }

        return $row;
    }

    private function payload(array $event): array
    {
        $payload = $event['payload'] ?? [];
        return is_array($payload) ? $payload : [];
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
        return $result && $result->num_rows > 0;
    }

    private function tableColumns(mysqli $conn, string $table): array
    {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }

        $columns = [];
        $result = $conn->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
        while ($row = $result->fetch_assoc()) {
            $columns[] = (string) $row['Field'];
        }
        $this->columnCache[$table] = $columns;

        return $columns;
    }

    private function bindParams(mysqli_stmt $stmt, string $types, array $values): void
    {
        $refs = [];
        foreach ($values as $key => &$value) {
            $refs[$key] = &$value;
        }
        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}
