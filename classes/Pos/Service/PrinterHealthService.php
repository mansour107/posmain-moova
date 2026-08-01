<?php

require_once __DIR__ . '/PrintBridgeClient.php';

/** Read-only connectivity checks; enabled state and live connectivity are separate. */
class PrinterHealthService
{
    private PrintBridgeClient $bridge;
    private $networkConnector;

    public function __construct(?PrintBridgeClient $bridge = null, ?callable $networkConnector = null)
    {
        $this->bridge = $bridge ?: new PrintBridgeClient();
        $this->networkConnector = $networkConnector;
    }

    public function check(array $printer): array
    {
        if (empty($printer['is_active'])) {
            return $this->result(false, 'disabled', 'متوقفة', 'فعّل الطابعة أولاً حتى تستقبل مهام الطباعة.');
        }
        $type = strtolower(trim((string) ($printer['connection_type'] ?? '')));
        $config = is_array($printer['config'] ?? null) ? $printer['config'] : [];
        if ($type === 'network') {
            return $this->network($config);
        }
        if ($type === 'usb') {
            return $this->cable($config);
        }
        return $this->result(false, 'legacy', 'إعداد قديم', 'عدّل طريقة الاتصال إلى شبكة أو كابل قبل استخدام الطباعة الصامتة.');
    }

    private function network(array $config): array
    {
        $host = trim((string) ($config['host'] ?? ''));
        $port = (int) ($config['port'] ?? 9100);
        if ($host === '' || $port < 1 || $port > 65535) {
            return $this->result(false, 'invalid', 'تحتاج إعداداً', 'أدخل عنوان الطابعة والمنفذ الصحيحين ثم احفظ.');
        }
        if (is_callable($this->networkConnector)) {
            $connected = (bool) call_user_func($this->networkConnector, $host, $port, 1.5);
        } else {
            try {
                $connected = !empty($this->bridge->checkNetwork($host, $port)['connected']);
            } catch (Throwable $exception) {
                error_log('POSMAIN_PRINT_HEALTH ' . $exception->getMessage());
                return $this->result(false, 'bridge_unavailable', 'خدمة الطباعة متوقفة', 'شغّل خدمة الطباعة المحلية، ثم أعد المحاولة.');
            }
        }
        return $connected
            ? $this->result(true, 'connected', 'متصلة', 'الطابعة متاحة على الشبكة.')
            : $this->result(false, 'offline', 'غير متصلة', 'تأكد أن الطابعة تعمل وأنها على نفس الشبكة، ثم أعد الاختبار.');
    }

    private function cable(array $config): array
    {
        $queue = trim((string) ($config['queue_name'] ?? ''));
        if ($queue === '') {
            return $this->result(false, 'invalid', 'تحتاج إعداداً', 'اختر الطابعة المتصلة بالجهاز ثم احفظ.');
        }
        try {
            $printer = $this->bridge->printer($queue);
        } catch (Throwable $exception) {
            error_log('POSMAIN_PRINT_HEALTH ' . $exception->getMessage());
            return $this->result(false, 'bridge_unavailable', 'خدمة الطباعة متوقفة', 'شغّل خدمة الطباعة المحلية على هذا الجهاز، ثم أعد المحاولة.');
        }
        if ($printer === null) {
            return $this->result(false, 'missing', 'غير موجودة', 'أعد توصيل الكابل أو ثبّت الطابعة في إعدادات الجهاز، ثم حدّث الصفحة.');
        }
        if (empty($printer['connected'])) {
            return $this->result(false, 'offline', 'غير متصلة', 'تأكد من الكابل والطاقة وحالة الطابعة في إعدادات الجهاز.');
        }
        return $this->result(true, 'connected', 'متصلة', 'الطابعة متاحة من خلال خدمة الطباعة المحلية.');
    }

    private function result(bool $connected, string $state, string $label, string $guidance): array
    {
        return ['connected' => $connected, 'state' => $state, 'label' => $label, 'guidance' => $guidance, 'checked_at' => gmdate('c')];
    }
}
