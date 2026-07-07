<?php

require_once __DIR__ . '/PrintJobService.php';

class CashDrawerHardwareService
{
    private const DRAWER_STATUS_QUERY = "\x10\x04\x01";

    public function resolveDriverConfig($conn, array $scope = []): array
    {
        $mode = strtolower(trim((string) (getenv('POSMAIN_CASH_DRAWER_MODE') ?: '')));
        if (in_array($mode, ['disabled', 'off', 'none'], true)) {
            return ['driver' => 'disabled'];
        }

        $host = trim((string) (getenv('POSMAIN_CASH_DRAWER_HOST') ?: ''));
        if ($host !== '') {
            return $this->networkConfig($host, $scope);
        }

        $printerConfig = ($conn instanceof mysqli)
            ? $this->resolveFromReceiptPrinter($conn, $scope)
            : null;
        if ($printerConfig !== null) {
            return $printerConfig;
        }

        if (in_array($mode, ['network', 'escpos'], true)) {
            throw new RuntimeException('CASH_DRAWER_NOT_CONFIGURED');
        }

        return ['driver' => 'disabled'];
    }

    public function open(array $config): array
    {
        $driver = strtolower(trim((string) ($config['driver'] ?? 'disabled')));
        if ($driver === 'disabled') {
            throw new RuntimeException('CASH_DRAWER_NOT_CONFIGURED');
        }

        if ($driver === 'network') {
            return $this->openNetwork($config);
        }

        throw new RuntimeException('CASH_DRAWER_DRIVER_INVALID');
    }

    public function readStatus(array $config): array
    {
        $driver = strtolower(trim((string) ($config['driver'] ?? 'disabled')));
        if ($driver === 'disabled') {
            return [
                'driver' => 'disabled',
                'status' => 'not_configured',
                'sensor_supported' => false,
            ];
        }

        if ($driver === 'network') {
            return $this->readNetworkStatus($config);
        }

        throw new RuntimeException('CASH_DRAWER_DRIVER_INVALID');
    }

    public static function openPulseCommand(int $pin = 0, int $onTimeMs = 50, int $offTimeMs = 500): string
    {
        $pin = $pin === 1 ? 1 : 0;
        $onUnits = max(1, min(255, (int) round($onTimeMs / 2)));
        $offUnits = max(1, min(255, (int) round($offTimeMs / 2)));

        return "\x1B\x70" . chr($pin) . chr($onUnits) . chr($offUnits);
    }

    public static function parseDrawerStatusByte(?string $byte): string
    {
        if ($byte === null || $byte === '') {
            return 'unknown';
        }

        $value = ord($byte[0]);
        if (($value & 0x08) === 0x08) {
            return 'open';
        }

        return 'closed';
    }

    public static function userMessageForCode(string $code): string
    {
        $map = [
            'DRAWER_SESSION_REQUIRED' => 'لا توجد وردية مفتوحة. افتح شيفت الكاشير أولاً ثم أعد المحاولة.',
            'CASH_DRAWER_NOT_CONFIGURED' => 'لم يتم إعداد درج النقدية. اربط طابعة الإيصالات عبر الشبكة أولاً.',
            'CASH_DRAWER_OFFLINE' => 'تعذر الاتصال بالطابعة أو درج النقدية. تحقق من التوصيل والشبكة.',
            'CASH_DRAWER_OPEN_FAILED' => 'أُرسل أمر فتح الدرج لكن الجهاز لم يستجب.',
            'CASH_DRAWER_STATUS_UNAVAILABLE' => 'تعذر قراءة حالة الدرج من الجهاز.',
            'CASH_DRAWER_DRIVER_INVALID' => 'إعداد درج النقدية غير صالح.',
            'MANAGER_APPROVAL_REQUIRED' => 'هذا الإجراء يتطلب اعتماد مدير.',
        ];

        return $map[$code] ?? 'تعذر فتح الدرج. حاول مرة أخرى.';
    }

    private function networkConfig(string $host, array $scope): array
    {
        $port = (int) (getenv('POSMAIN_CASH_DRAWER_PORT') ?: 9100);
        if ($port < 1) {
            $port = 9100;
        }

        return [
            'driver' => 'network',
            'host' => $host,
            'port' => $port,
            'pin' => (int) (getenv('POSMAIN_CASH_DRAWER_PIN') ?: 0) === 1 ? 1 : 0,
            'timeout_ms' => max(500, (int) (getenv('POSMAIN_CASH_DRAWER_TIMEOUT_MS') ?: 3000)),
            'state_key' => $this->stateKey($scope),
            'sensor_supported' => true,
        ];
    }

