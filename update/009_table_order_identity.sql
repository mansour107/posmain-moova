-- POSMAIN table/order identity repair.
-- Run after taking a full database backup. This script keeps old rows intact,
-- adds structured lifecycle fields used by the table-order code, and creates
-- a review table for ambiguous legacy table-name matches.

CREATE TABLE IF NOT EXISTS backup_ot_head_before_table_fix AS
SELECT * FROM ot_head;

CREATE TABLE IF NOT EXISTS backup_fat_details_before_table_fix AS
SELECT * FROM fat_details;

ALTER TABLE ot_head
    MODIFY COLUMN payment_status ENUM('unpaid','partial','paid','refunded','voided') DEFAULT 'unpaid',
    ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS cancelled_by INT NULL,
    ADD COLUMN IF NOT EXISTS cancellation_reason VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS created_by INT NULL,
    ADD COLUMN IF NOT EXISTS updated_by INT NULL,
    ADD COLUMN IF NOT EXISTS parent_order_id INT NULL,
    ADD COLUMN IF NOT EXISTS split_group_id VARCHAR(64) NULL;

CREATE TABLE IF NOT EXISTS order_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method VARCHAR(50) NULL,
    reference_no VARCHAR(100) NULL,
    paid_by_customer_id INT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_voided TINYINT(1) NOT NULL DEFAULT 0,
    voided_at DATETIME NULL,
    voided_by INT NULL,
    void_reason VARCHAR(255) NULL,
    INDEX idx_order_payments_order_id (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS table_order_migration_review (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    info VARCHAR(255) NULL,
    reason VARCHAR(100) NOT NULL,
    candidate_table_name VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_table_order_review_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE INDEX IF NOT EXISTS idx_ot_head_active_table_order
ON ot_head (table_id, pro_tybe, isdeleted, order_status, payment_status);

CREATE INDEX IF NOT EXISTS idx_ot_head_order_type
ON ot_head (order_type, pro_tybe, isdeleted, payment_status, order_status);

CREATE INDEX IF NOT EXISTS idx_fat_details_fatid
ON fat_details (fatid, isdeleted);

-- Backfill only exact, unambiguous legacy table labels. Ambiguous rows are
-- copied to table_order_migration_review and left unchanged for manual review.
UPDATE ot_head oh
INNER JOIN tables t
        ON oh.info LIKE CONCAT('%طاولة: ', t.tname, '%')
        OR oh.info LIKE CONCAT('%طاولة ', t.tname, '%')
        OR oh.info LIKE CONCAT('%Table: ', t.tname, '%')
        OR oh.info LIKE CONCAT('%Table ', t.tname, '%')
SET oh.table_id = t.id,
    oh.order_type = 'table'
WHERE (oh.table_id IS NULL OR oh.table_id = 0)
  AND oh.pro_tybe = 9
  AND oh.info IS NOT NULL
  AND t.isdeleted = 0;

UPDATE ot_head
SET payment_status = CASE
        WHEN COALESCE(paid_amount, 0) >= COALESCE(fat_net, 0) AND COALESCE(fat_net, 0) > 0 THEN 'paid'
        ELSE COALESCE(NULLIF(payment_status, ''), 'unpaid')
    END,
    order_status = CASE
        WHEN COALESCE(paid_amount, 0) >= COALESCE(fat_net, 0) AND COALESCE(fat_net, 0) > 0 THEN 'completed'
        ELSE COALESCE(NULLIF(order_status, ''), 'active')
    END,
    invoice_status = CASE
        WHEN COALESCE(paid_amount, 0) >= COALESCE(fat_net, 0) AND COALESCE(fat_net, 0) > 0 THEN 'completed'
        ELSE COALESCE(NULLIF(invoice_status, ''), 'draft')
    END,
    remaining_amount = CASE
        WHEN COALESCE(remaining_amount, 0) > 0 THEN remaining_amount
        ELSE GREATEST(0, COALESCE(fat_net, 0) - COALESCE(paid_amount, 0))
    END
WHERE pro_tybe = 9
  AND isdeleted = 0
  AND table_id IS NOT NULL
  AND table_id <> 0;

INSERT IGNORE INTO table_order_migration_review (order_id, info, reason, candidate_table_name)
SELECT oh.id, oh.info, 'unmatched_table_text', NULL
FROM ot_head oh
WHERE (oh.table_id IS NULL OR oh.table_id = 0)
  AND oh.pro_tybe = 9
  AND oh.info IS NOT NULL
  AND (oh.info LIKE '%طاولة%' OR oh.info LIKE '%Table%');

UPDATE tables
SET table_case = 0;

UPDATE tables t
SET table_case = 1
WHERE EXISTS (
    SELECT 1
    FROM ot_head oh
    WHERE oh.table_id = t.id
      AND oh.pro_tybe = 9
      AND oh.isdeleted = 0
      AND COALESCE(oh.order_status, 'active') = 'active'
      AND COALESCE(oh.payment_status, 'unpaid') IN ('unpaid', 'partial')
);
