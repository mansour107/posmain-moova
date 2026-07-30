<?php

class EscPosPrintRenderer
{
    public function render(array $job, array $printer): array
    {
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $jobType = (string) ($job['job_type'] ?? '');
        if (in_array($jobType, ['receipt', 'kot', 'kitchen'], true)) {
            $text = $this->renderOrder($payload, $jobType === 'receipt' ? 'RECEIPT' : 'KITCHEN');
        } else {
            $text = $this->renderGeneric($payload);
        }

        $text = str_replace(["\r\n", "\r"], "\n", trim($text)) . "\n";
        return [
            'text' => $text,
            'bytes' => "\x1B\x40" . $text . "\n\n\n\x1D\x56\x00",
            'encoding' => 'utf-8',
            'paper_width' => (int) ($printer['config']['paper_width'] ?? 80),
        ];
    }

    private function renderOrder(array $payload, string $title): string
    {
        $order = is_array($payload['order'] ?? null) ? $payload['order'] : [];
        $table = is_array($payload['table'] ?? null) ? $payload['table'] : [];
        $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
        $totals = is_array($payload['totals'] ?? null) ? $payload['totals'] : [];
        $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];

        $out = [$title, str_repeat('=', 32)];
        $out[] = 'Order: ' . (string) ($order['pro_id'] ?? $order['id'] ?? '');
        if (trim((string) ($table['name'] ?? '')) !== '') {
            $out[] = 'Table: ' . (string) $table['name'];
        }
        if (trim((string) ($customer['name'] ?? '')) !== '') {
            $out[] = 'Customer: ' . (string) $customer['name'];
        }
        $out[] = str_repeat('-', 32);

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $out[] = (string) ($line['qty'] ?? '0') . ' x ' . (string) ($line['name'] ?? '');
            if ($title === 'RECEIPT') {
                $out[] = '  ' . (string) ($line['line_total'] ?? $line['price'] ?? '0.00');
            }
            foreach ((array) ($line['modifiers'] ?? []) as $modifier) {
                if (is_array($modifier)) {
                    $label = $modifier['option_name'] ?? $modifier['name'] ?? $modifier['label'] ?? '';
                } else {
                    $label = $modifier;
                }
                if (trim((string) $label) !== '') {
                    $out[] = '  + ' . trim((string) $label);
                }
            }
            foreach ((array) ($line['notes'] ?? []) as $note) {
                $text = is_array($note)
                    ? ($note['note'] ?? $note['text'] ?? '')
                    : $note;
                if (trim((string) $text) !== '') {
                    $out[] = '  * ' . trim((string) $text);
                }
            }
            if (trim((string) ($line['legacy_notes'] ?? '')) !== '') {
                $out[] = '  * ' . trim((string) $line['legacy_notes']);
            }
            foreach ((array) ($line['preparation_values'] ?? []) as $preparation) {
                if (!is_array($preparation)) {
                    continue;
                }
                $label = trim((string) ($preparation['label'] ?? $preparation['field_name'] ?? ''));
                $value = trim((string) ($preparation['value'] ?? $preparation['display_value'] ?? ''));
                if ($label !== '' || $value !== '') {
                    $out[] = '  - ' . trim($label . ': ' . $value, ': ');
                }
            }
        }

        if ($title === 'RECEIPT') {
            $out[] = str_repeat('-', 32);
            $out[] = 'Total: ' . (string) ($totals['total'] ?? '0.00');
            $out[] = 'Discount: ' . (string) ($totals['discount'] ?? '0.00');
            $out[] = 'Net: ' . (string) ($totals['net'] ?? '0.00');
            $out[] = 'Paid: ' . (string) ($totals['paid'] ?? '0.00');
            $out[] = 'Remaining: ' . (string) ($totals['remaining'] ?? '0.00');
        }

        $attribution = trim((string) ($payload['escalation_attribution'] ?? ''));
        if ($attribution !== '') {
            $out[] = $attribution;
        }

        return implode("\n", $out);
    }

    private function renderGeneric(array $payload): string
    {
        $title = trim((string) ($payload['title'] ?? 'POSMAIN'));
        $content = trim((string) ($payload['content_text'] ?? $payload['text'] ?? ''));
        if ($content === '') {
            throw new InvalidArgumentException('PRINT_DOCUMENT_CONTENT_REQUIRED');
        }
        if (strlen($content) > 250000) {
            throw new InvalidArgumentException('PRINT_DOCUMENT_TOO_LARGE');
        }

        return $title . "\n" . str_repeat('=', 32) . "\n" . $content;
    }
}
