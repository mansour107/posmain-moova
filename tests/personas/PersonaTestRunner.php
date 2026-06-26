<?php

declare(strict_types=1);

final class PersonaTestRunner
{
    private string $root;
    /** @var array<string,mixed> */
    private array $manifest;
    /** @var array<string,mixed> */
    private array $options;

    /**
     * @param array<string,mixed> $options
     */
    public function __construct(string $root, array $options = [])
    {
        $this->root = $root;
        $this->manifest = require $root . '/tests/personas/manifest.php';
        $this->options = array_replace([
            'personas' => [],
            'layer' => 'both',
            'json' => false,
            'list' => false,
            'continue_on_failure' => false,
            'php_binary' => PHP_BINARY,
        ], $options);
    }

    /**
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $personaIds = $this->resolvePersonaIds();
        if ($this->options['list']) {
            return $this->buildListPayload($personaIds);
        }

        $layer = (string) $this->options['layer'];
        $runNonGui = in_array($layer, ['both', 'non_gui', 'non-gui', 'runtime'], true);
        $runGui = in_array($layer, ['both', 'gui', 'e2e'], true);

        $results = [
            'ok' => true,
            'started_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'layer' => $layer,
            'personas' => $personaIds,
            'non_gui' => [],
            'gui' => [],
        ];

        foreach ($personaIds as $personaId) {
            $persona = $this->manifest['personas'][$personaId] ?? null;
            if (!is_array($persona)) {
                $results['ok'] = false;
                $results['non_gui'][$personaId] = [[
                    'id' => 'persona_missing',
                    'ok' => false,
                    'message' => 'Unknown persona: ' . $personaId,
                ]];
                continue;
            }

            if ($runNonGui) {
                $results['non_gui'][$personaId] = $this->runNonGuiSuite($personaId, $persona);
                foreach ($results['non_gui'][$personaId] as $row) {
                    if (empty($row['ok']) && empty($row['skipped'])) {
                        $results['ok'] = false;
                        if (!$this->options['continue_on_failure']) {
                            return $this->finalize($results);
                        }
                    }
                }
            }

            if ($runGui) {
                $results['gui'][$personaId] = $this->runGuiSuite($personaId, $persona);
                if (empty($results['gui'][$personaId]['ok'])) {
                    $results['ok'] = false;
                    if (!$this->options['continue_on_failure']) {
                        return $this->finalize($results);
                    }
                }
            }
        }

        return $this->finalize($results);
    }

    /**
     * @return list<string>
     */
    private function resolvePersonaIds(): array
    {
        $requested = $this->options['personas'];
        if ($requested === [] || in_array('all', $requested, true)) {
            return array_keys($this->manifest['personas']);
        }

        $valid = [];
        foreach ($requested as $personaId) {
            $personaId = trim((string) $personaId);
            if ($personaId === '') {
                continue;
            }
            if (!isset($this->manifest['personas'][$personaId])) {
                throw new InvalidArgumentException('Unknown persona: ' . $personaId);
            }
            $valid[] = $personaId;
        }

        if ($valid === []) {
            throw new InvalidArgumentException('No personas selected. Use --persona=cashier or --all.');
        }

        return $valid;
    }

