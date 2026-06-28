<?php

require_once __DIR__ . '/../Service/PosOrderMutationService.php';
require_once __DIR__ . '/../Service/IdempotencyService.php';
require_once __DIR__ . '/../Service/OrderPricingService.php';
require_once __DIR__ . '/../Service/OrderMutationSideEffectsService.php';
require_once __DIR__ . '/../Service/OrderAccountingService.php';
require_once __DIR__ . '/../Validation/OrderInputValidator.php';
require_once __DIR__ . '/../Validation/PaymentInputValidator.php';
require_once __DIR__ . '/../Validation/TableInputValidator.php';
require_once __DIR__ . '/../../../classes/TableOrderService.php';
require_once __DIR__ . '/../../../includes/pos_user_context.php';
require_once __DIR__ . '/../../../includes/pos_default_accounts.php';
require_once __DIR__ . '/../../../includes/pos_operational_store.php';
require_once __DIR__ . '/../../../includes/pos_cashier_table_service_route.php';
require_once __DIR__ . '/../DTO/OrderCreateRequest.php';
require_once __DIR__ . '/PosResponse.php';

class PosOrderController
{
    public function saveTable(mysqli $conn, array $data, array $server, int $userId, array $options = []): array
    {
        if (!$data) {
            throw new InvalidArgumentException('بيانات غير صحيحة');
        }

        $idempotencyScope = (string) ($options['idempotency_scope'] ?? PosOrderMutationService::SCOPE_TABLE_SAVE);
        $sourceSystem = (string) ($options['source_system'] ?? 'pos_table');
        $eventSource = (string) ($options['event_source'] ?? 'pos_table_save');
        $staleAfterSeconds = (int) ($options['stale_after_seconds'] ?? 300);

        $data = OrderInputValidator::validateTableSave($data);
        $data = posmain_resolve_pos_invoice_accounts($conn, posmain_load_pos_settings_row($conn), $data);
        $data = (new OrderPricingService())->resolveTableSaveRequest($conn, $data, ['user_id' => $userId]);

        $tableId = (int) ($data['table_id'] ?? 0);
        $orderId = (int) ($data['order_id'] ?? 0);
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $isUpdate = $orderId > 0;

        $idempotencyService = new IdempotencyService();
        $idempotencyKey = $idempotencyService->resolveKey($data, $server);
        $idempotencyHash = $idempotencyService->requestHashForPayload($data);

        $posMutationService = new PosOrderMutationService();
        $sideEffects = new OrderMutationSideEffectsService();
        $conn->begin_transaction();

        $idempotency = $idempotencyService->begin($conn, $idempotencyScope, $idempotencyKey, $idempotencyHash, [
            'user_id' => $userId,
            'tenant' => 0,
            'branch' => 0,
            'stale_after_seconds' => $staleAfterSeconds,
        ]);
        if (($idempotency['status'] ?? '') === 'conflict') {
            $conn->rollback();

            return [
                'http_status' => 409,
                'payload' => [
                    'success' => false,
                    'code' => 'IDEMPOTENCY_CONFLICT',
                    'message' => 'تم استخدام نفس مفتاح الطلب مع بيانات مختلفة',
                    'request_id' => $idempotencyKey,
                ],
            ];
        }
        if (($idempotency['status'] ?? '') === 'completed') {
            $conn->commit();

            return [
                'http_status' => 200,
                'payload' => $idempotency['response'],
            ];
        }
        if (!in_array($idempotency['status'] ?? '', ['started', 'reclaimed'], true)) {
            $conn->rollback();

            return $this->idempotencyProcessingResponse($idempotencyKey);
        }

        $saveEnvelope = $posMutationService->saveTableOrder($conn, [
            'table_id' => $tableId,
            'order_id' => $orderId,
            'order_date' => trim((string) ($data['order_date'] ?? date('Y-m-d'))),
            'store_id' => (int) ($data['store_id'] ?? 0),
            'emp_id' => (int) ($data['emp_id'] ?? 0),
            'fund_id' => (int) ($data['fund_id'] ?? 0),
            'items' => $items,
            'total' => (float) ($data['total'] ?? 0),
            'discount' => (float) ($data['discount'] ?? 0),
            'net' => (float) ($data['net'] ?? 0),
            'user_id' => $userId,
            'pos_customer_id' => (int) ($data['pos_customer_id'] ?? $data['posCustomerId'] ?? 0) ?: null,
        ], ['user_id' => $userId, 'in_transaction' => true, 'event_source' => $eventSource]);

        $saveData = $saveEnvelope['data'] ?? [];
        $orderId = (int) ($saveData['order_id'] ?? 0);
        $orderStatus = (string) ($saveData['order_status'] ?? 'active');

        $sideEffects->recordTableSave($conn, $orderId, $tableId, $isUpdate, $orderStatus, $userId, $sourceSystem, $eventSource);

        $response = is_callable($options['response_builder'] ?? null)
            ? (array) call_user_func($options['response_builder'], $orderId, $idempotencyKey, $saveData)
            : [
                'success' => true,
                'code' => 'OK',
                'order_id' => $orderId,
                'message' => 'تم حفظ الطلب بنجاح',
                'request_id' => $idempotencyKey,
                'updated_state' => [
                    'order_id' => $orderId,
                    'edit_id' => $orderId,
                    'table_id' => $tableId,
                ],
            ];

        $idempotencyService->complete($conn, $idempotencyScope, $idempotencyKey, $idempotencyHash, $response);
        $conn->commit();

        return [
            'http_status' => 200,
            'payload' => $response,
        ];
    }

