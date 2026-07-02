<?php

require_once __DIR__ . '/KdsStationService.php';
require_once __DIR__ . '/KdsRoutingService.php';
require_once __DIR__ . '/OrderPrintPayloadService.php';
require_once __DIR__ . '/OrderEventService.php';

/**
 * Persists and drives kitchen tickets for the KDS.
 *
 * A ticket is one kitchen dispatch unit for an (order, station) pair.
 * Post-completion adds spawn a linked supplement ticket with only the delta
 * lines so cooks never see already-finished items again.
 */
class KdsTicketService
{
    private const ACTIVE_STATUSES = ['new', 'in_progress'];

    /** Reconcile only heals recent missed syncs — never backfills years of open POS history. */
    private const RECONCILE_LOOKBACK_HOURS = 24;

    private KdsStationService $stations;
    private KdsRoutingService $routing;
    private OrderPrintPayloadService $printPayload;
    private OrderEventService $orderEvents;
    private array $scopeCache = [];
    private ?bool $kitchenStatusColumn = null;

    public function __construct(
        ?KdsStationService $stations = null,
        ?KdsRoutingService $routing = null,
        ?OrderPrintPayloadService $printPayload = null,
        ?OrderEventService $orderEvents = null
    ) {
        $this->stations = $stations ?: new KdsStationService();
        $this->routing = $routing ?: new KdsRoutingService($this->stations);
        $this->printPayload = $printPayload ?: new OrderPrintPayloadService();
        $this->orderEvents = $orderEvents ?: new OrderEventService();
    }

    public function tablesExist(mysqli $conn): bool
    {
        $result = $conn->query("SHOW TABLES LIKE 'kds_tickets'");

        return $result && $result->num_rows > 0;
    }

    /**
     * Idempotently rebuilds the per-station tickets for an order. Safe to
     * call repeatedly; only writes a change-log row when content moves.
     */
    public function syncForOrder(mysqli $conn, int $orderId, string $eventType = 'updated', int $actorUserId = 0): void
    {
        if ($orderId < 1 || !$this->tablesExist($conn)) {
            return;
        }

        $order = $this->fetchOrderHeader($conn, $orderId);
        if (!$order || (int) ($order['isdeleted'] ?? 0) === 1) {
            $this->cancelAllTicketsForOrder($conn, $orderId);
            $this->recomputeOrderKitchenStatus($conn, $orderId);
            return;
        }

        $orderStatus = strtolower((string) ($order['order_status'] ?? 'active'));
        if ($orderStatus === 'cancelled') {
            $this->cancelAllTicketsForOrder($conn, $orderId);
            $this->recomputeOrderKitchenStatus($conn, $orderId);
            return;
        }

        try {
            $payload = $this->printPayload->buildKotPayloadByOrderId($conn, $orderId);
        } catch (Throwable $exception) {
            error_log('KdsTicketService payload skipped for order ' . $orderId . ': ' . $exception->getMessage());
            return;
        }

        $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        $paymentStatus = strtolower((string) ($payload['order']['payment_status'] ?? ($order['payment_status'] ?? 'unpaid')));
        $context = [
            'table_id' => $this->nullableInt($order['table_id'] ?? null),
            'table_name' => $this->nullableString($payload['table']['name'] ?? ($order['table_name'] ?? null)),
            'order_label' => $this->nullableString($payload['order']['pro_id'] ?? ($order['pro_id'] ?? (string) $orderId)),
            'order_type' => $this->nullableString($payload['order']['order_type'] ?? ($order['order_type'] ?? null)),
        ];

        $routed = $this->routing->routeLines($conn, $lines);
        $stationsWithLines = array_keys($routed);
        $existingStations = $this->stationIdsWithTicket($conn, $orderId);

        foreach ($routed as $stationId => $group) {
            $this->upsertTicket($conn, $orderId, (int) $stationId, $group['lines'], $context, $paymentStatus, $actorUserId, $eventType);
        }

        foreach ($existingStations as $stationId) {
            if (!in_array($stationId, $stationsWithLines, true)) {
                $this->cancelTicket($conn, $orderId, $stationId);
            }
        }

        $this->recomputeOrderKitchenStatus($conn, $orderId);
    }

    private function upsertTicket(
        mysqli $conn,
        int $orderId,
        int $stationId,
        array $lines,
        array $context,
        string $paymentStatus,
        int $actorUserId,
        string $eventType = 'updated'
    ): void {
        [$tenant, $branch] = $this->scope();
        $itemCount = count($lines);

        $active = $this->fetchActiveTicket($conn, $orderId, $stationId);
        if ($active) {
            $ticketId = (int) $active['id'];
            $meaningful = !empty($active['parent_ticket_id'])
                ? $this->syncPostCompletionEdit($conn, $orderId, $stationId, $ticketId, $lines)
                : $this->reconcileLines($conn, $ticketId, $lines);

            if ($meaningful) {
                $this->recomputeTicketStatusFromLines($conn, $ticketId);
                $this->touchTicketMeta($conn, $ticketId, $itemCount, $context);
                $this->recordChange($conn, $stationId, $ticketId, $orderId, 'upsert');
            }

            if ($paymentStatus === 'paid') {
                $this->autoCompleteOnPaidIfConfigured($conn, $stationId, $ticketId, $orderId, $actorUserId);
            }

            return;
        }

        $root = $this->fetchRootTicket($conn, $orderId, $stationId);
        if (!$root) {
            if ($itemCount === 0) {
                return;
            }
            $this->createRootTicket($conn, $orderId, $stationId, $lines, $context, $tenant, $branch);

            return;
        }

        if ($eventType === 'reconcile' && in_array((string) $root['status'], ['completed', 'cancelled'], true)) {
            return;
        }

        if (in_array((string) $root['status'], ['completed', 'recalled'], true)) {
            $delta = $this->computePostCompletionDelta($conn, $orderId, $stationId, $lines);
            $this->applySilentLedgerUpdates($conn, $orderId, $stationId, $lines, $delta);

            if ($delta['silent_only'] || empty($delta['delta_lines'])) {
                return;
            }

            $supplementId = $this->createSupplementTicket(
                $conn,
                $orderId,
                $stationId,
                (int) $root['id'],
                count($delta['delta_lines']),
                $context,
                $tenant,
                $branch
            );
            foreach ($delta['delta_lines'] as $line) {
                $this->insertLine($conn, $supplementId, $line, true);
            }
            $this->recordChange($conn, $stationId, $supplementId, $orderId, 'upsert');

            return;
        }

        // Root still open but was not returned by fetchActiveTicket (edge).
        $ticketId = (int) $root['id'];
        $meaningful = $this->reconcileLines($conn, $ticketId, $lines);
        if ($meaningful) {
            $this->recomputeTicketStatusFromLines($conn, $ticketId);
            $this->touchTicketMeta($conn, $ticketId, $itemCount, $context);
            $this->recordChange($conn, $stationId, $ticketId, $orderId, 'upsert');
        }

        if ($paymentStatus === 'paid') {
            $this->autoCompleteOnPaidIfConfigured($conn, $stationId, $ticketId, $orderId, $actorUserId);
        }
    }

