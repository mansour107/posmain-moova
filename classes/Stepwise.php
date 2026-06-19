<?php

/**
 * Applies ordered migration files one step at a time and records progress in a ledger table.
 */
class Stepwise
{
    private mysqli $conn;
    private string $stepsPath;
    private string $ledgerTable;
    private string $pattern;
    private bool $recursive;

    public function __construct(mysqli $conn, string $stepsPath, array $options = [])
    {
        $this->conn = $conn;
        $this->stepsPath = rtrim($stepsPath, '/\\');
        $this->ledgerTable = (string) ($options['ledger_table'] ?? 'stepwise_ledger');
        $this->pattern = (string) ($options['pattern'] ?? '*.sql');
        $this->recursive = (bool) ($options['recursive'] ?? false);
    }

    public function ledgerTable(): string
    {
        return $this->ledgerTable;
    }

    public function stepsPath(): string
    {
        return $this->stepsPath;
    }

    /**
     * @return array<int, array{step_key:string,source_file:string,checksum:string,absolute_path:string}>
     */
    public function discover(): array
    {
        if (!is_dir($this->stepsPath)) {
            throw new RuntimeException('Steps path does not exist: ' . $this->stepsPath);
        }

        $files = $this->recursive
            ? $this->discoverRecursive($this->stepsPath)
            : (glob($this->stepsPath . DIRECTORY_SEPARATOR . $this->pattern) ?: []);

        $steps = [];
        foreach ($files as $absolutePath) {
            if (!is_file($absolutePath) || !is_readable($absolutePath)) {
                continue;
            }

            $relative = $this->relativeStepPath($absolutePath);
            $contents = file_get_contents($absolutePath);
            if ($contents === false) {
                throw new RuntimeException('Unable to read step file: ' . $absolutePath);
            }

            $steps[] = [
                'step_key' => $relative,
                'source_file' => $relative,
                'checksum' => hash('sha256', $contents),
                'absolute_path' => $absolutePath,
            ];
        }

        usort($steps, static function (array $left, array $right): int {
            return strnatcasecmp($left['step_key'], $right['step_key']);
        });

        return $steps;
    }

    /**
     * @return array<string, array{checksum:string,applied_at:string,applied_by:?string}>
     */
    public function applied(): array
    {
        $this->ensureLedger();

        $table = $this->quotedIdentifier($this->ledgerTable);
        $result = $this->conn->query("SELECT step_key, checksum, applied_at, applied_by FROM {$table}");
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[(string) $row['step_key']] = [
                'checksum' => (string) $row['checksum'],
                'applied_at' => (string) $row['applied_at'],
                'applied_by' => $row['applied_by'] !== null ? (string) $row['applied_by'] : null,
            ];
        }

