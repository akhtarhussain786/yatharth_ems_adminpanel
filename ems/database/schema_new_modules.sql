USE yatharth_ems_db;

-- =============================================
-- LEAVE MANAGEMENT
-- =============================================
CREATE TABLE IF NOT EXISTS leave_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    days_allowed INT DEFAULT 12,
    carry_forward TINYINT(1) DEFAULT 0,
    status TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =============================================
-- DAILY WORK REPORT
-- =============================================
CREATE TABLE IF NOT EXISTS daily_work_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    report_date DATE NOT NULL,
    work_description TEXT,
    completed_tasks TEXT,
    pending_tasks TEXT,
    pending_reason TEXT,
    tomorrow_plan TEXT,
    status ENUM('draft','submitted','approved','rejected') DEFAULT 'draft',
    manager_remarks TEXT,
    approved_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_report (employee_id, report_date)
) ENGINE=InnoDB;

-- =============================================
-- TASK MANAGEMENT
-- =============================================
CREATE TABLE IF NOT EXISTS task_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    status TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    task_category_id INT,
    task_type VARCHAR(100),
    priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
    assigned_by INT,
    assigned_to INT NOT NULL,
    deadline DATETIME,
    points INT DEFAULT 0,
    status ENUM('pending','in_progress','completed','approved','rejected','correction') DEFAULT 'pending',
    completion_time DATETIME,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (assigned_to) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS task_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    employee_id INT NOT NULL,
    file_path VARCHAR(255),
    preview_path VARCHAR(255),
    description TEXT,
    rating INT DEFAULT 0,
    feedback TEXT,
    status ENUM('pending','approved','rejected','correction') DEFAULT 'pending',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS task_revisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    revision_number INT DEFAULT 1,
    file_path VARCHAR(255),
    feedback TEXT,
    submitted_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (submitted_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================
-- TRAVEL MANAGEMENT
-- =============================================
CREATE TABLE IF NOT EXISTS travel_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    travel_date DATE NOT NULL,
    purpose TEXT,
    start_km INT DEFAULT 0,
    end_km INT DEFAULT 0,
    total_km INT GENERATED ALWAYS AS (end_km - start_km) STORED,
    travel_allowance DECIMAL(10,2) DEFAULT 0.00,
    rate_per_km DECIMAL(5,2) DEFAULT 2.50,
    start_location TEXT,
    end_location TEXT,
    start_photo VARCHAR(255),
    end_photo VARCHAR(255),
    odometer_start_photo VARCHAR(255),
    odometer_end_photo VARCHAR(255),
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    approved_by INT,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================
-- EXPENSE MANAGEMENT
-- =============================================
CREATE TABLE IF NOT EXISTS expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    status TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;

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
-- DOCUMENT MANAGEMENT
-- =============================================
CREATE TABLE IF NOT EXISTS document_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    status TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT,
    category_id INT,
    title VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(50),
    file_size INT,
    expiry_date DATE,
    is_public TINYINT(1) DEFAULT 0,
    status TINYINT(1) DEFAULT 1,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL,
    FOREIGN KEY (category_id) REFERENCES document_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================
-- ASSET MANAGEMENT
-- =============================================
CREATE TABLE IF NOT EXISTS assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    asset_code VARCHAR(50) UNIQUE,
    category VARCHAR(100),
    purchase_date DATE,
    purchase_price DECIMAL(12,2),
    current_value DECIMAL(12,2),
    status ENUM('available','assigned','maintenance','retired') DEFAULT 'available',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS asset_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    employee_id INT NOT NULL,
    assigned_date DATE NOT NULL,
    return_date DATE,
    condition_on_assign TEXT,
    condition_on_return TEXT,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- INTERNAL CHAT
-- =============================================
CREATE TABLE IF NOT EXISTS chat_conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200),
    type ENUM('individual','group') DEFAULT 'individual',
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chat_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    user_id INT NOT NULL,
    last_read_at TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chat_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender_id INT NOT NULL,
    message TEXT,
    file_path VARCHAR(255),
    message_type ENUM('text','image','file') DEFAULT 'text',
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- NOTICE BOARD
-- =============================================
CREATE TABLE IF NOT EXISTS notices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT,
    priority ENUM('normal','important','urgent') DEFAULT 'normal',
    attachment VARCHAR(255),
    created_by INT,
    status TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================
-- MEETING MANAGEMENT
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

