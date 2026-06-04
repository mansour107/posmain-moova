<?php

require_once __DIR__ . '/DTO/RecipeCostContext.php';
require_once __DIR__ . '/DTO/RecipeExplosionResult.php';
require_once __DIR__ . '/DTO/RecipeOrderLineContext.php';
require_once __DIR__ . '/RecipeAccountingService.php';
require_once __DIR__ . '/RecipeAvailabilityService.php';
require_once __DIR__ . '/RecipeCostService.php';
require_once __DIR__ . '/RecipeExplosionService.php';
require_once __DIR__ . '/RecipeFeatureFlags.php';
require_once __DIR__ . '/RecipeInventoryMovementService.php';
require_once __DIR__ . '/RecipeReservationService.php';
require_once __DIR__ . '/RecipeScopeResolver.php';
require_once __DIR__ . '/RecipeSettingsService.php';
require_once __DIR__ . '/../Sync/SyncOutboxEventService.php';
require_once __DIR__ . '/Repository/InventoryMovementRepository.php';
require_once __DIR__ . '/Repository/RecipeOrderLineUsageRepository.php';

class RecipeOrderLifecycleService
{
    private RecipeFeatureFlags $flags;
    private RecipeScopeResolver $scopeResolver;
    private RecipeExplosionService $explosionService;
    private RecipeCostService $costService;
    private RecipeOrderLineUsageRepository $usageRepository;
    private InventoryMovementRepository $movementRepository;
    private RecipeReservationService $reservationService;
    private RecipeInventoryMovementService $movementService;
    private RecipeAccountingService $accountingService;
    private RecipeAvailabilityService $availabilityService;
    private SyncOutboxEventService $syncOutbox;
    private RecipeSettingsService $settings;
    private array $itemCategoryCache = [];

    public function __construct(
        ?RecipeFeatureFlags $flags = null,
        ?RecipeScopeResolver $scopeResolver = null,
        ?RecipeExplosionService $explosionService = null,
        ?RecipeCostService $costService = null,
        ?RecipeOrderLineUsageRepository $usageRepository = null,
        ?InventoryMovementRepository $movementRepository = null,
        ?RecipeReservationService $reservationService = null,
        ?RecipeInventoryMovementService $movementService = null,
        ?RecipeAccountingService $accountingService = null,
        ?RecipeAvailabilityService $availabilityService = null,
        ?SyncOutboxEventService $syncOutbox = null,
        ?RecipeSettingsService $settings = null
    ) {
        $this->flags = $flags ?: new RecipeFeatureFlags();
        $this->scopeResolver = $scopeResolver ?: new RecipeScopeResolver();
        $this->explosionService = $explosionService ?: new RecipeExplosionService($this->flags);
        $this->costService = $costService ?: new RecipeCostService(null, null, $this->explosionService);
        $this->usageRepository = $usageRepository ?: new RecipeOrderLineUsageRepository();
        $this->movementRepository = $movementRepository ?: new InventoryMovementRepository();
        $this->reservationService = $reservationService ?: new RecipeReservationService($this->flags);
        $this->movementService = $movementService ?: new RecipeInventoryMovementService($this->flags);
        $this->accountingService = $accountingService ?: new RecipeAccountingService($this->flags, null, $this->movementRepository);
        $this->availabilityService = $availabilityService ?: new RecipeAvailabilityService($this->flags, $this->explosionService);
        $this->syncOutbox = $syncOutbox ?: new SyncOutboxEventService();
        $this->settings = $settings ?: new RecipeSettingsService($this->flags->appConfig());
    }

    public function onOrderLineAdded($ctx): array
    {
        $conn = $this->connectionFromContext($ctx);
        if (!$conn || !$this->flags->isEnabled() || $this->flags->mode() === 'read_only' || $this->flags->mode() === 'schema_only') {
            return $this->noopResult('order_line_added', $ctx);
        }

        $lineContext = new RecipeOrderLineContext((array) $ctx);
        $explosion = $this->explosionService->explodeOrderLine($conn, $lineContext);
        if (!$explosion->hasRecipe) {
            return $this->result('order_line_added', $ctx, true, [], $explosion->warnings, $explosion->toArray());
        }
        $this->assertStrictAvailability($conn, $lineContext);

        $itemCategoryId = $this->itemCategoryId($conn, $lineContext->sellableItemId, $lineContext->itemCategoryId);
        $scope = $this->scopeResolver->resolve($this->contextArray($lineContext));
        $inReservationScope = $this->flags->isReservationEnabledForItem(
            $scope,
            $lineContext->sellableItemId,
            $itemCategoryId
        );
        $inConsumptionScope = $this->flags->isConsumptionEnabledForItem(
            $scope,
            $lineContext->sellableItemId,
            $itemCategoryId
        );
        if ($this->flags->isReservationEnabled() && !$inReservationScope && !$inConsumptionScope) {
            return $this->result('order_line_added', $this->contextArray($lineContext), true, [], $explosion->warnings, $explosion->toArray());
        }

        $usage = $this->ensureUsage($conn, $lineContext, $explosion, 'previewed');
        $writes = [
            'recipe_order_line_usage' => [(int) $usage['id']],
        ];

        if ($this->flags->isReservationEnabled()) {
            $reservationContext = $this->lineOrderContext($lineContext, (int) $usage['id']);
            $reservationContext['item_category_id'] = $itemCategoryId;
            $reservationResult = $this->reservationService->reserveExplosion(
                $conn,
                $explosion,
                $reservationContext
            );
            if (!$reservationResult->noop) {
                $this->usageRepository->updateUsage($conn, (int) $usage['id'], [
                    'status' => 'reserved',
                ]);
            }
            $writes['stock_reservations'] = $reservationResult->reservationIds;
            $writes['inventory_movements'] = $reservationResult->movementIds;
        }
        $this->refreshAvailabilityCache($conn, $lineContext);

        return $this->result('order_line_added', $ctx, false, $writes, $explosion->warnings, $explosion->toArray());
    }

