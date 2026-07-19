<?php

require_once __DIR__ . '/CloudAuthService.php';
require_once __DIR__ . '/BranchSecretProviderFactory.php';
require_once __DIR__ . '/CloudBranchSyncEventService.php';
require_once __DIR__ . '/CloudBranchRestoreExportService.php';
require_once __DIR__ . '/RestoreEventPhase.php';

class CloudBranchRestoreEventService
{
    public const CONTRACT_V1 = 1;
    public const CONTRACT_V2 = 2;
    public const RECOVERY_PROFILE_OPERATIONAL_V1 = 'operational_v1';

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

        try {
            $request = self::normalizeExportRequest($query);
        } catch (InvalidArgumentException $e) {
            return $this->response(400, ['ok' => false, 'reason' => 'invalid_request', 'message' => $e->getMessage()]);
        }

        $afterId = $request['after_id'];
        $limit = $request['limit'];
        $source = $request['source'];
        $signatureBody = self::exportSignatureBody(
            $branchUuid,
            $phase,
            $afterId,
            $limit,
            $source,
            $request
        );
        $auth = $this->verify($conn, $config, $headers, $branchUuid, $signatureBody);
        if (!$auth['ok']) {
            return $this->response(401, ['ok' => false, 'reason' => $auth['reason']]);
        }

        try {
            $exporter = new CloudBranchRestoreExportService();
            if ($request['contract_version'] === self::CONTRACT_V2) {
                $latestCheckpoint = $exporter->latestInboxCheckpoint($conn, $branchUuid);
                if ($request['snapshot_checkpoint'] !== null && $request['snapshot_checkpoint'] > $latestCheckpoint) {
                    throw new InvalidArgumentException('Snapshot checkpoint is ahead of hosted accepted data.');
                }
                if ($request['snapshot_checkpoint'] === null) {
                    $request['snapshot_checkpoint'] = $latestCheckpoint;
                }
                if ($request['history_since_utc'] === null) {
                    $request['history_since_utc'] = gmdate('Y-m-d\TH:i:s\Z', time() - (31 * 86400));
                }
            }

            $page = $exporter->exportPage(
                $conn,
                $branchUuid,
                $phase,
                $afterId,
                $limit,
                $source,
                $request
            );
            if ($request['contract_version'] === self::CONTRACT_V2) {
                $page['contract_version'] = self::CONTRACT_V2;
                $page['recovery_profile'] = $request['recovery_profile'];
                $page['snapshot_checkpoint'] = $request['snapshot_checkpoint'];
                $page['history_since_utc'] = $request['history_since_utc'];
            }
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
        string $source = 'auto',
        array $recovery = []
    ): string {
        $body = [
            'branch_uuid' => $branchUuid,
            'phase' => RestoreEventPhase::normalize($phase),
            'after_id' => max(0, $afterId),
            'limit' => max(1, min(100, $limit)),
            'source' => strtolower(trim($source !== '' ? $source : 'auto')),
        ];

        $contractVersion = (int) ($recovery['contract_version'] ?? self::CONTRACT_V1);
        if ($contractVersion === self::CONTRACT_V2) {
            $body['contract_version'] = self::CONTRACT_V2;
            $body['recovery_profile'] = (string) ($recovery['recovery_profile'] ?? self::RECOVERY_PROFILE_OPERATIONAL_V1);
            $body['snapshot_checkpoint'] = array_key_exists('snapshot_checkpoint', $recovery)
                && $recovery['snapshot_checkpoint'] !== null
                ? max(0, (int) $recovery['snapshot_checkpoint'])
                : null;
            $body['history_since_utc'] = isset($recovery['history_since_utc'])
                ? (string) $recovery['history_since_utc']
                : null;
        }

        return json_encode($body, JSON_UNESCAPED_SLASHES);
    }

    public static function normalizeExportRequest(array $query): array
    {
        $rawVersion = trim((string) ($query['contract_version'] ?? self::CONTRACT_V1));
        if (!in_array($rawVersion, ['1', '2'], true)) {
            throw new InvalidArgumentException('Unsupported restore export contract version.');
        }

        $contractVersion = (int) $rawVersion;
        $limitDefault = $contractVersion === self::CONTRACT_V2 ? 25 : 50;
        if ($contractVersion === self::CONTRACT_V2) {
            $rawAfterId = trim((string) ($query['after_id'] ?? '0'));
            $rawLimit = trim((string) ($query['limit'] ?? (string) $limitDefault));
            if ($rawAfterId === '' || !ctype_digit($rawAfterId)) {
                throw new InvalidArgumentException('Recovery cursor must be a non-negative integer.');
            }
            if ($rawLimit === '' || !ctype_digit($rawLimit) || (int) $rawLimit < 1 || (int) $rawLimit > 100) {
                throw new InvalidArgumentException('Recovery page size must be between 1 and 100.');
            }
            $afterId = (int) $rawAfterId;
            $limit = (int) $rawLimit;
        } else {
            $afterId = max(0, (int) ($query['after_id'] ?? 0));
            $limit = max(1, min(100, (int) ($query['limit'] ?? $limitDefault)));
        }
        $sourceDefault = $contractVersion === self::CONTRACT_V2 ? 'cloud_snapshot' : 'auto';
        $source = strtolower(trim((string) ($query['source'] ?? $sourceDefault)));

        $request = [
            'contract_version' => $contractVersion,
            'after_id' => $afterId,
            'limit' => $limit,
            'source' => $source !== '' ? $source : $sourceDefault,
        ];
        if ($contractVersion === self::CONTRACT_V1) {
            return $request;
        }

        if ($request['source'] !== 'cloud_snapshot') {
            throw new InvalidArgumentException('Recovery v2 snapshot requests require source=cloud_snapshot.');
        }
        $profile = strtolower(trim((string) ($query['recovery_profile'] ?? self::RECOVERY_PROFILE_OPERATIONAL_V1)));
        if ($profile !== self::RECOVERY_PROFILE_OPERATIONAL_V1) {
            throw new InvalidArgumentException('Unsupported recovery profile.');
        }
        $rawCheckpoint = array_key_exists('snapshot_checkpoint', $query)
            ? trim((string) $query['snapshot_checkpoint'])
            : null;
        if ($rawCheckpoint !== null && ($rawCheckpoint === '' || !ctype_digit($rawCheckpoint))) {
            throw new InvalidArgumentException('Snapshot checkpoint must be a non-negative integer when provided.');
        }
        $request['recovery_profile'] = $profile;
        $request['snapshot_checkpoint'] = $rawCheckpoint === null ? null : (int) $rawCheckpoint;
        $historySinceUtc = array_key_exists('history_since_utc', $query)
            ? trim((string) $query['history_since_utc'])
            : null;
        if ($historySinceUtc !== null && !self::isCanonicalUtcTimestamp($historySinceUtc)) {
            throw new InvalidArgumentException('History cutoff must use canonical UTC format YYYY-MM-DDTHH:MM:SSZ.');
        }
        $request['history_since_utc'] = $historySinceUtc;

        return $request;
    }

    private static function isCanonicalUtcTimestamp(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value)) {
            return false;
        }
        $timestamp = strtotime($value);

        return $timestamp !== false && gmdate('Y-m-d\TH:i:s\Z', $timestamp) === $value;
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