    private function createRootTicket(
        mysqli $conn,
        int $orderId,
        int $stationId,
        array $lines,
        array $context,
        int $tenant,
        int $branch
    ): void {
        $uuid = $this->uuid();
        $tableId = $context['table_id'];
        $tableName = $context['table_name'];
        $orderLabel = $context['order_label'];
        $orderType = $context['order_type'];
        $itemCount = count($lines);
        $stmt = $conn->prepare("
            INSERT INTO kds_tickets
                (uuid, order_id, station_id, table_id, table_name, order_label, order_type,
                 status, item_count, revision, placed_at, tenant, branch)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'new', ?, 1, NOW(), ?, ?)
        ");
        $stmt->bind_param(
            'siiisssiii',
            $uuid,
            $orderId,
            $stationId,
            $tableId,
            $tableName,
            $orderLabel,
            $orderType,
            $itemCount,
            $tenant,
            $branch
        );
        $stmt->execute();
        $ticketId = (int) $stmt->insert_id;
        $stmt->close();

        foreach ($lines as $line) {
            $this->insertLine($conn, $ticketId, $line, false);
        }
        $this->recordChange($conn, $stationId, $ticketId, $orderId, 'upsert');
    }

    private function createSupplementTicket(
        mysqli $conn,
        int $orderId,
        int $stationId,
        int $parentTicketId,
        int $itemCount,
        array $context,
        int $tenant,
        int $branch
    ): int {
        $uuid = $this->uuid();
        $tableId = $context['table_id'];
        $tableName = $context['table_name'];
        $orderLabel = $context['order_label'];
        $orderType = $context['order_type'];
        $stmt = $conn->prepare("
            INSERT INTO kds_tickets
                (uuid, order_id, station_id, parent_ticket_id, table_id, table_name, order_label, order_type,
                 status, item_count, revision, placed_at, tenant, branch)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'new', ?, 1, NOW(), ?, ?)
        ");
        $stmt->bind_param(
            'siiiisssiii',
            $uuid,
            $orderId,
            $stationId,
            $parentTicketId,
            $tableId,
            $tableName,
            $orderLabel,
            $orderType,
            $itemCount,
            $tenant,
            $branch
        );
        $stmt->execute();
        $ticketId = (int) $stmt->insert_id;
        $stmt->close();

        return $ticketId;
    }

