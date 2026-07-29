<?php

require_once __DIR__ . '/CloudLegacyPosMirrorService.php';
require_once __DIR__ . '/CloudOperationalMirrorService.php';
require_once __DIR__ . '/BranchMenuMasterProjectionService.php';
require_once __DIR__ . '/BranchRecipeMasterProjectionService.php';

/**
 * Applies the intentionally narrow cloud-to-branch live-sync contract.
 *
 * Disaster restore has a separate service and may restore operational rows.
 * The live poller must never use that broad restore capability: only catalog
 * and recipe-master events are accepted here.
 */
class BranchCloudMasterApplyService
{
    private CloudLegacyPosMirrorService $legacyMirror;
    private CloudOperationalMirrorService $operationalMirror;
    private BranchMenuMasterProjectionService $menuProjection;
    private BranchRecipeMasterProjectionService $recipeProjection;

    public function __construct(
        ?CloudLegacyPosMirrorService $legacyMirror = null,
        ?CloudOperationalMirrorService $operationalMirror = null,
        ?BranchMenuMasterProjectionService $menuProjection = null,
        ?BranchRecipeMasterProjectionService $recipeProjection = null
    ) {
        $this->legacyMirror = $legacyMirror ?: new CloudLegacyPosMirrorService();
        $this->operationalMirror = $operationalMirror ?: new CloudOperationalMirrorService();
        $this->menuProjection = $menuProjection ?: new BranchMenuMasterProjectionService();
        $this->recipeProjection = $recipeProjection ?: new BranchRecipeMasterProjectionService();
    }

    public function apply(mysqli $conn, string $branchUuid, array $event): array
    {
        $payload = $this->payload($event);
        $snapshotType = strtolower(trim((string) ($payload['snapshot_type'] ?? '')));
        $aggregateType = strtolower(trim((string) ($event['aggregate_type'] ?? $event['entity_type'] ?? '')));

        if ($this->isMenuMasterEvent($event, $snapshotType, $aggregateType)) {
            $denial = $this->authorizationOrClockDenial($event, $payload, 'menu.edit');
            if ($denial !== null) {
                return $denial;
            }
            if (!$this->hasFieldRevisionEnvelope($payload, 'menu_item')) {
                return $this->denied('MASTER_FIELD_REVISIONS_REQUIRED');
            }

            return $this->menuProjection->apply($conn, $branchUuid, $event);
        }

        if ($this->isRecipeMasterEvent($event, $snapshotType, $aggregateType)) {
            $denial = $this->authorizationOrClockDenial($event, $payload, 'recipe.manage');
            if ($denial !== null) {
                return $denial;
            }
            if (!$this->hasFieldRevisionEnvelope($payload, 'recipe')) {
                return $this->denied('MASTER_FIELD_REVISIONS_REQUIRED');
            }

            return $this->recipeProjection->apply($conn, $branchUuid, $event);
        }

        return $this->denied('CLOUD_OPERATIONAL_OR_UNKNOWN_EVENT_DENIED');
    }

    private function authorizationOrClockDenial(array $event, array $payload, string $requiredPermission): ?array
    {
        $actor = isset($payload['actor']) && is_array($payload['actor'])
            ? $payload['actor']
            : (isset($event['actor']) && is_array($event['actor']) ? $event['actor'] : []);
        $actorId = (int) ($actor['user_id'] ?? $actor['actor_user_id'] ?? 0);
        $permissions = $actor['permissions'] ?? $actor['capabilities'] ?? [];
        $permissions = is_array($permissions) ? array_values(array_map('strval', $permissions)) : [];
        if ($actorId < 1 || !in_array($requiredPermission, $permissions, true)) {
            return $this->denied('MASTER_EVENT_ADMIN_AUTH_REQUIRED');
        }

        $nodeId = trim((string) ($payload['source_node_id'] ?? $event['source_node_id'] ?? ''));
        if ($nodeId === '' || strlen($nodeId) > 100) {
            return $this->denied('MASTER_EVENT_SOURCE_NODE_REQUIRED');
        }

        $originClock = trim((string) ($payload['origin_clock_utc'] ?? $event['origin_clock_utc'] ?? ''));
        $originTimestamp = $originClock === '' ? false : strtotime($originClock);
        if ($originTimestamp === false) {
            return $this->denied('MASTER_EVENT_TRUSTED_CLOCK_REQUIRED');
        }

        return null;
    }

    private function isMenuMasterEvent(array $event, string $snapshotType, string $aggregateType): bool
    {
        if ($snapshotType !== 'pos_menu_item' || !in_array($aggregateType, ['menu_item', 'item'], true)) {
            return false;
        }

        $eventType = strtolower(trim((string) ($event['event_type'] ?? '')));
        return strpos($eventType, 'menu.') === 0 || strpos($eventType, 'item.') === 0;
    }

    private function isRecipeMasterEvent(array $event, string $snapshotType, string $aggregateType): bool
    {
        if ($snapshotType !== 'recipe_bundle' || $aggregateType !== 'recipe') {
            return false;
        }

        return strpos(strtolower(trim((string) ($event['event_type'] ?? ''))), 'recipe.') === 0;
    }

    private function payload(array $event): array
    {
        return isset($event['payload']) && is_array($event['payload']) ? $event['payload'] : [];
    }

    private function hasFieldRevisionEnvelope(array $payload, string $aggregateType): bool
    {
        $master = isset($payload['master_data']) && is_array($payload['master_data'])
            ? $payload['master_data']
            : [];
        return strtolower(trim((string) ($master['aggregate_type'] ?? ''))) === $aggregateType
            && !empty($master['aggregate_uuid'])
            && !empty($master['fields'])
            && is_array($master['fields']);
    }

    private function denied(string $reason): array
    {
        return [
            'denied' => true,
            'reason' => $reason,
        ];
    }
}
