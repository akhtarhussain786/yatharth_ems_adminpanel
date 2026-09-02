-- EAMS v2.0 Migration for ems_db schema
-- Does NOT modify existing tables, only adds new ones

USE ems_db;

-- =============================================
-- New Roles for Department Admins
-- roles table has: id, name, description, created_at, updated_at
-- =============================================
INSERT IGNORE INTO roles (name, description) VALUES
('hr_admin', 'HR Admin - manages HR operations'),
('digital_marketing_admin', 'Digital Marketing Admin - manages marketing campaigns & leads'),
('telecaller_admin', 'Telecaller Admin - manages call center operations'),
('accounts_admin', 'Accounts Admin - manages salary & accounting'),
('sales_admin', 'Sales Admin - manages sales & leads');

-- =============================================
-- Create permissions table (if not exists)
-- =============================================
CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    module VARCHAR(100) NOT NULL,
    can_view TINYINT(1) DEFAULT 0,
    can_create TINYINT(1) DEFAULT 0,
    can_edit TINYINT(1) DEFAULT 0,
    can_delete TINYINT(1) DEFAULT 0,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    UNIQUE KEY unique_role_module (role_id, module)
) ENGINE=InnoDB;

-- =============================================
-- Seed Permissions
-- Module list: employees, attendance, departments, designations, leaves,
--   payroll, reports, settings, daily_work_reports, tasks, leads,
--   campaigns, call_reports, follow_ups, hr_activities, notices, documents
-- =============================================
DELETE FROM permissions WHERE 1=1;

-- Super Admin (role_id=1) – full access to everything
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete)
SELECT 1, m.module, 1, 1, 1, 1
FROM (
  SELECT 'employees' AS module UNION SELECT 'attendance' UNION SELECT 'departments'
  UNION SELECT 'designations' UNION SELECT 'leaves' UNION SELECT 'payroll'
  UNION SELECT 'reports' UNION SELECT 'settings' UNION SELECT 'daily_work_reports'
  UNION SELECT 'tasks' UNION SELECT 'leads' UNION SELECT 'campaigns'
  UNION SELECT 'call_reports' UNION SELECT 'follow_ups' UNION SELECT 'hr_activities'
  UNION SELECT 'notices' UNION SELECT 'documents' UNION SELECT 'activity_logs'
) m;

-- Admin (role_id=2) – General admin access
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(2, 'employees', 1, 1, 1, 1),
(2, 'attendance', 1, 1, 1, 1),
(2, 'departments', 1, 1, 1, 1),
(2, 'designations', 1, 1, 1, 1),
(2, 'leaves', 1, 1, 1, 1),
(2, 'payroll', 1, 1, 1, 1),
(2, 'reports', 1, 0, 0, 0),
(2, 'settings', 1, 1, 1, 1),
(2, 'daily_work_reports', 1, 0, 0, 0),
(2, 'tasks', 1, 1, 1, 1),
(2, 'notices', 1, 1, 1, 1),
(2, 'documents', 1, 0, 0, 0);

-- Sub Admin (role_id=3) – HR operations
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(3, 'employees', 1, 1, 1, 1),
(3, 'attendance', 1, 1, 1, 1),
(3, 'leaves', 1, 1, 1, 1),
(3, 'departments', 1, 0, 0, 0),
(3, 'designations', 1, 0, 0, 0),
(3, 'reports', 1, 0, 0, 0),
(3, 'daily_work_reports', 1, 0, 0, 0),
(3, 'tasks', 1, 1, 1, 1),
(3, 'hr_activities', 1, 1, 1, 1);

-- Manager (role_id=4)
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(4, 'employees', 1, 0, 0, 0),
(4, 'attendance', 1, 0, 0, 0),
(4, 'tasks', 1, 1, 1, 1),
(4, 'reports', 1, 0, 0, 0),
(4, 'daily_work_reports', 1, 0, 0, 0);

-- HR Admin (role_id=6) – New department admin roles start at 6
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(6, 'employees', 1, 1, 1, 1),
(6, 'attendance', 1, 1, 1, 1),
(6, 'leaves', 1, 1, 1, 1),
(6, 'departments', 1, 0, 0, 0),
(6, 'designations', 1, 0, 0, 0),
(6, 'reports', 1, 0, 0, 0),
(6, 'daily_work_reports', 1, 0, 0, 0),
(6, 'hr_activities', 1, 1, 1, 1),
(6, 'notices', 1, 1, 1, 0);

-- Digital Marketing Admin (role_id=7)
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(7, 'employees', 1, 0, 0, 0),
(7, 'attendance', 1, 0, 0, 0),
(7, 'leads', 1, 1, 1, 1),
(7, 'campaigns', 1, 1, 1, 1),
(7, 'reports', 1, 0, 0, 0),
(7, 'daily_work_reports', 1, 0, 0, 0);

-- Telecaller Admin (role_id=8)
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(8, 'employees', 1, 0, 0, 0),
(8, 'attendance', 1, 0, 0, 0),
(8, 'call_reports', 1, 1, 1, 1),
(8, 'follow_ups', 1, 1, 1, 1),
(8, 'reports', 1, 0, 0, 0),
(8, 'daily_work_reports', 1, 0, 0, 0);

-- Accounts Admin (role_id=9)
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(9, 'employees', 1, 0, 0, 0),
(9, 'attendance', 1, 0, 0, 0),
(9, 'payroll', 1, 1, 1, 0),
(9, 'reports', 1, 0, 0, 0);

-- Sales Admin (role_id=10)
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(10, 'employees', 1, 0, 0, 0),
(10, 'attendance', 1, 0, 0, 0),
(10, 'leads', 1, 1, 1, 1),
(10, 'follow_ups', 1, 1, 1, 1),
(10, 'reports', 1, 0, 0, 0),
(10, 'daily_work_reports', 1, 0, 0, 0);

-- Employee (role_id=5) – basic access
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(5, 'attendance', 1, 0, 0, 0),
(5, 'tasks', 1, 0, 0, 0),
(5, 'daily_work_reports', 1, 1, 0, 0);

-- =============================================
-- 19. daily_work_reports – Common table for all departments
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
-- 20. leads – For Digital Marketing / Sales
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
-- 21. campaigns – For Digital Marketing
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
-- 22. call_reports – For Telecaller department
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
-- 23. follow_ups – For Telecaller / Sales
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
-- 24. hr_activities – For HR department
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
