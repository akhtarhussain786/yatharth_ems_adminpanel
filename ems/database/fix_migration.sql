-- Comprehensive fix migration for yatharth_ems_db
-- Run this on the server to fix all missing tables and columns

USE yatharth_ems_db;

-- =============================================
-- 1. Fix users table: ensure status column exists
-- =============================================
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists FROM information_schema.columns WHERE table_schema = 'yatharth_ems_db' AND table_name = 'users' AND column_name = 'status';
SET @sql = IF(@col_exists = 0, 'ALTER TABLE users ADD COLUMN status TINYINT(1) DEFAULT 1 AFTER password', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================
-- Create leave_requests table (with start_date/end_date matching code)
-- =============================================
CREATE TABLE IF NOT EXISTS leave_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    leave_type VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    total_days INT DEFAULT 1,
    reason TEXT,
    attachment VARCHAR(255),
    status ENUM('Pending','Approved','Rejected','Cancelled') DEFAULT 'Pending',
    approved_by INT,
    approved_at DATETIME,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================
-- Create projects table
-- =============================================
CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT DEFAULT NULL,
    project_name VARCHAR(255) NOT NULL,
    project_code VARCHAR(50) NOT NULL UNIQUE,
    scope_of_work TEXT,
    start_date DATE,
    expected_end_date DATE,
    actual_end_date DATE,
    budget DECIMAL(12,2) DEFAULT NULL,
    status ENUM('pending','in_progress','completed','cancelled') DEFAULT 'pending',
    created_by INT DEFAULT NULL,
    assigned_to INT DEFAULT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_project_lead (lead_id),
    INDEX idx_project_assigned (assigned_to)
) ENGINE=InnoDB;

-- =============================================
-- Create expenses table
-- =============================================
CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    expense_category_id INT,
    amount DECIMAL(12,2) NOT NULL,
    expense_date DATE NOT NULL,
    description TEXT,
    bill_photo VARCHAR(255),
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    approved_by INT,
    approval_date DATETIME,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================
-- Create meetings table
-- =============================================
CREATE TABLE IF NOT EXISTS meetings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    meeting_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME,
    venue VARCHAR(255),
    meeting_link VARCHAR(255),
    created_by INT,
    status ENUM('scheduled','ongoing','completed','cancelled') DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================
-- Add date_of_birth column to employees if missing
-- =============================================
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists FROM information_schema.columns WHERE table_schema = 'yatharth_ems_db' AND table_name = 'employees' AND column_name = 'date_of_birth';
SET @sql = IF(@col_exists = 0, 'ALTER TABLE employees ADD COLUMN date_of_birth DATE AFTER last_name', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================
-- Fix activity_logs id to be AUTO_INCREMENT
-- =============================================
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists FROM information_schema.columns WHERE table_schema = 'yatharth_ems_db' AND table_name = 'activity_logs' AND column_name = 'id' AND extra LIKE '%auto_increment%';
SET @sql = IF(@col_exists = 0, 'ALTER TABLE activity_logs MODIFY COLUMN id BIGINT AUTO_INCREMENT', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;