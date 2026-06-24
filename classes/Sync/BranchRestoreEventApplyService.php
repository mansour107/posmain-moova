<?php

require_once __DIR__ . '/CloudLegacyPosMirrorService.php';
require_once __DIR__ . '/CloudOperationalMirrorService.php';
require_once __DIR__ . '/RestoreEventPhase.php';

class BranchRestoreEventApplyService
{
    private CloudLegacyPosMirrorService $legacyMirror;
    private CloudOperationalMirrorService $operationalMirror;

    public function __construct(
        ?CloudLegacyPosMirrorService $legacyMirror = null,
        ?CloudOperationalMirrorService $operationalMirror = null
    ) {
        $this->legacyMirror = $legacyMirror ?: new CloudLegacyPosMirrorService();
        $this->operationalMirror = $operationalMirror ?: new CloudOperationalMirrorService();
    }

    public function apply(mysqli $conn, string $branchUuid, array $event): ?array
    {
        $phase = RestoreEventPhase::classify($event);
        if ($phase === RestoreEventPhase::OPERATIONAL) {
            return $this->operationalMirror->applyFromBranchEvent($conn, $branchUuid, $event);
        }

        if ($phase === null) {
            $operational = $this->operationalMirror->applyFromBranchEvent($conn, $branchUuid, $event);
            if ($operational) {
                return $operational;
            }

            return null;
        }

        return $this->legacyMirror->mirrorFromBranchEvent($conn, $branchUuid, $event);
    }
}
