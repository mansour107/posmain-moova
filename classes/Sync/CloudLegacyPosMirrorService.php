<?php

class CloudLegacyPosMirrorService
{
    public function mirrorFromBranchEvent(mysqli $conn, string $branchUuid, array $event): ?array
    {
        if ($this->isOrderEvent($event)) {
            return $this->mirrorOrderEvent($conn, $event);
        }

        if ($this->isTableEvent($event)) {
            return $this->mirrorTableEvent($conn, $event);
        }

        if ($this->isMenuEvent($event)) {
            return $this->mirrorMenuEvent($conn, $event);
        }

        return null;
    }

    private function mirrorOrderEvent(mysqli $conn, array $event): ?array
    {
        $payload = $this->payload($event);
        $order = $this->orderPayload($payload);
        $orderId = $this->intOrNull($this->firstExistingValue([$order, $payload, $event], [
            'local_order_id',
            'order_id',
            'id',
            'aggregate_local_id',
            'entity_local_id',
        ]));
        if (!$orderId) {
            return null;
        }

        if ($this->isIncomingOlderThanLocal($conn, 'ot_head', $orderId, $event, [$order, $payload])) {
            return [
                'legacy_entity_id' => 'ot_head:' . $orderId,
                'stale' => true,
                'reason' => 'local_newer',
            ];
        }

        $sourceMdtime = $this->incomingDatetime($event, [$order, $payload]);
        $legacy = isset($order['legacy']) && is_array($order['legacy']) ? $order['legacy'] : [];
        $orderUuid = $this->nullableString($this->firstExistingValue([$order, $payload, $event], [
            'order_uuid',
            'pos_order_uuid',
            'entity_uuid',
            'aggregate_uuid',
        ]), 36);
        $proTybe = $this->intOrNull($this->firstValue([$order, $payload], 'pro_tybe')) ?: 9;
        $orderType = $this->orderType($this->firstValue([$order, $payload], 'order_type'));
        $proDate = $this->dateOrNull($this->firstExistingValue([$order, $payload], ['pro_date', 'created_at']));
        $createdAt = $this->datetimeOrNull($this->firstValue([$order, $payload], 'created_at'))
            ?: ($proDate ? $proDate . ' 00:00:00' : date('Y-m-d H:i:s'));
        $completedAt = $this->datetimeOrNull($this->firstValue([$order, $payload], 'completed_at'));
        $paymentDate = $this->datetimeOrNull($this->firstValue([$order, $payload], 'payment_date'));
        $proId = $this->intOrNull($this->firstValue([$order, $payload], 'pro_id'));
        $tableId = $this->intOrNull($this->firstValue([$order, $payload], 'table_id'));
        $cashierUser = $this->intOrNull($this->firstExistingValue([$order, $payload], ['cashier_user_id', 'user', 'user_id'])) ?: 1;
        $waiterId = $this->intOrNull($this->firstExistingValue([$order, $payload], ['waiter_id', 'emp2_id']));
        $tenant = $this->intOrZero($this->firstExistingValue([$legacy, $order, $payload, $event], ['tenant', 'pos_tenant']));
        $branch = $this->intOrZero($this->firstExistingValue([$legacy, $order, $payload, $event], ['branch', 'pos_branch']));

        $params = [
            $orderId,
            $orderUuid,
            $proId,
            $proTybe,
            $orderType,
            $tableId,
            $this->nullableString($this->firstValue([$legacy, $order], 'info'), 200),
            $proDate,
            $createdAt,
            $completedAt,
            $paymentDate,
            $this->decimal($this->firstValue([$order, $payload], 'pro_value')),
            $this->decimal($this->firstValue([$order, $payload], 'fat_total')),
            $this->decimal($this->firstValue([$order, $payload], 'fat_net')),
            $this->decimal($this->firstValue([$order, $payload], 'fat_disc')),
            $this->decimal($this->firstValue([$order, $payload], 'paid_amount')),
            $this->decimal($this->firstValue([$order, $payload], 'remaining_amount')),
            $this->paymentStatus($this->firstValue([$order, $payload], 'payment_status')),
            $this->invoiceStatus($this->firstValue([$order, $payload], 'invoice_status')),
            $this->orderStatus($this->firstValue([$order, $payload], 'order_status')),
            $this->boolInt($this->firstValue([$order, $payload], 'isdeleted'), 0),
            $this->boolInt($this->firstValue([$order, $payload], 'closed'), 0),
            $cashierUser,
            $waiterId,
            $tenant,
            $branch,
            $this->intOrNull($this->firstValue([$legacy, $order], 'store_id')),
            $this->intOrNull($this->firstValue([$legacy, $order], 'acc1')),
            $this->intOrNull($this->firstValue([$legacy, $order], 'acc2')),
            $sourceMdtime,
        ];

        $stmt = $conn->prepare("
            INSERT INTO ot_head (
                id,
                uuid,
                pro_id,
                pro_tybe,
                order_type,
                table_id,
                info,
                pro_date,
                crtime,
                completed_at,
                payment_date,
                pro_value,
                fat_total,
                fat_net,
                fat_disc,
                paid_amount,
                remaining_amount,
                payment_status,
                invoice_status,
                order_status,
                isdeleted,
                closed,
                user,
                waiter_id,
                tenant,
                branch,
                store_id,
                acc1,
                acc2,
                mdtime
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, CURRENT_TIMESTAMP))
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                uuid = VALUES(uuid),
                pro_id = VALUES(pro_id),
                pro_tybe = VALUES(pro_tybe),
                order_type = VALUES(order_type),
                table_id = VALUES(table_id),
                info = VALUES(info),
                pro_date = VALUES(pro_date),
                completed_at = VALUES(completed_at),
                payment_date = VALUES(payment_date),
                pro_value = VALUES(pro_value),
                fat_total = VALUES(fat_total),
                fat_net = VALUES(fat_net),
                fat_disc = VALUES(fat_disc),
                paid_amount = VALUES(paid_amount),
                remaining_amount = VALUES(remaining_amount),
                payment_status = VALUES(payment_status),
                invoice_status = VALUES(invoice_status),
                order_status = VALUES(order_status),
                isdeleted = VALUES(isdeleted),
                closed = VALUES(closed),
                user = VALUES(user),
                waiter_id = VALUES(waiter_id),
                tenant = VALUES(tenant),
                branch = VALUES(branch),
                store_id = VALUES(store_id),
                acc1 = VALUES(acc1),
                acc2 = VALUES(acc2),
                mdtime = COALESCE(VALUES(mdtime), CURRENT_TIMESTAMP)
        ");
        $this->bindParams($stmt, str_repeat('s', count($params)), $params);
        $stmt->execute();
        $stmt->close();

        $lineCount = $this->mirrorOrderLines($conn, $payload, $order, $orderId, $proTybe, $tenant, $branch, $sourceMdtime);

        return [
            'legacy_entity_id' => 'ot_head:' . $orderId,
            'line_count' => $lineCount,
        ];
    }

