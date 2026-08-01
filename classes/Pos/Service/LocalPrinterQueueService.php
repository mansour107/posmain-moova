<?php

class LocalPrinterQueueException extends RuntimeException
{
    private bool $retrySafe;

    public function __construct(string $code, bool $retrySafe)
    {
        parent::__construct($code);
        $this->retrySafe = $retrySafe;
    }

    public function isRetrySafe(): bool
    {
        return $this->retrySafe;
    }
}

/**
 * Talks to the operating-system print spooler. The web application never
 * receives a command or device path; it may select only a queue returned here.
 */
class LocalPrinterQueueService
{
    private string $platform;
    private $runner;
    private string $windowsScript;

    public function __construct(?string $platform = null, ?callable $runner = null, ?string $windowsScript = null)
    {
        $this->platform = strtolower($platform ?: PHP_OS_FAMILY);
        $this->runner = $runner;
        $this->windowsScript = $windowsScript
            ?: dirname(__DIR__, 3) . '/deploy/printing/windows/raw_print.ps1';
    }

    /** @return array<int,array{queue:string,name:string,connected:bool,state:string,details:string,device:string}> */
    public function printers(): array
    {
        return $this->platform === 'windows'
            ? $this->windowsPrinters()
            : $this->cupsPrinters();
    }

    public function printer(string $queue): ?array
    {
        $queue = $this->queueName($queue);
        foreach ($this->printers() as $printer) {
            if (hash_equals($printer['queue'], $queue)) {
                return $printer;
            }
        }
        return null;
    }

    public function submit(string $queue, string $bytes): array
    {
        $queue = $this->queueName($queue);
        $printer = $this->printer($queue);
        if ($printer === null) {
            throw new LocalPrinterQueueException('PRINT_BRIDGE_QUEUE_NOT_FOUND', false);
        }
        if (!$printer['connected']) {
            throw new LocalPrinterQueueException('PRINT_BRIDGE_QUEUE_OFFLINE', true);
        }
        if ($bytes === '' || strlen($bytes) > 4 * 1024 * 1024) {
            throw new LocalPrinterQueueException('PRINT_BRIDGE_PAYLOAD_INVALID', false);
        }

        if ($this->platform === 'windows') {
            return $this->submitWindows($queue, $bytes);
        }
        $result = $this->run(['lp', '-d', $queue, '-o', 'raw'], $bytes);
        if ($result['exit_code'] !== 0) {
            throw new LocalPrinterQueueException('PRINT_BRIDGE_SUBMIT_FAILED', true);
        }
        $spoolId = '';
        if (preg_match('/request id is\s+([^\s]+)/i', $result['stdout'], $match) === 1) {
            $spoolId = $match[1];
        }
        return [
            'accepted' => true,
            'queue' => $queue,
            'spool_job_id' => $spoolId,
            'spooler' => 'cups',
        ];
    }

    private function cupsPrinters(): array
    {
        $states = $this->run(['lpstat', '-p']);
        if ($states['exit_code'] !== 0 && trim($states['stdout']) === '') {
            return [];
        }
        $devices = [];
        $deviceResult = $this->run(['lpstat', '-v']);
        foreach (preg_split('/\R/', $deviceResult['stdout']) ?: [] as $line) {
            if (preg_match('/^device for\s+([^:]+):\s*(.+)$/i', trim($line), $match) === 1) {
                $devices[trim($match[1])] = trim($match[2]);
            }
        }

        $printers = [];
        foreach (preg_split('/\R/', $states['stdout']) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/^printer\s+([^\s]+)\s+(.+)$/i', $line, $match) !== 1) {
                continue;
            }
            $queue = trim($match[1]);
            $details = trim($match[2]);
            $offline = preg_match('/\b(disabled|offline|not available|unplugged|stopped)\b/i', $details) === 1;
            $ready = !$offline && preg_match('/\b(idle|printing|enabled|ready)\b/i', $details) === 1;
            $printers[] = [
                'queue' => $queue,
                'name' => $queue,
                'connected' => $ready,
                'state' => $offline ? 'offline' : ($ready ? 'ready' : 'unknown'),
                'details' => $details,
                'device' => (string) ($devices[$queue] ?? ''),
            ];
        }
        usort($printers, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $printers;
    }

