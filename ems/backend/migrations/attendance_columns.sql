-- Fix attendance table: add missing columns required by check-in/check-out code
-- Run this in phpMyAdmin or via MySQL CLI

ALTER TABLE `attendance`
  ADD COLUMN IF NOT EXISTS `check_in_photo` VARCHAR(500) DEFAULT NULL AFTER `check_in`,
  ADD COLUMN IF NOT EXISTS `check_out_photo` VARCHAR(500) DEFAULT NULL AFTER `check_out`,
  ADD COLUMN IF NOT EXISTS `latitude` DECIMAL(10,8) DEFAULT NULL AFTER `check_out_photo`,
  ADD COLUMN IF NOT EXISTS `longitude` DECIMAL(11,8) DEFAULT NULL AFTER `latitude`,
  ADD COLUMN IF NOT EXISTS `address` TEXT DEFAULT NULL AFTER `longitude`,
  ADD COLUMN IF NOT EXISTS `distance` DECIMAL(10,2) DEFAULT NULL AFTER `address`,
  ADD COLUMN IF NOT EXISTS `late_minutes` INT DEFAULT 0 AFTER `distance`,
  ADD COLUMN IF NOT EXISTS `status` VARCHAR(20) DEFAULT 'present' AFTER `late_minutes`,
  ADD COLUMN IF NOT EXISTS `working_hours` VARCHAR(10) DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `overtime` VARCHAR(10) DEFAULT NULL AFTER `working_hours`,
  ADD COLUMN IF NOT EXISTS `remarks` TEXT DEFAULT NULL AFTER `overtime`,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `remarks`;

-- Fix for MySQL versions that don't support ADD COLUMN IF NOT EXISTS:
-- Uncomment and run these lines if the above fails:
-- 
-- SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS 
--   WHERE TABLE_SCHEMA = 'yatharth_ems_db' AND TABLE_NAME = 'attendance' 
--   AND COLUMN_NAME = 'latitude');
-- SET @sql = IF(@exist = 0, 
--   'ALTER TABLE attendance ADD COLUMN latitude DECIMAL(10,8) DEFAULT NULL',
--   'SELECT \"Column latitude already exists\"');
-- PREPARE stmt FROM @sql;
-- EXECUTE stmt;
