<?php

class RecipeActorContext
{
    public $userId;
    public $posTenant;
    public $posBranch;
    public $branchUuid;
    public $permissions;
    public $ipAddress;
    public $userAgent;

    public function __construct(
        ?int $userId = null,
        int $posTenant = 0,
        int $posBranch = 0,
        ?string $branchUuid = null,
        array $permissions = [],
        ?string $ipAddress = null,
        ?string $userAgent = null
    ) {
        $this->userId = $userId;
        $this->posTenant = max(0, $posTenant);
        $this->posBranch = max(0, $posBranch);
        $this->branchUuid = $branchUuid !== '' ? $branchUuid : null;
        $this->permissions = array_values(array_unique(array_map('strval', $permissions)));
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
