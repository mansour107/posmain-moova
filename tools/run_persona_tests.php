<?php

declare(strict_types=1);

require_once __DIR__ . '/../tests/personas/PersonaTestRunner.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'persona::',
    'all',
    'gui',
    'non-gui',
    'both',
    'list',
    'json',
    'continue-on-failure',
    'help',
]);

if (isset($options['help'])) {
    personaTestRunnerUsage();
    exit(0);
}

$root = dirname(__DIR__);
$personas = [];

if (isset($options['all'])) {
    $personas = ['all'];
} elseif (isset($options['persona'])) {
    $personas = array_values(array_filter(array_map('trim', explode(',', (string) $options['persona']))));
}

$layer = 'both';
if (isset($options['gui'])) {
    $layer = 'gui';
} elseif (isset($options['non-gui'])) {
    $layer = 'non_gui';
} elseif (isset($options['both'])) {
    $layer = 'both';
}

try {
    $runner = new PersonaTestRunner($root, [
        'personas' => $personas,
        'layer' => $layer,
        'json' => isset($options['json']),
        'list' => isset($options['list']),
        'continue_on_failure' => isset($options['continue-on-failure']),
    ]);
    $result = $runner->run();
} catch (Throwable $exception) {
    $result = [
        'ok' => false,
        'error' => $exception->getMessage(),
    ];
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    personaTestRunnerPrintHuman($result);
}

exit(!empty($result['ok']) ? 0 : 2);

function personaTestRunnerUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/run_persona_tests.php [--persona=cashier] [--persona=cashier,manager] [--all] [--gui|--non-gui|--both] [--list] [--json] [--continue-on-failure]\n\n");
    fwrite(STDOUT, "Personas: shared, cashier, waiter, manager, owner, sync_ops\n\n");
    fwrite(STDOUT, "Examples:\n");
    fwrite(STDOUT, "  php tools/run_persona_tests.php --list\n");
    fwrite(STDOUT, "  php tools/run_persona_tests.php --persona=cashier --non-gui\n");
    fwrite(STDOUT, "  php tools/run_persona_tests.php --persona=manager --gui\n");
    fwrite(STDOUT, "  php tools/run_persona_tests.php --all --both --continue-on-failure\n\n");
    fwrite(STDOUT, "Environment:\n");
    fwrite(STDOUT, "  POSMAIN_TEST_HTTP_BASE=http://127.0.0.1:8010\n");
    fwrite(STDOUT, "  POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307\n");
    fwrite(STDOUT, "  POSMAIN_E2E_DEMO_PASSWORD=P6demo123!  (after tools/seed_demo_restaurant.php --apply)\n");
}

/**
 * @param array<string,mixed> $result
 */
function personaTestRunnerPrintHuman(array $result): void
{
    if (isset($result['error'])) {
        fwrite(STDERR, 'Persona test runner failed: ' . $result['error'] . PHP_EOL);
        return;
    }

    if (isset($result['personas']) && !isset($result['non_gui']) && !isset($result['gui'])) {
        foreach ($result['personas'] as $personaId => $persona) {
            fwrite(STDOUT, '[' . $personaId . '] ' . ($persona['label'] ?? '') . PHP_EOL);
            fwrite(STDOUT, '  ' . ($persona['description'] ?? '') . PHP_EOL);
            foreach ($persona['non_gui'] ?? [] as $test) {
                fwrite(STDOUT, '  - non-gui: ' . $test['id'] . ' -> ' . $test['path'] . PHP_EOL);
            }
            foreach ($persona['gui'] ?? [] as $test) {
                fwrite(STDOUT, '  - gui: ' . $test['id'] . ' -> ' . $test['spec'] . PHP_EOL);
            }
            fwrite(STDOUT, PHP_EOL);
        }
        return;
    }

    $status = !empty($result['ok']) ? 'PASS' : 'FAIL';
    fwrite(STDOUT, 'Persona suite: ' . $status . PHP_EOL);

    foreach ($result['non_gui'] ?? [] as $personaId => $rows) {
        fwrite(STDOUT, PHP_EOL . '== ' . $personaId . ' (non-gui) ==' . PHP_EOL);
        foreach ($rows as $row) {
            $label = !empty($row['skipped']) ? 'SKIP' : (!empty($row['ok']) ? 'OK' : 'FAIL');
            fwrite(STDOUT, sprintf("  [%s] %s (%dms)\n", $label, $row['id'] ?? '?', $row['duration_ms'] ?? 0));
            if ($label === 'FAIL' && !empty($row['output_tail'])) {
                fwrite(STDOUT, $row['output_tail'] . PHP_EOL);
            }
        }
    }

    foreach ($result['gui'] ?? [] as $personaId => $row) {
        fwrite(STDOUT, PHP_EOL . '== ' . $personaId . ' (gui) ==' . PHP_EOL);
        if (!empty($row['skipped'])) {
            fwrite(STDOUT, '  [SKIP] ' . ($row['message'] ?? '') . PHP_EOL);
            continue;
        }
        $label = !empty($row['ok']) ? 'OK' : 'FAIL';
        fwrite(STDOUT, sprintf("  [%s] playwright project (%dms)\n", $label, $row['duration_ms'] ?? 0));
        if ($label === 'FAIL' && !empty($row['output_tail'])) {
            fwrite(STDOUT, $row['output_tail'] . PHP_EOL);
        }
    }
}
