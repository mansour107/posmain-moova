<?php

require_once __DIR__ . '/CloudAuthService.php';
require_once __DIR__ . '/CloudBranchRestoreEventService.php';
require_once __DIR__ . '/BranchRestoreEventApplyService.php';
require_once __DIR__ . '/RestoreEventPhase.php';
require_once __DIR__ . '/BranchIdentity.php';
require_once __DIR__ . '/BranchImageSyncService.php';
require_once __DIR__ . '/ItemImageSyncQueueService.php';
require_once __DIR__ . '/SyncRuntimeSettings.php';
require_once __DIR__ . '/BranchRestoreSafetyGuard.php';
require_once __DIR__ . '/BranchRestoreRunService.php';

class BranchRestoreFromHostedService
{
    private BranchRestoreEventApplyService $applyService;
    private BranchRestoreSafetyGuard $safetyGuard;
    private BranchRestoreRunService $runService;

    public function __construct(
        ?BranchRestoreEventApplyService $applyService = null,
        ?BranchRestoreSafetyGuard $safetyGuard = null,
        ?BranchRestoreRunService $runService = null
    ) {
        $this->applyService = $applyService ?: new BranchRestoreEventApplyService();
        $this->safetyGuard = $safetyGuard ?: new BranchRestoreSafetyGuard();
        $this->runService = $runService ?: new BranchRestoreRunService();
    }

    public static function localNeedsRestore(mysqli $conn): bool
    {
        $items = (int) ($conn->query("SELECT COUNT(*) AS c FROM myitems WHERE isdeleted = 0")->fetch_assoc()['c'] ?? 0);
        if ($items > 0) {
            return false;
        }

        if (self::tableExists($conn, 'ot_head')) {
            $orders = (int) ($conn->query('SELECT COUNT(*) AS c FROM ot_head')->fetch_assoc()['c'] ?? 0);
            if ($orders > 0) {
                return false;
            }
        }

        return true;
    }

