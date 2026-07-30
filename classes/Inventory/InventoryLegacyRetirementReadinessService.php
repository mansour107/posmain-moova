<?php

class InventoryLegacyRetirementReadinessService
{
    public function review(?mysqli $conn = null, ?string $root = null): array
    {
        $root = $root ?: dirname(__DIR__, 2);
        $pending = [];
        $proven = [];

        $this->reviewLiveModeControls($root, $pending, $proven);
        $triggerNames = $conn instanceof mysqli
            ? $this->legacyTriggerNames($conn)
            : $this->legacyTriggerNamesFromSchema($root);
        if ($triggerNames) {
            $pending[] = 'fat_details_stock_triggers_still_defined_in_db_schema';
        } else {
            $proven[] = 'fat_details_stock_triggers_removed_from_db_schema';
        }

        foreach ($this->directMyitemsUpdates($root) as $update) {
            if ($update['file'] === 'classes/Inventory/InventoryLedgerService.php') {
                $proven[] = 'myitems_itmqty_mirror_update_owned_by_inventory_ledger_service';
                continue;
            }
            if ($update['file'] === 'classes/Inventory/InventoryLegacyMirrorService.php') {
                $proven[] = 'legacy_opening_balance_mirror_update_owned_by_inventory_legacy_mirror_service';
                continue;
            }
            if ($update['file'] === 'save_start_balance.php') {
                $pending[] = 'legacy_opening_balance_still_contains_direct_myitems_itmqty_refresh';
                continue;
            }
            $pending[] = 'direct_myitems_itmqty_update:' . $update['file'] . ':' . $update['line'];
        }

        foreach ($this->unsafeLegacyStockEndpoints($root) as $endpoint) {
            if ($this->sourceContainsRetiredEndpoint($root . '/' . $endpoint['file'])) {
                $proven[] = 'legacy_stock_endpoint_retired:' . $endpoint['file'];
                continue;
            }
            if ($this->sourceContainsLiveGuard($root . '/' . $endpoint['file'])) {
                $proven[] = 'legacy_stock_endpoint_live_guarded:' . $endpoint['file'];
            }
            $pending[] = 'unsafe_legacy_stock_endpoint_still_present:' . $endpoint['file'] . ':' . $endpoint['reason'];
        }

        $pending = array_values(array_unique($pending));
        sort($pending);
        $proven = array_values(array_unique($proven));
        sort($proven);

        return [
            'ok' => empty($pending),
            'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'phase' => '16',
            'ready_to_delete_legacy_stock_core' => empty($pending),
            'trigger_names' => $triggerNames,
            'proven_controls' => $proven,
            'pending_retirement_items' => $pending,
            'blockers' => $pending,
        ];
    }

    private function reviewLiveModeControls(string $root, array &$pending, array &$proven): void
    {
        $sidebar = $this->source($root . '/includes/sidebar.php');
        $openingPage = $this->source($root . '/items_start_balance.php');
        $openingSave = $this->source($root . '/save_start_balance.php');

        if (strpos($sidebar, '$posmainInventoryLegacyOpeningBalanceVisible') !== false) {
            $proven[] = 'sidebar_hides_legacy_opening_balance_in_live_mode';
        } else {
            $pending[] = 'sidebar_still_exposes_legacy_opening_balance_without_live_guard';
        }

        if (strpos($openingPage, 'inventory_adjustments.php?legacy_opening_balance=retired') !== false) {
            $proven[] = 'legacy_opening_balance_page_redirects_in_live_mode';
        } else {
            $pending[] = 'legacy_opening_balance_page_not_retired_in_live_mode';
        }

        if (strpos($openingSave, 'opening_balance_legacy_workflow_retired_in_live_inventory_mode') !== false) {
            $proven[] = 'legacy_opening_balance_post_blocked_in_live_mode';
        } else {
            $pending[] = 'legacy_opening_balance_post_not_blocked_in_live_mode';
        }
    }

