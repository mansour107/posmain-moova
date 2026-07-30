<?php

/**
 * Document-root paths that must never be executable in a release artifact.
 * Kept as a machine-readable inventory for packaging gates and HTTP proof.
 *
 * @return list<string>
 */
return [
    'fix_passwords.php',
    'delete_fix_file.php',
    'fix_database.php',
    'fix_journal_entries.php',
    'quick_fix.php',
    'repair_database_jal.php',
    'run_migrations.php',
    'run_payment_updates.php',
    'run_table_updates.php',
    'setup_demo_data.php',
    'pre_start.php',
    'check_db_structure.php',
    'check_orders.php',
    'check_payments.php',
    'check_table_structure.php',
    'debug_bank.php',
    'debug_db.php',
    'debug_delivery.php',
    'debug_jal.php',
    'debug_tasks.php',
    'logs_viewer.php',
    'view_logs.php',
    'crud_tables.php',
    'create_acc_head_table.php',
    'list_columns.php',
    'generate-examples.php',
    'generate-verified-files.php',
    'index2.php',
    'indexmop.php',
    'fat1.php',
    'example.php',
    'blogcontent.php',
    'about.php',
    'conectedmachines.php',
    'machinelog.php',
    'aa',
    'do/dodel_invoice.php',
    'do/dodel_pro.php',
];
