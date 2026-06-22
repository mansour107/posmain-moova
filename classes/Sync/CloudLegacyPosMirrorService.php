<?php

class CloudLegacyPosMirrorService
{
    private array $columnExistsCache = [];

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
            ];

            $timestampColumns = '';
            $timestampValues = '';
            $timestampUpdates = '';
            if ($this->columnExists($conn, 'fat_details', 'mdtime')) {
                $timestampColumns = ',
                    mdtime';
                $timestampValues = ', COALESCE(?, CURRENT_TIMESTAMP)';
                $timestampUpdates = ',
                    mdtime = COALESCE(VALUES(mdtime), CURRENT_TIMESTAMP)';
                $params[] = $sourceMdtime;
            } elseif ($this->columnExists($conn, 'fat_details', 'crtime')) {
                $timestampColumns = ',
                    crtime';
                $timestampValues = ', COALESCE(?, CURRENT_TIMESTAMP)';
                $timestampUpdates = ',
                    crtime = COALESCE(VALUES(crtime), crtime)';
                $params[] = $sourceMdtime;
            }

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
                    branch{$timestampColumns}
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?{$timestampValues})
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
                    branch = VALUES(branch){$timestampUpdates}
            ");
            $this->bindParams($stmt, str_repeat('s', count($params)), $params);
            $stmt->execute();
            $stmt->close();
            $this->mirrorLineCustomizations($conn, $orderId, $lineId, $line);
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
        $tableCase = $this->intOrZero($this->firstExistingValue([$table, $payload], ['table_case', 'case']));
        $activeOrderLocalId = $this->intOrNull($this->firstExistingValue([$table, $payload], [
            'active_order_local_id',
            'current_order_id',
            'order_id',
        ]));
        if ($tableCase === 0 && ($activeOrderLocalId === null || $activeOrderLocalId <= 0)) {
            $this->closeActiveOrdersForFreedTable($conn, $tableId, $sourceMdtime);
        }

        $params = [
            $tableId,
            $tableUuid,
            $tableName,
            $tableCase,
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

    private function closeActiveOrdersForFreedTable(mysqli $conn, int $tableId, ?string $sourceMdtime = null): int
    {
        $orderIds = [];
        $stmt = $conn->prepare("
            SELECT id
            FROM ot_head
            WHERE table_id = ?
              AND pro_tybe = 9
              AND COALESCE(isdeleted, 0) = 0
              AND COALESCE(order_status, 'active') = 'active'
              AND COALESCE(payment_status, 'unpaid') IN ('unpaid', 'partial')
            FOR UPDATE
        ");
        $stmt->bind_param('i', $tableId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $orderIds[] = (int) $row['id'];
        }
        $stmt->close();

        if (!$orderIds) {
            return 0;
        }

        $set = [
            "order_status = 'cancelled'",
            "invoice_status = 'cancelled'",
            "payment_status = 'voided'",
            "isdeleted = 1",
        ];
        $params = [];
        if ($this->columnExists($conn, 'ot_head', 'cancelled_at')) {
            $set[] = 'cancelled_at = COALESCE(?, CURRENT_TIMESTAMP)';
            $params[] = $sourceMdtime;
        }
        if ($this->columnExists($conn, 'ot_head', 'cancellation_reason')) {
            $set[] = "cancellation_reason = COALESCE(NULLIF(cancellation_reason, ''), 'Synced table freed remotely')";
        }
        if ($this->columnExists($conn, 'ot_head', 'mdtime')) {
            $set[] = 'mdtime = COALESCE(?, CURRENT_TIMESTAMP)';
            $params[] = $sourceMdtime;
        }

        $placeholders = implode(', ', array_fill(0, count($orderIds), '?'));
        $sql = 'UPDATE ot_head SET ' . implode(', ', $set) . " WHERE id IN ({$placeholders})";
        foreach ($orderIds as $orderId) {
            $params[] = $orderId;
        }
        $stmt = $conn->prepare($sql);
        $this->bindParams($stmt, str_repeat('s', count($params)), $params);
        $stmt->execute();
        $updated = $stmt->affected_rows;
        $stmt->close();

        if ($this->tableExists($conn, 'fat_details')) {
            $stmt = $conn->prepare("UPDATE fat_details SET isdeleted = 1 WHERE fatid IN ({$placeholders})");
            $lineParams = $orderIds;
            $this->bindParams($stmt, str_repeat('s', count($lineParams)), $lineParams);
            $stmt->execute();
            $stmt->close();
        }

        return max(0, $updated);
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
        $this->mirrorMenuVariants($conn, $itemId, $item, $sourceMdtime);
        $this->mirrorMenuModifiers($conn, $itemId, $item, $sourceMdtime);
    }

    private function mirrorMenuVariants(mysqli $conn, int $itemId, array $item, ?string $sourceMdtime = null): void
    {
        $this->ensureItemVariantsTable($conn);
        if (!$this->tableExists($conn, 'item_variants')) {
            return;
        }

        $parentId = $this->intOrNull($this->firstExistingValue([$item], ['parent_item_id', 'parentItemId']));
        $variantLabel = $this->nullableString($this->firstExistingValue([$item], ['variant_label', 'variantLabel', 'label']), 120);
        if ($parentId && $parentId !== $itemId && $variantLabel !== null) {
            $this->upsertItemVariantRelation($conn, $parentId, $itemId, $variantLabel, 0, true, false, true);
        }

        $present = false;
        $variants = $this->firstListField($item, ['variants', 'item_variants', 'children'], $present);
        if (!$present) {
            return;
        }

        $activeVariantIds = [];
        foreach ($variants as $index => $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $variantItemId = $this->intOrNull($this->firstExistingValue([$variant], ['variant_item_id', 'item_id', 'local_item_id', 'id']));
            if (!$variantItemId || $variantItemId === $itemId) {
                continue;
            }

            $label = $this->nullableString($this->firstExistingValue([$variant], ['label', 'variant_label', 'name']), 120)
                ?: 'Variant ' . ($index + 1);
            $this->mirrorMenuItem($conn, $variantItemId, [
                'local_item_id' => $variantItemId,
                'item_name' => $this->nullableString($this->firstExistingValue([$variant], ['name', 'item_name', 'iname']), 200)
                    ?: $label,
                'barcode' => $this->nullableString($this->firstExistingValue([$variant], ['barcode', 'bar_code']), 25),
                'price1' => $this->firstExistingValue([$variant], ['price1', 'price', 'sale_price']),
                'price2' => $this->firstValue([$variant], 'price2'),
                'price3' => $this->firstValue([$variant], 'price3'),
                'cost_price' => $this->firstExistingValue([$variant], ['cost_price', 'cost']),
                'category_id' => $this->firstExistingValue([$variant, $item], ['category_id', 'group1']),
                'group2' => $this->firstExistingValue([$variant, $item], ['group2', 'category2_id']),
                'parent_item_id' => $itemId,
                'variant_label' => $label,
                'isdeleted' => 0,
                'legacy' => [
                    'info' => $this->firstValue([$item], 'info'),
                    'user' => $this->firstValue([$item['legacy'] ?? [], $item], 'user'),
                    'tenant' => $this->firstValue([$item['legacy'] ?? [], $item], 'tenant'),
                    'branch' => $this->firstValue([$item['legacy'] ?? [], $item], 'branch'),
                ],
            ], $sourceMdtime);

            $active = $this->boolInt($this->firstExistingValue([$variant], ['is_active', 'active']), 1) === 1;
            $isDefault = $this->boolInt($this->firstExistingValue([$variant], ['is_default', 'default']), 0) === 1;
            $sortOrder = $this->intOrZero($this->firstExistingValue([$variant], ['sort_order', 'sortOrder'])) ?: ($index + 1);
            $this->upsertItemVariantRelation($conn, $itemId, $variantItemId, $label, $sortOrder, $active, $isDefault);
            if ($active) {
                $activeVariantIds[] = $variantItemId;
            }
        }

        $this->deactivateMissingVariants($conn, $itemId, $activeVariantIds);
    }

    private function ensureItemVariantsTable(mysqli $conn): void
    {
        if ($this->tableExists($conn, 'item_variants')) {
            return;
        }

        $conn->query("
            CREATE TABLE IF NOT EXISTS item_variants (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                parent_item_id BIGINT UNSIGNED NOT NULL,
                variant_item_id BIGINT UNSIGNED NOT NULL,
                variant_label VARCHAR(120) NOT NULL,
                variant_name_en VARCHAR(120) NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_item_variant_child (variant_item_id),
                UNIQUE KEY uq_item_variant_parent_child (parent_item_id, variant_item_id),
                KEY idx_item_variants_parent (parent_item_id, is_active, sort_order),
                KEY idx_item_variants_variant (variant_item_id, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    private function upsertItemVariantRelation(
        mysqli $conn,
        int $parentItemId,
        int $variantItemId,
        string $label,
        int $sortOrder,
        bool $active,
        bool $isDefault,
        bool $preserveOrderingOnExisting = false
    ): void
    {
        $activeInt = $active ? 1 : 0;
        $defaultInt = $isDefault ? 1 : 0;
        $nameEn = null;
        $sortOrderUpdate = $preserveOrderingOnExisting ? 'sort_order = item_variants.sort_order' : 'sort_order = VALUES(sort_order)';
        $defaultUpdate = $preserveOrderingOnExisting ? 'is_default = item_variants.is_default' : 'is_default = VALUES(is_default)';
        $stmt = $conn->prepare("
            INSERT INTO item_variants (
                parent_item_id, variant_item_id, variant_label, variant_name_en, sort_order, is_default, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                parent_item_id = VALUES(parent_item_id),
                variant_label = VALUES(variant_label),
                variant_name_en = VALUES(variant_name_en),
                {$sortOrderUpdate},
                {$defaultUpdate},
                is_active = VALUES(is_active),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->bind_param('iissiii', $parentItemId, $variantItemId, $label, $nameEn, $sortOrder, $defaultInt, $activeInt);
        $stmt->execute();
        $stmt->close();
    }

    private function deactivateMissingVariants(mysqli $conn, int $parentItemId, array $activeVariantIds): void
    {
        if (!$this->tableExists($conn, 'item_variants')) {
            return;
        }

        if (!$activeVariantIds) {
            $stmt = $conn->prepare('UPDATE item_variants SET is_active = 0, is_default = 0 WHERE parent_item_id = ?');
            $stmt->bind_param('i', $parentItemId);
            $stmt->execute();
            $stmt->close();
            return;
        }

        $activeVariantIds = array_values(array_unique(array_map('intval', $activeVariantIds)));
        $placeholders = implode(',', array_fill(0, count($activeVariantIds), '?'));
        $stmt = $conn->prepare("UPDATE item_variants SET is_active = 0, is_default = 0 WHERE parent_item_id = ? AND variant_item_id NOT IN ({$placeholders})");
        $params = array_merge([$parentItemId], $activeVariantIds);
        $this->bindParams($stmt, str_repeat('s', count($params)), $params);
        $stmt->execute();
        $stmt->close();
    }

    private function mirrorMenuModifiers(mysqli $conn, int $itemId, array $item, ?string $sourceMdtime = null): void
    {
        unset($sourceMdtime);
        if (
            !$this->tableExists($conn, 'modifier_groups')
            || !$this->tableExists($conn, 'modifier_options')
            || !$this->tableExists($conn, 'item_modifier_groups')
        ) {
            return;
        }

        $present = false;
        $groups = $this->firstListField($item, ['modifier_groups', 'modifierGroups', 'modifiers', 'options'], $present);
        if (!$present) {
            return;
        }

        $keptGroupIds = [];
        foreach ($groups as $position => $group) {
            if (!is_array($group)) {
                continue;
            }

            $groupId = $this->idFromKeys($group, ['modifier_group_id', 'group_id', 'local_group_id', 'id']);
            if (!$groupId) {
                continue;
            }

            $nameAr = $this->nullableString($this->firstExistingValue([$group], ['name_ar', 'name', 'title', 'label']), 120)
                ?: 'Modifier Group ' . $groupId;
            $nameEn = $this->nullableString($this->firstExistingValue([$group], ['name_en', 'name2', 'nameEn']), 120);
            $selectionMin = max(0, $this->intOrZero($this->firstExistingValue([$group], ['selection_min', 'min', 'minSelections'])));
            $selectionMax = max(0, $this->intOrZero($this->firstExistingValue([$group], ['selection_max', 'max', 'maxSelections'])));
            $isRequired = $this->boolInt($this->firstExistingValue([$group], ['is_required', 'required']), 0);
            $isActive = $this->boolInt($this->firstExistingValue([$group], ['is_active', 'isActive', 'available']), 1);
            $sortOrder = $this->intOrNull($this->firstExistingValue([$group], ['sort_order', 'sortOrder', 'position']));
            $sortOrder = $sortOrder === null ? ((int) $position + 1) : $sortOrder;

            $params = [$groupId, $nameAr, $nameEn, $selectionMin, $selectionMax, $isRequired, $isActive, $sortOrder];
            $stmt = $conn->prepare("
                INSERT INTO modifier_groups (
                    id, name_ar, name_en, selection_min, selection_max, is_required, is_active, tenant, branch, sort_order
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?)
                ON DUPLICATE KEY UPDATE
                    name_ar = VALUES(name_ar),
                    name_en = VALUES(name_en),
                    selection_min = VALUES(selection_min),
                    selection_max = VALUES(selection_max),
                    is_required = VALUES(is_required),
                    is_active = VALUES(is_active),
                    sort_order = VALUES(sort_order)
            ");
            $this->bindParams($stmt, str_repeat('s', count($params)), $params);
            $stmt->execute();
            $stmt->close();

            $params = [$itemId, $groupId, $sortOrder];
            $stmt = $conn->prepare("
                INSERT INTO item_modifier_groups (item_id, group_id, sort_order)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)
            ");
            $this->bindParams($stmt, str_repeat('s', count($params)), $params);
            $stmt->execute();
            $stmt->close();

            $keptGroupIds[] = $groupId;
            $optionsPresent = false;
            $options = $this->firstListField($group, ['options', 'values', 'items'], $optionsPresent);
            if (!$optionsPresent) {
                continue;
            }

            $keptOptionIds = [];
            foreach ($options as $optionPosition => $option) {
                if (!is_array($option)) {
                    continue;
                }

                $optionId = $this->idFromKeys($option, ['modifier_option_id', 'option_id', 'local_option_id', 'id']);
                if (!$optionId) {
                    continue;
                }

                $optionNameAr = $this->nullableString($this->firstExistingValue([$option], ['name_ar', 'name', 'title', 'label']), 120)
                    ?: 'Modifier Option ' . $optionId;
                $optionNameEn = $this->nullableString($this->firstExistingValue([$option], ['name_en', 'name2', 'nameEn']), 120);
                $priceDelta = $this->decimal($this->firstExistingValue([$option], ['price_delta', 'priceDelta', 'price', 'amount']));
                $optionActive = $this->boolInt($this->firstExistingValue([$option], ['is_active', 'isActive', 'available']), 1);
                $optionSort = $this->intOrNull($this->firstExistingValue([$option], ['sort_order', 'sortOrder', 'position']));
                $optionSort = $optionSort === null ? ((int) $optionPosition + 1) : $optionSort;

                $params = [$optionId, $groupId, $optionNameAr, $optionNameEn, $priceDelta, $optionActive, $optionSort];
                $stmt = $conn->prepare("
                    INSERT INTO modifier_options (
                        id, group_id, name_ar, name_en, price_delta, is_active, sort_order
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        group_id = VALUES(group_id),
                        name_ar = VALUES(name_ar),
                        name_en = VALUES(name_en),
                        price_delta = VALUES(price_delta),
                        is_active = VALUES(is_active),
                        sort_order = VALUES(sort_order)
                ");
                $this->bindParams($stmt, str_repeat('s', count($params)), $params);
                $stmt->execute();
                $stmt->close();
                $keptOptionIds[] = $optionId;
            }
            $this->deactivateMissingModifierOptions($conn, $groupId, $keptOptionIds);
        }

        $this->removeMissingItemModifierLinks($conn, $itemId, $keptGroupIds);
    }

    private function mirrorLineCustomizations(mysqli $conn, int $orderId, int $lineId, array $line): void
    {
        $modifiersPresent = false;
        $modifiers = $this->firstListField($line, ['modifiers', 'modifier_options', 'selected_modifiers'], $modifiersPresent);
        if ($modifiersPresent && $this->tableExists($conn, 'order_line_modifiers')) {
            $delete = $conn->prepare('DELETE FROM order_line_modifiers WHERE order_id = ? AND detail_id = ?');
            $delete->bind_param('ii', $orderId, $lineId);
            $delete->execute();
            $delete->close();

            if ($modifiers) {
                $insert = $conn->prepare("
                    INSERT INTO order_line_modifiers (
                        order_id, detail_id, modifier_group_id, modifier_option_id, qty, price_delta
                    ) VALUES (?, ?, ?, ?, ?, ?)
                ");
                foreach ($modifiers as $modifier) {
                    if (!is_array($modifier)) {
                        continue;
                    }

                    $optionId = $this->idFromKeys($modifier, ['modifier_option_id', 'option_id', 'id']);
                    if (!$optionId) {
                        continue;
                    }
                    $groupId = $this->idFromKeys($modifier, ['modifier_group_id', 'group_id'])
                        ?: $this->modifierGroupIdForOption($conn, $optionId)
                        ?: 0;
                    $qty = $this->decimal($this->firstExistingValue([$modifier], ['qty', 'quantity']));
                    if ((float) $qty <= 0) {
                        $qty = '1.0000';
                    }
                    $priceDelta = $this->decimal($this->firstExistingValue([$modifier], ['price_delta', 'priceDelta', 'delta']));
                    $insert->bind_param('iiiiss', $orderId, $lineId, $groupId, $optionId, $qty, $priceDelta);
                    $insert->execute();
                }
                $insert->close();
            }
        }

        $notesPresent = false;
        $notes = $this->firstListField($line, ['notes', 'line_notes'], $notesPresent);
        if (!$notesPresent) {
            $noteText = $this->nullableString($this->firstExistingValue([$line], ['note', 'kitchen_note', 'line_note']), 500);
            if ($noteText !== null) {
                $notesPresent = true;
                $notes = [['note_type' => 'kitchen', 'note_text' => $noteText]];
            }
        }

        if ($notesPresent && $this->tableExists($conn, 'order_line_notes')) {
            $delete = $conn->prepare('DELETE FROM order_line_notes WHERE order_id = ? AND detail_id = ?');
            $delete->bind_param('ii', $orderId, $lineId);
            $delete->execute();
            $delete->close();

            if ($notes) {
                $insert = $conn->prepare("
                    INSERT INTO order_line_notes (order_id, detail_id, note_type, note_text, created_by)
                    VALUES (?, ?, ?, ?, ?)
                ");
                foreach ($notes as $note) {
                    if (is_array($note)) {
                        $noteType = $this->lineNoteType($this->firstExistingValue([$note], ['note_type', 'type']));
                        $noteText = $this->nullableString($this->firstExistingValue([$note], ['note_text', 'text', 'note']), 500);
                        $createdBy = $this->intOrNull($this->firstExistingValue([$note], ['created_by', 'user_id']));
                    } else {
                        $noteType = 'kitchen';
                        $noteText = $this->nullableString($note, 500);
                        $createdBy = null;
                    }
                    if ($noteText === null) {
                        continue;
                    }
                    $insert->bind_param('iissi', $orderId, $lineId, $noteType, $noteText, $createdBy);
                    $insert->execute();
                }
                $insert->close();
            }
        }
    }

    private function deactivateMissingModifierOptions(mysqli $conn, int $groupId, array $keptOptionIds): void
    {
        if (!$keptOptionIds) {
            $stmt = $conn->prepare('UPDATE modifier_options SET is_active = 0 WHERE group_id = ?');
            $stmt->bind_param('i', $groupId);
            $stmt->execute();
            $stmt->close();
            return;
        }

        $placeholders = implode(',', array_fill(0, count($keptOptionIds), '?'));
        $params = array_merge([$groupId], $keptOptionIds);
        $stmt = $conn->prepare("UPDATE modifier_options SET is_active = 0 WHERE group_id = ? AND id NOT IN ({$placeholders})");
        $this->bindParams($stmt, str_repeat('s', count($params)), $params);
        $stmt->execute();
        $stmt->close();
    }

    private function removeMissingItemModifierLinks(mysqli $conn, int $itemId, array $keptGroupIds): void
    {
        if (!$keptGroupIds) {
            $stmt = $conn->prepare('DELETE FROM item_modifier_groups WHERE item_id = ?');
            $stmt->bind_param('i', $itemId);
            $stmt->execute();
            $stmt->close();
            return;
        }

        $placeholders = implode(',', array_fill(0, count($keptGroupIds), '?'));
        $params = array_merge([$itemId], $keptGroupIds);
        $stmt = $conn->prepare("DELETE FROM item_modifier_groups WHERE item_id = ? AND group_id NOT IN ({$placeholders})");
        $this->bindParams($stmt, str_repeat('s', count($params)), $params);
        $stmt->execute();
        $stmt->close();
    }

    private function ensureCategory(mysqli $conn, int $categoryId, array $item): void
    {
        $categoryName = $this->nullableString($this->firstExistingValue([$item], ['category_name', 'group_name', 'gname']), 100);
        if ($categoryName === null) {
            return;
        }

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
        $payload = $this->payload($event);
        $snapshotType = strtolower(trim((string) ($payload['snapshot_type'] ?? '')));
        if (in_array($snapshotType, ['operational_row', 'operational_delete', 'recipe_bundle'], true)) {
            return false;
        }

        foreach (['aggregate_type', 'entity_type'] as $key) {
            if (strtolower(trim((string) ($event[$key] ?? ''))) === 'table') {
                return true;
            }
        }

        $eventType = strtolower(trim((string) ($event['event_type'] ?? '')));
        if (strpos($eventType, 'table.') === 0 || strpos($eventType, 'tables.') === 0) {
            return true;
        }

        if ($snapshotType === 'pos_table') {
            return true;
        }

        if (isset($payload['table']) && is_array($payload['table'])) {
            return true;
        }

        return array_key_exists('table_uuid', $payload)
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

    private function firstListField(array $source, array $keys, bool &$present): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                $present = true;
                return is_array($source[$key]) ? $source[$key] : [];
            }
        }

        $present = false;
        return [];
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

    private function idFromKeys(array $source, array $keys): ?int
    {
        $value = $this->firstExistingValue([$source], $keys);
        $id = $this->intOrNull($value);
        if ($id !== null && $id > 0) {
            return $id;
        }

        if (is_string($value) && preg_match('/(\d+)$/', $value, $matches)) {
            $id = (int) $matches[1];
            return $id > 0 ? $id : null;
        }

        return null;
    }

    private function modifierGroupIdForOption(mysqli $conn, int $optionId): ?int
    {
        if (!$this->tableExists($conn, 'modifier_options')) {
            return null;
        }

        $stmt = $conn->prepare('SELECT group_id FROM modifier_options WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $optionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? $this->intOrNull($row['group_id'] ?? null) : null;
    }

    private function lineNoteType($value): string
    {
        $type = strtolower(trim((string) $value));
        return in_array($type, ['kitchen', 'cashier', 'customer'], true) ? $type : 'kitchen';
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

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, $this->columnExistsCache)) {
            return $this->columnExistsCache[$key];
        }

        $tableEscaped = $conn->real_escape_string($table);
        $columnEscaped = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$tableEscaped}` LIKE '{$columnEscaped}'");
        $exists = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }

        $this->columnExistsCache[$key] = $exists;
        return $exists;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $tableEscaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$tableEscaped}'");
        $exists = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }

        return $exists;
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