    public function onOrderLineUpdated($oldCtx, $newCtx): array
    {
        $cancel = $this->onOrderLineCancelled($oldCtx, 'line_updated');
        $add = $this->onOrderLineAdded($newCtx);

        return [
            'success' => $cancel['success'] && $add['success'],
            'action' => 'order_line_updated',
            'mode' => $this->flags->mode(),
            'recipe_enabled' => $this->flags->isEnabled(),
            'noop' => $cancel['noop'] && $add['noop'],
            'writes' => array_merge_recursive($cancel['writes'], $add['writes']),
            'warnings' => array_merge($cancel['warnings'], $add['warnings']),
            'scope' => $add['scope'],
        ];
    }

    public function onOrderLineCancelled($ctx, string $reason): array
    {
        $conn = $this->connectionFromContext($ctx);
        if (!$conn || !$this->flags->isEnabled()) {
            return $this->noopResult('order_line_cancelled', ['context' => $ctx, 'reason' => $reason]);
        }

        $context = new RecipeOrderLineContext((array) $ctx);
        $targetUsages = $this->cancelTargetUsages($conn, $context);
        $usageIds = array_map(static function (array $usage): int {
            return (int) ($usage['id'] ?? 0);
        }, $targetUsages);

        if ($this->flags->isReservationEnabled()) {
            $release = $context->sourceLineUuid !== null
                ? $this->reservationService->releaseForUsageIds($conn, $usageIds, $reason)
                : $this->reservationService->releaseForOrderLine(
                    $conn,
                    (int) $context->orderId,
                    $context->fatDetailId,
                    $context->orderLineUuid,
                    $reason
                );
        } else {
            $release = new RecipeMovementResult(['noop' => true]);
        }

        foreach ($targetUsages as $usage) {
            if (!in_array((string) ($usage['status'] ?? ''), ['previewed', 'reserved'], true)) {
                continue;
            }
            $this->usageRepository->updateUsage($conn, (int) $usage['id'], [
                'status' => 'released',
                'released_at' => date('Y-m-d H:i:s'),
            ]);
        }
        $this->refreshAvailabilityCache($conn, $context);

        return $this->result(
            'order_line_cancelled',
            (array) $ctx,
            $release->noop,
            [
                'stock_reservations' => $release->reservationIds,
                'inventory_movements' => $release->movementIds,
            ],
            [],
            null
        );
    }

    private function cancelTargetUsages(mysqli $conn, RecipeOrderLineContext $context): array
    {
        if ($context->sourceLineUuid !== null) {
            return $this->usageRepository->findForExternalSourceLine(
                $conn,
                $context->posTenant,
                $context->posBranch,
                (int) $context->orderId,
                $context->sourceOrderUuid,
                $context->sourceLineUuid
            );
        }

        return $this->usageRepository->findForOrderLine(
            $conn,
            (int) $context->orderId,
            $context->fatDetailId,
            $context->orderLineUuid
        );
    }

