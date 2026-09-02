-- EAMS v2.0 Migration: Multi-Department RBAC + Extended Modules
-- Does NOT modify existing tables, only adds new ones and seeds data

USE eams_db;

-- =============================================
-- New Roles for Department Admins
-- =============================================
INSERT INTO roles (name, display_name) VALUES
('hr_admin', 'HR Admin'),
('digital_marketing_admin', 'Digital Marketing Admin'),
('telecaller_admin', 'Telecaller Admin'),
('accounts_admin', 'Accounts Admin'),
('sales_admin', 'Sales Admin')
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);

-- =============================================
-- Seed Permissions (activate the permissions table)
-- Module list: employees, attendance, departments, designations, holidays,
--   leave_requests, salary, reports, settings, daily_work_reports,
--   tasks, leads, campaigns, call_reports, follow_ups, hr_activities,
--   notifications, activity_logs
-- =============================================

-- Helper: Delete existing to avoid duplicates on re-run
DELETE FROM permissions WHERE 1=1;

-- Super Admin (role_id=1) – full access to everything
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete)
SELECT 1, m.module, 1, 1, 1, 1
FROM (
  SELECT 'employees' AS module UNION SELECT 'attendance' UNION SELECT 'departments'
  UNION SELECT 'designations' UNION SELECT 'holidays' UNION SELECT 'leave_requests'
  UNION SELECT 'salary' UNION SELECT 'reports' UNION SELECT 'settings'
  UNION SELECT 'daily_work_reports' UNION SELECT 'tasks' UNION SELECT 'leads'
  UNION SELECT 'campaigns' UNION SELECT 'call_reports' UNION SELECT 'follow_ups'
  UNION SELECT 'hr_activities' UNION SELECT 'notifications' UNION SELECT 'activity_logs'
) m;

-- HR Admin (role_id=4) – HR related modules only
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(4, 'employees', 1, 1, 1, 1),
(4, 'attendance', 1, 1, 1, 1),
(4, 'leave_requests', 1, 1, 1, 1),
(4, 'departments', 1, 0, 0, 0),
(4, 'designations', 1, 0, 0, 0),
(4, 'holidays', 1, 1, 1, 0),
(4, 'reports', 1, 0, 0, 0),
(4, 'daily_work_reports', 1, 0, 0, 0),
(4, 'hr_activities', 1, 1, 1, 1),
(4, 'notifications', 1, 1, 0, 0);

-- Digital Marketing Admin (role_id=5)
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(5, 'employees', 1, 0, 0, 0),
(5, 'attendance', 1, 0, 0, 0),
(5, 'leads', 1, 1, 1, 1),
(5, 'campaigns', 1, 1, 1, 1),
(5, 'reports', 1, 0, 0, 0),
(5, 'daily_work_reports', 1, 0, 0, 0);

-- Telecaller Admin (role_id=6)
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(6, 'employees', 1, 0, 0, 0),
(6, 'attendance', 1, 0, 0, 0),
(6, 'call_reports', 1, 1, 1, 1),
(6, 'follow_ups', 1, 1, 1, 1),
(6, 'reports', 1, 0, 0, 0),
(6, 'daily_work_reports', 1, 0, 0, 0);

-- Accounts Admin (role_id=7)
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(7, 'employees', 1, 0, 0, 0),
(7, 'attendance', 1, 0, 0, 0),
(7, 'salary', 1, 1, 1, 0),
(7, 'reports', 1, 0, 0, 0);

-- Sales Admin (role_id=8)
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(8, 'employees', 1, 0, 0, 0),
(8, 'attendance', 1, 0, 0, 0),
(8, 'leads', 1, 1, 1, 1),
(8, 'follow_ups', 1, 1, 1, 1),
(8, 'reports', 1, 0, 0, 0),
(8, 'daily_work_reports', 1, 0, 0, 0);

-- =============================================
-- 17. daily_work_reports – Common table for all departments
-- =============================================
CREATE TABLE IF NOT EXISTS daily_work_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    report_date DATE NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    hours_worked DECIMAL(5,2) DEFAULT 0.00,
    status ENUM('pending','submitted','approved','rejected') DEFAULT 'submitted',
    attachment VARCHAR(255),
    remarks TEXT,
    reviewed_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================
-- 18. tasks – For Development department
-- =============================================
CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
    status ENUM('pending','in_progress','completed','cancelled') DEFAULT 'pending',
    assigned_by INT,
    due_date DATE,
    completed_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================
-- 19. leads – For Digital Marketing / Sales
-- =============================================
CREATE TABLE IF NOT EXISTS leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    customer_name VARCHAR(200) NOT NULL,
    customer_phone VARCHAR(20),
    customer_email VARCHAR(100),
    source VARCHAR(100),
    status ENUM('new','contacted','qualified','proposal','negotiation','won','lost') DEFAULT 'new',
    notes TEXT,
    follow_up_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- 20. campaigns – For Digital Marketing
-- =============================================
CREATE TABLE IF NOT EXISTS campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    campaign_name VARCHAR(200) NOT NULL,
    platform VARCHAR(100),
    budget DECIMAL(12,2) DEFAULT 0.00,
    start_date DATE,
    end_date DATE,
    status ENUM('planning','active','paused','completed','cancelled') DEFAULT 'planning',
    results TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- 21. call_reports – For Telecaller department
-- =============================================
CREATE TABLE IF NOT EXISTS call_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    customer_name VARCHAR(200) NOT NULL,
    customer_phone VARCHAR(20),
    call_duration INT DEFAULT 0 COMMENT 'Duration in seconds',
    call_type ENUM('incoming','outgoing','follow_up') DEFAULT 'outgoing',
    status ENUM('completed','busy','no_answer','callback','not_interested') DEFAULT 'completed',
    notes TEXT,
    follow_up_required TINYINT(1) DEFAULT 0,
    call_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- 22. follow_ups – For Telecaller / Sales
-- =============================================
CREATE TABLE IF NOT EXISTS follow_ups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    customer_name VARCHAR(200) NOT NULL,
    customer_phone VARCHAR(20),
    follow_up_type ENUM('call','meeting','email','other') DEFAULT 'call',
    notes TEXT,
    status ENUM('pending','completed','cancelled') DEFAULT 'pending',
    follow_up_date DATE NOT NULL,
    completed_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- 23. hr_activities – For HR department
-- =============================================
CREATE TABLE IF NOT EXISTS hr_activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    activity_type ENUM('interview','training','onboarding','meeting','review','other') NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    activity_date DATE NOT NULL,
    status ENUM('scheduled','completed','cancelled') DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- Update app version in settings
-- =============================================
INSERT INTO settings (setting_key, setting_value) VALUES
('app_version', '2.0.0')
ON DUPLICATE KEY UPDATE setting_value = '2.0.0';
