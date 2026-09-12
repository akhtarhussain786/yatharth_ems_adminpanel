<?php
/**
 * Every schema change the API depends on, expressed so it can be applied from
 * PHP. Each block runs once and is then recorded in `schema_migrations`.
 *
 * These exist because the code writes columns and ENUM values that the base
 * schema.sql never defined (leads.first_name, leads.assigned_sales, the
 * telecaller lead statuses), and because the field-visit, help and downloads
 * tables ship in schema_new_modules.sql which may never have been applied.
 */
require_once __DIR__ . '/schema_guard.php';

function runSchemaMigrations($db)
{
    // ---------------------------------------------------------------
    // leads: columns the API and admin panel write but schema.sql lacks
    // ---------------------------------------------------------------
    SchemaGuard::ensure($db, 'leads_columns_v2', function ($db) {
        SchemaGuard::addColumns($db, 'leads', [
            'first_name'    => "VARCHAR(100) DEFAULT ''",
            'last_name'     => "VARCHAR(100) DEFAULT ''",
            'mobile'        => "VARCHAR(20) DEFAULT ''",
            'email'         => "VARCHAR(100) DEFAULT ''",
            'company_name'  => "VARCHAR(200) DEFAULT ''",
            'city'          => "VARCHAR(100) DEFAULT ''",
            'lead_source'   => "VARCHAR(100) DEFAULT ''",
            'campaign_name' => "VARCHAR(200) DEFAULT ''",
            'requirement'   => 'TEXT DEFAULT NULL',
            'budget'        => 'DECIMAL(12,2) DEFAULT NULL',
            'priority'      => "ENUM('low','medium','high') DEFAULT 'medium'",
            'created_by'    => 'INT DEFAULT NULL',
            'assigned_to'   => 'INT DEFAULT NULL',
            'assigned_by'   => 'INT DEFAULT NULL',
            'assigned_sales' => 'INT DEFAULT NULL',
        ]);
    });

    // updateLeadStatus() and the sales pipeline filter use statuses the
    // original ENUM never listed; without this they silently fail to save.
    SchemaGuard::ensure($db, 'leads_status_enum_v2', function ($db) {
        SchemaGuard::modifyColumn($db, 'leads', 'status',
            "ENUM('new','contacted','calling','connected','busy','no_answer'," .
            "'follow_up','interested','qualified','proposal','meeting_scheduled'," .
            "'demo_scheduled','quotation_sent','negotiation','not_interested'," .
            "'wrong_number','duplicate','won','lost','closed') DEFAULT 'new'");
    });

    // An admin creating a lead may have no employees row at all. employee_id
    // was NOT NULL, which made that a hard insert failure; such a lead is still
    // reachable by admins and by its assignee.
    SchemaGuard::ensure($db, 'leads_employee_id_nullable_v1', function ($db) {
        SchemaGuard::modifyColumn($db, 'leads', 'employee_id', 'INT NULL');
    });

    // Index the columns the visibility filter now searches on.
    SchemaGuard::ensure($db, 'leads_assignment_indexes_v1', function ($db) {
        foreach (['assigned_to', 'assigned_sales', 'employee_id'] as $col) {
            try {
                $db->exec("CREATE INDEX idx_leads_$col ON leads ($col)");
            } catch (Throwable $e) {
                // index already present, or column missing — neither is fatal
            }
        }
    });

    // ---------------------------------------------------------------
    // Field marketing: duty_logs + field_visits
    // ---------------------------------------------------------------
    SchemaGuard::ensure($db, 'field_marketing_v2', function ($db) {
        SchemaGuard::reconcile($db, "CREATE TABLE IF NOT EXISTS duty_logs (
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
            INDEX idx_duty_employee_date (employee_id, duty_date)
        ) ENGINE=InnoDB");

        SchemaGuard::reconcile($db, "CREATE TABLE IF NOT EXISTS field_visits (
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
            km_traveled INT DEFAULT 0,
            counts INT DEFAULT 0,
            latitude DECIMAL(10,8),
            longitude DECIMAL(11,8),
            photo VARCHAR(255),
            photo_stamped VARCHAR(255),
            remarks TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_visit_employee_date (employee_id, visit_date)
        ) ENGINE=InnoDB");

        // The app's visit form collects these two; older installs lack them.
        SchemaGuard::addColumns($db, 'field_visits', [
            'km_traveled' => 'INT DEFAULT 0',
            'counts'      => 'INT DEFAULT 0',
        ]);
    });

    // ---------------------------------------------------------------
    // Help desk
    // ---------------------------------------------------------------
    SchemaGuard::ensure($db, 'help_tickets_v2', function ($db) {
        SchemaGuard::reconcile($db, "CREATE TABLE IF NOT EXISTS help_tickets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            subject VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
            status ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
            assigned_to INT,
            resolved_at DATETIME,
            response TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_help_employee (employee_id)
        ) ENGINE=InnoDB");

        SchemaGuard::addColumns($db, 'help_tickets', ['response' => 'TEXT DEFAULT NULL']);
    });

    // ---------------------------------------------------------------
    // Downloads
    // ---------------------------------------------------------------
    SchemaGuard::ensure($db, 'downloads_v2', function ($db) {
        SchemaGuard::reconcile($db, "CREATE TABLE IF NOT EXISTS download_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            status TINYINT(1) DEFAULT 1
        ) ENGINE=InnoDB");

        SchemaGuard::reconcile($db, "CREATE TABLE IF NOT EXISTS downloads (
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
            INDEX idx_downloads_category (category_id)
        ) ENGINE=InnoDB");
    });

    // ---------------------------------------------------------------
    // employees: every column the admin panel and the app write.
    //
    // This used to add is_field_staff alone. The edit form writes the whole
    // address block as well, and MySQL rejects an UPDATE outright when any one
    // column in the field list is missing — so a table without `state` failed
    // every employee edit, whatever field had actually been changed.
    //
    // Listing them all means the next column to go missing is repaired by the
    // same block rather than by another one-line migration after another
    // failure. Columns are only added, never altered, and only when absent.
    // ---------------------------------------------------------------
    SchemaGuard::ensure($db, 'employees_columns_v2', function ($db) {
        SchemaGuard::addColumns($db, 'employees', [
            'is_field_staff' => 'TINYINT(1) DEFAULT 0',
            'address'        => 'TEXT DEFAULT NULL',
            'city'           => 'VARCHAR(100) DEFAULT NULL',
            'state'          => 'VARCHAR(100) DEFAULT NULL',
            'pincode'        => 'VARCHAR(20) DEFAULT NULL',
            'profile_photo'  => 'VARCHAR(255) DEFAULT NULL',
        ]);
    });

    // ---------------------------------------------------------------
    // users: FCM token for push notifications
    // ---------------------------------------------------------------
    SchemaGuard::ensure($db, 'users_fcm_token_v1', function ($db) {
        SchemaGuard::addColumns($db, 'users', [
            'fcm_token' => 'VARCHAR(500) DEFAULT NULL',
        ]);
    });

    // ---------------------------------------------------------------
    // leave_requests & leave_balances setup
    // ---------------------------------------------------------------
    SchemaGuard::ensure($db, 'leave_system_v3', function ($db) {
        SchemaGuard::addColumns($db, 'leave_requests', [
            'day_type'    => "ENUM('Full Day', 'Half Day') DEFAULT 'Full Day'",
            'attachment'  => 'VARCHAR(255) DEFAULT NULL',
            'remarks'     => 'TEXT DEFAULT NULL',
            'start_date'  => 'DATE NULL',
            'end_date'    => 'DATE NULL',
            'total_days'  => 'DECIMAL(4,1) DEFAULT 1.0',
        ]);

        SchemaGuard::reconcile($db, "CREATE TABLE IF NOT EXISTS leave_balances (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            leave_type VARCHAR(100) NOT NULL,
            allotted DECIMAL(4,1) DEFAULT 0.0,
            used DECIMAL(4,1) DEFAULT 0.0,
            year INT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_emp_leave_year (employee_id, leave_type, year),
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
    });

    // ---------------------------------------------------------------
    // notifications: user_id, is_read, link columns
    // ---------------------------------------------------------------
    SchemaGuard::ensure($db, 'notifications_columns_v1', function ($db) {
        SchemaGuard::addColumns($db, 'notifications', [
            'user_id' => 'INT DEFAULT NULL',
            'is_read' => 'TINYINT(1) DEFAULT 0',
            'link'    => 'VARCHAR(255) DEFAULT NULL',
        ]);
    });

    // ---------------------------------------------------------------
    // Attendance grading thresholds:
    //   before 10:00          on time
    //   10:00 - 10:10         late (recorded, not charged)
    //   after 10:10           half day
    //   checkout before 17:00 half day
    // half_day_time and late_start_time already existed with other values, but
    // no code applied them to pay, so they are set here to the agreed times.
    // All three stay editable by an admin afterwards.
    // ---------------------------------------------------------------
    SchemaGuard::ensure($db, 'attendance_thresholds_v2', function ($db) {
        $upsert = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $upsert->execute(['late_start_time', '10:00:00']);
        $upsert->execute(['half_day_time', '10:10:00']);
        $upsert->execute(['half_day_checkout_time', '17:00:00']);
    });

    // total_days was declared DECIMAL in leave_system_v2, but addColumns only
    // adds columns that are missing — the pre-existing INT was left alone, so a
    // half day (0.5) rounded to 1 on the way in. This changes the type.
    SchemaGuard::ensure($db, 'leave_total_days_decimal_v1', function ($db) {
        SchemaGuard::modifyColumn($db, 'leave_requests', 'total_days', 'DECIMAL(4,1) DEFAULT 1.0');
    });

    // ---------------------------------------------------------------
    // travel_requests ships only in schema_new_modules.sql, which may never
    // have been applied. total_km is left as a plain column here; the code
    // derives it on read, so this works whether the live table has it as a
    // generated column or not.
    // ---------------------------------------------------------------
    SchemaGuard::ensure($db, 'travel_requests_v2', function ($db) {
        SchemaGuard::reconcile($db, "CREATE TABLE IF NOT EXISTS travel_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            travel_date DATE NOT NULL,
            purpose TEXT,
            start_km INT DEFAULT 0,
            end_km INT DEFAULT 0,
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
            INDEX idx_travel_employee (employee_id, travel_date)
        ) ENGINE=InnoDB");
    });

    // ---------------------------------------------------------------
    // In-app APK updates. Only one row is ever active; the API serves that one.
    // ---------------------------------------------------------------
    SchemaGuard::ensure($db, 'app_updates_v2', function ($db) {
        SchemaGuard::reconcile($db, "CREATE TABLE IF NOT EXISTS app_updates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            latest_version VARCHAR(20) NOT NULL,
            minimum_version VARCHAR(20) NOT NULL DEFAULT '1.0.0',
            apk_url VARCHAR(500) NOT NULL,
            apk_file_name VARCHAR(255) NOT NULL,
            apk_file_size BIGINT DEFAULT 0,
            sha256 CHAR(64) DEFAULT NULL,
            update_title VARCHAR(150) NOT NULL DEFAULT 'New Update Available',
            update_message TEXT,
            force_update TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_app_updates_active (is_active, id)
        ) ENGINE=InnoDB");
    });

    // ---------------------------------------------------------------
    // Device binding. An account is tied to one handset, so a colleague cannot
    // sign in with borrowed credentials on their own phone.
    //
    // session_token is widened at the same time: a JWT is ~240 characters and
    // the column was varchar(255), which left no headroom — and a truncated
    // token would never match, locking every user out once sessions are
    // actually enforced.
    // ---------------------------------------------------------------
    SchemaGuard::ensure($db, 'device_binding_v1', function ($db) {
        SchemaGuard::modifyColumn($db, 'user_sessions', 'session_token', 'VARCHAR(512) NOT NULL');
        SchemaGuard::addColumns($db, 'user_sessions', [
            'device_id' => 'VARCHAR(128) DEFAULT NULL',
        ]);
        SchemaGuard::addColumns($db, 'users', [
            'device_id'      => 'VARCHAR(128) DEFAULT NULL',
            'device_name'    => 'VARCHAR(150) DEFAULT NULL',
            'device_bound_at' => 'DATETIME DEFAULT NULL',
        ]);
    });

    // ---------------------------------------------------------------
    // Face verification for attendance.
    //
    // Only the embedding is stored — a list of numbers produced by the model —
    // never the photograph itself. It cannot be turned back into a usable face
    // image, which matters because biometric data is sensitive personal data
    // under the DPDP Act 2023.
    //
    // Enrollment is one-time: once `is_locked` is set, only a super admin can
    // clear it. Without that, an employee could simply re-enroll a colleague's
    // face and the whole control would be worthless.
    // ---------------------------------------------------------------
    SchemaGuard::ensure($db, 'employee_face_data_v2', function ($db) {
        SchemaGuard::reconcile($db, "CREATE TABLE IF NOT EXISTS employee_face_data (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            embedding MEDIUMTEXT NOT NULL,
            dimensions INT NOT NULL DEFAULT 0,
            model_version VARCHAR(50) DEFAULT NULL,
            reference_photo VARCHAR(255) DEFAULT NULL,
            is_locked TINYINT(1) NOT NULL DEFAULT 1,
            enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            reset_by INT DEFAULT NULL,
            reset_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_face_employee (employee_id)
        ) ENGINE=InnoDB");

        // Outcome of every verification attempt, so a mismatch can be reviewed
        // and the threshold tuned against what actually happens.
        SchemaGuard::reconcile($db, "CREATE TABLE IF NOT EXISTS face_verification_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            action VARCHAR(20) NOT NULL,
            similarity DECIMAL(6,4) DEFAULT NULL,
            threshold DECIMAL(6,4) DEFAULT NULL,
            passed TINYINT(1) NOT NULL DEFAULT 0,
            mode VARCHAR(20) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_face_log_emp (employee_id, created_at)
        ) ENGINE=InnoDB");

        $upsert = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                                ON DUPLICATE KEY UPDATE setting_value = setting_value");
        // block = refuse a mismatched check-in; flag = record it for review;
        // log = observe only; off = disabled.
        $upsert->execute(['face_verification_mode', 'block']);
        // Cosine similarity floor. Tune against real staff before trusting it.
        $upsert->execute(['face_match_threshold', '0.65']);
    });

    // salary_deductions.remarks was read as `sd.reason` by the salary report,
    // which is not a column that exists. Adding it keeps any data written under
    // that name readable while the code moves to `remarks`.
    SchemaGuard::ensure($db, 'salary_deductions_remarks_v1', function ($db) {
        SchemaGuard::addColumns($db, 'salary_deductions', [
            'remarks' => 'TEXT DEFAULT NULL',
        ]);
    });

    // ---------------------------------------------------------------
    // Every column the code writes, for the tables no other migration owns.
    //
    // MySQL rejects an INSERT or UPDATE outright when a single column in the
    // field list is absent, so one missing column takes a whole feature down —
    // that is how duty_logs.start_selfie, travel_requests.start_photo,
    // employees.state, attendance.is_holiday, documents.file_size and
    // call_reports.follow_up_required each broke in turn. Adding them one at a
    // time as they fail does not converge; this states what every table needs.
    //
    // Only ever adds. Existing columns keep whatever type they already have,
    // and nothing is dropped or narrowed. Every definition is nullable or
    // carries a default, so it is safe on a table that already holds rows.
    // Types come from the schema files, or match how the code uses the column
    // where no schema file defines one.
    // ---------------------------------------------------------------
    SchemaGuard::ensure($db, 'written_columns_v1', function ($db) {
        $tables = [
            'activity_logs' => [
                'action' => "VARCHAR(100)",
                'description' => "TEXT",
                'ip_address' => "VARCHAR(45)",
                'module' => "VARCHAR(100)",
                'user_id' => "INT",
            ],
            'asset_assignments' => [
                'asset_id' => "INT",
                'assigned_date' => "DATE",
                'condition_on_assign' => "TEXT",
                'condition_on_return' => "TEXT",
                'employee_id' => "INT",
                'return_date' => "DATE",
            ],
            'assets' => [
                'asset_code' => "VARCHAR(50)",
                'category' => "VARCHAR(100)",
                'current_value' => "DECIMAL(12,2)",
                'description' => "TEXT",
                'name' => "VARCHAR(200)",
                'purchase_date' => "DATE",
                'purchase_price' => "DECIMAL(12,2)",
                'status' => "ENUM('available','assigned','maintenance','retired') DEFAULT 'available'",
            ],
            'attendance' => [
                'address' => "TEXT",
                'attendance_date' => "DATE",
                'check_in' => "DATETIME",
                'check_in_photo' => "VARCHAR(255)",
                'check_out' => "DATETIME",
                'checkin_location_type' => "VARCHAR(20) DEFAULT 'office'",
                'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                'distance_from_office' => "DECIMAL(10,2) DEFAULT NULL",
                'employee_id' => "INT",
                'holiday_title' => "VARCHAR(255) DEFAULT NULL",
                'is_field_work' => "TINYINT(1) DEFAULT 0",
                'is_holiday' => "TINYINT(1) DEFAULT 0",
                'late_minutes' => "INT DEFAULT 0",
                'latitude' => "DECIMAL(10,8)",
                'leave_type' => "VARCHAR(50) DEFAULT NULL",
                'longitude' => "DECIMAL(11,8)",
                'remarks' => "TEXT",
                'status' => "VARCHAR(20) DEFAULT 'present'",
                'working_hours' => "TIME DEFAULT '00:00:00'",
                'working_hours_decimal' => "DECIMAL(5,2) DEFAULT 0.00",
            ],
            'call_reports' => [
                'call_date' => "DATE",
                'call_duration' => "INT DEFAULT 0 COMMENT 'Duration in seconds'",
                'call_type' => "ENUM('incoming','outgoing','follow_up') DEFAULT 'outgoing'",
                'customer_name' => "VARCHAR(200)",
                'customer_phone' => "VARCHAR(20)",
                'employee_id' => "INT",
                'follow_up_required' => "TINYINT(1) DEFAULT 0",
                'lead_id' => "INT DEFAULT NULL",
                'notes' => "TEXT",
                'status' => "ENUM('completed','busy','no_answer','callback','not_interested') DEFAULT 'completed'",
            ],
            'campaigns' => [
                'budget' => "DECIMAL(12,2) DEFAULT 0.00",
                'campaign_name' => "VARCHAR(200)",
                'employee_id' => "INT",
                'end_date' => "DATE",
                'platform' => "VARCHAR(100)",
                'results' => "TEXT",
                'start_date' => "DATE",
                'status' => "ENUM('planning','active','paused','completed','cancelled') DEFAULT 'planning'",
            ],
            'chat_conversations' => [
                'created_by' => "INT",
                'title' => "VARCHAR(200)",
                'type' => "ENUM('individual','group') DEFAULT 'individual'",
            ],
            'chat_messages' => [
                'conversation_id' => "INT",
                'file_path' => "VARCHAR(255)",
                'message' => "TEXT",
                'message_type' => "ENUM('text','image','file') DEFAULT 'text'",
                'sender_id' => "INT",
            ],
            'chat_participants' => [
                'conversation_id' => "INT",
                'user_id' => "INT",
            ],
            'daily_work_reports' => [
                'attachment' => "VARCHAR(255) DEFAULT NULL",
                'description' => "TEXT DEFAULT NULL",
                'employee_id' => "INT",
                'hours_worked' => "DECIMAL(5,2) DEFAULT 0.00",
                'remarks' => "TEXT DEFAULT NULL",
                'report_date' => "DATE",
                'reviewed_by' => "INT DEFAULT NULL",
                'status' => "ENUM('draft','submitted','approved','rejected') DEFAULT 'draft'",
                'title' => "VARCHAR(255) DEFAULT NULL",
            ],
            'departments' => [
                'description' => "TEXT",
                'name' => "VARCHAR(100)",
            ],
            'designations' => [
                'department_id' => "INT",
                'name' => "VARCHAR(100)",
            ],
            'document_categories' => [
                'name' => "VARCHAR(100)",
            ],
            'documents' => [
                'category_id' => "INT",
                'employee_id' => "INT",
                'expiry_date' => "DATE",
                'file_path' => "VARCHAR(255)",
                'file_size' => "INT",
                'file_type' => "VARCHAR(50)",
                'is_public' => "TINYINT(1) DEFAULT 0",
                'title' => "VARCHAR(255)",
            ],
            'employee_targets' => [
                'daily_target' => "INT DEFAULT 0",
                'employee_id' => "INT",
                'month_year' => "VARCHAR(7)",
                'monthly_target' => "INT DEFAULT 0",
                'points_per_unit' => "INT DEFAULT 0",
                'target_type' => "VARCHAR(100)",
            ],
            'expenses' => [
                'amount' => "DECIMAL(12,2)",
                'approved_by' => "INT",
                'bill_photo' => "VARCHAR(255)",
                'description' => "TEXT",
                'employee_id' => "INT",
                'expense_category_id' => "INT",
                'expense_date' => "DATE",
                'remarks' => "TEXT",
                'status' => "ENUM('pending','approved','rejected') DEFAULT 'pending'",
            ],
            'fee_collections' => [
                'amount' => "DECIMAL(12,2)",
                'collected_by' => "INT",
                'fee_date' => "DATE",
                'fee_type' => "ENUM('admission','tuition','exam','other') DEFAULT 'tuition'",
                'lead_id' => "INT",
                'payment_method' => "VARCHAR(50)",
                'receipt_no' => "VARCHAR(50)",
                'reference_no' => "VARCHAR(100)",
                'remarks' => "TEXT",
                'student_name' => "VARCHAR(200)",
            ],
            'follow_ups' => [
                'customer_name' => "VARCHAR(200)",
                'customer_phone' => "VARCHAR(20)",
                'employee_id' => "INT",
                'follow_up_date' => "DATE",
                'follow_up_time' => "TIME DEFAULT NULL",
                'follow_up_type' => "ENUM('call','meeting','email','other') DEFAULT 'call'",
                'lead_id' => "INT DEFAULT NULL",
                'notes' => "TEXT",
                'status' => "ENUM('pending','completed','cancelled') DEFAULT 'pending'",
            ],
            'holidays' => [
                'description' => "TEXT",
                'holiday_date' => "DATE",
                'title' => "VARCHAR(100)",
                'type' => "VARCHAR(30) DEFAULT 'public'",
            ],
            'hr_activities' => [
                'activity_date' => "DATE",
                'activity_type' => "ENUM('interview','training','onboarding','meeting','review','other')",
                'description' => "TEXT",
                'employee_id' => "INT",
                'status' => "ENUM('scheduled','completed','cancelled') DEFAULT 'scheduled'",
                'title' => "VARCHAR(200)",
            ],
            'income_expense' => [
                'amount' => "DECIMAL(12,2)",
                'bill_photo' => "VARCHAR(255)",
                'category' => "VARCHAR(100)",
                'created_by' => "INT",
                'description' => "TEXT",
                'entry_date' => "DATE",
                'payment_method' => "VARCHAR(50)",
                'reference_no' => "VARCHAR(100)",
                'type' => "ENUM('income','expense')",
                'vendor_id' => "INT",
            ],
            'leave_requests' => [
                'attachment' => "VARCHAR(255) DEFAULT NULL",
                'day_type' => "VARCHAR(20) DEFAULT NULL",
                'end_date' => "DATE DEFAULT NULL",
                'start_date' => "DATE DEFAULT NULL",
                'total_days' => "DECIMAL(4,1) DEFAULT 0.0",
            ],
            'meeting_participants' => [
                'attendance' => "ENUM('pending','attended','absent') DEFAULT 'pending'",
                'employee_id' => "INT",
                'meeting_id' => "INT",
            ],
            'meetings' => [
                'created_by' => "INT",
                'description' => "TEXT",
                'end_time' => "TIME",
                'meeting_date' => "DATE",
                'meeting_link' => "VARCHAR(255)",
                'start_time' => "TIME",
                'status' => "ENUM('scheduled','ongoing','completed','cancelled') DEFAULT 'scheduled'",
                'title' => "VARCHAR(255)",
                'venue' => "VARCHAR(255)",
            ],
            'notices' => [
                'content' => "TEXT",
                'created_by' => "INT",
                'priority' => "ENUM('normal','important','urgent') DEFAULT 'normal'",
                'status' => "TINYINT(1) DEFAULT 1",
                'title' => "VARCHAR(255)",
            ],
            'notifications' => [
                'is_read' => "TINYINT(1) DEFAULT 0",
                'link' => "VARCHAR(255) DEFAULT NULL",
                'user_id' => "INT DEFAULT NULL",
            ],
            'office_locations' => [
                'address' => "TEXT",
                'latitude' => "DECIMAL(10,8)",
                'longitude' => "DECIMAL(11,8)",
                'office_name' => "VARCHAR(100)",
                'radius' => "INT DEFAULT 100",
            ],
            'payments' => [
                'amount' => "DECIMAL(12,2)",
                'created_by' => "INT DEFAULT NULL",
                'lead_id' => "INT",
                'payment_mode' => "ENUM('cash','cheque','bank_transfer','online','other') DEFAULT 'cash'",
                'status' => "ENUM('pending','completed','failed','refunded') DEFAULT 'completed'",
                'transaction_id' => "VARCHAR(100) DEFAULT NULL",
            ],
            'permissions' => [
                'can_create' => "TINYINT(1) DEFAULT 0",
                'can_delete' => "TINYINT(1) DEFAULT 0",
                'can_edit' => "TINYINT(1) DEFAULT 0",
                'can_view' => "TINYINT(1) DEFAULT 0",
                'module' => "VARCHAR(100)",
                'role_id' => "INT",
            ],
            'projects' => [
                'created_by' => "INT DEFAULT NULL",
                'expected_end_date' => "DATE DEFAULT NULL",
                'lead_id' => "INT DEFAULT NULL",
                'project_code' => "VARCHAR(50)",
                'project_name' => "VARCHAR(255)",
                'scope_of_work' => "TEXT DEFAULT NULL",
                'start_date' => "DATE DEFAULT NULL",
                'status' => "ENUM('pending','in_progress','completed','cancelled') DEFAULT 'pending'",
            ],
            'roles' => [
                'description' => "VARCHAR(150) DEFAULT NULL",
                'name' => "VARCHAR(50)",
            ],
            'salary_deductions' => [
                'deduction_type' => "VARCHAR(50) DEFAULT NULL",
            ],
            'salary_processing' => [
                'absent_days' => "INT DEFAULT 0",
                'allowances' => "DECIMAL(12,2) DEFAULT 0",
                'basic_salary' => "DECIMAL(12,2) DEFAULT 0",
                'deductions' => "DECIMAL(12,2) DEFAULT 0",
                'employee_id' => "INT",
                'half_days' => "INT DEFAULT 0",
                'late_days' => "INT DEFAULT 0",
                'month_year' => "VARCHAR(7)",
                'net_salary' => "DECIMAL(12,2) DEFAULT 0",
                'present_days' => "INT DEFAULT 0",
                'processed_by' => "INT",
                'status' => "ENUM('pending','processed','paid') DEFAULT 'pending'",
            ],
            'salary_rules' => [
                'absent_deduction_percent' => "DECIMAL(5,2) DEFAULT 100.00",
                'half_day_deduction_percent' => "DECIMAL(5,2) DEFAULT 50.00",
                'overtime_rate' => "DECIMAL(10,2) DEFAULT 0.00",
                'rule_name' => "VARCHAR(100)",
            ],
            'settings' => [
                'setting_key' => "VARCHAR(100)",
                'setting_value' => "TEXT",
            ],
            'task_points_config' => [
                'points' => "INT DEFAULT 0",
                'status' => "TINYINT(1) DEFAULT 1",
                'task_type' => "VARCHAR(100)",
            ],
            'task_submissions' => [
                'description' => "TEXT",
                'employee_id' => "INT",
                'feedback' => "TEXT",
                'file_path' => "VARCHAR(255)",
                'preview_path' => "VARCHAR(255)",
                'rating' => "INT DEFAULT 0",
                'status' => "ENUM('pending','approved','rejected','correction') DEFAULT 'pending'",
                'task_id' => "INT",
            ],
            'tasks' => [
                'assigned_by' => "INT",
                'assigned_to' => "INT",
                'deadline' => "DATETIME",
                'description' => "TEXT",
                'due_date' => "DATE DEFAULT NULL",
                'points' => "INT DEFAULT 0",
                'priority' => "ENUM('low','medium','high','urgent') DEFAULT 'medium'",
                'status' => "ENUM('pending','in_progress','completed','approved','rejected','correction') DEFAULT 'pending'",
                'task_category_id' => "INT",
                'task_type' => "VARCHAR(100)",
                'title' => "VARCHAR(255)",
            ],
            'vendors' => [
                'address' => "TEXT",
                'contact_person' => "VARCHAR(100)",
                'email' => "VARCHAR(100)",
                'gst_no' => "VARCHAR(50)",
                'mobile' => "VARCHAR(20)",
                'name' => "VARCHAR(200)",
                'pan_no' => "VARCHAR(50)",
                'status' => "TINYINT(1) DEFAULT 1",
            ],
        ];

        foreach ($tables as $table => $columns) {
            // Skips silently when the table is absent from this database.
            SchemaGuard::addColumns($db, $table, $columns);
        }
    });

    // ---------------------------------------------------------------
    // leads.created_by holds an EMPLOYEE id, not a user id.
    //
    // The insert binds $eid, and the visibility filter matches it against the
    // employee id too — so the column is used consistently. The constraint,
    // however, points at users(id). Whenever an employee id did not happen to
    // also exist as a user id, creating a lead failed with a foreign key error;
    // it was still doing so between 30 Jul and 14 Aug.
    //
    // The constraint is what is wrong here, not the data: every read treats the
    // column as an employee id. Retyping the column would mean rewriting live
    // rows on a guess about which kind of id each already holds.
    // ---------------------------------------------------------------
    SchemaGuard::ensure($db, 'leads_created_by_fk_v1', function ($db) {
        SchemaGuard::dropForeignKey($db, 'leads', 'fk_leads_created_by');
    });

    // ---------------------------------------------------------------
    // The tables written_columns_v1 skipped.
    //
    // That migration excluded any table another migration already touched —
    // but those cover only a column or two each, so the rest of the table was
    // left unguaranteed. users is the clearest case: a migration adds
    // fcm_token and the device fields, and nothing else, so users.employee_id
    // and api_token were never covered even though the code writes both.
    //
    // Same rules as before: add only, nullable or defaulted, existing columns
    // untouched whatever their current type.
    // ---------------------------------------------------------------
    SchemaGuard::ensure($db, 'written_columns_v2', function ($db) {
        $tables = [
            'employees' => [
                'department_id' => "INT DEFAULT NULL",
                'designation_id' => "INT DEFAULT NULL",
                'email' => "VARCHAR(100) DEFAULT NULL",
                'employee_code' => "VARCHAR(50) DEFAULT NULL",
                'first_name' => "VARCHAR(100) DEFAULT NULL",
                'joining_date' => "DATE DEFAULT NULL",
                'last_name' => "VARCHAR(100) DEFAULT NULL",
                'mobile' => "VARCHAR(20) DEFAULT NULL",
                'salary' => "DECIMAL(12,2) DEFAULT 0.00",
                'status' => "TINYINT(1) DEFAULT 1",
                'user_id' => "INT DEFAULT NULL",
            ],
            'leads' => [
                'customer_email' => "VARCHAR(100) DEFAULT NULL",
                'customer_name' => "VARCHAR(150) DEFAULT NULL",
                'customer_phone' => "VARCHAR(20) DEFAULT NULL",
                'employee_id' => "INT DEFAULT NULL",
                'follow_up_date' => "DATE DEFAULT NULL",
                'notes' => "TEXT DEFAULT NULL",
                'source' => "VARCHAR(100) DEFAULT NULL",
                'status' => "VARCHAR(40) DEFAULT 'new'",
            ],
            'leave_requests' => [
                'approved_by' => "INT DEFAULT NULL",
                'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                'employee_id' => "INT DEFAULT NULL",
                'leave_type' => "VARCHAR(50) DEFAULT NULL",
                'reason' => "TEXT DEFAULT NULL",
                'status' => "VARCHAR(20) DEFAULT 'Pending'",
            ],
            'notifications' => [
                'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                'department_id' => "INT DEFAULT NULL",
                'message' => "TEXT DEFAULT NULL",
                'send_to' => "VARCHAR(50) DEFAULT NULL",
                'title' => "VARCHAR(255) DEFAULT NULL",
                'type' => "VARCHAR(50) DEFAULT NULL",
            ],
            'user_sessions' => [
                'expiry_time' => "DATETIME DEFAULT NULL",
                'ip_address' => "VARCHAR(45) DEFAULT NULL",
                'is_active' => "TINYINT(1) DEFAULT 1",
                'last_activity' => "DATETIME DEFAULT NULL",
                'login_time' => "DATETIME DEFAULT NULL",
                'session_token' => "VARCHAR(255) DEFAULT NULL",
                'user_agent' => "VARCHAR(255) DEFAULT NULL",
                'user_id' => "INT DEFAULT NULL",
            ],
            'users' => [
                'api_token' => "VARCHAR(255) DEFAULT NULL",
                'email' => "VARCHAR(100) DEFAULT NULL",
                'employee_id' => "INT DEFAULT NULL",
                'password' => "VARCHAR(255) DEFAULT NULL",
                'role_id' => "INT DEFAULT NULL",
                'status' => "TINYINT(1) DEFAULT 1",
                'token_expiry' => "DATETIME DEFAULT NULL",
                'username' => "VARCHAR(50) DEFAULT NULL",
            ],
        ];

        foreach ($tables as $table => $columns) {
            SchemaGuard::addColumns($db, $table, $columns);
        }
    });

    // ---------------------------------------------------------------
    // Columns assigned a function or a literal rather than a placeholder.
    //
    // The scan that produced written_columns_v1 and _v2 read UPDATE statements
    // looking for `column = ?`, so it never saw `approved_at = NOW()` or
    // `status = 'Approved'`. Ten columns were invisible to it, and the first to
    // bite was leave_requests.approved_at: approving a leave failed outright,
    // because that one column in the field list did not exist.
    //
    // Several of the rest are on the sign-in path — users.last_login and
    // last_activity are written on every login, user_sessions.logout_time on
    // every logout — so the same failure was waiting there too.
    // ---------------------------------------------------------------
    SchemaGuard::ensure($db, 'written_columns_v3', function ($db) {
        $tables = [
            'leave_requests' => [
                'approved_at' => "DATETIME DEFAULT NULL",
            ],
            'users' => [
                'last_login'    => "DATETIME DEFAULT NULL",
                'last_activity' => "DATETIME DEFAULT NULL",
                'is_logged_in'  => "TINYINT(1) DEFAULT 0",
            ],
            'user_sessions' => [
                'logout_time' => "DATETIME DEFAULT NULL",
            ],
            'tasks' => [
                'completed_at'    => "DATETIME DEFAULT NULL",
                'completion_time' => "DATETIME DEFAULT NULL",
            ],
            'expenses' => [
                'approval_date' => "DATETIME DEFAULT NULL",
            ],
            'follow_ups' => [
                'completed_at' => "DATETIME DEFAULT NULL",
            ],
            'chat_participants' => [
                'last_read_at' => "DATETIME DEFAULT NULL",
            ],
        ];

        foreach ($tables as $table => $columns) {
            SchemaGuard::addColumns($db, $table, $columns);
        }
    });

    // ---------------------------------------------------------------
    // ENUMs too narrow for the values the code actually stores.
    //
    // An out-of-range ENUM value is not a loud failure: MySQL rejects the
    // write in strict mode and silently stores '' otherwise, so a holiday or
    // a week-off could vanish into an empty status with nothing in the log.
    // These columns hold a small vocabulary that has grown over time, so they
    // are better as VARCHAR than as an ENUM that has to be widened again.
    // ---------------------------------------------------------------
    // tasks is one of the tables defined twice with different columns. The
    // live table kept the old NOT NULL employee_id, but everything that reads
    // a task's owner — the app, the reports, the IT board — uses assigned_to,
    // and every INSERT writes only that. So creating a task failed outright
    // with "Field 'employee_id' doesn't have a default value", from both the
    // admin panel and the API. Same remedy as leads.employee_id above.
    // leave_requests carries from_date/to_date from the older of its two
    // definitions, both NOT NULL with no default, while every write uses
    // start_date/end_date. Applying for leave therefore failed outright with
    // "Field 'from_date' doesn't have a default value". The pair is legacy —
    // nothing writes it — so it is allowed to be empty rather than kept in
    // sync from two places, which would only drift.
    SchemaGuard::ensure($db, 'leave_requests_legacy_dates_nullable_v1', function ($db) {
        SchemaGuard::modifyColumn($db, 'leave_requests', 'from_date', 'DATE NULL');
        SchemaGuard::modifyColumn($db, 'leave_requests', 'to_date', 'DATE NULL');
    });

    // follow_ups.customer_name is NOT NULL, but scheduleFollowUp() does not
    // write it — the same failure on the telecaller's follow-up button.
    SchemaGuard::ensure($db, 'follow_ups_customer_name_nullable_v1', function ($db) {
        SchemaGuard::modifyColumn($db, 'follow_ups', 'customer_name', "VARCHAR(150) NULL");
    });

    // user_sessions is written on every login and read by the session-timeout
    // check, but nothing ever created it — it is only ever addColumns()'d, and
    // that is a no-op when the table is absent. The insert sat in a try/catch,
    // so it failed silently, and because the api_token update shared that try
    // block it never ran either.
    SchemaGuard::ensure($db, 'user_sessions_table_v1', function ($db) {
        SchemaGuard::reconcile($db, "CREATE TABLE IF NOT EXISTS user_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            session_token VARCHAR(512) NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            device_id VARCHAR(128) DEFAULT NULL,
            login_time DATETIME DEFAULT NULL,
            last_activity DATETIME DEFAULT NULL,
            expiry_time DATETIME DEFAULT NULL,
            logout_time DATETIME DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            INDEX idx_user_sessions_user (user_id),
            INDEX idx_user_sessions_active (is_active)
        ) ENGINE=InnoDB");
    });

    SchemaGuard::ensure($db, 'tasks_employee_id_nullable_v1', function ($db) {
        SchemaGuard::modifyColumn($db, 'tasks', 'employee_id', 'INT NULL');
    });

    // notifications.type listed five values while the code raises them for
    // leaves, tasks, expenses, leads, sales, travel, salary, notices, help and
    // documents. MySQL answers an out-of-range ENUM with a truncation warning,
    // which PDO raises as an exception — so approving a leave, which notifies
    // the employee from inside its transaction, rolled the whole thing back
    // and told the admin nothing had changed. Several of the types are built
    // at runtime, so the column has to be open rather than a longer list.
    // attendance.leave_type is an ENUM on the live database — present,
    // paid_leave, earned_leave, unpaid_leave, absent, weekly_off, holiday —
    // while approving a leave copies the request's type across verbatim
    // ('Casual Leave', 'Earned Leave', 'Leave Without Pay'). None of those are
    // members, so the insert truncated and the whole approval rolled back.
    // leave_requests.leave_type and leave_balances.leave_type are already
    // VARCHAR; this makes the third column in that chain agree with them.
    // The create-lead form collects a full address, an alternate number, a
    // follow-up type and a next action, and none of it had anywhere to go —
    // createLead() never read those keys, so the employee typed them and they
    // were dropped on the floor. follow_up_date and status already had columns
    // and were being ignored just as quietly.
    // Leads created before createLead() stopped falling back to the user id.
    // employees.id and users.id are offset by one on this database, so every
    // employee's user id is some other employee's id: a lead saved that way
    // either vanished from its creator's list or turned up in a colleague's.
    // Only rows with no employee_id are touched, and only where the user id
    // resolves to exactly one employee, so nothing is guessed.
    SchemaGuard::ensure($db, 'leads_created_by_repair_v1', function ($db) {
        try {
            $db->exec(
                "UPDATE leads l
                 JOIN employees e ON e.user_id = l.created_by
                 SET l.employee_id = e.id, l.created_by = e.id
                 WHERE l.employee_id IS NULL
                   AND l.created_by IS NOT NULL
                   AND l.created_by NOT IN (SELECT id FROM (SELECT id FROM employees) AS x)"
            );
        } catch (Throwable $e) {
            error_log('leads_created_by_repair_v1: ' . $e->getMessage());
        }
    });

    SchemaGuard::ensure($db, 'leads_form_fields_v1', function ($db) {
        SchemaGuard::addColumns($db, 'leads', [
            'address'          => "TEXT DEFAULT NULL",
            'state'            => "VARCHAR(100) DEFAULT NULL",
            'pincode'          => "VARCHAR(20) DEFAULT NULL",
            'alternate_mobile' => "VARCHAR(20) DEFAULT NULL",
            'follow_up_type'   => "VARCHAR(50) DEFAULT NULL",
            'next_action'      => "TEXT DEFAULT NULL",
        ]);
    });

    SchemaGuard::ensure($db, 'attendance_leave_type_varchar_v1', function ($db) {
        SchemaGuard::modifyColumn($db, 'attendance', 'leave_type', "VARCHAR(50) DEFAULT NULL");
    });

    SchemaGuard::ensure($db, 'notifications_type_varchar_v1', function ($db) {
        SchemaGuard::modifyColumn($db, 'notifications', 'type', "VARCHAR(40) DEFAULT 'announcement'");
    });

    SchemaGuard::ensure($db, 'status_vocabulary_varchar_v1', function ($db) {
        // markAttendance() stores the manual gradings 'holiday', 'week-off'
        // and 'weekoff'; the ENUM listed only present/late/half-day/absent/leave.
        SchemaGuard::modifyColumn($db, 'attendance', 'status', "VARCHAR(20) DEFAULT 'present'");

        // The holiday calendar writes 'weekly_off' for Sundays; the ENUM
        // allowed only 'public' and 'company'.
        SchemaGuard::modifyColumn($db, 'holidays', 'type', "VARCHAR(30) DEFAULT 'public'");

        // Leave types are stored as full labels ('Casual Leave', 'Earned
        // Leave', 'Leave Without Pay'), and leave_balances already keys on
        // that same VARCHAR. The older ENUM held only lowercase short names.
        SchemaGuard::modifyColumn($db, 'leave_requests', 'leave_type', "VARCHAR(100)");

        // Status is written capitalised ('Pending', 'Approved', 'Rejected',
        // 'Cancelled'); the older ENUM was lowercase and had no 'Cancelled'.
        SchemaGuard::modifyColumn($db, 'leave_requests', 'status', "VARCHAR(20) DEFAULT 'Pending'");

        // Rows written while the column was still a lowercase ENUM read back as
        // 'approved'; the panel and the API compare against 'Approved', and PHP
        // string comparison is case sensitive. Capitalise the stored values so
        // old and new rows agree. A no-op where they are already capitalised.
        try {
            // Rewrites every row rather than only the ones that differ: the
            // case-sensitive comparison that would narrow it needs the BINARY
            // operator, which MySQL 8.0.37 deprecated and 9.0 removed. This
            // migration records itself as applied either way, so a version
            // that choked on BINARY would leave the statuses wrong for good.
            // Setting a value to what it already is costs nothing here.
            $db->exec(
                "UPDATE leave_requests SET status = " .
                "CONCAT(UPPER(LEFT(status, 1)), LOWER(SUBSTRING(status, 2))) " .
                "WHERE status IS NOT NULL AND status <> ''"
            );
        } catch (Throwable $e) {
            error_log('SchemaGuard leave status normalise: ' . $e->getMessage());
        }
    });

    // Performance indexes for Lead ownership, Working Reports, and Notification Polling
    SchemaGuard::ensure($db, 'working_reports_lead_indexes_v1', function ($db) {
        $indexes = [
            'leads' => ['created_by', 'status', 'created_at', 'campaign_name'],
            'attendance' => ['attendance_date', 'status', 'late_minutes'],
            'notifications' => ['user_id', 'is_read', 'created_at']
        ];
        foreach ($indexes as $table => $cols) {
            foreach ($cols as $col) {
                try {
                    $db->exec("CREATE INDEX idx_{$table}_{$col} ON {$table} ({$col})");
                } catch (Throwable $e) {
                    // Index already exists or table busy
                }
            }
        }
    });
    // The admin panel and the app both offer an "Urgent" priority, and the
    // task board sends the canonical 'in_progress'. The ENUMs listed only
    // low/medium/high (and normal/high/urgent for notices), so every one of
    // those choices died on a 1265 "Data truncated" fatal instead of saving.
    // Widened rather than trimmed: the two front-ends are the spec here, and
    // a VARCHAR cannot truncate a future value the way an ENUM does.
    SchemaGuard::ensure($db, 'priority_vocabulary_varchar_v1', function ($db) {
        SchemaGuard::modifyColumn($db, 'tasks', 'priority', "VARCHAR(20) DEFAULT 'medium'");
        SchemaGuard::modifyColumn($db, 'leads', 'priority', "VARCHAR(20) DEFAULT 'medium'");
        SchemaGuard::modifyColumn($db, 'notices', 'priority', "VARCHAR(20) DEFAULT 'normal'");
        SchemaGuard::modifyColumn($db, 'tasks', 'status', "VARCHAR(20) DEFAULT 'pending'");
    });

    // campaigns carries both a legacy NOT NULL `name` and the `campaign_name`
    // every form actually fills in, so INSERTs failed with 1364 "Field 'name'
    // doesn't have a default value" — campaign creation was impossible from
    // the panel and from the app. The writers now populate both columns; this
    // relaxes the legacy column so no other path can fatal on it either.
    // The status ENUM held only active/inactive while the edit form offers the
    // full planning/paused/completed/cancelled lifecycle.
    SchemaGuard::ensure($db, 'campaigns_lifecycle_v1', function ($db) {
        SchemaGuard::modifyColumn($db, 'campaigns', 'name', "VARCHAR(255) NULL");
        SchemaGuard::modifyColumn($db, 'campaigns', 'status', "VARCHAR(20) DEFAULT 'active'");
        try {
            $db->exec("UPDATE campaigns SET name = campaign_name
                       WHERE (name IS NULL OR name = '') AND campaign_name IS NOT NULL");
        } catch (Throwable $e) {
            // Column set differs on an older copy; the writers still fill both.
        }
    });

    // expense_categories was empty and no page anywhere creates a row, so the
    // Category dropdown on the expense form only ever showed "Select" and every
    // expense was saved uncategorised. Seeds the standard set, but only when the
    // table is genuinely empty, so an existing list is never disturbed.
    SchemaGuard::ensure($db, 'expense_categories_seed_v1', function ($db) {
        try {
            $count = (int) $db->query("SELECT COUNT(*) FROM expense_categories")->fetchColumn();
            if ($count > 0) {
                return;
            }
            $stmt = $db->prepare("INSERT INTO expense_categories (name, status) VALUES (?, 1)");
            foreach ([
                'Travel', 'Fuel', 'Food & Refreshment', 'Accommodation',
                'Office Supplies', 'Marketing', 'Communication',
                'Repair & Maintenance', 'Training', 'Miscellaneous',
            ] as $name) {
                $stmt->execute([$name]);
            }
        } catch (Throwable $e) {
            // Table absent on an older copy; the form degrades to "Select".
        }
    });

    // Restored from main: seeds the roles and permissions rows the whole
    // RBAC layer reads. Without it a role can exist with no permission rows,
    // which is why some marketing staff could create leads and others could not.
    SchemaGuard::ensure($db, 'rbac_roles_permissions_v2', function ($db) {
        $roles = [
            ['name' => 'super_admin', 'description' => 'Super Admin - full system access'],
            ['name' => 'admin', 'description' => 'Admin - general system administration'],
            ['name' => 'sub_admin', 'description' => 'Sub Admin - operational administration'],
            ['name' => 'manager', 'description' => 'Manager - team management'],
            ['name' => 'employee', 'description' => 'Employee - standard employee access'],
            ['name' => 'hr_admin', 'description' => 'HR Admin - full HR and departmental overview'],
            ['name' => 'hr', 'description' => 'HR - human resources and operations'],
            ['name' => 'hr_executive', 'description' => 'HR Executive - HR and full marketing overview'],
            ['name' => 'digital_marketing_admin', 'description' => 'Digital Marketing Admin - marketing management'],
            ['name' => 'digital_marketing', 'description' => 'Digital Marketing - marketing campaigns and lead generation'],
            ['name' => 'marketing_admin', 'description' => 'Marketing Admin - field & digital marketing management'],
            ['name' => 'marketing_executive', 'description' => 'Marketing Executive - field marketing and lead generation'],
            ['name' => 'telecaller_admin', 'description' => 'Telecaller Admin - calling and lead follow-up management'],
            ['name' => 'telecaller', 'description' => 'Telecaller - lead calling and follow-up handling'],
            ['name' => 'sales_admin', 'description' => 'Sales Admin - sales management'],
            ['name' => 'sales_manager', 'description' => 'Sales Manager - sales team management'],
            ['name' => 'sales_executive', 'description' => 'Sales Executive - sales conversions'],
            ['name' => 'accounts_admin', 'description' => 'Accounts Admin - payroll and financial management'],
            ['name' => 'accounts', 'description' => 'Accounts - accounting and expenses'],
            ['name' => 'accounts_executive', 'description' => 'Accounts Executive - accounting operations'],
            ['name' => 'project_manager', 'description' => 'Project Manager - projects and task coordination'],
        ];

        $insRole = $db->prepare("INSERT INTO roles (name, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description)");
        foreach ($roles as $r) {
            $insRole->execute([$r['name'], $r['description']]);
        }

        // Fetch role IDs map
        $roleRows = $db->query("SELECT id, name FROM roles")->fetchAll();
        $roleIdMap = [];
        foreach ($roleRows as $row) {
            $roleIdMap[$row['name']] = (int)$row['id'];
        }

        $grantPerm = $db->prepare("
            INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                can_view = VALUES(can_view),
                can_create = VALUES(can_create),
                can_edit = VALUES(can_edit),
                can_delete = VALUES(can_delete)
        ");

        // Helper to grant permissions for a role
        $setPerms = function($roleName, $perms) use ($roleIdMap, $grantPerm) {
            if (!isset($roleIdMap[$roleName])) return;
            $rid = $roleIdMap[$roleName];
            foreach ($perms as $module => $flags) {
                $grantPerm->execute([
                    $rid,
                    $module,
                    $flags[0] ?? 0,
                    $flags[1] ?? 0,
                    $flags[2] ?? 0,
                    $flags[3] ?? 0,
                ]);
            }
        };

        // HR Admin & HR & HR Executive: Full Marketing Data View Access
        $hrPermissions = [
            'employees'          => [1, 1, 1, 1],
            'attendance'         => [1, 1, 1, 0],
            'leaves'             => [1, 1, 1, 1],
            'leave_requests'     => [1, 1, 1, 1],
            'departments'        => [1, 0, 0, 0],
            'designations'       => [1, 0, 0, 0],
            'daily_work_reports' => [1, 1, 1, 0],
            'work_reports'       => [1, 1, 1, 0],
            'reports'            => [1, 0, 0, 0],
            'hr_activities'      => [1, 1, 1, 1],
            'notices'            => [1, 1, 1, 0],
            'notifications'      => [1, 1, 1, 0],
            // Marketing Department Full Visibility
            'leads'              => [1, 0, 0, 0],
            'marketing'          => [1, 0, 0, 0],
            'campaigns'          => [1, 0, 0, 0],
            'call_reports'       => [1, 0, 0, 0],
            'follow_ups'         => [1, 0, 0, 0],
            'meetings'           => [1, 0, 0, 0],
            'documents'          => [1, 1, 1, 0],
            'downloads'          => [1, 1, 1, 0],
            'help'               => [1, 1, 1, 0],
        ];

        $setPerms('hr_admin', $hrPermissions);
        $setPerms('hr', $hrPermissions);
        $setPerms('hr_executive', $hrPermissions);

        // Marketing Admin
        $marketingAdminPermissions = [
            'leads'              => [1, 1, 1, 1],
            'marketing'          => [1, 1, 1, 1],
            'campaigns'          => [1, 1, 1, 1],
            'daily_work_reports' => [1, 1, 1, 0],
            'work_reports'       => [1, 1, 1, 0],
            'reports'            => [1, 0, 0, 0],
            'attendance'         => [1, 0, 0, 0],
            'employees'          => [1, 0, 0, 0],
            'notices'            => [1, 0, 0, 0],
            'notifications'      => [1, 1, 1, 0],
            'downloads'          => [1, 0, 0, 0],
            'help'               => [1, 1, 0, 0],
        ];
        $setPerms('digital_marketing_admin', $marketingAdminPermissions);
        $setPerms('marketing_admin', $marketingAdminPermissions);

        // Marketing Executive
        $marketingExecPermissions = [
            'leads'              => [1, 1, 1, 0],
            'marketing'          => [1, 1, 1, 0],
            'campaigns'          => [1, 1, 1, 0],
            'daily_work_reports' => [1, 1, 0, 0],
            'work_reports'       => [1, 1, 0, 0],
            'attendance'         => [1, 0, 0, 0],
            'leaves'             => [1, 1, 0, 0],
            'leave_requests'     => [1, 1, 0, 0],
            'travel'             => [1, 1, 0, 0],
            'expenses'           => [1, 1, 0, 0],
            'notices'            => [1, 0, 0, 0],
            'notifications'      => [1, 0, 0, 0],
            'downloads'          => [1, 0, 0, 0],
            'help'               => [1, 1, 0, 0],
        ];
        $setPerms('digital_marketing', $marketingExecPermissions);
        $setPerms('marketing_executive', $marketingExecPermissions);
        $setPerms('marketing', $marketingExecPermissions);
    });
}
