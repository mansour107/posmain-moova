<?php

require_once __DIR__ . '/DTO/RecipeActorContext.php';
require_once __DIR__ . '/RecipeAccountingService.php';
require_once __DIR__ . '/RecipeAuditService.php';
require_once __DIR__ . '/RecipeDecimal.php';
require_once __DIR__ . '/RecipeFeatureFlags.php';
require_once __DIR__ . '/RecipeInventoryMovementService.php';
require_once __DIR__ . '/RecipePermissionService.php';
require_once __DIR__ . '/RecipeScopeResolver.php';
require_once __DIR__ . '/Repository/InventoryMovementRepository.php';

class RecipeWasteAdjustmentService
{
    private $flags;
    private $scopeResolver;
    private $movements;
    private $accounting;
    private $audit;
    private $permissions;
    private $movementRepository;

    public function __construct(
        ?RecipeFeatureFlags $flags = null,
        ?RecipeScopeResolver $scopeResolver = null,
        ?RecipeInventoryMovementService $movements = null,
        ?RecipeAccountingService $accounting = null,
        ?RecipeAuditService $audit = null,
        ?RecipePermissionService $permissions = null,
        ?InventoryMovementRepository $movementRepository = null
    ) {
        $this->flags = $flags ?: new RecipeFeatureFlags();
        $this->scopeResolver = $scopeResolver ?: new RecipeScopeResolver();
        $this->movements = $movements ?: new RecipeInventoryMovementService($this->flags);
        $this->accounting = $accounting ?: new RecipeAccountingService($this->flags);
        $this->audit = $audit ?: new RecipeAuditService();
        $this->permissions = $permissions ?: new RecipePermissionService();
        $this->movementRepository = $movementRepository ?: new InventoryMovementRepository();
    }

    public function handle(mysqli $conn, string $action, array $input, RecipeActorContext $actor): array
    {
        $action = strtolower(trim($action));
        if ($action === 'record_waste') {
            return $this->recordWaste($conn, $input, $actor);
        }
        if ($action === 'record_adjustment') {
            return $this->recordAdjustment($conn, $input, $actor);
        }

        throw new InvalidArgumentException('Unsupported recipe waste/adjustment action.');
    }

    public function recordWaste(mysqli $conn, array $input, RecipeActorContext $actor): array
    {
        $this->assertWritesEnabled();
        $this->permissions->assertCanEdit($actor);
        $this->assertBackdatedAllowed($input, $actor);

        $context = $this->baseContext($input, $actor, 'waste');
        $context['qty'] = $this->positiveDecimal($input['qty'] ?? '0', 'Waste quantity is required.');
        $context['unit_cost'] = $this->nonNegativeDecimal($input['unit_cost'] ?? '0', 'Waste unit cost must be zero or positive.');
        $context['total_cost'] = array_key_exists('total_cost', $input) && trim((string) $input['total_cost']) !== ''
            ? $this->nonNegativeDecimal($input['total_cost'], 'Waste total cost must be zero or positive.')
            : RecipeDecimal::multiply($context['qty'], $context['unit_cost']);
        $context['reason'] = $this->requiredText($input['reason'] ?? '', 'Waste reason is required.');
        $context['details'] = 'Recipe waste: ' . $context['reason'];
        $context['waste_uuid'] = $this->sourceUuid($input, 'waste_uuid');
        $context['idempotency_key'] = $this->idempotencyKey($context, 'waste', $context['waste_uuid']);

        return $this->writeMovement($conn, $context, $actor, 'waste');
    }

