<?php

require_once dirname(__DIR__) . '/InventoryDecimal.php';

class InventoryMovementRequest
{
    public array $scope;
    public ?string $movementUuid;
    public ?string $movementGroupUuid;
    public int $itemId;
    public string $movementType;
    public string $sourceType;
    public ?int $sourceId;
    public ?string $sourceUuid;
    public ?int $orderId;
    public ?int $fatDetailId;
    public ?string $orderLineUuid;
    public ?int $recipeOrderLineUsageId;
    public ?int $recipeId;
    public ?int $recipeCostSnapshotId;
    public ?int $productionBatchId;
    public string $qtyIn;
    public string $qtyOut;
    public string $qtyReserved;
    public ?int $unitId;
    public string $unitConversionToBase;
    public string $unitCost;
    public string $totalCost;
    public string $idempotencyKey;
    public string $payloadHash;
    public array $metadata;
    public ?int $reversedMovementId;
    public ?int $createdBy;

    private function __construct(array $data)
    {
        $this->scope = is_array($data['scope'] ?? null) ? $data['scope'] : [];
        $this->movementUuid = $this->nullableString($data['movement_uuid'] ?? $data['movementUuid'] ?? null);
        $this->movementGroupUuid = $this->nullableString($data['movement_group_uuid'] ?? $data['movementGroupUuid'] ?? null);
        $this->itemId = $this->positiveInt($data['item_id'] ?? $data['itemId'] ?? 0);
        $this->movementType = $this->normalizeToken((string) ($data['movement_type'] ?? $data['movementType'] ?? ''));
        $this->sourceType = $this->normalizeToken((string) ($data['source_type'] ?? $data['sourceType'] ?? 'manual'));
        $this->sourceId = $this->nullablePositiveInt($data['source_id'] ?? $data['sourceId'] ?? null);
        $this->sourceUuid = $this->nullableString($data['source_uuid'] ?? $data['sourceUuid'] ?? null);
        $this->orderId = $this->nullablePositiveInt($data['order_id'] ?? $data['orderId'] ?? null);
        $this->fatDetailId = $this->nullablePositiveInt($data['fat_detail_id'] ?? $data['fatDetailId'] ?? null);
        $this->orderLineUuid = $this->nullableString($data['order_line_uuid'] ?? $data['orderLineUuid'] ?? null);
        $this->recipeOrderLineUsageId = $this->nullablePositiveInt($data['recipe_order_line_usage_id'] ?? null);
        $this->recipeId = $this->nullablePositiveInt($data['recipe_id'] ?? $data['recipeId'] ?? null);
        $this->recipeCostSnapshotId = $this->nullablePositiveInt($data['recipe_cost_snapshot_id'] ?? null);
        $this->productionBatchId = $this->nullablePositiveInt($data['production_batch_id'] ?? null);
        $this->qtyIn = InventoryDecimal::normalize($data['qty_in'] ?? $data['qtyIn'] ?? '0');
        $this->qtyOut = InventoryDecimal::normalize($data['qty_out'] ?? $data['qtyOut'] ?? '0');
        $this->qtyReserved = InventoryDecimal::normalize(
            $data['qty_reserved'] ?? $data['qtyReserved'] ?? $data['reserved_delta'] ?? $data['reservedDelta'] ?? '0'
        );
        $this->unitId = $this->nullablePositiveInt($data['unit_id'] ?? $data['unitId'] ?? null);
        $this->unitConversionToBase = InventoryDecimal::normalize($data['unit_conversion_to_base'] ?? '1', 8);
        $this->unitCost = InventoryDecimal::normalize($data['unit_cost'] ?? $data['unitCost'] ?? '0');
        $this->totalCost = InventoryDecimal::normalize(
            $data['total_cost'] ?? $data['totalCost'] ?? $this->defaultTotalCost()
        );
        $this->idempotencyKey = trim((string) ($data['idempotency_key'] ?? $data['idempotencyKey'] ?? ''));
        $this->metadata = is_array($data['metadata'] ?? null)
            ? $data['metadata']
            : (is_array($data['metadata_json'] ?? null) ? $data['metadata_json'] : []);
        $this->reversedMovementId = $this->nullablePositiveInt(
            $data['reversed_movement_id'] ?? $data['reversedMovementId'] ?? null
        );
        $this->createdBy = $this->nullablePositiveInt($data['created_by'] ?? $data['createdBy'] ?? null);
        $this->payloadHash = trim((string) ($data['payload_hash'] ?? $data['payloadHash'] ?? ''));
        if ($this->payloadHash === '') {
            $this->payloadHash = $this->computePayloadHash();
        }
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function movementRow(): array
    {
        return [
            'movement_uuid' => $this->movementUuid ?: self::uuid(),
            'movement_group_uuid' => $this->movementGroupUuid,
            'pos_tenant' => $this->posTenant(),
            'pos_branch' => $this->posBranch(),
            'branch_uuid' => $this->branchUuid(),
            'store_id' => $this->storeId(),
            'item_id' => $this->itemId,
            'movement_type' => $this->movementType,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'source_uuid' => $this->sourceUuid,
            'order_id' => $this->orderId,
            'fat_detail_id' => $this->fatDetailId,
            'order_line_uuid' => $this->orderLineUuid,
            'recipe_order_line_usage_id' => $this->recipeOrderLineUsageId,
            'recipe_id' => $this->recipeId,
            'recipe_cost_snapshot_id' => $this->recipeCostSnapshotId,
            'production_batch_id' => $this->productionBatchId,
            'qty_in' => $this->storedQtyIn(),
            'qty_out' => $this->storedQtyOut(),
            'unit_id' => $this->unitId,
            'unit_conversion_to_base' => $this->unitConversionToBase,
            'unit_cost' => $this->unitCost,
            'total_cost' => $this->totalCost,
            'idempotency_key' => $this->idempotencyKey,
            'payload_hash' => $this->payloadHash,
            'metadata_json' => $this->metadataJson(),
            'reversed_movement_id' => $this->reversedMovementId,
            'created_by' => $this->createdBy,
        ];
    }

    public function posTenant(): int
    {
        return $this->nonNegativeInt($this->scope['pos_tenant'] ?? $this->scope['tenant'] ?? 0);
    }

    public function posBranch(): int
    {
        return $this->nonNegativeInt($this->scope['pos_branch'] ?? $this->scope['branch'] ?? 0);
    }

    public function storeId(): int
    {
        return $this->nonNegativeInt($this->scope['store_id'] ?? $this->scope['det_store'] ?? 0);
    }

    public function branchUuid(): ?string
    {
        return $this->nullableString($this->scope['branch_uuid'] ?? null);
    }

    public function isReservationMovement(): bool
    {
        return in_array($this->movementType, ['reservation', 'reservation_release'], true);
    }

    public function storedQtyIn(): string
    {
        return $this->isReservationMovement() ? InventoryDecimal::zero() : $this->qtyIn;
    }

    public function storedQtyOut(): string
    {
        return $this->isReservationMovement() ? InventoryDecimal::zero() : $this->qtyOut;
    }

    public function metadataJson(): ?string
    {
        $metadata = $this->metadata;
        if ($this->isReservationMovement()) {
            $metadata['qty_reserved'] = $this->qtyReserved;
        }

        if (!$metadata) {
            return null;
        }

        $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Failed to encode inventory movement metadata: ' . json_last_error_msg());
        }

        return $json;
    }

