<?php

require_once __DIR__ . '/../Service/PosOrderMutationService.php';
require_once __DIR__ . '/../Service/IdempotencyService.php';
require_once __DIR__ . '/../Service/OrderPricingService.php';
require_once __DIR__ . '/../Service/OrderMutationSideEffectsService.php';
require_once __DIR__ . '/../Service/OrderAccountingService.php';
require_once __DIR__ . '/../Service/DrawerSessionService.php';
require_once __DIR__ . '/../Service/PaymentMethodService.php';
require_once __DIR__ . '/../../../classes/Financial/Money.php';
require_once __DIR__ . '/../../../classes/Financial/FinancialMoneyInput.php';
require_once __DIR__ . '/../../../classes/Financial/DecimalQuantity.php';
require_once __DIR__ . '/../../../classes/Financial/UnitPrice.php';
require_once __DIR__ . '/../../../classes/Financial/RoundingPolicy.php';
require_once __DIR__ . '/../../../classes/Financial/FinancialRefundService.php';
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
    public function refundOrder(mysqli $conn, array $data, array $server, int $userId): array
    {
        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? $data['idempotencyKey'] ?? ''));
        if ($idempotencyKey === '') {
            $idempotencyKey = (new IdempotencyService())->resolveKey($data, $server);
        }
        $data['idempotency_key'] = $idempotencyKey;
        $data['user_id'] = $userId;
        $data['order_id'] = (int) ($data['order_id'] ?? $data['original_order_id'] ?? 0);
        $data['action'] = 'refund';

        $result = (new PosOrderMutationService())->reversePaidOrder($conn, $data, $this->browserMutationContext($userId, [
            'require_drawer_session' => true,
            'event_source' => 'pos_api_refund',
        ]));
        $refundData = $result['data'] ?? [];
        $replayed = !empty($refundData['replayed']);

        return [
            'http_status' => $replayed ? 200 : 201,
            'payload' => [
                'success' => true,
                'code' => $replayed ? 'REFUND_REPLAYED' : 'REFUND_POSTED',
                'data' => $refundData,
                'request_id' => $idempotencyKey,
            ],
        ];
    }

    public function saveTable(mysqli $conn, array $data, array $server, int $userId, array $options = []): array
    {
        if (!$data) {
            throw new InvalidArgumentException('بيانات غير صحيحة');
        }

        $data['idempotency_key'] = $this->requireIdempotencyKey($data, $server);
        $idempotencyScope = (string) ($options['idempotency_scope'] ?? PosOrderMutationService::SCOPE_TABLE_SAVE);
        $sourceSystem = (string) ($options['source_system'] ?? 'pos_table');
        $eventSource = (string) ($options['event_source'] ?? 'pos_table_save');
        $staleAfterSeconds = (int) ($options['stale_after_seconds'] ?? 300);

        $data = OrderInputValidator::validateTableSave($data);
        $resolvedAccounts = posmain_resolve_pos_invoice_accounts($conn, posmain_load_pos_settings_row($conn), $data);
        $data['store_id'] = (int) ($resolvedAccounts['store_id'] ?? $data['store_id'] ?? 0);
        $data['emp_id'] = (int) ($resolvedAccounts['emp_id'] ?? $data['emp_id'] ?? 0);
        $data['fund_id'] = (int) ($resolvedAccounts['fund_id'] ?? $data['fund_id'] ?? 0);
        $data['acc2_id'] = (int) ($resolvedAccounts['acc2_id'] ?? $data['acc2_id'] ?? 0);
        $data['payment_fund_id'] = (int) ($resolvedAccounts['payment_fund_id'] ?? $data['payment_fund_id'] ?? $data['fund_id'] ?? 0);
        $data['payment_bank_id'] = (int) ($resolvedAccounts['payment_bank_id'] ?? $data['payment_bank_id'] ?? 0);
        if (!empty($resolvedAccounts['sales_account_id'])) {
            $data['sales_account_id'] = (int) $resolvedAccounts['sales_account_id'];
        }
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
        $sideEffects->preflightSyncIdentity($conn);
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

        $saveEnvelope = $posMutationService->saveTableOrder($conn, [
            'table_id' => $tableId,
            'order_id' => $orderId,
            'order_date' => trim((string) ($data['order_date'] ?? date('Y-m-d'))),
            'store_id' => (int) ($data['store_id'] ?? 0),
            'emp_id' => (int) ($data['emp_id'] ?? 0),
            'fund_id' => (int) ($data['fund_id'] ?? 0),
            'items' => $items,
            // Monetary API contracts stay decimal strings at the boundary.
            'total' => (string) ($data['total'] ?? '0.00'),
            'discount' => (string) ($data['discount'] ?? '0.00'),
            'net' => (string) ($data['net'] ?? '0.00'),
            'mutation_version' => $data['mutation_version'] ?? $data['order_version'] ?? null,
            'user_id' => $userId,
            'pos_customer_id' => (int) ($data['pos_customer_id'] ?? $data['posCustomerId'] ?? 0) ?: null,
        ], [
            'user_id' => $userId,
            'in_transaction' => true,
            'event_source' => $eventSource,
            'record_outbox' => false,
        ]);

        $saveData = $saveEnvelope['data'] ?? [];
        $orderId = (int) ($saveData['order_id'] ?? 0);
        $orderStatus = (string) ($saveData['order_status'] ?? 'active');
        $kitchenRevision = (int) ($saveData['kitchen_revision'] ?? 0);

        $sideEffects->recordTableSave(
            $conn,
            $orderId,
            $tableId,
            $isUpdate,
            $orderStatus,
            $userId,
            $sourceSystem,
            $eventSource,
            $kitchenRevision
        );

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
                    'kitchen_revision' => $kitchenRevision,
                    'mutation_version' => (int) ($saveData['mutation_version'] ?? 0),
                    'cart_saved' => true,
                ],
            ];

        $idempotencyService->complete($conn, $idempotencyScope, $idempotencyKey, $idempotencyHash, $response);
        if (!function_exists('pos_consume_lane_permission_override_if_needed')) {
            require_once __DIR__ . '/../../../includes/auth_guard.php';
        }
        pos_consume_lane_permission_override_if_needed($conn, 'pos.table.open', $userId);
        $conn->commit();

        return [
            'http_status' => 200,
            'payload' => $response,
        ];
    }

    public function createTakeaway(mysqli $conn, array $data, array $server, int $userId): array
    {
        $data['idempotency_key'] = $this->requireIdempotencyKey($data, $server);
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
                $kitchenRevision = (int) ($saveData['kitchen_revision'] ?? 0);
                $sideEffects->recordCashierMutation(
                    $conn,
                    $orderId,
                    'takeaway',
                    false,
                    $userId,
                    (string) ($saveData['order_status'] ?? 'active'),
                    'pos_cashier',
                    'pos_takeaway_create',
                    [],
                    $kitchenRevision
                );

                return $this->formatCashierMutationPayload($result, $request, 'takeaway');
            }
        );
    }

    public function createDelivery(mysqli $conn, array $data, array $server, int $userId): array
    {
        $data['idempotency_key'] = $this->requireIdempotencyKey($data, $server);
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
                $kitchenRevision = (int) ($saveData['kitchen_revision'] ?? 0);
                $sideEffects->recordCashierMutation(
                    $conn,
                    $orderId,
                    'delivery',
                    false,
                    $userId,
                    (string) ($saveData['order_status'] ?? 'active'),
                    'pos_cashier_delivery',
                    'pos_delivery_create',
                    [],
                    $kitchenRevision
                );

                return $this->formatCashierMutationPayload($result, $request, 'delivery');
            }
        );
    }

    public function updateOrder(mysqli $conn, array $data, array $server, int $userId): array
    {
        $data['idempotency_key'] = $this->requireIdempotencyKey($data, $server);
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
        (new PosOrderMutationService())->assertCashierEditVoidApprovalIfNeeded($conn, $request, [
            'user_id' => $userId,
        ]);
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
                $kitchenRevision = (int) ($saveData['kitchen_revision'] ?? 0);
                $sideEffects->recordCashierMutation(
                    $conn,
                    $orderId,
                    $channel,
                    true,
                    $userId,
                    (string) ($saveData['order_status'] ?? 'active'),
                    $channel === 'delivery' ? 'pos_cashier_delivery' : 'pos_cashier',
                    'pos_cashier_update',
                    [
                        'reason' => $request['reason'] ?? $request['cancellation_reason'] ?? '',
                        'manager_approval_id' => $request['manager_approval_id'] ?? null,
                    ],
                    $kitchenRevision
                );

                return $this->formatCashierMutationPayload($result, $request, $channel);
            }
        );
    }

    public function freeTable(mysqli $conn, array $data, array $server, int $userId): array
    {
        $data['idempotency_key'] = $this->requireIdempotencyKey($data, $server);
        $tableId = (int) ($data['table_id'] ?? $data['selected_table_id'] ?? 0);
        if ($tableId < 1) {
            throw new InvalidArgumentException('الرجاء اختيار طاولة');
        }

        $idempotencyService = new IdempotencyService();
        $idempotencyKey = $idempotencyService->resolveKey($data, $server);
        $idempotencyHash = $idempotencyService->requestHashForPayload($data);
        (new OrderMutationSideEffectsService())->preflightSyncIdentity($conn);
        $conn->begin_transaction();
        try {

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
        if (!function_exists('pos_consume_lane_permission_override_if_needed')) {
            require_once __DIR__ . '/../../../includes/auth_guard.php';
        }
        pos_consume_lane_permission_override_if_needed($conn, 'pos.table.open', $userId);
        $conn->commit();

        return [
            'http_status' => 200,
            'payload' => $response,
        ];
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    public function payTable(mysqli $conn, array $data, array $server, int $userId): array
    {
        $data['idempotency_key'] = $this->requireIdempotencyKey($data, $server);
        try {
            $paymentInput = PaymentInputValidator::validateTablePayment($data);
        } catch (Exception $e) {
            throw new InvalidArgumentException($e->getMessage());
        }

        $tableId = (int) ($paymentInput['table_id'] ?? 0);
        $orderId = (int) ($paymentInput['order_id'] ?? 0);
        $paid = FinancialMoneyInput::money($paymentInput['paid'] ?? '0');
        $paymentInputs = is_array($paymentInput['tenders'] ?? null) ? $paymentInput['tenders'] : [];

        if ($tableId <= 0 || !$paid->isPositive()) {
            throw new InvalidArgumentException('بيانات غير صحيحة');
        }

        $tableOrderService = new TableOrderService();
        $paymentMethodService = new PaymentMethodService();
        $posMutationService = new PosOrderMutationService();
        $accountingPostingService = new OrderAccountingService();
        $idempotencyService = new IdempotencyService();
        $idempotencyKey = $idempotencyService->resolveKey($data, $server);
        $idempotencyHash = $idempotencyService->requestHashForPayload($data);
        (new OrderMutationSideEffectsService())->preflightSyncIdentity($conn);
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

        $table = $tableOrderService->requireTable($conn, $tableId);
        $resolvedPaymentOrder = $this->resolveTableOrderIdForPayment(
            $conn,
            $data,
            $tableId,
            $orderId,
            $userId,
            $posMutationService,
            $tableOrderService
        );
        $orderId = (int) ($resolvedPaymentOrder['order_id'] ?? 0);
        $autoCreatedMutationVersion = !empty($resolvedPaymentOrder['created'])
            ? (int) ($resolvedPaymentOrder['mutation_version'] ?? 0)
            : 0;

        $order = $tableOrderService->queryOne($conn, 'SELECT * FROM ot_head WHERE id = ? LIMIT 1', [$orderId]);
        if (!$order) {
            throw new InvalidArgumentException('الطلب غير موجود');
        }
        $resolvedTenders = [];
        foreach ($paymentInputs as $paymentIndex => $inputTender) {
            $resolved = $paymentMethodService->resolveTender(
                $conn,
                $inputTender['payment_method'] ?? '',
                $inputTender['reference_no'] ?? null
            );
            $resolved['amount'] = FinancialMoneyInput::moneyString($inputTender['amount'] ?? '0');
            $resolved['input_index'] = (int) $paymentIndex;
            $resolvedTenders[] = $resolved;
        }
        usort($resolvedTenders, static function (array $left, array $right): int {
            $leftCash = ($left['type'] ?? '') === 'cash' ? 1 : 0;
            $rightCash = ($right['type'] ?? '') === 'cash' ? 1 : 0;
            if ($leftCash !== $rightCash) {
                return $leftCash <=> $rightCash;
            }

            return ((int) ($left['input_index'] ?? 0)) <=> ((int) ($right['input_index'] ?? 0));
        });

        $receiptIds = [];
        $paymentResults = [];
        $totalTendered = Money::zero();
        $totalApplied = Money::zero();
        $totalChange = Money::zero();
        $expectedVersion = $data['mutation_version']
            ?? $data['order_version']
            ?? $paymentInput['mutation_version']
            ?? ($autoCreatedMutationVersion > 0 ? $autoCreatedMutationVersion : null);
        $customerAcc = $tableOrderService->resolveDefaultCustomerId($conn, (int) ($order['acc2'] ?? 0));
        $empId = (int) ($order['emp_id'] ?? 0);

        foreach ($resolvedTenders as $paymentIndex => $tender) {
            $tenderIdempotencyKey = $idempotencyKey . ':tender:' . $paymentIndex;
            $paymentEnvelope = $posMutationService->payTableOrder($conn, [
                'table_id' => $tableId,
                'order_id' => $orderId,
                'paid' => (string) $tender['amount'],
                'payment_method_id' => $tender['id'],
                'payment_method' => $tender['code'],
                'reference_no' => $tender['reference_no'],
                'user_id' => $userId,
                'pos_customer_id' => (int) ($data['pos_customer_id'] ?? 0),
                'idempotency_key' => $tenderIdempotencyKey,
                'mutation_version' => $expectedVersion,
            ], $this->browserMutationContext($userId, [
                'in_transaction' => true,
                'skip_idempotency' => true,
                'record_outbox' => false,
            ]));
            $paymentResult = $paymentEnvelope['data'] ?? [];
            $expectedVersion = $paymentResult['mutation_version'] ?? $expectedVersion;
            $actualPaid = Money::from((string) ($paymentResult['applied_amount'] ?? '0'));
            $tenderedAmount = Money::from((string) ($paymentResult['tendered_amount'] ?? $tender['amount']));
            $changeDue = Money::from((string) ($paymentResult['change_due'] ?? '0'));
            $totalTendered = $totalTendered->add($tenderedAmount);
            $totalApplied = $totalApplied->add($actualPaid);
            $totalChange = $totalChange->add($changeDue);

            $receiptId = null;
            if ($actualPaid->isPositive()) {
                $accountingResult = $accountingPostingService->postTablePaymentReceipt($conn, [
                    'order_id' => $orderId,
                    'table_name' => $table['tname'] ?? '',
                    'amount' => $actualPaid->toString(),
                    'safe_account_id' => (int) $tender['account_id'],
                    'payment_method_id' => (int) $tender['id'],
                    'payment_method_code' => (string) $tender['code'],
                    'reference_no' => $tender['reference_no'],
                    'customer_account_id' => $customerAcc,
                    'emp_id' => $empId,
                    'payment_date' => date('Y-m-d'),
                    'user_id' => $userId,
                    'idempotency_key' => $tenderIdempotencyKey,
                ], $this->browserMutationContext($userId));
                $receiptId = $accountingResult['receipt_id'] ?? null;
                $movementId = (int) ($paymentResult['drawer_movement_id'] ?? 0);
                if ($receiptId && $movementId > 0) {
                    (new DrawerSessionService())->linkMovementToVoucher($conn, $movementId, (int) $receiptId);
                } elseif ($receiptId) {
                    (new DrawerSessionService())->linkLatestSaleMovementToVoucher($conn, $orderId, (int) $receiptId);
                }
                if ($receiptId) {
                    $receiptIds[] = (int) $receiptId;
                }
            }
            $paymentResult['receipt_id'] = $receiptId;
            $paymentResult['payment_method'] = (string) $tender['code'];
            $paymentResults[] = $paymentResult;
        }
        $paymentResult = $paymentResults[count($paymentResults) - 1] ?? [];
        if (count($paymentResults) > 1) {
            $tableOrderService->execute(
                $conn,
                "UPDATE ot_head SET payment_method = 'mixed' WHERE id = ? AND table_id = ?",
                [$orderId, $tableId]
            );
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
            'message' => !empty($paymentResult['fully_paid']) ? 'تم السداد بالكامل' : 'تم تسجيل دفعة جزئية',
            'receipt_id' => $receiptIds ? $receiptIds[count($receiptIds) - 1] : null,
            'receipt_ids' => $receiptIds,
            'payments' => $paymentResults,
            'tendered_amount' => $totalTendered->toString(),
            'applied_amount' => $totalApplied->toString(),
            'change_due' => $totalChange->toString(),
            'order_id' => $orderId,
            'invoice_id' => $orderId,
            'payment_status' => $paymentResult['payment_status'] ?? null,
            'remaining_amount' => $paymentResult['remaining_amount'] ?? null,
            'request_id' => $idempotencyKey,
            'print_url' => 'print/receipt.php?id=' . $orderId,
            'updated_state' => [
                'order_id' => $orderId,
                'table_id' => $tableId,
                'mutation_version' => (int) ($paymentResult['mutation_version'] ?? 0),
            ],
        ];
        $idempotencyService->complete($conn, PosOrderMutationService::SCOPE_TABLE_PAYMENT, $idempotencyKey, $idempotencyHash, $response);
        if (!function_exists('pos_consume_lane_permission_override_if_needed')) {
            require_once __DIR__ . '/../../../includes/auth_guard.php';
        }
        pos_consume_lane_permission_override_if_needed($conn, 'pos.table.open', $userId);
        $conn->commit();

        return [
            'http_status' => 200,
            'payload' => $response,
        ];
    }

    public function splitPayment(mysqli $conn, array $data, array $server, int $userId): array
    {
        $data['idempotency_key'] = $this->requireIdempotencyKey($data, $server);
        $expectedMutationVersion = $data['mutation_version'] ?? $data['order_version'] ?? null;
        $splitRows = $this->extractSplitPaymentRows($data);
        $tableId = (int) ($data['table_id'] ?? $data['selected_table_id'] ?? 0);
        $orderId = (int) ($data['order_id'] ?? $data['edit_id'] ?? $data['selected_order_id'] ?? 0);
        $paidAmount = FinancialMoneyInput::money($data['paid_amount'] ?? $data['paid'] ?? '0');
        $paymentMethod = trim((string) ($data['payment_method'] ?? $data['pos_split_payment_method'] ?? ''));
        if ($paymentMethod === '') {
            $paymentMethod = FinancialMoneyInput::money($data['paid_bank'] ?? '0')->isPositive() ? 'bank' : 'cash';
        }

        if ($tableId <= 0 || !$paidAmount->isPositive() || !$splitRows) {
            throw new InvalidArgumentException('بيانات السداد المقسم غير صحيحة');
        }

        $posMutationService = new PosOrderMutationService();
        $idempotencyService = new IdempotencyService();
        $idempotencyKey = $idempotencyService->resolveKey($data, $server);
        $idempotencyHash = $idempotencyService->requestHashForPayload($data);
        (new OrderMutationSideEffectsService())->preflightSyncIdentity($conn);
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

        try {
            $resolvedItems = $this->resolveSplitPaymentItems($conn, $data, $splitRows, $tableId, $orderId, $userId, $posMutationService);
            $data = PaymentInputValidator::validateSplitPayment([
                'order_id' => $orderId,
                'table_id' => $tableId,
                'items' => $resolvedItems,
                'paid_amount' => $paidAmount->toString(),
                'payment_method' => $paymentMethod,
                'tenders' => $data['tenders'] ?? null,
                'reference_no' => $data['reference_no'] ?? $data['notes'] ?? '',
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            throw new InvalidArgumentException($e->getMessage());
        }

        $originalOrderId = (int) ($data['order_id'] ?? 0);
        $tableId = (int) ($data['table_id'] ?? 0);
        $rawItems = is_array($data['items'] ?? null) ? $data['items'] : [];
        $paidAmount = (string) ($data['paid_amount'] ?? '0.00');
        $paymentMethod = trim((string) ($data['payment_method'] ?? 'cash'));

        $splitEnvelope = $posMutationService->splitTablePayment($conn, [
            'order_id' => $originalOrderId,
            'table_id' => $tableId,
            'items' => $rawItems,
            'paid_amount' => $paidAmount,
            'payment_method' => $paymentMethod,
            'tenders' => $data['tenders'] ?? [],
            'user_id' => $userId,
            'idempotency_key' => $idempotencyKey,
            'mutation_version' => $expectedMutationVersion,
        ], $this->browserMutationContext($userId, [
            'in_transaction' => true,
            'skip_idempotency' => true,
            'record_outbox' => false,
        ]));
        $splitData = $splitEnvelope['data'] ?? [];
        $newHeadId = (int) ($splitData['new_invoice_id'] ?? 0);
        $splitGroupId = (string) ($splitData['split_group_id'] ?? '');
        $remainingTotal = FinancialMoneyInput::moneyString($splitData['remaining_total'] ?? '0.00');
        $activeTableOrderId = $splitData['active_order_id'] ?? null;
        $splitPayments = is_array($splitData['payments'] ?? null) ? $splitData['payments'] : [];
        $receiptIds = [];
        if ($newHeadId > 0 && $splitPayments !== []) {
            $tableOrderService = new TableOrderService();
            $table = $tableOrderService->requireTable($conn, $tableId);
            $childOrder = $tableOrderService->queryOne($conn, 'SELECT * FROM ot_head WHERE id = ? LIMIT 1', [$newHeadId]);
            if (!$childOrder) {
                throw new RuntimeException('SPLIT_CHILD_ORDER_NOT_FOUND');
            }
            $customerAcc = $tableOrderService->resolveDefaultCustomerId($conn, (int) ($childOrder['acc2'] ?? 0));
            $accountingPostingService = new OrderAccountingService();
            foreach ($splitPayments as $paymentIndex => $splitPayment) {
                $applied = FinancialMoneyInput::money($splitPayment['applied_amount'] ?? '0');
                if (!$applied->isPositive()) {
                    continue;
                }
                $tenderKey = $idempotencyKey . ':tender:' . $paymentIndex;
                $accountingResult = $accountingPostingService->postTablePaymentReceipt($conn, [
                    'order_id' => $newHeadId,
                    'table_name' => $table['tname'] ?? '',
                    'amount' => $applied->toString(),
                    'safe_account_id' => (int) ($splitPayment['account_id'] ?? 0),
                    'payment_method_id' => (int) ($splitPayment['payment_method_id'] ?? 0),
                    'payment_method_code' => (string) ($splitPayment['payment_method'] ?? ''),
                    'reference_no' => $splitPayment['reference_no'] ?? null,
                    'customer_account_id' => $customerAcc,
                    'emp_id' => (int) ($childOrder['emp_id'] ?? 0),
                    'payment_date' => date('Y-m-d'),
                    'user_id' => $userId,
                    'idempotency_key' => $tenderKey,
                ], $this->browserMutationContext($userId));
                $receiptId = (int) ($accountingResult['receipt_id'] ?? 0);
                if ($receiptId > 0) {
                    $receiptIds[] = $receiptId;
                    $movementId = (int) ($splitPayment['drawer_movement_id'] ?? 0);
                    if ($movementId > 0) {
                        (new DrawerSessionService())->linkMovementToVoucher($conn, $movementId, $receiptId);
                    }
                }
            }
        }

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
            'active_order_id' => $activeTableOrderId !== null ? (int) $activeTableOrderId : null,
            'original_mutation_version' => (int) ($splitData['original_mutation_version'] ?? 0),
            'mutation_version' => (int) ($splitData['mutation_version'] ?? 0),
            'receipt_id' => $receiptIds ? $receiptIds[count($receiptIds) - 1] : null,
            'receipt_ids' => $receiptIds,
            'payments' => $splitPayments,
            'tendered_amount' => $splitData['tendered_amount'] ?? null,
            'paid_amount' => $splitData['paid_amount'] ?? null,
            'change_due' => $splitData['change_due'] ?? null,
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

        $cofeOrderId = trim((string) ($data['cofeOrderId'] ?? $data['cofe_order_id'] ?? ''));
        if ($cofeOrderId === '') {
            $cofeOrderId = $idempotencyKey;
        }

        $orderItems = [];
        foreach ($cofeItems as $cofeIndex => $cofeItem) {
            if (!is_array($cofeItem)) {
                continue;
            }
            $cofeItemId = (string) ($cofeItem['itemId'] ?? '');
            $qty = DecimalQuantity::from($cofeItem['qty'] ?? 1)->toString();
            if (FinancialDecimal::compare($qty, '0', DecimalQuantity::SCALE) <= 0 || $cofeItemId === '') {
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

            $externalLineId = trim((string) (
                $cofeItem['externalLineId']
                ?? $cofeItem['external_line_id']
                ?? $cofeItem['lineId']
                ?? $cofeItem['line_id']
                ?? ''
            ));
            if ($externalLineId === '') {
                $externalLineId = 'line:' . (int) $cofeIndex . ':item:' . (int) $item['id'];
            }

            $orderItems[] = [
                'id' => (int) $item['id'],
                'qty' => $qty,
                'price' => UnitPrice::from((string) $item['price1'])->toString(),
                'discount' => '0.000000',
                'external_line_id' => $externalLineId,
                'source_order_uuid' => $cofeOrderId,
                'source_line_uuid' => substr('cofe:' . $externalLineId, 0, 128),
                'source_channel' => 'cofe',
                'modifiers' => is_array($cofeItem['modifiers'] ?? null) ? $cofeItem['modifiers'] : [],
            ];
        }

        if ($orderItems === []) {
            throw new InvalidArgumentException('لا توجد أصناف صالحة');
        }

        $accounts = posmain_resolve_pos_invoice_accounts($conn, posmain_load_pos_settings_row($conn), []);
        $total = Money::zero();
        foreach ($orderItems as $line) {
            $lineTotal = RoundingPolicy::halfUp(
                FinancialDecimal::multiply($line['qty'], $line['price'], UnitPrice::SCALE)
            );
            $total = $total->add(Money::from($lineTotal));
        }

        $payload = [
            'table_id' => $tableId,
            'order_id' => 0,
            'order_date' => date('Y-m-d'),
            'store_id' => (int) ($accounts['store_id'] ?? 0),
            'emp_id' => (int) ($accounts['emp_id'] ?? 0),
            'fund_id' => (int) ($accounts['fund_id'] ?? 0),
            'items' => $orderItems,
            'total' => $total->toString(),
            'discount' => '0.00',
            'net' => $total->toString(),
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
        (new OrderMutationSideEffectsService())->preflightSyncIdentity($conn);
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
            $payload = $callback($conn, $this->browserMutationContext($userId, [
                'in_transaction' => true,
                'skip_idempotency' => true,
                'transaction_started' => true,
            ]));
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

    private function requireIdempotencyKey(array $data, array $server): string
    {
        return (new IdempotencyService())->resolveKey($data, $server);
    }

    /**
     * The AJAX bootstrap releases the PHP session lock before controller work,
     * but the authenticated session snapshot remains readable in $_SESSION.
     * Carry the drawer and branch scope explicitly into every cashier mutation
     * so cash settlement can validate owned drawers or approved overrides.
     */
    private function browserMutationContext(int $userId, array $extra = []): array
    {
        return array_merge([
            'user_id' => $userId,
            'tenant' => max(0, (int) ($_SESSION['pos_tenant'] ?? 0)),
            'branch' => max(0, (int) ($_SESSION['pos_branch'] ?? 0)),
            'drawer_session_id' => max(0, (int) ($_SESSION['pos_drawer_session_id'] ?? 0)),
        ], $extra);
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
                    'qty' => DecimalQuantity::from($qtyFields[$index] ?? 1)->toString(),
                    'price' => UnitPrice::from($priceFields[$index] ?? 0)->toString(),
                    'discount' => UnitPrice::from($discFields[$index] ?? 0)->toString(),
                ];
            }
        }

        if (!$items) {
            return $data;
        }

        $pricingInput = [
            'items' => $items,
            'total' => Money::from($data['headtotal'] ?? $data['total'] ?? 0)->toString(),
            'discount' => Money::from($data['headdisc'] ?? $data['discount'] ?? 0)->toString(),
            'net' => Money::from($data['headnet'] ?? $data['net'] ?? 0)->toString(),
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
                $data['itmqty'][] = DecimalQuantity::from($item['qty'] ?? 1)->toString();
                $data['itmprice'][] = UnitPrice::from($item['price'] ?? 0)->toString();
                $data['itmdisc'][] = UnitPrice::from($item['discount'] ?? 0)->toString();
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
            'paid_bank' => Money::from($data['paid_bank'] ?? 0)->toString(),
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

    /**
     * @return array{order_id:int,mutation_version:int,created:bool}
     */
    private function resolveTableOrderIdForPayment(
        mysqli $conn,
        array $data,
        int $tableId,
        int $orderId,
        int $userId,
        PosOrderMutationService $posMutationService,
        TableOrderService $tableOrderService
    ): array {
        if ($orderId > 0) {
            return [
                'order_id' => $orderId,
                'mutation_version' => 0,
                'created' => false,
            ];
        }

        $activeOrder = $tableOrderService->findActiveOrderByTableId($conn, $tableId, true);
        if ($activeOrder) {
            return [
                'order_id' => (int) $activeOrder['id'],
                'mutation_version' => max(1, (int) ($activeOrder['mutation_version'] ?? 1)),
                'created' => false,
            ];
        }

        $normalized = $this->normalizeCashierMutationRequest($conn, $data);
        $items = $this->extractItemsFromCashierPayload($normalized);
        if ($items === []) {
            throw new InvalidArgumentException('لا يوجد طلب نشط لهذه الطاولة');
        }

        $saveRequest = OrderInputValidator::validateTableSave([
            'table_id' => $tableId,
            'order_id' => 0,
            'order_date' => trim((string) ($normalized['pro_date'] ?? $normalized['order_date'] ?? date('Y-m-d'))),
            'store_id' => (int) ($normalized['store_id'] ?? 0),
            'emp_id' => (int) ($normalized['emp_id'] ?? 0),
            'fund_id' => (int) ($normalized['fund_id'] ?? 0),
            'items' => $items,
            'total' => Money::from($normalized['headtotal'] ?? $normalized['total'] ?? 0)->toString(),
            'discount' => Money::from($normalized['headdisc'] ?? $normalized['discount'] ?? 0)->toString(),
            'net' => Money::from($normalized['headnet'] ?? $normalized['net'] ?? 0)->toString(),
        ]);
        $saveRequest = (new OrderPricingService())->resolveTableSaveRequest($conn, $saveRequest, ['user_id' => $userId]);

        $saveEnvelope = $posMutationService->saveTableOrder($conn, [
            'table_id' => $tableId,
            'order_id' => 0,
            'order_date' => trim((string) ($saveRequest['order_date'] ?? date('Y-m-d'))),
            'store_id' => (int) ($saveRequest['store_id'] ?? 0),
            'emp_id' => (int) ($saveRequest['emp_id'] ?? 0),
            'fund_id' => (int) ($saveRequest['fund_id'] ?? 0),
            'items' => is_array($saveRequest['items'] ?? null) ? $saveRequest['items'] : [],
            'total' => Money::from($saveRequest['total'] ?? 0)->toString(),
            'discount' => Money::from($saveRequest['discount'] ?? 0)->toString(),
            'net' => Money::from($saveRequest['net'] ?? 0)->toString(),
            'user_id' => $userId,
            'pos_customer_id' => (int) ($data['pos_customer_id'] ?? $data['posCustomerId'] ?? 0) ?: null,
        ], ['user_id' => $userId, 'in_transaction' => true, 'event_source' => 'pos_table_pay_save']);

        $saveData = is_array($saveEnvelope['data'] ?? null) ? $saveEnvelope['data'] : [];
        $newOrderId = (int) ($saveData['order_id'] ?? 0);
        if ($newOrderId < 1) {
            throw new InvalidArgumentException('تعذر حفظ طلب الطاولة قبل الدفع');
        }

        (new OrderMutationSideEffectsService())->recordTableSave(
            $conn,
            $newOrderId,
            $tableId,
            false,
            (string) ($saveData['order_status'] ?? 'active'),
            $userId,
            'pos_table',
            'pos_table_pay_save',
            (int) ($saveData['kitchen_revision'] ?? 0)
        );

        return [
            'order_id' => $newOrderId,
            'mutation_version' => max(1, (int) ($saveData['mutation_version'] ?? 1)),
            'created' => true,
        ];
    }

    private function extractItemsFromCashierPayload(array $data): array
    {
        if (is_array($data['items'] ?? null) && $data['items'] !== []) {
            return $data['items'];
        }

        $names = is_array($data['itmname'] ?? null) ? $data['itmname'] : [];
        if ($names === []) {
            return [];
        }

        $qtyFields = is_array($data['itmqty'] ?? null) ? $data['itmqty'] : [];
        $priceFields = is_array($data['itmprice'] ?? null) ? $data['itmprice'] : [];
        $discFields = is_array($data['itmdisc'] ?? null) ? $data['itmdisc'] : [];
        $noteFields = is_array($data['itmnote'] ?? null) ? $data['itmnote'] : [];
        $items = [];

        foreach ($names as $index => $name) {
            $itemId = (int) $name;
            if ($itemId < 1) {
                continue;
            }
            $items[] = [
                'id' => $itemId,
                'qty' => DecimalQuantity::from($qtyFields[$index] ?? 1)->toString(),
                'price' => UnitPrice::from($priceFields[$index] ?? 0)->toString(),
                'discount' => UnitPrice::from($discFields[$index] ?? 0)->toString(),
                'note' => (string) ($noteFields[$index] ?? ''),
            ];
        }

        return $items;
    }

    private function formatCashierMutationPayload(array $result, array $request, string $channel): array
    {
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $orderId = (int) ($data['order_id'] ?? 0);
        $proId = (int) ($data['pro_id'] ?? 0);
        $submit = (string) ($request['submit'] ?? $request['submit_action'] ?? 'cash');
        $paid = Money::from($request['paid_cash'] ?? 0)->add(Money::from($request['paid_bank'] ?? 0));
        if (isset($request['paid'])) {
            $paid = Money::from($request['paid']);
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
                'kitchen_revision' => (int) ($data['kitchen_revision'] ?? 0),
                'mutation_version' => (int) ($data['mutation_version'] ?? 0),
                'cart_saved' => true,
            ],
        ];

        if ($submit === 'print_receipt' && $orderId > 0) {
            $payload['print_url'] = 'print/receipt.php?id=' . $orderId;
        } elseif ($submit === 'cash' && $paid->isPositive()) {
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

    private function extractSplitPaymentRows(array $data): array
    {
        if (isset($data['split_items']) && is_array($data['split_items'])) {
            return $data['split_items'];
        }

        $rawPayload = $data['pos_split_payment_payload'] ?? null;
        if (is_string($rawPayload) && trim($rawPayload) !== '') {
            $decoded = json_decode($rawPayload, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function splitRowsNeedDetailResolution(array $splitRows): bool
    {
        foreach ($splitRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $detailId = (int) ($row['detail_id'] ?? $row['detailId'] ?? 0);
            if ($detailId > 0) {
                continue;
            }
            if (array_key_exists('row_index', $row)) {
                return true;
            }
        }

        return false;
    }

    private function extractCartItemsForSplitSave(array $data): array
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $cartItems = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (array_key_exists('row_index', $item) && !isset($item['id']) && !isset($item['item_id'])) {
                continue;
            }
            $itemId = (int) ($item['item_id'] ?? $item['id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }
            $cartItems[] = [
                'id' => $itemId,
                'qty' => DecimalQuantity::from($item['qty'] ?? 1)->toString(),
                'price' => UnitPrice::from($item['price'] ?? 0)->toString(),
                'discount' => UnitPrice::from($item['discount'] ?? 0)->toString(),
                'note' => (string) ($item['note'] ?? ''),
            ];
        }
        if ($cartItems) {
            return $cartItems;
        }

        $names = is_array($data['itmname'] ?? null) ? $data['itmname'] : [];
        if (!$names) {
            return [];
        }

        $qtyFields = is_array($data['itmqty'] ?? null) ? $data['itmqty'] : [];
        $priceFields = is_array($data['itmprice'] ?? null) ? $data['itmprice'] : [];
        $discFields = is_array($data['itmdisc'] ?? null) ? $data['itmdisc'] : [];
        $noteFields = is_array($data['itmnote'] ?? null) ? $data['itmnote'] : [];

        foreach ($names as $index => $name) {
            $itemId = (int) $name;
            if ($itemId <= 0) {
                continue;
            }
            $cartItems[] = [
                'id' => $itemId,
                'qty' => DecimalQuantity::from($qtyFields[$index] ?? 1)->toString(),
                'price' => UnitPrice::from($priceFields[$index] ?? 0)->toString(),
                'discount' => UnitPrice::from($discFields[$index] ?? 0)->toString(),
                'note' => (string) ($noteFields[$index] ?? ''),
            ];
        }

        return $cartItems;
    }

    private function buildTableSaveRequestForSplit(mysqli $conn, array $data, int $tableId, int $orderId, array $cartItems, int $userId): array
    {
        $saveData = array_merge($data, [
            'table_id' => $tableId,
            'order_id' => $orderId,
            'items' => $cartItems,
            'user_id' => $userId,
        ]);
        $saveData = OrderInputValidator::validateTableSave($saveData);
        $resolvedAccounts = posmain_resolve_pos_invoice_accounts($conn, posmain_load_pos_settings_row($conn), $saveData);
        $saveData['store_id'] = (int) ($resolvedAccounts['store_id'] ?? $saveData['store_id'] ?? 0);
        $saveData['emp_id'] = (int) ($resolvedAccounts['emp_id'] ?? $saveData['emp_id'] ?? 0);
        $saveData['fund_id'] = (int) ($resolvedAccounts['fund_id'] ?? $saveData['fund_id'] ?? 0);

        return $saveData;
    }

    private function loadOrderDetailIdsByIndex(mysqli $conn, int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $tableOrderService = new TableOrderService();
        $rows = $tableOrderService->queryAll($conn, "
            SELECT id
            FROM fat_details
            WHERE fatid = ?
              AND isdeleted = 0
            ORDER BY id ASC
        ", [$orderId]);

        $detailIds = [];
        foreach ($rows as $row) {
            $detailIds[] = (int) ($row['id'] ?? 0);
        }

        return $detailIds;
    }

    private function mapSplitRowsToDetailIds(array $splitRows, array $detailIdsByIndex): array
    {
        $mapped = [];
        foreach ($splitRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $detailId = (int) ($row['detail_id'] ?? $row['detailId'] ?? 0);
            if ($detailId <= 0 && array_key_exists('row_index', $row)) {
                $rowIndex = (int) $row['row_index'];
                $detailId = (int) ($detailIdsByIndex[$rowIndex] ?? 0);
            }
            if ($detailId <= 0) {
                throw new InvalidArgumentException('تعذر ربط الأصناف المحددة بتفاصيل الطلب');
            }

            $mapped[] = [
                'detail_id' => $detailId,
                'qty' => DecimalQuantity::from($row['qty'] ?? $row['quantity'] ?? 0)->toString(),
            ];
        }

        if (!$mapped) {
            throw new InvalidArgumentException('بيانات السداد المقسم غير صحيحة');
        }

        return $mapped;
    }

    private function resolveSplitPaymentItems(
        mysqli $conn,
        array $data,
        array $splitRows,
        int $tableId,
        int &$orderId,
        int $userId,
        PosOrderMutationService $posMutationService
    ): array {
        if (!$this->splitRowsNeedDetailResolution($splitRows)) {
            $normalized = [];
            foreach ($splitRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $detailId = (int) ($row['detail_id'] ?? $row['detailId'] ?? $row['id'] ?? 0);
                if ($detailId <= 0) {
                    continue;
                }
                $normalized[] = [
                    'detail_id' => $detailId,
                    'qty' => isset($row['qty']) || isset($row['quantity'])
                        ? DecimalQuantity::from($row['qty'] ?? $row['quantity'] ?? 0)->toString()
                        : null,
                ];
            }

            if (!$normalized || $orderId <= 0) {
                throw new InvalidArgumentException('بيانات السداد المقسم غير صحيحة');
            }

            return $normalized;
        }

        $cartItems = $this->extractCartItemsForSplitSave($data);
        if (!$cartItems) {
            throw new InvalidArgumentException('بيانات السداد المقسم غير صحيحة');
        }

        $saveData = $this->buildTableSaveRequestForSplit($conn, $data, $tableId, $orderId, $cartItems, $userId);
        $saveData = (new OrderPricingService())->resolveTableSaveRequest($conn, $saveData, ['user_id' => $userId]);
        $saveEnvelope = $posMutationService->saveTableOrder($conn, [
            'table_id' => $tableId,
            'order_id' => (int) ($saveData['order_id'] ?? $orderId),
            'order_date' => trim((string) ($saveData['order_date'] ?? date('Y-m-d'))),
            'store_id' => (int) ($saveData['store_id'] ?? 0),
            'emp_id' => (int) ($saveData['emp_id'] ?? 0),
            'fund_id' => (int) ($saveData['fund_id'] ?? 0),
            'items' => $cartItems,
            'total' => Money::from($saveData['total'] ?? 0)->toString(),
            'discount' => Money::from($saveData['discount'] ?? 0)->toString(),
            'net' => Money::from($saveData['net'] ?? 0)->toString(),
            'user_id' => $userId,
            'pos_customer_id' => (int) ($saveData['pos_customer_id'] ?? $data['pos_customer_id'] ?? 0) ?: null,
        ], ['user_id' => $userId, 'in_transaction' => true, 'event_source' => 'pos_split_payment_save']);

        $orderId = (int) (($saveEnvelope['data'] ?? [])['order_id'] ?? 0);
        if ($orderId <= 0) {
            throw new InvalidArgumentException('تعذر حفظ الطلب قبل السداد المقسم');
        }

        return $this->mapSplitRowsToDetailIds($splitRows, $this->loadOrderDetailIdsByIndex($conn, $orderId));
    }
}
