<?php
/**
 * Offer Letter & Appointment Letter Generator Module
 * Yatharth Group of Institution - EMS Admin Panel
 */

require_once __DIR__ . '/../includes/config.php';

if (!isLoggedIn()) {
    redirect(BASE_URL . 'index');
}

// ============================================================
// Self-Healing: Ensure offer_letters table & permissions exist FIRST
// ============================================================
try {
    $pdo->query("SELECT 1 FROM offer_letters LIMIT 1");
} catch (Throwable $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS offer_letters (
            id INT AUTO_INCREMENT PRIMARY KEY,
            offer_letter_no VARCHAR(100) NOT NULL UNIQUE,
            template_type VARCHAR(50) DEFAULT 'appointment_letter',
            title VARCHAR(150) DEFAULT 'APPOINTMENT LETTER',
            employee_id INT NULL,
            candidate_name VARCHAR(150) NOT NULL,
            father_name VARCHAR(150) DEFAULT NULL,
            email VARCHAR(150) DEFAULT NULL,
            mobile VARCHAR(50) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            department_id INT NULL,
            department_name VARCHAR(100) DEFAULT NULL,
            designation VARCHAR(150) NOT NULL,
            employment_type VARCHAR(50) DEFAULT 'Full Time',
            work_location VARCHAR(150) DEFAULT 'Siwan, Bihar',
            reporting_manager VARCHAR(200) DEFAULT 'Miss Pooja Pandey (Admission & Marketing Head)',
            issue_date DATE NOT NULL,
            joining_date DATE NOT NULL,
            acceptance_deadline DATE DEFAULT NULL,
            probation_period VARCHAR(100) DEFAULT '3 Months',
            working_hours VARCHAR(150) DEFAULT '09:30 AM to 06:30 PM',
            monthly_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            salary_words VARCHAR(255) DEFAULT NULL,
            target_admissions INT DEFAULT 8,
            target_revenue DECIMAL(12,2) DEFAULT 160000.00,
            target_revenue_words VARCHAR(255) DEFAULT 'Rupees One Lakh Sixty Thousand Only',
            notice_period VARCHAR(100) DEFAULT '30 Days',
            liquidated_damages DECIMAL(12,2) DEFAULT 500000.00,
            non_compete_radius VARCHAR(100) DEFAULT '150 kilometers',
            non_compete_duration VARCHAR(100) DEFAULT 'twelve (12) calendar months',
            jurisdiction_court VARCHAR(150) DEFAULT 'Siwan, Bihar',
            company_name VARCHAR(200) DEFAULT 'Yatharth Group of Institution',
            company_address TEXT DEFAULT '3rd Floor, B.G. Company Building Near Hospital More, Naya Bazar, Siwan (Bihar) - 841226',
            company_phone VARCHAR(100) DEFAULT '+91-915-396-5656',
            company_email VARCHAR(150) DEFAULT 'info@yatharthinstitution.com',
            company_website VARCHAR(150) DEFAULT 'www.yatharthinstitution.com',
            signatory_name VARCHAR(150) DEFAULT 'Pooja Pandey',
            signatory_designation VARCHAR(150) DEFAULT 'Human Resource & Recruitment Division',
            show_company_stamp TINYINT(1) DEFAULT 1,
            show_signatory_signature TINYINT(1) DEFAULT 1,
            subject VARCHAR(255) DEFAULT NULL,
            terms_and_conditions LONGTEXT NULL,
            additional_remarks TEXT NULL,
            status ENUM('draft', 'generated', 'sent', 'accepted', 'rejected', 'cancelled') DEFAULT 'draft',
            created_by INT NULL,
            updated_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_offer_emp (employee_id),
            INDEX idx_offer_status (status),
            INDEX idx_offer_issue_date (issue_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $rolesStmt = $pdo->query("SELECT id FROM roles");
        while ($r = $rolesStmt->fetch()) {
            $pdo->prepare("INSERT IGNORE INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES (?, 'offer_letters', 1, 1, 1, 1)")
                ->execute([(int)$r['id']]);
        }
    } catch (Throwable $e2) {
        error_log("offer_letters self-heal table creation: " . $e2->getMessage());
    }
}

requireModuleAccess('offer_letters');

// ============================================================
// Helper: Convert Number to Indian Words (Rupees ... Only)
// ============================================================
if (!function_exists('numberToWordsINR')) {
    function numberToWordsINR($number) {
        $number = round((float)$number);
        if ($number <= 0) return 'Rupees Zero Only';
        
        $words = [
            0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five',
            6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten',
            11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen',
            16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen',
            20 => 'Twenty', 30 => 'Thirty', 40 => 'Forty', 50 => 'Fifty',
            60 => 'Sixty', 70 => 'Seventy', 80 => 'Eighty', 90 => 'Ninety'
        ];
        
        $crore = floor($number / 10000000);
        $number %= 10000000;
        $lakh = floor($number / 100000);
        $number %= 100000;
        $thousand = floor($number / 1000);
        $number %= 1000;
        $hundred = floor($number / 100);
        $remainder = $number % 100;
        
        $res = [];
        if ($crore > 0) $res[] = trim(str_replace(['Rupees ', ' Only'], '', numberToWordsINR($crore))) . ' Crore';
        if ($lakh > 0) $res[] = trim(str_replace(['Rupees ', ' Only'], '', numberToWordsINR($lakh))) . ' Lakh';
        if ($thousand > 0) $res[] = trim(str_replace(['Rupees ', ' Only'], '', numberToWordsINR($thousand))) . ' Thousand';
        if ($hundred > 0) $res[] = $words[$hundred] . ' Hundred';
        if ($remainder > 0) {
            if ($remainder < 20) {
                $res[] = $words[$remainder];
            } else {
                $tens = floor($remainder / 10) * 10;
                $units = $remainder % 10;
                $res[] = $words[$tens] . ($units > 0 ? ' ' . $words[$units] : '');
            }
        }
        return 'Rupees ' . implode(' ', $res) . ' Only';
    }
}

// ============================================================
// Helper: Generate Unique Offer Letter Number
// ============================================================
if (!function_exists('generateNextOfferLetterNo')) {
    function generateNextOfferLetterNo($pdo) {
        try {
            $year = date('Y');
            $prefix = "YGI/AL/{$year}/";
            $stmt = $pdo->prepare("SELECT offer_letter_no FROM offer_letters WHERE offer_letter_no LIKE ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$prefix . '%']);
            $last = $stmt->fetchColumn();
            if ($last && preg_match('/\/(\d+)$/', $last, $m)) {
                $next = str_pad((int)$m[1] + 1, 4, '0', STR_PAD_LEFT);
            } else {
                $next = '0001';
            }
            return $prefix . $next;
        } catch (Throwable $e) {
            return "YGI/AL/" . date('Y') . "/0001";
        }
    }
}

// Load company logo & emblem as base64 for rock-solid print & PDF generation
$emblemFile = __DIR__ . '/../assets/yatharth_emblem.png';
$logoFile = __DIR__ . '/../assets/logo25.png';
$emblemBase64 = file_exists($emblemFile) ? 'data:image/png;base64,' . base64_encode(file_get_contents($emblemFile)) : '';
$logoBase64 = file_exists($logoFile) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoFile)) : $emblemBase64;

// Default company details
$defaultCompany = [
    'name' => 'Yatharth Group of Institution',
    'address' => '3rd Floor, B.G. Company Building Near Hospital More, Naya Bazar, Siwan (Bihar) - 841226',
    'phone' => '+91-915-396-5656',
    'email' => 'info@yatharthinstitution.com',
    'website' => 'www.yatharthinstitution.com',
    'signatory_name' => 'Pooja Pandey',
    'signatory_designation' => 'Human Resource & Recruitment Division',
];

