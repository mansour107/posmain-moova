<?php

require_once dirname(__DIR__, 2) . '/Financial/FinancialMoneyInput.php';

class OrderLineRequest
{
    public int $itemId;
    public string $qty;
    public string $price;
    public string $discount;
    public array $raw;

    public function __construct(array $line)
    {
        $this->itemId = (int) ($line['item_id'] ?? $line['id'] ?? 0);
        $this->qty = FinancialMoneyInput::quantityString($line['qty'] ?? 0);
        $this->price = FinancialMoneyInput::unitPriceString($line['price'] ?? 0);
        $this->discount = FinancialMoneyInput::unitPriceString($line['discount'] ?? 0);
        $this->raw = $line;
    }

    public function toArray(): array
    {
        $line = $this->raw;
        $line['id'] = $this->itemId;
        $line['item_id'] = $this->itemId;
        $line['qty'] = $this->qty;
        $line['price'] = $this->price;
        $line['discount'] = $this->discount;

        return $line;
    }
}

class OrderCreateRequest
{
    public int $tableId;
    public int $orderId;
    public string $orderDate;
    public int $storeId;
    public int $empId;
    public int $fundId;
    public string $total;
    public string $discount;
    public string $net;
    public int $userId;
    public string $idempotencyKey;
    public ?int $posCustomerId;
    /** @var OrderLineRequest[] */
    public array $lines;
    public array $raw;

    public static function fromTableSavePayload(array $data, int $userId): self
    {
        $request = new self();
        $request->raw = $data;
        $request->tableId = (int) ($data['table_id'] ?? 0);
        $request->orderId = (int) ($data['order_id'] ?? 0);
        $request->orderDate = trim((string) ($data['order_date'] ?? date('Y-m-d')));
        $request->storeId = (int) ($data['store_id'] ?? 0);
        $request->empId = (int) ($data['emp_id'] ?? 0);
        $request->fundId = (int) ($data['fund_id'] ?? 0);
        $request->total = FinancialMoneyInput::moneyString($data['total'] ?? 0);
        $request->discount = FinancialMoneyInput::moneyString($data['discount'] ?? 0);
        if (array_key_exists('net', $data)) {
            $request->net = FinancialMoneyInput::moneyString($data['net']);
        } else {
            $computedNet = FinancialMoneyInput::money($request->total)
                ->subtract(FinancialMoneyInput::money($request->discount));
            $request->net = $computedNet->isNegative() ? '0.00' : $computedNet->toString();
        }
        $request->userId = $userId;
        $request->idempotencyKey = trim((string) ($data['idempotency_key'] ?? $data['idempotencyKey'] ?? ''));
        $request->posCustomerId = self::resolvePosCustomerId($data);
        $request->lines = [];
        foreach ((array) ($data['items'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $request->lines[] = new OrderLineRequest($line);
        }

        return $request;
    }

    public static function fromTakeawayPayload(array $data, int $userId): self
    {
        $request = self::fromTableSavePayload($data, $userId);
        $request->tableId = 0;
        $request->raw['channel'] = 'takeaway';

        return $request;
    }

    public static function fromDeliveryPayload(array $data, int $userId): self
    {
        $request = self::fromTableSavePayload($data, $userId);
        $request->tableId = 0;
        $request->raw['channel'] = 'delivery';

        return $request;
    }

    public static function fromTablePaymentPayload(array $data, int $userId): self
    {
        $request = new self();
        $request->raw = $data;
        $request->orderId = (int) ($data['order_id'] ?? $data['edit_id'] ?? 0);
        $request->tableId = (int) ($data['table_id'] ?? 0);
        $request->net = FinancialMoneyInput::moneyString($data['amount'] ?? $data['net'] ?? 0);
        $request->userId = $userId;
        $request->idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
        $request->lines = [];

        return $request;
    }

    public static function fromSplitPaymentPayload(array $data, int $userId): self
    {
        $request = self::fromTablePaymentPayload($data, $userId);
        $request->raw['split_lines'] = is_array($data['lines'] ?? null) ? $data['lines'] : [];
        $request->lines = [];
        foreach ((array) ($data['lines'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $request->lines[] = new OrderLineRequest($line);
        }

        return $request;
    }

    public static function fromIntegrationPayload(array $data, int $userId, string $channel): self
    {
        $request = self::fromTableSavePayload($data, $userId);
        $request->raw['channel'] = $channel;
        $request->idempotencyKey = trim((string) ($data['idempotencyKey'] ?? $data['idempotency_key'] ?? ''));

        return $request;
    }

    public function toTableSaveArray(): array
    {
        return [
            'table_id' => $this->tableId,
            'order_id' => $this->orderId,
            'order_date' => $this->orderDate,
            'store_id' => $this->storeId,
            'emp_id' => $this->empId,
            'fund_id' => $this->fundId,
            'items' => array_map(static function (OrderLineRequest $line) {
                return $line->toArray();
            }, $this->lines),
            'total' => $this->total,
            'discount' => $this->discount,
            'net' => $this->net,
            'user_id' => $this->userId,
            'idempotency_key' => $this->idempotencyKey,
            'pos_customer_id' => $this->posCustomerId,
        ];
    }

    private static function resolvePosCustomerId(array $data): ?int
    {
        $customerId = (int) ($data['pos_customer_id'] ?? $data['posCustomerId'] ?? 0);

        return $customerId > 0 ? $customerId : null;
    }
}
