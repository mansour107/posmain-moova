<?php

class RecipeScope
{
    public int $posTenant;
    public int $posBranch;
    public ?string $branchUuid;
    public int $storeId;
    public string $channel;
    public string $orderType;
    public string $source;

    public function __construct(
        int $posTenant = 0,
        int $posBranch = 0,
        ?string $branchUuid = null,
        int $storeId = 0,
        string $channel = 'pos',
        string $orderType = 'takeaway',
        string $source = 'pos'
    ) {
        $this->posTenant = max(0, $posTenant);
        $this->posBranch = max(0, $posBranch);
        $this->branchUuid = $branchUuid !== '' ? $branchUuid : null;
        $this->storeId = max(0, $storeId);
        $this->channel = $this->normalizeToken($channel, 'pos');
        $this->orderType = $this->normalizeToken($orderType, 'takeaway');
        $this->source = $this->normalizeToken($source, 'pos');
    }

    public function toArray(): array
    {
        return [
            'pos_tenant' => $this->posTenant,
            'pos_branch' => $this->posBranch,
            'branch_uuid' => $this->branchUuid,
            'store_id' => $this->storeId,
            'channel' => $this->channel,
            'order_type' => $this->orderType,
            'source' => $this->source,
        ];
    }

    private function normalizeToken(string $value, string $default): string
    {
        $token = strtolower(trim($value));
        $token = str_replace(['-', ' '], '_', $token);

        return preg_match('/^[a-z0-9_]{1,40}$/', $token) === 1 ? $token : $default;
    }
}

