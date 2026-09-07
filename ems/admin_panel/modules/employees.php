<?php
require_once '../includes/config.php';
if (!isLoggedIn()) redirect('../index.php');
requireModuleAccess('employees');

$message = '';

// =============================================
// Auto Generate Employee Code (YGI001, YGI002...)
// =============================================
function generateEmployeeCode($pdo) {
    $prefix = 'YGI';
    $stmt = $pdo->query("SELECT employee_code FROM employees WHERE employee_code LIKE 'YGI%' ORDER BY employee_code DESC LIMIT 1");
    $last = $stmt->fetchColumn();

    if ($last) {
        $num = (int)substr($last, 3) + 1;
    } else {
        $num = 1;
    }

    // Ensure uniqueness (skip if code already exists)
    $code = $prefix . str_pad($num, 3, '0', STR_PAD_LEFT);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE employee_code = ?");
    while (true) {
        $stmt->execute([$code]);
        if ($stmt->fetchColumn() == 0) break;
        $num++;
        $code = $prefix . str_pad($num, 3, '0', STR_PAD_LEFT);
    }

    return $code;
}

// Add / Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $first_name = sanitize($_POST['first_name']);
    $last_name = sanitize($_POST['last_name'] ?? '');
    $employee_code = sanitize($_POST['employee_code'] ?? '');
    $mobile = sanitize($_POST['mobile'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $department_id = $_POST['department_id'] ?: null;
    $designation_id = $_POST['designation_id'] ?: null;
    $joining_date = $_POST['joining_date'] ?: null;
    $salary = $_POST['salary'] ?? 0;
    $address = sanitize($_POST['address'] ?? '');
    $city = sanitize($_POST['city'] ?? '');
    $state = sanitize($_POST['state'] ?? '');
    $pincode = sanitize($_POST['pincode'] ?? '');
    $status = $_POST['status'] ?? 1;
    $create_login = !empty($_POST['create_login']) ? 1 : 0;
    $is_field_staff = !empty($_POST['is_field_staff']) ? 1 : 0;

    try {
        $pdo->beginTransaction();

        if ($action == 'add') {
            // Auto-generate code if not provided
            if (empty($employee_code)) {
                $employee_code = generateEmployeeCode($pdo);
            }
            $chk = $pdo->prepare("SELECT id FROM employees WHERE employee_code = ?");
            $chk->execute([$employee_code]);
            if ($chk->fetch()) throw new Exception('Employee code already exists');

            $userId = null;
            if ($create_login) {
                $username = sanitize($_POST['username'] ?? $employee_code);
                $password = password_hash($_POST['password'] ?? 'employee123', PASSWORD_BCRYPT);
                $role_id = (int)($_POST['role_id'] ?? 5);

                $uchk = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $uchk->execute([$username]);
                if ($uchk->fetch()) throw new Exception('Username already exists');

                // users.employee_id is not written: it is an integer column
                // that was being given the employee CODE ("YGI001"), the column
                // is absent from the schema entirely, and nothing reads it —
                // login joins employees.user_id instead. Writing it could only
                // fail the insert or store a wrong value.
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role_id, status) VALUES (?, ?, ?, ?, 1)");
                $stmt->execute([$username, $email, $password, $role_id]);
                $userId = $pdo->lastInsertId();
            }

            $stmt = $pdo->prepare("INSERT INTO employees (user_id, employee_code, first_name, last_name, mobile, email, department_id, designation_id, joining_date, salary, address, city, state, pincode, status, is_field_staff) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$userId, $employee_code, $first_name, $last_name, $mobile, $email, $department_id, $designation_id, $joining_date, $salary, $address, $city, $state, $pincode, $status, $is_field_staff]);
            $message = 'Employee added successfully';
        } else {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("UPDATE employees SET first_name=?, last_name=?, employee_code=?, mobile=?, email=?, department_id=?, designation_id=?, joining_date=?, salary=?, address=?, city=?, state=?, pincode=?, status=?, is_field_staff=? WHERE id=?");
            $stmt->execute([$first_name, $last_name, $employee_code, $mobile, $email, $department_id, $designation_id, $joining_date, $salary, $address, $city, $state, $pincode, $status, $is_field_staff, $id]);

            if (isset($_POST['role_id'])) {
                $emp = $pdo->prepare("SELECT user_id FROM employees WHERE id = ?");
                $emp->execute([$id]);
                $e = $emp->fetch();
                if ($e && $e['user_id']) {
                    $pdo->prepare("UPDATE users SET role_id = ? WHERE id = ?")->execute([(int)$_POST['role_id'], $e['user_id']]);
                }
            }

            $message = 'Employee updated successfully';
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = 'Error: ' . $e->getMessage();
    }
}

// Unbind the account's device — needed whenever someone replaces or loses a
// phone, otherwise device binding would lock them out permanently.
if (isset($_GET['reset_device'])) {
    $eid = (int) $_GET['reset_device'];
    $u = $pdo->prepare("SELECT user_id FROM employees WHERE id = ?");
    $u->execute([$eid]);
    $row = $u->fetch();
    if ($row && $row['user_id']) {
        $pdo->prepare("UPDATE users SET device_id = NULL, device_name = NULL, device_bound_at = NULL WHERE id = ?")
            ->execute([$row['user_id']]);
        // Existing sessions belonged to the old handset.
        $pdo->prepare("UPDATE user_sessions SET is_active = 0, logout_time = NOW() WHERE user_id = ? AND is_active = 1")
            ->execute([$row['user_id']]);
        $message = 'Device reset. The next sign-in will register a new phone.';
    }
}

// Clear a face registration so the employee can capture it again.
//
// Restricted to super admins: registering once is what stops someone enrolling
// a colleague's face, and an admin who could clear it at will would hand that
// back. The row is kept and stamped rather than deleted, so who cleared whose
// biometric stays answerable; only the embedding itself goes.
if (isset($_GET['reset_face'])) {
    if (getAdminRoleName() !== 'super_admin') {
        $message = 'Error: only a super admin can reset a face registration.';
    } else {
        $eid = (int) $_GET['reset_face'];
        try {
            $stmt = $pdo->prepare("UPDATE employee_face_data
                                   SET embedding = '', dimensions = 0, is_locked = 0,
                                       reset_by = ?, reset_at = NOW()
                                   WHERE employee_id = ? AND embedding <> ''");
            $stmt->execute([$_SESSION['admin_id'] ?? null, $eid]);
            $message = $stmt->rowCount()
                ? 'Face registration cleared. The employee can capture it again from the app.'
                : 'That employee has no face registered.';
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
        }
    }
}

// Delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $emp = $pdo->prepare("SELECT user_id FROM employees WHERE id = ?");
    $emp->execute([$id]);
    $d = $emp->fetch();
    if ($d) {
        $pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$id]);
        if ($d['user_id']) $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$d['user_id']]);
        $message = 'Employee deleted';
    }
}

