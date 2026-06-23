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
            $legacyStatus = $this->reconcileKnownLegacyStep($step, $appliedBy);
            if ($legacyStatus === 'baseline') {
                $skippedKeys[] = $step['step_key'];
                continue;
            }
            if ($legacyStatus === 'repaired') {
                $appliedKeys[] = $step['step_key'];
                continue;
            }

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
     * Older installs often received these schema changes through db.sql or
     * manual server work before stepwise_ledger existed. Reconcile only these
     * known legacy files so future migrations can stay strict and file-driven.
     *
     * @param array{step_key:string,source_file:string,checksum:string,absolute_path:string} $step
     */
    private function reconcileKnownLegacyStep(array $step, ?string $appliedBy): ?string
    {
        switch ($step['step_key']) {
            case '006_add_jal_columns.sql':
                return $this->reconcileColumns($step, $appliedBy, 'ot_head', [
                    'jal_name' => 'VARCHAR(255) DEFAULT NULL',
                    'jal_notes' => 'TEXT DEFAULT NULL',
                ]);

            case '007_add_jal_amount.sql':
                return $this->reconcileColumns($step, $appliedBy, 'ot_head', [
                    'jal_amount' => 'DECIMAL(10, 2) DEFAULT 0.00',
                ]);

            case '008_comprehensive_update.sql':
                return $this->reconcileColumns($step, $appliedBy, 'ot_head', [
                    'jal_name' => 'VARCHAR(255) DEFAULT NULL',
                    'jal_notes' => 'TEXT DEFAULT NULL',
                    'jal_amount' => 'DECIMAL(10, 2) DEFAULT 0.00',
                ]);

            case '009_table_order_identity.sql':
                if ($this->tableOrderIdentitySatisfied()) {
                    $this->recordStep($step, $appliedBy, [
                        'baseline_reconciled' => true,
                        'note' => 'Legacy table/order identity schema already exists.',
                    ]);

                    return 'baseline';
                }

                return null;

            case '010_pulse_setup.sql':
                return $this->reconcilePulseSetup($step, $appliedBy);

            case '011_customer_visits_setup.sql':
                return $this->reconcileCustomerVisitsSetup($step, $appliedBy);

            case '012_sync_money_decimal_types.sql':
                return $this->reconcileSyncMoneyDecimalTypes($step, $appliedBy);
        }

        return null;
    }

    private function reconcileSyncMoneyDecimalTypes(array $step, ?string $appliedBy): ?string
    {
        if (!$this->tableExists('ot_head') || !$this->columnExists('ot_head', 'pro_value')) {
            return null;
        }

        $type = strtolower($this->columnType('ot_head', 'pro_value'));
        if (strpos($type, 'decimal(15,4)') === false) {
            return null;
        }

        if ($this->tableExists('order_payments') && $this->columnExists('order_payments', 'amount')) {
            $paymentType = strtolower($this->columnType('order_payments', 'amount'));
            if (strpos($paymentType, 'decimal(15,4)') === false) {
                return null;
            }
        }

        $this->recordStep($step, $appliedBy, [
            'baseline_reconciled' => true,
            'note' => 'Sync money decimal columns already aligned.',
        ]);

        return 'baseline';
    }

    /**
     * @param array{step_key:string,source_file:string,checksum:string,absolute_path:string} $step
     * @param array<string, string> $columns
     */
    private function reconcileColumns(array $step, ?string $appliedBy, string $table, array $columns): string
    {
        $missing = [];
        foreach ($columns as $column => $definition) {
            if (!$this->columnExists($table, $column)) {
                $missing[$column] = $definition;
            }
        }

        if ($missing === []) {
            $this->recordStep($step, $appliedBy, [
                'baseline_reconciled' => true,
                'note' => 'Legacy columns already exist.',
            ]);

            return 'baseline';
        }

        $this->conn->begin_transaction();
        try {
            foreach ($missing as $column => $definition) {
                $this->conn->query(
                    'ALTER TABLE ' . $this->quoteKnownIdentifier($table)
                    . ' ADD COLUMN ' . $this->quoteKnownIdentifier($column)
                    . ' ' . $definition
                );
            }
            $this->recordStep($step, $appliedBy, [
                'baseline_repaired' => true,
                'added_columns' => array_keys($missing),
            ]);
            $this->conn->commit();
        } catch (Throwable $exception) {
            $this->conn->rollback();
            throw $exception;
        }

        return 'repaired';
    }

    private function reconcilePulseSetup(array $step, ?string $appliedBy): string
    {
        $changed = false;
        $this->conn->begin_transaction();
        try {
            if (!$this->columnExists('settings', 'showpulse')) {
                $this->conn->query('ALTER TABLE settings ADD COLUMN showpulse INT DEFAULT 1');
                $changed = true;
            }
            if (!$this->columnExists('usr_pwrs', 'sid_pulse')) {
                $this->conn->query('ALTER TABLE usr_pwrs ADD COLUMN sid_pulse INT DEFAULT 1');
                $changed = true;
            }
            if (!$this->tableExists('pulse_types')) {
                $this->conn->query("
                    CREATE TABLE pulse_types (
                        id INT(11) NOT NULL AUTO_INCREMENT,
                        name VARCHAR(100) NOT NULL,
                        category ENUM('positive','negative') NOT NULL DEFAULT 'positive',
                        icon VARCHAR(50) DEFAULT 'fas fa-star',
                        points INT DEFAULT 1,
                        isdeleted TINYINT(1) DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                $changed = true;
            }
            if (!$this->tableExists('pulse_logs')) {
                $this->conn->query("
                    CREATE TABLE pulse_logs (
                        id INT(11) NOT NULL AUTO_INCREMENT,
                        employee_id INT(11) NOT NULL,
                        type_id INT(11) NOT NULL,
                        category ENUM('positive','negative') NOT NULL,
                        rating INT DEFAULT 5,
                        notes TEXT,
                        recorded_by INT(11) NOT NULL,
                        recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        KEY idx_employee (employee_id),
                        KEY idx_type (type_id),
                        KEY idx_recorded_at (recorded_at),
                        KEY idx_category (category)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                $changed = true;
            }
            $this->seedPulseTypes();
            $this->recordStep($step, $appliedBy, [
                $changed ? 'baseline_repaired' : 'baseline_reconciled' => true,
                'note' => $changed ? 'Pulse legacy schema repaired.' : 'Pulse legacy schema already exists.',
            ]);
            $this->conn->commit();
        } catch (Throwable $exception) {
            $this->conn->rollback();
            throw $exception;
        }

        return $changed ? 'repaired' : 'baseline';
    }

    private function reconcileCustomerVisitsSetup(array $step, ?string $appliedBy): string
    {
        $changed = false;
        $this->conn->begin_transaction();
        try {
            if (!$this->tableExists('customer_visits')) {
                $this->conn->query("
                    CREATE TABLE customer_visits (
                        id INT(11) NOT NULL AUTO_INCREMENT,
                        gender ENUM('male','female') NOT NULL,
                        age_group ENUM('under18','18_25','25_40','over40') NOT NULL,
                        mode ENUM('solo','group') NOT NULL,
                        start_time TIME NOT NULL,
                        end_time TIME NULL DEFAULT NULL,
                        order_value ENUM('under60','over60') NOT NULL,
                        visit_type ENUM('new','returning','regular') NOT NULL,
                        created_by INT UNSIGNED NOT NULL DEFAULT 0,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        isdeleted TINYINT(1) NOT NULL DEFAULT 0,
                        PRIMARY KEY (id),
                        KEY idx_created_at (created_at),
                        KEY idx_isdeleted (isdeleted),
                        KEY idx_visit_type (visit_type)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                $changed = true;
            }
            if (!$this->columnExists('settings', 'show_customer_visits')) {
                $this->conn->query('ALTER TABLE settings ADD COLUMN show_customer_visits INT DEFAULT 1');
                $changed = true;
            }
            $this->recordStep($step, $appliedBy, [
                $changed ? 'baseline_repaired' : 'baseline_reconciled' => true,
                'note' => $changed ? 'Customer visits legacy schema repaired.' : 'Customer visits legacy schema already exists.',
            ]);
            $this->conn->commit();
        } catch (Throwable $exception) {
            $this->conn->rollback();
            throw $exception;
        }

        return $changed ? 'repaired' : 'baseline';
    }

    private function tableOrderIdentitySatisfied(): bool
    {
        foreach ([
            'cancelled_at',
            'cancelled_by',
            'cancellation_reason',
            'completed_at',
            'created_by',
            'updated_by',
            'parent_order_id',
            'split_group_id',
        ] as $column) {
            if (!$this->columnExists('ot_head', $column)) {
                return false;
            }
        }

        return $this->tableExists('order_payments')
            && $this->tableExists('table_order_migration_review')
            && $this->indexExists('ot_head', 'idx_ot_head_active_table_order')
            && $this->indexExists('ot_head', 'idx_ot_head_order_type')
            && $this->indexExists('fat_details', 'idx_fat_details_fatid');
    }

    private function seedPulseTypes(): void
    {
        $types = [
            ['الالتزام بالمواعيد', 'positive', 'fas fa-clock', 3],
            ['جودة العمل', 'positive', 'fas fa-award', 5],
            ['روح الفريق', 'positive', 'fas fa-users', 4],
            ['المبادرة', 'positive', 'fas fa-lightbulb', 5],
            ['خدمة العملاء', 'positive', 'fas fa-handshake', 4],
            ['النظافة والترتيب', 'positive', 'fas fa-broom', 2],
            ['التأخر', 'negative', 'fas fa-clock', -3],
            ['الإهمال', 'negative', 'fas fa-exclamation-triangle', -5],
            ['عدم التعاون', 'negative', 'fas fa-user-slash', -4],
            ['سوء التعامل', 'negative', 'fas fa-frown', -5],
        ];

        $result = $this->conn->query('SELECT COUNT(*) AS total FROM pulse_types');
        $count = (int) (($result->fetch_assoc()['total'] ?? 0));
        $stmt = $this->conn->prepare("
            INSERT INTO pulse_types (name, category, icon, points)
            SELECT ?, ?, ?, ? FROM DUAL
            WHERE (SELECT COUNT(*) FROM pulse_types) < ?
        ");
        foreach ($types as $index => $type) {
            $threshold = $index + 1;
            if ($count >= $threshold) {
                continue;
            }
            $stmt->bind_param('sssii', $type[0], $type[1], $type[2], $type[3], $threshold);
            $stmt->execute();
        }
        $stmt->close();
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) AS total
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['total'] ?? 0) > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) AS total
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['total'] ?? 0) > 0;
    }

    private function columnType(string $table, string $column): string
    {
        $stmt = $this->conn->prepare("
            SELECT COLUMN_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (string) ($row['COLUMN_TYPE'] ?? '');
    }

    private function indexExists(string $table, string $index): bool
    {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) AS total
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
        ");
        $stmt->bind_param('ss', $table, $index);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['total'] ?? 0) > 0;
    }

    private function quoteKnownIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Invalid SQL identifier.');
        }

        return '`' . $identifier . '`';
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
