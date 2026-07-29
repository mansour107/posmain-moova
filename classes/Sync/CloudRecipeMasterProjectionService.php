<?php

require_once __DIR__ . '/MasterDataRevisionService.php';
require_once __DIR__ . '/BranchRecipeMasterProjectionService.php';
require_once __DIR__ . '/CloudOperationalMirrorService.php';

/**
 * Projects authenticated branch recipe-master events into the cloud database.
 *
 * Draft recipes use field-level convergence. A first projection, or a later
 * projection of an active/archived branch recipe, may materialize the complete
 * branch snapshot because operational recipe versions remain branch-owned.
 */
class CloudRecipeMasterProjectionService
{
    private MasterDataRevisionService $revisions;
    private BranchRecipeMasterProjectionService $draftProjection;
    private CloudOperationalMirrorService $branchMirror;

    public function __construct(
        ?MasterDataRevisionService $revisions = null,
        ?BranchRecipeMasterProjectionService $draftProjection = null,
        ?CloudOperationalMirrorService $branchMirror = null
    ) {
        $this->revisions = $revisions ?: new MasterDataRevisionService();
        $this->draftProjection = $draftProjection ?: new BranchRecipeMasterProjectionService($this->revisions);
        $this->branchMirror = $branchMirror ?: new CloudOperationalMirrorService();
    }

    public function supports(array $event): bool
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $master = is_array($payload['master_data'] ?? null) ? $payload['master_data'] : [];

        return strtolower(trim((string) ($payload['snapshot_type'] ?? ''))) === 'recipe_bundle'
            && strtolower(trim((string) ($master['aggregate_type'] ?? ''))) === 'recipe'
            && trim((string) ($master['aggregate_uuid'] ?? '')) !== ''
            && isset($master['fields'])
            && is_array($master['fields']);
    }

    public function apply(mysqli $conn, string $branchUuid, array $event): array
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $master = is_array($payload['master_data'] ?? null) ? $payload['master_data'] : [];
        $recipeUuid = strtolower(trim((string) ($master['aggregate_uuid'] ?? '')));
        $sourceNodeId = trim((string) ($master['source_node_id'] ?? $payload['source_node_id'] ?? ''));
        $actor = is_array($master['actor'] ?? null)
            ? $master['actor']
            : (is_array($payload['actor'] ?? null) ? $payload['actor'] : []);
        $actorId = (int) ($actor['user_id'] ?? $actor['actor_user_id'] ?? 0);
        $permissions = is_array($actor['permissions'] ?? null)
            ? array_values(array_map('strval', $actor['permissions']))
            : [];

        if ($actorId < 1 || !in_array('recipe.manage', $permissions, true)) {
            throw new RuntimeException('MASTER_RECIPE_BRANCH_ADMIN_AUTH_REQUIRED');
        }
        if (!hash_equals('branch:' . strtolower($branchUuid), strtolower($sourceNodeId))) {
            throw new RuntimeException('MASTER_RECIPE_BRANCH_SOURCE_NODE_REQUIRED');
        }

        $fields = is_array($master['fields'] ?? null) ? $master['fields'] : [];
        if ($fields === []) {
            return [
                'entity_id' => 'recipe_headers:unchanged',
                'stale' => true,
                'reason' => 'no_master_field_change',
            ];
        }
        $this->draftProjection->validateIncomingFields($conn, $fields);

        $header = $this->findHeaderForUpdate($conn, $recipeUuid);
        if ($header && (string) ($header['status'] ?? '') === 'draft') {
            return $this->draftProjection->apply($conn, $branchUuid, $event);
        }

        if ($header) {
            $this->revisions->seedCurrentValues(
                $conn,
                $branchUuid,
                'recipe',
                $recipeUuid,
                $this->draftProjection->currentValuesForRecipe($conn, (int) $header['id'], $header),
                'cloud-legacy:' . $branchUuid,
                (string) ($header['updated_at'] ?? $header['created_at'] ?? gmdate('Y-m-d H:i:s')) . 'Z',
                'cloud_legacy_recipe'
            );
        } else {
            $missing = array_values(array_diff(
                BranchRecipeMasterProjectionService::allowedFields(),
                array_keys($fields)
            ));
            if ($missing !== []) {
                throw new RuntimeException(
                    'MASTER_RECIPE_CREATION_REQUIRES_COMPLETE_SNAPSHOT:' . implode(',', $missing)
                );
            }
        }

        $resolution = $this->revisions->resolve(
            $conn,
            $branchUuid,
            $event,
            BranchRecipeMasterProjectionService::allowedFields()
        );
        if (empty($resolution['winning_fields'])) {
            return [
                'entity_id' => $header ? 'recipe_headers:' . (int) $header['id'] : 'recipe_headers:unchanged',
                'stale' => true,
                'reason' => 'all_master_fields_older_or_duplicate',
                'master_resolution' => $resolution,
            ];
        }

        $projection = $this->branchMirror->applyFromBranchEvent($conn, $branchUuid, $event);
        if (!$projection || empty($projection['entity_id'])) {
            throw new RuntimeException('MASTER_RECIPE_BRANCH_SNAPSHOT_INVALID');
        }

        $stored = $this->findHeaderForUpdate($conn, $recipeUuid);
        if (!$stored) {
            throw new RuntimeException('MASTER_RECIPE_BRANCH_PROJECTION_MISSING');
        }

        return [
            'entity_id' => (string) $projection['entity_id'],
            'recipe_id' => (int) $stored['id'],
            'master_resolution' => $resolution,
        ];
    }

    private function findHeaderForUpdate(mysqli $conn, string $recipeUuid): ?array
    {
        $stmt = $conn->prepare('SELECT * FROM recipe_headers WHERE recipe_uuid = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('s', $recipeUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }
}
