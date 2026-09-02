-- RBAC Enhancement Migration: Adds missing roles, user_permissions table
-- Updates permissions for all roles with proper module access

USE ems_db;

-- =============================================
-- Add new roles for complete CRM workflow
-- =============================================
INSERT IGNORE INTO roles (name, description) VALUES
('hr', 'HR - manages employee records and leaves'),
('digital_marketing', 'Digital Marketing - creates and manages leads'),
('telecaller', 'Telecaller - handles calls and follow-ups on assigned leads'),
('sales_executive', 'Sales Executive - converts qualified leads to customers'),
('sales_manager', 'Sales Manager - oversees sales team and approves deals'),
('accounts', 'Accounts - manages payments and payroll'),
('project_manager', 'Project Manager - manages projects after sale');

-- =============================================
-- Create user_permissions table for per-user overrides
-- =============================================
CREATE TABLE IF NOT EXISTS user_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    module VARCHAR(100) NOT NULL,
    can_view TINYINT(1) DEFAULT 0,
    can_create TINYINT(1) DEFAULT 0,
    can_edit TINYINT(1) DEFAULT 0,
    can_delete TINYINT(1) DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_module (user_id, module)
) ENGINE=InnoDB;

-- =============================================
-- Seed permissions for all roles (idempotent)
-- =============================================

-- Helper: Delete old flat permissions for roles that will be re-seeded
DELETE FROM permissions WHERE 1=1;

-- Super Admin (1) – full access to everything
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete)
SELECT 1, m.module, 1, 1, 1, 1
FROM (
  SELECT 'employees' AS module UNION SELECT 'attendance' UNION SELECT 'departments'
  UNION SELECT 'designations' UNION SELECT 'leaves' UNION SELECT 'payroll'
  UNION SELECT 'reports' UNION SELECT 'settings' UNION SELECT 'daily_work_reports'
  UNION SELECT 'tasks' UNION SELECT 'leads' UNION SELECT 'campaigns'
  UNION SELECT 'call_reports' UNION SELECT 'follow_ups' UNION SELECT 'hr_activities'
  UNION SELECT 'notices' UNION SELECT 'documents' UNION SELECT 'activity_logs'
  UNION SELECT 'roles' UNION SELECT 'permissions' UNION SELECT 'users'
  UNION SELECT 'marketing' UNION SELECT 'telecaller' UNION SELECT 'sales'
  UNION SELECT 'travel' UNION SELECT 'expenses' UNION SELECT 'assets'
  UNION SELECT 'meetings' UNION SELECT 'help' UNION SELECT 'downloads'
  UNION SELECT 'notifications'
) m;

-- Admin (2) – General admin access (no super_admin privileges)
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete)
SELECT 2, m.module, 1, 1, 1, 1
FROM (
  SELECT 'employees' AS module UNION SELECT 'attendance' UNION SELECT 'departments'
  UNION SELECT 'designations' UNION SELECT 'leaves' UNION SELECT 'payroll'
  UNION SELECT 'reports' UNION SELECT 'settings' UNION SELECT 'daily_work_reports'
  UNION SELECT 'tasks' UNION SELECT 'leads' UNION SELECT 'campaigns'
  UNION SELECT 'call_reports' UNION SELECT 'follow_ups' UNION SELECT 'hr_activities'
  UNION SELECT 'notices' UNION SELECT 'documents' UNION SELECT 'activity_logs'
  UNION SELECT 'roles' UNION SELECT 'permissions' UNION SELECT 'users'
  UNION SELECT 'travel' UNION SELECT 'expenses' UNION SELECT 'assets'
  UNION SELECT 'meetings' UNION SELECT 'notifications'
) m;

-- Sub Admin (3) – HR operations
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(3, 'employees', 1, 1, 1, 1),
(3, 'attendance', 1, 1, 1, 1),
(3, 'leaves', 1, 1, 1, 1),
(3, 'departments', 1, 0, 0, 0),
(3, 'designations', 1, 0, 0, 0),
(3, 'reports', 1, 0, 0, 0),
(3, 'daily_work_reports', 1, 0, 0, 0),
(3, 'tasks', 1, 1, 1, 1),
(3, 'hr_activities', 1, 1, 1, 1),
(3, 'notices', 1, 1, 0, 0),
(3, 'notifications', 1, 1, 0, 0);

-- Manager (4) – Team management
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(4, 'employees', 1, 0, 0, 0),
(4, 'attendance', 1, 0, 0, 0),
(4, 'tasks', 1, 1, 1, 1),
(4, 'reports', 1, 0, 0, 0),
(4, 'daily_work_reports', 1, 0, 0, 0),
(4, 'leads', 1, 1, 0, 0),
(4, 'follow_ups', 1, 1, 0, 0);

-- Employee (5) – Basic access
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(5, 'attendance', 1, 0, 0, 0),
(5, 'tasks', 1, 0, 0, 0),
(5, 'daily_work_reports', 1, 1, 0, 0),
(5, 'leaves', 1, 1, 0, 0),
(5, 'notices', 1, 0, 0, 0),
(5, 'notifications', 1, 0, 0, 0);

-- HR Admin (6) – Full HR operations
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(6, 'employees', 1, 1, 1, 1),
(6, 'attendance', 1, 1, 1, 1),
(6, 'leaves', 1, 1, 1, 1),
(6, 'departments', 1, 0, 0, 0),
(6, 'designations', 1, 0, 0, 0),
(6, 'reports', 1, 0, 0, 0),
(6, 'daily_work_reports', 1, 0, 0, 0),
(6, 'hr_activities', 1, 1, 1, 1),
(6, 'notices', 1, 1, 1, 0),
(6, 'notifications', 1, 1, 1, 0);