    public function restore(mysqli $conn, array $config = [], array $options = []): array
    {
        if (!$config && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        $apply = !empty($options['apply']);
        $streamOptions = $options;
        $streamOptions['apply'] = false;

        if (!$apply) {
            $plan = $this->restoreStream($conn, $config, $streamOptions);
            $phases = array_map(
                static fn (array $phase): string => (string) ($phase['phase'] ?? ''),
                (array) ($plan['phases'] ?? [])
            );
            $plan['safety'] = $this->safetyGuard->describePlan(
                $conn,
                (string) $plan['branch_uuid'],
                $phases,
                $plan,
                $config
            );
            return $plan;
        }

        if (!$this->runService->acquireWriterLock($conn)) {
            throw new RuntimeException('Another branch recovery writer is already active.');
        }

        try {
            $resumeRunUuid = strtolower(trim((string) ($options['resume_run_uuid'] ?? '')));
            $run = null;
            if ($resumeRunUuid !== '') {
                $run = $this->runService->find($conn, $resumeRunUuid);
                if ($run === null) {
                    throw new RuntimeException('Restore resume run was not found.');
                }
                $streamOptions['snapshot_checkpoint'] = (int) $run['snapshot_checkpoint'];
                $streamOptions['history_since_utc'] = (string) $run['history_since_utc'];
            }

            $plan = $this->restoreStream($conn, $config, $streamOptions);
            $phases = array_map(
                static fn (array $phase): string => (string) ($phase['phase'] ?? ''),
                (array) ($plan['phases'] ?? [])
            );

            if ($run === null) {
                $authorization = $this->safetyGuard->assertApplyAuthorized(
                    $conn,
                    (string) $plan['branch_uuid'],
                    $phases,
                    $plan,
                    $config,
                    $options
                );
                $runUuid = strtolower(trim((string) ($options['restore_run_uuid'] ?? '')));
                if ($runUuid === '') {
                    $runUuid = $this->runService->newRunUuid();
                }
                $binding = $this->restoreRunBinding($runUuid, $plan, $authorization);
                $run = $this->runService->prepare($conn, $binding);
                $run = $this->runService->start($conn, (string) $run['run_uuid']);
            } else {
                $authorization = $this->safetyGuard->assertResumeAuthorized(
                    $conn,
                    (string) $plan['branch_uuid'],
                    $phases,
                    $plan,
                    $config,
                    $options,
                    $run
                );
                $binding = $this->restoreRunBinding((string) $run['run_uuid'], $plan, $authorization);
                $run = $this->runService->assertResumeBinding($conn, (string) $run['run_uuid'], $binding);
            }

            $applyOptions = $options;
            $applyOptions['apply'] = true;
            $applyOptions['snapshot_checkpoint'] = $plan['snapshot_checkpoint'];
            $applyOptions['history_since_utc'] = $plan['history_since_utc'];
            $applyOptions['phase_resume_state'] = $run['phase_state'];
            $applyOptions['initial_metrics'] = [
                'fetched' => (int) $run['fetched'],
                'mirrored' => (int) $run['mirrored'],
                'skipped' => (int) $run['skipped'],
                'failed' => (int) $run['failed'],
            ];
            $applyOptions['on_page_complete'] = function (
                string $phase,
                int $expectedCursor,
                int $nextCursor,
                bool $phaseComplete,
                array $metrics
            ) use ($conn, &$run): void {
                $run = $this->runService->advancePage(
                    $conn,
                    (string) $run['run_uuid'],
                    $phase,
                    $expectedCursor,
                    $nextCursor,
                    $phaseComplete,
                    $metrics
                );
            };

            $summary = $this->restoreStream($conn, $config, $applyOptions);
            $summary['safety'] = $authorization;
            $summary['dry_run'] = [
                'manifest_hash' => (string) $authorization['manifest_hash'],
                'expected_events' => (int) $authorization['expected_events'],
            ];
            $summary['reconciliation'] = [
                'ok' => (int) ($summary['failed'] ?? 0) === 0
                    && (int) ($summary['skipped'] ?? 0) === 0
                    && (int) ($summary['fetched'] ?? -1) === (int) $authorization['expected_events']
                    && (int) ($summary['mirrored'] ?? -1) === (int) $authorization['expected_events'],
                'expected_events' => (int) $authorization['expected_events'],
                'fetched' => (int) ($summary['fetched'] ?? 0),
                'mirrored' => (int) ($summary['mirrored'] ?? 0),
                'skipped' => (int) ($summary['skipped'] ?? 0),
                'failed' => (int) ($summary['failed'] ?? 0),
            ];

            if (!empty($summary['reconciliation']['ok'])) {
                $run = $this->runService->complete($conn, (string) $run['run_uuid']);
                $this->recordRestoreCheckpoint($conn, (string) $summary['branch_uuid'], $summary);
                $summary['images'] = $this->startBackgroundImageDownload(
                    $conn,
                    $config,
                    (string) $summary['branch_uuid']
                );
            } else {
                $summary['images'] = [
                    'enabled' => false,
                    'reason' => 'restore_reconciliation_failed',
                ];
            }
            $summary['restore_run'] = [
                'run_uuid' => (string) $run['run_uuid'],
                'status' => (string) $run['status'],
                'phase_state' => $run['phase_state'],
            ];

            return $summary;
        } finally {
            try {
                $this->runService->releaseWriterLock($conn);
            } catch (Throwable $e) {
                // The connection itself releases advisory locks; never mask the restore result.
            }
        }
    }

    private function restoreStream(mysqli $conn, array $config, array $options): array
    {
        if (!$config && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        $apply = !empty($options['apply']);
        $contractVersion = (int) ($options['contract_version'] ?? CloudBranchRestoreEventService::CONTRACT_V2);
        if (!in_array($contractVersion, [
            CloudBranchRestoreEventService::CONTRACT_V1,
            CloudBranchRestoreEventService::CONTRACT_V2,
        ], true)) {
            throw new InvalidArgumentException('Unsupported restore contract version.');
        }
        $limitDefault = $contractVersion === CloudBranchRestoreEventService::CONTRACT_V2 ? 25 : 50;
        $limit = max(1, min(100, (int) ($options['limit'] ?? $limitDefault)));
        $source = strtolower(trim((string) ($options['source'] ?? (
            $contractVersion === CloudBranchRestoreEventService::CONTRACT_V2 ? 'cloud_snapshot' : 'auto'
        ))));
        $recoveryProfile = strtolower(trim((string) (
            $options['recovery_profile'] ?? CloudBranchRestoreEventService::RECOVERY_PROFILE_OPERATIONAL_V1
        )));
        $snapshotCheckpoint = array_key_exists('snapshot_checkpoint', $options)
            ? max(0, (int) $options['snapshot_checkpoint'])
            : null;
        $historySinceUtc = array_key_exists('history_since_utc', $options)
            ? trim((string) $options['history_since_utc'])
            : null;
        $pagePauseMs = max(0, min(2000, (int) ($options['page_pause_ms'] ?? 50)));
        $maxResponseBytes = $this->normalizeMaxResponseBytes($options['max_response_bytes'] ?? null);
        $initialMetrics = is_array($options['initial_metrics'] ?? null) ? $options['initial_metrics'] : [];
        $phaseResumeState = is_array($options['phase_resume_state'] ?? null) ? $options['phase_resume_state'] : [];
        $onPageComplete = is_callable($options['on_page_complete'] ?? null) ? $options['on_page_complete'] : null;
        $phases = $options['phases'] ?? RestoreEventPhase::all();
        if (!is_array($phases) || $phases === []) {
            $phases = RestoreEventPhase::all();
        }

        $storedIdentity = (new SyncBranchIdentity())->find($conn);
        $configuredBranchUuid = strtolower(trim((string) ($config['branch']['uuid'] ?? '')));
        $storedBranchUuid = strtolower(trim((string) ($storedIdentity['branch_uuid'] ?? '')));
        if ($storedBranchUuid !== '' && $configuredBranchUuid !== '' && $storedBranchUuid !== $configuredBranchUuid) {
            throw new RuntimeException('Configured branch UUID does not match the persisted branch identity.');
        }
        $branchUuid = $storedBranchUuid !== '' ? $storedBranchUuid : $configuredBranchUuid;
        $cloudBaseUrl = rtrim(trim((string) (
            $storedIdentity['cloud_base_url'] ?? ($config['branch']['cloud_base_url'] ?? '')
        )), '/');
        $branchSecret = (string) ($config['sync']['branch_secret'] ?? '');

        if ($branchUuid === '' || $cloudBaseUrl === '' || $branchSecret === '') {
            throw new InvalidArgumentException('Branch UUID, cloud base URL, and sync secret are required to restore from hosted.');
        }

        $summary = [
            'apply' => $apply,
            'branch_uuid' => $branchUuid,
            'cloud_base_url' => $cloudBaseUrl,
            'contract_version' => $contractVersion,
            'recovery_profile' => $contractVersion === CloudBranchRestoreEventService::CONTRACT_V2
                ? $recoveryProfile
                : null,
            'snapshot_checkpoint' => $snapshotCheckpoint,
            'history_since_utc' => $historySinceUtc,
            'page_size' => $limit,
            'page_pause_ms' => $pagePauseMs,
            'max_response_bytes' => $maxResponseBytes,
            'phases' => [],
            'fetched' => max(0, (int) ($initialMetrics['fetched'] ?? 0)),
            'mirrored' => max(0, (int) ($initialMetrics['mirrored'] ?? 0)),
            'skipped' => max(0, (int) ($initialMetrics['skipped'] ?? 0)),
            'failed' => max(0, (int) ($initialMetrics['failed'] ?? 0)),
            'legacy_shift_closes_recovered' => 0,
            'http_retries' => 0,
            'errors' => [],
        ];

        foreach ($phases as $phaseName) {
            $phase = RestoreEventPhase::normalize((string) $phaseName);
            $resumePhase = is_array($phaseResumeState[$phase] ?? null) ? $phaseResumeState[$phase] : [];
            $afterId = max(0, (int) ($resumePhase['cursor'] ?? 0));
            $phaseSummary = [
                'phase' => $phase,
                'source' => $resumePhase !== [] ? $source : null,
                'resumed_from_cursor' => $afterId,
                'pages' => max(0, (int) ($resumePhase['pages'] ?? 0)),
                'fetched' => max(0, (int) ($resumePhase['fetched'] ?? 0)),
                'mirrored' => max(0, (int) ($resumePhase['mirrored'] ?? 0)),
                'skipped' => max(0, (int) ($resumePhase['skipped'] ?? 0)),
                'failed' => max(0, (int) ($resumePhase['failed'] ?? 0)),
                'legacy_shift_closes_recovered' => 0,
                'http_retries' => 0,
            ];
            if (!empty($resumePhase['complete'])) {
                $summary['phases'][] = $phaseSummary;
                continue;
            }
            $maxPages = max(0, (int) ($options['max_pages_per_phase'] ?? 0));

            do {
                $pageStartCursor = $afterId;
                $pageBefore = [
                    'fetched' => $phaseSummary['fetched'],
                    'mirrored' => $phaseSummary['mirrored'],
                    'skipped' => $phaseSummary['skipped'],
                    'failed' => $phaseSummary['failed'],
                ];
                $response = $this->fetchPageWithRetry(
                    $cloudBaseUrl,
                    $branchUuid,
                    $branchSecret,
                    $phase,
                    $afterId,
                    $limit,
                    $source,
                    $contractVersion,
                    $recoveryProfile,
                    $snapshotCheckpoint,
                    $historySinceUtc,
                    $config,
                    $options
                );

                $pageRetries = max(0, (int) ($response['_http_retries'] ?? 0));
                $phaseSummary['http_retries'] += $pageRetries;
                $summary['http_retries'] += $pageRetries;

                $responseJson = is_array($response['json'] ?? null) ? $response['json'] : null;
                if (empty($response['ok']) || $responseJson === null || empty($responseJson['ok'])) {
                    if ($responseJson !== null) {
                        $reason = (string) ($responseJson['reason'] ?? 'export_failed');
                    } else {
                        $status = (int) ($response['status'] ?? 0);
                        $reason = $status > 0 ? 'http_status_' . $status : 'invalid_or_unreachable_response';
                    }
                    $attempts = max(1, (int) ($response['_http_attempts'] ?? 1));
                    throw new RuntimeException('Hosted restore export failed after ' . $attempts . ' attempt(s): ' . $reason);
                }

                $page = $responseJson;
                if ($contractVersion === CloudBranchRestoreEventService::CONTRACT_V2) {
                    $pageVersion = (int) ($page['contract_version'] ?? 0);
                    $pageProfile = (string) ($page['recovery_profile'] ?? '');
                    $pageCheckpoint = $page['snapshot_checkpoint'] ?? null;
                    $pageHistorySinceUtc = $page['history_since_utc'] ?? null;
                    if ($pageVersion !== CloudBranchRestoreEventService::CONTRACT_V2
                        || $pageProfile !== $recoveryProfile
                        || !is_int($pageCheckpoint)
                        || $pageCheckpoint < 0
                        || ($snapshotCheckpoint !== null && $pageCheckpoint !== $snapshotCheckpoint)
                        || !is_string($pageHistorySinceUtc)
                        || ($historySinceUtc !== null && $pageHistorySinceUtc !== $historySinceUtc)) {
                        throw new RuntimeException('Hosted restore export returned inconsistent recovery-v2 metadata.');
                    }
                    $snapshotCheckpoint = $pageCheckpoint;
                    $historySinceUtc = $pageHistorySinceUtc;
                    $summary['snapshot_checkpoint'] = $snapshotCheckpoint;
                    $summary['history_since_utc'] = $historySinceUtc;
                }
                if ($phaseSummary['source'] === null) {
                    $phaseSummary['source'] = (string) ($page['source'] ?? '');
                    if ($contractVersion === CloudBranchRestoreEventService::CONTRACT_V1 && $source === 'auto') {
                        $source = $phaseSummary['source'];
                    } elseif ($phaseSummary['source'] !== $source) {
                        throw new RuntimeException('Hosted restore export changed the requested source.');
                    }
                }

                $phaseSummary['pages']++;
                $events = is_array($page['events'] ?? null) ? $page['events'] : [];

                foreach ($events as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $event = $entry['event'] ?? null;
                    if (!is_array($event)) {
                        $phaseSummary['skipped']++;
                        $summary['skipped']++;
                        continue;
                    }

                    $phaseSummary['fetched']++;
                    $summary['fetched']++;

                    if (!$apply) {
                        $phaseSummary['mirrored']++;
                        $summary['mirrored']++;
                        continue;
                    }

                    try {
                        $conn->begin_transaction();
                        $result = $this->applyService->apply($conn, $branchUuid, $event);
                        $conn->commit();
                        if ($result) {
                            $phaseSummary['mirrored']++;
                            $summary['mirrored']++;
                            if (!empty($result['recovered_legacy_shift_close'])) {
                                $phaseSummary['legacy_shift_closes_recovered']++;
                                $summary['legacy_shift_closes_recovered']++;
                            }
                        } else {
                            $phaseSummary['skipped']++;
                            $summary['skipped']++;
                        }
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $phaseSummary['failed']++;
                        $summary['failed']++;
                        if (count($summary['errors']) < 10) {
                            $summary['errors'][] = [
                                'phase' => $phase,
                                'restore_id' => $entry['restore_id'] ?? null,
                                'message' => $e->getMessage(),
                            ];
                        }
                    }
                }

                $afterId = max($afterId, (int) ($page['next_after_id'] ?? $afterId));
                $remoteHasMore = !empty($page['has_more']);
                $hasMore = $remoteHasMore;
                if ($apply && $onPageComplete !== null) {
                    $pageMetrics = [
                        'pages' => 1,
                        'fetched' => $phaseSummary['fetched'] - $pageBefore['fetched'],
                        'mirrored' => $phaseSummary['mirrored'] - $pageBefore['mirrored'],
                        'skipped' => $phaseSummary['skipped'] - $pageBefore['skipped'],
                        'failed' => $phaseSummary['failed'] - $pageBefore['failed'],
                    ];
                    if ($pageMetrics['skipped'] > 0 || $pageMetrics['failed'] > 0) {
                        throw new RuntimeException('Restore page did not reconcile; durable cursor was not advanced.');
                    }
                    $onPageComplete($phase, $pageStartCursor, $afterId, !$remoteHasMore, $pageMetrics);
                }
                if ($maxPages > 0 && $phaseSummary['pages'] >= $maxPages) {
                    $hasMore = false;
                }
                if ($hasMore && $pagePauseMs > 0) {
                    usleep($pagePauseMs * 1000);
                }
            } while ($hasMore);

            $summary['phases'][] = $phaseSummary;
        }

        return $summary;
    }

    private function fetchPage(
        string $cloudBaseUrl,
        string $branchUuid,
        string $branchSecret,
        string $phase,
        int $afterId,
        int $limit,
        string $source,
        int $contractVersion,
        string $recoveryProfile,
        ?int $snapshotCheckpoint,
        ?string $historySinceUtc,
        array $config,
        array $options
    ): array {
        $signatureBody = CloudBranchRestoreEventService::exportSignatureBody(
            $branchUuid,
            $phase,
            $afterId,
            $limit,
            $source,
            [
                'contract_version' => $contractVersion,
                'recovery_profile' => $recoveryProfile,
                'snapshot_checkpoint' => $snapshotCheckpoint,
                'history_since_utc' => $historySinceUtc,
            ]
        );
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(12));
        $headers = [
            'Accept: application/json',
            'X-POSMAIN-Branch-UUID: ' . $branchUuid,
            'X-POSMAIN-Timestamp: ' . $timestamp,
            'X-POSMAIN-Nonce: ' . $nonce,
            'X-POSMAIN-Signature: ' . CloudAuthService::sign($branchSecret, $timestamp, $nonce, $signatureBody),
        ];
        $url = $this->exportUrl(
            $cloudBaseUrl,
            $branchUuid,
            $phase,
            $afterId,
            $limit,
            $source,
            $contractVersion,
            $recoveryProfile,
            $snapshotCheckpoint,
            $historySinceUtc
        );
        $maxResponseBytes = $this->normalizeMaxResponseBytes($options['max_response_bytes'] ?? null);

        if (isset($options['http_get']) && is_callable($options['http_get'])) {
            return $this->enforceResponseSize(
                $options['http_get']($url, $headers, $config),
                $maxResponseBytes
            );
        }

        if (function_exists('curl_init')) {
            return $this->getWithCurl($url, $headers, $config, $maxResponseBytes);
        }

        return $this->getWithStreams($url, $headers, $config, $maxResponseBytes);
    }

    private function fetchPageWithRetry(
        string $cloudBaseUrl,
        string $branchUuid,
        string $branchSecret,
        string $phase,
        int $afterId,
        int $limit,
        string $source,
        int $contractVersion,
        string $recoveryProfile,
        ?int $snapshotCheckpoint,
        ?string $historySinceUtc,
        array $config,
        array $options
    ): array {
        $maxAttempts = max(1, min(8, (int) ($options['http_max_attempts'] ?? 5)));
        $delayMs = max(0, min(5000, (int) ($options['http_retry_delay_ms'] ?? 250)));
        $response = [];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = $this->fetchPage(
                $cloudBaseUrl,
                $branchUuid,
                $branchSecret,
                $phase,
                $afterId,
                $limit,
                $source,
                $contractVersion,
                $recoveryProfile,
                $snapshotCheckpoint,
                $historySinceUtc,
                $config,
                $options
            );
            $response['_http_attempts'] = $attempt;
            $response['_http_retries'] = $attempt - 1;

            if (!empty($response['ok']) && is_array($response['json'] ?? null)) {
                return $response;
            }

            $status = (int) ($response['status'] ?? 0);
            $invalidSuccessResponse = $status >= 200 && $status < 300 && !is_array($response['json'] ?? null);
            $retryable = $status === 0
                || $status === 408
                || $status === 425
                || $status === 429
                || $status >= 500
                || $invalidSuccessResponse;
            if (!$retryable || $attempt >= $maxAttempts) {
                return $response;
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
                $delayMs = min(5000, max(250, $delayMs * 2));
            }
        }

        return $response + [
            '_http_attempts' => $maxAttempts,
            '_http_retries' => max(0, $maxAttempts - 1),
        ];
    }