CREATE TABLE IF NOT EXISTS meeting_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meeting_id INT NOT NULL,
    employee_id INT NOT NULL,
    attendance ENUM('pending','attended','absent') DEFAULT 'pending',
    FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- DOWNLOAD CENTER
-- =============================================
CREATE TABLE IF NOT EXISTS download_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    status TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS downloads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    file_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(50),
    file_size INT,
    download_count INT DEFAULT 0,
    status TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES download_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================
-- HELP & SUPPORT
-- =============================================
CREATE TABLE IF NOT EXISTS help_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
    status ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
    assigned_to INT,
    resolved_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================
-- CRM / LEAD MANAGEMENT
-- =============================================
CREATE TABLE IF NOT EXISTS lead_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    status TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100),
    mobile VARCHAR(20) NOT NULL,
    alternate_mobile VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    city VARCHAR(100),
    state VARCHAR(100),
    school_college VARCHAR(200),
    course_interested VARCHAR(200),
    lead_type ENUM('student','parent','teacher','other') DEFAULT 'student',
    lead_source VARCHAR(100),
    source_id INT,
    assigned_to INT,
    assigned_by INT,
    status ENUM('new','contacted','interested','not_interested','follow_up','admission_pending','admitted','closed') DEFAULT 'new',
    priority ENUM('low','medium','high') DEFAULT 'medium',
    feedback TEXT,
    next_followup_date DATE,
    next_followup_time TIME,
    converted_to_admission TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (assigned_to) REFERENCES employees(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS lead_followups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT NOT NULL,
    employee_id INT NOT NULL,
    followup_date DATE NOT NULL,
    followup_time TIME,
    call_status VARCHAR(100),
    remarks TEXT,
    next_followup_date DATE,
    next_followup_time TIME,
    status ENUM('completed','pending','missed') DEFAULT 'completed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- MARKETING EXECUTIVE - FIELD VISITS
-- =============================================
CREATE TABLE IF NOT EXISTS duty_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    duty_date DATE NOT NULL,
    start_time DATETIME,
    end_time DATETIME,
    start_selfie VARCHAR(255),
    end_selfie VARCHAR(255),
    start_km INT DEFAULT 0,
    end_km INT DEFAULT 0,
    odometer_start_photo VARCHAR(255),
    odometer_end_photo VARCHAR(255),
    start_latitude DECIMAL(10,8),
    start_longitude DECIMAL(11,8),
    end_latitude DECIMAL(10,8),
    end_longitude DECIMAL(11,8),
    total_visits INT DEFAULT 0,
    total_leads INT DEFAULT 0,
    status ENUM('active','completed') DEFAULT 'active',
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS field_visits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    duty_log_id INT,
    employee_id INT NOT NULL,
    visit_date DATE NOT NULL,
    visit_time TIME,
    institute_name VARCHAR(255),
    contact_person VARCHAR(100),
    contact_number VARCHAR(20),
    visit_type ENUM('school','college','other') DEFAULT 'other',
    teacher_met INT DEFAULT 0,
    student_met INT DEFAULT 0,
    parent_met INT DEFAULT 0,
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    photo VARCHAR(255),
    photo_stamped VARCHAR(255),
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (duty_log_id) REFERENCES duty_logs(id) ON DELETE SET NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- ACCOUNTS MODULE
-- =============================================
CREATE TABLE IF NOT EXISTS vendors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    contact_person VARCHAR(100),
    mobile VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    gst_no VARCHAR(50),
    pan_no VARCHAR(50),
    status TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS income_expense (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('income','expense') NOT NULL,
    category VARCHAR(100),
    amount DECIMAL(12,2) NOT NULL,
    entry_date DATE NOT NULL,
    description TEXT,
    payment_method VARCHAR(50),
    reference_no VARCHAR(100),
    vendor_id INT,
    bill_photo VARCHAR(255),
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fee_collections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT,
    student_name VARCHAR(200) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    fee_date DATE NOT NULL,
    fee_type ENUM('admission','tuition','exam','other') DEFAULT 'tuition',
    payment_method VARCHAR(50),
    reference_no VARCHAR(100),
    receipt_no VARCHAR(50) UNIQUE,
    remarks TEXT,
    collected_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (collected_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================
-- SALARY PROCESSING & LEDGER
-- =============================================
CREATE TABLE IF NOT EXISTS salary_processing (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    month_year VARCHAR(7) NOT NULL,
    payroll_month VARCHAR(7) DEFAULT NULL,
    joining_date_snapshot DATE DEFAULT NULL,
    relieving_date_snapshot DATE DEFAULT NULL,
    eligible_days INT DEFAULT 0,
    total_days_in_month INT DEFAULT 30,
    daily_rate DECIMAL(12,2) DEFAULT 0.00,
    base_earned_salary DECIMAL(12,2) DEFAULT 0.00,
    basic_salary DECIMAL(12,2) DEFAULT 0.00,
    allowances DECIMAL(12,2) DEFAULT 0.00,
    deductions DECIMAL(12,2) DEFAULT 0.00,
    net_salary DECIMAL(12,2) DEFAULT 0.00,
    current_net_salary DECIMAL(12,2) DEFAULT 0.00,
    previous_due DECIMAL(12,2) DEFAULT 0.00,
    total_payable DECIMAL(12,2) DEFAULT 0.00,
    paid_amount DECIMAL(12,2) DEFAULT 0.00,
    remaining_due DECIMAL(12,2) DEFAULT 0.00,
    present_days DECIMAL(5,2) DEFAULT 0.00,
    paid_leave_days DECIMAL(5,2) DEFAULT 0.00,
    unpaid_leave_days DECIMAL(5,2) DEFAULT 0.00,
    absent_days DECIMAL(5,2) DEFAULT 0.00,
    late_days DECIMAL(5,2) DEFAULT 0.00,
    half_days DECIMAL(5,2) DEFAULT 0.00,
    weekly_off_days DECIMAL(5,2) DEFAULT 0.00,
    holiday_days DECIMAL(5,2) DEFAULT 0.00,
    attendance_deduction DECIMAL(12,2) DEFAULT 0.00,
    bonus_amount DECIMAL(12,2) DEFAULT 0.00,
    incentive_amount DECIMAL(12,2) DEFAULT 0.00,
    overtime_hours DECIMAL(5,2) DEFAULT 0.00,
    overtime_amount DECIMAL(12,2) DEFAULT 0.00,
    other_earnings DECIMAL(12,2) DEFAULT 0.00,
    advance_deduction DECIMAL(12,2) DEFAULT 0.00,
    other_deductions DECIMAL(12,2) DEFAULT 0.00,
    payment_status VARCHAR(30) DEFAULT 'unpaid',
    lock_status VARCHAR(30) DEFAULT 'generated',
    processed_by INT DEFAULT NULL,
    finalized_by INT DEFAULT NULL,
    finalized_at DATETIME DEFAULT NULL,
    paid_date DATE DEFAULT NULL,
    remarks TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_salary (employee_id, month_year),
    INDEX idx_salary_emp_month (employee_id, month_year),
    INDEX idx_salary_payment_status (payment_status),
    INDEX idx_salary_lock_status (lock_status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS salary_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payroll_id INT DEFAULT NULL,
    employee_id INT NOT NULL,
    payment_amount DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method VARCHAR(50) DEFAULT 'bank_transfer',
    reference_no VARCHAR(100) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (payroll_id) REFERENCES salary_processing(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_pay_emp (employee_id, payment_date),
    INDEX idx_pay_payroll (payroll_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS salary_audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    payroll_id INT DEFAULT NULL,
    employee_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    old_data TEXT DEFAULT NULL,
    new_data TEXT DEFAULT NULL,
    user_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    INDEX idx_salary_audit_emp (employee_id),
    INDEX idx_salary_audit_action (action)
) ENGINE=InnoDB;

-- =============================================
-- IT TEAM - TASK POINTS CONFIG
-- =============================================
CREATE TABLE IF NOT EXISTS task_points_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_type VARCHAR(100) NOT NULL,
    points INT DEFAULT 0,
    status TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;

INSERT INTO task_points_config (task_type, points) VALUES
('Poster', 10), ('Banner', 15), ('Brochure', 20), ('Video', 20),
('Reel', 15), ('Website Update', 15), ('Bug Fix', 10), ('Social Media Post', 5),
('AI Content', 10), ('AI Image', 10), ('AI Video', 15);

-- =============================================
-- EMPLOYEE TARGETS
-- =============================================
CREATE TABLE IF NOT EXISTS employee_targets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    target_type VARCHAR(100) NOT NULL,
    daily_target INT DEFAULT 0,
    monthly_target INT DEFAULT 0,
    points_per_unit INT DEFAULT 0,
    month_year VARCHAR(7),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- NOTIFICATIONS (extended)
-- =============================================
CREATE TABLE IF NOT EXISTS employee_notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT,
    type VARCHAR(50),
    is_read TINYINT(1) DEFAULT 0,
    reference_id INT,
    reference_type VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;