    private function mirrorOrderLines(
        mysqli $conn,
        array $payload,
        array $order,
        int $orderId,
        int $proTybe,
        int $tenant,
        int $branch,
        ?string $sourceMdtime = null
    ): int {
        $count = 0;
        foreach ($this->listFromPayload($payload, $order, ['lines', 'order_lines', 'items']) as $line) {
            if (!is_array($line)) {
                continue;
            }

            $lineId = $this->intOrNull($this->firstExistingValue([$line], ['local_line_id', 'line_id', 'det_id', 'id']));
            if (!$lineId) {
                continue;
            }

            $this->mirrorMenuItemFromLine($conn, $line, $sourceMdtime);
            $lineUuid = $this->nullableString($this->firstExistingValue([$line], ['line_uuid', 'local_uuid', 'uuid']), 36);
            $params = [
                $lineId,
                $lineUuid,
                $proTybe,
                $orderId,
                $this->intOrZero($this->firstValue([$line], 'item_id')),
                $this->decimal($this->firstValue([$line], 'qty_in')),
                $this->decimal($this->firstValue([$line], 'qty_out')),
                $this->decimal($this->firstValue([$line], 'price')),
                $this->decimal($this->firstValue([$line], 'cost_price')),
                $this->decimal($this->firstExistingValue([$line], ['discount', 'disc'])),
                $this->decimal($this->firstExistingValue([$line], ['det_value', 'line_total'])),
                $this->decimal($this->firstValue([$line], 'profit')),
                $orderId,
                $proTybe,
                $this->boolInt($this->firstValue([$line], 'isdeleted'), 0),
                $tenant,
                $branch,
                $sourceMdtime,
            ];

            $stmt = $conn->prepare("
                INSERT INTO fat_details (
                    id,
                    uuid,
                    pro_tybe,
                    pro_id,
                    item_id,
                    qty_in,
                    qty_out,
                    price,
                    cost_price,
                    discount,
                    det_value,
                    profit,
                    fatid,
                    fat_tybe,
                    isdeleted,
                    tenant,
                    branch,
                    mdtime
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, CURRENT_TIMESTAMP))
                ON DUPLICATE KEY UPDATE
                    id = LAST_INSERT_ID(id),
                    uuid = VALUES(uuid),
                    pro_tybe = VALUES(pro_tybe),
                    pro_id = VALUES(pro_id),
                    item_id = VALUES(item_id),
                    qty_in = VALUES(qty_in),
                    qty_out = VALUES(qty_out),
                    price = VALUES(price),
                    cost_price = VALUES(cost_price),
                    discount = VALUES(discount),
                    det_value = VALUES(det_value),
                    profit = VALUES(profit),
                    fatid = VALUES(fatid),
                    fat_tybe = VALUES(fat_tybe),
                    isdeleted = VALUES(isdeleted),
                    tenant = VALUES(tenant),
                    branch = VALUES(branch),
                    mdtime = COALESCE(VALUES(mdtime), CURRENT_TIMESTAMP)
            ");
            $this->bindParams($stmt, str_repeat('s', count($params)), $params);
            $stmt->execute();
            $stmt->close();
            $count++;
        }

        return $count;
    }