    private function touchTicketMeta(mysqli $conn, int $ticketId, int $itemCount, array $context): void
    {
        $stmt = $conn->prepare("
            UPDATE kds_tickets
            SET item_count = ?, revision = revision + 1,
                table_name = ?, order_label = ?, order_type = ?
            WHERE id = ?
        ");
        $tableName = $context['table_name'];
        $orderLabel = $context['order_label'];
        $orderType = $context['order_type'];
        $stmt->bind_param('isssi', $itemCount, $tableName, $orderLabel, $orderType, $ticketId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Merge further edits into an already-open supplement ticket.
     */
    private function syncPostCompletionEdit(
        mysqli $conn,
        int $orderId,
        int $stationId,
        int $supplementTicketId,
        array $lines
    ): bool {
        $delta = $this->computePostCompletionDelta($conn, $orderId, $stationId, $lines);
        $this->applySilentLedgerUpdates($conn, $orderId, $stationId, $lines, $delta);

        $pruned = $this->pruneSupplementToOrder($conn, $supplementTicketId, $lines);
        $synced = $this->syncSupplementLines($conn, $supplementTicketId, $delta['delta_lines']);

        if ($synced || $pruned) {
            $this->recomputeTicketStatusFromLines($conn, $supplementTicketId);

            return true;
        }

        return false;
    }

    private function pruneSupplementToOrder(mysqli $conn, int $ticketId, array $orderLines): bool
    {
        $seen = [];
        foreach (array_keys($this->aggregateOrderLinesByKey($orderLines)) as $lineKey) {
            $seen[$lineKey] = true;
        }

        $stmt = $conn->prepare("SELECT id, line_key, item_id, notes, modifiers_json FROM kds_ticket_lines WHERE ticket_id = ?");
        $stmt->bind_param('i', $ticketId);
        $stmt->execute();
        $result = $stmt->get_result();
        $changed = false;
        while ($row = $result->fetch_assoc()) {
            $lineKey = (string) ($row['line_key'] ?? '');
            if ($lineKey === '') {
                $lineKey = $this->kitchenLineKeyFromRow($row);
            }
            if (isset($seen[$lineKey])) {
                continue;
            }
            $del = $conn->prepare("DELETE FROM kds_ticket_lines WHERE id = ?");
            $lineId = (int) $row['id'];
            $del->bind_param('i', $lineId);
            $del->execute();
            $del->close();
            $changed = true;
        }
        $stmt->close();

        if ($changed) {
            $this->recomputeTicketStatusFromLines($conn, $ticketId);
        }

        return $changed;
    }

    /**
     * @param array<int, array<string, mixed>> $deltaLines
     */
    private function syncSupplementLines(mysqli $conn, int $ticketId, array $deltaLines): bool
    {
        if (!$deltaLines) {
            return false;
        }

        $changed = false;
        foreach ($deltaLines as $line) {
            $lineKey = $this->kitchenLineKey($line);
            if ($lineKey === '') {
                continue;
            }
            $stmt = $conn->prepare("SELECT id FROM kds_ticket_lines WHERE ticket_id = ? AND line_key = ? LIMIT 1");
            $stmt->bind_param('is', $ticketId, $lineKey);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $normalized = $this->normalizeLine($line);
                $this->updateLineSpec($conn, (int) $row['id'], $line, $normalized, 'new', true);
            } else {
                $this->insertLine($conn, $ticketId, $line, true);
            }
            $changed = true;
        }

        return $changed;
    }

    /**
     * @return array{silent_only: bool, delta_lines: array<int, array<string, mixed>>, seen: array<string, bool>}
     */
    private function computePostCompletionDelta(mysqli $conn, int $orderId, int $stationId, array $orderLines): array
    {
        $fulfilled = $this->fulfilledKitchenQtyByLineKey($conn, $orderId, $stationId);
        $ledger = $this->rootLedgerLinesByLineKey($conn, $orderId, $stationId);
        $orderByKey = $this->aggregateOrderLinesByKey($orderLines);
        $deltaLines = [];
        $silentOnly = true;
        $seen = [];

        foreach ($orderByKey as $lineKey => $line) {
            $seen[$lineKey] = true;
            $normalized = $this->normalizeLine($line);
            $orderQty = $normalized['qty_num'];
            $doneQty = (float) ($fulfilled[$lineKey] ?? 0);

            if ($doneQty <= 0) {
                $deltaLines[] = $line;
                $silentOnly = false;
                continue;
            }

            if ($orderQty > $doneQty + 0.0005) {
                $deltaLine = $line;
                $deltaLine['qty'] = $orderQty - $doneQty;
                $deltaLines[] = $deltaLine;
                $silentOnly = false;
            }
        }

        foreach ($ledger as $lineKey => $row) {
            if (isset($seen[$lineKey])) {
                continue;
            }
            if ((string) ($row['line_status'] ?? '') === 'voided') {
                continue;
            }
            // Removal after kitchen finished: kitchen ledger only, no board card.
        }

        return [
            'silent_only' => $silentOnly,
            'delta_lines' => $deltaLines,
            'seen' => $seen,
        ];
    }

    /**
     * @param array{silent_only: bool, delta_lines: array<int, array<string, mixed>>, seen: array<string, bool>} $delta
     */
    private function applySilentLedgerUpdates(
        mysqli $conn,
        int $orderId,
        int $stationId,
        array $orderLines,
        array $delta
    ): void {
        $ledger = $this->rootLedgerLinesByLineKey($conn, $orderId, $stationId);
        $orderByKey = $this->aggregateOrderLinesByKey($orderLines);
        $seen = $delta['seen'];

        foreach ($orderByKey as $lineKey => $line) {
            if (!isset($ledger[$lineKey])) {
                continue;
            }
            $normalized = $this->normalizeLine($line);
            $prev = $ledger[$lineKey];
            if ((string) ($prev['line_status'] ?? '') !== 'done') {
                continue;
            }
            if ($normalized['qty_num'] < (float) $prev['qty']) {
                $this->updateLineSpec($conn, (int) $prev['id'], $line, $normalized, 'done', true);
            }
        }

        foreach ($ledger as $lineKey => $row) {
            if (isset($seen[$lineKey])) {
                continue;
            }
            if ((string) ($row['line_status'] ?? '') === 'voided') {
                continue;
            }
            $this->setLineStatus($conn, (int) $row['id'], 'voided');
        }
    }

    /** @return array<string, float> */
    private function fulfilledKitchenQtyByLineKey(mysqli $conn, int $orderId, int $stationId): array
    {
        $stmt = $conn->prepare("
            SELECT l.line_key, SUM(l.qty) AS fulfilled_qty
            FROM kds_ticket_lines l
            INNER JOIN kds_tickets t ON t.id = l.ticket_id
            WHERE t.order_id = ? AND t.station_id = ? AND l.line_status = 'done' AND l.line_key <> ''
            GROUP BY l.line_key
        ");
        $stmt->bind_param('ii', $orderId, $stationId);
        $stmt->execute();
        $result = $stmt->get_result();
        $map = [];
        while ($r = $result->fetch_assoc()) {
            $map[(string) $r['line_key']] = (float) $r['fulfilled_qty'];
        }
        $stmt->close();

        return $map;
    }

    /** @return array<string, array<string, mixed>> */
    private function rootLedgerLinesByLineKey(mysqli $conn, int $orderId, int $stationId): array
    {
        $root = $this->fetchRootTicket($conn, $orderId, $stationId);
        if (!$root) {
            return [];
        }

        $stmt = $conn->prepare("
            SELECT id, detail_id, line_key, item_id, qty, notes, modifiers_json, line_status
            FROM kds_ticket_lines
            WHERE ticket_id = ?
        ");
        $ticketId = (int) $root['id'];
        $stmt->bind_param('i', $ticketId);
        $stmt->execute();
        $result = $stmt->get_result();
        $map = [];
        while ($r = $result->fetch_assoc()) {
            $lineKey = (string) ($r['line_key'] ?? '');
            if ($lineKey === '') {
                $lineKey = $this->kitchenLineKeyFromRow($r);
            }
            $map[$lineKey] = $r;
        }
        $stmt->close();

        return $map;
    }

    /**
     * Derive ticket status from line states on an active ticket.
     */
    private function recomputeTicketStatusFromLines(mysqli $conn, int $ticketId): void
    {
        $stmt = $conn->prepare("SELECT line_status, COUNT(*) AS c FROM kds_ticket_lines WHERE ticket_id = ? GROUP BY line_status");
        $stmt->bind_param('i', $ticketId);
        $stmt->execute();
        $result = $stmt->get_result();
        $counts = [];
        while ($r = $result->fetch_assoc()) {
            $counts[(string) $r['line_status']] = (int) $r['c'];
        }
        $stmt->close();

        $actionable = (int) ($counts['new'] ?? 0) + (int) ($counts['cooking'] ?? 0);
        $ticket = $this->fetchTicketById($conn, $ticketId);
        $currentStatus = $ticket ? (string) $ticket['status'] : 'new';
        if ($currentStatus === 'cancelled') {
            return;
        }

        if ($actionable > 0) {
            $anyCooking = (int) ($counts['cooking'] ?? 0) > 0;
            $newStatus = ($anyCooking || $currentStatus === 'in_progress') ? 'in_progress' : 'new';
            if ($currentStatus !== $newStatus) {
                $stmt = $conn->prepare("UPDATE kds_tickets SET status = ? WHERE id = ?");
                $stmt->bind_param('si', $newStatus, $ticketId);
                $stmt->execute();
                $stmt->close();
            }

            return;
        }

        if ($currentStatus !== 'completed') {
            $stmt = $conn->prepare("
                UPDATE kds_tickets
                SET status = 'completed', completed_at = COALESCE(completed_at, NOW()),
                    started_at = COALESCE(started_at, NOW())
                WHERE id = ?
            ");
            $stmt->bind_param('i', $ticketId);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Reconcile ticket lines against the current order payload using the
     * line-level model. Removed lines are marked 'voided' (never silently
     * deleted) so kitchen staff see the cancellation. Done lines keep their
     * 'done' status unless their spec changed in a way that requires a remake.
     *
     * @return bool whether any line was added/removed/changed
     */
    private function reconcileLines(mysqli $conn, int $ticketId, array $lines): bool
    {
        $stmt = $conn->prepare("SELECT id, detail_id, line_key, item_id, qty, name, notes, modifiers_json, line_status FROM kds_ticket_lines WHERE ticket_id = ?");
        $stmt->bind_param('i', $ticketId);
        $stmt->execute();
        $result = $stmt->get_result();
        $existing = [];
        while ($r = $result->fetch_assoc()) {
            $lineKey = (string) ($r['line_key'] ?? '');
            if ($lineKey === '') {
                $lineKey = $this->kitchenLineKeyFromRow($r);
            }
            $existing[$lineKey] = $r;
        }
        $stmt->close();

        $seen = [];
        $changed = false;

        foreach ($this->aggregateOrderLinesByKey($lines) as $lineKey => $line) {
            $seen[$lineKey] = true;
            $normalized = $this->normalizeLine($line);

            if (!isset($existing[$lineKey])) {
                $this->insertLine($conn, $ticketId, $line, true);
                $changed = true;
                continue;
            }

            $prev = $existing[$lineKey];
            $prevStatus = (string) ($prev['line_status'] ?? 'new');

            if ($prevStatus === 'voided') {
                $this->updateLineSpec($conn, (int) $prev['id'], $line, $normalized, 'new', true);
                $changed = true;
                continue;
            }

            if ($prevStatus === 'done') {
                continue;
            }

            $sameQty = $this->quantity($prev['qty']) === $normalized['qty'];
            $sameNotes = (string) ($prev['notes'] ?? '') === $normalized['notes'];
            $sameMods = (string) ($prev['modifiers_json'] ?? '') === $normalized['modifiers_json'];
            $sameDetail = (int) ($prev['detail_id'] ?? 0) === (int) ($line['detail_id'] ?? 0);
            if ($sameQty && $sameNotes && $sameMods) {
                if (!$sameDetail) {
                    $this->refreshLineDetailId($conn, (int) $prev['id'], (int) ($line['detail_id'] ?? 0));
                    $changed = true;
                }
                continue;
            }

            $this->updateLineSpec($conn, (int) $prev['id'], $line, $normalized, $prevStatus, true);
            $changed = true;
        }

        foreach ($existing as $lineKey => $row) {
            if (isset($seen[$lineKey])) {
                continue;
            }
            if ((string) ($row['line_status'] ?? '') === 'voided') {
                continue;
            }
            $this->setLineStatus($conn, (int) $row['id'], 'voided');
            $changed = true;
        }

        return $changed;
    }

    private function updateLineSpec(mysqli $conn, int $lineId, array $line, array $normalized, string $status, bool $isChanged): void
    {
        $changedFlag = $isChanged ? 1 : 0;
        $modsValue = $normalized['modifiers_json'] !== '' ? $normalized['modifiers_json'] : null;
        $detailId = (int) ($line['detail_id'] ?? 0);
        $lineKey = $this->kitchenLineKey($line);
        $itemId = $normalized['item_id'];
        $stmt = $conn->prepare("
            UPDATE kds_ticket_lines
            SET detail_id = ?, line_key = ?, item_id = ?, qty = ?, name = ?, notes = ?, modifiers_json = ?, item_group_id = ?, line_status = ?, is_changed = ?
            WHERE id = ?
        ");
        $stmt->bind_param(
            'isidsssisii',
            $detailId,
            $lineKey,
            $itemId,
            $normalized['qty_num'],
            $normalized['name'],
            $normalized['notes'],
            $modsValue,
            $normalized['item_group_id'],
            $status,
            $changedFlag,
            $lineId
        );
        $stmt->execute();
        $stmt->close();
    }

    private function setLineStatus(mysqli $conn, int $lineId, string $status): void
    {
        $stmt = $conn->prepare("UPDATE kds_ticket_lines SET line_status = ? WHERE id = ?");
        $stmt->bind_param('si', $status, $lineId);
        $stmt->execute();
        $stmt->close();
    }

    private function refreshLineDetailId(mysqli $conn, int $lineId, int $detailId): void
    {
        $stmt = $conn->prepare("UPDATE kds_ticket_lines SET detail_id = ? WHERE id = ?");
        $stmt->bind_param('ii', $detailId, $lineId);
        $stmt->execute();
        $stmt->close();
    }

    private function insertLine(mysqli $conn, int $ticketId, array $line, bool $isChanged): void
    {
        $normalized = $this->normalizeLine($line);
        $changedFlag = $isChanged ? 1 : 0;
        $lineKey = $this->kitchenLineKey($line);
        $stmt = $conn->prepare("
            INSERT INTO kds_ticket_lines
                (ticket_id, detail_id, line_key, item_id, item_group_id, name, qty, notes, modifiers_json, line_status, is_changed)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', ?)
            ON DUPLICATE KEY UPDATE
                detail_id = VALUES(detail_id),
                item_id = VALUES(item_id),
                qty = VALUES(qty), name = VALUES(name), notes = VALUES(notes),
                modifiers_json = VALUES(modifiers_json), item_group_id = VALUES(item_group_id),
                is_changed = VALUES(is_changed)
        ");
        $detailId = (int) ($line['detail_id'] ?? 0);
        $itemId = $normalized['item_id'];
        $groupId = $normalized['item_group_id'];
        $name = $normalized['name'];
        $qty = $normalized['qty_num'];
        $notes = $normalized['notes'];
        $mods = $normalized['modifiers_json'] !== '' ? $normalized['modifiers_json'] : null;
        $stmt->bind_param(
            'iisiisdssi',
            $ticketId,
            $detailId,
            $lineKey,
            $itemId,
            $groupId,
            $name,
            $qty,
            $notes,
            $mods,
            $changedFlag
        );
        $stmt->execute();
        $stmt->close();
    }

    // --- Status transitions ----------------------------------------------

    public function startTicket(mysqli $conn, int $ticketId, int $userId): bool
    {
        return $this->transition($conn, $ticketId, $userId, 'start');
    }

    public function completeTicket(mysqli $conn, int $ticketId, int $userId): bool
    {
        return $this->transition($conn, $ticketId, $userId, 'complete');
    }

    public function recallTicket(mysqli $conn, int $ticketId, int $userId): bool
    {
        return $this->transition($conn, $ticketId, $userId, 'recall');
    }

    private function transition(mysqli $conn, int $ticketId, int $userId, string $action): bool
    {
        $ticket = $this->fetchTicketById($conn, $ticketId);
        if (!$ticket) {
            throw new InvalidArgumentException('KDS_TICKET_NOT_FOUND');
        }
        $stationId = (int) $ticket['station_id'];
        $orderId = (int) $ticket['order_id'];
        $currentStatus = (string) $ticket['status'];

        switch ($action) {
            case 'start':
                if ($currentStatus === 'new') {
                    $lineUp = $conn->prepare("UPDATE kds_ticket_lines SET line_status = 'cooking' WHERE ticket_id = ? AND line_status = 'new'");
                    $lineUp->bind_param('i', $ticketId);
                    $lineUp->execute();
                    $lineUp->close();
                    $sql = "UPDATE kds_tickets SET status = 'in_progress', started_at = COALESCE(started_at, NOW()) WHERE id = ?";
                } elseif ($currentStatus === 'in_progress') {
                    $lineUp = $conn->prepare("UPDATE kds_ticket_lines SET line_status = 'cooking' WHERE ticket_id = ? AND line_status = 'new'");
                    $lineUp->bind_param('i', $ticketId);
                    $lineUp->execute();
                    $lineAffected = $lineUp->affected_rows;
                    $lineUp->close();
                    if ($lineAffected < 1) {
                        return false;
                    }

                    return $this->recordStatusChange($conn, $stationId, $ticketId, $orderId);
                } else {
                    return false;
                }
                $param = 'i';
                break;
            case 'complete':
                if (!in_array($currentStatus, ['new', 'in_progress'], true)) {
                    return false;
                }
                // Bump every non-voided actionable line to 'done'.
                $lineUp = $conn->prepare("UPDATE kds_ticket_lines SET line_status = 'done' WHERE ticket_id = ? AND line_status IN ('new','cooking')");
                $lineUp->bind_param('i', $ticketId);
                $lineUp->execute();
                $lineUp->close();
                $sql = "UPDATE kds_tickets
                        SET status = 'completed', completed_at = NOW(), completed_by = ?,
                            started_at = COALESCE(started_at, NOW())
                        WHERE id = ?";
                $param = 'ii';
                break;
            case 'recall':
                if ($currentStatus !== 'completed') {
                    return false;
                }
                // Bring done lines back into the kitchen as 'cooking'.
                $lineUp = $conn->prepare("UPDATE kds_ticket_lines SET line_status = 'cooking' WHERE ticket_id = ? AND line_status = 'done'");
                $lineUp->bind_param('i', $ticketId);
                $lineUp->execute();
                $lineUp->close();
                $sql = "UPDATE kds_tickets
                        SET status = 'in_progress', completed_at = NULL, completed_by = NULL, ready_at = NULL
                        WHERE id = ?";
                $param = 'i';
                break;
            default:
                throw new InvalidArgumentException('KDS_TICKET_ACTION_INVALID');
        }

        $stmt = $conn->prepare($sql);
        if ($param === 'ii') {
            $stmt->bind_param('ii', $userId, $ticketId);
        } else {
            $stmt->bind_param('i', $ticketId);
        }
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected < 1) {
            return false;
        }

        return $this->recordStatusChange($conn, $stationId, $ticketId, $orderId);
    }

    private function recordStatusChange(mysqli $conn, int $stationId, int $ticketId, int $orderId): bool
    {
        $this->recordChange($conn, $stationId, $ticketId, $orderId, 'status');
        $this->recomputeOrderKitchenStatus($conn, $orderId);

        return true;
    }

    private function autoCompleteOnPaidIfConfigured(mysqli $conn, int $stationId, int $ticketId, int $orderId, int $userId): void
    {
        $station = $this->stations->getStation($conn, $stationId);
        if (!$station || empty($station['auto_complete_on_paid'])) {
            return;
        }
        $lineUp = $conn->prepare("UPDATE kds_ticket_lines SET line_status = 'done' WHERE ticket_id = ? AND line_status IN ('new','cooking')");
        $lineUp->bind_param('i', $ticketId);
        $lineUp->execute();
        $lineUp->close();
        $stmt = $conn->prepare("
            UPDATE kds_tickets
            SET status = 'completed', completed_at = NOW(), completed_by = ?,
                started_at = COALESCE(started_at, NOW())
            WHERE id = ? AND status IN ('new','in_progress')
        ");
        $stmt->bind_param('ii', $userId, $ticketId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected > 0) {
            $this->recordChange($conn, $stationId, $ticketId, $orderId, 'status');
        }
    }

    private function cancelTicket(mysqli $conn, int $orderId, int $stationId): void
    {
        $stmt = $conn->prepare("SELECT id FROM kds_tickets WHERE order_id = ? AND station_id = ? AND status <> 'cancelled'");
        $stmt->bind_param('ii', $orderId, $stationId);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($r = $result->fetch_assoc()) {
            $ids[] = (int) $r['id'];
        }
        $stmt->close();

        foreach ($ids as $ticketId) {
            $up = $conn->prepare("UPDATE kds_tickets SET status = 'cancelled' WHERE id = ?");
            $up->bind_param('i', $ticketId);
            $up->execute();
            $up->close();
            $this->recordChange($conn, $stationId, $ticketId, $orderId, 'removed');
        }
    }

    private function cancelAllTicketsForOrder(mysqli $conn, int $orderId): void
    {
        $stmt = $conn->prepare("SELECT id, station_id FROM kds_tickets WHERE order_id = ? AND status <> 'cancelled'");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($r = $result->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();

        foreach ($rows as $row) {
            $ticketId = (int) $row['id'];
            $stationId = (int) $row['station_id'];
            $up = $conn->prepare("UPDATE kds_tickets SET status = 'cancelled' WHERE id = ?");
            $up->bind_param('i', $ticketId);
            $up->execute();
            $up->close();
            $this->recordChange($conn, $stationId, $ticketId, $orderId, 'removed');
        }
    }

    // --- Reads (display feed) --------------------------------------------

    public function listActiveForStation(mysqli $conn, int $stationId): array
    {
        $placeholders = "'" . implode("','", self::ACTIVE_STATUSES) . "'";
        $stmt = $conn->prepare("
            SELECT * FROM kds_tickets
            WHERE station_id = ? AND status IN ($placeholders)
            ORDER BY placed_at ASC, id ASC
        ");
        $stmt->bind_param('i', $stationId);
        $stmt->execute();
        $result = $stmt->get_result();
        $tickets = [];
        while ($r = $result->fetch_assoc()) {
            $tickets[] = $this->ticketPublicState($conn, $r, true);
        }
        $stmt->close();

        return $tickets;
    }

    /**
     * Cursor poll. Returns only what changed since $sinceId, plus a new
     * cursor. When $sinceId <= 0 a full active snapshot is returned.
     */
    public function changesSince(mysqli $conn, int $stationId, int $sinceId): array
    {
        $cursor = $this->maxChangeId($conn, $stationId);

        if ($sinceId <= 0) {
            $tickets = $this->listActiveForStation($conn, $stationId);
            $changes = [];
            foreach ($tickets as $ticket) {
                $changes[] = ['type' => 'ticket', 'ticket' => $ticket];
            }
            return ['cursor' => $cursor, 'full' => true, 'changes' => $changes];
        }

        $stmt = $conn->prepare("
            SELECT DISTINCT ticket_id
            FROM kds_changes
            WHERE station_id = ? AND id > ?
            ORDER BY ticket_id ASC
        ");
        $stmt->bind_param('ii', $stationId, $sinceId);
        $stmt->execute();
        $result = $stmt->get_result();
        $ticketIds = [];
        while ($r = $result->fetch_assoc()) {
            $ticketIds[] = (int) $r['ticket_id'];
        }
        $stmt->close();

        $changes = [];
        foreach ($ticketIds as $ticketId) {
            $row = $this->fetchTicketById($conn, $ticketId);
            if (!$row || !in_array((string) $row['status'], self::ACTIVE_STATUSES, true)) {
                $changes[] = ['type' => 'removed', 'ticket_id' => $ticketId];
                continue;
            }
            $changes[] = ['type' => 'ticket', 'ticket' => $this->ticketPublicState($conn, $row, true)];
        }

        return ['cursor' => $cursor, 'full' => false, 'changes' => $changes];
    }

    public function stationIdForTicket(mysqli $conn, int $ticketId): int
    {
        $ticket = $this->fetchTicketById($conn, $ticketId);

        return $ticket ? (int) $ticket['station_id'] : 0;
    }

    public function history(mysqli $conn, int $stationId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $conn->prepare("
            SELECT * FROM kds_tickets
            WHERE station_id = ? AND status IN ('completed','cancelled')
            ORDER BY COALESCE(completed_at, updated_at) DESC, id DESC
            LIMIT ?
        ");
        $stmt->bind_param('ii', $stationId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $tickets = [];
        while ($r = $result->fetch_assoc()) {
            $tickets[] = $this->ticketPublicState($conn, $r, true);
        }
        $stmt->close();

        return $tickets;
    }

    // --- Order rollup -----------------------------------------------------

    public function recomputeOrderKitchenStatus(mysqli $conn, int $orderId): void
    {
        if (!$this->hasKitchenStatusColumn($conn)) {
            return;
        }

        $stmt = $conn->prepare("SELECT status, COUNT(*) AS c FROM kds_tickets WHERE order_id = ? AND status <> 'cancelled' GROUP BY status");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $counts = [];
        $total = 0;
        while ($r = $result->fetch_assoc()) {
            $counts[(string) $r['status']] = (int) $r['c'];
            $total += (int) $r['c'];
        }
        $stmt->close();

        if ($total === 0) {
            return;
        }

        $completed = (int) ($counts['completed'] ?? 0);
        $started = (int) ($counts['in_progress'] ?? 0);
        if ($completed === $total) {
            $newStatus = 'completed';
        } elseif ($started > 0 || $completed > 0) {
            $newStatus = 'in_progress';
        } else {
            $newStatus = 'pending';
        }

        $current = $this->currentKitchenStatus($conn, $orderId);
        if ($current === $newStatus) {
            return;
        }

        if ($newStatus === 'completed') {
            $stmt = $conn->prepare("UPDATE ot_head SET kitchen_status = 'completed', kitchen_completed_at = NOW() WHERE id = ?");
            $stmt->bind_param('i', $orderId);
        } else {
            $stmt = $conn->prepare("UPDATE ot_head SET kitchen_status = ?, kitchen_completed_at = NULL WHERE id = ?");
            $stmt->bind_param('si', $newStatus, $orderId);
        }
        $stmt->execute();
        $stmt->close();

        if ($newStatus === 'completed' && $current !== 'completed') {
            try {
                $this->orderEvents->recordIfAvailable($conn, $orderId, 'kitchen.order.completed', 'kds', [
                    'metadata' => ['ticket_count' => $total],
                ]);
            } catch (Throwable $exception) {
                error_log('KDS order completed event skipped: ' . $exception->getMessage());
            }
        }
    }

    // --- Reconciliation (resilience) -------------------------------------

    public function reconcile(mysqli $conn, int $limit = 25): void
    {
        if (!$this->tablesExist($conn)) {
            return;
        }
        $this->stations->ensureDefaultStation($conn);
        $limit = max(1, min(100, $limit));
        $lookbackHours = self::RECONCILE_LOOKBACK_HOURS;
        $kitchenDoneFilter = $this->hasKitchenStatusColumn($conn)
            ? " AND COALESCE(h.kitchen_status, 'pending') <> 'completed'"
            : '';

        // Only heal recent orders that never received a kitchen ticket. Orders
        // already completed/cancelled on KDS, or older than the lookback
        // window, are intentionally skipped so opening the board does not
        // import the entire historical open-order backlog.
        $sql = "
            SELECT h.id
            FROM ot_head h
            WHERE h.pro_tybe = 9
              AND COALESCE(h.isdeleted, 0) = 0
              AND COALESCE(h.order_status, 'active') = 'active'
              AND COALESCE(h.payment_status, 'unpaid') IN ('unpaid','partial')
              AND NOT EXISTS (
                  SELECT 1 FROM kds_tickets k
                  WHERE k.order_id = h.id AND k.status <> 'cancelled'
              )
              AND (
                  h.pro_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                  OR h.mdtime >= DATE_SUB(NOW(), INTERVAL ? HOUR)
              )
              {$kitchenDoneFilter}
            ORDER BY h.id DESC
            LIMIT ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $lookbackHours, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $missing = [];
        while ($r = $result->fetch_assoc()) {
            $missing[] = (int) $r['id'];
        }
        $stmt->close();

        foreach ($missing as $orderId) {
            try {
                $this->syncForOrder($conn, $orderId, 'reconcile');
            } catch (Throwable $exception) {
                error_log('KDS reconcile sync skipped for order ' . $orderId . ': ' . $exception->getMessage());
            }
        }

        $stale = $conn->prepare("
            SELECT DISTINCT k.order_id
            FROM kds_tickets k
            JOIN ot_head h ON h.id = k.order_id
            WHERE k.status NOT IN ('cancelled','completed')
              AND (COALESCE(h.isdeleted, 0) = 1 OR COALESCE(h.order_status, 'active') = 'cancelled')
            LIMIT ?
        ");
        $stale->bind_param('i', $limit);
        $stale->execute();
        $staleResult = $stale->get_result();
        $staleOrders = [];
        while ($r = $staleResult->fetch_assoc()) {
            $staleOrders[] = (int) $r['order_id'];
        }
        $stale->close();

        foreach ($staleOrders as $orderId) {
            $this->cancelAllTicketsForOrder($conn, $orderId);
            $this->recomputeOrderKitchenStatus($conn, $orderId);
        }
    }

    // --- Internal queries -------------------------------------------------

    private function fetchOrderHeader(mysqli $conn, int $orderId): ?array
    {
        $stmt = $conn->prepare("
            SELECT h.id, h.pro_id, h.table_id, h.order_type, h.order_status, h.payment_status, h.isdeleted,
                   t.tname AS table_name
            FROM ot_head h
            LEFT JOIN tables t ON t.id = h.table_id
            WHERE h.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function fetchActiveTicket(mysqli $conn, int $orderId, int $stationId): ?array
    {
        $placeholders = "'" . implode("','", self::ACTIVE_STATUSES) . "'";
        $stmt = $conn->prepare("
            SELECT * FROM kds_tickets
            WHERE order_id = ? AND station_id = ? AND status IN ($placeholders)
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param('ii', $orderId, $stationId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function fetchRootTicket(mysqli $conn, int $orderId, int $stationId): ?array
    {
        $stmt = $conn->prepare("
            SELECT * FROM kds_tickets
            WHERE order_id = ? AND station_id = ? AND parent_ticket_id IS NULL
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->bind_param('ii', $orderId, $stationId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function fetchTicket(mysqli $conn, int $orderId, int $stationId): ?array
    {
        return $this->fetchActiveTicket($conn, $orderId, $stationId)
            ?: $this->fetchRootTicket($conn, $orderId, $stationId);
    }

    private function fetchTicketById(mysqli $conn, int $ticketId): ?array
    {
        $stmt = $conn->prepare("SELECT * FROM kds_tickets WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $ticketId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function stationIdsWithTicket(mysqli $conn, int $orderId): array
    {
        $stmt = $conn->prepare("SELECT station_id FROM kds_tickets WHERE order_id = ? AND status <> 'cancelled'");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($r = $result->fetch_assoc()) {
            $ids[] = (int) $r['station_id'];
        }
        $stmt->close();

        return $ids;
    }

    private function maxChangeId(mysqli $conn, int $stationId): int
    {
        $stmt = $conn->prepare("SELECT COALESCE(MAX(id), 0) AS m FROM kds_changes WHERE station_id = ?");
        $stmt->bind_param('i', $stationId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['m'] ?? 0);
    }

    private function recordChange(mysqli $conn, int $stationId, int $ticketId, int $orderId, string $kind): void
    {
        [$tenant, $branch] = $this->scope();
        $stmt = $conn->prepare("
            INSERT INTO kds_changes (station_id, ticket_id, order_id, kind, tenant, branch)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('iiisii', $stationId, $ticketId, $orderId, $kind, $tenant, $branch);
        $stmt->execute();
        $stmt->close();
    }

    private function ticketLines(mysqli $conn, int $ticketId): array
    {
        $stmt = $conn->prepare("
            SELECT detail_id, item_id, item_group_id, name, qty, notes, modifiers_json, is_changed, line_status
            FROM kds_ticket_lines
            WHERE ticket_id = ?
            ORDER BY id ASC
        ");
        $stmt->bind_param('i', $ticketId);
        $stmt->execute();
        $result = $stmt->get_result();
        $lines = [];
        while ($r = $result->fetch_assoc()) {
            $modifiers = [];
            if (!empty($r['modifiers_json'])) {
                $decoded = json_decode((string) $r['modifiers_json'], true);
                if (is_array($decoded)) {
                    $modifiers = $decoded;
                }
            }
            $rawStatus = (string) ($r['line_status'] ?? 'new');
            if ($rawStatus === 'pending') {
                $rawStatus = 'new';
            }
            $lines[] = [
                'detail_id' => (int) $r['detail_id'],
                'item_id' => $this->nullableInt($r['item_id']),
                'name' => (string) ($r['name'] ?? ''),
                'qty' => $this->quantity($r['qty']),
                'notes' => (string) ($r['notes'] ?? ''),
                'modifiers' => $modifiers,
                'is_changed' => (int) $r['is_changed'] === 1,
                'line_status' => $rawStatus,
            ];
        }
        $stmt->close();

        return $lines;
    }

    private function ticketPublicState(mysqli $conn, array $row, bool $includeLines): array
    {
        $placedAt = (string) ($row['placed_at'] ?? '');
        $placedTs = $placedAt !== '' ? strtotime($placedAt) : time();
        $parentTicketId = $this->nullableInt($row['parent_ticket_id'] ?? null);
        $state = [
            'id' => (int) $row['id'],
            'uuid' => (string) ($row['uuid'] ?? ''),
            'order_id' => (int) $row['order_id'],
            'station_id' => (int) $row['station_id'],
            'parent_ticket_id' => $parentTicketId,
            'is_supplement' => $parentTicketId !== null && $parentTicketId > 0,
            'table_id' => $this->nullableInt($row['table_id'] ?? null),
            'table_name' => (string) ($row['table_name'] ?? ''),
            'order_label' => (string) ($row['order_label'] ?? ''),
            'order_type' => (string) ($row['order_type'] ?? ''),
            'status' => (string) $row['status'] === 'ready' ? 'in_progress' : (string) $row['status'],
            'item_count' => (int) $row['item_count'],
            'revision' => (int) $row['revision'],
            'placed_at' => $placedAt,
            'placed_ts' => $placedTs,
            'seconds_elapsed' => max(0, time() - $placedTs),
            'completed_at' => (string) ($row['completed_at'] ?? ''),
        ];
        if ($includeLines) {
            $lines = $this->ticketLines($conn, (int) $row['id']);
            if ($state['is_supplement'] && in_array($state['status'], self::ACTIVE_STATUSES, true)) {
                $lines = array_values(array_filter($lines, static function (array $line): bool {
                    return in_array($line['line_status'], ['new', 'cooking'], true);
                }));
            }
            $state['lines'] = $lines;
        }

        return $state;
    }

    private function hasKitchenStatusColumn(mysqli $conn): bool
    {
        if ($this->kitchenStatusColumn !== null) {
            return $this->kitchenStatusColumn;
        }
        $result = $conn->query("SHOW COLUMNS FROM ot_head LIKE 'kitchen_status'");
        $this->kitchenStatusColumn = $result && $result->num_rows > 0;

        return $this->kitchenStatusColumn;
    }

    private function currentKitchenStatus(mysqli $conn, int $orderId): string
    {
        $stmt = $conn->prepare("SELECT kitchen_status FROM ot_head WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (string) ($row['kitchen_status'] ?? 'pending');
    }

    /**
     * Stable identity for a kitchen line across POS saves that churn fat_details.id.
     * Matches item + modifiers + notes, not the ephemeral detail row id.
     */
    private function kitchenLineKey(array $line): string
    {
        $normalized = $this->normalizeLine($line);

        return $this->kitchenLineKeyFromParts(
            (int) ($normalized['item_id'] ?? 0),
            $normalized['modifiers_json'],
            $normalized['notes']
        );
    }

    private function kitchenLineKeyFromRow(array $row): string
    {
        return $this->kitchenLineKeyFromParts(
            (int) ($row['item_id'] ?? 0),
            (string) ($row['modifiers_json'] ?? ''),
            (string) ($row['notes'] ?? '')
        );
    }

    private function kitchenLineKeyFromParts(int $itemId, string $modifiersJson, string $notes): string
    {
        if ($itemId < 1) {
            return '';
        }

        return hash('sha256', $itemId . '|' . $modifiersJson . '|' . $notes);
    }

    /**
     * Merge order payload lines that share the same kitchen identity (e.g. two
     * fat_details rows for the same burger) into one logical line for KDS math.
     *
     * @return array<string, array<string, mixed>>
     */
    private function aggregateOrderLinesByKey(array $lines): array
    {
        $byKey = [];
        foreach ($lines as $line) {
            $lineKey = $this->kitchenLineKey($line);
            if ($lineKey === '') {
                continue;
            }
            $normalized = $this->normalizeLine($line);
            if (!isset($byKey[$lineKey])) {
                $byKey[$lineKey] = $line;
                $byKey[$lineKey]['qty'] = $normalized['qty_num'];
                continue;
            }
            $byKey[$lineKey]['qty'] = (float) ($byKey[$lineKey]['qty'] ?? 0) + $normalized['qty_num'];
            $byKey[$lineKey]['detail_id'] = $line['detail_id'] ?? $byKey[$lineKey]['detail_id'];
        }

        return $byKey;
    }

    private function normalizeLine(array $line): array
    {
        $notesParts = [];
        $legacy = trim((string) ($line['legacy_notes'] ?? ''));
        if ($legacy !== '') {
            $notesParts[] = $legacy;
        }
        if (is_array($line['notes'] ?? null)) {
            foreach ($line['notes'] as $note) {
                $text = is_array($note) ? trim((string) ($note['note_text'] ?? '')) : trim((string) $note);
                if ($text !== '') {
                    $notesParts[] = $text;
                }
            }
        } elseif (!empty($line['notes']) && is_string($line['notes'])) {
            $notesParts[] = trim((string) $line['notes']);
        }
        $notes = implode(' | ', array_unique($notesParts));

        $modifiers = is_array($line['modifiers'] ?? null) ? $line['modifiers'] : [];
        $modifiersJson = $modifiers ? (json_encode(array_values($modifiers), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') : '';

        $qtyNum = (float) ($line['qty'] ?? 0);

        return [
            'item_id' => $this->nullableInt($line['item_id'] ?? null),
            'item_group_id' => $this->nullableInt($line['item_group_id'] ?? null),
            'name' => (string) ($line['name'] ?? ''),
            'qty_num' => $qtyNum,
            'qty' => $this->quantity($qtyNum),
            'notes' => $notes,
            'modifiers_json' => $modifiersJson,
        ];
    }

    private function quantity($value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function scope(): array
    {
        if ($this->scopeCache) {
            return $this->scopeCache;
        }
        $tenant = 0;
        $branch = 0;
        if (function_exists('posmain_config')) {
            $config = posmain_config();
            $branchCfg = is_array($config['branch'] ?? null) ? $config['branch'] : [];
            $tenant = (int) ($branchCfg['pos_tenant'] ?? 0);
            $branch = (int) ($branchCfg['pos_branch'] ?? 0);
        }

        return $this->scopeCache = [$tenant, $branch];
    }
}
