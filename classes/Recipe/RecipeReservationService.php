<?php

require_once __DIR__ . '/DTO/RecipeExplosionResult.php';
require_once __DIR__ . '/DTO/RecipeMovementResult.php';
require_once __DIR__ . '/DTO/RecipeScope.php';
require_once __DIR__ . '/RecipeFeatureFlags.php';
require_once __DIR__ . '/RecipeInventoryMovementService.php';
require_once __DIR__ . '/Repository/RecipeOrderLineUsageRepository.php';
require_once __DIR__ . '/Repository/StockReservationRepository.php';

class RecipeReservationService
{
    private $flags;
    private $reservations;
    private $usageRepository;
    private $movementService;

    public function __construct(
        ?RecipeFeatureFlags $flags = null,
        ?StockReservationRepository $reservations = null,
        ?RecipeOrderLineUsageRepository $usageRepository = null,
        ?RecipeInventoryMovementService $movementService = null
    ) {
        $this->flags = $flags ?: new RecipeFeatureFlags();
        $this->reservations = $reservations ?: new StockReservationRepository();
        $this->usageRepository = $usageRepository ?: new RecipeOrderLineUsageRepository();
        $this->movementService = $movementService ?: new RecipeInventoryMovementService($this->flags);
    }

    public function reserveExplosion(mysqli $conn, RecipeExplosionResult $explosion, array $orderContext): RecipeMovementResult
    {
        $scope = $this->scopeFromOrderContext($orderContext);
        if (!$this->flags->isReservationEnabledForItem(
            $scope,
            $explosion->sellableItemId,
            $this->itemCategoryIdFromOrderContext($orderContext)
        )) {
            return new RecipeMovementResult(['noop' => true]);
        }

        $reservationIds = [];
        foreach ($explosion->requirements as $requirement) {
            $idempotencyKey = $this->reservationIdempotencyKey($scope, $explosion, $requirement, $orderContext);
            $existing = $this->reservations->findByIdempotencyKey(
                $conn,
                $scope->posTenant,
                $scope->posBranch,
                $scope->storeId,
                $idempotencyKey
            );
            if ($existing) {
                $reservationIds[] = (int) $existing['id'];
                continue;
            }

            $reservationIds[] = $this->reservations->createReservation($conn, [
                'reservation_uuid' => $this->uuid(),
                'pos_tenant' => $scope->posTenant,
                'pos_branch' => $scope->posBranch,
                'branch_uuid' => $scope->branchUuid,
                'store_id' => $scope->storeId,
                'order_id' => $orderContext['order_id'] ?? 0,
                'fat_detail_id' => $orderContext['fat_detail_id'] ?? null,
                'order_line_uuid' => $orderContext['order_line_uuid'] ?? null,
                'recipe_order_line_usage_id' => $orderContext['recipe_order_line_usage_id'] ?? null,
                'sellable_item_id' => $explosion->sellableItemId,
                'recipe_id' => $explosion->recipeId,
                'ingredient_item_id' => $requirement->ingredientItemId,
                'qty_reserved' => $requirement->requiredQtyBase,
                'expires_at' => $orderContext['expires_at'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);
        }

        $movementResult = $this->movementService->recordReservationMovement($conn, $explosion, $orderContext);

        return new RecipeMovementResult([
            'movement_ids' => $movementResult->movementIds,
            'reservation_ids' => $reservationIds,
        ]);
    }

    public function releaseForOrderLine(mysqli $conn, int $orderId, ?int $fatDetailId, ?string $orderLineUuid, string $reason): RecipeMovementResult
    {
        $active = $this->reservations->findActiveForOrderLine($conn, $orderId, $fatDetailId, $orderLineUuid);
        if (!$active) {
            return new RecipeMovementResult(['noop' => true]);
        }

        $movementResult = $this->movementService->recordReservationRelease($conn, $active, $reason);
        foreach ($active as $reservation) {
            $this->reservations->updateStatus($conn, (int) $reservation['id'], 'released');
        }

        return new RecipeMovementResult([
            'movement_ids' => $movementResult->movementIds,
            'reservation_ids' => array_map(static function ($reservation) {
                return (int) $reservation['id'];
            }, $active),
        ]);
    }

    public function releaseForUsageIds(mysqli $conn, array $usageIds, string $reason): RecipeMovementResult
    {
        $active = $this->reservations->findActiveForUsageIds($conn, $usageIds);
        if (!$active) {
            return new RecipeMovementResult(['noop' => true]);
        }

        $movementResult = $this->movementService->recordReservationRelease($conn, $active, $reason);
        foreach ($active as $reservation) {
            $this->reservations->updateStatus($conn, (int) $reservation['id'], 'released');
        }

        return new RecipeMovementResult([
            'movement_ids' => $movementResult->movementIds,
            'reservation_ids' => array_map(static function ($reservation) {
                return (int) $reservation['id'];
            }, $active),
        ]);
    }

    public function activeForOrderLine(mysqli $conn, int $orderId, ?int $fatDetailId, ?string $orderLineUuid): array
    {
        return $this->reservations->findActiveForOrderLine($conn, $orderId, $fatDetailId, $orderLineUuid);
    }

    public function consumeForOrderLine(mysqli $conn, int $orderId, ?int $fatDetailId, ?string $orderLineUuid): RecipeMovementResult
    {
        $active = $this->reservations->findActiveForOrderLine($conn, $orderId, $fatDetailId, $orderLineUuid);
        foreach ($active as $reservation) {
            $this->reservations->updateStatus($conn, (int) $reservation['id'], 'consumed');
        }

        return new RecipeMovementResult([
            'noop' => !$active,
            'reservation_ids' => array_map(static function ($reservation) {
                return (int) $reservation['id'];
            }, $active),
        ]);
    }

    public function expireReservations(mysqli $conn, DateTimeInterface $now, int $limit = 500): RecipeMovementResult
    {
        if ($limit < 1) {
            return new RecipeMovementResult(['noop' => true]);
        }

        $expired = $this->reservations->findExpiredReserved($conn, $now->format('Y-m-d H:i:s'), $limit);
        if (!$expired) {
            return new RecipeMovementResult(['noop' => true]);
        }

        $movementResult = $this->movementService->recordReservationRelease($conn, $expired, 'expired');
        foreach ($expired as $reservation) {
            $this->reservations->updateStatus($conn, (int) $reservation['id'], 'expired');

            $usageId = (int) ($reservation['recipe_order_line_usage_id'] ?? 0);
            if ($usageId > 0) {
                $this->usageRepository->markReservedAsPreviewed($conn, $usageId);
            }
        }

        return new RecipeMovementResult([
            'movement_ids' => $movementResult->movementIds,
            'reservation_ids' => array_map(static function ($reservation) {
                return (int) $reservation['id'];
            }, $expired),
        ]);
    }

    private function scopeFromOrderContext(array $orderContext): RecipeScope
    {
        return new RecipeScope(
            (int) ($orderContext['pos_tenant'] ?? 0),
            (int) ($orderContext['pos_branch'] ?? 0),
            $orderContext['branch_uuid'] ?? null,
            (int) ($orderContext['store_id'] ?? 0),
            (string) ($orderContext['channel'] ?? 'pos'),
            (string) ($orderContext['order_type'] ?? 'takeaway'),
            'recipe'
        );
    }

    private function itemCategoryIdFromOrderContext(array $orderContext): ?int
    {
        $categoryId = (int) ($orderContext['item_category_id'] ?? $orderContext['category_id'] ?? 0);

        return $categoryId > 0 ? $categoryId : null;
    }

    private function reservationIdempotencyKey(
        RecipeScope $scope,
        RecipeExplosionResult $explosion,
        IngredientRequirement $requirement,
        array $orderContext
    ): string {
        $orderId = (string) ($orderContext['order_id'] ?? '0');
        $lineId = (string) (
            $orderContext['recipe_order_line_usage_id']
            ?? $orderContext['order_line_uuid']
            ?? $orderContext['source_line_uuid']
            ?? $orderContext['fat_detail_id']
            ?? '0'
        );
        $recipeId = (string) ($explosion->recipeId ?? '0');
        $version = (string) ($explosion->recipeVersion ?? '0');

        return 'reservation'
            . ':' . $scope->posTenant
            . ':' . $scope->posBranch
            . ':store:' . $scope->storeId
            . ':order:' . $orderId
            . ':line:' . $lineId
            . ':recipe:' . $recipeId
            . ':item:' . $requirement->ingredientItemId
            . ':v:' . $version;
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