// Fetch departments and active employees for dropdowns
$departments = [];
$employeesList = [];
try {
    $departments = $pdo->query("SELECT id, name FROM departments ORDER BY name ASC")->fetchAll();
    $employeesList = $pdo->query("SELECT e.id, e.employee_code, e.first_name, e.last_name, e.email, e.mobile, e.address, e.salary, e.joining_date, d.name AS dept_name, des.name AS des_name 
                                  FROM employees e 
                                  LEFT JOIN departments d ON d.id = e.department_id 
                                  LEFT JOIN designations des ON des.id = e.designation_id 
                                  WHERE e.status = 1 
                                  ORDER BY e.first_name ASC")->fetchAll();
} catch (Throwable $e) {
    error_log("Offer letters init dropdowns: " . $e->getMessage());
}

// ============================================================
// POST REQUEST PROCESSING (Save / Delete / Update Status)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['post_action'] ?? '';

    // 1. Save or Update Offer Letter (Draft or Final)
    if ($postAction === 'save') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $isAjax = !empty($_POST['is_ajax']);

        if ($id && !canEdit('offer_letters')) {
            if ($isAjax) { echo json_encode(['success' => false, 'message' => 'Permission denied: cannot edit offer letters']); exit; }
            setFlash('Permission denied: cannot edit offer letters', 'error');
            redirect(BASE_URL . 'modules/offer_letters');
        }
        if (!$id && !canCreate('offer_letters')) {
            if ($isAjax) { echo json_encode(['success' => false, 'message' => 'Permission denied: cannot create offer letters']); exit; }
            setFlash('Permission denied: cannot create offer letters', 'error');
            redirect(BASE_URL . 'modules/offer_letters');
        }

        $offerLetterNo = trim($_POST['offer_letter_no'] ?? '');
        if (empty($offerLetterNo)) {
            $offerLetterNo = generateNextOfferLetterNo($pdo);
        }

        // Check duplicate offer_letter_no
        $chkQuery = "SELECT id FROM offer_letters WHERE offer_letter_no = ?" . ($id ? " AND id != ?" : "");
        $chkParams = $id ? [$offerLetterNo, $id] : [$offerLetterNo];
        $chkStmt = $pdo->prepare($chkQuery);
        $chkStmt->execute($chkParams);
        if ($chkStmt->fetch()) {
            $msg = "Offer Letter Number '{$offerLetterNo}' already exists. Please choose a unique number.";
            if ($isAjax) { echo json_encode(['success' => false, 'message' => $msg]); exit; }
            setFlash($msg, 'error');
            redirect(BASE_URL . 'modules/offer_letters?action=' . ($id ? "edit&id={$id}" : 'create'));
        }

        $candidateName = trim(sanitize($_POST['candidate_name'] ?? ''));
        $designation = trim(sanitize($_POST['designation'] ?? ''));
        $issueDate = trim($_POST['issue_date'] ?? date('Y-m-d'));
        $joiningDate = trim($_POST['joining_date'] ?? date('Y-m-d'));

        if (empty($candidateName) || empty($designation)) {
            $msg = "Candidate Name and Designation are required fields.";
            if ($isAjax) { echo json_encode(['success' => false, 'message' => $msg]); exit; }
            setFlash($msg, 'error');
            redirect(BASE_URL . 'modules/offer_letters?action=' . ($id ? "edit&id={$id}" : 'create'));
        }

        $monthlySalary = (float)($_POST['monthly_salary'] ?? 0);
        $salaryWords = trim($_POST['salary_words'] ?? '');
        if (empty($salaryWords) && $monthlySalary > 0) {
            $salaryWords = numberToWordsINR($monthlySalary);
        }

        $targetRevenue = (float)($_POST['target_revenue'] ?? 160000);
        $targetRevenueWords = trim($_POST['target_revenue_words'] ?? '');
        if (empty($targetRevenueWords) && $targetRevenue > 0) {
            $targetRevenueWords = numberToWordsINR($targetRevenue);
        }

        $departmentId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $departmentName = '';
        if ($departmentId) {
            $dStmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
            $dStmt->execute([$departmentId]);
            $departmentName = $dStmt->fetchColumn() ?: '';
        }

        $status = in_array($_POST['status'] ?? '', ['draft', 'generated', 'sent', 'accepted', 'rejected', 'cancelled']) ? $_POST['status'] : 'draft';
        $currentUserId = $_SESSION['admin_id'] ?? null;

        $data = [
            'offer_letter_no'       => $offerLetterNo,
            'template_type'         => sanitize($_POST['template_type'] ?? 'appointment_letter'),
            'title'                 => sanitize($_POST['title'] ?? 'APPOINTMENT LETTER'),
            'employee_id'           => !empty($_POST['employee_id']) ? (int)$_POST['employee_id'] : null,
            'candidate_name'        => $candidateName,
            'father_name'           => sanitize($_POST['father_name'] ?? ''),
            'email'                 => sanitize($_POST['email'] ?? ''),
            'mobile'                => sanitize($_POST['mobile'] ?? ''),
            'address'               => sanitize($_POST['address'] ?? ''),
            'department_id'         => $departmentId,
            'department_name'       => $departmentName,
            'designation'           => $designation,
            'employment_type'       => sanitize($_POST['employment_type'] ?? 'Full Time'),
            'work_location'         => sanitize($_POST['work_location'] ?? 'Siwan, Bihar'),
            'reporting_manager'     => sanitize($_POST['reporting_manager'] ?? 'Miss Pooja Pandey (Admission & Marketing Head)'),
            'issue_date'            => $issueDate,
            'joining_date'          => $joiningDate,
            'acceptance_deadline'   => !empty($_POST['acceptance_deadline']) ? $_POST['acceptance_deadline'] : null,
            'probation_period'      => sanitize($_POST['probation_period'] ?? '3 Months'),
            'working_hours'         => sanitize($_POST['working_hours'] ?? '09:30 AM to 06:30 PM'),
            'monthly_salary'        => $monthlySalary,
            'salary_words'          => $salaryWords,
            'target_admissions'     => (int)($_POST['target_admissions'] ?? 8),
            'target_revenue'        => $targetRevenue,
            'target_revenue_words'  => $targetRevenueWords,
            'notice_period'         => sanitize($_POST['notice_period'] ?? '30 Days'),
            'liquidated_damages'    => (float)($_POST['liquidated_damages'] ?? 500000),
            'non_compete_radius'    => sanitize($_POST['non_compete_radius'] ?? '150 kilometers'),
            'non_compete_duration'  => sanitize($_POST['non_compete_duration'] ?? 'twelve (12) calendar months'),
            'jurisdiction_court'    => sanitize($_POST['jurisdiction_court'] ?? 'Siwan, Bihar'),
            'company_name'          => sanitize($_POST['company_name'] ?? $defaultCompany['name']),
            'company_address'       => sanitize($_POST['company_address'] ?? $defaultCompany['address']),
            'company_phone'         => sanitize($_POST['company_phone'] ?? $defaultCompany['phone']),
            'company_email'         => sanitize($_POST['company_email'] ?? $defaultCompany['email']),
            'company_website'       => sanitize($_POST['company_website'] ?? $defaultCompany['website']),
            'signatory_name'        => sanitize($_POST['signatory_name'] ?? $defaultCompany['signatory_name']),
            'signatory_designation' => sanitize($_POST['signatory_designation'] ?? $defaultCompany['signatory_designation']),
            'show_company_stamp'    => isset($_POST['show_company_stamp']) ? 1 : 0,
            'show_signatory_signature' => isset($_POST['show_signatory_signature']) ? 1 : 0,
            'subject'               => sanitize($_POST['subject'] ?? ''),
            'terms_and_conditions'  => $_POST['terms_and_conditions'] ?? null,
            'additional_remarks'    => sanitize($_POST['additional_remarks'] ?? ''),
            'status'                => $status,
        ];

        try {
            if ($id) {
                // Update
                $sql = "UPDATE offer_letters SET 
                    offer_letter_no = :offer_letter_no, template_type = :template_type, title = :title,
                    employee_id = :employee_id, candidate_name = :candidate_name, father_name = :father_name,
                    email = :email, mobile = :mobile, address = :address, department_id = :department_id,
                    department_name = :department_name, designation = :designation, employment_type = :employment_type,
                    work_location = :work_location, reporting_manager = :reporting_manager, issue_date = :issue_date,
                    joining_date = :joining_date, acceptance_deadline = :acceptance_deadline, probation_period = :probation_period,
                    working_hours = :working_hours, monthly_salary = :monthly_salary, salary_words = :salary_words,
                    target_admissions = :target_admissions, target_revenue = :target_revenue, target_revenue_words = :target_revenue_words,
                    notice_period = :notice_period, liquidated_damages = :liquidated_damages, non_compete_radius = :non_compete_radius,
                    non_compete_duration = :non_compete_duration, jurisdiction_court = :jurisdiction_court,
                    company_name = :company_name, company_address = :company_address, company_phone = :company_phone,
                    company_email = :company_email, company_website = :company_website, signatory_name = :signatory_name,
                    signatory_designation = :signatory_designation, show_company_stamp = :show_company_stamp,
                    show_signatory_signature = :show_signatory_signature, subject = :subject,
                    terms_and_conditions = :terms_and_conditions, additional_remarks = :additional_remarks,
                    status = :status, updated_by = :updated_by 
                    WHERE id = :id";
                $data['updated_by'] = $currentUserId;
                $data['id'] = $id;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($data);
                $savedId = $id;
                $flashMsg = 'Offer letter updated successfully.';
            } else {
                // Insert
                $sql = "INSERT INTO offer_letters (
                    offer_letter_no, template_type, title, employee_id, candidate_name, father_name,
                    email, mobile, address, department_id, department_name, designation, employment_type,
                    work_location, reporting_manager, issue_date, joining_date, acceptance_deadline,
                    probation_period, working_hours, monthly_salary, salary_words, target_admissions,
                    target_revenue, target_revenue_words, notice_period, liquidated_damages, non_compete_radius,
                    non_compete_duration, jurisdiction_court, company_name, company_address, company_phone,
                    company_email, company_website, signatory_name, signatory_designation, show_company_stamp,
                    show_signatory_signature, subject, terms_and_conditions, additional_remarks, status, created_by
                ) VALUES (
                    :offer_letter_no, :template_type, :title, :employee_id, :candidate_name, :father_name,
                    :email, :mobile, :address, :department_id, :department_name, :designation, :employment_type,
                    :work_location, :reporting_manager, :issue_date, :joining_date, :acceptance_deadline,
                    :probation_period, :working_hours, :monthly_salary, :salary_words, :target_admissions,
                    :target_revenue, :target_revenue_words, :notice_period, :liquidated_damages, :non_compete_radius,
                    :non_compete_duration, :jurisdiction_court, :company_name, :company_address, :company_phone,
                    :company_email, :company_website, :signatory_name, :signatory_designation, :show_company_stamp,
                    :show_signatory_signature, :subject, :terms_and_conditions, :additional_remarks, :status, :created_by
                )";
                $data['created_by'] = $currentUserId;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($data);
                $savedId = (int)$pdo->lastInsertId();
                $flashMsg = 'Offer letter created successfully.';
            }

            if ($isAjax) {
                echo json_encode([
                    'success' => true,
                    'id' => $savedId,
                    'offer_letter_no' => $offerLetterNo,
                    'status' => $status,
                    'message' => $flashMsg
                ]);
                exit;
            }

            setFlash($flashMsg, 'success');
            redirect(BASE_URL . "modules/offer_letters?action=view&id={$savedId}");
        } catch (Exception $e) {
            $errMsg = 'Error saving offer letter: ' . $e->getMessage();
            if ($isAjax) { echo json_encode(['success' => false, 'message' => $errMsg]); exit; }
            setFlash($errMsg, 'error');
            redirect(BASE_URL . 'modules/offer_letters?action=' . ($id ? "edit&id={$id}" : 'create'));
        }
    }

    // 2. Delete Offer Letter
    if ($postAction === 'delete') {
        requireDelete('offer_letters');
        $delId = (int)($_POST['id'] ?? 0);
        if ($delId > 0) {
            $pdo->prepare("DELETE FROM offer_letters WHERE id = ?")->execute([$delId]);
            setFlash('Offer letter deleted successfully.', 'success');
        }
        redirect(BASE_URL . 'modules/offer_letters');
    }

    // 3. Quick Status Update
    if ($postAction === 'update_status') {
        $id = (int)($_POST['id'] ?? 0);
        $newStatus = $_POST['status'] ?? 'draft';
        if ($id > 0 && in_array($newStatus, ['draft', 'generated', 'sent', 'accepted', 'rejected', 'cancelled'])) {
            $pdo->prepare("UPDATE offer_letters SET status = ?, updated_by = ? WHERE id = ?")
                ->execute([$newStatus, $_SESSION['admin_id'] ?? null, $id]);
            setFlash('Status updated to ' . ucfirst($newStatus), 'success');
        }
        $redirectUrl = !empty($_POST['redirect_to']) ? $_POST['redirect_to'] : BASE_URL . 'modules/offer_letters';
        redirect($redirectUrl);
    }
}