    private function exportUrl(
        string $cloudBaseUrl,
        string $branchUuid,
        string $phase,
        int $afterId,
        int $limit,
        string $source,
        int $contractVersion,
        string $recoveryProfile,
        ?int $snapshotCheckpoint,
        ?string $historySinceUtc
    ): string {
        $base = preg_match('#/api/sync/export_branch_restore\.php$#', $cloudBaseUrl)
            ? $cloudBaseUrl
            : rtrim($cloudBaseUrl, '/') . '/api/sync/export_branch_restore.php';

        $query = [
            'branch_uuid' => $branchUuid,
            'phase' => $phase,
            'after_id' => max(0, $afterId),
            'limit' => max(1, min(100, $limit)),
            'source' => $source !== '' ? $source : 'auto',
        ];
        if ($contractVersion === CloudBranchRestoreEventService::CONTRACT_V2) {
            $query['contract_version'] = CloudBranchRestoreEventService::CONTRACT_V2;
            $query['recovery_profile'] = $recoveryProfile;
            if ($snapshotCheckpoint !== null) {
                $query['snapshot_checkpoint'] = $snapshotCheckpoint;
            }
            if ($historySinceUtc !== null) {
                $query['history_since_utc'] = $historySinceUtc;
            }
        }

        return $base . '?' . http_build_query($query);
    }

