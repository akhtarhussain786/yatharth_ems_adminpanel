-- Attendance System Fix Migration
-- Run this in phpMyAdmin or MySQL CLI
-- Database: yatharth_ems_db

-- =============================================
-- 1. Add missing columns to attendance table
-- =============================================
SET @db_name = 'yatharth_ems_db';

-- is_holiday
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'is_holiday');
SET @sql := IF(@exist = 0, 'ALTER TABLE attendance ADD COLUMN `is_holiday` TINYINT(1) DEFAULT 0 AFTER `status`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- holiday_title
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'holiday_title');
SET @sql := IF(@exist = 0, 'ALTER TABLE attendance ADD COLUMN `holiday_title` VARCHAR(255) DEFAULT NULL AFTER `is_holiday`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- working_hours_decimal
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'working_hours_decimal');
SET @sql := IF(@exist = 0, 'ALTER TABLE attendance ADD COLUMN `working_hours_decimal` DECIMAL(5,2) DEFAULT 0.00 AFTER `working_hours`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- leave_type column
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'leave_type');
SET @sql := IF(@exist = 0, 'ALTER TABLE attendance ADD COLUMN `leave_type` VARCHAR(20) DEFAULT "present" COMMENT \"present, paid_leave, earned_leave, unpaid_leave, absent, weekly_off, holiday\" AFTER `status`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add indexes
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'attendance' AND INDEX_NAME = 'idx_is_holiday');
SET @sql := IF(@exist = 0, 'ALTER TABLE attendance ADD INDEX `idx_is_holiday` (`is_holiday`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'attendance' AND INDEX_NAME = 'idx_attendance_date');
SET @sql := IF(@exist = 0, 'ALTER TABLE attendance ADD INDEX `idx_attendance_date` (`attendance_date`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'attendance' AND INDEX_NAME = 'idx_leave_type');
SET @sql := IF(@exist = 0, 'ALTER TABLE attendance ADD INDEX `idx_leave_type` (`leave_type`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================
-- 2. Modify holidays type column to accept more types
-- =============================================
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'holidays' AND COLUMN_NAME = 'type' AND DATA_TYPE = 'varchar');
SET @sql := IF(@exist = 0, 'ALTER TABLE holidays MODIFY COLUMN `type` VARCHAR(50) DEFAULT "public"', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================
-- 3. Add columns to users table
-- =============================================
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'users' AND COLUMN_NAME = 'api_token');
SET @sql := IF(@exist = 0, 'ALTER TABLE users ADD COLUMN `api_token` VARCHAR(255) NULL AFTER `password`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'users' AND COLUMN_NAME = 'token_expiry');
SET @sql := IF(@exist = 0, 'ALTER TABLE users ADD COLUMN `token_expiry` DATETIME NULL AFTER `api_token`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_activity');
SET @sql := IF(@exist = 0, 'ALTER TABLE users ADD COLUMN `last_activity` DATETIME NULL AFTER `token_expiry`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_logged_in');
SET @sql := IF(@exist = 0, 'ALTER TABLE users ADD COLUMN `is_logged_in` TINYINT(1) DEFAULT 0 AFTER `last_activity`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================
-- 4. Create user_sessions table
-- =============================================
CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `session_token` varchar(255) NOT NULL,
    `ip_address` varchar(45) DEFAULT NULL,
    `user_agent` text,
    `login_time` datetime DEFAULT CURRENT_TIMESTAMP,
    `last_activity` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `expiry_time` datetime DEFAULT NULL,
    `is_active` tinyint(1) DEFAULT 1,
    `logout_time` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`),
    KEY `session_token` (`session_token`),
    KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