        return $rows;
    }

    /**
     * @return array{
     *   ledger_ready:bool,
     *   discovered:int,
     *   pending:array<int, array{step_key:string,source_file:string,checksum:string,absolute_path:string,changed:bool}>,
     *   drift:array<int, array{step_key:string,source_file:string,checksum:string,recorded_checksum:string}>
     * }
     */
    public function plan(): array
    {
        $applied = $this->applied();
        $pending = [];
        $drift = [];

        foreach ($this->discover() as $step) {
            $key = $step['step_key'];
            if (!isset($applied[$key])) {
                $step['changed'] = false;
                $pending[] = $step;
                continue;
            }

            if ($applied[$key]['checksum'] !== $step['checksum']) {
                $drift[] = [
                    'step_key' => $key,
                    'source_file' => $step['source_file'],
                    'checksum' => $step['checksum'],
                    'recorded_checksum' => $applied[$key]['checksum'],
                ];
            }
        }

        return [
            'ledger_ready' => true,
            'discovered' => count($this->discover()),
            'pending' => $pending,
            'drift' => $drift,
        ];
    }

    /**
     * @return array{
     *   applied:array<int, string>,
     *   skipped:array<int, string>,
     *   drift:array<int, array{step_key:string,source_file:string,checksum:string,recorded_checksum:string}>
     * }
     */
    public function apply(?string $appliedBy = null, bool $allowDestructive = false): array
    {
        $plan = $this->plan();
        if ($plan['drift'] !== []) {
            throw new RuntimeException('One or more applied steps changed on disk. Resolve drift before applying new steps.');
        }

        $appliedKeys = [];
        $skippedKeys = [];

        foreach ($plan['pending'] as $step) {
            $sql = file_get_contents($step['absolute_path']);
            if ($sql === false) {
                throw new RuntimeException('Unable to read step file: ' . $step['absolute_path']);
            }

            $statements = $this->splitStatements($sql);
            if ($statements === []) {
                $skippedKeys[] = $step['step_key'];
                $this->recordStep($step, $appliedBy, ['statement_count' => 0, 'note' => 'empty file']);
                continue;
            }

            if (!$allowDestructive && $this->containsDestructiveStatement($statements)) {
                throw new RuntimeException(
                    'Step contains destructive SQL and was not applied: ' . $step['step_key']
                );
            }

            $this->conn->begin_transaction();
            try {
                foreach ($statements as $statement) {
                    $this->conn->query($statement);
                }
                $this->recordStep($step, $appliedBy, ['statement_count' => count($statements)]);
                $this->conn->commit();
                $appliedKeys[] = $step['step_key'];
            } catch (Throwable $exception) {
                $this->conn->rollback();
                throw new RuntimeException(
                    'Failed while applying step ' . $step['step_key'] . ': ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }
        }

        return [
            'applied' => $appliedKeys,
            'skipped' => $skippedKeys,
            'drift' => $plan['drift'],
        ];
    }

    public function ensureLedger(): void
    {
        $table = $this->quotedIdentifier($this->ledgerTable);
        $this->conn->query("
            CREATE TABLE IF NOT EXISTS {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                step_key VARCHAR(191) NOT NULL,
                source_file VARCHAR(255) NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                applied_by VARCHAR(100) NULL,
                notes JSON NULL,
                UNIQUE KEY uq_stepwise_step_key (step_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * @return array<int, string>
     */
    public function splitStatements(string $sql): array
    {
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        $statements = [];
        $length = strlen($sql);
        $buffer = '';
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($index = 0; $index < $length; $index++) {
            $char = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';

            if ($inLineComment) {
                $buffer .= $char;
                if ($char === "\n") {
                    $inLineComment = false;
                }
                continue;
            }

            if ($inBlockComment) {
                $buffer .= $char;
                if ($char === '*' && $next === '/') {
                    $buffer .= $next;
                    $index++;
                    $inBlockComment = false;
                }
                continue;
            }

            if (!$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
                if ($char === '-' && $next === '-') {
                    $inLineComment = true;
                    $buffer .= $char;
                    continue;
                }
                if ($char === '#') {
                    $inLineComment = true;
                    $buffer .= $char;
                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $buffer .= $char;
                    continue;
                }
            }

            if ($char === "'" && !$inDoubleQuote && !$inBacktick) {
                $escaped = $index > 0 && $sql[$index - 1] === '\\';
                if (!$escaped) {
                    $inSingleQuote = !$inSingleQuote;
                }
            } elseif ($char === '"' && !$inSingleQuote && !$inBacktick) {
                $escaped = $index > 0 && $sql[$index - 1] === '\\';
                if (!$escaped) {
                    $inDoubleQuote = !$inDoubleQuote;
                }
            } elseif ($char === '`' && !$inSingleQuote && !$inDoubleQuote) {
                $inBacktick = !$inBacktick;
            }

            if ($char === ';' && !$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }

    /**
     * @param array<int, string> $statements
     */
    public function containsDestructiveStatement(array $statements): bool
    {
        foreach ($statements as $statement) {
            if (preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM|UPDATE\s+\w+\s+SET)\b/i', $statement) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{step_key:string,source_file:string,checksum:string,absolute_path:string} $step
     * @param array<string, mixed> $notes
     */
    private function recordStep(array $step, ?string $appliedBy, array $notes): void
    {
        $this->ensureLedger();
        $table = $this->quotedIdentifier($this->ledgerTable);
        $appliedBy = $appliedBy ?: (getenv('USER') ?: getenv('USERNAME') ?: 'cli');
        $notesJson = json_encode($notes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt = $this->conn->prepare("
            INSERT INTO {$table} (step_key, source_file, checksum, applied_by, notes)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                checksum = VALUES(checksum),
                applied_at = CURRENT_TIMESTAMP,
                applied_by = VALUES(applied_by),
                notes = VALUES(notes)
        ");
        $stmt->bind_param(
            'sssss',
            $step['step_key'],
            $step['source_file'],
            $step['checksum'],
            $appliedBy,
            $notesJson
        );
        $stmt->execute();
        $stmt->close();
    }

    /**
     * @return array<int, string>
     */
    private function discoverRecursive(string $directory): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        $matches = [];
        $pattern = $this->patternToRegex($this->pattern);

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $basename = $fileInfo->getFilename();
            if (preg_match($pattern, $basename) !== 1) {
                continue;
            }
            $matches[] = $fileInfo->getPathname();
        }

        return $matches;
    }

    private function relativeStepPath(string $absolutePath): string
    {
        $base = rtrim($this->stepsPath, '/\\') . DIRECTORY_SEPARATOR;
        if (strpos($absolutePath, $base) === 0) {
            return str_replace('\\', '/', substr($absolutePath, strlen($base)));
        }

        return str_replace('\\', '/', basename($absolutePath));
    }

    private function patternToRegex(string $pattern): string
    {
        $escaped = preg_quote($pattern, '/');
        $regex = str_replace('\*', '.*', $escaped);

        return '/^' . $regex . '$/i';
    }

    private function quotedIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Invalid ledger table name.');
        }

        return '`' . $identifier . '`';
    }
}