    public function onOrderPaid($order): array
    {
        $conn = $this->connectionFromContext($order);
        if (!$conn || !$this->flags->isEnabled()) {
            return $this->noopResult('order_paid', $order);
        }

        $orderId = (int) (((array) $order)['order_id'] ?? 0);
        $pendingUsageResult = $this->consumePendingUsagesForOrder($conn, (array) $order, $orderId);
        if ($pendingUsageResult !== null) {
            return $pendingUsageResult;
        }

        $lines = $this->orderLines((array) $order);
        if (!$lines) {
            return $this->noopResult('order_paid', $order);
        }

        $writes = [
            'recipe_order_line_usage' => [],
            'recipe_cost_snapshots' => [],
            'inventory_movements' => [],
            'stock_reservations' => [],
            'accounting_journals' => [],
        ];
        $warnings = [];
        $paidAny = false;

        foreach ($lines as $line) {
            $lineContext = new RecipeOrderLineContext(array_merge((array) $order, $line));
            if ($this->hasConsumedExternalUsageForLine($conn, $lineContext)) {
                continue;
            }
            $itemCategoryId = $this->itemCategoryId($conn, $lineContext->sellableItemId, $lineContext->itemCategoryId);
            if (!$this->flags->isConsumptionEnabledForItem(
                $this->scopeResolver->resolve($this->contextArray($lineContext)),
                $lineContext->sellableItemId,
                $itemCategoryId
            )) {
                continue;
            }

            $explosion = $this->explosionService->explodeOrderLine($conn, $lineContext);
            if (!$explosion->hasRecipe) {
                $warnings[] = 'No active recipe for item ' . $lineContext->sellableItemId . '.';
                continue;
            }
            $this->assertStrictAvailability($conn, $lineContext);

            $snapshot = $this->costService->getOrCreateOrderSnapshot($conn, (int) $explosion->recipeId, new RecipeCostContext([
                'pos_tenant' => $lineContext->posTenant,
                'pos_branch' => $lineContext->posBranch,
                'branch_uuid' => $lineContext->branchUuid,
                'store_id' => $lineContext->storeId,
                'order_type' => $lineContext->orderType,
                'channel' => $lineContext->channel,
                'modifiers' => $lineContext->modifiers,
                'calculated_at' => date('Y-m-d H:i:s'),
            ]), $lineContext->sellableItemId);
            $explosion->costSnapshotId = (int) $snapshot['id'];
            $this->applySnapshotCosts($explosion, $snapshot);

            $usage = $this->ensureUsage($conn, $lineContext, $explosion, 'consumed', $snapshot);
            $movementContext = $this->lineOrderContext($lineContext, (int) $usage['id']);
            $movementContext['item_category_id'] = $itemCategoryId;
            $activeReservations = $this->reservationService->activeForOrderLine(
                $conn,
                (int) $lineContext->orderId,
                $lineContext->fatDetailId,
                $lineContext->orderLineUuid
            );
            $movementContext['consume_reserved'] = (bool) $activeReservations;
            $movementResult = $this->movementService->recordRecipeConsumption($conn, $explosion, $movementContext);
            if ($movementResult->movementIds && $this->flags->isAccountingEnabledForItem(
                $this->scopeResolver->resolve($this->contextArray($lineContext)),
                $lineContext->sellableItemId,
                $itemCategoryId
            )) {
                $accounting = $this->accountingService->postSaleCogs(
                    $conn,
                    array_merge((array) $order, $line, $movementContext, [
                        'sellable_item_id' => $lineContext->sellableItemId,
                        'item_category_id' => $itemCategoryId,
                        'recipe_inventory_account_type' => $this->inventoryAccountType($explosion),
                    ]),
                    $movementResult->movementIds
                );
                if (!empty($accounting['journal_head_id'])) {
                    $writes['accounting_journals'][] = (int) $accounting['journal_head_id'];
                }
            }
            $reservationResult = $this->reservationService->consumeForOrderLine(
                $conn,
                (int) $lineContext->orderId,
                $lineContext->fatDetailId,
                $lineContext->orderLineUuid
            );
            $this->usageRepository->updateUsage($conn, (int) $usage['id'], [
                'status' => 'consumed',
                'consumed_at' => date('Y-m-d H:i:s'),
                'recipe_cost_snapshot_id' => (int) $snapshot['id'],
                'cost_total' => $this->explosionCostTotal($explosion),
                'explosion_json' => json_encode($explosion->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            $writes['recipe_order_line_usage'][] = (int) $usage['id'];
            $writes['recipe_cost_snapshots'][] = (int) $snapshot['id'];
            $writes['inventory_movements'] = array_merge($writes['inventory_movements'], $movementResult->movementIds);
            $writes['stock_reservations'] = array_merge($writes['stock_reservations'], $reservationResult->reservationIds);
            $warnings = array_merge($warnings, $explosion->warnings);
            $this->refreshAvailabilityCache($conn, $lineContext);
            $paidAny = true;
        }

        $writes = array_map(static function (array $ids): array {
            return array_values(array_unique($ids));
        }, $writes);

        return $this->result('order_paid', (array) $order, !$paidAny, $writes, $warnings, null);
    }

    public function onOrderVoided($order, $void): array
    {
        $voidContext = is_array($void) ? $void : [];
        $voidContext['policy'] = $this->resolveRefundPolicy($voidContext);

        return $this->reversePaidOrder('order_voided', (array) $order, $voidContext, 'voided');
    }

    public function onOrderRefunded($order, $refund): array
    {
        $refundContext = is_array($refund) ? $refund : [];
        $refundContext['policy'] = $this->resolveRefundPolicy($refundContext);

        return $this->reversePaidOrder('order_refunded', (array) $order, $refundContext, 'refunded');
    }

    public function onOrderSplit($ctx): array
    {
        $context = is_array($ctx) ? $ctx : [];
        $sourceLines = $this->lifecycleLinesFromContext($context, ['source_lines', 'original_lines', 'old_lines']);
        $remainingLines = $this->lifecycleLinesFromContext($context, ['remaining_lines', 'updated_source_lines', 'source_remaining_lines', 'new_lines']);
        $paidLines = $this->lifecycleLinesFromContext($context, ['paid_lines', 'child_lines', 'split_paid_lines']);
        if (!$sourceLines && !$remainingLines && !$paidLines) {
            return $this->noopResult('order_split', $ctx);
        }

        $baseContext = $this->lineLifecycleBaseContext($context);
        $reason = trim((string) ($context['reason'] ?? 'split_payment'));
        if ($reason === '') {
            $reason = 'split_payment';
        }

        $writes = [];
        $warnings = [];
        $allNoop = true;

        foreach ($sourceLines as $sourceLine) {
            $result = $this->onOrderLineCancelled(array_merge($baseContext, $sourceLine), $reason);
            $allNoop = $allNoop && (bool) ($result['noop'] ?? true);
            $writes = $this->mergeLifecycleWrites($writes, $result['writes'] ?? []);
            $warnings = array_merge($warnings, $result['warnings'] ?? []);
        }

        foreach ($remainingLines as $remainingLine) {
            $result = $this->onOrderLineAdded(array_merge($baseContext, $remainingLine));
            $allNoop = $allNoop && (bool) ($result['noop'] ?? true);
            $writes = $this->mergeLifecycleWrites($writes, $result['writes'] ?? []);
            $warnings = array_merge($warnings, $result['warnings'] ?? []);
        }

        if ($paidLines) {
            $paidOrder = $this->splitPaidOrderContext($context, $baseContext, $paidLines);
            $result = $this->onOrderPaid($paidOrder);
            $allNoop = $allNoop && (bool) ($result['noop'] ?? true);
            $writes = $this->mergeLifecycleWrites($writes, $result['writes'] ?? []);
            $warnings = array_merge($warnings, $result['warnings'] ?? []);
        }

        return $this->result(
            'order_split',
            $baseContext ?: $context,
            $allNoop,
            $this->uniqueLifecycleWrites($writes),
            $warnings,
            null
        );
    }

    public function onOrderMerged($ctx): array
    {
        $context = is_array($ctx) ? $ctx : [];
        $sourceLines = $this->lifecycleLinesFromContext($context, ['source_lines', 'old_lines', 'from_lines']);
        $destinationLines = $this->lifecycleLinesFromContext($context, ['destination_lines', 'new_lines', 'to_lines']);
        if (!$sourceLines && !$destinationLines) {
            return $this->noopResult('order_merged', $ctx);
        }

        $baseContext = $this->lineLifecycleBaseContext($context);
        $reason = trim((string) ($context['reason'] ?? 'table_merged'));
        if ($reason === '') {
            $reason = 'table_merged';
        }

        $writes = [];
        $warnings = [];
        $allNoop = true;

        foreach ($sourceLines as $sourceLine) {
            $result = $this->onOrderLineCancelled(array_merge($baseContext, $sourceLine), $reason);
            $allNoop = $allNoop && (bool) ($result['noop'] ?? true);
            $writes = $this->mergeLifecycleWrites($writes, $result['writes'] ?? []);
            $warnings = array_merge($warnings, $result['warnings'] ?? []);
        }

        foreach ($destinationLines as $destinationLine) {
            $result = $this->onOrderLineAdded(array_merge($baseContext, $destinationLine));
            $allNoop = $allNoop && (bool) ($result['noop'] ?? true);
            $writes = $this->mergeLifecycleWrites($writes, $result['writes'] ?? []);
            $warnings = array_merge($warnings, $result['warnings'] ?? []);
        }

        return $this->result(
            'order_merged',
            $baseContext ?: $context,
            $allNoop,
            $this->uniqueLifecycleWrites($writes),
            $warnings,
            null
        );
    }

    private function ensureUsage(
        mysqli $conn,
        RecipeOrderLineContext $context,
        RecipeExplosionResult $explosion,
        string $status,
        ?array $snapshot = null
    ): array {
        $idempotencyKey = $this->usageIdempotencyKey($context, $explosion);
        $existing = $this->usageRepository->findByIdempotencyKey(
            $conn,
            $context->posTenant,
            $context->posBranch,
            $context->storeId,
            $idempotencyKey
        );
        if ($existing) {
            return $existing;
        }

        $usageId = $this->usageRepository->createUsage($conn, [
            'usage_uuid' => $this->uuid(),
            'pos_tenant' => $context->posTenant,
            'pos_branch' => $context->posBranch,
            'branch_uuid' => $context->branchUuid,
            'store_id' => $context->storeId,
            'order_id' => (int) $context->orderId,
            'fat_detail_id' => $context->fatDetailId,
            'order_line_uuid' => $context->orderLineUuid,
            'source_order_uuid' => $context->sourceOrderUuid,
            'source_line_uuid' => $context->sourceLineUuid,
            'source_event_uuid' => $context->sourceEventUuid,
            'source_channel' => $context->channel,
            'sellable_item_id' => $context->sellableItemId,
            'variant_id' => $context->variantId,
            'modifiers_hash' => $this->modifiersHash($context->modifiers),
            'modifiers_json' => $context->modifiers ? json_encode($context->modifiers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'order_qty' => RecipeDecimal::normalize($context->quantity),
            'order_unit_id' => $context->unitId,
            'recipe_id' => $explosion->recipeId,
            'recipe_version_number' => $explosion->recipeVersion,
            'recipe_cost_snapshot_id' => $snapshot ? (int) $snapshot['id'] : null,
            'explosion_json' => json_encode($explosion->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'cost_total' => $this->explosionCostTotal($explosion),
            'status' => $status,
            'idempotency_key' => $idempotencyKey,
        ]);

        return $this->usageRepository->findByIdempotencyKey(
            $conn,
            $context->posTenant,
            $context->posBranch,
            $context->storeId,
            $idempotencyKey
        ) ?: ['id' => $usageId];
    }

    private function assertStrictAvailability(mysqli $conn, RecipeOrderLineContext $context): void
    {
        if (!$this->flags->isStrictStockEnabled()) {
            return;
        }

        $this->availabilityService->assertAvailableForOrderLine($conn, $context);
    }

    private function refreshAvailabilityCache(mysqli $conn, RecipeOrderLineContext $context): void
    {
        $this->availabilityService->refreshForOrderLine($conn, $context);
        if (!$this->flags->isMoovaSyncEnabled()) {
            return;
        }

        $moovaContext = $context;
        if ($context->channel !== 'moova' || $context->orderType !== 'delivery') {
            $moovaContext = new RecipeOrderLineContext(array_merge($this->contextArray($context), [
                'channel' => 'moova',
                'order_type' => 'delivery',
                'quantity' => '1.000000',
            ]));
            $this->availabilityService->refreshForOrderLine($conn, $moovaContext);
        }

        $this->recordMenuAvailabilitySnapshot($conn, $moovaContext->sellableItemId);
    }

    private function recordMenuAvailabilitySnapshot(mysqli $conn, int $itemId): void
    {
        if ($itemId < 1) {
            return;
        }

        try {
            $this->syncOutbox->recordMenuItemSnapshot($conn, $itemId, [
                'event_type' => 'menu.item_availability_changed',
                'source_system' => 'recipe_lifecycle',
                'config' => $this->flags->appConfig(),
            ]);
        } catch (Throwable $exception) {
            error_log('[Recipe] Failed to record menu availability sync snapshot: ' . $exception->getMessage());
        }
    }

    private function contextArray(RecipeOrderLineContext $context): array
    {
        return [
            'pos_tenant' => $context->posTenant,
            'pos_branch' => $context->posBranch,
            'branch_uuid' => $context->branchUuid,
            'store_id' => $context->storeId,
            'order_id' => $context->orderId,
            'fat_detail_id' => $context->fatDetailId,
            'order_line_uuid' => $context->orderLineUuid,
            'source_order_uuid' => $context->sourceOrderUuid,
            'source_line_uuid' => $context->sourceLineUuid,
            'source_event_uuid' => $context->sourceEventUuid,
            'sellable_item_id' => $context->sellableItemId,
            'item_category_id' => $context->itemCategoryId,
            'quantity' => $context->quantity,
            'unit_id' => $context->unitId,
            'variant_id' => $context->variantId,
            'modifiers' => $context->modifiers,
            'order_type' => $context->orderType,
            'channel' => $context->channel,
            'requested_at' => $context->requestedAt,
        ];
    }

    private function consumePendingUsagesForOrder(mysqli $conn, array $order, int $orderId): ?array
    {
        if ($orderId < 1) {
            return null;
        }

        $pending = $this->usageRepository->findPendingForOrder($conn, $orderId);
        if (!$pending) {
            return null;
        }

        $writes = [
            'recipe_order_line_usage' => [],
            'recipe_cost_snapshots' => [],
            'inventory_movements' => [],
            'stock_reservations' => [],
            'accounting_journals' => [],
        ];
        $warnings = [];
        $paidAny = false;

        foreach ($pending as $usage) {
            $lineContextData = $this->lineContextFromUsage($conn, $order, $usage);
            $lineContext = new RecipeOrderLineContext($lineContextData);
            $itemCategoryId = $this->itemCategoryId($conn, $lineContext->sellableItemId, $lineContext->itemCategoryId);
            if (!$this->flags->isConsumptionEnabledForItem(
                $this->scopeResolver->resolve($lineContextData),
                $lineContext->sellableItemId,
                $itemCategoryId
            )) {
                continue;
            }

            $explosion = $this->explosionFromUsage($usage);
            if (!$explosion || !$explosion->hasRecipe || !$explosion->recipeId) {
                $explosion = $this->explosionService->explodeOrderLine($conn, $lineContext);
            }
            if (!$explosion->hasRecipe || !$explosion->recipeId) {
                $warnings[] = 'No active recipe for item ' . $lineContext->sellableItemId . '.';
                continue;
            }
            if ((string) ($usage['status'] ?? '') !== 'reserved') {
                $this->assertStrictAvailability($conn, $lineContext);
            }

            $snapshot = $this->costService->getOrCreateOrderSnapshot($conn, (int) $explosion->recipeId, new RecipeCostContext([
                'pos_tenant' => $lineContext->posTenant,
                'pos_branch' => $lineContext->posBranch,
                'branch_uuid' => $lineContext->branchUuid,
                'store_id' => $lineContext->storeId,
                'order_type' => $lineContext->orderType,
                'channel' => $lineContext->channel,
                'modifiers' => $lineContext->modifiers,
                'calculated_at' => date('Y-m-d H:i:s'),
            ]), $lineContext->sellableItemId);
            $explosion->costSnapshotId = (int) $snapshot['id'];
            $this->applySnapshotCosts($explosion, $snapshot);

            $movementContext = $this->lineOrderContext($lineContext, (int) $usage['id']);
            $movementContext['item_category_id'] = $itemCategoryId;
            $activeReservations = $this->reservationService->activeForOrderLine(
                $conn,
                (int) $lineContext->orderId,
                $lineContext->fatDetailId,
                $lineContext->orderLineUuid
            );
            $movementContext['consume_reserved'] = (bool) $activeReservations;
            $movementResult = $this->movementService->recordRecipeConsumption($conn, $explosion, $movementContext);
            if ($movementResult->movementIds && $this->flags->isAccountingEnabledForItem(
                $this->scopeResolver->resolve($lineContextData),
                $lineContext->sellableItemId,
                $itemCategoryId
            )) {
                $accounting = $this->accountingService->postSaleCogs(
                    $conn,
                    array_merge($order, $lineContextData, $movementContext, [
                        'sellable_item_id' => $lineContext->sellableItemId,
                        'item_category_id' => $itemCategoryId,
                        'recipe_inventory_account_type' => $this->inventoryAccountType($explosion),
                    ]),
                    $movementResult->movementIds
                );
                if (!empty($accounting['journal_head_id'])) {
                    $writes['accounting_journals'][] = (int) $accounting['journal_head_id'];
                }
            }

            $reservationResult = $this->reservationService->consumeForOrderLine(
                $conn,
                (int) $lineContext->orderId,
                $lineContext->fatDetailId,
                $lineContext->orderLineUuid
            );
            $this->usageRepository->updateUsage($conn, (int) $usage['id'], [
                'status' => 'consumed',
                'consumed_at' => date('Y-m-d H:i:s'),
                'recipe_cost_snapshot_id' => (int) $snapshot['id'],
                'cost_total' => $this->explosionCostTotal($explosion),
                'explosion_json' => json_encode($explosion->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            $writes['recipe_order_line_usage'][] = (int) $usage['id'];
            $writes['recipe_cost_snapshots'][] = (int) $snapshot['id'];
            $writes['inventory_movements'] = array_merge($writes['inventory_movements'], $movementResult->movementIds);
            $writes['stock_reservations'] = array_merge($writes['stock_reservations'], $reservationResult->reservationIds);
            $warnings = array_merge($warnings, $explosion->warnings);
            $this->refreshAvailabilityCache($conn, $lineContext);
            $paidAny = true;
        }

        $writes = array_map(static function (array $ids): array {
            return array_values(array_unique($ids));
        }, $writes);

        return $this->result('order_paid', $order, !$paidAny, $writes, $warnings, null);
    }

    private function lineContextFromUsage(mysqli $conn, array $order, array $usage): array
    {
        $modifiers = json_decode((string) ($usage['modifiers_json'] ?? '[]'), true);
        if (!is_array($modifiers)) {
            $modifiers = [];
        }

        return [
            'conn' => $conn,
            'tenant' => (int) ($usage['pos_tenant'] ?? $order['pos_tenant'] ?? $order['tenant'] ?? 0),
            'branch' => (int) ($usage['pos_branch'] ?? $order['pos_branch'] ?? $order['branch'] ?? 0),
            'branch_uuid' => $usage['branch_uuid'] ?? ($order['branch_uuid'] ?? null),
            'store_id' => (int) ($usage['store_id'] ?? $order['store_id'] ?? 0),
            'order_id' => (int) ($usage['order_id'] ?? $order['order_id'] ?? 0),
            'fat_detail_id' => isset($usage['fat_detail_id']) ? (int) $usage['fat_detail_id'] : null,
            'order_line_uuid' => $usage['order_line_uuid'] ?? null,
            'source_order_uuid' => $usage['source_order_uuid'] ?? null,
            'source_line_uuid' => $usage['source_line_uuid'] ?? null,
            'source_event_uuid' => $usage['source_event_uuid'] ?? null,
            'channel' => (string) ($usage['source_channel'] ?? $order['channel'] ?? 'pos'),
            'order_type' => (string) ($order['order_type'] ?? 'takeaway'),
            'sellable_item_id' => (int) ($usage['sellable_item_id'] ?? 0),
            'item_id' => (int) ($usage['sellable_item_id'] ?? 0),
            'item_category_id' => $this->itemCategoryId($conn, (int) ($usage['sellable_item_id'] ?? 0)),
            'quantity' => (string) ($usage['order_qty'] ?? '1.000000'),
            'qty' => (string) ($usage['order_qty'] ?? '1.000000'),
            'variant_id' => isset($usage['variant_id']) ? (int) $usage['variant_id'] : null,
            'modifiers' => $modifiers,
        ];
    }

    private function explosionFromUsage(array $usage): ?RecipeExplosionResult
    {
        $data = json_decode((string) ($usage['explosion_json'] ?? ''), true);
        if (!is_array($data)) {
            return null;
        }

        $requirements = [];
        foreach (($data['requirements'] ?? []) as $requirement) {
            if (is_array($requirement)) {
                $requirements[] = new IngredientRequirement($requirement);
            }
        }
        $data['requirements'] = $requirements;

        return new RecipeExplosionResult($data);
    }

    private function hasConsumedExternalUsageForLine(mysqli $conn, RecipeOrderLineContext $context): bool
    {
        if ($context->sourceLineUuid !== null || $context->orderLineUuid !== null || !$context->fatDetailId) {
            return false;
        }

        foreach ($this->usageRepository->findForOrderLine($conn, (int) $context->orderId, $context->fatDetailId, null) as $usage) {
            if (
                !empty($usage['source_line_uuid'])
                && in_array((string) ($usage['status'] ?? ''), ['consumed', 'refunded', 'wasted', 'voided'], true)
            ) {
                return true;
            }
        }

        return false;
    }

    private function reversePaidOrder(string $action, array $order, array $reverseContext, string $usageStatus): array
    {
        $conn = $this->connectionFromContext($order);
        if (!$conn || !$this->flags->isEnabled()) {
            return $this->noopResult($action, ['order' => $order, 'reverse' => $reverseContext]);
        }

        $usageRows = [];
        foreach ($this->orderLines($order) ?: [$order] as $line) {
            $orderId = (int) ($line['order_id'] ?? $order['order_id'] ?? 0);
            $fatDetailId = isset($line['fat_detail_id']) ? (int) $line['fat_detail_id'] : null;
            $orderLineUuid = isset($line['order_line_uuid']) ? (string) $line['order_line_uuid'] : null;
            foreach ($this->usageRepository->findForOrderLine($conn, $orderId, $fatDetailId, $orderLineUuid) as $usage) {
                $usageRows[(int) $usage['id']] = $usage;
            }
        }

        if (!$usageRows) {
            return $this->noopResult($action, ['order' => $order, 'reverse' => $reverseContext]);
        }

        $movementIds = [];
        $journalIds = [];
        $warnings = [];
        foreach ($usageRows as $usage) {
            if ((string) ($usage['status'] ?? '') !== 'consumed') {
                continue;
            }

            $originalMovements = $this->movementRepository->findByRecipeUsageAndType($conn, (int) $usage['id'], 'recipe_consumption');
            $result = $this->movementService->recordRefundReversal($conn, $originalMovements, array_merge($reverseContext, [
                'created_by' => $reverseContext['created_by'] ?? null,
            ]));
            $movementIds = array_merge($movementIds, $result->movementIds);
            $itemCategoryId = $this->itemCategoryId($conn, (int) ($usage['sellable_item_id'] ?? 0));
            if ($result->movementIds && $this->flags->isAccountingEnabledForItem(
                $this->scopeResolver->resolve(array_merge($order, $reverseContext)),
                (int) ($usage['sellable_item_id'] ?? 0),
                $itemCategoryId
            )) {
                $accounting = $this->accountingService->postRefundReversal(
                    $conn,
                    array_merge($order, $reverseContext, [
                        'sellable_item_id' => (int) ($usage['sellable_item_id'] ?? 0),
                        'item_category_id' => $itemCategoryId,
                        'recipe_inventory_account_type' => $this->inventoryAccountTypeFromUsage($usage),
                    ]),
                    $result->movementIds
                );
                if (!empty($accounting['journal_head_id'])) {
                    $journalIds[] = (int) $accounting['journal_head_id'];
                }
            }
            $warnings = array_merge($warnings, $result->warnings);
            $timestampColumn = $usageStatus === 'voided' ? 'voided_at' : 'refunded_at';
            $finalStatus = ($reverseContext['policy'] ?? '') === 'waste' && $usageStatus === 'refunded'
                ? 'wasted'
                : $usageStatus;
            $this->usageRepository->updateUsage($conn, (int) $usage['id'], [
                'status' => $finalStatus,
                $timestampColumn => date('Y-m-d H:i:s'),
            ]);
            $this->refreshAvailabilityCache(
                $conn,
                new RecipeOrderLineContext($this->lineContextFromUsage($conn, $order, $usage))
            );
        }

        if (!$movementIds && !$journalIds && !$warnings) {
            return $this->noopResult($action, ['order' => $order, 'reverse' => $reverseContext]);
        }

        return $this->result(
            $action,
            $order,
            false,
            [
                'recipe_order_line_usage' => array_keys($usageRows),
                'inventory_movements' => array_values(array_unique($movementIds)),
                'accounting_journals' => array_values(array_unique($journalIds)),
            ],
            $warnings,
            null
        );
    }

    private function resolveRefundPolicy(array $context): string
    {
        $policy = $this->settings->refundStockPolicy($context);
        if ($policy !== 'manager_choice') {
            return $policy;
        }

        $requested = strtolower(trim((string) (
            $context['refund_stock_policy']
            ?? $context['policy']
            ?? ''
        )));

        return in_array($requested, ['waste', 'return_to_stock'], true) ? $requested : 'waste';
    }

    private function applySnapshotCosts(RecipeExplosionResult $explosion, array $snapshot): void
    {
        $costs = json_decode((string) ($snapshot['ingredient_cost_json'] ?? '[]'), true);
        if (!is_array($costs)) {
            return;
        }

        foreach ($explosion->requirements as $requirement) {
            $matched = false;
            foreach ($costs as $cost) {
                if ((int) ($cost['ingredient_item_id'] ?? 0) !== $requirement->ingredientItemId) {
                    continue;
                }
                $requirement->unitCost = (string) ($cost['unit_cost'] ?? '0.000000');
                $requirement->totalCost = RecipeDecimal::multiply($requirement->requiredQtyBase, $requirement->unitCost);
                $matched = true;
                break;
            }
            if (!$matched && $this->isPreparedStockRequirement($explosion, $requirement)) {
                $requirement->unitCost = (string) ($snapshot['cost_per_sell_unit'] ?? '0.000000');
                $requirement->totalCost = RecipeDecimal::multiply($requirement->requiredQtyBase, $requirement->unitCost);
            }
        }
    }

    private function inventoryAccountType(RecipeExplosionResult $explosion): string
    {
        foreach ($explosion->requirements as $requirement) {
            if ($this->isPreparedStockRequirement($explosion, $requirement)) {
                return 'prepared';
            }
        }

        return 'raw';
    }

    private function inventoryAccountTypeFromUsage(array $usage): string
    {
        $data = json_decode((string) ($usage['explosion_json'] ?? ''), true);
        if (!is_array($data)) {
            return 'raw';
        }

        $sellableItemId = (int) ($data['sellable_item_id'] ?? $usage['sellable_item_id'] ?? 0);
        foreach (($data['requirements'] ?? []) as $requirement) {
            if (!is_array($requirement)) {
                continue;
            }
            if ((string) ($requirement['line_type'] ?? '') !== 'prepared_stock') {
                continue;
            }
            if ((int) ($requirement['ingredient_item_id'] ?? 0) === $sellableItemId) {
                return 'prepared';
            }
        }

        return 'raw';
    }

    private function isPreparedStockRequirement(RecipeExplosionResult $explosion, IngredientRequirement $requirement): bool
    {
        return $requirement->lineType === 'prepared_stock'
            && $requirement->ingredientItemId === $explosion->sellableItemId;
    }

    private function lineOrderContext(RecipeOrderLineContext $context, int $usageId): array
    {
        return [
            'pos_tenant' => $context->posTenant,
            'pos_branch' => $context->posBranch,
            'branch_uuid' => $context->branchUuid,
            'store_id' => $context->storeId,
            'order_id' => (int) $context->orderId,
            'fat_detail_id' => $context->fatDetailId,
            'order_line_uuid' => $context->orderLineUuid,
            'source_order_uuid' => $context->sourceOrderUuid,
            'source_line_uuid' => $context->sourceLineUuid,
            'source_event_uuid' => $context->sourceEventUuid,
            'recipe_order_line_usage_id' => $usageId,
            'item_category_id' => $context->itemCategoryId,
            'channel' => $context->channel,
            'order_type' => $context->orderType,
            'expires_at' => $this->reservationExpiresAt($context),
        ];
    }

    private function reservationExpiresAt(RecipeOrderLineContext $context): string
    {
        try {
            $requestedAt = new DateTimeImmutable((string) ($context->requestedAt ?: 'now'));
        } catch (Throwable $exception) {
            $requestedAt = new DateTimeImmutable('now');
        }

        return $requestedAt
            ->modify('+' . $this->settings->defaultReservationMinutes() . ' minutes')
            ->format('Y-m-d H:i:s');
    }

    private function orderLines(array $order): array
    {
        if (isset($order['lines']) && is_array($order['lines'])) {
            return array_values(array_filter($order['lines'], 'is_array'));
        }

        if (isset($order['sellable_item_id']) || isset($order['item_id'])) {
            return [$order];
        }

        return [];
    }

    private function lifecycleLinesFromContext(array $context, array $keys): array
    {
        foreach ($keys as $key) {
            if (!isset($context[$key]) || !is_array($context[$key])) {
                continue;
            }

            if ($this->looksLikeLifecycleLine($context[$key])) {
                return [$context[$key]];
            }

            $lines = [];
            foreach ($context[$key] as $line) {
                if (is_array($line)) {
                    $lines[] = $line;
                }
            }

            return $lines;
        }

        return [];
    }

    private function looksLikeLifecycleLine(array $value): bool
    {
        foreach (['order_id', 'fat_detail_id', 'order_line_uuid', 'sellable_item_id', 'item_id', 'quantity', 'qty'] as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }

        return false;
    }

    private function lineLifecycleBaseContext(array $context): array
    {
        $base = [];
        foreach ([
            'conn',
            'tenant',
            'pos_tenant',
            'branch',
            'pos_branch',
            'branch_uuid',
            'store_id',
            'det_store',
            'channel',
            'order_type',
            'requested_at',
            'source',
            'source_system',
        ] as $key) {
            if (array_key_exists($key, $context)) {
                $base[$key] = $context[$key];
            }
        }

        return $base;
    }

    private function splitPaidOrderContext(array $context, array $baseContext, array $paidLines): array
    {
        $paidOrder = isset($context['paid_order']) && is_array($context['paid_order'])
            ? $context['paid_order']
            : [];
        $orderId = (int) (
            $paidOrder['order_id']
            ?? $context['paid_order_id']
            ?? $context['child_order_id']
            ?? $context['split_order_id']
            ?? 0
        );
        if ($orderId > 0) {
            $paidOrder['order_id'] = $orderId;
        }
        $paidOrder['lines'] = $paidLines;

        return array_merge($baseContext, $paidOrder);
    }

    private function mergeLifecycleWrites(array $writes, array $next): array
    {
        foreach ($next as $key => $ids) {
            if (!isset($writes[$key])) {
                $writes[$key] = [];
            }
            if (is_array($ids)) {
                foreach ($ids as $id) {
                    $writes[$key][] = $id;
                }
                continue;
            }
            if ($ids !== null && $ids !== '') {
                $writes[$key][] = $ids;
            }
        }

        return $writes;
    }

    private function uniqueLifecycleWrites(array $writes): array
    {
        foreach ($writes as $key => $ids) {
            $writes[$key] = array_values(array_unique($ids, SORT_REGULAR));
        }

        return $writes;
    }

    private function usageIdempotencyKey(RecipeOrderLineContext $context, RecipeExplosionResult $explosion): string
    {
        $lineId = (string) ($context->sourceLineUuid ?? $context->orderLineUuid ?? $context->fatDetailId ?? '0');
        $modifiersHash = $this->modifiersHash($context->modifiers) ?: 'none';

        return 'recipe-usage'
            . ':' . $context->posTenant
            . ':' . $context->posBranch
            . ':store:' . $context->storeId
            . ':order:' . (int) $context->orderId
            . ':line:' . $lineId
            . ':item:' . $context->sellableItemId
            . ':qty:' . RecipeDecimal::normalize($context->quantity)
            . ':mods:' . substr($modifiersHash, 0, 16)
            . ':recipe:' . (int) $explosion->recipeId
            . ':v:' . (int) $explosion->recipeVersion;
    }

    private function explosionCostTotal(RecipeExplosionResult $explosion): string
    {
        $total = RecipeDecimal::zero();
        foreach ($explosion->requirements as $requirement) {
            $total = RecipeDecimal::add($total, $requirement->totalCost);
        }

        return $total;
    }

    private function modifiersHash(array $modifiers): ?string
    {
        if (!$modifiers) {
            return null;
        }

        return hash('sha256', json_encode($modifiers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function connectionFromContext($context): ?mysqli
    {
        if (is_array($context) && isset($context['conn']) && $context['conn'] instanceof mysqli) {
            return $context['conn'];
        }

        return null;
    }

    private function itemCategoryId(mysqli $conn, int $itemId, ?int $contextCategoryId = null): ?int
    {
        if ($contextCategoryId !== null && $contextCategoryId > 0) {
            return $contextCategoryId;
        }
        if ($itemId < 1) {
            return null;
        }

        $databaseRow = $conn->query('SELECT DATABASE() AS db_name')->fetch_assoc();
        $database = (string) ($databaseRow['db_name'] ?? '');
        $cacheKey = $database . ':' . $itemId;
        if (array_key_exists($cacheKey, $this->itemCategoryCache)) {
            return $this->itemCategoryCache[$cacheKey];
        }
        if (!$this->columnExists($conn, 'myitems', 'group1')) {
            $this->itemCategoryCache[$cacheKey] = null;

            return null;
        }

        $stmt = $conn->prepare('SELECT group1 FROM myitems WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $categoryId = (int) ($row['group1'] ?? 0);
        $this->itemCategoryCache[$cacheKey] = $categoryId > 0 ? $categoryId : null;

        return $this->itemCategoryCache[$cacheKey];
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

    private function result(string $action, array $context, bool $noop, array $writes, array $warnings, ?array $preview): array
    {
        $scope = $this->scopeResolver->resolve($context);

        return [
            'success' => true,
            'action' => $action,
            'mode' => $this->flags->mode(),
            'recipe_enabled' => $this->flags->isEnabled(),
            'noop' => $noop,
            'writes' => $writes,
            'warnings' => $warnings,
            'scope' => $scope->toArray(),
            'preview' => $preview,
        ];
    }

    private function noopResult(string $action, $context): array
    {
        $scope = is_array($context) ? $this->scopeResolver->resolve($context) : $this->scopeResolver->resolve();

        return [
            'success' => true,
            'action' => $action,
            'mode' => $this->flags->mode(),
            'recipe_enabled' => $this->flags->isEnabled(),
            'noop' => true,
            'writes' => [],
            'warnings' => [],
            'scope' => $scope->toArray(),
        ];
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
