<?php

/**
 * Branch already has an open drawer owned by another cashier.
 * Message stays BRANCH_DRAWER_ALREADY_OPEN for API/e2e compatibility.
 */
class DrawerBranchBlockedException extends RuntimeException
{
    /** @var array<string, mixed> */
    private array $blockingSession;

    /**
     * @param array<string, mixed> $blockingSession
     */
    public function __construct(array $blockingSession)
    {
        parent::__construct('BRANCH_DRAWER_ALREADY_OPEN');
        $this->blockingSession = $blockingSession;
    }

    /** @return array<string, mixed> */
    public function blockingSession(): array
    {
        return $this->blockingSession;
    }
}
