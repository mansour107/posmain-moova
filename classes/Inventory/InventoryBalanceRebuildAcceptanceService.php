<?php

class InventoryBalanceRebuildAcceptanceService
{
    private const REQUIRED_FIELDS = [
        'pos_tenant',
        'pos_branch',
        'store_id',
        'item_id',
        'derived_qty_on_hand',
        'current_qty_on_hand',
        'qty_difference',
        'derived_moving_average_cost',
        'current_moving_average_cost',
        'last_movement_id',
        'current_last_movement_id',
        'current_balance_exists',
        'has_difference',
        'has_cost_difference',
        'has_last_movement_difference',
    ];

    public function loadFile(string $path): array
    {
        $path = trim($path);
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return [
                'entries' => [],
                'blockers' => ['readable_balance_rebuild_acceptance_file_required'],
            ];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [
                'entries' => [],
                'blockers' => ['balance_rebuild_acceptance_file_empty'],
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'entries' => [],
                'blockers' => ['balance_rebuild_acceptance_file_invalid_json'],
            ];
        }

        $entries = $decoded['accepted_balance_rebuild_differences'] ?? $decoded['acceptances'] ?? $decoded;
        if (!is_array($entries)) {
            return [
                'entries' => [],
                'blockers' => ['balance_rebuild_acceptance_file_missing_entries'],
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
                $blockers[] = 'balance_rebuild_acceptance_entry_' . $index . '_invalid';
                continue;
            }

            $entryErrors = $this->validateEntry($entry);
            foreach ($entryErrors as $error) {
                $blockers[] = 'balance_rebuild_acceptance_entry_' . $index . '_' . $error;
            }
            if ($entryErrors) {
                continue;
            }

            $key = $this->keyFromPayload($entry);
            if (isset($acceptedByKey[$key])) {
                $blockers[] = 'duplicate_balance_rebuild_acceptance_entry';
                continue;
            }

            $acceptedByKey[$key] = false;
            $acceptedMetaByKey[$key] = [
                'accepted_balance_rebuild_by' => (string) $entry['accepted_by'],
                'accepted_balance_rebuild_at_utc' => (string) $entry['accepted_at_utc'],
                'balance_rebuild_acceptance_reason' => (string) $entry['reason'],
            ];
        }

        $acceptedCount = 0;
        $unacceptedCount = 0;
        foreach ($rows as &$row) {
            $row['accepted_balance_rebuild_difference'] = false;
            if (empty($row['needs_rebuild'])) {
                continue;
            }

            $key = $this->keyFromPayload($row);
            if (array_key_exists($key, $acceptedByKey)) {
                $acceptedByKey[$key] = true;
                $row['accepted_balance_rebuild_difference'] = true;
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
            $blockers[] = 'unused_balance_rebuild_acceptance_entries';
        }

        return [
            'rows' => $rows,
            'summary' => [
                'accepted_rebuild_candidate_count' => $acceptedCount,
                'unaccepted_rebuild_candidate_count' => $unacceptedCount,
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
        $presenceOnlyFields = [
            'current_balance_exists' => true,
            'has_difference' => true,
            'has_cost_difference' => true,
            'has_last_movement_difference' => true,
        ];
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $entry)) {
                $errors[] = 'missing_' . $field;
                continue;
            }
            if (!isset($presenceOnlyFields[$field]) && trim((string) $entry[$field]) === '') {
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
            (int) ($payload['pos_tenant'] ?? 0),
            (int) ($payload['pos_branch'] ?? 0),
            (int) ($payload['store_id'] ?? 0),
            (int) ($payload['item_id'] ?? 0),
            (string) ($payload['derived_qty_on_hand'] ?? ''),
            (string) ($payload['current_qty_on_hand'] ?? ''),
            (string) ($payload['qty_difference'] ?? ''),
            (string) ($payload['derived_moving_average_cost'] ?? ''),
            (string) ($payload['current_moving_average_cost'] ?? ''),
            (int) ($payload['last_movement_id'] ?? 0),
            (int) ($payload['current_last_movement_id'] ?? 0),
            !empty($payload['current_balance_exists']) ? '1' : '0',
            !empty($payload['has_difference']) ? '1' : '0',
            !empty($payload['has_cost_difference']) ? '1' : '0',
            !empty($payload['has_last_movement_difference']) ? '1' : '0',
        ]);
    }
}
