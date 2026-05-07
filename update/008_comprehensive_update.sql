-- Add Credit (Ajal) Columns to ot_head table
-- This script combines updates for jal_name, jal_notes, and jal_amount variables
-- Run this on your hosting database

-- 1. Add jal_name and jal_notes
ALTER TABLE `ot_head` 
ADD COLUMN `jal_name` VARCHAR(255) DEFAULT NULL,
ADD COLUMN `jal_notes` TEXT DEFAULT NULL;

-- 2. Add jal_amount
ALTER TABLE `ot_head` 
ADD COLUMN `jal_amount` DECIMAL(10, 2) DEFAULT 0.00;
