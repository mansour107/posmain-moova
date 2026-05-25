<?php

require_once __DIR__ . '/RecipeOrderLineContext.php';

class RecipeCostContext
{
    public $posTenant;
    public $posBranch;
    public $branchUuid;
    public $storeId;
    public $orderType;
    public $channel;
    public $modifiers;
    public $manualCosts;
    public $costingMethod;
    public $calculatedAt;
    public $actorUserId;

    public function __construct(array $data = [])
    {
        $this->posTenant = max(0, (int) ($data['pos_tenant'] ?? $data['tenant'] ?? 0));
        $this->posBranch = max(0, (int) ($data['pos_branch'] ?? $data['branch'] ?? 0));
        $this->branchUuid = trim((string) ($data['branch_uuid'] ?? '')) ?: null;
        $this->storeId = max(0, (int) ($data['store_id'] ?? 0));
        $this->orderType = (string) ($data['order_type'] ?? 'any');
        $this->channel = (string) ($data['channel'] ?? 'any');
        $this->modifiers = is_array($data['modifiers'] ?? null) ? $data['modifiers'] : [];
        $this->manualCosts = is_array($data['manual_costs'] ?? null) ? $data['manual_costs'] : [];
        $this->costingMethod = isset($data['costing_method']) ? (string) $data['costing_method'] : null;
        $this->calculatedAt = (string) ($data['calculated_at'] ?? date('Y-m-d H:i:s'));
        $this->actorUserId = isset($data['actor_user_id']) ? (int) $data['actor_user_id'] : null;
    }

    public function toOrderLineContext(int $sellableItemId, string $quantity): RecipeOrderLineContext
    {
        return new RecipeOrderLineContext([
            'pos_tenant' => $this->posTenant,
            'pos_branch' => $this->posBranch,
            'branch_uuid' => $this->branchUuid,
            'store_id' => $this->storeId,
            'sellable_item_id' => $sellableItemId,
            'quantity' => $quantity,
            'order_type' => $this->orderType,
            'channel' => $this->channel,
            'modifiers' => $this->modifiers,
            'requested_at' => $this->calculatedAt,
        ]);
    }
}
