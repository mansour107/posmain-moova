<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

if (!function_exists('tableOrderTestCreateSchema')) {
    function tableOrderTestCreateSchema(mysqli $conn): void
    {
        $conn->query("
            CREATE TABLE settings (
                id INT NOT NULL PRIMARY KEY,
                def_pos_client INT NULL,
                def_pos_store INT NULL,
                def_pos_employee INT NULL,
                def_pos_fund INT NULL,
                isdeleted TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $conn->query("
            CREATE TABLE acc_head (
                id INT NOT NULL PRIMARY KEY,
                code VARCHAR(40) NULL,
                aname VARCHAR(255) NULL,
                parent_id INT NULL,
                is_basic TINYINT(1) NOT NULL DEFAULT 0,
                is_stock TINYINT(1) NOT NULL DEFAULT 0,
                is_fund TINYINT(1) NOT NULL DEFAULT 0,
                isdeleted TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $conn->query("
            CREATE TABLE myitems (
                id INT NOT NULL PRIMARY KEY,
                iname VARCHAR(255) NULL,
                item_type ENUM('sellable','ingredient','packaging','service') NOT NULL DEFAULT 'sellable',
                track_stock TINYINT(1) NOT NULL DEFAULT 1,
                price1 DECIMAL(15,4) NOT NULL DEFAULT 0,
                cost_price DECIMAL(15,4) NOT NULL DEFAULT 0,
                itmqty DECIMAL(15,4) NOT NULL DEFAULT 0,
                isdeleted TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $conn->query("
            CREATE TABLE item_units (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                item_id INT NOT NULL,
                unit_id INT NOT NULL DEFAULT 1,
                u_val DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
                price1 DECIMAL(15,4) NOT NULL DEFAULT 0,
                isdeleted TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $conn->query("
            CREATE TABLE tables (
                id INT NOT NULL PRIMARY KEY,
                tname VARCHAR(255) NULL,
                table_case INT NOT NULL DEFAULT 0,
                isdeleted TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $conn->query("
            CREATE TABLE document_counters (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                pos_tenant INT NOT NULL DEFAULT 0,
                pos_branch INT NOT NULL DEFAULT 0,
                counter_type VARCHAR(50) NOT NULL,
                counter_key VARCHAR(100) NOT NULL,
                current_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                UNIQUE KEY uq_document_counter_scope (pos_tenant, pos_branch, counter_type, counter_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $conn->query("
            CREATE TABLE ot_head (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                pro_id INT NULL,
                pro_tybe INT NULL,
                is_journal INT NULL,
                journal_tybe INT NULL,
                pro_date DATE NULL,
                accural_date DATE NULL,
                store_id INT NULL,
                emp_id INT NULL,
                emp2_id INT NULL,
                acc1 INT NULL,
                acc2 INT NULL,
                fat_total DECIMAL(15,4) NOT NULL DEFAULT 0,
                fat_disc DECIMAL(15,4) NOT NULL DEFAULT 0,
                fat_net DECIMAL(15,4) NOT NULL DEFAULT 0,
                pro_value DECIMAL(15,4) NOT NULL DEFAULT 0,
                cost_center INT NULL,
                profit DECIMAL(15,4) NOT NULL DEFAULT 0,
                paid_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
                remaining_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
                table_id INT NULL,
                order_type VARCHAR(40) NULL,
                payment_status VARCHAR(40) NULL,
                invoice_status VARCHAR(40) NULL,
                order_status VARCHAR(40) NULL,
                waiter_id INT NULL,
                payment_method VARCHAR(50) NULL,
                payment_notes TEXT NULL,
                payment_date DATETIME NULL,
                op2 INT NULL,
                info TEXT NULL,
                user INT NULL,
                crtime DATETIME NULL,
                completed_at DATETIME NULL,
                isdeleted TINYINT(1) NOT NULL DEFAULT 0,
                tenant INT NULL DEFAULT 0,
                branch INT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $conn->query("
            CREATE TABLE fat_details (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                pro_tybe INT NULL,
                pro_id INT NULL,
                item_id INT NULL,
                u_val DECIMAL(15,4) NOT NULL DEFAULT 1,
                qty_in DECIMAL(15,4) NOT NULL DEFAULT 0,
                qty_out DECIMAL(15,4) NOT NULL DEFAULT 0,
                price DECIMAL(15,4) NOT NULL DEFAULT 0,
                discount DECIMAL(15,4) NOT NULL DEFAULT 0,
                det_value DECIMAL(15,4) NOT NULL DEFAULT 0,
                fatid INT NOT NULL,
                fat_tybe INT NULL,
                det_store INT NULL,
                cost_price DECIMAL(15,4) NOT NULL DEFAULT 0,
                profit DECIMAL(15,4) NOT NULL DEFAULT 0,
                isdeleted TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $conn->query("
            CREATE TABLE journal_heads (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                journal_id INT NOT NULL,
                total DECIMAL(15,4) NOT NULL DEFAULT 0,
                jdate DATE NULL,
                details VARCHAR(255) NULL,
                user INT NULL,
                op_id INT NULL,
                op2 INT NULL,
                tenant INT NOT NULL DEFAULT 0,
                branch INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $conn->query("
            CREATE TABLE journal_entries (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                journal_id INT NOT NULL,
                account_id INT NOT NULL,
                debit DECIMAL(15,4) NOT NULL DEFAULT 0,
                credit DECIMAL(15,4) NOT NULL DEFAULT 0,
                tybe INT NOT NULL DEFAULT 0,
                op_id INT NULL,
                op2 INT NULL,
                tenant INT NOT NULL DEFAULT 0,
                branch INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $conn->query("
            CREATE TABLE order_payments (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                order_id INT NOT NULL,
                amount DECIMAL(15,4) NOT NULL DEFAULT 0,
                payment_method VARCHAR(40) NULL,
                reference_no VARCHAR(80) NULL,
                created_by INT NULL,
                created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        (new SyncSchemaManager())->apply($conn);

        $conn->query("
            CREATE TABLE users (
                id INT NOT NULL PRIMARY KEY,
                uname VARCHAR(120) NOT NULL,
                userrole INT NULL,
                permission_mode ENUM('role_only','role_with_overrides') NOT NULL DEFAULT 'role_only',
                isdeleted TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $conn->query("
            CREATE TABLE usr_pwrs (
                id INT NOT NULL PRIMARY KEY,
                rollname VARCHAR(191) NULL,
                add_sales TINYINT(1) NOT NULL DEFAULT 0,
                show_sales TINYINT(1) NOT NULL DEFAULT 0,
                sid_sales TINYINT(1) NOT NULL DEFAULT 0,
                edit_sales TINYINT(1) NOT NULL DEFAULT 0,
                add_payment TINYINT(1) NOT NULL DEFAULT 0,
                show_payment TINYINT(1) NOT NULL DEFAULT 0,
                delete_sales TINYINT(1) NOT NULL DEFAULT 0,
                edit_payment TINYINT(1) NOT NULL DEFAULT 0,
                delete_payment TINYINT(1) NOT NULL DEFAULT 0,
                isdeleted TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }
}