    private function mirrorTableEvent(mysqli $conn, array $event): ?array
    {
        $payload = $this->payload($event);
        $table = $this->tablePayload($payload);
        $tableId = $this->intOrNull($this->firstExistingValue([$table, $payload, $event], [
            'local_table_id',
            'table_id',
            'id',
            'aggregate_local_id',
            'entity_local_id',
        ]));
        if (!$tableId) {
            return null;
        }

        if ($this->isIncomingOlderThanLocal($conn, 'tables', $tableId, $event, [$table, $payload])) {
            return [
                'legacy_entity_id' => 'tables:' . $tableId,
                'stale' => true,
                'reason' => 'local_newer',
            ];
        }

        $tableUuid = $this->nullableString($this->firstExistingValue([$table, $payload, $event], [
            'table_uuid',
            'local_uuid',
            'uuid',
            'entity_uuid',
            'aggregate_uuid',
        ]), 36);
        $tableName = $this->nullableString($this->firstExistingValue([$table, $payload], ['tname', 'table_name', 'name']), 255)
            ?: 'Table ' . $tableId;
        $sourceMdtime = $this->incomingDatetime($event, [$table, $payload]);
        $params = [
            $tableId,
            $tableUuid,
            $tableName,
            $this->intOrZero($this->firstExistingValue([$table, $payload], ['table_case', 'case'])),
            $this->boolInt($this->firstExistingValue([$table, $payload], ['isdeleted', 'deleted']), 0),
            $sourceMdtime,
        ];

        $stmt = $conn->prepare("
            INSERT INTO tables (id, uuid, tname, table_case, isdeleted, mdtime)
            VALUES (?, ?, ?, ?, ?, COALESCE(?, CURRENT_TIMESTAMP))
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                uuid = VALUES(uuid),
                tname = VALUES(tname),
                table_case = VALUES(table_case),
                isdeleted = VALUES(isdeleted),
                mdtime = COALESCE(VALUES(mdtime), CURRENT_TIMESTAMP)
        ");
        $this->bindParams($stmt, str_repeat('s', count($params)), $params);
        $stmt->execute();
        $stmt->close();

        return ['legacy_entity_id' => 'tables:' . $tableId];
    }

    private function mirrorMenuEvent(mysqli $conn, array $event): ?array
    {
        $payload = $this->payload($event);
        $item = $this->itemPayload($payload);
        $itemId = $this->intOrNull($this->firstExistingValue([$item, $payload, $event], [
            'local_item_id',
            'item_id',
            'id',
            'aggregate_local_id',
            'entity_local_id',
        ]));
        if (!$itemId) {
            return null;
        }

        if ($this->isIncomingOlderThanLocal($conn, 'myitems', $itemId, $event, [$item, $payload])) {
            return [
                'legacy_entity_id' => 'myitems:' . $itemId,
                'stale' => true,
                'reason' => 'local_newer',
            ];
        }

        $this->mirrorMenuItem($conn, $itemId, $item, $this->incomingDatetime($event, [$item, $payload]));

        return ['legacy_entity_id' => 'myitems:' . $itemId];
    }

    private function mirrorMenuItemFromLine(mysqli $conn, array $line, ?string $sourceMdtime = null): void
    {
        $itemId = $this->intOrNull($this->firstValue([$line], 'item_id'));
        $itemName = $this->nullableString($this->firstExistingValue([$line], ['item_name', 'name']), 255);
        if (!$itemId || $itemName === null) {
            return;
        }

        $this->mirrorMenuItem($conn, $itemId, [
            'local_item_id' => $itemId,
            'item_name' => $itemName,
            'barcode' => $this->nullableString($this->firstValue([$line], 'barcode'), 191),
            'price' => $this->firstValue([$line], 'price'),
            'cost' => $this->firstValue([$line], 'cost_price'),
            'category_id' => 0,
            'isdeleted' => 0,
        ], $sourceMdtime);
    }

    private function mirrorMenuItem(mysqli $conn, int $itemId, array $item, ?string $sourceMdtime = null): void
    {
        $categoryId = $this->intOrZero($this->firstExistingValue([$item], ['category_id', 'cat_id', 'group_id', 'group1']));
        if ($categoryId > 0) {
            $this->ensureCategory($conn, $categoryId, $item);
        }

        $itemName = $this->nullableString($this->firstExistingValue([$item], ['item_name', 'name', 'title', 'iname']), 200)
            ?: 'Synced Item ' . $itemId;
        $price = $this->decimal($this->firstExistingValue([$item], ['price1', 'price', 'sale_price']));
        $price2 = $this->decimal($this->firstValue([$item], 'price2'));
        $price3 = $this->decimal($this->firstValue([$item], 'price3'));
        $cost = $this->decimal($this->firstExistingValue([$item], ['cost_price', 'cost']));
        $legacy = isset($item['legacy']) && is_array($item['legacy']) ? $item['legacy'] : [];
        $params = [
            $itemId,
            $itemName,
            $this->nullableString($this->firstValue([$item], 'name2'), 200),
            $this->nullableString($this->firstExistingValue([$item], ['barcode', 'bar_code']), 25),
            $this->nullableString($this->firstValue([$legacy, $item], 'info'), 250),
            $cost,
            $price,
            $price2,
            $price3,
            $categoryId,
            $this->intOrZero($this->firstExistingValue([$legacy, $item], ['group2', 'category2_id'])),
            $this->boolInt($this->firstExistingValue([$item], ['isdeleted', 'deleted']), 0),
            $this->intOrNull($this->firstValue([$legacy, $item], 'user')) ?: 1,
            $this->intOrZero($this->firstValue([$legacy, $item], 'tenant')),
            $this->intOrZero($this->firstValue([$legacy, $item], 'branch')),
            $this->itemType($this->firstValue([$legacy, $item], 'item_type')),
            $this->boolInt($this->firstValue([$legacy, $item], 'track_stock'), 1),
            $this->boolInt($this->firstValue([$legacy, $item], 'manual_price_edit'), 0),
            $sourceMdtime,
        ];

        $stmt = $conn->prepare("
            INSERT INTO myitems (
                id,
                iname,
                name2,
                barcode,
                info,
                cost_price,
                price1,
                price2,
                price3,
                group1,
                group2,
                isdeleted,
                user,
                tenant,
                branch,
                item_type,
                track_stock,
                manual_price_edit,
                mdtime
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, CURRENT_TIMESTAMP))
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                iname = VALUES(iname),
                name2 = VALUES(name2),
                barcode = VALUES(barcode),
                info = VALUES(info),
                cost_price = VALUES(cost_price),
                price1 = VALUES(price1),
                price2 = VALUES(price2),
                price3 = VALUES(price3),
                group1 = VALUES(group1),
                group2 = VALUES(group2),
                isdeleted = VALUES(isdeleted),
                user = VALUES(user),
                tenant = VALUES(tenant),
                branch = VALUES(branch),
                item_type = VALUES(item_type),
                track_stock = VALUES(track_stock),
                manual_price_edit = VALUES(manual_price_edit),
                mdtime = COALESCE(VALUES(mdtime), CURRENT_TIMESTAMP)
        ");
        $this->bindParams($stmt, str_repeat('s', count($params)), $params);
        $stmt->execute();
        $stmt->close();
    }

    private function ensureCategory(mysqli $conn, int $categoryId, array $item): void
    {
        $categoryName = $this->nullableString($this->firstExistingValue([$item], ['category_name', 'group_name', 'gname']), 100)
            ?: 'Synced Category ' . $categoryId;
        $isDeleted = $this->boolInt($this->firstExistingValue([$item], ['category_isdeleted', 'category_deleted']), 0);
        $params = [$categoryId, $categoryName, $isDeleted];

        $stmt = $conn->prepare("
            INSERT INTO item_group (id, gname, isdeleted)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                gname = VALUES(gname),
                isdeleted = VALUES(isdeleted)
        ");
        $this->bindParams($stmt, str_repeat('s', count($params)), $params);
        $stmt->execute();
        $stmt->close();
    }

    private function isOrderEvent(array $event): bool
    {
        $aggregateType = strtolower(trim((string) ($event['aggregate_type'] ?? '')));
        $entityType = strtolower(trim((string) ($event['entity_type'] ?? '')));
        $eventType = strtolower(trim((string) ($event['event_type'] ?? '')));
        if ($aggregateType === 'order' || $entityType === 'order' || strpos($eventType, 'order.') === 0) {
            return true;
        }

        $payload = $this->payload($event);
        $order = $this->orderPayload($payload);

        return $this->firstExistingValue([$order, $payload], ['order_uuid', 'pos_order_uuid']) !== null;
    }

    private function isTableEvent(array $event): bool
    {
        foreach (['aggregate_type', 'entity_type'] as $key) {
            if (strtolower(trim((string) ($event[$key] ?? ''))) === 'table') {
                return true;
            }
        }

        $eventType = strtolower(trim((string) ($event['event_type'] ?? '')));
        if (strpos($eventType, 'table.') === 0 || strpos($eventType, 'tables.') === 0) {
            return true;
        }

        $payload = $this->payload($event);
        return array_key_exists('table', $payload)
            || array_key_exists('table_uuid', $payload)
            || array_key_exists('local_table_id', $payload);
    }

    private function isMenuEvent(array $event): bool
    {
        foreach (['aggregate_type', 'entity_type'] as $key) {
            $type = strtolower(trim((string) ($event[$key] ?? '')));
            if ($type === 'menu_item' || $type === 'item') {
                return true;
            }
        }

        $eventType = strtolower(trim((string) ($event['event_type'] ?? '')));
        if (strpos($eventType, 'menu.') === 0 || strpos($eventType, 'item.') === 0) {
            return true;
        }

        $payload = $this->payload($event);
        return array_key_exists('menu_item', $payload)
            || array_key_exists('item', $payload)
            || array_key_exists('item_uuid', $payload);
    }

    private function payload(array $event): array
    {
        return isset($event['payload']) && is_array($event['payload']) ? $event['payload'] : [];
    }

    private function orderPayload(array $payload): array
    {
        if (isset($payload['order']) && is_array($payload['order'])) {
            return $payload['order'];
        }

        if (isset($payload['snapshot']) && is_array($payload['snapshot'])) {
            return $payload['snapshot'];
        }

        return $payload;
    }

    private function tablePayload(array $payload): array
    {
        if (isset($payload['table']) && is_array($payload['table'])) {
            return $payload['table'];
        }

        if (isset($payload['snapshot']) && is_array($payload['snapshot'])) {
            return $payload['snapshot'];
        }

        return $payload;
    }

    private function itemPayload(array $payload): array
    {
        if (isset($payload['menu_item']) && is_array($payload['menu_item'])) {
            return $payload['menu_item'];
        }

        if (isset($payload['item']) && is_array($payload['item'])) {
            return $payload['item'];
        }

        if (isset($payload['snapshot']) && is_array($payload['snapshot'])) {
            return $payload['snapshot'];
        }

        return $payload;
    }

    private function listFromPayload(array $payload, array $order, array $keys): array
    {
        $list = $this->firstExistingValue([$order, $payload], $keys);
        return is_array($list) ? $list : [];
    }

    private function firstExistingValue(array $sources, array $keys)
    {
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            foreach ($keys as $key) {
                if (array_key_exists($key, $source) && $source[$key] !== null && $source[$key] !== '') {
                    return $source[$key];
                }
            }
        }

        return null;
    }

    private function firstValue(array $sources, string $key)
    {
        return $this->firstExistingValue($sources, [$key]);
    }

    private function nullableString($value, int $maxLength): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return substr($value, 0, $maxLength);
    }