    public function recordAdjustment(mysqli $conn, array $input, RecipeActorContext $actor): array
    {
        $this->assertWritesEnabled();
        $this->permissions->assertCanEdit($actor);
        $this->assertBackdatedAllowed($input, $actor);

        $direction = strtolower(trim((string) ($input['direction'] ?? '')));
        if (!in_array($direction, ['increase', 'decrease'], true)) {
            throw new InvalidArgumentException('Stock adjustment direction must be increase or decrease.');
        }

        $context = $this->baseContext($input, $actor, 'adjustment');
        $qty = $this->positiveDecimal($input['qty'] ?? '0', 'Stock adjustment quantity is required.');
        $context['qty_in'] = $direction === 'increase' ? $qty : '0.000000';
        $context['qty_out'] = $direction === 'decrease' ? $qty : '0.000000';
        $context['direction'] = $direction;
        $context['unit_cost'] = $this->nonNegativeDecimal($input['unit_cost'] ?? '0', 'Stock adjustment unit cost must be zero or positive.');
        $context['total_cost'] = array_key_exists('total_cost', $input) && trim((string) $input['total_cost']) !== ''
            ? $this->nonNegativeDecimal($input['total_cost'], 'Stock adjustment total cost must be zero or positive.')
            : RecipeDecimal::multiply($qty, $context['unit_cost']);
        $context['reason'] = $this->requiredText($input['reason'] ?? '', 'Stock adjustment reason is required.');
        $context['details'] = 'Recipe stock adjustment: ' . $context['reason'];
        $context['adjustment_uuid'] = $this->sourceUuid($input, 'adjustment_uuid');
        $context['idempotency_key'] = $this->idempotencyKey($context, 'adjustment', $context['adjustment_uuid']);

        return $this->writeMovement($conn, $context, $actor, 'adjustment');
    }