    private function getWithCurl(string $url, array $headers, array $config, int $maxResponseBytes): array
    {
        $ch = curl_init($url);
        $body = '';
        $tooLarge = false;
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => max(5, (int) ($config['sync']['http_timeout_seconds'] ?? 30)),
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge, $maxResponseBytes): int {
                if (strlen($body) + strlen($chunk) > $maxResponseBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($tooLarge) {
            return ['ok' => false, 'status' => 413, 'error' => 'restore_response_too_large'];
        }
        if ($result === false) {
            return ['ok' => false, 'status' => $status, 'error' => $error !== '' ? $error : 'curl_failed'];
        }

        $json = json_decode($body, true);

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'json' => is_array($json) ? $json : null,
            'body' => $body,
        ];
    }

    private function getWithStreams(string $url, array $headers, array $config, int $maxResponseBytes): array
    {
        $headerLines = implode("\r\n", $headers);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $headerLines . "\r\n",
                'timeout' => max(5, (int) ($config['sync']['http_timeout_seconds'] ?? 30)),
                'ignore_errors' => true,
            ],
        ]);
        $stream = @fopen($url, 'rb', false, $context);
        $body = false;
        $responseHeaders = [];
        if (is_resource($stream)) {
            $body = stream_get_contents($stream, $maxResponseBytes + 1);
            $metadata = stream_get_meta_data($stream);
            $responseHeaders = is_array($metadata['wrapper_data'] ?? null)
                ? $metadata['wrapper_data']
                : [];
            fclose($stream);
        }
        if (is_string($body) && strlen($body) > $maxResponseBytes) {
            return ['ok' => false, 'status' => 413, 'error' => 'restore_response_too_large'];
        }
        $status = 0;
        if (isset($responseHeaders[0]) && preg_match('#\s(\d{3})\s#', (string) $responseHeaders[0], $matches)) {
            $status = (int) $matches[1];
        }
        $json = is_string($body) ? json_decode($body, true) : null;

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'json' => is_array($json) ? $json : null,
            'body' => is_string($body) ? $body : '',
        ];
    }

    private function normalizeMaxResponseBytes($value): int
    {
        $default = 8 * 1024 * 1024;
        $bytes = $value === null ? $default : (int) $value;

        return max(64 * 1024, min($default, $bytes));
    }

    private function enforceResponseSize(array $response, int $maxResponseBytes): array
    {
        $body = $response['body'] ?? null;
        if (is_string($body)) {
            $size = strlen($body);
        } else {
            $encoded = json_encode($response['json'] ?? null, JSON_UNESCAPED_SLASHES);
            $size = is_string($encoded) ? strlen($encoded) : 0;
        }
        if ($size > $maxResponseBytes) {
            return ['ok' => false, 'status' => 413, 'error' => 'restore_response_too_large'];
        }

        return $response;
    }

    private function restoreRunBinding(string $runUuid, array $plan, array $authorization): array
    {
        return [
            'run_uuid' => $runUuid,
            'branch_uuid' => (string) ($plan['branch_uuid'] ?? ''),
            'contract_version' => (int) ($plan['contract_version'] ?? 0),
            'source' => 'cloud_snapshot',
            'recovery_profile' => (string) ($plan['recovery_profile'] ?? ''),
            'snapshot_checkpoint' => (int) ($plan['snapshot_checkpoint'] ?? -1),
            'history_since_utc' => (string) ($plan['history_since_utc'] ?? ''),
            'manifest_hash' => (string) ($authorization['manifest_hash'] ?? ''),
            'expected_events' => (int) ($authorization['expected_events'] ?? -1),
            'confirmation_token' => (string) ($authorization['confirmation_token'] ?? ''),
            'backup_sha256' => (string) ($authorization['backup']['sha256'] ?? ''),
        ];
    }

    private function recordRestoreCheckpoint(mysqli $conn, string $branchUuid, array $summary): void
    {
        if (!self::tableExists($conn, 'sync_checkpoints')) {
            return;
        }

        $payload = json_encode([
            'restored_at' => gmdate('c'),
            'mirrored' => (int) ($summary['mirrored'] ?? 0),
            'failed' => (int) ($summary['failed'] ?? 0),
        ], JSON_UNESCAPED_SLASHES);

        $stream = 'branch_restore_from_hosted';
        $stmt = $conn->prepare("
            INSERT INTO sync_checkpoints (branch_uuid, stream_name, last_cursor, last_event_time)
            VALUES (?, ?, ?, NOW(6))
            ON DUPLICATE KEY UPDATE
                last_cursor = VALUES(last_cursor),
                last_event_time = NOW(6)
        ");
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('sss', $branchUuid, $stream, $payload);
        $stmt->execute();
        $stmt->close();
    }

    private function startBackgroundImageDownload(mysqli $conn, array $config, string $branchUuid): array
    {
        if (empty($config['sync']['image_sync_enabled'])) {
            return ['enabled' => false];
        }

        $queueService = new ItemImageSyncQueueService();
        $queued = $queueService->scanBranchDownloadQueue($conn, $branchUuid);
        $counts = $queueService->countByStatus($conn, $branchUuid, 'cloud_to_branch');
        $pending = (int) ($counts['pending'] ?? 0) + (int) ($counts['failed'] ?? 0);
        $spawned = false;
        if ($pending > 0) {
            $spawned = (new BranchImageSyncService())->spawnBackgroundWorker();
        }

        return [
            'enabled' => true,
            'queued' => $queued,
            'pending' => $pending,
            'spawned' => $spawned,
        ];
    }

    private static function tableExists(mysqli $conn, string $table): bool
    {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
        $exists = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }

        return $exists;
    }
}