    public function createTakeaway(mysqli $conn, array $data, array $server, int $userId): array
    {
        if ($this->requiresLegacyEditShim($data)) {
            return $this->updateOrder($conn, $data, $server, $userId);
        }

        $request = $this->normalizeCashierMutationRequest($conn, $data);
        $request = $this->resolveCashierPricing($conn, $request, $userId);
        OrderCreateRequest::fromTakeawayPayload($request, $userId);

        return $this->executeIdempotentWrite(
            $conn,
            $request,
            $server,
            $userId,
            PosOrderMutationService::SCOPE_TAKEAWAY_CREATE,
            function (mysqli $conn, array $context) use ($request, $userId): array {
                $mutationService = new PosOrderMutationService();
                $sideEffects = new OrderMutationSideEffectsService();
                $result = $mutationService->createTakeawayOrder($conn, $request, array_merge($context, [
                    'record_outbox' => false,
                ]));
                $saveData = is_array($result['data'] ?? null) ? $result['data'] : [];
                $orderId = (int) ($saveData['order_id'] ?? 0);
                $sideEffects->recordCashierMutation(
                    $conn,
                    $orderId,
                    'takeaway',
                    false,
                    $userId,
                    (string) ($saveData['order_status'] ?? 'active'),
                    'pos_cashier',
                    'pos_takeaway_create'
                );

                return $this->formatCashierMutationPayload($result, $request, 'takeaway');
            }
        );
    }

    public function createDelivery(mysqli $conn, array $data, array $server, int $userId): array
    {
        if ($this->requiresLegacyEditShim($data)) {
            return $this->updateOrder($conn, $data, $server, $userId);
        }

        $request = $this->normalizeCashierMutationRequest($conn, $data);
        $request = $this->resolveCashierPricing($conn, $request, $userId);
        OrderCreateRequest::fromDeliveryPayload($request, $userId);

        return $this->executeIdempotentWrite(
            $conn,
            $request,
            $server,
            $userId,
            PosOrderMutationService::SCOPE_DELIVERY_CREATE,
            function (mysqli $conn, array $context) use ($request, $userId): array {
                $mutationService = new PosOrderMutationService();
                $sideEffects = new OrderMutationSideEffectsService();
                $result = $mutationService->createDeliveryOrder($conn, $request, array_merge($context, [
                    'record_outbox' => false,
                ]));
                $saveData = is_array($result['data'] ?? null) ? $result['data'] : [];
                $orderId = (int) ($saveData['order_id'] ?? 0);
                $sideEffects->recordCashierMutation(
                    $conn,
                    $orderId,
                    'delivery',
                    false,
                    $userId,
                    (string) ($saveData['order_status'] ?? 'active'),
                    'pos_cashier_delivery',
                    'pos_delivery_create'
                );

                return $this->formatCashierMutationPayload($result, $request, 'delivery');
            }
        );
    }