    private function directMyitemsUpdates(string $root): array
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $matches = [];
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (
                strpos($path, '/vendor/') !== false
                || strpos($path, '/PhpSpreadsheet/') !== false
                || strpos($path, '/tests/') !== false
                || strpos($path, '/var/') !== false
                || strpos($path, '/output/') !== false
                || strpos($path, '/docs/') !== false
                || strpos($path, '/node_modules/') !== false
                || strpos($path, '/.git/') !== false
                || strpos($path, '/.codex/') !== false
                || strpos($path, '/.agents/') !== false
            ) {
                continue;
            }
            $relative = ltrim(str_replace($root, '', $path), '/');
            $source = file_get_contents($path);
            if (!is_string($source)) {
                continue;
            }
            if (preg_match_all('/(["\'])(?:\\\\.|(?!\1).)*\bUPDATE\s+`?myitems`?\s+SET\b(?:\\\\.|(?!\1).)*\bitmqty\b(?:\\\\.|(?!\1).)*\1/is', $source, $statementMatches, PREG_OFFSET_CAPTURE)) {
                foreach ($statementMatches[0] as $match) {
                    $updateOffset = stripos((string) $match[0], 'UPDATE');
                    $absoluteOffset = (int) $match[1] + ($updateOffset === false ? 0 : $updateOffset);
                    $matches[] = [
                        'file' => $relative,
                        'line' => substr_count(substr($source, 0, $absoluteOffset), "\n") + 1,
                    ];
                }
            }
        }

        return $matches;
    }

    private function unsafeLegacyStockEndpoints(string $root): array
    {
        $knownUnsafe = [
            'js/ajax/reindex.php' => 'global_myitems_itmqty_reindex_from_fat_details',
            'js/ajax/add_row_to_fat_details.php' => 'detached_fat_details_stock_insert',
            'js/ajax/insertfatdet.php' => 'detached_fat_details_stock_insert',
            'js/ajax/delitmdet.php' => 'hard_delete_fat_details_stock_history',
            'do/offline_sync.php' => 'legacy_offline_stock_replay_still_present',
            'pos_sync.php' => 'legacy_pos_sync_stock_replay_still_present',
            'do/doadd_invoice_clothes.php' => 'specialized_invoice_stock_writer_still_present',
        ];

        $present = [];
        foreach ($knownUnsafe as $relative => $reason) {
            if (is_file($root . '/' . $relative)) {
                $present[] = [
                    'file' => $relative,
                    'reason' => $reason,
                ];
            }
        }

        return $present;
    }

    private function sourceContainsLiveGuard(string $path): bool
    {
        $source = file_get_contents($path);
        if (!is_string($source)) {
            return false;
        }

        return strpos($source, 'InventoryLegacyStockEndpointGuard::blockIfLive') !== false;
    }

    private function sourceContainsRetiredEndpoint(string $path): bool
    {
        $source = file_get_contents($path);
        if (!is_string($source)) {
            return false;
        }

        return strpos($source, 'InventoryRetiredLegacyEndpoint::respond') !== false;
    }

    private function legacyTriggerNames(mysqli $conn): array
    {
        $stmt = $conn->prepare("
SELECT TRIGGER_NAME
FROM INFORMATION_SCHEMA.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND EVENT_OBJECT_TABLE = 'fat_details'
  AND TRIGGER_NAME IN ('update_after_update', 'update_balance_trigger')
ORDER BY TRIGGER_NAME");
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map(static fn(array $row): string => (string) $row['TRIGGER_NAME'], $rows);
    }

    private function legacyTriggerNamesFromSchema(string $root): array
    {
        $schema = $this->source($root . '/db/DB.sql');
        preg_match_all('/CREATE\s+TRIGGER\s+`?(update_after_update|update_balance_trigger)`?/i', $schema, $matches);

        return array_values(array_unique(array_map(static fn(string $name): string => $name, $matches[1] ?? [])));
    }

    private function source(string $path): string
    {
        $source = file_get_contents($path);
        if (!is_string($source)) {
            throw new RuntimeException('Unable to read ' . $path);
        }

        return $source;
    }
}
