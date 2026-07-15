#!/usr/bin/env php
<?php

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve repository root.\n");
    exit(1);
}

$jsonMode = in_array('--json', $argv, true);

$categories = [
    'pos_order' => ['ot_head', 'fat_details', 'ot_details', 'orders'],
    'table_state' => ['tables', 'pos_tables'],
    'payments/accounting' => ['journal_heads', 'journal_entries', 'acc_head', 'vouchers'],
    'shift_session' => ['drawer_sessions', 'drawer_session_close_summaries', 'shifts'],
    'menu_catalog' => ['myitems', 'items', 'item_units', 'item_group', 'item_group2', 'myunits'],
    'moova_bridge' => ['moova_pos_integrations', 'moova_pos_order_links', 'moova_pos_order_change_links', 'moova_pos_order_lines'],
    'user_admin' => ['users', 'usr_pwrs', 'roles'],
    'inventory_stock' => ['stores', 'productions', 'inv_operations'],
];

$excludePrefixes = [
    '.git/',
    'vendor/',
    'barcodegr/',
    'PhpSpreadsheet/',
    'src/Twilio/',
];

$files = listPhpFiles($root, $excludePrefixes);
$surfaces = [];

foreach ($files as $file) {
    $content = file_get_contents($root . '/' . $file);
    if ($content === false) {
        continue;
    }

    $writes = array_merge(findWrites($content), findDelegatedWrites($file, $content));
    if (!$writes) {
        continue;
    }

    $tables = array_values(array_unique(array_map('normalizeTable', array_column($writes, 'table'))));
    sort($tables);

    $surfaceCategories = classifySurface($file, $tables, $categories);
    $surfaces[] = [
        'path' => $file,
        'categories' => $surfaceCategories,
        'tables' => $tables,
        'writes' => $writes,
    ];
}

usort($surfaces, function ($a, $b) {
    return strcmp($a['path'], $b['path']);
});

$summary = [];
foreach ($categories as $category => $_) {
    $summary[$category] = 0;
}
$summary['other_business_write'] = 0;

foreach ($surfaces as $surface) {
    foreach ($surface['categories'] as $category) {
        $summary[$category] = ($summary[$category] ?? 0) + 1;
    }
}

$payload = [
    'generated_by' => 'tools/audit_write_paths.php',
    'scope' => 'PHP files with INSERT, UPDATE, DELETE, REPLACE, ALTER, CREATE, DROP, or configured delegated write surfaces',
    'categories' => array_keys($summary),
    'summary' => $summary,
    'surfaces' => $surfaces,
];

if ($jsonMode) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

renderMarkdown($payload);

function listPhpFiles($root, array $excludePrefixes)
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    $files = [];

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
            continue;
        }

        $relative = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($root) + 1));
        $excluded = false;
        foreach ($excludePrefixes as $prefix) {
            if (strpos($relative, $prefix) === 0) {
                $excluded = true;
                break;
            }
        }
        if (!$excluded) {
            $files[] = $relative;
        }
    }

    sort($files);
    return $files;
}

function findWrites($content)
{
    $writes = [];
    $patterns = [
        '/\b(INSERT)\s+INTO\s+`?([a-zA-Z0-9_]+)`?/i',
        '/\b(UPDATE)\s+`?([a-zA-Z0-9_]+)`?\s+SET\b/i',
        '/\b(DELETE)\s+FROM\s+`?([a-zA-Z0-9_]+)`?/i',
        '/\b(REPLACE)\s+INTO\s+`?([a-zA-Z0-9_]+)`?/i',
        '/\b(ALTER)\s+TABLE\s+`?([a-zA-Z0-9_]+)`?/i',
        '/\b(CREATE)\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([a-zA-Z0-9_]+)`?/i',
        '/\b(DROP)\s+TABLE(?:\s+IF\s+EXISTS)?\s+`?([a-zA-Z0-9_]+)`?/i',
    ];

    foreach ($patterns as $pattern) {
        if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            continue;
        }

        foreach ($matches as $match) {
            $line = substr_count(substr($content, 0, $match[0][1]), "\n") + 1;
            $writes[] = [
                'operation' => strtoupper($match[1][0]),
                'table' => normalizeTable($match[2][0]),
                'line' => $line,
            ];
        }
    }

    usort($writes, function ($a, $b) {
        return $a['line'] <=> $b['line'];
    });

    return $writes;
}