    private function resolveFromReceiptPrinter(mysqli $conn, array $scope): ?array
    {
        if (!$this->tableExists($conn, 'printers')) {
            return null;
        }

        $tenant = (int) ($scope['tenant'] ?? 0);
        $branch = (int) ($scope['branch'] ?? 0);
        $printers = (new PrintJobService())->listActivePrinters($conn, [
            'tenant' => $tenant,
            'branch' => $branch,
            'printer_type' => 'receipt',
        ]);

        foreach ($printers as $printer) {
            if (($printer['connection_type'] ?? '') !== 'network') {
                continue;
            }

            $config = is_array($printer['config'] ?? null) ? $printer['config'] : [];
            $drawer = is_array($config['cash_drawer'] ?? null) ? $config['cash_drawer'] : [];
            $host = trim((string) ($drawer['host'] ?? $config['host'] ?? ''));
            if ($host === '') {
                continue;
            }

            return [
                'driver' => 'network',
                'host' => $host,
                'port' => (int) ($drawer['port'] ?? $config['port'] ?? 9100),
                'pin' => (int) ($drawer['pin'] ?? 0) === 1 ? 1 : 0,
                'timeout_ms' => max(500, (int) ($drawer['timeout_ms'] ?? 3000)),
                'printer_id' => (int) ($printer['id'] ?? 0),
                'state_key' => $this->stateKey($scope),
                'sensor_supported' => true,
            ];
        }

        return null;
    }

    private function openNetwork(array $config): array
    {
        $host = trim((string) ($config['host'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('CASH_DRAWER_NOT_CONFIGURED');
        }

        $pulse = self::openPulseCommand((int) ($config['pin'] ?? 0));
        $this->sendNetworkPayload($config, $pulse);

        $status = $this->readNetworkStatus($config);
        $this->rememberNetworkOpen((string) ($config['state_key'] ?? 'default'));

        return [
            'driver' => 'network',
            'opened' => true,
            'status' => $status['status'] ?? 'unknown',
            'sensor_supported' => true,
            'host' => $host,
            'message' => 'تم إرسال أمر فتح الدرج إلى الطابعة.',
        ];
    }

    private function readNetworkStatus(array $config): array
    {
        $host = trim((string) ($config['host'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('CASH_DRAWER_NOT_CONFIGURED');
        }

        try {
            $response = $this->sendNetworkPayload($config, self::DRAWER_STATUS_QUERY, true);
            $status = self::parseDrawerStatusByte($response);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'CASH_DRAWER_OFFLINE') {
                throw $exception;
            }
            $status = 'unknown';
        }

        return [
            'driver' => 'network',
            'status' => $status,
            'sensor_supported' => $status !== 'unknown',
            'host' => $host,
        ];
    }

    private function sendNetworkPayload(array $config, string $payload, bool $expectResponse = false): ?string
    {
        $host = trim((string) ($config['host'] ?? ''));
        $port = (int) ($config['port'] ?? 9100);
        $timeoutMs = max(500, (int) ($config['timeout_ms'] ?? 3000));
        $timeoutSec = $timeoutMs / 1000;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errno,
            $errstr,
            $timeoutSec
        );

        if (!is_resource($socket)) {
            throw new RuntimeException('CASH_DRAWER_OFFLINE');
        }

        stream_set_timeout($socket, (int) ceil($timeoutSec));
        $written = fwrite($socket, $payload);
        if ($written === false || $written < strlen($payload)) {
            fclose($socket);
            throw new RuntimeException('CASH_DRAWER_OPEN_FAILED');
        }

        if (!$expectResponse) {
            fclose($socket);
            return null;
        }

        $response = fread($socket, 1);
        fclose($socket);

        if ($response === false || $response === '') {
            throw new RuntimeException('CASH_DRAWER_STATUS_UNAVAILABLE');
        }

        return $response;
    }

    private function rememberNetworkOpen(string $stateKey): void
    {
        $this->writeState($stateKey, [
            'status' => 'open',
            'opened_at' => time(),
            'driver' => 'network',
        ]);
    }

    private function stateKey(array $scope): string
    {
        $tenant = (int) ($scope['tenant'] ?? 0);
        $branch = (int) ($scope['branch'] ?? 0);
        $terminal = trim((string) (getenv('POSMAIN_CASH_DRAWER_TERMINAL') ?: 'default'));

        return $tenant . ':' . $branch . ':' . $terminal;
    }

    private function statePath(string $stateKey): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'posmain_cash_drawer_'
            . md5($stateKey)
            . '.json';
    }

    private function writeState(string $stateKey, array $state): void
    {
        $path = $this->statePath($stateKey);
        file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE));
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $safe = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $result && $result->num_rows > 0;
    }
}