    public function updateOrder(mysqli $conn, array $data, array $server, int $userId): array
    {
        $request = $this->normalizeCashierMutationRequest($conn, $data);
        $editId = (int) ($request['edit_id'] ?? $request['edit'] ?? $request['order_id'] ?? 0);
        if ($editId < 1) {
            throw new InvalidArgumentException('ORDER_ID_REQUIRED');
        }

        $age = (int) ($request['age'] ?? 1);
        if ($age === 2) {
            $request['order_id'] = $editId;
            $request['table_id'] = (int) ($request['table_id'] ?? $request['selected_table_id'] ?? 0);
            return $this->saveTable($conn, $request, $server, $userId);
        }

        $request['edit_id'] = $editId;
        $request = $this->resolveCashierPricing($conn, $request, $userId);
        OrderCreateRequest::fromTakeawayPayload($request, $userId);
        $channel = !empty($request['delivery_customer_name']) ? 'delivery' : 'takeaway';

        return $this->executeIdempotentWrite(
            $conn,
            $request,
            $server,
            $userId,
            PosOrderMutationService::SCOPE_ORDER_UPDATE,
            function (mysqli $conn, array $context) use ($request, $userId, $channel): array {
                $mutationService = new PosOrderMutationService();
                $sideEffects = new OrderMutationSideEffectsService();
                $result = $mutationService->updateCashierOrder($conn, $request, array_merge($context, [
                    'record_outbox' => false,
                ]));
                $saveData = is_array($result['data'] ?? null) ? $result['data'] : [];
                $orderId = (int) ($saveData['order_id'] ?? 0);
                $sideEffects->recordCashierMutation(
                    $conn,
                    $orderId,
                    $channel,
                    true,
                    $userId,
                    (string) ($saveData['order_status'] ?? 'active'),
                    $channel === 'delivery' ? 'pos_cashier_delivery' : 'pos_cashier',
                    'pos_cashier_update'
                );

                return $this->formatCashierMutationPayload($result, $request, $channel);
            }
        );
    }

    public function freeTable(mysqli $conn, array $data, array $server, int $userId): array
    {
        $tableId = (int) ($data['table_id'] ?? $data['selected_table_id'] ?? 0);
        if ($tableId < 1) {
            throw new InvalidArgumentException('الرجاء اختيار طاولة');
        }

        $idempotencyService = new IdempotencyService();
        $idempotencyKey = $idempotencyService->resolveKey($data, $server);
        $idempotencyHash = $idempotencyService->requestHashForPayload($data);
        $conn->begin_transaction();

        $idempotency = $idempotencyService->begin($conn, PosOrderMutationService::SCOPE_TABLE_FREE, $idempotencyKey, $idempotencyHash, [
            'user_id' => $userId,
            'tenant' => 0,
            'branch' => 0,
            'stale_after_seconds' => 300,
        ]);
        if (($idempotency['status'] ?? '') === 'conflict') {
            $conn->rollback();

            return [
                'http_status' => 409,
                'payload' => [
                    'success' => false,
                    'code' => 'IDEMPOTENCY_CONFLICT',
                    'message' => 'تم استخدام نفس مفتاح الطلب مع بيانات مختلفة',
                    'request_id' => $idempotencyKey,
                ],
            ];
        }
        if (($idempotency['status'] ?? '') === 'completed') {
            $conn->commit();

            return [
                'http_status' => 200,
                'payload' => $idempotency['response'],
            ];
        }
        if (!in_array($idempotency['status'] ?? '', ['started', 'reclaimed'], true)) {
            $conn->rollback();

            return $this->idempotencyProcessingResponse($idempotencyKey);
        }

        $mutationService = new PosOrderMutationService();
        $result = $mutationService->freeTable($conn, [
            'table_id' => $tableId,
            'user_id' => $userId,
        ], ['user_id' => $userId, 'in_transaction' => true, 'skip_idempotency' => true, 'record_outbox' => false]);

        (new OrderMutationSideEffectsService())->recordTableFreed($conn, $tableId, $userId);

        $response = [
            'success' => true,
            'code' => 'OK',
            'message' => 'تم إفراغ الطاولة بنجاح',
            'table_id' => $tableId,
            'request_id' => $idempotencyKey,
            'updated_state' => [
                'table_id' => $tableId,
                'cleared' => true,
            ],
        ];
        $idempotencyService->complete($conn, PosOrderMutationService::SCOPE_TABLE_FREE, $idempotencyKey, $idempotencyHash, $response);
        $conn->commit();

        return [
            'http_status' => 200,
            'payload' => $response,
        ];
    }

