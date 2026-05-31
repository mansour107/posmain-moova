<?php

class InventoryAccountingReconciliationAcceptanceService
{
    private const REQUIRED_FIELDS = [
        'review_key',
        'reconciliation_status',
        'sample_movement_type',
        'sample_source_type',
        'movement_count',
        'movement_total',
        'journal_debit_total',
        'journal_credit_total',
    ];

    public function loadFile(string $path): array
    {
        $path = trim($path);
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return [
                'entries' => [],
                'blockers' => ['readable_accounting_reconciliation_acceptance_file_required'],
            ];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [
                'entries' => [],
                'blockers' => ['accounting_reconciliation_acceptance_file_empty'],
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'entries' => [],
                'blockers' => ['accounting_reconciliation_acceptance_file_invalid_json'],
            ];
        }

        $entries = $decoded['accepted_accounting_problems'] ?? $decoded['acceptances'] ?? $decoded;
        if (!is_array($entries)) {
            return [
                'entries' => [],
                'blockers' => ['accounting_reconciliation_acceptance_file_missing_entries'],
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
                $blockers[] = 'accounting_reconciliation_acceptance_entry_' . $index . '_invalid';
                continue;
            }

            $entryErrors = $this->validateEntry($entry);
            foreach ($entryErrors as $error) {
                $blockers[] = 'accounting_reconciliation_acceptance_entry_' . $index . '_' . $error;
            }
            if ($entryErrors) {
                continue;
            }

            $key = $this->keyFromPayload($entry);
            if (isset($acceptedByKey[$key])) {
                $blockers[] = 'duplicate_accounting_reconciliation_acceptance_entry';
                continue;
            }

            $acceptedByKey[$key] = false;
            $acceptedMetaByKey[$key] = [
                'accepted_accounting_by' => (string) $entry['accepted_by'],
                'accepted_accounting_at_utc' => (string) $entry['accepted_at_utc'],
                'accounting_acceptance_reason' => (string) $entry['reason'],
            ];
        }

        $acceptedCount = 0;
        $unacceptedCount = 0;
        foreach ($rows as &$row) {
            $row['accepted_accounting_reconciliation'] = false;
            if ((string) ($row['reconciliation_status'] ?? '') === 'balanced') {
                continue;
            }

            $key = $this->keyFromPayload($row);
            if (array_key_exists($key, $acceptedByKey)) {
                $acceptedByKey[$key] = true;
                $row['accepted_accounting_reconciliation'] = true;
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
            $blockers[] = 'unused_accounting_reconciliation_acceptance_entries';
        }

        return [
            'rows' => $rows,
            'summary' => [
                'accepted_problem_count' => $acceptedCount,
                'unaccepted_problem_count' => $unacceptedCount,
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
            (string) ($payload['review_key'] ?? ''),
            (string) ($payload['reconciliation_status'] ?? ''),
            (string) ($payload['accounting_journal_id'] ?? ''),
            (string) ($payload['sample_movement_type'] ?? ''),
            (string) ($payload['sample_source_type'] ?? ''),
            (string) ($payload['movement_count'] ?? ''),
            (string) ($payload['movement_total'] ?? ''),
            (string) ($payload['journal_debit_total'] ?? ''),
            (string) ($payload['journal_credit_total'] ?? ''),
        ]);
    }
}