    private function intOrNull($value): ?int
    {
        if ($value === null || $value === false || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function intOrZero($value): int
    {
        return $this->intOrNull($value) ?: 0;
    }

    private function boolInt($value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if ($value === false || $value === '0' || $value === 0) {
            return 0;
        }

        return 1;
    }

    private function decimal($value): string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return '0.0000';
        }

        return number_format((float) $value, 4, '.', '');
    }

    private function dateOrNull($value): ?string
    {
        $value = $this->nullableString($value, 30);
        if ($value === null) {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? substr($value, 0, 10) : date('Y-m-d', $timestamp);
    }

    private function datetimeOrNull($value): ?string
    {
        $value = $this->nullableString($value, 40);
        if ($value === null) {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? $value : date('Y-m-d H:i:s', $timestamp);
    }

    private function orderType($value): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, ['dine_in', 'takeaway', 'delivery', 'table'], true) ? $value : 'takeaway';
    }

    private function paymentStatus($value): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, ['unpaid', 'partial', 'paid', 'refunded', 'voided'], true) ? $value : 'unpaid';
    }

    private function invoiceStatus($value): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, ['draft', 'completed', 'cancelled'], true) ? $value : 'completed';
    }

    private function orderStatus($value): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, ['draft', 'active', 'completed', 'cancelled'], true) ? $value : 'active';
    }

    private function itemType($value): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, ['sellable', 'ingredient', 'packaging', 'service'], true) ? $value : 'sellable';
    }

    private function isIncomingOlderThanLocal(mysqli $conn, string $table, int $id, array $event, array $payloadSources): bool
    {
        $incomingTimestamp = $this->incomingTimestamp($event, $payloadSources);
        if ($incomingTimestamp === null) {
            return false;
        }

        $localTimestamp = $this->localTimestamp($conn, $table, $id);
        if ($localTimestamp === null) {
            return false;
        }

        return $incomingTimestamp < $localTimestamp;
    }

    private function incomingTimestamp(array $event, array $payloadSources): ?int
    {
        $payload = $this->payload($event);
        $sources = array_merge([$event, $payload], $payloadSources);
        foreach (['captured_at_utc', 'updated_at_utc', 'updated_at', 'mdtime', 'created_at', 'crtime'] as $key) {
            $value = $this->firstValue($sources, $key);
            $timestamp = $this->timestampOrNull($value);
            if ($timestamp !== null) {
                return $timestamp;
            }
        }

        foreach (['sync_revision', 'menu_version', 'revision', 'event_version'] as $key) {
            $value = $this->firstValue($sources, $key);
            if (is_numeric($value) && (int) $value > 946684800) {
                return (int) $value;
            }
        }

        return null;
    }

    private function incomingDatetime(array $event, array $payloadSources): ?string
    {
        $timestamp = $this->incomingTimestamp($event, $payloadSources);
        return $timestamp === null ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function localTimestamp(mysqli $conn, string $table, int $id): ?int
    {
        if (!in_array($table, ['ot_head', 'tables', 'myitems'], true)) {
            return null;
        }

        $stmt = $conn->prepare("SELECT mdtime, crtime FROM {$table} WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        foreach (['mdtime', 'crtime'] as $key) {
            $timestamp = $this->timestampOrNull($row[$key] ?? null);
            if ($timestamp !== null) {
                return $timestamp;
            }
        }

        return null;
    }

    private function timestampOrNull($value): ?int
    {
        $value = $this->nullableString($value, 40);
        if ($value === null) {
            return null;
        }

        if (is_numeric($value) && (int) $value > 946684800) {
            return (int) $value;
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? null : (int) $timestamp;
    }

    private function bindParams(mysqli_stmt $stmt, string $types, array &$params): void
    {
        $refs = [];
        foreach ($params as $key => &$value) {
            $refs[$key] = &$value;
        }

        $stmt->bind_param($types, ...$refs);
    }
}