    public function payTable(mysqli $conn, array $data, array $server, int $userId): array
    {
        try {
            $paymentInput = PaymentInputValidator::validateTablePayment($data);
        } catch (Exception $e) {
            throw new InvalidArgumentException($e->getMessage());
        }

        $tableId = (int) ($paymentInput['table_id'] ?? 0);
        $orderId = (int) ($paymentInput['order_id'] ?? 0);
        $discount = $paymentInput['discount'];
        $net = $paymentInput['net'];
        $paid = (float) ($paymentInput['paid'] ?? 0);
        $paymentMethod = (string) ($paymentInput['payment_method'] ?? 'cash');
        $notes = (string) ($paymentInput['notes'] ?? '');

        if ($tableId <= 0 || $paid <= 0) {
            throw new InvalidArgumentException('بيانات غير صحيحة');
        }

        $tableOrderService = new TableOrderService();
        $posMutationService = new PosOrderMutationService();
        $accountingPostingService = new OrderAccountingService();
        $idempotencyService = new IdempotencyService();
        $idempotencyKey = $idempotencyService->resolveKey($data, $server);
        $idempotencyHash = $idempotencyService->requestHashForPayload($data);
        $conn->begin_transaction();

        $idempotency = $idempotencyService->begin($conn, PosOrderMutationService::SCOPE_TABLE_PAYMENT, $idempotencyKey, $idempotencyHash, [
            'user_id' => $userId,
            'tenant' => 0,
            'branch' => 0,
            'stale_after_seconds' => 300,
        ]);
        if (($idempotency['status'] ?? '') === 'conflict') {
            $conn->rollback();

            return [
                'http_status' => 409,
                'payload' => [
                    'success' => false,
                    'code' => 'IDEMPOTENCY_CONFLICT',
                    'message' => 'تم استخدام نفس مفتاح الطلب مع بيانات مختلفة',
                    'request_id' => $idempotencyKey,
                ],
            ];
        }
        if (($idempotency['status'] ?? '') === 'completed') {
            $conn->commit();

            return [
                'http_status' => 200,
                'payload' => $idempotency['response'],
            ];
        }
        if (!in_array($idempotency['status'] ?? '', ['started', 'reclaimed'], true)) {
            $conn->rollback();

            return $this->idempotencyProcessingResponse($idempotencyKey);
        }

        $table = $tableOrderService->requireTable($conn, $tableId);
        if ($orderId <= 0) {
            $activeOrder = $tableOrderService->findActiveOrderByTableId($conn, $tableId, true);
            if (!$activeOrder) {
                throw new InvalidArgumentException('لا يوجد طلب نشط لهذه الطاولة');
            }
            $orderId = (int) $activeOrder['id'];
        }

        $paymentEnvelope = $posMutationService->payTableOrder($conn, [
            'table_id' => $tableId,
            'order_id' => $orderId,
            'paid' => $paid,
            'payment_method' => $paymentMethod,
            'notes' => $notes,
            'user_id' => $userId,
            'discount' => $discount,
            'net' => $net,
            'pos_customer_id' => (int) ($data['pos_customer_id'] ?? 0),
        ], ['user_id' => $userId]);
        $paymentResult = $paymentEnvelope['data'] ?? [];

        $order = $tableOrderService->queryOne($conn, 'SELECT * FROM ot_head WHERE id = ? LIMIT 1', [$orderId]);
        if (!$order) {
            throw new InvalidArgumentException('الطلب غير موجود');
        }

        $receiptId = null;
        $actualPaid = (float) ($paymentResult['applied_amount'] ?? 0);
        if ($actualPaid > 0) {
            $date = date('Y-m-d');
            $safeAcc = 51;
            $safeRes = $conn->query("SELECT id FROM acc_head WHERE aname LIKE '%خزينة%' OR aname LIKE '%صندوق%' LIMIT 1");
            if ($safeRes && $safeRes->num_rows > 0) {
                $safeAcc = (int) $safeRes->fetch_assoc()['id'];
            }

            $customerAcc = $tableOrderService->resolveDefaultCustomerId($conn, (int) ($order['acc2'] ?? 0));
            $empId = (int) ($order['emp_id'] ?? 0);
            $accountingResult = $accountingPostingService->postTablePaymentReceipt($conn, [
                'order_id' => $orderId,
                'table_name' => $table['tname'] ?? '',
                'amount' => $actualPaid,
                'safe_account_id' => $safeAcc,
                'customer_account_id' => $customerAcc,
                'emp_id' => $empId,
                'payment_date' => $date,
                'user_id' => $userId,
            ], ['user_id' => $userId, 'tenant' => 0, 'branch' => 0]);
            $receiptId = $accountingResult['receipt_id'] ?? null;
        }

        (new OrderMutationSideEffectsService())->recordTablePayment(
            $conn,
            $orderId,
            $tableId,
            $userId,
            !empty($paymentResult['fully_paid'])
        );

        $response = [
            'success' => true,
            'code' => 'OK',
            'message' => $paymentResult['fully_paid'] ? 'تم السداد بالكامل' : 'تم تسجيل دفعة جزئية',
            'receipt_id' => $receiptId,
            'order_id' => $orderId,
            'invoice_id' => $orderId,
            'payment_status' => $paymentResult['payment_status'] ?? null,
            'remaining_amount' => $paymentResult['remaining_amount'] ?? null,
            'request_id' => $idempotencyKey,
            'print_url' => 'print/receipt.php?id=' . $orderId,
            'updated_state' => [
                'order_id' => $orderId,
                'table_id' => $tableId,
            ],
        ];
        $idempotencyService->complete($conn, PosOrderMutationService::SCOPE_TABLE_PAYMENT, $idempotencyKey, $idempotencyHash, $response);
        $conn->commit();

        return [
            'http_status' => 200,
            'payload' => $response,
        ];
    }

