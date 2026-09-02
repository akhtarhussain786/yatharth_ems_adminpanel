CREATE DATABASE IF NOT EXISTS eams_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE eams_db;

-- =============================================
-- 1. roles
-- =============================================
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =============================================
-- 2. permissions
-- =============================================
CREATE TABLE permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    module VARCHAR(100) NOT NULL,
    can_view TINYINT(1) DEFAULT 0,
    can_create TINYINT(1) DEFAULT 0,
    can_edit TINYINT(1) DEFAULT 0,
    can_delete TINYINT(1) DEFAULT 0,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- 3. users
-- =============================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role_id INT NOT NULL DEFAULT 3,
    status TINYINT(1) DEFAULT 1,
    last_login DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

-- =============================================
-- 4. departments
-- =============================================
CREATE TABLE departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    status TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =============================================
-- 5. designations
-- =============================================
CREATE TABLE designations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    department_id INT,
    description TEXT,
    status TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================
-- 6. employees
-- =============================================
CREATE TABLE employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    employee_code VARCHAR(50) NOT NULL UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100),
    mobile VARCHAR(20),
    email VARCHAR(100),
    department_id INT,
    designation_id INT,
    joining_date DATE,
    salary DECIMAL(12,2) DEFAULT 0.00,
    profile_photo VARCHAR(255),
    address TEXT,
    city VARCHAR(100),
    state VARCHAR(100),
    pincode VARCHAR(20),
    status TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    FOREIGN KEY (designation_id) REFERENCES designations(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================
-- 7. office_locations
-- =============================================
CREATE TABLE office_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    office_name VARCHAR(100) NOT NULL,
    latitude DECIMAL(10,8) NOT NULL,
    longitude DECIMAL(11,8) NOT NULL,
    radius INT DEFAULT 100,
    address TEXT,
    status TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =============================================
-- 8. attendance
-- =============================================
CREATE TABLE attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    check_in DATETIME,
    check_out DATETIME,
    check_in_photo VARCHAR(255),
    check_out_photo VARCHAR(255),
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    address TEXT,
    distance FLOAT,
    late_minutes INT DEFAULT 0,
    working_hours TIME DEFAULT '00:00:00',
    overtime TIME DEFAULT '00:00:00',
    status ENUM('present','late','half-day','absent','leave') DEFAULT 'present',
    check_in_method VARCHAR(50) DEFAULT 'gps',
    check_out_method VARCHAR(50) DEFAULT 'gps',
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_attendance (employee_id, attendance_date),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- 9. attendance_photos
-- =============================================
CREATE TABLE attendance_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attendance_id INT NOT NULL,
    photo_path VARCHAR(255) NOT NULL,
    photo_type ENUM('check_in','check_out') NOT NULL,
    captured_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- 10. salary_rules
-- =============================================
CREATE TABLE salary_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rule_name VARCHAR(100) NOT NULL,
    late_minutes_allowed INT DEFAULT 0,
    late_count_for_half_day INT DEFAULT 3,
    half_day_deduction_percent DECIMAL(5,2) DEFAULT 50.00,
    absent_deduction_percent DECIMAL(5,2) DEFAULT 100.00,
    overtime_rate DECIMAL(10,2) DEFAULT 0.00,
    overtime_period_minutes INT DEFAULT 60,
    status TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =============================================
-- 11. salary_deductions
-- =============================================
CREATE TABLE salary_deductions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    attendance_id INT,
    deduction_type ENUM('late','half-day','absent') NOT NULL,
    deduction_amount DECIMAL(12,2) DEFAULT 0.00,
    deduction_date DATE NOT NULL,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================
-- 12. holidays
-- =============================================
CREATE TABLE holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    holiday_date DATE NOT NULL,
    description TEXT,
    type ENUM('public','company') DEFAULT 'public',
    status TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =============================================
-- 13. leave_requests
-- =============================================
CREATE TABLE leave_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    leave_type ENUM('sick','casual','annual','personal','other') NOT NULL,
    from_date DATE NOT NULL,
    to_date DATE NOT NULL,
    reason TEXT,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    approved_by INT,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================
-- 14. notifications
-- =============================================
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    message TEXT,
    type ENUM('holiday','birthday','announcement','attendance','system') DEFAULT 'announcement',
    send_to ENUM('all','employee','department') DEFAULT 'all',
    department_id INT,
    status TINYINT(1) DEFAULT 1,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================
-- 15. activity_logs
-- =============================================
CREATE TABLE activity_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    module VARCHAR(100),
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================
-- 16. settings (for key-value config)
-- =============================================
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =============================================
-- Insert Default Data
-- =============================================

-- Roles
INSERT INTO roles (id, name, display_name) VALUES
(1, 'super_admin', 'Super Admin'),
(2, 'hr', 'HR Manager'),
(3, 'employee', 'Employee'),
(4, 'hr_admin', 'HR Admin'),
(5, 'digital_marketing_admin', 'Digital Marketing Admin'),
(6, 'telecaller_admin', 'Telecaller Admin'),
(7, 'accounts_admin', 'Accounts Admin'),
(8, 'sales_admin', 'Sales Admin');

-- Default office location
INSERT INTO office_locations (office_name, latitude, longitude, radius, address) VALUES
('Head Office', 22.804566, 86.202875, 100, 'Main Office Address');

-- Default salary rules
INSERT INTO salary_rules (rule_name, late_minutes_allowed, late_count_for_half_day, half_day_deduction_percent, absent_deduction_percent) VALUES
('Default Rules', 0, 3, 50.00, 100.00);

-- Default settings
INSERT INTO settings (setting_key, setting_value) VALUES
('office_start_time', '09:30'),
('late_start_time', '09:31'),
('half_day_time', '10:00'),
('office_end_time', '18:30'),
('attendance_window_start', '09:00'),
('attendance_window_end', '09:30'),
('app_version', '2.0.0');

-- Super Admin user (password: admin123)
INSERT INTO users (id, username, email, password, role_id) VALUES
(1, 'admin', 'admin@eams.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1);

-- =============================================
-- 17. daily_work_reports
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
-- 18. tasks
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
-- 19. leads
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
-- 20. campaigns
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
-- 21. call_reports
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
-- 22. follow_ups
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
-- 23. hr_activities
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

-- Permissions seed data
DELETE FROM permissions WHERE 1=1;
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete)
SELECT 1, m.module, 1, 1, 1, 1 FROM (
  SELECT 'employees' AS module UNION SELECT 'attendance' UNION SELECT 'departments'
  UNION SELECT 'designations' UNION SELECT 'holidays' UNION SELECT 'leave_requests'
  UNION SELECT 'salary' UNION SELECT 'reports' UNION SELECT 'settings'
  UNION SELECT 'daily_work_reports' UNION SELECT 'tasks' UNION SELECT 'leads'
  UNION SELECT 'campaigns' UNION SELECT 'call_reports' UNION SELECT 'follow_ups'
  UNION SELECT 'hr_activities' UNION SELECT 'notifications' UNION SELECT 'activity_logs'
) m;
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES
(4, 'employees', 1, 1, 1, 1), (4, 'attendance', 1, 1, 1, 1), (4, 'leave_requests', 1, 1, 1, 1), (4, 'departments', 1, 0, 0, 0), (4, 'designations', 1, 0, 0, 0), (4, 'holidays', 1, 1, 1, 0), (4, 'reports', 1, 0, 0, 0), (4, 'daily_work_reports', 1, 0, 0, 0), (4, 'hr_activities', 1, 1, 1, 1), (4, 'notifications', 1, 1, 0, 0),
(5, 'employees', 1, 0, 0, 0), (5, 'attendance', 1, 0, 0, 0), (5, 'leads', 1, 1, 1, 1), (5, 'campaigns', 1, 1, 1, 1), (5, 'reports', 1, 0, 0, 0), (5, 'daily_work_reports', 1, 0, 0, 0),
(6, 'employees', 1, 0, 0, 0), (6, 'attendance', 1, 0, 0, 0), (6, 'call_reports', 1, 1, 1, 1), (6, 'follow_ups', 1, 1, 1, 1), (6, 'reports', 1, 0, 0, 0), (6, 'daily_work_reports', 1, 0, 0, 0),
(7, 'employees', 1, 0, 0, 0), (7, 'attendance', 1, 0, 0, 0), (7, 'salary', 1, 1, 1, 0), (7, 'reports', 1, 0, 0, 0),
(8, 'employees', 1, 0, 0, 0), (8, 'attendance', 1, 0, 0, 0), (8, 'leads', 1, 1, 1, 1), (8, 'follow_ups', 1, 1, 1, 1), (8, 'reports', 1, 0, 0, 0), (8, 'daily_work_reports', 1, 0, 0, 0);
