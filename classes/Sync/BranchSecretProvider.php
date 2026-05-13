<?php

interface BranchSecretProvider
{
    public function getSecretForBranch(string $branchUuid): ?string;

    public function isBranchActive(string $branchUuid): bool;
}