    public function payloadForHash(): array
    {
        return [
            'scope' => [
                'pos_tenant' => $this->posTenant(),
                'pos_branch' => $this->posBranch(),
                'store_id' => $this->storeId(),
            ],
            'item_id' => $this->itemId,
            'movement_type' => $this->movementType,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'source_uuid' => $this->sourceUuid,
            'movement_group_uuid' => $this->movementGroupUuid,
            'qty_in' => $this->qtyIn,
            'qty_out' => $this->qtyOut,
            'qty_reserved' => $this->qtyReserved,
            'unit_id' => $this->unitId,
            'unit_conversion_to_base' => $this->unitConversionToBase,
            'unit_cost' => $this->unitCost,
            'total_cost' => $this->totalCost,
            'metadata' => $this->metadata,
            'reversed_movement_id' => $this->reversedMovementId,
        ];
    }

    private function computePayloadHash(): string
    {
        return hash('sha256', json_encode($this->payloadForHash(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function defaultTotalCost(): string
    {
        $qty = InventoryDecimal::isPositive($this->qtyIn ?? '0') ? $this->qtyIn : ($this->qtyOut ?? '0');
        if (!InventoryDecimal::isPositive($qty)) {
            return InventoryDecimal::zero();
        }

        return InventoryDecimal::multiply($qty, $this->unitCost ?? '0');
    }

    private function normalizeToken(string $value): string
    {
        $token = strtolower(trim($value));
        $token = str_replace(['-', ' '], '_', $token);

        return $token;
    }

    private function positiveInt($value): int
    {
        $int = (int) $value;

        return $int > 0 ? $int : 0;
    }

    private function nullablePositiveInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function nonNegativeInt($value): int
    {
        $int = (int) $value;

        return $int < 0 ? 0 : $int;
    }

    private function nullableString($value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function uuid(): string
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
