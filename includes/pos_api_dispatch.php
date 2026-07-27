<?php

require_once __DIR__ . '/pos_order_api_router_guard.php';
require_once __DIR__ . '/../classes/Pos/Security/PosOrderAccessPolicy.php';
require_once __DIR__ . '/../classes/Pos/Security/PosIntegrationAuth.php';
require_once __DIR__ . '/../classes/Pos/Http/PosRequest.php';
require_once __DIR__ . '/../classes/Pos/Http/PosOrderController.php';
require_once __DIR__ . '/pos_user_context.php';

if (!function_exists('pos_api_integration_routes')) {
    function pos_api_integration_routes(): array
    {
        return ['integrations.cofe.orders'];
    }
}

if (!function_exists('pos_api_browser_routes')) {
    function pos_api_browser_routes(): array
    {
        return [
            'orders.table',
            'orders.takeaway',
            'orders.delivery',
            'orders.payment',
            'orders.split-payment',
            'orders.refund',
            'orders.edit',
            'orders.table.free',
        ];
    }
}

if (!function_exists('pos_api_resolve_route_from_request')) {
    function pos_api_resolve_route_from_request(array $server): string
    {
        $route = trim((string) ($_GET['route'] ?? ''));
        if ($route !== '') {
            return $route;
        }

        $path = (string) parse_url((string) ($server['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $map = [
            '#/api/pos/orders/table$#' => 'orders.table',
            '#/api/pos/orders/takeaway$#' => 'orders.takeaway',
            '#/api/pos/orders/delivery$#' => 'orders.delivery',
            '#/api/pos/orders/payment$#' => 'orders.payment',
            '#/api/pos/orders/split-payment$#' => 'orders.split-payment',
            '#/api/pos/orders/refund$#' => 'orders.refund',
            '#/api/pos/orders/edit$#' => 'orders.edit',
            '#/api/pos/orders/table/free$#' => 'orders.table.free',
            '#/api/pos/integrations/cofe/orders$#' => 'integrations.cofe.orders',
        ];

        foreach ($map as $pattern => $resolved) {
            if (preg_match($pattern, $path)) {
                return $resolved;
            }
        }

        return '';
    }
}

if (!function_exists('pos_api_dispatch')) {
    /**
     * @return array{http_status:int,payload:array}
     */
    function pos_api_dispatch(mysqli $conn, string $route, array $options = []): array
    {
        $route = trim($route);
        if ($route === '') {
            return [
                'http_status' => 404,
                'payload' => ['success' => false, 'code' => 'NOT_FOUND', 'message' => 'Route not found'],
            ];
        }

        $isIntegration = in_array($route, pos_api_integration_routes(), true);
        $request = $options['request'] ?? PosRequest::fromGlobals();

        if ($isIntegration) {
            try {
                PosIntegrationAuth::requireCofeSignature($request->payload(), $request->server(), $conn);
            } catch (Throwable $e) {
                return [
                    'http_status' => 401,
                    'payload' => [
                        'success' => false,
                        'code' => 'INTEGRATION_UNAUTHORIZED',
                        'message' => $e->getMessage(),
                    ],
                ];
            }
            $userId = posmain_resolve_pos_user_id($request->payload());
        } else {
            require_pos_authenticated();
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                require_csrf('pos_browser');
            }

            $userId = $request->userId();
            if ($userId < 1) {
                throw new InvalidArgumentException('USER_ID_REQUIRED');
            }

            PosOrderAccessPolicy::requireRoutePermission($conn, $route);
        }

        $controller = new PosOrderController();
        $payload = $request->payload();
        $server = $request->server();

        switch ($route) {
            case 'orders.table':
                return $controller->saveTable($conn, $payload, $server, $userId);
            case 'orders.takeaway':
                return $controller->createTakeaway($conn, $payload, $server, $userId);
            case 'orders.delivery':
                return $controller->createDelivery($conn, $payload, $server, $userId);
            case 'orders.payment':
                return $controller->payTable($conn, $payload, $server, $userId);
            case 'orders.split-payment':
                return $controller->splitPayment($conn, $payload, $server, $userId);
            case 'orders.refund':
                return $controller->refundOrder($conn, $payload, $server, $userId);
            case 'orders.edit':
                return $controller->updateOrder($conn, $payload, $server, $userId);
            case 'orders.table.free':
                return $controller->freeTable($conn, $payload, $server, $userId);
            case 'integrations.cofe.orders':
                return $controller->createCofeTableOrder($conn, $payload, $server, $userId);
            default:
                return [
                    'http_status' => 404,
                    'payload' => ['success' => false, 'code' => 'NOT_FOUND', 'message' => 'Route not found'],
                ];
        }
    }
}

if (!function_exists('pos_api_emit_dispatch_result')) {
    function pos_api_emit_dispatch_result(array $result): void
    {
        http_response_code((int) ($result['http_status'] ?? 200));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result['payload'] ?? [], JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('pos_api_dispatch_exception_payload')) {
    function pos_api_dispatch_exception_payload(Throwable $e, string $route): array
    {
        if ($e instanceof InvalidArgumentException) {
            $code = in_array($e->getMessage(), [
                'PRICE_MISMATCH',
                'TOTAL_MISMATCH',
                'NET_MISMATCH',
                'USER_ID_REQUIRED',
                'IDEMPOTENCY_REQUIRED',
                'ORDER_ID_REQUIRED',
                'DELIVERY_ZONE_INVALID',
                'MUTATION_VERSION_REQUIRED',
                'MUTATION_VERSION_INVALID',
            ], true)
                ? $e->getMessage()
                : 'VALIDATION_FAILED';

            if ($code === 'VALIDATION_FAILED') {
                return [
                    'http_status' => 400,
                    'payload' => posmain_exception_payload($e, $e->getMessage(), $code, true, 'api_pos_' . $route),
                ];
            }

            return [
                'http_status' => 400,
                'payload' => [
                    'success' => false,
                    'code' => $code,
                    'message' => $e->getMessage(),
                ],
            ];
        }

        if ($e instanceof RuntimeException) {
            if (
                str_starts_with($e->getMessage(), 'KITCHEN_SNAPSHOT_')
                || str_starts_with($e->getMessage(), 'KDS_PREPARATION_SNAPSHOT_')
            ) {
                return [
                    'http_status' => 422,
                    'payload' => [
                        'success' => false,
                        'code' => 'KITCHEN_TICKET_INCOMPLETE',
                        'message' => 'تعذر إرسال تذكرة المطبخ كاملة. راجع الصنف وإضافاته وتعليمات التحضير ثم أعد المحاولة.',
                    ],
                ];
            }
            if ($e instanceof ManagerApprovalRequiredException || $e->getMessage() === 'MANAGER_APPROVAL_REQUIRED') {
                $permissionKey = $e instanceof ManagerApprovalRequiredException ? $e->permissionKey() : '';
                return [
                    'http_status' => 403,
                    'payload' => [
                        'success' => false,
                        'code' => 'MANAGER_APPROVAL_REQUIRED',
                        'message' => 'يتطلب اعتماد مدير',
                        'permission_key' => $permissionKey !== '' ? $permissionKey : null,
                        'escalation_permission_key' => $permissionKey !== '' ? $permissionKey : null,
                    ],
                ];
            }
            if ($e->getMessage() === 'PAID_ORDER_LINE_REMOVAL_DENIED') {
                return [
                    'http_status' => 422,
                    'payload' => [
                        'success' => false,
                        'code' => 'PAID_ORDER_LINE_REMOVAL_DENIED',
                        'message' => 'لا يمكن إزالة أصناف من طلب مدفوع من هنا — استخدم الاسترداد',
                    ],
                ];
            }
            if ($e->getMessage() === 'IDEMPOTENCY_CONFLICT') {
                return [
                    'http_status' => 409,
                    'payload' => [
                        'success' => false,
                        'code' => 'IDEMPOTENCY_CONFLICT',
                        'message' => 'تم استخدام نفس مفتاح الطلب مع بيانات مختلفة',
                    ],
                ];
            }
            if ($e->getMessage() === 'STALE_ORDER_VERSION') {
                return [
                    'http_status' => 409,
                    'payload' => [
                        'success' => false,
                        'code' => 'STALE_ORDER_VERSION',
                        'message' => 'تم تعديل الطلب من جهاز آخر. أعد تحميل الطلب قبل الحفظ.',
                        'reload_required' => true,
                    ],
                ];
            }
            if ($e->getMessage() === 'MUTATION_VERSION_SCHEMA_REQUIRED') {
                return [
                    'http_status' => 503,
                    'payload' => [
                        'success' => false,
                        'code' => 'MUTATION_VERSION_SCHEMA_REQUIRED',
                        'message' => 'مخطط حماية تعديلات الطلبات غير جاهز. أوقف عمليات البيع وشغّل الترحيلات المطلوبة.',
                    ],
                ];
            }
            if ($e->getMessage() === 'IDEMPOTENCY_PROCESSING') {
                return [
                    'http_status' => 423,
                    'payload' => [
                        'success' => false,
                        'code' => 'IDEMPOTENCY_PROCESSING',
                        'message' => 'طلب سابق بنفس المفتاح لا يزال قيد المعالجة',
                    ],
                ];
            }
        }

        return [
            'http_status' => 500,
            'payload' => posmain_exception_payload(
                $e,
                'حدث خطأ أثناء حفظ الطلب، يرجى المحاولة مرة أخرى',
                'ERROR',
                true,
                'api_pos_' . $route
            ),
        ];
    }
}
