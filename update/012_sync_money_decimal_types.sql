-- Align legacy POS money columns with cloud sync DECIMAL(15,4) limits.
-- Cleans existing out-of-range values, then tightens column types so local
-- data matches what hosted cloud_order_* tables can store.
--
-- Applied by Stepwise (update/ ledger). UpdateOrchestrator applies with
-- allowDestructive=true. Manual CLI: php tools/stepwise.php --steps=.../update --apply --allow-destructive

-- ot_head: payment receipt rows use pro_value; order totals use fat_* / profit.
UPDATE `ot_head`
SET `pro_value` = 0
WHERE `pro_value` IS NOT NULL
  AND (`pro_value` > 99999999999.9999 OR `pro_value` < -99999999999.9999);

UPDATE `ot_head`
SET `fat_total` = 0
WHERE `fat_total` IS NOT NULL
  AND (`fat_total` > 99999999999.9999 OR `fat_total` < -99999999999.9999);

UPDATE `ot_head`
SET `fat_net` = 0
WHERE `fat_net` IS NOT NULL
  AND (`fat_net` > 99999999999.9999 OR `fat_net` < -99999999999.9999);

UPDATE `ot_head`
SET `fat_disc` = 0
WHERE `fat_disc` IS NOT NULL
  AND (`fat_disc` > 99999999999.9999 OR `fat_disc` < -99999999999.9999);

UPDATE `ot_head`
SET `profit` = 0
WHERE `profit` IS NOT NULL
  AND (`profit` > 99999999999.9999 OR `profit` < -99999999999.9999);

UPDATE `ot_head`
SET `fat_cost` = 0
WHERE `fat_cost` IS NOT NULL
  AND (`fat_cost` > 99999999999.9999 OR `fat_cost` < -99999999999.9999);

UPDATE `ot_head`
SET `paid_amount` = 0
WHERE `paid_amount` IS NOT NULL
  AND (`paid_amount` > 99999999999.9999 OR `paid_amount` < -99999999999.9999);

UPDATE `ot_head`
SET `remaining_amount` = 0
WHERE `remaining_amount` IS NOT NULL
  AND (`remaining_amount` > 99999999999.9999 OR `remaining_amount` < -99999999999.9999);

UPDATE `order_payments`
SET `amount` = 0
WHERE `amount` > 99999999999.9999 OR `amount` < -99999999999.9999;

ALTER TABLE `ot_head`
  MODIFY COLUMN `pro_value` DECIMAL(15,4) NULL DEFAULT NULL,
  MODIFY COLUMN `fat_cost` DECIMAL(15,4) NULL DEFAULT NULL,
  MODIFY COLUMN `profit` DECIMAL(15,4) NULL DEFAULT NULL,
  MODIFY COLUMN `fat_total` DECIMAL(15,4) NULL DEFAULT NULL,
  MODIFY COLUMN `fat_net` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  MODIFY COLUMN `fat_disc` DECIMAL(15,4) NULL DEFAULT NULL,
  MODIFY COLUMN `paid_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  MODIFY COLUMN `remaining_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000;

ALTER TABLE `order_payments`
  MODIFY COLUMN `amount` DECIMAL(15,4) NOT NULL;