// Filters — each select submits on change, so the list reloads immediately.
$filterDept   = $_GET['department_id'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterRole   = $_GET['role_id'] ?? '';

$where = [];
$params = [];
if ($filterDept !== '')   { $where[] = 'e.department_id = ?'; $params[] = (int) $filterDept; }
if ($filterStatus !== '') { $where[] = 'e.status = ?';        $params[] = (int) $filterStatus; }
if ($filterRole !== '')   { $where[] = 'u.role_id = ?';       $params[] = (int) $filterRole; }

// Built in two parts so the face columns can be dropped if that table is not
// there. The migration creates it, but this page listing every employee is not
// worth losing to a migration that did not run.
$buildSql = function ($withFace) use ($where) {
    $sql = "
    SELECT e.*, d.name as department_name, des.name as designation_name,
           u.role_id as user_role_id, u.status as user_active, u.username,
           u.device_id, u.device_name,
           r.name as role_name, r.description as role_display";
    if ($withFace) {
        $sql .= ",
           fd.enrolled_at as face_enrolled_at,
           CASE WHEN fd.embedding IS NULL OR fd.embedding = '' THEN 0 ELSE 1 END AS face_enrolled";
    }
    $sql .= "
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN designations des ON des.id = e.designation_id
    LEFT JOIN users u ON u.id = e.user_id
    LEFT JOIN roles r ON r.id = u.role_id";
    if ($withFace) $sql .= "\n    LEFT JOIN employee_face_data fd ON fd.employee_id = e.id";
    if ($where) $sql .= "\n    WHERE " . implode(' AND ', $where);
    return $sql . "\n    ORDER BY e.first_name ASC";
};

try {
    $stmt = $pdo->prepare($buildSql(true));
    $stmt->execute($params);
    $employees = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Employees face join unavailable: ' . $e->getMessage());
    $stmt = $pdo->prepare($buildSql(false));
    $stmt->execute($params);
    $employees = $stmt->fetchAll();
}

$departments = $pdo->query("SELECT * FROM departments WHERE status = 1 ORDER BY name")->fetchAll();
$designations = $pdo->query("SELECT * FROM designations WHERE status = 1 ORDER BY name")->fetchAll();
$roles = $pdo->query("SELECT id, name, description FROM roles ORDER BY id")->fetchAll();

require_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold"><i class="fas fa-users me-2"></i>Employee Management</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#employeeModal">
        <i class="fas fa-plus me-1"></i>Add Employee
    </button>
</div>

<?php if ($message): ?>
<div class="alert alert-info alert-dismissible fade show"><?php echo $message; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label mb-1 small text-muted">Department</label>
                <select name="department_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?php echo $d['id']; ?>" <?php echo (string)$filterDept === (string)$d['id'] ? 'selected' : ''; ?>><?php echo sanitize($d['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1 small text-muted">Role</label>
                <select name="role_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Roles</option>
                    <?php foreach ($roles as $r): ?>
                    <option value="<?php echo $r['id']; ?>" <?php echo (string)$filterRole === (string)$r['id'] ? 'selected' : ''; ?>><?php echo sanitize($r['description'] ?: $r['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1 small text-muted">Status</label>
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All</option>
                    <option value="1" <?php echo $filterStatus === '1' ? 'selected' : ''; ?>>Active</option>
                    <option value="0" <?php echo $filterStatus === '0' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-3">
                <span class="text-muted small me-2"><?php echo count($employees); ?> employee<?php echo count($employees) === 1 ? '' : 's'; ?></span>
                <?php if ($filterDept !== '' || $filterStatus !== '' || $filterRole !== ''): ?>
                <a href="employees.php" class="btn btn-sm btn-outline-secondary">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover datatable mb-0">
                <thead>
                    <tr><th>Code</th><th>Name</th><th>Department</th><th>Designation</th><th>Mobile</th><th>Role</th><th>Type</th><th>Device</th><th>Face</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $emp): ?>
                    <tr>
                        <td><?php echo sanitize($emp['employee_code']); ?></td>
                        <td><?php echo sanitize($emp['first_name'] . ' ' . $emp['last_name']); ?></td>
                        <td><?php echo sanitize($emp['department_name'] ?? '-'); ?></td>
                        <td><?php echo sanitize($emp['designation_name'] ?? '-'); ?></td>
                        <td><?php echo sanitize($emp['mobile']); ?></td>
                        <td><span class="badge bg-info"><?php echo sanitize($emp['role_display'] ?? ($emp['role_name'] ?? 'No Login')); ?></span></td>
                        <td><?php if (!empty($emp['is_field_staff'])): ?><span class="badge bg-primary">Field Staff</span><?php else: ?><span class="text-muted">Office</span><?php endif; ?></td>
                        <td>
                            <?php if (!empty($emp['device_id'])): ?>
                            <span class="badge bg-light text-dark border" title="<?php echo sanitize($emp['device_id']); ?>">
                                <i class="fas fa-mobile-alt me-1"></i><?php echo sanitize($emp['device_name'] ?: 'Bound'); ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted small">Not bound</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($emp['face_enrolled'])): ?>
                            <span class="badge bg-success bg-opacity-75" title="Registered on <?php echo sanitize((string) $emp['face_enrolled_at']); ?>">
                                <i class="fas fa-user-check me-1"></i>Registered
                            </span>
                            <?php else: ?>
                            <span class="badge bg-light text-dark border" title="This employee's check-ins are not face-verified">
                                <i class="fas fa-user-slash me-1"></i>Not set
                            </span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-<?php echo $emp['status'] ? 'success' : 'danger'; ?>"><?php echo $emp['status'] ? 'Active' : 'Inactive'; ?></span></td>
                        <td>
                            <button class="btn btn-sm btn-info" onclick="editEmployee(<?php echo htmlspecialchars(json_encode($emp)); ?>)"><i class="fas fa-edit"></i></button>
                            <?php if (!empty($emp['device_id'])): ?>
                            <a href="?reset_device=<?php echo $emp['id']; ?>" class="btn btn-sm btn-warning" title="Reset bound device"
                               onclick="return confirm('Unbind this device? The employee can then sign in from a new phone.')"><i class="fas fa-mobile-alt"></i></a>
                            <?php endif; ?>
                            <?php if (!empty($emp['face_enrolled']) && getAdminRoleName() === 'super_admin'): ?>
                            <a href="?reset_face=<?php echo $emp['id']; ?>" class="btn btn-sm btn-secondary" title="Reset face registration"
                               onclick="return confirm('Clear this face registration? The employee will be able to register a new face, and their check-ins are not verified until they do.')"><i class="fas fa-user-times"></i></a>
                            <?php endif; ?>
                            <a href="?delete=<?php echo $emp['id']; ?>" class="btn btn-sm btn-danger btn-delete"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="employeeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="formId" value="">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Employee Code *</label>
                            <input type="text" name="employee_code" id="f_code" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">First Name *</label>
                            <input type="text" name="first_name" id="f_fname" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="last_name" id="f_lname" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Mobile</label>
                            <input type="text" name="mobile" id="f_mobile" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="f_email" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department</label>
                            <select name="department_id" id="f_dept" class="form-select">
                                <option value="">Select</option>
                                <?php foreach ($departments as $d): ?>
                                <option value="<?php echo $d['id']; ?>"><?php echo sanitize($d['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                <div class="col-md-6">
                    <label class="form-label">Designation</label>
                    <select name="designation_id" id="f_desig" class="form-select">
                        <option value="">Select Department First</option>
                    </select>
                </div>
                        <div class="col-md-6">
                            <label class="form-label">Joining Date</label>
                            <input type="date" name="joining_date" id="f_date" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Salary</label>
                            <input type="number" step="0.01" name="salary" id="f_salary" class="form-control">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Address</label>
                            <textarea name="address" id="f_address" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">City</label>
                            <input type="text" name="city" id="f_city" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">State</label>
                            <input type="text" name="state" id="f_state" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Pincode</label>
                            <input type="text" name="pincode" id="f_pincode" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" id="f_status" class="form-select">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Employee Type</label>
                            <select name="is_field_staff" id="f_field_staff" class="form-select">
                                <option value="0">Office Staff</option>
                                <option value="1">Field Staff</option>
                            </select>
                            <small class="text-muted">Field staff market se bhi check-in/out kar sakta hai (GPS+selfie required)</small>
                        </div>
                        <div class="col-12">
                            <hr>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="createLoginToggle" name="create_login" value="1" onchange="toggleLoginFields()">
                                <label class="form-check-label fw-bold" for="createLoginToggle">Create Login Account</label>
                            </div>
                        </div>
                        <div id="loginFields" style="display:none;" class="col-12">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Username</label>
                                    <input type="text" name="username" id="f_username" class="form-control" placeholder="Auto: employee code">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Password</label>
                                    <input type="text" name="password" id="f_password" class="form-control" value="employee123">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Role</label>
                                    <select name="role_id" id="f_role" class="form-select">
                                        <option value="">Select Role</option>
                                        <?php foreach ($roles as $r): ?>
                                        <option value="<?php echo $r['id']; ?>"><?php echo sanitize($r['description'] ?? $r['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var allDesignations = <?php echo json_encode($designations); ?>;
var nextEmployeeCode = '<?php echo generateEmployeeCode($pdo); ?>';

document.addEventListener('DOMContentLoaded', function() {
    document.querySelector('[data-bs-target="#employeeModal"]').addEventListener('click', function() {
        document.getElementById('modalTitle').textContent = 'Add Employee';
        document.getElementById('formAction').value = 'add';
        document.getElementById('formId').value = '';
        document.getElementById('f_code').value = nextEmployeeCode;
        document.getElementById('f_code').readOnly = true;
        document.getElementById('f_fname').value = '';
        document.getElementById('f_lname').value = '';
        document.getElementById('f_mobile').value = '';
        document.getElementById('f_email').value = '';
        document.getElementById('f_dept').value = '';
        filterDesignations('', null);
        document.getElementById('f_date').value = '';
        document.getElementById('f_salary').value = '';
        document.getElementById('f_address').value = '';
        document.getElementById('f_city').value = '';
        document.getElementById('f_state').value = '';
        document.getElementById('f_pincode').value = '';
        document.getElementById('f_status').value = '1';
        document.getElementById('createLoginToggle').checked = false;
        document.getElementById('loginFields').style.display = 'none';
        document.getElementById('f_password').disabled = false;
    });

    document.getElementById('employeeModal').addEventListener('show.bs.modal', function(event) {
        if (document.getElementById('formAction').value === 'add') {
            document.getElementById('f_code').value = nextEmployeeCode;
            document.getElementById('f_code').readOnly = true;
        } else {
            document.getElementById('f_code').readOnly = false;
        }
    });
});

function filterDesignations(deptId, selectedId) {
    var sel = document.getElementById('f_desig');
    sel.innerHTML = '<option value="">Select</option>';
    allDesignations.forEach(function(d) {
        if (d.department_id == deptId || !deptId) {
            var opt = document.createElement('option');
            opt.value = d.id;
            opt.textContent = d.name;
            if (selectedId && d.id == selectedId) opt.selected = true;
            sel.appendChild(opt);
        }
    });
}

document.getElementById('f_dept').addEventListener('change', function() {
    filterDesignations(this.value, null);
});

function toggleLoginFields() {
    document.getElementById('loginFields').style.display = document.getElementById('createLoginToggle').checked ? 'block' : 'none';
}

function editEmployee(emp) {
    document.getElementById('modalTitle').textContent = 'Edit Employee';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formId').value = emp.id;
    document.getElementById('f_code').value = emp.employee_code;
    document.getElementById('f_fname').value = emp.first_name;
    document.getElementById('f_lname').value = emp.last_name || '';
    document.getElementById('f_mobile').value = emp.mobile || '';
    document.getElementById('f_email').value = emp.email || '';
    document.getElementById('f_dept').value = emp.department_id || '';
    filterDesignations(emp.department_id, emp.designation_id);
    document.getElementById('f_date').value = emp.joining_date || '';
    document.getElementById('f_salary').value = emp.salary || '';
    document.getElementById('f_address').value = emp.address || '';
    document.getElementById('f_city').value = emp.city || '';
    document.getElementById('f_state').value = emp.state || '';
    document.getElementById('f_pincode').value = emp.pincode || '';
    document.getElementById('f_status').value = emp.status;
    document.getElementById('f_field_staff').value = emp.is_field_staff ? '1' : '0';

    if (emp.user_role_id) {
        document.getElementById('createLoginToggle').checked = true;
        document.getElementById('loginFields').style.display = 'block';
        document.getElementById('f_username').value = emp.username || emp.employee_code;
        document.getElementById('f_role').value = emp.user_role_id;
        document.getElementById('f_password').value = '********';
        document.getElementById('f_password').disabled = true;
    } else {
        document.getElementById('createLoginToggle').checked = false;
        document.getElementById('loginFields').style.display = 'none';
        document.getElementById('f_password').disabled = false;
    }

    new bootstrap.Modal(document.getElementById('employeeModal')).show();
}
</script>

<?php require_once '../includes/footer.php'; ?>
