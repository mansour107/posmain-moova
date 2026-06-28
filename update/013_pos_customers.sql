-- POS Customer CRM (phone-first)
-- Applied via SyncSchemaManager; kept for manual/reference installs.

CREATE TABLE IF NOT EXISTS pos_customers (
  id INT NOT NULL AUTO_INCREMENT,
  display_name VARCHAR(160) NOT NULL,
  primary_phone_id INT NULL,
  notes TEXT NULL,
  orders_count INT NOT NULL DEFAULT 0,
  lifetime_paid DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  last_order_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_pos_customers_deleted (isdeleted, updated_at),
  KEY idx_pos_customers_last_order (last_order_at),
  KEY idx_pos_customers_lifetime (lifetime_paid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS pos_customer_phones (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  phone_normalized VARCHAR(32) NOT NULL,
  phone_display VARCHAR(40) NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  label VARCHAR(40) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pos_customer_phone_normalized (phone_normalized),
  KEY idx_pos_customer_phones_customer (customer_id, is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS pos_customer_addresses (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  address_text VARCHAR(500) NOT NULL,
  zone_id INT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_pos_customer_addresses_customer (customer_id, is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
