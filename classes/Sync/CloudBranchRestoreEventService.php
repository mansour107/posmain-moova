<?php

require_once __DIR__ . '/CloudAuthService.php';
require_once __DIR__ . '/BranchSecretProviderFactory.php';
require_once __DIR__ . '/CloudBranchSyncEventService.php';
require_once __DIR__ . '/CloudBranchRestoreExportService.php';
require_once __DIR__ . '/RestoreEventPhase.php';

class CloudBranchRestoreEventService
{
    public function handleExport(mysqli $conn, array $headers, array $query, array $config = []): array
    {
        if (!$config && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        if (!$this->isCloudRole($config)) {
            return $this->response(403, ['ok' => false, 'reason' => 'invalid_role']);
        }

        $branchUuid = $this->branchUuid($headers, $query);
        if ($branchUuid === '') {
            return $this->response(400, ['ok' => false, 'reason' => 'branch_uuid_required']);
        }

        try {
            $phase = RestoreEventPhase::normalize((string) ($query['phase'] ?? ''));
        } catch (InvalidArgumentException $e) {
            return $this->response(400, ['ok' => false, 'reason' => 'invalid_phase', 'message' => $e->getMessage()]);
        }

        $afterId = max(0, (int) ($query['after_id'] ?? 0));
        $limit = max(1, min(100, (int) ($query['limit'] ?? 50)));
        $source = strtolower(trim((string) ($query['source'] ?? 'auto')));
        $signatureBody = self::exportSignatureBody($branchUuid, $phase, $afterId, $limit, $source);
        $auth = $this->verify($conn, $config, $headers, $branchUuid, $signatureBody);
        if (!$auth['ok']) {
            return $this->response(401, ['ok' => false, 'reason' => $auth['reason']]);
        }

        try {
            $page = (new CloudBranchRestoreExportService())->exportPage(
                $conn,
                $branchUuid,
                $phase,
                $afterId,
                $limit,
                $source
            );
        } catch (InvalidArgumentException $e) {
            return $this->response(400, ['ok' => false, 'reason' => 'invalid_request', 'message' => $e->getMessage()]);
        }

        return $this->response(200, array_merge([
            'ok' => true,
            'branch_uuid' => $branchUuid,
        ], $page));
    }

    public static function exportSignatureBody(
        string $branchUuid,
        string $phase,
        int $afterId,
        int $limit,
        string $source = 'auto'
    ): string {
        return json_encode([
            'branch_uuid' => $branchUuid,
            'phase' => RestoreEventPhase::normalize($phase),
            'after_id' => max(0, $afterId),
            'limit' => max(1, min(100, $limit)),
            'source' => strtolower(trim($source !== '' ? $source : 'auto')),
        ], JSON_UNESCAPED_SLASHES);
    }

    public static function headersFromServer(array $server): array
    {
        return CloudBranchSyncEventService::headersFromServer($server);
    }

    private function verify(mysqli $conn, array $config, array $headers, string $branchUuid, string $signatureBody): array
    {
        $provider = BranchSecretProviderFactory::fromConfig($conn, $config);
        $auth = (new CloudAuthService())->verifyRequest(
            $provider,
            $branchUuid,
            $this->header($headers, ['x-posmain-timestamp', 'x-timestamp']),
            $this->header($headers, ['x-posmain-nonce', 'x-nonce']),
            $signatureBody,
            $this->header($headers, ['x-posmain-signature', 'x-signature'])
        );

        if ($auth['ok']) {
            $provider->touchLastSeen($branchUuid);
        }

        return $auth;
    }

    private function isCloudRole(array $config): bool
    {
        return in_array((string) ($config['role'] ?? 'branch'), ['cloud', 'fake_cloud'], true);
    }

    private function branchUuid(array $headers, array $payloadOrQuery): string
    {
        $branchUuid = $this->header($headers, ['x-posmain-branch-uuid', 'x-branch-uuid']);
        if ($branchUuid !== '') {
            return $branchUuid;
        }

        return trim((string) ($payloadOrQuery['branch_uuid'] ?? ''));
    }

    private function header(array $headers, array $names): string
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = (string) $value;
        }

        foreach ($names as $name) {
            if (isset($normalized[$name])) {
                return trim($normalized[$name]);
            }
        }

        return '';
    }

    private function response(int $statusCode, array $body): array
    {
        return [
            'status_code' => $statusCode,
            'body' => $body,
        ];
    }
}