    /**
     * @param list<string> $personaIds
     * @return array<string,mixed>
     */
    private function buildListPayload(array $personaIds): array
    {
        $payload = [
            'ok' => true,
            'personas' => [],
        ];

        foreach ($personaIds as $personaId) {
            $persona = $this->manifest['personas'][$personaId];
            $payload['personas'][$personaId] = [
                'label' => $persona['label'] ?? $personaId,
                'description' => $persona['description'] ?? '',
                'non_gui' => array_map(static function (array $test): array {
                    return [
                        'id' => $test['id'],
                        'path' => $test['path'],
                        'description' => $test['description'] ?? '',
                        'requires' => $test['requires'] ?? [],
                    ];
                }, $persona['non_gui'] ?? []),
                'gui' => array_map(static function (array $test): array {
                    return [
                        'id' => $test['id'],
                        'spec' => $test['spec'],
                        'description' => $test['description'] ?? '',
                    ];
                }, $persona['gui'] ?? []),
            ];
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $persona
     * @return list<array<string,mixed>>
     */
    private function runNonGuiSuite(string $personaId, array $persona): array
    {
        $rows = [];
        foreach ($persona['non_gui'] ?? [] as $test) {
            $rows[] = $this->runNonGuiTest($personaId, $test);
        }

        foreach ($persona['tools'] ?? [] as $tool) {
            $rows[] = $this->runToolTest($personaId, $tool);
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $test
     * @return array<string,mixed>
     */
    private function runNonGuiTest(string $personaId, array $test): array
    {
        $id = (string) ($test['id'] ?? 'unnamed');
        $path = (string) ($test['path'] ?? '');
        $absolute = $this->root . '/' . ltrim($path, '/');

        $row = [
            'persona' => $personaId,
            'id' => $id,
            'path' => $path,
            'ok' => false,
            'exit_code' => null,
            'duration_ms' => 0,
            'output_tail' => '',
        ];

        if (!is_file($absolute)) {
            $row['message'] = 'Missing test file: ' . $path;
            return $row;
        }

        if (!$this->requirementsMet($test['requires'] ?? [])) {
            $row['ok'] = true;
            $row['skipped'] = true;
            $row['message'] = 'Skipped: requirements not met (' . implode(', ', $test['requires'] ?? []) . ')';
            return $row;
        }

        $started = microtime(true);
        $command = escapeshellarg($this->options['php_binary']) . ' ' . escapeshellarg($absolute);
        if (!empty($test['args']) && is_array($test['args'])) {
            foreach ($test['args'] as $arg) {
                $command .= ' ' . escapeshellarg((string) $arg);
            }
        }

        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);
        $row['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
        $row['exit_code'] = $exitCode;
        $row['output_tail'] = $this->tailOutput($output);
        $row['ok'] = $exitCode === 0;
        if (!$row['ok']) {
            $row['message'] = 'Non-GUI test failed with exit code ' . $exitCode;
        }

        if (!$row['ok'] && !empty($test['optional'])) {
            $row['ok'] = true;
            $row['skipped'] = true;
            $row['message'] = 'Optional test failed; treated as skip. ' . ($row['message'] ?? '');
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $tool
     * @return array<string,mixed>
     */
    private function runToolTest(string $personaId, array $tool): array
    {
        $id = (string) ($tool['id'] ?? 'tool');
        $path = (string) ($tool['path'] ?? '');
        $absolute = $this->root . '/' . ltrim($path, '/');

        $row = [
            'persona' => $personaId,
            'id' => $id,
            'path' => $path,
            'ok' => false,
            'exit_code' => null,
            'duration_ms' => 0,
            'output_tail' => '',
            'tool' => true,
        ];

        if (!is_file($absolute)) {
            $row['message'] = 'Missing tool: ' . $path;
            return $row;
        }

        if (!empty($tool['optional']) && getenv('POSMAIN_QA_CAMPAIGN') === '1') {
            $row['ok'] = true;
            $row['skipped'] = true;
            $row['message'] = 'Skipped optional tool during QA campaign orchestrator';
            return $row;
        }

        if (!$this->requirementsMet($tool['requires'] ?? [])) {
            $row['ok'] = true;
            $row['skipped'] = true;
            $row['message'] = 'Skipped: requirements not met';
            return $row;
        }

        $started = microtime(true);
        $command = escapeshellarg($this->options['php_binary']) . ' ' . escapeshellarg($absolute);
        foreach ($tool['args'] ?? [] as $arg) {
            $command .= ' ' . escapeshellarg((string) $arg);
        }

        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);
        $row['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
        $row['exit_code'] = $exitCode;
        $row['output_tail'] = $this->tailOutput($output);
        $row['ok'] = $exitCode === 0;
        if (!$row['ok']) {
            $row['message'] = 'Tool failed with exit code ' . $exitCode;
        }

        if (!$row['ok'] && !empty($tool['optional'])) {
            $row['ok'] = true;
            $row['skipped'] = true;
            $row['message'] = 'Optional tool failed; treated as skip. ' . ($row['message'] ?? '');
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $persona
     * @return array<string,mixed>
     */
    private function runGuiSuite(string $personaId, array $persona): array
    {
        $specs = [];
        foreach ($persona['gui'] ?? [] as $test) {
            $spec = (string) ($test['spec'] ?? '');
            if ($spec !== '') {
                $specs[] = $this->root . '/' . ltrim($spec, '/');
            }
        }

        if ($specs === []) {
            return [
                'ok' => true,
                'skipped' => true,
                'message' => 'No GUI specs registered for persona',
            ];
        }

        if (!$this->requirementsMet(['http', 'playwright'])) {
            return [
                'ok' => true,
                'skipped' => true,
                'message' => 'Skipped GUI: HTTP stack or Playwright not available',
            ];
        }

        $npmCommand = $this->detectNpmCommand();
        if ($npmCommand === null) {
            return [
                'ok' => true,
                'skipped' => true,
                'message' => 'Skipped GUI: npm/npx not found',
            ];
        }

        $started = microtime(true);
        $command = 'cd ' . escapeshellarg($this->root)
            . ' && ' . $npmCommand
            . ' playwright test --project=' . escapeshellarg($personaId);

        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);

        return [
            'ok' => $exitCode === 0,
            'exit_code' => $exitCode,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'command' => $command,
            'output_tail' => $this->tailOutput($output),
            'specs' => $specs,
        ];
    }

    /**
     * @param list<string> $requirements
     */
    private function requirementsMet(array $requirements): bool
    {
        foreach ($requirements as $requirement) {
            switch ($requirement) {
                case 'mysql':
                    if (!$this->mysqlAvailable()) {
                        return false;
                    }
                    break;
                case 'http':
                    if (!$this->httpAvailable()) {
                        return false;
                    }
                    break;
                case 'playwright':
                    if (!is_dir($this->root . '/node_modules/@playwright/test')) {
                        return false;
                    }
                    break;
                default:
                    break;
            }
        }

        return true;
    }

    private function mysqlAvailable(): bool
    {
        $host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
        $user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
        $pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';

        try {
            $conn = new mysqli($host, $user, $pass, '', $port);
            if ($conn->connect_errno) {
                return false;
            }
            $conn->close();
        } catch (Throwable $exception) {
            return false;
        }

        return true;
    }

    private function httpAvailable(): bool
    {
        $base = rtrim(getenv('POSMAIN_TEST_HTTP_BASE') ?: getenv('POSMAIN_LOCAL_POS_URL') ?: 'http://127.0.0.1:8010', '/');
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 3,
                    'ignore_errors' => true,
                ],
            ]);
            $body = file_get_contents($base . '/index.php', false, $context);
        } catch (Throwable $exception) {
            return false;
        }

        return is_string($body) && $body !== '';
    }

    private function detectNpmCommand(): ?string
    {
        foreach (['npx', 'npm exec --'] as $candidate) {
            $probe = trim($candidate) === 'npx' ? 'command -v npx' : 'command -v npm';
            $output = [];
            $exitCode = 0;
            exec($probe . ' 2>/dev/null', $output, $exitCode);
            if ($exitCode === 0) {
                return $candidate === 'npx' ? 'npx' : 'npm exec --';
            }
        }

        return null;
    }

    /**
     * @param list<string> $lines
     */
    private function tailOutput(array $lines): string
    {
        return implode("\n", array_slice($lines, -40));
    }

    /**
     * @param array<string,mixed> $results
     * @return array<string,mixed>
     */
    private function finalize(array $results): array
    {
        $results['finished_at_utc'] = gmdate('Y-m-d\TH:i:s\Z');
        return $results;
    }
}