    public function splitPayment(mysqli $conn, array $data, array $server, int $userId): array
    {
        try {
            $data = PaymentInputValidator::validateSplitPayment($data);
        } catch (Exception $e) {
            throw new InvalidArgumentException($e->getMessage());
        }

        $originalOrderId = (int) ($data['order_id'] ?? 0);
        $tableId = (int) ($data['table_id'] ?? 0);
        $rawItems = is_array($data['items'] ?? null) ? $data['items'] : [];
        $splitRequests = [];
        foreach ($rawItems as $item) {
            if (is_array($item)) {
                $detailId = (int) ($item['detail_id'] ?? $item['detailId'] ?? $item['id'] ?? 0);
                $qty = isset($item['qty']) ? (float) $item['qty'] : (isset($item['quantity']) ? (float) $item['quantity'] : null);
            } else {
                $detailId = (int) $item;
                $qty = null;
            }

            if ($detailId > 0) {
                if (!isset($splitRequests[$detailId])) {
                    $splitRequests[$detailId] = ['qty' => null];
                }
                if ($qty !== null) {
                    $splitRequests[$detailId]['qty'] = ($splitRequests[$detailId]['qty'] ?? 0) + $qty;
                }
            }
        }
        $selectedItems = array_keys($splitRequests);
        $paidAmount = (float) ($data['paid_amount'] ?? 0);
        $paymentMethod = trim((string) ($data['payment_method'] ?? 'cash'));

        if ($originalOrderId <= 0 || $tableId <= 0 || !$selectedItems || $paidAmount <= 0) {
            throw new InvalidArgumentException('بيانات السداد المقسم غير صحيحة');
        }

        $posMutationService = new PosOrderMutationService();
        $idempotencyService = new IdempotencyService();
        $idempotencyKey = $idempotencyService->resolveKey($data, $server);
        $idempotencyHash = $idempotencyService->requestHashForPayload($data);
        $conn->begin_transaction();

        $idempotency = $idempotencyService->begin($conn, PosOrderMutationService::SCOPE_SPLIT_PAYMENT, $idempotencyKey, $idempotencyHash, [
            'user_id' => $userId,
            'tenant' => 0,
            'branch' => 0,
            'stale_after_seconds' => 300,
        ]);
        if (($idempotency['status'] ?? '') === 'conflict') {
            $conn->rollback();

            return [
                'http_status' => 409,
                'payload' => [
                    'success' => false,
                    'code' => 'IDEMPOTENCY_CONFLICT',
                    'message' => 'تم استخدام نفس مفتاح الطلب مع بيانات مختلفة',
                    'request_id' => $idempotencyKey,
                ],
            ];
        }
        if (($idempotency['status'] ?? '') === 'completed') {
            $conn->commit();

            return [
                'http_status' => 200,
                'payload' => $idempotency['response'],
            ];
        }
        if (!in_array($idempotency['status'] ?? '', ['started', 'reclaimed'], true)) {
            $conn->rollback();

            return $this->idempotencyProcessingResponse($idempotencyKey);
        }

        $splitEnvelope = $posMutationService->splitTablePayment($conn, [
            'order_id' => $originalOrderId,
            'table_id' => $tableId,
            'items' => $rawItems,
            'paid_amount' => $paidAmount,
            'payment_method' => $paymentMethod,
            'user_id' => $userId,
        ], ['user_id' => $userId, 'in_transaction' => true]);
        $splitData = $splitEnvelope['data'] ?? [];
        $newHeadId = (int) ($splitData['new_invoice_id'] ?? 0);
        $splitGroupId = (string) ($splitData['split_group_id'] ?? '');
        $remainingTotal = (float) ($splitData['remaining_total'] ?? 0);
        $activeTableOrderId = $splitData['active_order_id'] ?? null;

        (new OrderMutationSideEffectsService())->recordSplitPayment(
            $conn,
            $originalOrderId,
            $newHeadId,
            $tableId,
            $userId,
            $activeTableOrderId
        );

        $response = [
            'success' => true,
            'code' => 'OK',
            'message' => 'تم سداد الأصناف المختارة بنجاح',
            'new_invoice_id' => $newHeadId,
            'order_id' => $newHeadId,
            'invoice_id' => $newHeadId,
            'split_group_id' => $splitGroupId,
            'remaining_total' => $remainingTotal,
            'request_id' => $idempotencyKey,
            'print_url' => $newHeadId > 0 ? 'print/receipt.php?id=' . $newHeadId : null,
            'redirect_url' => 'pos_barcode.php',
        ];
        $idempotencyService->complete($conn, PosOrderMutationService::SCOPE_SPLIT_PAYMENT, $idempotencyKey, $idempotencyHash, $response);
        $conn->commit();

        return [
            'http_status' => 200,
            'payload' => $response,
        ];
    }

