<?php

require_once __DIR__ . '/DTO/RecipeActorContext.php';
require_once __DIR__ . '/Repository/RecipeAuditRepository.php';

class RecipeAuditService
{
    private $auditRepository;

    public function __construct(?RecipeAuditRepository $auditRepository = null)
    {
        $this->auditRepository = $auditRepository ?: new RecipeAuditRepository();
    }

    public function record(
        mysqli $conn,
        RecipeActorContext $actor,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?int $recipeId = null,
        ?array $before = null,
        ?array $after = null
    ): int {
        return $this->auditRepository->log($conn, [
            'pos_tenant' => $actor->posTenant,
            'pos_branch' => $actor->posBranch,
            'branch_uuid' => $actor->branchUuid,
            'recipe_id' => $recipeId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before_json' => $this->encodePayload($before),
            'after_json' => $this->encodePayload($after),
            'actor_user_id' => $actor->userId,
            'ip_address' => $actor->ipAddress,
            'user_agent' => $actor->userAgent,
        ]);
    }

    public function report(mysqli $conn, array $filters = []): array
    {
        return $this->auditRepository->search($conn, $filters);
    }

    public function actionOptions(mysqli $conn): array
    {
        return $this->auditRepository->distinctValues($conn, 'action');
    }

    public function entityTypeOptions(mysqli $conn): array
    {
        return $this->auditRepository->distinctValues($conn, 'entity_type');
    }

    private function encodePayload(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Failed to encode recipe audit payload: ' . json_last_error_msg());
        }

        return $json;
    }
}
