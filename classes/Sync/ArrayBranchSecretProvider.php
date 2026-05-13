<?php

require_once __DIR__ . '/BranchSecretProvider.php';

class ArrayBranchSecretProvider implements BranchSecretProvider
{
    private array $secrets;
    private array $activeBranches;

    public function __construct(array $secrets, array $activeBranches = [])
    {
        $this->secrets = $secrets;
        $this->activeBranches = $activeBranches;
    }

    public function getSecretForBranch(string $branchUuid): ?string
    {
        if (!$this->isBranchActive($branchUuid)) {
            return null;
        }

        return isset($this->secrets[$branchUuid]) ? (string) $this->secrets[$branchUuid] : null;
    }

    public function isBranchActive(string $branchUuid): bool
    {
        if (!array_key_exists($branchUuid, $this->secrets)) {
            return false;
        }

        if (!$this->activeBranches) {
            return true;
        }

        return !empty($this->activeBranches[$branchUuid]);
    }
}
