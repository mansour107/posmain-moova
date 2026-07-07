<?php

/**
 * ERP HTML page permission manifest.
 * Keys are page filenames relative to project root.
 */
return [
    // user admin
    'users.php' => ['permission' => 'users.manage', 'admin_or' => true],
    'team.php' => ['any_of' => ['users.manage', 'roles.manage'], 'admin_or' => true],
    'add_user.php' => ['permission' => 'users.manage', 'admin_or' => true],
    'edit_user.php' => ['permission' => 'users.manage', 'admin_or' => true],
    'myroles.php' => ['permission' => 'roles.manage', 'admin_or' => true],
    'add_role.php' => ['permission' => 'roles.manage', 'admin_or' => true],
    'edit_role.php' => ['permission' => 'roles.manage', 'admin_or' => true],
    'role_permissions.php' => ['permission' => 'roles.manage', 'admin_or' => true],
    'setting.php' => ['permission' => 'system.tools.run', 'admin_or' => true],
    'change_password.php' => ['permission' => null],

    // menu / inventory
    'myitems.php' => ['permission' => 'menu.edit'],
    'add_item.php' => ['permission' => 'menu.edit'],
    'mystores.php' => ['permission' => 'inventory.edit'],
    'items_factory.php' => ['permission' => 'menu.edit'],
    'inv_operations.php' => ['permission' => 'inventory.edit'],
    'inventory_dashboard.php' => ['permission' => 'inventory.edit'],
    'inventory_reports.php' => ['permission' => 'inventory.edit'],
    'inventory_purchasing.php' => ['permission' => 'inventory.edit'],
    'inventory_counts.php' => ['permission' => 'inventory.edit'],
    'inventory_transfers.php' => ['permission' => 'inventory.edit'],
    'inventory_adjustments.php' => ['permission' => 'inventory.edit'],
    'inventory_stock_levels.php' => ['permission' => 'inventory.edit'],
    'inventory_stores.php' => ['permission' => 'inventory.edit'],
    'inventory_reason_codes.php' => ['permission' => 'inventory.edit'],

    // accounting
    'vouchers.php' => ['permission' => 'accounting.view'],
    'add_voucher.php' => ['permission' => 'accounting.view'],
    'add_journal.php' => ['permission' => 'accounting.view'],
    'addmulti_journal.php' => ['permission' => 'accounting.view'],
    'daily_journal.php' => ['permission' => 'accounting.view'],
    'myaccounts.php' => ['permission' => 'accounting.view'],
    'acc_report.php' => ['permission' => 'accounting.view'],
    'add_account.php' => ['permission' => 'accounting.view'],
    'edit_account.php' => ['permission' => 'accounting.view'],

    // reports / HR
    'reports.php' => ['permission' => 'reports.view'],
    'shift_sales_report.php' => ['permission' => 'reports.view'],
    'stagnant-items-report.php' => ['permission' => 'reports.view'],
    'items_summery.php' => ['permission' => 'reports.view'],
    'calcsalary.php' => ['permission' => 'reports.view'],
    'attandance.php' => ['permission' => 'reports.view'],
    'orders.php' => ['permission' => 'reports.view'],
    'employees.php' => ['permission' => 'reports.view'],
    'departments.php' => ['permission' => 'reports.view'],
    'hr_operations.php' => ['permission' => 'reports.view'],
    'add_shift.php' => ['permission' => 'reports.view'],
    'news.php' => ['permission' => 'reports.view'],
    'add_news.php' => ['permission' => 'reports.view'],
    'booking.php' => ['permission' => 'reports.view'],
    'add_reservation.php' => ['permission' => 'reports.view'],
    'edit_reservation.php' => ['permission' => 'reports.view'],
    'customer_visits.php' => ['permission' => 'reports.view'],
    'add_customer_visit.php' => ['permission' => 'reports.view'],
    'pulse.php' => ['permission' => 'reports.view'],
    'pulse_stats.php' => ['permission' => 'reports.view'],

    // POS admin
    'pos_customers.php' => ['permission' => 'customers.manage', 'admin_or' => true],
    'closed_sessions.php' => ['permission' => 'pos.shift.close'],
    'cash_flow_report.php' => ['permission' => 'reports.cash_flow'],
    'z_report.php' => ['permission' => 'pos.shift.close'],

    // delivery / moova / kds
    'moova_integration.php' => ['permission' => 'moova.manage'],
    'delivery_board.php' => ['permission' => 'delivery.dispatch'],
    'delivery_zones.php' => ['permission' => 'delivery.zones.manage'],
    'kds.php' => ['permission' => 'kds.view'],
    'kds_settings.php' => ['permission' => 'kds.manage', 'admin_or' => true],
    'kds_station.php' => ['permission' => 'kds.manage', 'admin_or' => true],
];