    public function createCofeTableOrder(mysqli $conn, array $data, array $server, int $userId): array
    {
        $cofeItems = is_array($data['items'] ?? null) ? $data['items'] : [];
        if ($cofeItems === []) {
            throw new InvalidArgumentException('لا توجد أصناف في الطلب');
        }

        $tableNumber = trim((string) ($data['tableNumber'] ?? ''));
        $idempotencyKey = trim((string) ($data['idempotencyKey'] ?? $data['idempotency_key'] ?? ''));
        $tableId = self::resolveCofeTableId($conn, $tableNumber);
        if ($tableId < 1) {
            throw new InvalidArgumentException('رقم الطاولة مطلوب');
        }

        $orderItems = [];
        foreach ($cofeItems as $cofeItem) {
            if (!is_array($cofeItem)) {
                continue;
            }
            $cofeItemId = (string) ($cofeItem['itemId'] ?? '');
            $qty = (float) ($cofeItem['qty'] ?? 1);
            if ($qty <= 0 || $cofeItemId === '') {
                continue;
            }

            $stmt = $conn->prepare(
                "SELECT id, price1
                 FROM myitems
                 WHERE (cofe_item_id = ? OR barcode = ? OR id = ?)
                   AND (isdeleted = 0 OR isdeleted IS NULL)
                 LIMIT 1"
            );
            $cofeItemIdInt = (int) $cofeItemId;
            $stmt->bind_param('ssi', $cofeItemId, $cofeItemId, $cofeItemIdInt);
            $stmt->execute();
            $item = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$item) {
                throw new InvalidArgumentException("الصنف رقم {$cofeItemId} غير موجود في النظام");
            }

            $orderItems[] = [
                'id' => (int) $item['id'],
                'qty' => $qty,
                'price' => (float) $item['price1'],
                'discount' => 0,
            ];
        }

        if ($orderItems === []) {
            throw new InvalidArgumentException('لا توجد أصناف صالحة');
        }

        $accounts = posmain_resolve_pos_invoice_accounts($conn, posmain_load_pos_settings_row($conn), []);
        $total = 0.0;
        foreach ($orderItems as $line) {
            $total += (float) $line['qty'] * (float) $line['price'];
        }

        $payload = [
            'table_id' => $tableId,
            'order_id' => 0,
            'order_date' => date('Y-m-d'),
            'store_id' => (int) ($accounts['store_id'] ?? 0),
            'emp_id' => (int) ($accounts['emp_id'] ?? 0),
            'fund_id' => (int) ($accounts['fund_id'] ?? 0),
            'items' => $orderItems,
            'total' => $total,
            'discount' => 0,
            'net' => $total,
            'idempotency_key' => $idempotencyKey,
        ];

        if ($idempotencyKey !== '') {
            $payload['idempotencyKey'] = $idempotencyKey;
        }