function normalizeTable($table)
{
    return strtolower(trim((string) $table, "` \t\n\r\0\x0B"));
}

function findDelegatedWrites($path, $content)
{
    $writes = [];

    if (
        $path === 'ajax/moova_confirm_order.php'
        && (
            strpos($content, 'MoovaNewOrderApplyService') !== false
            || (
                strpos($content, 'PosOrderMutationService') !== false
                && strpos($content, 'confirmMoovaOrder') !== false
            )
        )
    ) {
        $writes[] = [
            'operation' => 'DELEGATE',
            'table' => 'moova_pos_order_links',
            'line' => firstLineContaining($content, strpos($content, 'confirmMoovaOrder') !== false ? 'confirmMoovaOrder' : 'MoovaNewOrderApplyService'),
        ];
    }
    if (
        $path === 'ajax/moova_change_order.php'
        && (
            strpos($content, 'MoovaChangeOrderApplyService') !== false
            || (
                strpos($content, 'PosOrderMutationService') !== false
                && strpos($content, 'changeMoovaOrder') !== false
            )
        )
    ) {
        $writes[] = [
            'operation' => 'DELEGATE',
            'table' => 'moova_pos_order_change_links',
            'line' => firstLineContaining($content, strpos($content, 'changeMoovaOrder') !== false ? 'changeMoovaOrder' : 'MoovaChangeOrderApplyService'),
        ];
    }

    return $writes;
}

function firstLineContaining($content, $needle)
{
    $offset = strpos($content, $needle);
    if ($offset === false) {
        return 1;
    }

    return substr_count(substr($content, 0, $offset), "\n") + 1;
}

function classifySurface($path, array $tables, array $categories)
{
    $matched = [];

    foreach ($categories as $category => $categoryTables) {
        if (array_intersect($tables, $categoryTables)) {
            $matched[] = $category;
        }
    }

    if (strpos($path, 'moova_') !== false || strpos($path, 'cofe_') !== false || in_array('moova_bridge', $matched, true)) {
        $matched[] = 'moova_bridge';
    }
    if (strpos($path, 'ajax/moova_confirm_order.php') !== false || strpos($path, 'ajax/moova_change_order.php') !== false) {
        $matched[] = 'pos_order';
        $matched[] = 'table_state';
    }
    if (strpos($path, 'MoovaNewOrderApplyService.php') !== false) {
        $matched[] = 'pos_order';
        $matched[] = 'table_state';
        $matched[] = 'moova_bridge';
    }
    if (strpos($path, 'MoovaChangeOrderApplyService.php') !== false) {
        $matched[] = 'pos_order';
        $matched[] = 'table_state';
        $matched[] = 'moova_bridge';
    }
    if (strpos($path, 'close_shift') !== false) {
        $matched[] = 'shift_session';
    }
    if (strpos($path, 'doadd_invoice') !== false || strpos($path, 'PosOrderService.php') !== false || strpos($path, 'offline_sync.php') !== false) {
        $matched[] = 'pos_order';
    }
    if (strpos($path, 'item') !== false || strpos($path, 'group') !== false || strpos($path, 'unit') !== false) {
        $matched[] = 'menu_catalog';
    }

    $matched = array_values(array_unique($matched));
    if (!$matched) {
        $matched[] = 'other_business_write';
    }
    sort($matched);

    return $matched;
}

function renderMarkdown(array $payload)
{
    echo "# POSMAIN Write Surface Audit\n\n";
    echo "Scope: " . $payload['scope'] . ".\n\n";
    echo "## Summary\n\n";
    echo "| Category | PHP write paths |\n";
    echo "| --- | ---: |\n";
    foreach ($payload['summary'] as $category => $count) {
        echo '| ' . $category . ' | ' . $count . " |\n";
    }

    echo "\n## Surfaces\n\n";
    echo "| Path | Categories | Tables |\n";
    echo "| --- | --- | --- |\n";
    foreach ($payload['surfaces'] as $surface) {
        echo '| `' . $surface['path'] . '` | ' . implode(', ', $surface['categories']) . ' | ' . implode(', ', $surface['tables']) . " |\n";
    }
}
