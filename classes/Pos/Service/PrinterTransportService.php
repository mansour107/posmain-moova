<?php

class PrintTransportException extends RuntimeException
{
    private bool $retrySafe;
    public function __construct(string $message, bool $retrySafe) { parent::__construct($message); $this->retrySafe = $retrySafe; }
    public function isRetrySafe(): bool { return $this->retrySafe; }
}

require_once __DIR__ . '/PrintBridgeClient.php';

/**
 * Both physical transports go through the local bridge. This keeps the
 * delivery-key receipt and uncertain-output policy identical for network and
 * cable printers, including after a worker or HTTP response is interrupted.
 */
class PrinterTransportService
{
    private PrintBridgeClient $bridge;
    public function __construct(?PrintBridgeClient $bridge = null) { $this->bridge = $bridge ?: new PrintBridgeClient(); }

    public function send(array $job, array $printer, array $rendered): array
    {
        $connectionType = strtolower(trim((string) ($printer['connection_type'] ?? '')));
        if (in_array($connectionType, ['network', 'usb'], true)) {
            return $this->bridge->print($job, $printer, $rendered);
        }
        if ($connectionType === 'browser') {
            throw new PrintTransportException('SILENT_PRINT_BROWSER_PRINTER_UNSUPPORTED', false);
        }
        throw new PrintTransportException('SILENT_PRINT_TRANSPORT_UNSUPPORTED', false);
    }
}