    private function windowsPrinters(): array
    {
        $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$rows = Get-CimInstance Win32_Printer | Select-Object Name,WorkOffline,PrinterStatus,DetectedErrorState,PortName
$rows | ConvertTo-Json -Compress
POWERSHELL;
        $result = $this->run([
            'powershell.exe',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-Command',
            $script,
        ]);
        if ($result['exit_code'] !== 0) {
            return [];
        }
        $decoded = json_decode(trim($result['stdout']), true);
        if (!is_array($decoded)) {
            return [];
        }
        if (isset($decoded['Name'])) {
            $decoded = [$decoded];
        }
        $printers = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || trim((string) ($row['Name'] ?? '')) === '') {
                continue;
            }
            $offline = filter_var($row['WorkOffline'] ?? false, FILTER_VALIDATE_BOOLEAN)
                || (int) ($row['DetectedErrorState'] ?? 0) > 2;
            $queue = trim((string) $row['Name']);
            $printers[] = [
                'queue' => $queue,
                'name' => $queue,
                'connected' => !$offline,
                'state' => $offline ? 'offline' : 'ready',
                'details' => $offline ? 'offline' : 'ready',
                'device' => trim((string) ($row['PortName'] ?? '')),
            ];
        }
        usort($printers, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $printers;
    }

    private function submitWindows(string $queue, string $bytes): array
    {
        if (!is_file($this->windowsScript) || !is_readable($this->windowsScript)) {
            throw new LocalPrinterQueueException('PRINT_BRIDGE_SUBMIT_FAILED', false);
        }
        $temporary = tempnam(sys_get_temp_dir(), 'posmain-print-');
        if (!is_string($temporary) || file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new LocalPrinterQueueException('PRINT_BRIDGE_SUBMIT_FAILED', true);
        }
        try {
            $result = $this->run([
                'powershell.exe',
                '-NoProfile',
                '-NonInteractive',
                '-ExecutionPolicy',
                'Bypass',
                '-File',
                $this->windowsScript,
                '-PrinterName',
                $queue,
                '-DataFile',
                $temporary,
            ]);
        } finally {
            @unlink($temporary);
        }
        if ($result['exit_code'] !== 0) {
            throw new LocalPrinterQueueException('PRINT_BRIDGE_SUBMIT_FAILED', true);
        }
        return [
            'accepted' => true,
            'queue' => $queue,
            'spool_job_id' => trim($result['stdout']),
            'spooler' => 'windows',
        ];
    }

    private function queueName(string $queue): string
    {
        $queue = trim($queue);
        if ($queue === '' || strlen($queue) > 128 || preg_match('/[\x00-\x1F\x7F]/', $queue) === 1) {
            throw new LocalPrinterQueueException('PRINT_CABLE_QUEUE_INVALID', false);
        }
        return $queue;
    }

    private function run(array $command, ?string $stdin = null): array
    {
        if (is_callable($this->runner)) {
            $result = call_user_func($this->runner, $command, $stdin);
            return [
                'exit_code' => (int) ($result['exit_code'] ?? 1),
                'stdout' => (string) ($result['stdout'] ?? ''),
                'stderr' => (string) ($result['stderr'] ?? ''),
            ];
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $environment = $this->platform === 'windows' ? null : ['LC_ALL' => 'C', 'LANG' => 'C'];
        $process = @proc_open($command, $descriptors, $pipes, null, $environment);
        if (!is_resource($process)) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'process unavailable'];
        }
        fwrite($pipes[0], $stdin ?? '');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [
            'exit_code' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }
}
