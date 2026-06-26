<?php

require_once __DIR__ . '/InventoryDecimal.php';
require_once __DIR__ . '/InventoryFeatureFlags.php';
require_once __DIR__ . '/InventoryItemPolicyService.php';
require_once __DIR__ . '/InventoryScopeResolver.php';

class InventoryStockLevelService
{
    private InventoryFeatureFlags $flags;
    private InventoryScopeResolver $scopeResolver;
    private InventoryItemPolicyService $itemPolicy;

    public function __construct(
        ?InventoryFeatureFlags $flags = null,
        ?InventoryScopeResolver $scopeResolver = null,
        ?InventoryItemPolicyService $itemPolicy = null
    ) {
        $this->flags = $flags ?: new InventoryFeatureFlags();
        $this->scopeResolver = $scopeResolver ?: new InventoryScopeResolver($this->flags->appConfig());
        $this->itemPolicy = $itemPolicy ?: new InventoryItemPolicyService();
    }

    public function save(mysqli $conn, array $request, array $context = []): array
    {
        $this->assertTables($conn);
        $normalized = $this->normalizeRequest($conn, $request, $context);
        $before = $this->findLevel($conn, $normalized['scope'], (int) $normalized['item_id']);
        if ($this->stockLevelChangeNeedsApproval($conn, $normalized) && empty($context['allow_policy_approval'])) {
            throw new InvalidArgumentException('STOCK_LEVEL_APPROVAL_REQUIRED');
        }

        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $hasDefaultSupplierColumn = $this->columnExists($conn, 'inventory_item_stock_levels', 'default_supplier_account_id');
            if ($hasDefaultSupplierColumn) {
                $stmt = $conn->prepare("
INSERT INTO inventory_item_stock_levels
  (pos_tenant, pos_branch, branch_uuid, store_id, item_id, minimum_level, reorder_level, par_level, maximum_level, safety_stock_qty, preferred_count_unit_id, preferred_purchase_unit_id, default_supplier_account_id, is_active, created_by, updated_by)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
  branch_uuid = VALUES(branch_uuid),
  minimum_level = VALUES(minimum_level),
  reorder_level = VALUES(reorder_level),
  par_level = VALUES(par_level),
  maximum_level = VALUES(maximum_level),
  safety_stock_qty = VALUES(safety_stock_qty),
  preferred_count_unit_id = VALUES(preferred_count_unit_id),
  preferred_purchase_unit_id = VALUES(preferred_purchase_unit_id),
  default_supplier_account_id = VALUES(default_supplier_account_id),
  is_active = VALUES(is_active),
  updated_by = VALUES(updated_by)");
            } else {
                $stmt = $conn->prepare("
INSERT INTO inventory_item_stock_levels
  (pos_tenant, pos_branch, branch_uuid, store_id, item_id, minimum_level, reorder_level, par_level, maximum_level, safety_stock_qty, preferred_count_unit_id, preferred_purchase_unit_id, is_active, created_by, updated_by)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
  branch_uuid = VALUES(branch_uuid),
  minimum_level = VALUES(minimum_level),
  reorder_level = VALUES(reorder_level),
  par_level = VALUES(par_level),
  maximum_level = VALUES(maximum_level),
  safety_stock_qty = VALUES(safety_stock_qty),
  preferred_count_unit_id = VALUES(preferred_count_unit_id),
  preferred_purchase_unit_id = VALUES(preferred_purchase_unit_id),
  is_active = VALUES(is_active),
  updated_by = VALUES(updated_by)");
            }
            $scope = $normalized['scope'];
            $posTenant = (int) $scope['pos_tenant'];
            $posBranch = (int) $scope['pos_branch'];
            $branchUuid = $scope['branch_uuid'];
            $storeId = (int) $scope['store_id'];
            $itemId = (int) $normalized['item_id'];
            $minimum = $normalized['minimum_level'];
            $reorder = $normalized['reorder_level'];
            $par = $normalized['par_level'];
            $maximum = $normalized['maximum_level'];
            $safety = $normalized['safety_stock_qty'];
            $preferredCountUnitId = $normalized['preferred_count_unit_id'];
            $preferredPurchaseUnitId = $normalized['preferred_purchase_unit_id'];
            $defaultSupplierAccountId = $normalized['default_supplier_account_id'];
            $isActive = (int) $normalized['is_active'];
            $userId = $this->userId($context);
            if ($hasDefaultSupplierColumn) {
                $stmt->bind_param(
                    'iisiisssssiiiiii',
                    $posTenant,
                    $posBranch,
                    $branchUuid,
                    $storeId,
                    $itemId,
                    $minimum,
                    $reorder,
                    $par,
                    $maximum,
                    $safety,
                    $preferredCountUnitId,
                    $preferredPurchaseUnitId,
                    $defaultSupplierAccountId,
                    $isActive,
                    $userId,
                    $userId
                );
            } else {
                $stmt->bind_param(
                    'iisiisssssiiiii',
                    $posTenant,
                    $posBranch,
                    $branchUuid,
                    $storeId,
                    $itemId,
                    $minimum,
                    $reorder,
                    $par,
                    $maximum,
                    $safety,
                    $preferredCountUnitId,
                    $preferredPurchaseUnitId,
                    $isActive,
                    $userId,
                    $userId
                );
            }
            $stmt->execute();
            $stmt->close();
            $after = $this->findLevel($conn, $scope, $itemId);
            $auditId = $this->recordStockLevelAudit($conn, $before, $after, $context);

            if ($ownsTransaction) {
                $conn->commit();
            }

            return [
                'success' => true,
                'stock_level' => $after,
                'writes' => [
                    'inventory_item_stock_levels' => [(int) ($after['id'] ?? 0)],
                    'recipe_audit_log' => $auditId > 0 ? [$auditId] : [],
                ],
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    public function findLevel(mysqli $conn, array $scope, int $itemId): array
    {
        $stmt = $conn->prepare("
SELECT *
FROM inventory_item_stock_levels
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND store_id = ?
  AND item_id = ?
LIMIT 1");
        $posTenant = (int) ($scope['pos_tenant'] ?? 0);
        $posBranch = (int) ($scope['pos_branch'] ?? 0);
        $storeId = (int) ($scope['store_id'] ?? 0);
        $stmt->bind_param('iiii', $posTenant, $posBranch, $storeId, $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return is_array($row) ? $row : [];
    }

    public function importCsv(mysqli $conn, string $csv, array $context = []): array
    {
        $rows = $this->parseImportCsv($csv);
        if (!$rows) {
            throw new InvalidArgumentException('CSV_EMPTY');
        }
        if (count($rows) > 500) {
            throw new InvalidArgumentException('CSV_TOO_LARGE');
        }

        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $importContext = array_merge($context, ['in_transaction' => true]);
            $imported = 0;
            foreach ($rows as $row) {
                try {
                    $this->save($conn, $row['payload'], $importContext);
                    $imported++;
                } catch (Throwable $exception) {
                    throw new InvalidArgumentException('CSV_ROW_' . $row['line_number'] . '_' . $exception->getMessage(), 0, $exception);
                }
            }

            if ($ownsTransaction) {
                $conn->commit();
            }

            return [
                'success' => true,
                'imported_count' => $imported,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    public function updateCategory(mysqli $conn, array $request, array $context = []): array
    {
        $this->assertTables($conn);
        if (!$this->columnExists($conn, 'myitems', 'group1')) {
            throw new RuntimeException('CATEGORY_UPDATE_NOT_SUPPORTED');
        }

        $categoryId = (int) ($request['category_id'] ?? 0);
        if ($categoryId < 1) {
            throw new InvalidArgumentException('CATEGORY_REQUIRED');
        }

        $itemIds = $this->itemIdsForCategory($conn, $categoryId);
        if (!$itemIds) {
            throw new InvalidArgumentException('CATEGORY_ITEMS_NOT_FOUND');
        }
        if (count($itemIds) > 500) {
            throw new InvalidArgumentException('CATEGORY_TOO_LARGE');
        }

        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $updated = 0;
            $categoryContext = array_merge($context, ['in_transaction' => true, 'preserve_default_supplier' => true]);
            foreach ($itemIds as $itemId) {
                $payload = [
                    'store_id' => $request['store_id'] ?? 0,
                    'item_id' => $itemId,
                    'minimum_level' => $request['minimum_level'] ?? '0',
                    'reorder_level' => $request['reorder_level'] ?? '0',
                    'par_level' => $request['par_level'] ?? '0',
                    'maximum_level' => $request['maximum_level'] ?? '0',
                    'safety_stock_qty' => $request['safety_stock_qty'] ?? '0',
                    'is_active' => $request['is_active'] ?? 1,
                ];
                $this->save($conn, $payload, $categoryContext);
                $updated++;
            }

            if ($ownsTransaction) {
                $conn->commit();
            }

            return [
                'success' => true,
                'category_id' => $categoryId,
                'updated_count' => $updated,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    private function normalizeRequest(mysqli $conn, array $request, array $context): array
    {
        $scope = $this->scopeResolver->resolveForConn($conn,[
            'store_id' => $request['store_id'] ?? 0,
            'pos_tenant' => $context['pos_tenant'] ?? $request['pos_tenant'] ?? null,
            'pos_branch' => $context['pos_branch'] ?? $request['pos_branch'] ?? null,
            'branch_uuid' => $context['branch_uuid'] ?? $request['branch_uuid'] ?? null,
            'source' => 'inventory_stock_level',
        ]);
        if ((int) $scope['store_id'] < 1) {
            throw new InvalidArgumentException('STORE_REQUIRED');
        }

        $itemId = (int) ($request['item_id'] ?? 0);
        if ($itemId < 1) {
            throw new InvalidArgumentException('ITEM_REQUIRED');
        }
        $item = $this->loadItem($conn, $itemId);
        $policy = $this->itemPolicy->policyForItem($item, $scope);
        if (empty($policy['track_stock'])) {
            throw new InvalidArgumentException('NON_STOCK_ITEM_CANNOT_HAVE_LEVELS');
        }

        $minimum = $this->nonNegativeDecimal($request['minimum_level'] ?? '0', 'MINIMUM_LEVEL_INVALID');
        $reorder = $this->nonNegativeDecimal($request['reorder_level'] ?? '0', 'REORDER_LEVEL_INVALID');
        $par = $this->nonNegativeDecimal($request['par_level'] ?? '0', 'PAR_LEVEL_INVALID');
        $maximum = $this->nonNegativeDecimal($request['maximum_level'] ?? '0', 'MAXIMUM_LEVEL_INVALID');
        $safety = $this->nonNegativeDecimal($request['safety_stock_qty'] ?? '0', 'SAFETY_STOCK_INVALID');
        $preferredCountUnitId = $this->nullablePositiveInt($request['preferred_count_unit_id'] ?? null);
        $preferredPurchaseUnitId = $this->nullablePositiveInt($request['preferred_purchase_unit_id'] ?? null);
        $defaultSupplierAccountId = $this->nullablePositiveInt($request['default_supplier_account_id'] ?? null);
        if (!array_key_exists('default_supplier_account_id', $request) && !empty($context['preserve_default_supplier'])) {
            $existing = $this->findLevel($conn, $scope, $itemId);
            $defaultSupplierAccountId = $this->nullablePositiveInt($existing['default_supplier_account_id'] ?? null);
        }
        if ($preferredCountUnitId !== null) {
            $this->assertItemUnit($conn, $itemId, $preferredCountUnitId);
        }
        if ($preferredPurchaseUnitId !== null) {
            $this->assertItemUnit($conn, $itemId, $preferredPurchaseUnitId);
        }
        if ($defaultSupplierAccountId !== null) {
            $this->assertSupplierAccount($conn, $defaultSupplierAccountId);
        }

        if (InventoryDecimal::isPositive($reorder) && InventoryDecimal::isPositive($minimum) && InventoryDecimal::compare($reorder, $minimum) < 0) {
            throw new InvalidArgumentException('REORDER_BELOW_MINIMUM');
        }
        if (InventoryDecimal::isPositive($par) && InventoryDecimal::isPositive($reorder) && InventoryDecimal::compare($par, $reorder) < 0) {
            throw new InvalidArgumentException('PAR_BELOW_REORDER');
        }
        if (InventoryDecimal::isPositive($maximum) && InventoryDecimal::isPositive($par) && InventoryDecimal::compare($maximum, $par) < 0) {
            throw new InvalidArgumentException('MAXIMUM_BELOW_PAR');
        }

        return [
            'scope' => $scope,
            'item_id' => $itemId,
            'minimum_level' => $minimum,
            'reorder_level' => $reorder,
            'par_level' => $par,
            'maximum_level' => $maximum,
            'safety_stock_qty' => $safety,
            'preferred_count_unit_id' => $preferredCountUnitId,
            'preferred_purchase_unit_id' => $preferredPurchaseUnitId,
            'default_supplier_account_id' => $defaultSupplierAccountId,
            'is_active' => empty($request['is_active']) && (string) ($request['is_active'] ?? '1') === '0' ? 0 : 1,
        ];
    }

    private function assertSupplierAccount(mysqli $conn, int $supplierAccountId): void
    {
        if (!$this->tableExists($conn, 'acc_head')) {
            throw new InvalidArgumentException('SUPPLIER_NOT_FOUND');
        }

        $conditions = ['id = ?'];
        if ($this->columnExists($conn, 'acc_head', 'isdeleted')) {
            $conditions[] = 'COALESCE(isdeleted, 0) = 0';
        }
        if ($this->columnExists($conn, 'acc_head', 'is_stock')) {
            $conditions[] = 'COALESCE(is_stock, 0) = 0';
        }

        $stmt = $conn->prepare('SELECT id FROM acc_head WHERE ' . implode(' AND ', $conditions) . ' LIMIT 1');
        $stmt->bind_param('i', $supplierAccountId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new InvalidArgumentException('SUPPLIER_NOT_FOUND');
        }
    }

    private function assertItemUnit(mysqli $conn, int $itemId, int $unitId): void
    {
        if (!$this->tableExists($conn, 'item_units')) {
            throw new InvalidArgumentException('ITEM_UNIT_NOT_FOUND');
        }

        $conditions = ['item_id = ?', 'unit_id = ?'];
        if ($this->columnExists($conn, 'item_units', 'isdeleted')) {
            $conditions[] = 'COALESCE(isdeleted, 0) = 0';
        }
        $stmt = $conn->prepare('SELECT u_val FROM item_units WHERE ' . implode(' AND ', $conditions) . ' ORDER BY id ASC LIMIT 1');
        $stmt->bind_param('ii', $itemId, $unitId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || InventoryDecimal::compare(InventoryDecimal::normalize($row['u_val'] ?? '0', 8), '0', 8) <= 0) {
            throw new InvalidArgumentException('ITEM_UNIT_NOT_FOUND');
        }
    }

    private function nonNegativeDecimal($value, string $code): string
    {
        $decimal = InventoryDecimal::normalize($value ?? '0');
        if (InventoryDecimal::compare($decimal, '0') < 0) {
            throw new InvalidArgumentException($code);
        }

        return $decimal;
    }

    private function loadItem(mysqli $conn, int $itemId): array
    {
        if ($itemId < 1 || !$this->tableExists($conn, 'myitems')) {
            return ['item_id' => $itemId, 'item_type' => 'sellable', 'track_stock' => 0];
        }

        $columns = ['id'];
        foreach (['item_type', 'track_stock', 'base_unit_id'] as $column) {
            if ($this->columnExists($conn, 'myitems', $column)) {
                $columns[] = $column;
            }
        }

        $stmt = $conn->prepare('SELECT ' . implode(', ', $columns) . ' FROM myitems WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $item ?: ['item_id' => $itemId, 'item_type' => 'sellable', 'track_stock' => 0];
    }

    private function assertTables(mysqli $conn): void
    {
        foreach (['inventory_item_stock_levels', 'myitems'] as $table) {
            if (!$this->tableExists($conn, $table)) {
                throw new RuntimeException('INVENTORY_SCHEMA_MISSING_' . strtoupper($table));
            }
        }
    }

    private function stockLevelChangeNeedsApproval(mysqli $conn, array $normalized): bool
    {
        $existing = $this->findLevel($conn, $normalized['scope'], (int) $normalized['item_id']);
        if (!$existing) {
            return false;
        }

        foreach (['minimum_level', 'reorder_level', 'par_level', 'maximum_level', 'safety_stock_qty'] as $field) {
            if (InventoryDecimal::compare(
                InventoryDecimal::normalize($existing[$field] ?? '0'),
                $normalized[$field] ?? '0'
            ) !== 0) {
                return true;
            }
        }

        foreach (['preferred_count_unit_id', 'preferred_purchase_unit_id', 'default_supplier_account_id'] as $field) {
            if ($this->nullablePositiveInt($existing[$field] ?? null) !== ($normalized[$field] ?? null)) {
                return true;
            }
        }

        return (int) ($existing['is_active'] ?? 1) !== (int) ($normalized['is_active'] ?? 1);
    }

    private function recordStockLevelAudit(mysqli $conn, array $before, array $after, array $context): int
    {
        if (!$after || !$this->tableExists($conn, 'recipe_audit_log')) {
            return 0;
        }

        $beforeJson = $before ? json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $afterJson = json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $action = $before ? 'update_inventory_stock_level' : 'create_inventory_stock_level';
        $posTenant = (int) ($after['pos_tenant'] ?? 0);
        $posBranch = (int) ($after['pos_branch'] ?? 0);
        $branchUuid = $after['branch_uuid'] ?? null;
        $entityId = (int) ($after['id'] ?? 0);
        $actorUserId = $this->userId($context);

        $stmt = $conn->prepare("
INSERT INTO recipe_audit_log
  (pos_tenant, pos_branch, branch_uuid, entity_type, entity_id, action, before_json, after_json, actor_user_id)
VALUES (?, ?, ?, 'inventory_stock_level', ?, ?, ?, ?, ?)");
        $stmt->bind_param('iisisssi', $posTenant, $posBranch, $branchUuid, $entityId, $action, $beforeJson, $afterJson, $actorUserId);
        $stmt->execute();
        $auditId = (int) $conn->insert_id;
        $stmt->close();

        return $auditId;
    }

    private function itemIdsForCategory(mysqli $conn, int $categoryId): array
    {
        $conditions = ['group1 = ?'];
        if ($this->columnExists($conn, 'myitems', 'isdeleted')) {
            $conditions[] = 'COALESCE(isdeleted, 0) = 0';
        }
        if ($this->columnExists($conn, 'myitems', 'track_stock')) {
            $conditions[] = 'COALESCE(track_stock, 1) = 1';
        }
        if ($this->columnExists($conn, 'myitems', 'item_type')) {
            $conditions[] = "COALESCE(item_type, 'sellable') <> 'service'";
        }

        $stmt = $conn->prepare('SELECT id FROM myitems WHERE ' . implode(' AND ', $conditions) . ' ORDER BY iname, id LIMIT 501');
        $stmt->bind_param('i', $categoryId);
        $stmt->execute();
        $result = $stmt->get_result();
        $itemIds = [];
        while ($row = $result->fetch_assoc()) {
            $itemIds[] = (int) $row['id'];
        }
        $stmt->close();

        return $itemIds;
    }

    private function parseImportCsv(string $csv): array
    {
        $csv = trim($csv);
        if ($csv === '') {
            return [];
        }

        $handle = fopen('php://temp', 'r+');
        if (!$handle) {
            throw new RuntimeException('CSV_READ_FAILED');
        }
        fwrite($handle, $csv);
        rewind($handle);

        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if (!is_array($header)) {
            fclose($handle);
            return [];
        }

        $headers = array_map(static function ($value): string {
            return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $value)));
        }, $header);
        foreach (['store_id', 'item_id'] as $required) {
            if (!in_array($required, $headers, true)) {
                fclose($handle);
                throw new InvalidArgumentException('CSV_MISSING_' . strtoupper($required));
            }
        }

        $allowed = [
            'store_id',
            'item_id',
            'minimum_level',
            'reorder_level',
            'par_level',
            'maximum_level',
            'safety_stock_qty',
            'preferred_count_unit_id',
            'preferred_purchase_unit_id',
            'default_supplier_account_id',
            'is_active',
        ];
        $rows = [];
        $lineNumber = 1;
        while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $lineNumber++;
            if ($this->csvRowIsBlank($data)) {
                continue;
            }
            $payload = [];
            foreach ($headers as $index => $column) {
                if (!in_array($column, $allowed, true)) {
                    continue;
                }
                $payload[$column] = trim((string) ($data[$index] ?? ''));
            }
            $rows[] = [
                'line_number' => $lineNumber,
                'payload' => $payload,
            ];
        }
        fclose($handle);

        return $rows;
    }

    private function csvRowIsBlank(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function userId(array $context): ?int
    {
        $userId = (int) ($context['user_id'] ?? ($_SESSION['userid'] ?? $_SESSION['user_id'] ?? 0));

        return $userId > 0 ? $userId : null;
    }

    private function nullablePositiveInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare("
SELECT COUNT(*) AS table_count
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['table_count'] ?? 0) > 0;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $stmt = $conn->prepare("
SELECT COUNT(*) AS column_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?
  AND COLUMN_NAME = ?");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['column_count'] ?? 0) > 0;
    }
}