-- Digital Marketing Admin (7)
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(7, 'employees', 1, 0, 0, 0),
(7, 'attendance', 1, 0, 0, 0),
(7, 'leads', 1, 1, 1, 1),
(7, 'campaigns', 1, 1, 1, 1),
(7, 'reports', 1, 0, 0, 0),
(7, 'daily_work_reports', 1, 0, 0, 0),
(7, 'marketing', 1, 1, 1, 1),
(7, 'notices', 1, 0, 0, 0);

-- Telecaller Admin (8)
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(8, 'employees', 1, 0, 0, 0),
(8, 'attendance', 1, 0, 0, 0),
(8, 'call_reports', 1, 1, 1, 1),
(8, 'follow_ups', 1, 1, 1, 1),
(8, 'reports', 1, 0, 0, 0),
(8, 'daily_work_reports', 1, 0, 0, 0),
(8, 'telecaller', 1, 1, 1, 1);

-- Accounts Admin (9)
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(9, 'employees', 1, 0, 0, 0),
(9, 'attendance', 1, 0, 0, 0),
(9, 'payroll', 1, 1, 1, 0),
(9, 'reports', 1, 0, 0, 0),
(9, 'accounts', 1, 1, 1, 1),
(9, 'expenses', 1, 1, 0, 0);

-- Sales Admin (10)
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(10, 'employees', 1, 0, 0, 0),
(10, 'attendance', 1, 0, 0, 0),
(10, 'leads', 1, 1, 1, 1),
(10, 'follow_ups', 1, 1, 1, 1),
(10, 'reports', 1, 0, 0, 0),
(10, 'daily_work_reports', 1, 0, 0, 0),
(10, 'sales', 1, 1, 1, 1);

-- HR (11) – new clean role
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(11, 'employees', 1, 1, 1, 1),
(11, 'attendance', 1, 1, 0, 0),
(11, 'leaves', 1, 1, 1, 1),
(11, 'hr_activities', 1, 1, 1, 1),
(11, 'notices', 1, 1, 0, 0),
(11, 'reports', 1, 0, 0, 0),
(11, 'daily_work_reports', 1, 0, 0, 0);

-- Digital Marketing (12) – clean role
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(12, 'attendance', 1, 0, 0, 0),
(12, 'leads', 1, 1, 1, 1),
(12, 'campaigns', 1, 1, 1, 1),
(12, 'marketing', 1, 1, 1, 1),
(12, 'daily_work_reports', 1, 1, 0, 0),
(12, 'notices', 1, 0, 0, 0);

-- Telecaller (13) – clean role
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(13, 'attendance', 1, 0, 0, 0),
(13, 'leads', 1, 1, 0, 0),
(13, 'call_reports', 1, 1, 1, 1),
(13, 'follow_ups', 1, 1, 1, 1),
(13, 'telecaller', 1, 1, 1, 1),
(13, 'daily_work_reports', 1, 1, 0, 0),
(13, 'notices', 1, 0, 0, 0);

-- Sales Executive (14) – clean role
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(14, 'attendance', 1, 0, 0, 0),
(14, 'leads', 1, 1, 1, 0),
(14, 'follow_ups', 1, 1, 1, 0),
(14, 'sales', 1, 1, 1, 1),
(14, 'daily_work_reports', 1, 1, 0, 0),
(14, 'notices', 1, 0, 0, 0);

-- Sales Manager (15) – clean role
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(15, 'employees', 1, 0, 0, 0),
(15, 'attendance', 1, 0, 0, 0),
(15, 'leads', 1, 1, 1, 1),
(15, 'follow_ups', 1, 1, 1, 1),
(15, 'sales', 1, 1, 1, 1),
(15, 'reports', 1, 0, 0, 0),
(15, 'daily_work_reports', 1, 0, 0, 0);

-- Accounts (16) – clean role
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(16, 'attendance', 1, 0, 0, 0),
(16, 'payroll', 1, 1, 1, 0),
(16, 'accounts', 1, 1, 1, 1),
(16, 'expenses', 1, 1, 0, 0),
(16, 'reports', 1, 0, 0, 0),
(16, 'notices', 1, 0, 0, 0);

-- Project Manager (17) – clean role
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(17, 'employees', 1, 0, 0, 0),
(17, 'attendance', 1, 0, 0, 0),
(17, 'leads', 1, 1, 0, 0),
(17, 'tasks', 1, 1, 1, 1),
(17, 'daily_work_reports', 1, 0, 0, 0),
(17, 'reports', 1, 0, 0, 0),
(17, 'notices', 1, 0, 0, 0);

-- Update description of existing admin user role to 'admin'
UPDATE roles SET description = 'Admin - full system access' WHERE name = 'admin';

-- =============================================
-- Create CRM tables: payments and projects
-- =============================================
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_mode ENUM('cash','cheque','bank_transfer','online','other') DEFAULT 'cash',
    transaction_id VARCHAR(100) DEFAULT NULL,
    payment_date DATE DEFAULT NULL,
    status ENUM('pending','completed','failed','refunded') DEFAULT 'completed',
    notes TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payment_lead (lead_id),
    INDEX idx_payment_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT DEFAULT NULL,
    project_name VARCHAR(255) NOT NULL,
    project_code VARCHAR(50) NOT NULL UNIQUE,
    scope_of_work TEXT DEFAULT NULL,
    start_date DATE DEFAULT NULL,
    expected_end_date DATE DEFAULT NULL,
    actual_end_date DATE DEFAULT NULL,
    budget DECIMAL(12,2) DEFAULT NULL,
    status ENUM('pending','in_progress','completed','cancelled') DEFAULT 'pending',
    created_by INT DEFAULT NULL,
    assigned_to INT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_project_lead (lead_id),
    INDEX idx_project_assigned (assigned_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
