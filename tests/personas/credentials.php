<?php

declare(strict_types=1);

/**
 * Default demo credentials for persona GUI tests against a Phase 6 seeded local stack.
 *
 * Override with env:
 *   POSMAIN_E2E_USER_CASHIER, POSMAIN_E2E_PASS_CASHIER, etc.
 */
return [
    'demo_password' => getenv('POSMAIN_E2E_DEMO_PASSWORD') ?: 'P6demo123!',
    'users' => [
        'admin' => [
            'username' => getenv('POSMAIN_E2E_USER_ADMIN') ?: 'p6_admin',
            'password' => getenv('POSMAIN_E2E_PASS_ADMIN') ?: (getenv('POSMAIN_E2E_DEMO_PASSWORD') ?: 'P6demo123!'),
        ],
        'manager' => [
            'username' => getenv('POSMAIN_E2E_USER_MANAGER') ?: 'p6_manager',
            'password' => getenv('POSMAIN_E2E_PASS_MANAGER') ?: (getenv('POSMAIN_E2E_DEMO_PASSWORD') ?: 'P6demo123!'),
        ],
        'cashier' => [
            'username' => getenv('POSMAIN_E2E_USER_CASHIER') ?: 'p6_cashier',
            'password' => getenv('POSMAIN_E2E_PASS_CASHIER') ?: (getenv('POSMAIN_E2E_DEMO_PASSWORD') ?: 'P6demo123!'),
        ],
        'waiter' => [
            'username' => getenv('POSMAIN_E2E_USER_WAITER') ?: 'p6_waiter',
            'password' => getenv('POSMAIN_E2E_PASS_WAITER') ?: (getenv('POSMAIN_E2E_DEMO_PASSWORD') ?: 'P6demo123!'),
        ],
    ],
];