    private function writeMovement(mysqli $conn, array $context, RecipeActorContext $actor, string $type): array
    {
        $existing = $this->movementRepository->findByIdempotencyKey(
            $conn,
            (int) $context['pos_tenant'],
            (int) $context['pos_branch'],
            (int) $context['store_id'],
            (string) $context['idempotency_key']
        );

        $conn->begin_transaction();
        try {
            $movementResult = $type === 'waste'
                ? $this->movements->recordWaste($conn, $context)
                : $this->movements->recordAdjustment($conn, $context);
            $movementIds = $movementResult->movementIds;
            $journal = $this->postAccountingIfNeeded($conn, $context, $movementIds, $type);

            $auditId = null;
            if (!$existing && !$movementResult->noop && $movementIds) {
                $auditId = $this->audit->record(
                    $conn,
                    $actor,
                    $type === 'waste' ? 'record_waste' : 'record_stock_adjustment',
                    'inventory_movement',
                    (int) $movementIds[0],
                    isset($context['recipe_id']) ? (int) $context['recipe_id'] : null,
                    null,
                    $this->auditPayload($context, $movementIds, $journal)
                );
            }

            $conn->commit();

            return [
                'success' => true,
                'noop' => $movementResult->noop,
                'existing' => (bool) $existing,
                'movement_ids' => $movementIds,
                'journal' => $journal,
                'audit_id' => $auditId,
                'message' => $type === 'waste' ? 'Waste recorded.' : 'Stock adjustment recorded.',
            ];
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    private function postAccountingIfNeeded(mysqli $conn, array $context, array $movementIds, string $type): array
    {
        $scope = $this->scopeResolver->resolve($context);
        if (
            $this->flags->isAccountingEnabledForItem($scope, (int) $context['item_id'], $this->itemCategoryId($context))
            && !RecipeDecimal::isPositive($context['total_cost'] ?? '0')
        ) {
            throw new InvalidArgumentException('Recipe accounting requires a positive unit cost or total cost for waste and stock adjustments.');
        }

        if ($type === 'waste') {
            return $this->accounting->postWaste($conn, $context, $movementIds);
        }

        return $this->accounting->postStockAdjustment($conn, $context, $movementIds);
    }

    private function baseContext(array $input, RecipeActorContext $actor, string $sourceType): array
    {
        $scope = $this->scopeResolver->resolve(array_merge([
            'pos_tenant' => $actor->posTenant,
            'pos_branch' => $actor->posBranch,
            'branch_uuid' => $actor->branchUuid,
        ], $input));
        $itemId = (int) ($input['item_id'] ?? 0);
        if ($itemId < 1) {
            throw new InvalidArgumentException('Item is required.');
        }

        return [
            'pos_tenant' => $scope->posTenant,
            'pos_branch' => $scope->posBranch,
            'branch_uuid' => $scope->branchUuid,
            'store_id' => $scope->storeId,
            'channel' => $scope->channel,
            'order_type' => $scope->orderType,
            'source_type' => 'manual',
            'source_system' => $sourceType,
            'item_id' => $itemId,
            'item_category_id' => $this->itemCategoryId($input),
            'unit_id' => isset($input['unit_id']) && (int) $input['unit_id'] > 0 ? (int) $input['unit_id'] : null,
            'unit_conversion_to_base' => $this->positiveDecimal($input['unit_conversion_to_base'] ?? '1', 'Unit conversion must be positive.', 8),
            'inventory_account_id' => (int) ($input['inventory_account_id'] ?? 0),
            'raw_inventory_account_id' => (int) ($input['raw_inventory_account_id'] ?? 0),
            'prepared_inventory_account_id' => (int) ($input['prepared_inventory_account_id'] ?? 0),
            'packaging_inventory_account_id' => (int) ($input['packaging_inventory_account_id'] ?? 0),
            'waste_expense_account_id' => (int) ($input['waste_expense_account_id'] ?? 0),
            'production_variance_account_id' => (int) ($input['production_variance_account_id'] ?? 0),
            'created_by' => $actor->userId,
            'user_id' => $actor->userId,
            'occurred_at' => $this->optionalDateTime($input['occurred_at'] ?? null),
        ];
    }

    private function auditPayload(array $context, array $movementIds, array $journal): array
    {
        return [
            'pos_tenant' => $context['pos_tenant'],
            'pos_branch' => $context['pos_branch'],
            'branch_uuid' => $context['branch_uuid'],
            'store_id' => $context['store_id'],
            'item_id' => $context['item_id'],
            'movement_ids' => $movementIds,
            'idempotency_key' => $context['idempotency_key'],
            'reason' => $context['reason'] ?? '',
            'qty' => $context['qty'] ?? null,
            'qty_in' => $context['qty_in'] ?? null,
            'qty_out' => $context['qty_out'] ?? null,
            'occurred_at' => $context['occurred_at'] ?? null,
            'journal_head_id' => $journal['journal_head_id'] ?? null,
        ];
    }

    private function assertWritesEnabled(): void
    {
        if (!$this->flags->isEnabled() || in_array($this->flags->mode(), ['schema_only', 'read_only'], true)) {
            throw new RuntimeException('Recipe waste/adjustment writes are disabled by feature flags.');
        }
    }

    private function itemCategoryId(array $context): ?int
    {
        $categoryId = (int) (
            $context['item_category_id']
            ?? $context['sellable_item_category_id']
            ?? $context['category_id']
            ?? $context['group1']
            ?? 0
        );

        return $categoryId > 0 ? $categoryId : null;
    }

    private function assertBackdatedAllowed(array $input, RecipeActorContext $actor): void
    {
        $occurredAt = $this->optionalDateTime($input['occurred_at'] ?? null);
        if ($occurredAt === '') {
            return;
        }

        $date = substr($occurredAt, 0, 10);
        if ($date < date('Y-m-d')) {
            $this->permissions->assertCanApprove($actor);
        }
    }

    private function idempotencyKey(array $context, string $type, string $sourceUuid): string
    {
        return $type . ':'
            . (int) $context['pos_tenant'] . ':'
            . (int) $context['pos_branch'] . ':store:'
            . (int) $context['store_id'] . ':item:'
            . (int) $context['item_id'] . ':source:'
            . $sourceUuid;
    }

    private function sourceUuid(array $input, string $key): string
    {
        $uuid = trim((string) ($input[$key] ?? $input['source_uuid'] ?? ''));
        if ($uuid === '') {
            throw new InvalidArgumentException('A source UUID is required for idempotent recipe waste/adjustment writes.');
        }

        return $uuid;
    }

    private function requiredText($value, string $message): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new InvalidArgumentException($message);
        }

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, 255, 'UTF-8');
        }

        return substr($text, 0, 255);
    }

    private function optionalDateTime($value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            return $text . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $text) === 1) {
            return str_replace('T', ' ', strlen($text) === 16 ? $text . ':00' : $text);
        }

        throw new InvalidArgumentException('Operation date must be a valid date or datetime.');
    }

    private function positiveDecimal($value, string $message, int $scale = 6): string
    {
        $normalized = RecipeDecimal::normalize($value, $scale);
        if (!RecipeDecimal::isPositive($normalized)) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
    }

    private function nonNegativeDecimal($value, string $message): string
    {
        $normalized = RecipeDecimal::normalize($value);
        if (RecipeDecimal::compare($normalized, '0') < 0) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
    }
}
