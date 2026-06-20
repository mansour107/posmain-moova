<?php

require_once __DIR__ . '/BranchSecretProviderFactory.php';

class CloudReceiveService
{
    public function handle(mysqli $conn, array $headers, string $rawBody, array $config = []): array
    {
        if (!$config && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        $role = (string) ($config['role'] ?? 'branch');
        if (!in_array($role, ['cloud', 'fake_cloud'], true)) {
            return $this->response(403, ['ok' => false, 'reason' => 'invalid_role']);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || !isset($payload['events']) || !is_array($payload['events'])) {
            return $this->response(400, ['ok' => false, 'reason' => 'invalid_json_or_events']);
        }

        $branchUuid = $this->header($headers, ['x-posmain-branch-uuid', 'x-branch-uuid']);
        if ($branchUuid === '') {
            $branchUuid = trim((string) ($payload['branch_uuid'] ?? ''));
        }

        $provider = BranchSecretProviderFactory::fromConfig($conn, $config);
        $auth = (new CloudAuthService())->verifyRequest(
            $provider,
            $branchUuid,
            $this->header($headers, ['x-posmain-timestamp', 'x-timestamp']),
            $this->header($headers, ['x-posmain-nonce', 'x-nonce']),
            $rawBody,
            $this->header($headers, ['x-posmain-signature', 'x-signature'])
        );
        if (!$auth['ok']) {
            return $this->response(401, ['ok' => false, 'reason' => $auth['reason']]);
        }
        $provider->touchLastSeen($branchUuid);

        $mode = SyncApplyMode::fromFlags(
            (bool) ($config['sync']['cloud_apply_enabled'] ?? true),
            (bool) ($config['sync']['shadow_mode'] ?? false)
        );

        $inbox = new SyncInboxService();
        $results = [];
        foreach ($payload['events'] as $event) {
            try {
                $results[] = $inbox->receiveBranchEvent($conn, $branchUuid, is_array($event) ? $event : [], $mode, $config);
            } catch (Throwable $e) {
                $results[] = [
                    'event_uuid' => is_array($event) ? (string) ($event['event_uuid'] ?? '') : '',
                    'idempotency_key' => is_array($event) ? (string) ($event['idempotency_key'] ?? '') : '',
                    'status' => 'failed',
                    'stored' => false,
                    'applied' => false,
                    'report_trusted' => false,
                    'cloud_entity_id' => null,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $this->response(200, SyncApplyMode::response($mode, $results));
    }

    public static function headersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        if (isset($server['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $server['CONTENT_TYPE'];
        }

        return $headers;
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
