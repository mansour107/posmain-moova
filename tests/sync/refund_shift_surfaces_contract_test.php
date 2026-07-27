<?php

$root = dirname(__DIR__, 2);
$failures = [];

foreach ([
    'shift_sales_report.php',
    'print/daily_sales_receipt.php',
    'print/shift_sales_receipt.php',
] as $relative) {
    $contents = file_get_contents($root . '/' . $relative);
    if ($contents === false) {
        $failures[] = "missing:{$relative}";
        continue;
    }
    foreach ([
        "\$totals['total_gross']",
        "\$totals['total_discount']",
        "\$totals['total_refunds']",
        "\$totals['total_net']",
        "\$sales_data['total_refunds']",
    ] as $needle) {
        if (strpos($contents, $needle) === false) {
            $failures[] = "missing:{$relative}:{$needle}";
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "refund-shift-surfaces-contract-FAILED\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "refund-shift-surfaces-contract-ok\n";
