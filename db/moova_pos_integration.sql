-- Moova first-party POS integration tables.
-- The PHP endpoint also runs CREATE TABLE IF NOT EXISTS, but keep this file for explicit installs/backups.

CREATE TABLE IF NOT EXISTS `moova_pos_shop_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `moova_shop_id` varchar(128) DEFAULT NULL,
  `moova_branch_id` varchar(128) NOT NULL,
  `moova_device_token` varchar(191) DEFAULT NULL,
  `moova_device_token_hash` char(64) NOT NULL,
  `moova_device_token_last4` varchar(16) DEFAULT NULL,
  `pos_tenant` int(11) NOT NULL DEFAULT 0,
  `pos_branch` int(11) NOT NULL DEFAULT 0,
  `widget_url` varchar(255) NOT NULL DEFAULT 'https://withmoova.com/pos-widget',
  `locale` varchar(16) NOT NULL DEFAULT 'ar',
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_moova_token_branch_status` (`moova_device_token_hash`, `moova_branch_id`, `status`),
  UNIQUE KEY `uq_pos_scope_status` (`pos_tenant`, `pos_branch`, `status`),
  KEY `idx_moova_pos_scope_status` (`pos_tenant`, `pos_branch`, `status`),
  KEY `idx_moova_token_status` (`moova_device_token_hash`, `status`),
  KEY `idx_moova_branch_status` (`moova_branch_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `moova_pos_table_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `moova_branch_id` varchar(128) NOT NULL,
  `moova_table_id` varchar(128) NOT NULL,
  `pos_tenant` int(11) NOT NULL DEFAULT 0,
  `pos_branch` int(11) NOT NULL DEFAULT 0,
  `pos_table_id` int(11) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_moova_table_scope` (`moova_branch_id`, `moova_table_id`, `pos_tenant`, `pos_branch`),
  KEY `idx_pos_table_scope` (`pos_tenant`, `pos_branch`, `pos_table_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `moova_pos_order_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `idempotency_key` varchar(191) NOT NULL,
  `request_hash` char(64) NOT NULL,
  `moova_order_id` varchar(191) DEFAULT NULL,
  `moova_branch_id` varchar(128) NOT NULL,
  `pos_tenant` int(11) NOT NULL DEFAULT 0,
  `pos_branch` int(11) NOT NULL DEFAULT 0,
  `pos_order_id` int(11) DEFAULT NULL,
  `provider_status` varchar(32) NOT NULL DEFAULT 'processing',
  `last_pos_state_hash` char(64) DEFAULT NULL,
  `last_pos_state_payload` longtext,
  `request_payload` longtext,
  `response_payload` longtext,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_moova_idempotency_scope` (`pos_tenant`, `pos_branch`, `idempotency_key`),
  KEY `idx_moova_order_pos_order` (`pos_order_id`),
  KEY `idx_moova_order_branch` (`moova_branch_id`, `pos_tenant`, `pos_branch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `moova_pos_order_change_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `idempotency_key` varchar(191) NOT NULL,
  `request_hash` char(64) NOT NULL,
  `moova_order_id` varchar(191) NOT NULL,
  `moova_request_event_id` varchar(191) DEFAULT NULL,
  `change_type` varchar(20) NOT NULL,
  `moova_branch_id` varchar(128) NOT NULL,
  `pos_tenant` int(11) NOT NULL DEFAULT 0,
  `pos_branch` int(11) NOT NULL DEFAULT 0,
  `pos_order_id` int(11) DEFAULT NULL,
  `provider_status` varchar(32) NOT NULL DEFAULT 'processing',
  `request_payload` longtext,
  `response_payload` longtext,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_moova_change_idempotency_scope` (`pos_tenant`, `pos_branch`, `idempotency_key`),
  KEY `idx_moova_change_order_scope` (`moova_order_id`, `pos_tenant`, `pos_branch`),
  KEY `idx_moova_change_pos_order` (`pos_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `moova_pos_order_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `moova_order_id` varchar(191) NOT NULL,
  `pos_order_id` int(11) NOT NULL,
  `fat_detail_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `qty_out` double NOT NULL DEFAULT 0,
  `price` double NOT NULL DEFAULT 0,
  `discount` double NOT NULL DEFAULT 0,
  `det_value` double NOT NULL DEFAULT 0,
  `line_hash` char(64) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `pos_tenant` int(11) NOT NULL DEFAULT 0,
  `pos_branch` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_moova_line_order_scope` (`moova_order_id`, `pos_tenant`, `pos_branch`, `status`),
  KEY `idx_moova_line_pos_order` (`pos_order_id`, `status`),
  KEY `idx_moova_line_detail` (`fat_detail_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Example binding:
-- INSERT INTO moova_pos_shop_links (
--   moova_branch_id,
--   moova_device_token,
--   moova_device_token_hash,
--   moova_device_token_last4,
--   pos_tenant,
--   pos_branch
-- ) VALUES (
--   '',
--   'MOOVA_DEVICE_TOKEN',
--   SHA2('MOOVA_DEVICE_TOKEN', 256),
--   RIGHT('MOOVA_DEVICE_TOKEN', 4),
--   0,
--   0
-- );
