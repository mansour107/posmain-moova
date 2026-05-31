<?php

class InventoryReconciliationAcceptanceService
{
    private const REQUIRED_FIELDS = [
        'pos_tenant',
        'pos_branch',
        'store_id',
        'item_id',
        'legacy_qty',
        'fat_details_qty',
        'ledger_qty',
        'balance_qty',
    ];

    public function loadFile(string $path): array
    {
        $path = trim($path);
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return [
                'entries' => [],
                'blockers' => ['readable_reconciliation_acceptance_file_required'],
            ];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [
                'entries' => [],
                'blockers' => ['reconciliation_acceptance_file_empty'],
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'entries' => [],
                'blockers' => ['reconciliation_acceptance_file_invalid_json'],
            ];
        }

        $entries = $decoded['accepted_differences'] ?? $decoded['acceptances'] ?? $decoded;
        if (!is_array($entries)) {
            return [
                'entries' => [],
                'blockers' => ['reconciliation_acceptance_file_missing_entries'],
            ];
        }

        return [
            'entries' => $entries,
            'file' => $path,
            'blockers' => [],
        ];
    }

    public function evaluate(array $rows, array $entries): array
    {
        $blockers = [];
        $acceptedByKey = [];
        $acceptedMetaByKey = [];

        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                $blockers[] = 'reconciliation_acceptance_entry_' . $index . '_invalid';
                continue;
            }
            $entryErrors = $this->validateEntry($entry);
            foreach ($entryErrors as $error) {
                $blockers[] = 'reconciliation_acceptance_entry_' . $index . '_' . $error;
            }
            if ($entryErrors) {
                continue;
            }

            $key = $this->keyFromPayload($entry);
            if (isset($acceptedByKey[$key])) {
                $blockers[] = 'duplicate_reconciliation_acceptance_entry';
                continue;
            }

            $acceptedByKey[$key] = false;
            $acceptedMetaByKey[$key] = [
                'accepted_by' => (string) $entry['accepted_by'],
                'accepted_at_utc' => (string) $entry['accepted_at_utc'],
                'acceptance_reason' => (string) $entry['reason'],
            ];
        }

        $acceptedCount = 0;
        $unacceptedCount = 0;
        foreach ($rows as &$row) {
            $row['accepted_reconciliation'] = false;
            if (empty($row['has_difference'])) {
                continue;
            }

            $key = $this->keyFromPayload($row);
            if (array_key_exists($key, $acceptedByKey)) {
                $acceptedByKey[$key] = true;
                $row['accepted_reconciliation'] = true;
                foreach ($acceptedMetaByKey[$key] as $field => $value) {
                    $row[$field] = $value;
                }
                $acceptedCount++;
                continue;
            }

            $unacceptedCount++;
        }
        unset($row);

        $unused = [];
        foreach ($acceptedByKey as $key => $used) {
            if (!$used) {
                $unused[] = $key;
            }
        }
        if ($unused) {
            $blockers[] = 'unused_reconciliation_acceptance_entries';
        }

        return [
            'rows' => $rows,
            'summary' => [
                'accepted_difference_count' => $acceptedCount,
                'unaccepted_difference_count' => $unacceptedCount,
                'unused_acceptance_count' => count($unused),
                'acceptance_entry_count' => count($acceptedByKey),
            ],
            'unused_acceptance_keys' => $unused,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    private function validateEntry(array $entry): array
    {
        $errors = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $entry) || trim((string) $entry[$field]) === '') {
                $errors[] = 'missing_' . $field;
            }
        }

        if ($this->reasonsCsv($entry) === '') {
            $errors[] = 'missing_difference_reasons';
        }
        foreach (['accepted_by', 'accepted_at_utc', 'reason'] as $field) {
            if (!array_key_exists($field, $entry) || trim((string) $entry[$field]) === '') {
                $errors[] = 'missing_' . $field;
            }
        }
        if (!empty($entry['accepted_at_utc'])
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $entry['accepted_at_utc']) !== 1
        ) {
            $errors[] = 'invalid_accepted_at_utc';
        }

        return $errors;
    }

    private function keyFromPayload(array $payload): string
    {
        return implode('|', [
            (int) ($payload['pos_tenant'] ?? 0),
            (int) ($payload['pos_branch'] ?? 0),
            (int) ($payload['store_id'] ?? 0),
            (int) ($payload['item_id'] ?? 0),
            $this->reasonsCsv($payload),
            (string) ($payload['legacy_qty'] ?? ''),
            (string) ($payload['fat_details_qty'] ?? ''),
            (string) ($payload['ledger_qty'] ?? ''),
            (string) ($payload['balance_qty'] ?? ''),
        ]);
    }

    private function reasonsCsv(array $payload): string
    {
        $reasons = $payload['difference_reasons'] ?? null;
        if (!is_array($reasons)) {
            $compact = trim((string) ($payload['difference_reason'] ?? ''));
            $reasons = $compact === '' ? [] : explode(',', $compact);
        }

        $reasons = array_values(array_filter(array_map(static function ($reason): string {
            return trim((string) $reason);
        }, $reasons), static fn(string $reason): bool => $reason !== ''));
        sort($reasons);

        return implode(',', $reasons);
    }
}
