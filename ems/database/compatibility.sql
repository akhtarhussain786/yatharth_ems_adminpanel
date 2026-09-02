-- Add compatibility columns to ems_db so existing code works
USE ems_db;

-- Helper: add column if not exists
DELIMITER //
DROP PROCEDURE IF EXISTS add_col_if_not_exists//
CREATE PROCEDURE add_col_if_not_exists(
    IN p_table VARCHAR(100), IN p_column VARCHAR(100),
    IN p_col_def TEXT)
BEGIN
    DECLARE col_count INT;
    SET @stmt = CONCAT('SELECT COUNT(*) INTO @cnt FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = "', p_table, '" AND column_name = "', p_column, '"');
    PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
    IF @cnt = 0 THEN
        SET @alter_stmt = CONCAT('ALTER TABLE ', p_table, ' ADD COLUMN ', p_column, ' ', p_col_def);
        PREPARE s2 FROM @alter_stmt; EXECUTE s2; DEALLOCATE PREPARE s2;
    END IF;
END//
DELIMITER ;

-- Employees table compatibility columns
CALL add_col_if_not_exists('employees', 'employee_code', 'VARCHAR(50) UNIQUE AFTER user_id');
CALL add_col_if_not_exists('employees', 'mobile', 'VARCHAR(20) AFTER phone');
CALL add_col_if_not_exists('employees', 'profile_photo', 'VARCHAR(255) AFTER profile_image');
CALL add_col_if_not_exists('employees', 'pincode', 'VARCHAR(20) AFTER zip_code');
CALL add_col_if_not_exists('employees', 'emp_city', 'VARCHAR(100) AFTER address');
CALL add_col_if_not_exists('employees', 'emp_state', 'VARCHAR(100) AFTER emp_city');

-- Attendance table compatibility columns
CALL add_col_if_not_exists('attendance', 'attendance_date', 'DATE AFTER date');
CALL add_col_if_not_exists('attendance', 'check_in', 'DATETIME AFTER check_in_time');
CALL add_col_if_not_exists('attendance', 'check_out', 'DATETIME AFTER check_out_time');
CALL add_col_if_not_exists('attendance', 'working_hours', 'TIME DEFAULT \"00:00:00\" AFTER overtime_minutes');
CALL add_col_if_not_exists('attendance', 'overtime', 'TIME DEFAULT \"00:00:00\" AFTER working_hours');
CALL add_col_if_not_exists('attendance', 'check_in_method', 'VARCHAR(50) DEFAULT \"gps\" AFTER check_out');
CALL add_col_if_not_exists('attendance', 'check_out_method', 'VARCHAR(50) DEFAULT \"gps\" AFTER check_in_method');
CALL add_col_if_not_exists('attendance', 'att_address', 'TEXT AFTER check_out_location');
CALL add_col_if_not_exists('attendance', 'latitude', 'DECIMAL(10,8) AFTER att_address');
CALL add_col_if_not_exists('attendance', 'longitude', 'DECIMAL(11,8) AFTER latitude');
CALL add_col_if_not_exists('attendance', 'distance', 'FLOAT AFTER longitude');

-- Copy data
UPDATE employees SET mobile = phone WHERE mobile IS NULL AND phone IS NOT NULL;
UPDATE employees SET profile_photo = profile_image WHERE profile_photo IS NULL AND profile_image IS NOT NULL;
UPDATE employees SET pincode = zip_code WHERE pincode IS NULL AND zip_code IS NOT NULL;
UPDATE attendance SET attendance_date = date WHERE attendance_date IS NULL;
UPDATE attendance SET check_in = check_in_time WHERE check_in IS NULL;
UPDATE attendance SET check_out = check_out_time WHERE check_out IS NULL;

-- Create tables needed by backend
CREATE TABLE IF NOT EXISTS office_locations (
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

CREATE TABLE IF NOT EXISTS salary_rules (
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

CREATE TABLE IF NOT EXISTS salary_deductions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    attendance_id INT,
    deduction_type ENUM('late','half-day','absent') NOT NULL,
    deduction_amount DECIMAL(12,2) DEFAULT 0.00,
    deduction_date DATE NOT NULL,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    holiday_date DATE NOT NULL,
    description TEXT,
    type ENUM('public','company') DEFAULT 'public',
    status TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    message TEXT,
    type ENUM('holiday','birthday','announcement','attendance','system') DEFAULT 'announcement',
    send_to ENUM('all','employee','department') DEFAULT 'all',
    department_id INT,
    status TINYINT(1) DEFAULT 1,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Drop and recreate procedure
DROP PROCEDURE IF EXISTS add_col_if_not_exists;