        return $this->saveTable($conn, $payload, $server, $userId, [
            'idempotency_scope' => PosOrderMutationService::SCOPE_COFE_CREATE,
            'source_system' => 'cofe_widget',
            'event_source' => 'cofe_create_order',
            'stale_after_seconds' => 600,
            'response_builder' => static function (int $orderId, string $requestId) use ($idempotencyKey): array {
                return [
                    'success' => true,
                    'orderId' => $orderId,
                    'providerOrderId' => (string) $orderId,
                    'providerReferenceId' => $idempotencyKey !== '' ? $idempotencyKey : $requestId,
                    'providerStatus' => 'created',
                    'message' => 'تم إنشاء الطلب بنجاح',
                ];
            },
        ]);
    }

    private function requiresLegacyEditShim(array $data): bool
    {
        return (int) ($data['edit_id'] ?? $data['edit'] ?? 0) > 0;
    }

    /**
     * @param callable(mysqli,array):array $callback
     * @return array{http_status:int,payload:array}
     */
    private function executeIdempotentWrite(
        mysqli $conn,
        array $data,
        array $server,
        int $userId,
        string $scope,
        callable $callback,
        int $staleAfterSeconds = 300
    ): array {
        $idempotencyService = new IdempotencyService();
        $idempotencyKey = $idempotencyService->resolveKey($data, $server);
        $idempotencyHash = $idempotencyService->requestHashForPayload($data);
        $conn->begin_transaction();

        $idempotency = $idempotencyService->begin($conn, $scope, $idempotencyKey, $idempotencyHash, [
            'user_id' => $userId,
            'tenant' => 0,
            'branch' => 0,
            'stale_after_seconds' => $staleAfterSeconds,
        ]);
        $gate = $this->mapIdempotencyGate($conn, $idempotency, $idempotencyKey);
        if ($gate !== null) {
            return $gate;
        }

        try {
            $payload = $callback($conn, [
                'user_id' => $userId,
                'in_transaction' => true,
                'skip_idempotency' => true,
                'transaction_started' => true,
            ]);
            if (!is_array($payload)) {
                throw new RuntimeException('INVALID_IDEMPOTENT_CALLBACK_RESPONSE');
            }
            $payload['request_id'] = $idempotencyKey;
            $idempotencyService->complete($conn, $scope, $idempotencyKey, $idempotencyHash, $payload);
            $conn->commit();

            return [
                'http_status' => 200,
                'payload' => $payload,
            ];
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    private function mapIdempotencyGate(mysqli $conn, array $idempotency, string $idempotencyKey): ?array
    {
        if (($idempotency['status'] ?? '') === 'conflict') {
            $conn->rollback();

            return [
                'http_status' => 409,
                'payload' => [
                    'success' => false,
                    'code' => 'IDEMPOTENCY_CONFLICT',
                    'message' => 'تم استخدام نفس مفتاح الطلب مع بيانات مختلفة',
                    'request_id' => $idempotencyKey,
                ],
            ];
        }
        if (($idempotency['status'] ?? '') === 'completed') {
            $conn->commit();
            $response = is_array($idempotency['response'] ?? null) ? $idempotency['response'] : [];
            $response['idempotency_replayed'] = true;

            return [
                'http_status' => 200,
                'payload' => $response,
            ];
        }
        if (!in_array($idempotency['status'] ?? '', ['started', 'reclaimed'], true)) {
            $conn->rollback();

            return $this->idempotencyProcessingResponse($idempotencyKey);
        }

        return null;
    }

    private function idempotencyProcessingResponse(string $idempotencyKey): array
    {
        return [
            'http_status' => 423,
            'payload' => [
                'success' => false,
                'code' => 'IDEMPOTENCY_PROCESSING',
                'message' => 'طلب سابق بنفس المفتاح لا يزال قيد المعالجة',
                'request_id' => $idempotencyKey,
            ],
        ];
    }

    private function resolveCashierPricing(mysqli $conn, array $data, int $userId): array
    {
        $items = [];
        if (isset($data['items']) && is_array($data['items']) && $data['items']) {
            $items = $data['items'];
        } elseif (isset($data['itmname']) && is_array($data['itmname'])) {
            $qtyFields = is_array($data['itmqty'] ?? null) ? $data['itmqty'] : [];
            $priceFields = is_array($data['itmprice'] ?? null) ? $data['itmprice'] : [];
            $discFields = is_array($data['itmdisc'] ?? null) ? $data['itmdisc'] : [];
            foreach ($data['itmname'] as $index => $itemId) {
                $items[] = [
                    'id' => (int) $itemId,
                    'qty' => (float) ($qtyFields[$index] ?? 1),
                    'price' => (float) ($priceFields[$index] ?? 0),
                    'discount' => (float) ($discFields[$index] ?? 0),
                ];
            }
        }

        if (!$items) {
            return $data;
        }

        $pricingInput = [
            'items' => $items,
            'total' => (float) ($data['headtotal'] ?? $data['total'] ?? 0),
            'discount' => (float) ($data['headdisc'] ?? $data['discount'] ?? 0),
            'net' => (float) ($data['headnet'] ?? $data['net'] ?? 0),
            'manager_approval_id' => $data['manager_approval_id'] ?? null,
            'price_override_approval_id' => $data['price_override_approval_id'] ?? null,
        ];
        $resolved = (new OrderPricingService())->resolveTableSaveRequest($conn, $pricingInput, ['user_id' => $userId]);
        $data['items'] = $resolved['items'];
        $data['headtotal'] = $resolved['total'];
        $data['headdisc'] = $resolved['discount'];
        $data['headnet'] = $resolved['net'];
        $data['total'] = $resolved['total'];
        $data['discount'] = $resolved['discount'];
        $data['net'] = $resolved['net'];

        return $data;
    }

    private function normalizeCashierMutationRequest(mysqli $conn, array $data): array
    {
        if (!isset($data['itmname']) && isset($data['items']) && is_array($data['items'])) {
            $data['itmname'] = [];
            $data['itmqty'] = [];
            $data['itmprice'] = [];
            $data['itmdisc'] = [];
            $data['itmnote'] = [];
            foreach ($data['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $data['itmname'][] = (int) ($item['id'] ?? $item['item_id'] ?? 0);
                $data['itmqty'][] = (float) ($item['qty'] ?? 1);
                $data['itmprice'][] = (float) ($item['price'] ?? 0);
                $data['itmdisc'][] = (float) ($item['discount'] ?? 0);
                $data['itmnote'][] = (string) ($item['note'] ?? '');
            }
        }

        $settings = posmain_load_pos_settings_row($conn);
        $resolved = posmain_resolve_pos_invoice_accounts($conn, $settings, [
            'store_id' => (int) ($data['store_id'] ?? 0),
            'emp_id' => (int) ($data['emp_id'] ?? 0),
            'fund_id' => (int) ($data['fund_id'] ?? 0),
            'acc2_id' => (int) ($data['acc2_id'] ?? 0),
            'payment_fund_id' => (int) ($data['payment_fund_id'] ?? $data['fund_id'] ?? 0),
            'payment_bank_id' => (int) ($data['payment_bank_id'] ?? 0),
            'paid_bank' => (float) ($data['paid_bank'] ?? 0),
        ]);

        $data['store_id'] = (int) $resolved['store_id'];
        $data['emp_id'] = (int) $resolved['emp_id'];
        $data['fund_id'] = (int) $resolved['fund_id'];
        $data['acc2_id'] = (int) $resolved['acc2_id'];
        $data['payment_fund_id'] = (int) $resolved['payment_fund_id'];
        $data['payment_bank_id'] = (int) $resolved['payment_bank_id'];

        if (!isset($data['submit']) && isset($data['submit_action'])) {
            $data['submit'] = $data['submit_action'];
        }

        return $data;
    }

    private function formatCashierMutationPayload(array $result, array $request, string $channel): array
    {
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $orderId = (int) ($data['order_id'] ?? 0);
        $proId = (int) ($data['pro_id'] ?? 0);
        $submit = (string) ($request['submit'] ?? $request['submit_action'] ?? 'cash');
        $paid = (float) ($request['paid_cash'] ?? 0) + (float) ($request['paid_bank'] ?? 0);
        if (isset($request['paid'])) {
            $paid = (float) $request['paid'];
        }

        $message = $channel === 'delivery'
            ? 'تم حفظ طلب الدليفري بنجاح - رقم الفاتورة: ' . $proId
            : 'تم حفظ الطلب بنجاح - رقم الفاتورة: ' . $proId;

        $payload = [
            'success' => (bool) ($result['success'] ?? true),
            'code' => (string) ($result['code'] ?? 'OK'),
            'message' => $message,
            'order_id' => $orderId,
            'invoice_id' => $orderId,
            'pro_id' => $proId,
            'request_id' => (string) ($request['idempotency_key'] ?? ''),
            'payment_status' => $data['payment_status'] ?? null,
            'remaining_amount' => $data['remaining_amount'] ?? null,
            'updated_state' => [
                'order_id' => $orderId,
                'edit_id' => $orderId,
                'pro_id' => $proId,
            ],
        ];

        if ($submit === 'print_receipt' && $orderId > 0) {
            $payload['print_url'] = 'print/receipt.php?id=' . $orderId;
        } elseif ($submit === 'cash' && $paid > 0) {
            $payload['print_url'] = 'print/receipt.php?id=' . $orderId;
        }

        return $payload;
    }

    private static function resolveCofeTableId(mysqli $conn, string $tableNumber): int
    {
        $tableNumber = trim($tableNumber);
        if ($tableNumber === '') {
            return 0;
        }

        if (ctype_digit($tableNumber)) {
            $tableId = (int) $tableNumber;
            $stmt = $conn->prepare('SELECT id FROM tables WHERE id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $tableId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    return $tableId;
                }
            }
        }

        $stmt = $conn->prepare('SELECT id FROM tables WHERE tname = ? LIMIT 1');
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('s', $tableNumber);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['id'] ?? 0);
    }
}