// Router actions
$action = $_GET['action'] ?? 'list';
$currentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ============================================================
// STANDALONE PRINT VIEW (Pure Clean A4 Sheet)
// ============================================================
if ($action === 'print' && $currentId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM offer_letters WHERE id = ?");
    $stmt->execute([$currentId]);
    $doc = $stmt->fetch();
    if (!$doc) { die("Document not found"); }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($doc['title'] . ' - ' . $doc['offer_letter_no']); ?></title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <link href="<?php echo BASE_URL; ?>css/style.css?v=<?php echo @filemtime(__DIR__ . '/../css/style.css') ?: '1'; ?>" rel="stylesheet">
        <style>
            body { background: #ffffff !important; margin: 0; padding: 0; }
            .a4-page-sheet { box-shadow: none !important; margin: 0 auto !important; }
        </style>
    </head>
    <body onload="window.print()">
        <div id="printDocContent">
            <?php renderAppointmentLetterPages($doc, $emblemBase64, false); ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================
// HTML RENDERING HELPER: Renders the 5-Page Document
// ============================================================
if (!function_exists('renderAppointmentLetterPages')) {
function renderAppointmentLetterPages($d, $emblemBase64, $isInteractive = true) {
    $cName = htmlspecialchars($d['candidate_name'] ?? 'Candidate Name');
    $fName = htmlspecialchars($d['father_name'] ?? '');
    $address = nl2br(htmlspecialchars($d['address'] ?? ''));
    $mobile = htmlspecialchars($d['mobile'] ?? '');
    $joiningDate = !empty($d['joining_date']) ? date('jS F, Y', strtotime($d['joining_date'])) : '24th August, 2026';
    $issueDate = !empty($d['issue_date']) ? date('d/m/Y', strtotime($d['issue_date'])) : date('d/m/Y');
    $designation = htmlspecialchars($d['designation'] ?? 'SENIOR ADMISSION FIELD MARKETING EXECUTIVE');
    $designationUpper = strtoupper($designation);
    $mgr = htmlspecialchars($d['reporting_manager'] ?? 'Miss Pooja Pandey (Admission & Marketing Head)');
    // Extract short manager name
    $mgrShort = preg_match('/^(Miss|Mr\.|Mrs\.|Dr\.)?\s*([^(\n\r]+)/', $mgr, $mm) ? trim($mm[0]) : $mgr;
    $location = htmlspecialchars($d['work_location'] ?? 'Siwan district and surrounding zones of Bihar');
    $salary = number_format((float)($d['monthly_salary'] ?? 15000), 2);
    $salaryWords = htmlspecialchars($d['salary_words'] ?? 'Rupees Fifteen Thousand Only');
    $targetAdm = (int)($d['target_admissions'] ?? 8);
    $targetRev = number_format((float)($d['target_revenue'] ?? 160000), 2);
    $targetRevWords = htmlspecialchars($d['target_revenue_words'] ?? 'Rupees One Lakh Sixty Thousand Only');
    $compName = htmlspecialchars($d['company_name'] ?? 'Yatharth Group of Institution');
    $compNameUpper = strtoupper($compName);
    $compAddress = htmlspecialchars($d['company_address'] ?? '3rd Floor, B.G. Company Building Near Hospital More, Naya Bazar, Siwan (Bihar) - 841226');
    $compPhone = htmlspecialchars($d['company_phone'] ?? '+91-915-396-5656');
    $compEmail = htmlspecialchars($d['company_email'] ?? 'info@yatharthinstitution.com');
    $compWeb = htmlspecialchars($d['company_website'] ?? 'www.yatharthinstitution.com');
    $sigName = htmlspecialchars($d['signatory_name'] ?? 'Pooja Pandey');
    $sigDesig = htmlspecialchars($d['signatory_designation'] ?? 'Human Resource & Recruitment Division');
    $docTitle = htmlspecialchars($d['title'] ?? 'APPOINTMENT LETTER');
    $noticePeriod = htmlspecialchars($d['notice_period'] ?? '30 Days');
    $liquidatedDamages = number_format((float)($d['liquidated_damages'] ?? 500000), 2);
    $nonCompeteRadius = htmlspecialchars($d['non_compete_radius'] ?? '150 kilometers');
    $nonCompeteDuration = htmlspecialchars($d['non_compete_duration'] ?? 'twelve (12) calendar months');
    $court = htmlspecialchars($d['jurisdiction_court'] ?? 'Siwan, Bihar');
    $showStamp = !empty($d['show_company_stamp']);
    $showSig = !empty($d['show_signatory_signature']);
    $candInitials = '';
    $candInitialSig = '';

    // Reusable Header
    $headerHtml = '
    <div class="a4-page-header">
        <div class="brand-area">
            <img src="' . $emblemBase64 . '" class="brand-logo-img" alt="Emblem">
            <div class="brand-title">' . $compNameUpper . '</div>
        </div>
        <div class="contact-area">
            <div><i class="fas fa-phone-alt me-1"></i> ' . $compPhone . '</div>
            <div><i class="fas fa-envelope me-1"></i> ' . $compEmail . '</div>
        </div>
    </div>';

    // Reusable Footer
    $footerHtml = '
    <div class="a4-page-footer">
        ' . $compAddress . '
    </div>';

    // Reusable SVG Rubber Stamp
    $stampHtml = '
    <div class="official-stamp-seal" style="width:125px;height:125px;">
        <svg viewBox="0 0 160 160" width="125" height="125" style="transform: rotate(-6deg);">
            <circle cx="80" cy="80" r="76" fill="none" stroke="#1d4ed8" stroke-width="2.5" opacity="0.88"/>
            <circle cx="80" cy="80" r="71" fill="none" stroke="#1d4ed8" stroke-width="1.1" opacity="0.88"/>
            <circle cx="80" cy="80" r="49" fill="none" stroke="#1d4ed8" stroke-width="0.9" opacity="0.75"/>
            <path id="stamp-top-arc" d="M 18,80 A 62,62 0 1,1 142,80" fill="none"/>
            <text font-family="Times New Roman, serif" font-size="9.5" font-weight="bold" fill="#1d4ed8" letter-spacing="1px">
                <textPath href="#stamp-top-arc" startOffset="50%" text-anchor="middle">Yatharth Group of Institution</textPath>
            </text>
            <path id="stamp-bot-arc" d="M 142,80 A 62,62 0 0,1 18,80" fill="none"/>
            <text font-family="Times New Roman, serif" font-size="8" font-weight="bold" fill="#1d4ed8" letter-spacing="0.8px">
                <textPath href="#stamp-bot-arc" startOffset="50%" text-anchor="middle">' . strtolower($compWeb) . '</textPath>
            </text>
            <text x="80" y="64" font-family="Georgia, serif" font-size="12" font-weight="bold" fill="#1d4ed8" text-anchor="middle">यथार्थ</text>
            <text x="80" y="76" font-family="Times New Roman, serif" font-size="7.5" font-weight="bold" fill="#1d4ed8" text-anchor="middle">3rd Floor, B.G Company</text>
            <text x="80" y="86" font-family="Times New Roman, serif" font-size="7.5" font-weight="bold" fill="#1d4ed8" text-anchor="middle">Building, Naya Bazar</text>
            <text x="80" y="96" font-family="Times New Roman, serif" font-size="8" font-weight="bold" fill="#1d4ed8" text-anchor="middle">Siwan - 841226</text>
            <text x="80" y="106" font-family="Times New Roman, serif" font-size="7.5" font-weight="bold" fill="#1d4ed8" text-anchor="middle">(Bihar)</text>
        </svg>
        <div class="sig-overlay">' . $sigName . '</div>
    </div>';
    ?>

    <!-- ================= PAGE 1 ================= -->
    <div class="a4-page-sheet" id="page-sheet-1">
        <?php echo $headerHtml; ?>
        <div class="a4-page-body">
            <div class="doc-main-title"><?php echo $docTitle; ?></div>

            <div class="mb-3" style="font-size: 10pt; line-height: 1.45;">
                <div>To,</div>
                <div class="fw-bold" style="font-size: 11pt;"><?php echo $cName; ?></div>
                <?php if ($fName): ?>
                <div><span class="fw-bold">S/o:</span> <?php echo $fName; ?></div>
                <?php endif; ?>
                <?php if ($address): ?>
                <div><span class="fw-bold">Permanent Address:</span> <?php echo $address; ?></div>
                <?php endif; ?>
                <?php if ($mobile): ?>
                <div><span class="fw-bold">Contact Number:</span> <?php echo $mobile; ?></div>
                <?php endif; ?>
                <div><span class="fw-bold">Joining Date:</span> <?php echo $joiningDate; ?></div>
            </div>

            <div class="fw-bold mb-3" style="font-size: 10.5pt; text-transform: uppercase;">
                SUBJECT: APPOINTMENT LETTER FOR THE POSITION OF "<?php echo $designationUpper; ?>"
            </div>

            <p><strong>Dear <?php echo $cName; ?>,</strong></p>
            <p>
                With reference to your application, subsequent personal interview, and the field competency evaluation rounds you conducted with the Selection Committee of <strong><?php echo $compName; ?></strong> (hereinafter referred to as the <strong>"Institution"</strong>, <strong>"Management"</strong>, or <strong>"Employer"</strong>), we are pleased to offer you the position of <strong><?php echo $designation; ?></strong> under the following strictly non-negotiable terms, institutional norms, work disciplines, and performance-linked conditions.
            </p>

            <div class="doc-section-title">DEFINITIONS, REPORTING HIERARCHY &amp; CORE FIELD RESPONSIBILITIES</div>

            <div class="doc-sub-heading">1. INTERPRETATION &amp; DEFINITIONS</div>
            <ul class="list-unstyled ps-2 mb-2">
                <li>• <strong>"Employer/Management"</strong> shall mean the Board of Directors, Managing Trustees, HR Department, and authorized representatives of <?php echo $compName; ?>.</li>
                <li>• <strong>"Employee"</strong> shall mean <?php echo $cName; ?>.</li>
                <li>• <strong>"Target"</strong> shall mean the minimum number of confirmed, fee-paid student admissions and total revenue allocated to the employee for every academic cycle/month.</li>
            </ul>

            <div class="doc-sub-heading">2. OFFICIAL REPORTING HIERARCHY</div>
            <ul class="list-unstyled ps-2 mb-2">
                <li>• <strong>Designated Reporting Officer:</strong> Your immediate supervisor and administrative authority for all operational purposes will be <strong><?php echo $mgr; ?></strong>.</li>
                <li>• <strong>Command and Authority:</strong> You are required to work directly under the command, guidance, and supervision of <strong><?php echo $mgrShort; ?></strong>. Any official communication, leave approval, tour planning, or dispute resolution must be routed strictly through her office.</li>
                <li>• <strong>Compliance:</strong> Failure to follow the direct instructions, strategies, or field allocations given by <strong><?php echo $mgrShort; ?></strong> will be legally treated as insubordination and breach of employment terms, inviting immediate disciplinary action.</li>
            </ul>

            <div class="doc-section-title">CORE RESPONSIBILITIES &amp; KEY PERFORMANCE AREAS (KPAs)</div>
            <p class="mb-0">
                Your profile is <strong>100% field-based physical marketing</strong>. You are required to actively operate in rural, semi-urban, and urban territories, with a primary focus on the <?php echo $location; ?>, or any other region assigned by <strong><?php echo $mgrShort; ?></strong>. Your daily responsibilities include:
            </p>

            <!-- Bottom Stamp & Signature on Page 1 -->
            <div class="d-flex justify-content-end align-items-center gap-4 mt-3">
                <?php if ($showStamp): echo $stampHtml; endif; ?>
            </div>
        </div>
        <div class="page-number-tag">Page 1 of 5</div>
        <?php echo $footerHtml; ?>
    </div>

    <!-- ================= PAGE 2 ================= -->
    <div class="a4-page-sheet" id="page-sheet-2">
        <?php echo $headerHtml; ?>
        <div class="a4-page-body">
            <ul class="list-unstyled ps-2 mb-3">
                <li>• <strong>Extensive Rural &amp; Ground Penetration:</strong> Conducting rigorous door-to-door and village-to-village visits in rural areas (including Siwan, Barharia and entire assigned blocks) to identify prospective students for Paramedical, Fire Safety, IT, and other vocational courses offered by the Institution.</li>
                <li>• <strong>Canopy &amp; BTL Activity Execution:</strong> Erecting promotional canopies, stalls, and institutional helpdesks in prominent village squares, local markets (Haat-Bazaars), and near educational institutions to harvest high-quality student leads.</li>
                <li>• <strong>Career Counselling Seminars:</strong> Planning, organizing, and executing independent career counselling seminars, presentations, and workshops in local high schools, intermediate colleges, and private coaching centres.</li>
                <li>• <strong>Agent &amp; Referral Network Development:</strong> Identifying, on boarding, and managing local education consultants, field agents, village influencers, and school teachers to create a sustainable pipeline for student admissions.</li>
            </ul>

            <div class="doc-section-title">REMUNERATION STRUCTURE, GRACE PERIOD &amp; SALARY DEDUCTION / HOLD POLICY</div>

            <div class="doc-sub-heading">4. REMUNERATION &amp; COMPENSATION PACKAGE</div>
            <ul class="list-unstyled ps-2 mb-3">
                <li>• Your Fixed Gross Monthly Salary is structured at <strong>INR <?php echo $salary; ?>/- (<?php echo $salaryWords; ?>)</strong>.</li>
                <li>• This salary is inclusive of basic salary, house rent allowance, and basic field allowances. Statutory deductions such as TDS or Professional Tax (if applicable) will be deducted at source.</li>
                <li>• Any additional fuel/conveyance allowance or performance incentives will be governed strictly by the written institutional dynamic incentive policy issued at the start of the admission cycle.</li>
            </ul>

            <div class="doc-section-title">5. PERFORMANCE-LINKED SALARY CLAUSES &amp; DEDUCTION NORMS (CRITICAL CLAUSE)</div>
            <p>
                As a core commercial representative of the institution, your compensation is strictly tied to your field accountability. The employee explicitly agrees to the following legal enforcement:
            </p>
            <ul class="list-unstyled ps-2 mb-2">
                <li>• <strong>First Month Protection (Grace Period):</strong> Recognizing that territory setup, agent creation, and database building require an initial lead time, <strong>the first month of your employment (from the date of joining) will be treated as a Grace Period</strong>. Your fixed salary of INR <?php echo $salary; ?>/- will be paid in full for the first month without any performance deductions, provided you submit authenticated daily field proofs and verified data to <strong><?php echo $mgrShort; ?></strong>.</li>
                <li>• <strong>Subsequent Months (Second Month Onwards) Enforcement:</strong> From the second month of employment onwards, your continuation and full salary disbursement are entirely performance-dependent.</li>
                <li>• <strong>Mandatory Monthly Target:</strong> You are legally bound to deliver a minimum of <strong><?php echo $targetAdm; ?> Confirmed Admissions</strong> per calendar month, which must translate into a minimum institutional fee collection amount of <strong>INR <?php echo $targetRev; ?>/- (<?php echo $targetRevWords; ?>)</strong>.</li>
                <li>• <strong>Salary Hold &amp; Deduction Rights:</strong> If you fail to meet the minimum baseline target of <?php echo $targetAdm; ?> admissions or the absolute revenue metric of INR <?php echo $targetRev; ?>/- as evaluated by <strong><?php echo $mgrShort; ?></strong> in any month from the second month onwards, the Management reserves the absolute, unilateral legal right to:
                    <ol class="ps-3 mt-1 mb-1">
                        <li>Place your salary on <strong>HOLD</strong> until the minimum milestone leads/admissions are converted.</li>
                        <li>Deduct your salary proportionally (up to 50% or more) in direct ratio to your target deficit.</li>
                        <li>Forfeit any pending allowances if no field output/admissions are recorded.</li>
                    </ol>
                </li>
                <li>• The employee hereby waives any right to legally dispute or object to such salary hold or deduction, acknowledging that the institution cannot sustain non-performing operational expenses.</li>
            </ul>

            <!-- Bottom Stamp & Signature on Page 2 -->
            <div class="d-flex justify-content-end align-items-center gap-4 mt-3">
                <?php if ($showStamp): echo $stampHtml; endif; ?>
            </div>
        </div>
        <div class="page-number-tag">Page 2 of 5</div>
        <?php echo $footerHtml; ?>
    </div>

    <!-- ================= PAGE 3 ================= -->
    <div class="a4-page-sheet" id="page-sheet-3">
        <?php echo $headerHtml; ?>
        <div class="a4-page-body">
            <div class="doc-section-title">FIELD MONITORING, DAILY REPORTING STANDARDS &amp; NORMS</div>

            <div class="doc-sub-heading">6. REAL-TIME FIELD MONITORING &amp; REPORTING MANDATES</div>
            <ul class="list-unstyled ps-2 mb-3">
                <li>• <strong>Daily Attendance &amp; GPS Tracking:</strong> You are required to log your attendance through the official Employee App or via real-time GPS location sharing directly with <strong><?php echo $mgrShort; ?></strong> at the start and end of your field shift.</li>
                <li>• <strong>Geotagged Proofs:</strong> For every canopy activity, seminar, or poster-pasting drive, you must upload high-resolution, time-stamped, and geotagged photographs to the reporting portal and verify it over text/call with <strong><?php echo $mgrShort; ?></strong>. Failure to do so will result in that day being marked as <strong>"Absent/Loss of Pay (LOP)"</strong>.</li>
                <li>• <strong>Daily Activity Report (DAR) Submission:</strong> You must submit a digital or physical copy of the Daily Activity Report containing the names, verified mobile numbers, streams, and parental data of a minimum of 10 prospective students counseled daily. This report must be checked and signed off by <strong><?php echo $mgrShort; ?></strong> every single evening.</li>
            </ul>

            <div class="doc-section-title">7. INSTITUTIONAL CODE OF CONDUCT &amp; DISCIPLINE</div>
            <ul class="list-unstyled ps-2 mb-3">
                <li>• <strong>Professional Representation:</strong> You shall maintain the highest level of integrity and professional decorum. You are prohibited from making false promises, fake fee commitments, or unauthorized scholarship guarantees to students or parents.</li>
                <li>• <strong>Anti-Poaching &amp; Direct Collection Prohibition:</strong> You are strictly forbidden from collecting any cash, online application fees, or tokens directly from students into your personal bank account. All financial transactions must be routed through the official bank account/counters of <strong><?php echo $compName; ?></strong>. Any violation will be treated as a criminal breach of trust.</li>
                <li>• <strong>Absconding Policy:</strong> Remaining absent from the assigned field territory or failing to report your daily activity to <strong><?php echo $mgrShort; ?></strong> for three (3) consecutive days without prior written approval will automatically classify you as "Absconded from Duty," leading to immediate termination and freezing of accounts.</li>
            </ul>

            <div class="doc-section-title">CONFIDENTIALITY, NON-COMPETE, AND ANTI-BARGAINING PROTECTION</div>

            <div class="doc-sub-heading">8. NON-DISCLOSURE OF CONFIDENTIAL DATA</div>
            <ul class="list-unstyled ps-2 mb-2">
                <li>• During your field operations, you will compile, access, and generate sensitive data, including student contact lists, agent structures, pricing matrices, institutional brochures, and strategy modules.</li>
                <li>• You explicitly agree that all such information is the exclusive intellectual and commercial property of <strong><?php echo $compName; ?></strong>.</li>
            </ul>

            <!-- Bottom Stamp & Signature on Page 3 -->
            <div class="d-flex justify-content-end align-items-center gap-4 mt-4">
                <?php if ($showStamp): echo $stampHtml; endif; ?>
            </div>
        </div>
        <div class="page-number-tag">Page 3 of 5</div>
        <?php echo $footerHtml; ?>
    </div>

    <!-- ================= PAGE 4 ================= -->
    <div class="a4-page-sheet" id="page-sheet-4">
        <?php echo $headerHtml; ?>
        <div class="a4-page-body">
            <ul class="list-unstyled ps-2 mb-3">
                <li>• You shall not, during or after your employment, disclose, duplicate, transfer, or sell this database to any competitor educational group, coaching institute, or third-party marketing agency. Breach of this clause will invoke an immediate financial liability of <strong>INR <?php echo $liquidatedDamages; ?>/-</strong> payable by you to the institution as liquidated damages.</li>
            </ul>

            <div class="doc-section-title">9. ANTI-BARGAINING &amp; EMPLOYEE WAIVER CLAUSE (LEGAL PROTECTION)</div>
            <ul class="list-unstyled ps-2 mb-3">
                <li>• <strong>Waiver of Claims:</strong> <?php echo $cName; ?> explicitly agrees, confirms, and signs that he has completely read, understood, and consented to the performance-salary linkage clause outlined in Section 5.</li>
                <li>• <strong>Estoppel:</strong> The employee agrees that if their employment is terminated or their salary is held/deducted due to non-achievement of targets as reported by <strong><?php echo $mgrShort; ?></strong> from the second month onwards, they are legally estopped (barred) from claiming any compensation, moving to labor courts for full salary recovery, or staging any protest against the management.</li>
                <li>• The employee accepts that this contract represents a strictly professional commercial-marketing alliance where compensation is balanced against actual field conversion.</li>
            </ul>

            <div class="doc-section-title">10. NON-COMPETE RESTRICTIONS</div>
            <p>
                In the event of your resignation or termination, you are legally restricted from joining, consulting, or operating for any competitor institute offering Paramedical, Fire Safety, or similar vocational programs within a radius of <strong><?php echo $nonCompeteRadius; ?></strong> from your assigned territories for a minimum duration of <strong><?php echo $nonCompeteDuration; ?></strong>.
            </p>

            <div class="doc-section-title">LEGAL INDEMNITY, TERMINATION, AND JURISDICTION</div>

            <div class="doc-sub-heading">11. INDEMNITY CLAUSE</div>
            <p>
                You shall indemnify, protect, and hold harmless the Management, Trustees, and Directors of <strong><?php echo $compName; ?></strong> from and against any claims, losses, civil liabilities, police cases, or disputes arising out of your personal misconduct, misrepresentation, rash driving during field visits, local fights, or unauthorized financial dealings with local agents or publics.
            </p>

            <div class="doc-sub-heading">12. TERMINATION MECHANISM</div>
            <ul class="list-unstyled ps-2 mb-2">
                <li>• <strong>During Probationary Period:</strong> The Management, upon the official performance evaluation report of <strong><?php echo $mgrShort; ?></strong>, reserves the absolute right to terminate your employment at any moment without any prior notice, notice period, or assignment of reasons, if your field performance is found zero or unsatisfactory.</li>
            </ul>

            <!-- Bottom Stamp & Signature on Page 4 -->
            <div class="d-flex justify-content-end align-items-center gap-4 mt-3">
                <?php if ($showStamp): echo $stampHtml; endif; ?>
            </div>
        </div>
        <div class="page-number-tag">Page 4 of 5</div>
        <?php echo $footerHtml; ?>
    </div>

    <!-- ================= PAGE 5 ================= -->
    <div class="a4-page-sheet" id="page-sheet-5">
        <?php echo $headerHtml; ?>
        <div class="a4-page-body">
            <ul class="list-unstyled ps-2 mb-3">
                <li>• <strong>Termination for Cause:</strong> The Management can terminate your services with Immediate Effect, forfeiting all pending balances, if you are found guilty of:
                    <ul class="list-unstyled ps-3 mt-1 mb-1" style="list-style-type: circle;">
                        <li>o Financial fraud, embezzlement, or taking bribes from agents/students.</li>
                        <li>o Submitting fabricated lead data, fake seminar sheets, or forged expense bills to <strong><?php echo $mgrShort; ?></strong>.</li>
                        <li>o Criminal behavior, intoxication during field duty, or defaming the Institution on public social media platforms.</li>
                    </ul>
                </li>
                <li>• <strong>Resignation:</strong> If you wish to resign, you must submit a formal resignation letter addressed to the HR Department through <strong><?php echo $mgrShort; ?></strong> with a mandatory <strong><?php echo $noticePeriod; ?></strong> advance written notice or surrender 1 month's salary in lieu of notice.</li>
            </ul>

            <div class="doc-section-title">13. GOVERNING LAW &amp; EXCLUSIVE JURISDICTION</div>
            <p class="mb-3">
                This appointment contract shall be governed, interpreted, and enforced strictly in accordance with the statutory laws of the Republic of India. In the event of any irreconcilable legal dispute, litigation, class-action, or claim arising out of this contract, the exclusive territorial jurisdiction shall lie solely within the competent civil courts of <strong><?php echo $court; ?></strong>. No court outside the territorial limits of <?php echo $court; ?> shall have authority to entertain any application or suit filed by either party.
            </p>

            <div class="doc-section-title">DECLARATION &amp; UNCONDITIONAL ACCEPTANCE</div>
            <p class="mb-4">
                I, <strong><?php echo $cName; ?></strong>, <?php echo $fName ? 'S/o Mr. <strong>' . $fName . '</strong>, ' : ''; ?>resident of <strong><?php echo $address ?: $location; ?></strong>, do hereby solemnly declare that I have read this entire 5-page Appointment Letter (or it has been read out and explained to me in my native language). I completely understand my reporting line under <strong><?php echo $mgrShort; ?></strong>, all the rigid norms, daily field duties, and the target-salary deduction rules. I accept this appointment unconditionally and pledge to abide by all the clauses with absolute loyalty and discipline.
            </p>

            <!-- Signature & Acceptance Sign-off Section -->
            <div class="row align-items-end mt-4">
                <div class="col-6">
                    <div class="mb-3">
                        <span class="fw-bold d-block mb-4">Full Signature of the Employee:</span>
                        <div class="border-bottom border-dark" style="width: 250px;"></div>
                    </div>
                    <div class="mt-2"><span class="fw-bold">Name of the Employee:</span> <?php echo $cName; ?></div>

                    <div class="mt-3">
                        <span class="fw-bold d-block mb-1">Thumb Impression:</span>
                        <div class="thumb-impression-box">
                            <i class="fas fa-fingerprint fa-2x mb-1 text-secondary opacity-75"></i>
                            <span>Thumb Impression</span>
                        </div>
                    </div>

                    <div class="d-flex gap-4 mt-3 small fw-bold">
                        <div>Date: <?php echo $issueDate; ?></div>
                        <div>Place: <?php echo $court; ?></div>
                    </div>
                </div>

                <div class="col-6 text-end">
                    <div class="small fw-bold">For &amp; On Behalf of the Management,</div>
                    <div class="fw-bold text-uppercase mt-1 mb-2" style="font-size: 11pt;"><?php echo $compNameUpper; ?></div>

                    <div class="d-flex justify-content-end align-items-center gap-3 my-2">
                        <?php if ($showStamp): echo $stampHtml; endif; ?>
                    </div>

                    <div class="fw-bold" style="font-size: 11pt;"><?php echo $sigName; ?></div>
                    <div class="fw-semibold small"><?php echo $compName; ?></div>
                    <div class="text-muted small"><?php echo $sigDesig; ?></div>
                </div>
            </div>
        </div>
        <div class="page-number-tag">Page 5 of 5</div>
        <?php echo $footerHtml; ?>
    </div>
    <?php
}
}

// ============================================================
// VIEW ACTION: Dedicated Full View Document
// ============================================================
if ($action === 'view') {
    $stmt = $pdo->prepare("SELECT * FROM offer_letters WHERE id = ?");
    $stmt->execute([$currentId]);
    $doc = $stmt->fetch();
    if (!$doc) {
        setFlash('Offer letter not found', 'error');
        redirect(BASE_URL . 'modules/offer_letters');
    }

    require_once __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
        <div class="d-flex align-items-center gap-2">
            <a href="<?php echo BASE_URL; ?>modules/offer_letters" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i>Back to List
            </a>
            <h4 class="fw-bold mb-0">
                <i class="fas fa-file-signature text-primary me-2"></i><?php echo htmlspecialchars($doc['title'] . ' #' . $doc['offer_letter_no']); ?>
            </h4>
            <span class="badge bg-<?php 
                echo $doc['status'] === 'accepted' ? 'success' : ($doc['status'] === 'generated' ? 'primary' : ($doc['status'] === 'sent' ? 'info' : 'secondary')); 
            ?> text-uppercase px-2 py-1"><?php echo $doc['status']; ?></span>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if (canEdit('offer_letters')): ?>
            <a href="<?php echo BASE_URL; ?>modules/offer_letters?action=edit&id=<?php echo $doc['id']; ?>" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-edit me-1"></i>Edit Letter
            </a>
            <?php endif; ?>
            <button class="btn btn-primary btn-sm" onclick="downloadPdfNow('<?php echo sanitize($doc['offer_letter_no']); ?>')">
                <i class="fas fa-file-pdf me-1"></i>Download PDF
            </button>
            <a href="<?php echo BASE_URL; ?>modules/offer_letters?action=print&id=<?php echo $doc['id']; ?>" target="_blank" class="btn btn-success btn-sm">
                <i class="fas fa-print me-1"></i>Print Letter
            </a>
            <div class="dropdown">
                <button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                    Status: <?php echo ucfirst($doc['status']); ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <?php foreach (['draft', 'generated', 'sent', 'accepted', 'rejected', 'cancelled'] as $st): ?>
                    <li>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="post_action" value="update_status">
                            <input type="hidden" name="id" value="<?php echo $doc['id']; ?>">
                            <input type="hidden" name="status" value="<?php echo $st; ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo BASE_URL . "modules/offer_letters?action=view&id={$doc['id']}"; ?>">
                            <button type="submit" class="dropdown-item <?php echo $doc['status'] === $st ? 'active' : ''; ?>">
                                Mark as <?php echo ucfirst($st); ?>
                            </button>
                        </form>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>

    <?php displayFlash(); ?>

    <div class="offer-preview-wrapper" id="previewWrapper">
        <div class="offer-preview-zoom-container" id="printableOfferDoc">
            <?php renderAppointmentLetterPages($doc, $emblemBase64, false); ?>
        </div>
    </div>

    <script src="<?php echo BASE_URL; ?>js/html2pdf.bundle.min.js"></script>
    <script>
    function downloadPdfNow(num) {
        const element = document.getElementById('printableOfferDoc');
        const filename = 'Appointment_Letter_' + (num || 'Document').replace(/[\/\\?%*:|"<>]/g, '_') + '.pdf';
        const opt = {
            margin:       [0, 0, 0, 0],
            filename:     filename,
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2, useCORS: true, logging: false },
            jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' },
            pagebreak:    { mode: ['css', 'legacy'] }
        };
        html2pdf().set(opt).from(element).save();
    }
    </script>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// ============================================================
// CREATE & EDIT ACTIONS: Split-Screen Form + Live Preview
// ============================================================
if ($action === 'create' || $action === 'edit') {
    $editDoc = null;
    if ($action === 'edit' && $currentId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM offer_letters WHERE id = ?");
        $stmt->execute([$currentId]);
        $editDoc = $stmt->fetch();
        if (!$editDoc) {
            setFlash('Offer letter not found', 'error');
            redirect(BASE_URL . 'modules/offer_letters');
        }
    }

    // Default values if creating new
    $formData = $editDoc ?: [
        'id'                    => '',
        'offer_letter_no'       => generateNextOfferLetterNo($pdo),
        'template_type'         => 'appointment_letter',
        'title'                 => 'APPOINTMENT LETTER',
        'employee_id'           => '',
        'candidate_name'        => 'Md. Afroz Ansari',
        'father_name'           => 'Md. Shahabuddin Ansari',
        'email'                 => 'afroz.ansari@yatharth.com',
        'mobile'                => '8262836979',
        'address'               => "Hindustan Rice Mill At Yergaon Po Pipari Dixit Tah Mul, Yergaon Alias Belgaon, PO Bembal, Dist: Chandrapur, Maharastra",
        'department_id'         => 3,
        'department_name'       => 'Sales & Marketing',
        'designation'           => 'Senior Admission Field Marketing Executive',
        'employment_type'       => 'Full Time',
        'work_location'         => 'Siwan district and surrounding zones of Bihar',
        'reporting_manager'     => 'Miss Pooja Pandey (Admission & Marketing Head)',
        'issue_date'            => date('Y-m-d'),
        'joining_date'          => '2026-08-24',
        'acceptance_deadline'   => date('Y-m-d', strtotime('+7 days')),
        'probation_period'      => '3 Months',
        'working_hours'         => '09:30 AM to 06:30 PM',
        'monthly_salary'        => 15000.00,
        'salary_words'          => 'Rupees Fifteen Thousand Only',
        'target_admissions'     => 8,
        'target_revenue'        => 160000.00,
        'target_revenue_words'  => 'Rupees One Lakh Sixty Thousand Only',
        'notice_period'         => '30 Days',
        'liquidated_damages'    => 500000.00,
        'non_compete_radius'    => '150 kilometers',
        'non_compete_duration'  => 'twelve (12) calendar months',
        'jurisdiction_court'    => 'Siwan, Bihar',
        'company_name'          => $defaultCompany['name'],
        'company_address'       => $defaultCompany['address'],
        'company_phone'         => $defaultCompany['phone'],
        'company_email'         => $defaultCompany['email'],
        'company_website'       => $defaultCompany['website'],
        'signatory_name'        => $defaultCompany['signatory_name'],
        'signatory_designation' => $defaultCompany['signatory_designation'],
        'show_company_stamp'    => 1,
        'show_signatory_signature' => 1,
        'status'                => 'draft',
    ];

    require_once __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
        <div class="d-flex align-items-center gap-2">
            <a href="<?php echo BASE_URL; ?>modules/offer_letters" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i>Cancel &amp; Back
            </a>
            <h4 class="fw-bold mb-0">
                <i class="fas fa-file-contract text-primary me-2"></i>
                <?php echo $action === 'edit' ? 'Edit Offer Letter' : 'Create New Offer Letter'; ?>
            </h4>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-info btn-sm" onclick="loadSampleReferenceData()">
                <i class="fas fa-magic me-1"></i>Load Reference Sample
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="saveForm(false)">
                <i class="fas fa-save me-1"></i>Save Draft
            </button>
            <button type="button" class="btn btn-primary btn-sm" onclick="saveForm(true)">
                <i class="fas fa-check-circle me-1"></i>Generate Final
            </button>
            <button type="button" class="btn btn-dark btn-sm" onclick="downloadPdfFromPreview()">
                <i class="fas fa-file-pdf me-1"></i>Download PDF
            </button>
            <button type="button" class="btn btn-success btn-sm" onclick="printFromPreview()">
                <i class="fas fa-print me-1"></i>Print
            </button>
        </div>
    </div>

    <?php displayFlash(); ?>

    <div class="row g-3">
        <!-- ================= LEFT COLUMN: FORM CONTROLS ================= -->
        <div class="col-lg-5 col-md-12" id="formPanelCol">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-primary">
                        <i class="fas fa-sliders-h me-2"></i>Offer Letter Parameters
                    </h6>
                    <span class="badge bg-light text-dark border" id="liveSaveBadge">Ready</span>
                </div>
                <div class="card-body p-3">
                    <form id="offerLetterForm" method="POST" action="<?php echo BASE_URL; ?>modules/offer_letters">
                        <input type="hidden" name="post_action" value="save">
                        <input type="hidden" name="id" id="field_id" value="<?php echo htmlspecialchars($formData['id']); ?>">
                        <input type="hidden" name="is_ajax" id="is_ajax" value="0">
                        <input type="hidden" name="status" id="field_status" value="<?php echo htmlspecialchars($formData['status']); ?>">

                        <!-- Quick Employee Picker -->
                        <div class="bg-light p-2 rounded border mb-3">
                            <label class="form-label small fw-bold text-muted mb-1">
                                <i class="fas fa-user-check me-1"></i>Select from Existing Employees (Auto-Fill)
                            </label>
                            <select class="form-select form-select-sm" id="employee_picker" onchange="autoFillFromEmployee(this.value)">
                                <option value="">-- Choose Existing Employee or Enter New Below --</option>
                                <?php foreach ($employeesList as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>"
                                    data-fname="<?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>"
                                    data-email="<?php echo htmlspecialchars($emp['email'] ?? ''); ?>"
                                    data-mobile="<?php echo htmlspecialchars($emp['mobile'] ?? ''); ?>"
                                    data-dept="<?php echo htmlspecialchars($emp['dept_name'] ?? ''); ?>"
                                    data-desig="<?php echo htmlspecialchars($emp['des_name'] ?? ''); ?>"
                                    data-salary="<?php echo htmlspecialchars($emp['salary'] ?? '0'); ?>"
                                    data-address="<?php echo htmlspecialchars($emp['address'] ?? ''); ?>"
                                    data-joining="<?php echo htmlspecialchars($emp['joining_date'] ?? ''); ?>"
                                    <?php echo ($formData['employee_id'] == $emp['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($emp['employee_code'] . ' - ' . $emp['first_name'] . ' ' . $emp['last_name'] . ' (' . ($emp['des_name'] ?: 'Employee') . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="employee_id" id="field_employee_id" value="<?php echo htmlspecialchars($formData['employee_id']); ?>">
                        </div>

                        <!-- Accordion for Organized Form Controls -->
                        <div class="accordion" id="formAccordion">
                            
                            <!-- 1. Candidate Details -->
                            <div class="accordion-item border mb-2 rounded">
                                <h2 class="accordion-header" id="headingCandidate">
                                    <button class="accordion-button py-2 px-3 fw-bold bg-white" type="button" data-bs-toggle="collapse" data-bs-target="#collapseCandidate">
                                        <i class="fas fa-user-circle me-2 text-primary"></i>1. Candidate Details
                                    </button>
                                </h2>
                                <div id="collapseCandidate" class="accordion-collapse collapse show">
                                    <div class="accordion-body p-3">
                                        <div class="mb-2">
                                            <label class="form-label small fw-semibold">Candidate Full Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control form-control-sm" name="candidate_name" id="field_candidate_name" value="<?php echo htmlspecialchars($formData['candidate_name']); ?>" required>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Father's / Guardian Name (S/o)</label>
                                                <input type="text" class="form-control form-control-sm" name="father_name" id="field_father_name" value="<?php echo htmlspecialchars($formData['father_name']); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Contact / Mobile Number</label>
                                                <input type="text" class="form-control form-control-sm" name="mobile" id="field_mobile" value="<?php echo htmlspecialchars($formData['mobile']); ?>">
                                            </div>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-semibold">Candidate Email</label>
                                            <input type="email" class="form-control form-control-sm" name="email" id="field_email" value="<?php echo htmlspecialchars($formData['email']); ?>">
                                        </div>
                                        <div class="mb-0">
                                            <label class="form-label small fw-semibold">Permanent / Residential Address</label>
                                            <textarea class="form-control form-control-sm" name="address" id="field_address" rows="2"><?php echo htmlspecialchars($formData['address']); ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 2. Position & Hierarchy -->
                            <div class="accordion-item border mb-2 rounded">
                                <h2 class="accordion-header" id="headingPosition">
                                    <button class="accordion-button py-2 px-3 fw-bold bg-white collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapsePosition">
                                        <i class="fas fa-briefcase me-2 text-primary"></i>2. Position, Role &amp; Hierarchy
                                    </button>
                                </h2>
                                <div id="collapsePosition" class="accordion-collapse collapse">
                                    <div class="accordion-body p-3">
                                        <div class="mb-2">
                                            <label class="form-label small fw-semibold">Designation / Job Title <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control form-control-sm" name="designation" id="field_designation" value="<?php echo htmlspecialchars($formData['designation']); ?>" required>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Department</label>
                                                <select class="form-select form-select-sm" name="department_id" id="field_department_id">
                                                    <option value="">-- Select Department --</option>
                                                    <?php foreach ($departments as $dept): ?>
                                                    <option value="<?php echo $dept['id']; ?>" <?php echo ($formData['department_id'] == $dept['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($dept['name']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Employment Type</label>
                                                <select class="form-select form-select-sm" name="employment_type" id="field_employment_type">
                                                    <?php foreach (['Full Time', 'Probation', 'Contract', 'Part Time', 'Internship'] as $et): ?>
                                                    <option value="<?php echo $et; ?>" <?php echo ($formData['employment_type'] === $et) ? 'selected' : ''; ?>><?php echo $et; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-semibold">Designated Reporting Officer</label>
                                            <input type="text" class="form-control form-control-sm" name="reporting_manager" id="field_reporting_manager" value="<?php echo htmlspecialchars($formData['reporting_manager']); ?>">
                                        </div>
                                        <div class="mb-0">
                                            <label class="form-label small fw-semibold">Work Location / Territory</label>
                                            <input type="text" class="form-control form-control-sm" name="work_location" id="field_work_location" value="<?php echo htmlspecialchars($formData['work_location']); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 3. Remuneration & Targets -->
                            <div class="accordion-item border mb-2 rounded">
                                <h2 class="accordion-header" id="headingRemuneration">
                                    <button class="accordion-button py-2 px-3 fw-bold bg-white collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseRemuneration">
                                        <i class="fas fa-money-bill-wave me-2 text-primary"></i>3. Remuneration &amp; Targets
                                    </button>
                                </h2>
                                <div id="collapseRemuneration" class="accordion-collapse collapse">
                                    <div class="accordion-body p-3">
                                        <div class="row g-2 mb-2">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Gross Monthly Salary (₹)</label>
                                                <input type="number" step="0.01" class="form-control form-control-sm" name="monthly_salary" id="field_monthly_salary" value="<?php echo htmlspecialchars($formData['monthly_salary']); ?>" oninput="autoCalculateSalaryWords(this.value)">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Salary in Words</label>
                                                <input type="text" class="form-control form-control-sm" name="salary_words" id="field_salary_words" value="<?php echo htmlspecialchars($formData['salary_words']); ?>">
                                            </div>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Monthly Target Admissions</label>
                                                <input type="number" class="form-control form-control-sm" name="target_admissions" id="field_target_admissions" value="<?php echo htmlspecialchars($formData['target_admissions']); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Target Revenue (₹)</label>
                                                <input type="number" step="0.01" class="form-control form-control-sm" name="target_revenue" id="field_target_revenue" value="<?php echo htmlspecialchars($formData['target_revenue']); ?>" oninput="autoCalculateRevenueWords(this.value)">
                                            </div>
                                        </div>
                                        <div class="mb-0">
                                            <label class="form-label small fw-semibold">Target Revenue in Words</label>
                                            <input type="text" class="form-control form-control-sm" name="target_revenue_words" id="field_target_revenue_words" value="<?php echo htmlspecialchars($formData['target_revenue_words']); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 4. Offer Details & Dates -->
                            <div class="accordion-item border mb-2 rounded">
                                <h2 class="accordion-header" id="headingDates">
                                    <button class="accordion-button py-2 px-3 fw-bold bg-white collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseDates">
                                        <i class="fas fa-calendar-alt me-2 text-primary"></i>4. Dates &amp; Document Number
                                    </button>
                                </h2>
                                <div id="collapseDates" class="accordion-collapse collapse">
                                    <div class="accordion-body p-3">
                                        <div class="row g-2 mb-2">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Offer Letter Number</label>
                                                <input type="text" class="form-control form-control-sm" name="offer_letter_no" id="field_offer_letter_no" value="<?php echo htmlspecialchars($formData['offer_letter_no']); ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Document Title</label>
                                                <input type="text" class="form-control form-control-sm" name="title" id="field_title" value="<?php echo htmlspecialchars($formData['title']); ?>">
                                            </div>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Issue Date</label>
                                                <input type="date" class="form-control form-control-sm" name="issue_date" id="field_issue_date" value="<?php echo htmlspecialchars($formData['issue_date']); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Joining Date</label>
                                                <input type="date" class="form-control form-control-sm" name="joining_date" id="field_joining_date" value="<?php echo htmlspecialchars($formData['joining_date']); ?>">
                                            </div>
                                        </div>
                                        <div class="row g-2 mb-0">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Probation Period</label>
                                                <input type="text" class="form-control form-control-sm" name="probation_period" id="field_probation_period" value="<?php echo htmlspecialchars($formData['probation_period']); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Notice Period</label>
                                                <input type="text" class="form-control form-control-sm" name="notice_period" id="field_notice_period" value="<?php echo htmlspecialchars($formData['notice_period']); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 5. Legal Terms, Signatory & Company -->
                            <div class="accordion-item border mb-2 rounded">
                                <h2 class="accordion-header" id="headingLegal">
                                    <button class="accordion-button py-2 px-3 fw-bold bg-white collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseLegal">
                                        <i class="fas fa-stamp me-2 text-primary"></i>5. Company, Signatory &amp; Stamp
                                    </button>
                                </h2>
                                <div id="collapseLegal" class="accordion-collapse collapse">
                                    <div class="accordion-body p-3">
                                        <div class="row g-2 mb-2">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Authorized Signatory Name</label>
                                                <input type="text" class="form-control form-control-sm" name="signatory_name" id="field_signatory_name" value="<?php echo htmlspecialchars($formData['signatory_name']); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Signatory Designation</label>
                                                <input type="text" class="form-control form-control-sm" name="signatory_designation" id="field_signatory_designation" value="<?php echo htmlspecialchars($formData['signatory_designation']); ?>">
                                            </div>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Jurisdiction Court</label>
                                                <input type="text" class="form-control form-control-sm" name="jurisdiction_court" id="field_jurisdiction_court" value="<?php echo htmlspecialchars($formData['jurisdiction_court']); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Liquidated Damages (₹)</label>
                                                <input type="number" class="form-control form-control-sm" name="liquidated_damages" id="field_liquidated_damages" value="<?php echo htmlspecialchars($formData['liquidated_damages']); ?>">
                                            </div>
                                        </div>
                                        <div class="d-flex gap-4 mt-3 pt-2 border-top">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="show_company_stamp" id="field_show_company_stamp" value="1" <?php echo !empty($formData['show_company_stamp']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label small fw-bold" for="field_show_company_stamp">Include Official Stamp</label>
                                            </div>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="show_signatory_signature" id="field_show_signatory_signature" value="1" <?php echo !empty($formData['show_signatory_signature']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label small fw-bold" for="field_show_signatory_signature">Include Signatures</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div> <!-- /accordion -->
                    </form>
                </div>
            </div>
        </div>

        <!-- ================= RIGHT COLUMN: LIVE A4 PREVIEW ================= -->
        <div class="col-lg-7 col-md-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                            <i class="fas fa-sync-alt fa-spin me-1" id="previewSpinIcon" style="display:none;"></i>Live A4 Preview
                        </span>
                        <small class="text-muted d-none d-sm-inline">Updates in real time</small>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" onclick="adjustZoom(-0.05)" title="Zoom Out">
                            <i class="fas fa-search-minus"></i>
                        </button>
                        <span class="small fw-bold text-muted" id="zoomLevelText" style="min-width: 40px; text-align: center;">85%</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" onclick="adjustZoom(0.05)" title="Zoom In">
                            <i class="fas fa-search-plus"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" onclick="resetZoom()" title="Reset Zoom">
                            <i class="fas fa-expand"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="offer-preview-wrapper" id="previewWrapper">
                        <div class="offer-preview-zoom-container" id="previewContainer" style="transform: scale(0.85);">
                            <!-- The dynamic 5 pages are rendered here -->
                            <div id="livePagesRoot">
                                <?php renderAppointmentLetterPages($formData, $emblemBase64, true); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts for Live Preview, Auto-Fill and PDF Generator -->
    <script src="<?php echo BASE_URL; ?>js/html2pdf.bundle.min.js"></script>
    <script>
    let currentZoom = 0.85;
    const emblemBase64 = "<?php echo $emblemBase64; ?>";

    function adjustZoom(delta) {
        currentZoom = Math.min(Math.max(currentZoom + delta, 0.4), 1.3);
        applyZoom();
    }
    function resetZoom() {
        currentZoom = window.innerWidth < 992 ? 0.65 : 0.85;
        applyZoom();
    }
    function applyZoom() {
        document.getElementById('previewContainer').style.transform = 'scale(' + currentZoom.toFixed(2) + ')';
        document.getElementById('zoomLevelText').innerText = Math.round(currentZoom * 100) + '%';
    }

    // Number to Indian Words in JS for instant feedback
    function inrWords(num) {
        num = Math.round(Number(num) || 0);
        if (num <= 0) return 'Rupees Zero Only';
        const a = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        const b = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        
        function convertChunk(n) {
            let s = '';
            if (n >= 100) {
                s += a[Math.floor(n / 100)] + ' Hundred ';
                n %= 100;
            }
            if (n >= 20) {
                s += b[Math.floor(n / 10)] + (n % 10 > 0 ? ' ' + a[n % 10] : '');
            } else if (n > 0) {
                s += a[n];
            }
            return s.trim();
        }

        let crore = Math.floor(num / 10000000);
        num %= 10000000;
        let lakh = Math.floor(num / 100000);
        num %= 100000;
        let thousand = Math.floor(num / 1000);
        num %= 1000;
        let remainder = num;

        let parts = [];
        if (crore > 0) parts.push(convertChunk(crore) + ' Crore');
        if (lakh > 0) parts.push(convertChunk(lakh) + ' Lakh');
        if (thousand > 0) parts.push(convertChunk(thousand) + ' Thousand');
        if (remainder > 0) parts.push(convertChunk(remainder));

        return 'Rupees ' + parts.join(' ').replace(/\s+/g, ' ').trim() + ' Only';
    }

    function autoCalculateSalaryWords(val) {
        const words = inrWords(val);
        document.getElementById('field_salary_words').value = words;
        triggerLivePreview();
    }

    function autoCalculateRevenueWords(val) {
        const words = inrWords(val);
        document.getElementById('field_target_revenue_words').value = words;
        triggerLivePreview();
    }

    // Auto-fill from Existing Employee
    function autoFillFromEmployee(empId) {
        if (!empId) return;
        const sel = document.getElementById('employee_picker');
        const opt = sel.options[sel.selectedIndex];
        if (!opt) return;

        document.getElementById('field_employee_id').value = empId;
        if (opt.dataset.fname) document.getElementById('field_candidate_name').value = opt.dataset.fname;
        if (opt.dataset.email) document.getElementById('field_email').value = opt.dataset.email;
        if (opt.dataset.mobile) document.getElementById('field_mobile').value = opt.dataset.mobile;
        if (opt.dataset.address) document.getElementById('field_address').value = opt.dataset.address;
        if (opt.dataset.desig) document.getElementById('field_designation').value = opt.dataset.desig;
        if (opt.dataset.joining) document.getElementById('field_joining_date').value = opt.dataset.joining;
        if (opt.dataset.salary && Number(opt.dataset.salary) > 0) {
            document.getElementById('field_monthly_salary').value = opt.dataset.salary;
            autoCalculateSalaryWords(opt.dataset.salary);
        }
        triggerLivePreview();
    }

    // Load exact sample data matching reference image
    function loadSampleReferenceData() {
        document.getElementById('field_candidate_name').value = "Md. Afroz Ansari";
        document.getElementById('field_father_name').value = "Md. Shahabuddin Ansari";
        document.getElementById('field_address').value = "Hindustan Rice Mill At Yergaon Po Pipari Dixit Tah Mul, Yergaon Alias Belgaon, PO Bembal, Dist: Chandrapur, Maharastra";
        document.getElementById('field_mobile').value = "8262836979";
        document.getElementById('field_designation').value = "Senior Admission Field Marketing Executive";
        document.getElementById('field_joining_date').value = "2026-08-24";
        document.getElementById('field_issue_date').value = "2026-08-24";
        document.getElementById('field_work_location').value = "Siwan district and surrounding zones of Bihar";
        document.getElementById('field_reporting_manager').value = "Miss Pooja Pandey (Admission & Marketing Head)";
        document.getElementById('field_monthly_salary').value = 15000;
        document.getElementById('field_salary_words').value = "Rupees Fifteen Thousand Only";
        document.getElementById('field_target_admissions').value = 8;
        document.getElementById('field_target_revenue').value = 160000;
        document.getElementById('field_target_revenue_words').value = "Rupees One Lakh Sixty Thousand Only";
        document.getElementById('field_jurisdiction_court').value = "Siwan, Bihar";
        document.getElementById('field_signatory_name').value = "Pooja Pandey";
        document.getElementById('field_signatory_designation').value = "Human Resource & Recruitment Division";
        document.getElementById('field_show_company_stamp').checked = true;
        document.getElementById('field_show_signatory_signature').checked = true;
        triggerLivePreview();
    }

    // Instant DOM Live Preview Updates
    function triggerLivePreview() {
        const spin = document.getElementById('previewSpinIcon');
        if (spin) spin.style.display = 'inline-block';

        const cName = document.getElementById('field_candidate_name').value || 'Candidate Name';
        const fName = document.getElementById('field_father_name').value || '';
        const address = document.getElementById('field_address').value || '';
        const mobile = document.getElementById('field_mobile').value || '';
        const desig = document.getElementById('field_designation').value || 'SENIOR ADMISSION FIELD MARKETING EXECUTIVE';
        const desigUpper = desig.toUpperCase();
        const mgr = document.getElementById('field_reporting_manager').value || 'Miss Pooja Pandey (Admission & Marketing Head)';
        const mgrShortMatch = mgr.match(/^(Miss|Mr\.|Mrs\.|Dr\.)?\s*([^(\n\r]+)/);
        const mgrShort = mgrShortMatch ? mgrShortMatch[0].trim() : mgr;
        const location = document.getElementById('field_work_location').value || 'Siwan district and surrounding zones of Bihar';
        const salary = Number(document.getElementById('field_monthly_salary').value || 15000).toLocaleString('en-IN', {minimumFractionDigits: 2});
        const salaryWords = document.getElementById('field_salary_words').value || inrWords(document.getElementById('field_monthly_salary').value || 15000);
        const targetAdm = document.getElementById('field_target_admissions').value || 8;
        const targetRev = Number(document.getElementById('field_target_revenue').value || 160000).toLocaleString('en-IN', {minimumFractionDigits: 2});
        const targetRevWords = document.getElementById('field_target_revenue_words').value || inrWords(document.getElementById('field_target_revenue').value || 160000);
        const joiningRaw = document.getElementById('field_joining_date').value || '2026-08-24';
        const issueRaw = document.getElementById('field_issue_date').value || '<?php echo date('Y-m-d'); ?>';
        const court = document.getElementById('field_jurisdiction_court').value || 'Siwan, Bihar';
        const sigName = document.getElementById('field_signatory_name').value || 'Pooja Pandey';
        const sigDesig = document.getElementById('field_signatory_designation').value || 'Human Resource & Recruitment Division';
        const showStamp = document.getElementById('field_show_company_stamp').checked;
        const docTitle = document.getElementById('field_title').value || 'APPOINTMENT LETTER';

        // Format dates
        let joiningFormatted = joiningRaw;
        try {
            const jd = new Date(joiningRaw);
            const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            const day = jd.getDate();
            const sfx = (day === 1 || day === 21 || day === 31) ? 'st' : ((day === 2 || day === 22) ? 'nd' : ((day === 3 || day === 23) ? 'rd' : 'th'));
            joiningFormatted = day + sfx + ' ' + months[jd.getMonth()] + ', ' + jd.getFullYear();
        } catch(e) {}

        let issueFormatted = issueRaw;
        try {
            const isd = new Date(issueRaw);
            const dd = String(isd.getDate()).padStart(2, '0');
            const mm = String(isd.getMonth() + 1).padStart(2, '0');
            issueFormatted = dd + '/' + mm + '/' + isd.getFullYear();
        } catch(e) {}

        const candInitialSig = '';

        // Update elements across the preview DOM
        document.querySelectorAll('.doc-main-title').forEach(el => el.innerText = docTitle);
        
        // Stamp visibility
        document.querySelectorAll('.official-stamp-seal').forEach(el => {
            el.style.display = showStamp ? 'inline-block' : 'none';
        });

        // Signatory name on stamps and signature blocks
        document.querySelectorAll('.official-stamp-seal .sig-overlay').forEach(el => el.innerText = sigName);

        // Candidate initials on sheets
        document.querySelectorAll('.candidate-init-seal').forEach(el => el.innerText = candInitialSig);

        // Refresh entire live pages inner HTML cleanly
        const root = document.getElementById('livePagesRoot');
        if (root) {
            // Build dynamically with exact markup
            root.innerHTML = generateAppointmentPagesMarkup({
                cName, fName, address, mobile, joiningFormatted, issueFormatted,
                desig, desigUpper, mgr, mgrShort, location, salary, salaryWords,
                targetAdm, targetRev, targetRevWords, court, sigName, sigDesig,
                showStamp, docTitle, candInitialSig
            });
        }

        setTimeout(() => {
            if (spin) spin.style.display = 'none';
        }, 150);
    }

    function generateAppointmentPagesMarkup(p) {
        const stampMarkup = p.showStamp ? `
            <div class="official-stamp-seal" style="width:125px;height:125px;">
                <svg viewBox="0 0 160 160" width="125" height="125" style="transform: rotate(-6deg);">
                    <circle cx="80" cy="80" r="76" fill="none" stroke="#1d4ed8" stroke-width="2.5" opacity="0.88"/>
                    <circle cx="80" cy="80" r="71" fill="none" stroke="#1d4ed8" stroke-width="1.1" opacity="0.88"/>
                    <circle cx="80" cy="80" r="49" fill="none" stroke="#1d4ed8" stroke-width="0.9" opacity="0.75"/>
                    <path id="stamp-top-arc-dyn" d="M 18,80 A 62,62 0 1,1 142,80" fill="none"/>
                    <text font-family="Times New Roman, serif" font-size="9.5" font-weight="bold" fill="#1d4ed8" letter-spacing="1px">
                        <textPath href="#stamp-top-arc-dyn" startOffset="50%" text-anchor="middle">Yatharth Group of Institution</textPath>
                    </text>
                    <path id="stamp-bot-arc-dyn" d="M 142,80 A 62,62 0 0,1 18,80" fill="none"/>
                    <text font-family="Times New Roman, serif" font-size="8" font-weight="bold" fill="#1d4ed8" letter-spacing="0.8px">
                        <textPath href="#stamp-bot-arc-dyn" startOffset="50%" text-anchor="middle">www.yatharthinstitution.com</textPath>
                    </text>
                    <text x="80" y="64" font-family="Georgia, serif" font-size="12" font-weight="bold" fill="#1d4ed8" text-anchor="middle">यथार्थ</text>
                    <text x="80" y="76" font-family="Times New Roman, serif" font-size="7.5" font-weight="bold" fill="#1d4ed8" text-anchor="middle">3rd Floor, B.G Company</text>
                    <text x="80" y="86" font-family="Times New Roman, serif" font-size="7.5" font-weight="bold" fill="#1d4ed8" text-anchor="middle">Building, Naya Bazar</text>
                    <text x="80" y="96" font-family="Times New Roman, serif" font-size="8" font-weight="bold" fill="#1d4ed8" text-anchor="middle">Siwan - 841226</text>
                    <text x="80" y="106" font-family="Times New Roman, serif" font-size="7.5" font-weight="bold" fill="#1d4ed8" text-anchor="middle">(Bihar)</text>
                </svg>
                <div class="sig-overlay">${p.sigName}</div>
            </div>` : '';

        const headerMarkup = `
            <div class="a4-page-header">
                <div class="brand-area">
                    <img src="${emblemBase64}" class="brand-logo-img" alt="Emblem">
                    <div class="brand-title">YATHARTH GROUP OF INSTITUTION</div>
                </div>
                <div class="contact-area">
                    <div><i class="fas fa-phone-alt me-1"></i> +91-915-396-5656</div>
                    <div><i class="fas fa-envelope me-1"></i> info@yatharthinstitution.com</div>
                </div>
            </div>`;

        const footerMarkup = `
            <div class="a4-page-footer">
                3RD FLOOR, B.G. COMPANY BUILDING NEAR HOSPITAL MORE, NAYA BAZAR, SIWAN (BIHAR) - 841226
            </div>`;

        return `
            <!-- PAGE 1 -->
            <div class="a4-page-sheet" id="page-sheet-1">
                ${headerMarkup}
                <div class="a4-page-body">
                    <div class="doc-main-title">${p.docTitle}</div>
                    <div class="mb-3" style="font-size: 10pt; line-height: 1.45;">
                        <div>To,</div>
                        <div class="fw-bold" style="font-size: 11pt;">${p.cName}</div>
                        ${p.fName ? `<div><span class="fw-bold">S/o:</span> ${p.fName}</div>` : ''}
                        ${p.address ? `<div><span class="fw-bold">Permanent Address:</span> ${p.address}</div>` : ''}
                        ${p.mobile ? `<div><span class="fw-bold">Contact Number:</span> ${p.mobile}</div>` : ''}
                        <div><span class="fw-bold">Joining Date:</span> ${p.joiningFormatted}</div>
                    </div>

                    <div class="fw-bold mb-3" style="font-size: 10.5pt; text-transform: uppercase;">
                        SUBJECT: APPOINTMENT LETTER FOR THE POSITION OF "${p.desigUpper}"
                    </div>

                    <p><strong>Dear ${p.cName},</strong></p>
                    <p>
                        With reference to your application, subsequent personal interview, and the field competency evaluation rounds you conducted with the Selection Committee of <strong>Yatharth Group of Institution</strong> (hereinafter referred to as the <strong>"Institution"</strong>, <strong>"Management"</strong>, or <strong>"Employer"</strong>), we are pleased to offer you the position of <strong>${p.desig}</strong> under the following strictly non-negotiable terms, institutional norms, work disciplines, and performance-linked conditions.
                    </p>

                    <div class="doc-section-title">DEFINITIONS, REPORTING HIERARCHY &amp; CORE FIELD RESPONSIBILITIES</div>
                    <div class="doc-sub-heading">1. INTERPRETATION &amp; DEFINITIONS</div>
                    <ul class="list-unstyled ps-2 mb-2">
                        <li>• <strong>"Employer/Management"</strong> shall mean the Board of Directors, Managing Trustees, HR Department, and authorized representatives of Yatharth Group of Institution.</li>
                        <li>• <strong>"Employee"</strong> shall mean ${p.cName}.</li>
                        <li>• <strong>"Target"</strong> shall mean the minimum number of confirmed, fee-paid student admissions and total revenue allocated to the employee for every academic cycle/month.</li>
                    </ul>

                    <div class="doc-sub-heading">2. OFFICIAL REPORTING HIERARCHY</div>
                    <ul class="list-unstyled ps-2 mb-2">
                        <li>• <strong>Designated Reporting Officer:</strong> Your immediate supervisor and administrative authority for all operational purposes will be <strong>${p.mgr}</strong>.</li>
                        <li>• <strong>Command and Authority:</strong> You are required to work directly under the command, guidance, and supervision of <strong>${p.mgrShort}</strong>. Any official communication, leave approval, tour planning, or dispute resolution must be routed strictly through her office.</li>
                        <li>• <strong>Compliance:</strong> Failure to follow the direct instructions, strategies, or field allocations given by <strong>${p.mgrShort}</strong> will be legally treated as insubordination and breach of employment terms, inviting immediate disciplinary action.</li>
                    </ul>

                    <div class="doc-section-title">CORE RESPONSIBILITIES &amp; KEY PERFORMANCE AREAS (KPAs)</div>
                    <p class="mb-0">
                        Your profile is <strong>100% field-based physical marketing</strong>. You are required to actively operate in rural, semi-urban, and urban territories, with a primary focus on the ${p.location}, or any other region assigned by <strong>${p.mgrShort}</strong>. Your daily responsibilities include:
                    </p>

                    <div class="d-flex justify-content-end align-items-center gap-4 mt-3">
                        ${stampMarkup}
                    </div>
                </div>
                <div class="page-number-tag">Page 1 of 5</div>
                ${footerMarkup}
            </div>

            <!-- PAGE 2 -->
            <div class="a4-page-sheet" id="page-sheet-2">
                ${headerMarkup}
                <div class="a4-page-body">
                    <ul class="list-unstyled ps-2 mb-3">
                        <li>• <strong>Extensive Rural &amp; Ground Penetration:</strong> Conducting rigorous door-to-door and village-to-village visits in rural areas (including Siwan, Barharia and entire assigned blocks) to identify prospective students for Paramedical, Fire Safety, IT, and other vocational courses offered by the Institution.</li>
                        <li>• <strong>Canopy &amp; BTL Activity Execution:</strong> Erecting promotional canopies, stalls, and institutional helpdesks in prominent village squares, local markets (Haat-Bazaars), and near educational institutions to harvest high-quality student leads.</li>
                        <li>• <strong>Career Counselling Seminars:</strong> Planning, organizing, and executing independent career counselling seminars, presentations, and workshops in local high schools, intermediate colleges, and private coaching centres.</li>
                        <li>• <strong>Agent &amp; Referral Network Development:</strong> Identifying, on boarding, and managing local education consultants, field agents, village influencers, and school teachers to create a sustainable pipeline for student admissions.</li>
                    </ul>

                    <div class="doc-section-title">REMUNERATION STRUCTURE, GRACE PERIOD &amp; SALARY DEDUCTION / HOLD POLICY</div>
                    <div class="doc-sub-heading">4. REMUNERATION &amp; COMPENSATION PACKAGE</div>
                    <ul class="list-unstyled ps-2 mb-3">
                        <li>• Your Fixed Gross Monthly Salary is structured at <strong>INR ${p.salary}/- (${p.salaryWords})</strong>.</li>
                        <li>• This salary is inclusive of basic salary, house rent allowance, and basic field allowances. Statutory deductions such as TDS or Professional Tax (if applicable) will be deducted at source.</li>
                        <li>• Any additional fuel/conveyance allowance or performance incentives will be governed strictly by the written institutional dynamic incentive policy issued at the start of the admission cycle.</li>
                    </ul>

                    <div class="doc-section-title">5. PERFORMANCE-LINKED SALARY CLAUSES &amp; DEDUCTION NORMS (CRITICAL CLAUSE)</div>
                    <p>
                        As a core commercial representative of the institution, your compensation is strictly tied to your field accountability. The employee explicitly agrees to the following legal enforcement:
                    </p>
                    <ul class="list-unstyled ps-2 mb-2">
                        <li>• <strong>First Month Protection (Grace Period):</strong> Recognizing that territory setup, agent creation, and database building require an initial lead time, <strong>the first month of your employment (from the date of joining) will be treated as a Grace Period</strong>. Your fixed salary of INR ${p.salary}/- will be paid in full for the first month without any performance deductions, provided you submit authenticated daily field proofs and verified data to <strong>${p.mgrShort}</strong>.</li>
                        <li>• <strong>Subsequent Months (Second Month Onwards) Enforcement:</strong> From the second month of employment onwards, your continuation and full salary disbursement are entirely performance-dependent.</li>
                        <li>• <strong>Mandatory Monthly Target:</strong> You are legally bound to deliver a minimum of <strong>${p.targetAdm} Confirmed Admissions</strong> per calendar month, which must translate into a minimum institutional fee collection amount of <strong>INR ${p.targetRev}/- (${p.targetRevWords})</strong>.</li>
                        <li>• <strong>Salary Hold &amp; Deduction Rights:</strong> If you fail to meet the minimum baseline target of ${p.targetAdm} admissions or the absolute revenue metric of INR ${p.targetRev}/- as evaluated by <strong>${p.mgrShort}</strong> in any month from the second month onwards, the Management reserves the absolute, unilateral legal right to:
                            <ol class="ps-3 mt-1 mb-1">
                                <li>Place your salary on <strong>HOLD</strong> until the minimum milestone leads/admissions are converted.</li>
                                <li>Deduct your salary proportionally (up to 50% or more) in direct ratio to your target deficit.</li>
                                <li>Forfeit any pending allowances if no field output/admissions are recorded.</li>
                            </ol>
                        </li>
                        <li>• The employee hereby waives any right to legally dispute or object to such salary hold or deduction, acknowledging that the institution cannot sustain non-performing operational expenses.</li>
                    </ul>

                    <div class="d-flex justify-content-end align-items-center gap-4 mt-3">
                        ${stampMarkup}
                    </div>
                </div>
                <div class="page-number-tag">Page 2 of 5</div>
                ${footerMarkup}
            </div>

            <!-- PAGE 3 -->
            <div class="a4-page-sheet" id="page-sheet-3">
                ${headerMarkup}
                <div class="a4-page-body">
                    <div class="doc-section-title">FIELD MONITORING, DAILY REPORTING STANDARDS &amp; NORMS</div>
                    <div class="doc-sub-heading">6. REAL-TIME FIELD MONITORING &amp; REPORTING MANDATES</div>
                    <ul class="list-unstyled ps-2 mb-3">
                        <li>• <strong>Daily Attendance &amp; GPS Tracking:</strong> You are required to log your attendance through the official Employee App or via real-time GPS location sharing directly with <strong>${p.mgrShort}</strong> at the start and end of your field shift.</li>
                        <li>• <strong>Geotagged Proofs:</strong> For every canopy activity, seminar, or poster-pasting drive, you must upload high-resolution, time-stamped, and geotagged photographs to the reporting portal and verify it over text/call with <strong>${p.mgrShort}</strong>. Failure to do so will result in that day being marked as <strong>"Absent/Loss of Pay (LOP)"</strong>.</li>
                        <li>• <strong>Daily Activity Report (DAR) Submission:</strong> You must submit a digital or physical copy of the Daily Activity Report containing the names, verified mobile numbers, streams, and parental data of a minimum of 10 prospective students counseled daily. This report must be checked and signed off by <strong>${p.mgrShort}</strong> every single evening.</li>
                    </ul>

                    <div class="doc-section-title">7. INSTITUTIONAL CODE OF CONDUCT &amp; DISCIPLINE</div>
                    <ul class="list-unstyled ps-2 mb-3">
                        <li>• <strong>Professional Representation:</strong> You shall maintain the highest level of integrity and professional decorum. You are prohibited from making false promises, fake fee commitments, or unauthorized scholarship guarantees to students or parents.</li>
                        <li>• <strong>Anti-Poaching &amp; Direct Collection Prohibition:</strong> You are strictly forbidden from collecting any cash, online application fees, or tokens directly from students into your personal bank account. All financial transactions must be routed through the official bank account/counters of <strong>Yatharth Group of Institution</strong>. Any violation will be treated as a criminal breach of trust.</li>
                        <li>• <strong>Absconding Policy:</strong> Remaining absent from the assigned field territory or failing to report your daily activity to <strong>${p.mgrShort}</strong> for three (3) consecutive days without prior written approval will automatically classify you as "Absconded from Duty," leading to immediate termination and freezing of accounts.</li>
                    </ul>

                    <div class="doc-section-title">CONFIDENTIALITY, NON-COMPETE, AND ANTI-BARGAINING PROTECTION</div>
                    <div class="doc-sub-heading">8. NON-DISCLOSURE OF CONFIDENTIAL DATA</div>
                    <ul class="list-unstyled ps-2 mb-2">
                        <li>• During your field operations, you will compile, access, and generate sensitive data, including student contact lists, agent structures, pricing matrices, institutional brochures, and strategy modules.</li>
                        <li>• You explicitly agree that all such information is the exclusive intellectual and commercial property of <strong>Yatharth Group of Institution</strong>.</li>
                    </ul>

                    <div class="d-flex justify-content-end align-items-center gap-4 mt-4">
                        ${stampMarkup}
                    </div>
                </div>
                <div class="page-number-tag">Page 3 of 5</div>
                ${footerMarkup}
            </div>

            <!-- PAGE 4 -->
            <div class="a4-page-sheet" id="page-sheet-4">
                ${headerMarkup}
                <div class="a4-page-body">
                    <ul class="list-unstyled ps-2 mb-3">
                        <li>• You shall not, during or after your employment, disclose, duplicate, transfer, or sell this database to any competitor educational group, coaching institute, or third-party marketing agency. Breach of this clause will invoke an immediate financial liability of <strong>INR 5,00,000.00/-</strong> payable by you to the institution as liquidated damages.</li>
                    </ul>

                    <div class="doc-section-title">9. ANTI-BARGAINING &amp; EMPLOYEE WAIVER CLAUSE (LEGAL PROTECTION)</div>
                    <ul class="list-unstyled ps-2 mb-3">
                        <li>• <strong>Waiver of Claims:</strong> ${p.cName} explicitly agrees, confirms, and signs that he has completely read, understood, and consented to the performance-salary linkage clause outlined in Section 5.</li>
                        <li>• <strong>Estoppel:</strong> The employee agrees that if their employment is terminated or their salary is held/deducted due to non-achievement of targets as reported by <strong>${p.mgrShort}</strong> from the second month onwards, they are legally estopped (barred) from claiming any compensation, moving to labor courts for full salary recovery, or staging any protest against the management.</li>
                        <li>• The employee accepts that this contract represents a strictly professional commercial-marketing alliance where compensation is balanced against actual field conversion.</li>
                    </ul>

                    <div class="doc-section-title">10. NON-COMPETE RESTRICTIONS</div>
                    <p>
                        In the event of your resignation or termination, you are legally restricted from joining, consulting, or operating for any competitor institute offering Paramedical, Fire Safety, or similar vocational programs within a radius of <strong>150 kilometers</strong> from your assigned territories for a minimum duration of <strong>twelve (12) calendar months</strong>.
                    </p>

                    <div class="doc-section-title">LEGAL INDEMNITY, TERMINATION, AND JURISDICTION</div>
                    <div class="doc-sub-heading">11. INDEMNITY CLAUSE</div>
                    <p>
                        You shall indemnify, protect, and hold harmless the Management, Trustees, and Directors of <strong>Yatharth Group of Institution</strong> from and against any claims, losses, civil liabilities, police cases, or disputes arising out of your personal misconduct, misrepresentation, rash driving during field visits, local fights, or unauthorized financial dealings with local agents or publics.
                    </p>

                    <div class="doc-sub-heading">12. TERMINATION MECHANISM</div>
                    <ul class="list-unstyled ps-2 mb-2">
                        <li>• <strong>During Probationary Period:</strong> The Management, upon the official performance evaluation report of <strong>${p.mgrShort}</strong>, reserves the absolute right to terminate your employment at any moment without any prior notice, notice period, or assignment of reasons, if your field performance is found zero or unsatisfactory.</li>
                    </ul>

                    <div class="d-flex justify-content-end align-items-center gap-4 mt-3">
                        ${stampMarkup}
                    </div>
                </div>
                <div class="page-number-tag">Page 4 of 5</div>
                ${footerMarkup}
            </div>

            <!-- PAGE 5 -->
            <div class="a4-page-sheet" id="page-sheet-5">
                ${headerMarkup}
                <div class="a4-page-body">
                    <ul class="list-unstyled ps-2 mb-3">
                        <li>• <strong>Termination for Cause:</strong> The Management can terminate your services with Immediate Effect, forfeiting all pending balances, if you are found guilty of:
                            <ul class="list-unstyled ps-3 mt-1 mb-1" style="list-style-type: circle;">
                                <li>o Financial fraud, embezzlement, or taking bribes from agents/students.</li>
                                <li>o Submitting fabricated lead data, fake seminar sheets, or forged expense bills to <strong>${p.mgrShort}</strong>.</li>
                                <li>o Criminal behavior, intoxication during field duty, or defaming the Institution on public social media platforms.</li>
                            </ul>
                        </li>
                        <li>• <strong>Resignation:</strong> If you wish to resign, you must submit a formal resignation letter addressed to the HR Department through <strong>${p.mgrShort}</strong> with a mandatory <strong>30 Days</strong> advance written notice or surrender 1 month's salary in lieu of notice.</li>
                    </ul>

                    <div class="doc-section-title">13. GOVERNING LAW &amp; EXCLUSIVE JURISDICTION</div>
                    <p class="mb-3">
                        This appointment contract shall be governed, interpreted, and enforced strictly in accordance with the statutory laws of the Republic of India. In the event of any irreconcilable legal dispute, litigation, class-action, or claim arising out of this contract, the exclusive territorial jurisdiction shall lie solely within the competent civil courts of <strong>${p.court}</strong>. No court outside the territorial limits of ${p.court} shall have authority to entertain any application or suit filed by either party.
                    </p>

                    <div class="doc-section-title">DECLARATION &amp; UNCONDITIONAL ACCEPTANCE</div>
                    <p class="mb-4">
                        I, <strong>${p.cName}</strong>, ${p.fName ? 'S/o Mr. <strong>' + p.fName + '</strong>, ' : ''}resident of <strong>${p.address || p.location}</strong>, do hereby solemnly declare that I have read this entire 5-page Appointment Letter (or it has been read out and explained to me in my native language). I completely understand my reporting line under <strong>${p.mgrShort}</strong>, all the rigid norms, daily field duties, and the target-salary deduction rules. I accept this appointment unconditionally and pledge to abide by all the clauses with absolute loyalty and discipline.
                    </p>

                    <div class="row align-items-end mt-4">
                        <div class="col-6">
                            <div class="mb-3">
                                <span class="fw-bold d-block mb-4">Full Signature of the Employee:</span>
                                <div class="border-bottom border-dark" style="width: 250px;"></div>
                            </div>
                            <div class="mt-2"><span class="fw-bold">Name of the Employee:</span> ${p.cName}</div>

                            <div class="mt-3">
                                <span class="fw-bold d-block mb-1">Thumb Impression:</span>
                                <div class="thumb-impression-box">
                                    <i class="fas fa-fingerprint fa-2x mb-1 text-secondary opacity-75"></i>
                                    <span>Thumb Impression</span>
                                </div>
                            </div>

                            <div class="d-flex gap-4 mt-3 small fw-bold">
                                <div>Date: ${p.issueFormatted}</div>
                                <div>Place: ${p.court}</div>
                            </div>
                        </div>

                        <div class="col-6 text-end">
                            <div class="small fw-bold">For &amp; On Behalf of the Management,</div>
                            <div class="fw-bold text-uppercase mt-1 mb-2" style="font-size: 11pt;">YATHARTH GROUP OF INSTITUTION</div>

                            <div class="d-flex justify-content-end align-items-center gap-3 my-2">
                                ${stampMarkup}
                            </div>

                            <div class="fw-bold" style="font-size: 11pt;">${p.sigName}</div>
                            <div class="fw-semibold small">Yatharth Group of Institution</div>
                            <div class="text-muted small">${p.sigDesig}</div>
                        </div>
                    </div>
                </div>
                <div class="page-number-tag">Page 5 of 5</div>
                ${footerMarkup}
            </div>
        `;
    }

    // Attach real-time input listeners to all form controls
    document.querySelectorAll('#offerLetterForm input, #offerLetterForm textarea, #offerLetterForm select').forEach(input => {
        input.addEventListener('input', triggerLivePreview);
        input.addEventListener('change', triggerLivePreview);
    });

    // Save Draft or Final via AJAX
    function saveForm(isFinal) {
        document.getElementById('field_status').value = isFinal ? 'generated' : 'draft';
        const form = document.getElementById('offerLetterForm');
        const badge = document.getElementById('liveSaveBadge');
        badge.innerText = 'Saving...';
        badge.className = 'badge bg-warning text-dark';

        const formData = new FormData(form);
        formData.set('is_ajax', '1');

        fetch('<?php echo BASE_URL; ?>modules/offer_letters', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                badge.innerText = isFinal ? 'Generated!' : 'Saved Draft!';
                badge.className = isFinal ? 'badge bg-primary text-white' : 'badge bg-success text-white';
                if (data.id) {
                    document.getElementById('field_id').value = data.id;
                }
                setTimeout(() => {
                    badge.innerText = 'All changes saved';
                    badge.className = 'badge bg-light text-dark border';
                }, 3000);
            } else {
                badge.innerText = 'Save Error';
                badge.className = 'badge bg-danger text-white';
                alert(data.message || 'Error saving offer letter');
            }
        })
        .catch(err => {
            badge.innerText = 'Network Error';
            badge.className = 'badge bg-danger text-white';
            console.error(err);
        });
    }

    // Download PDF from Preview
    function downloadPdfFromPreview() {
        const element = document.getElementById('livePagesRoot');
        const num = document.getElementById('field_offer_letter_no').value || 'Document';
        const filename = 'Appointment_Letter_' + num.replace(/[\/\\?%*:|"<>]/g, '_') + '.pdf';
        
        const badge = document.getElementById('liveSaveBadge');
        badge.innerText = 'Generating PDF...';
        badge.className = 'badge bg-info text-dark';

        const opt = {
            margin:       [0, 0, 0, 0],
            filename:     filename,
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2, useCORS: true, logging: false },
            jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' },
            pagebreak:    { mode: ['css', 'legacy'] }
        };
        html2pdf().set(opt).from(element).save().then(() => {
            badge.innerText = 'Downloaded!';
            badge.className = 'badge bg-success text-white';
            setTimeout(() => {
                badge.innerText = 'Ready';
                badge.className = 'badge bg-light text-dark border';
            }, 2500);
        });
    }

    // Print from Preview
    function printFromPreview() {
        const id = document.getElementById('field_id').value;
        if (id) {
            window.open('<?php echo BASE_URL; ?>modules/offer_letters?action=print&id=' + id, '_blank');
        } else {
            // Print current document view
            window.print();
        }
    }

    // Initialize zoom on load
    window.addEventListener('DOMContentLoaded', () => {
        resetZoom();
        triggerLivePreview();
    });
    </script>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// ============================================================
// LIST ACTION (DEFAULT DASHBOARD VIEW)
// ============================================================
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$sql = "SELECT ol.*, d.name AS dept_name, e.employee_code 
        FROM offer_letters ol 
        LEFT JOIN departments d ON d.id = ol.department_id 
        LEFT JOIN employees e ON e.id = ol.employee_id";
$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(ol.candidate_name LIKE ? OR ol.offer_letter_no LIKE ? OR ol.email LIKE ? OR ol.mobile LIKE ? OR ol.designation LIKE ? OR e.employee_code LIKE ?)";
    $like = "%{$search}%";
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
}
if ($statusFilter !== '') {
    $where[] = "ol.status = ?";
    $params[] = $statusFilter;
}
if ($dateFrom !== '') {
    $where[] = "ol.issue_date >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = "ol.issue_date <= ?";
    $params[] = $dateTo;
}

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY ol.id DESC";

$offerLetters = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $offerLetters = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log("Offer letters list query error: " . $e->getMessage());
}

// KPI counts
$kpiTotal = 0;
$kpiDraft = 0;
$kpiGenerated = 0;
$kpiAccepted = 0;
try {
    $kpiTotal = (int)$pdo->query("SELECT COUNT(*) FROM offer_letters")->fetchColumn();
    $kpiDraft = (int)$pdo->query("SELECT COUNT(*) FROM offer_letters WHERE status = 'draft'")->fetchColumn();
    $kpiGenerated = (int)$pdo->query("SELECT COUNT(*) FROM offer_letters WHERE status = 'generated'")->fetchColumn();
    $kpiAccepted = (int)$pdo->query("SELECT COUNT(*) FROM offer_letters WHERE status = 'accepted'")->fetchColumn();
} catch (Throwable $e) {
    error_log("Offer letters KPI query error: " . $e->getMessage());
}

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Title & Top CTA -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h4 class="fw-bold mb-0">
            <i class="fas fa-file-signature text-primary me-2"></i>Offer Letter Generator
        </h4>
        <small class="text-muted">Generate, preview, print and download official print-ready company appointment letters</small>
    </div>
    <?php if (canCreate('offer_letters')): ?>
    <a href="<?php echo BASE_URL; ?>modules/offer_letters?action=create" class="btn btn-primary">
        <i class="fas fa-plus me-1"></i>Create New Offer Letter
    </a>
    <?php endif; ?>
</div>

<?php displayFlash(); ?>

<!-- KPI Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-sm-6">
        <div class="card shadow-sm border-0 border-start border-4 border-primary">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small fw-bold text-uppercase">Total Letters</span>
                        <h4 class="fw-bold text-dark mt-1 mb-0"><?php echo number_format($kpiTotal); ?></h4>
                    </div>
                    <div class="bg-primary-subtle text-primary p-2 rounded-circle" style="width: 44px; height: 44px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-file-contract fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6">
        <div class="card shadow-sm border-0 border-start border-4 border-warning">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small fw-bold text-uppercase">Drafts</span>
                        <h4 class="fw-bold text-warning mt-1 mb-0"><?php echo number_format($kpiDraft); ?></h4>
                    </div>
                    <div class="bg-warning-subtle text-warning p-2 rounded-circle" style="width: 44px; height: 44px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-edit fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6">
        <div class="card shadow-sm border-0 border-start border-4 border-info">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small fw-bold text-uppercase">Generated / Issued</span>
                        <h4 class="fw-bold text-info mt-1 mb-0"><?php echo number_format($kpiGenerated); ?></h4>
                    </div>
                    <div class="bg-info-subtle text-info p-2 rounded-circle" style="width: 44px; height: 44px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-stamp fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6">
        <div class="card shadow-sm border-0 border-start border-4 border-success">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small fw-bold text-uppercase">Accepted</span>
                        <h4 class="fw-bold text-success mt-1 mb-0"><?php echo number_format($kpiAccepted); ?></h4>
                    </div>
                    <div class="bg-success-subtle text-success p-2 rounded-circle" style="width: 44px; height: 44px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-check-double fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters Card -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-3">
        <form method="GET" action="<?php echo BASE_URL; ?>modules/offer_letters" class="row g-2 align-items-end">
            <div class="col-md-4 col-sm-12">
                <label class="form-label small fw-semibold text-muted mb-1">Search Employee / Letter #</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0" name="search" placeholder="Name, Offer #, Mobile, Designation..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <label class="form-label small fw-semibold text-muted mb-1">Status</label>
                <select class="form-select form-select-sm" name="status">
                    <option value="">All Statuses</option>
                    <?php foreach (['draft' => 'Draft', 'generated' => 'Generated', 'sent' => 'Sent', 'accepted' => 'Accepted', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'] as $k => $label): ?>
                    <option value="<?php echo $k; ?>" <?php echo $statusFilter === $k ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-sm-6">
                <label class="form-label small fw-semibold text-muted mb-1">Date From</label>
                <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
            </div>
            <div class="col-md-2 col-sm-6">
                <label class="form-label small fw-semibold text-muted mb-1">Date To</label>
                <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
            </div>
            <div class="col-md-2 col-sm-6 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="fas fa-filter me-1"></i>Filter
                </button>
                <?php if ($search || $statusFilter || $dateFrom || $dateTo): ?>
                <a href="<?php echo BASE_URL; ?>modules/offer_letters" class="btn btn-outline-secondary btn-sm" title="Clear Filters">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Offer Letters Data Table -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0">
            <i class="fas fa-list me-2 text-primary"></i>Offer Letters On Record (<?php echo count($offerLetters); ?>)
        </h6>
        <?php if (!empty($offerLetters)): ?>
        <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
            <i class="fas fa-print me-1"></i>Print List
        </button>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($offerLetters)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-file-contract fa-3x mb-3 text-secondary opacity-50"></i>
            <h5 class="fw-bold">No Offer Letters Found</h5>
            <p class="small mb-3">No matching offer letter records exist yet. You can create your first appointment letter in seconds!</p>
            <?php if (canCreate('offer_letters')): ?>
            <a href="<?php echo BASE_URL; ?>modules/offer_letters?action=create" class="btn btn-primary btn-sm">
                <i class="fas fa-plus me-1"></i>Create First Offer Letter
            </a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Letter #</th>
                        <th>Candidate Details</th>
                        <th>Designation &amp; Dept</th>
                        <th>Salary &amp; Target</th>
                        <th>Issue / Joining Date</th>
                        <th>Status</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($offerLetters as $row): 
                        $statusClass = [
                            'draft' => 'secondary',
                            'generated' => 'primary',
                            'sent' => 'info',
                            'accepted' => 'success',
                            'rejected' => 'danger',
                            'cancelled' => 'dark'
                        ][$row['status']] ?? 'secondary';
                    ?>
                    <tr>
                        <td class="ps-3 fw-bold text-nowrap">
                            <a href="<?php echo BASE_URL; ?>modules/offer_letters?action=view&id=<?php echo $row['id']; ?>" class="text-decoration-none">
                                <?php echo htmlspecialchars($row['offer_letter_no']); ?>
                            </a>
                        </td>
                        <td>
                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($row['candidate_name']); ?></div>
                            <div class="small text-muted">
                                <?php if ($row['father_name']): ?>S/o <?php echo htmlspecialchars($row['father_name']); ?> · <?php endif; ?>
                                <?php if ($row['mobile']): ?><i class="fas fa-phone-alt fa-xs me-1"></i><?php echo htmlspecialchars($row['mobile']); ?><?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="fw-semibold"><?php echo htmlspecialchars($row['designation']); ?></div>
                            <div class="small text-muted"><?php echo htmlspecialchars($row['department_name'] ?: 'General'); ?></div>
                        </td>
                        <td>
                            <div class="fw-bold text-success">₹<?php echo number_format($row['monthly_salary'], 2); ?></div>
                            <div class="small text-muted"><?php echo (int)$row['target_admissions']; ?> Admissions Target</div>
                        </td>
                        <td>
                            <div class="small"><span class="fw-semibold">Issued:</span> <?php echo date('d M Y', strtotime($row['issue_date'])); ?></div>
                            <div class="small text-muted"><span class="fw-semibold">Joining:</span> <?php echo date('d M Y', strtotime($row['joining_date'])); ?></div>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $statusClass; ?> text-uppercase px-2 py-1">
                                <?php echo htmlspecialchars($row['status']); ?>
                            </span>
                        </td>
                        <td class="text-end pe-3 text-nowrap">
                            <a href="<?php echo BASE_URL; ?>modules/offer_letters?action=view&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-light border py-1 px-2" title="View Document">
                                <i class="fas fa-eye text-primary"></i>
                            </a>
                            <?php if (canEdit('offer_letters')): ?>
                            <a href="<?php echo BASE_URL; ?>modules/offer_letters?action=edit&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-light border py-1 px-2" title="Edit Letter">
                                <i class="fas fa-pencil-alt text-dark"></i>
                            </a>
                            <?php endif; ?>
                            <a href="<?php echo BASE_URL; ?>modules/offer_letters?action=print&id=<?php echo $row['id']; ?>" target="_blank" class="btn btn-sm btn-light border py-1 px-2" title="Print Letter">
                                <i class="fas fa-print text-success"></i>
                            </a>
                            <a href="<?php echo BASE_URL; ?>modules/offer_letters?action=view&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-light border py-1 px-2" title="Download PDF">
                                <i class="fas fa-file-pdf text-danger"></i>
                            </a>
                            <?php if (canDelete('offer_letters')): ?>
                            <button type="button" class="btn btn-sm btn-light border py-1 px-2 text-danger" title="Delete Letter" onclick="confirmDelete(<?php echo $row['id']; ?>, '<?php echo addslashes($row['offer_letter_no']); ?>')">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
                <h6 class="modal-title fw-bold"><i class="fas fa-trash-alt me-2"></i>Confirm Deletion</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-4">
                <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                <h5>Are you sure you want to delete this offer letter?</h5>
                <p class="text-muted small mb-0" id="delModalText">This action cannot be undone.</p>
            </div>
            <div class="modal-footer bg-light">
                <form method="POST" action="<?php echo BASE_URL; ?>modules/offer_letters">
                    <input type="hidden" name="post_action" value="delete">
                    <input type="hidden" name="id" id="delInputId" value="">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm">Yes, Delete Letter</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(id, num) {
    document.getElementById('delInputId').value = id;
    document.getElementById('delModalText').innerText = 'Are you sure you want to permanently delete offer letter #' + num + '?';
    new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
