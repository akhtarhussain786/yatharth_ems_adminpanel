<?php
// Ensure clean output buffer for JSON responses
if (ob_get_level()) ob_clean();

require_once __DIR__ . '/config/config.php';

// Catch fatal errors (PHP 7+)
if (PHP_MAJOR_VERSION >= 7) {
    register_shutdown_function(function () {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            if (ob_get_level()) ob_clean();
            http_response_code(500);
            // The file path and line number stay in the log; sending them to
            // the app told the employee nothing and advertised the server's
            // directory layout to anyone watching.
            echo json_encode([
                'success' => false,
                'message' => 'Something went wrong. Please try again.',
            ]);
            error_log("FATAL: " . $error['message'] . " in " . $error['file'] . ":" . $error['line']);
        }
    });
}


set_exception_handler(function ($e) {
    if (ob_get_level()) ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
    ]);
    error_log("Uncaught " . get_class($e) . ": " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    exit;
});

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Read raw input once to avoid php://input consumption issues
$GLOBALS['_RAW_INPUT'] = file_get_contents('php://input');

try {
    $url = $_GET['url'] ?? '';
    $url = rtrim($url, '/');
    $urlParts = explode('/', $url);

    $endpoint = $urlParts[0] ?? '';
    $action = $urlParts[1] ?? '';
    $param = $urlParts[2] ?? '';

    $response = ['success' => false, 'message' => 'Invalid endpoint'];

    switch ($endpoint) {
        case 'auth':
            require_once __DIR__ . '/api/auth.php';
            $response = handleAuthRequest($action);
            break;
        case 'employee':
            require_once __DIR__ . '/api/employee.php';
            $response = handleEmployeeRequest($action, $param);
            break;
        case 'attendance':
            require_once __DIR__ . '/api/attendance.php';
            $response = handleAttendanceRequest($action, $param);
            break;
        case 'dashboard':
            require_once __DIR__ . '/api/dashboard.php';
            $response = handleDashboardRequest($action);
            break;
        case 'reports':
            require_once __DIR__ . '/api/reports.php';
            $response = handleReportRequest($action, $param);
            break;
        case 'settings':
            require_once __DIR__ . '/api/settings.php';
            $response = handleSettingsRequest($action);
            break;
        case 'work_reports':
            require_once __DIR__ . '/api/work_reports.php';
            $response = handleWorkReportRequest($action, $param);
            break;
        case 'tasks':
            require_once __DIR__ . '/api/tasks.php';
            $response = handleTaskRequest($action, $param);
            break;
        case 'leads':
            require_once __DIR__ . '/api/leads.php';
            $response = handleLeadRequest($action, $param);
            break;
        case 'campaigns':
            require_once __DIR__ . '/api/campaigns.php';
            $response = handleCampaignRequest($action, $param);
            break;
        case 'call_reports':
            require_once __DIR__ . '/api/call_reports.php';
            $response = handleCallReportRequest($action, $param);
            break;
        case 'follow_ups':
            require_once __DIR__ . '/api/follow_ups.php';
            $response = handleFollowUpRequest($action, $param);
            break;
        case 'hr_activities':
            require_once __DIR__ . '/api/hr_activities.php';
            $response = handleHRActivityRequest($action, $param);
            break;
        case 'holidays':
            // There is no holidays.php; the holiday list lives in attendance.php.
            // Requiring the missing file made every /holidays call a fatal 500.
            require_once __DIR__ . '/api/attendance.php';
            $response = handleAttendanceRequest('holidays', $action ?: $param);
            break;
        case 'leaves':
            require_once __DIR__ . '/api/leaves.php';
            $response = handleLeaveRequest($action, $param);
            break;
        case 'notices':
            require_once __DIR__ . '/api/notices.php';
            $response = handleNoticeRequest($action);
            break;
        case 'notifications':
            require_once __DIR__ . '/api/notifications.php';
            $response = handleNotificationRequest($action, $param);
            break;
        case 'permissions':
            require_once __DIR__ . '/api/permissions.php';
            $response = handlePermissionRequest($action, $param);
            break;
        case 'travel':
            require_once __DIR__ . '/api/travel.php';
            $response = handleTravelRequest($action, $param);
            break;
        case 'expenses':
            require_once __DIR__ . '/api/expenses.php';
            $response = handleExpenseRequest($action, $param);
            break;
        case 'documents':
            require_once __DIR__ . '/api/documents.php';
            $response = handleDocumentRequest($action, $param);
            break;
        case 'it_tasks':
            require_once __DIR__ . '/api/it_tasks.php';
            $response = handleITTaskRequest($action, $param);
            break;
        case 'accounts':
            require_once __DIR__ . '/api/accounts.php';
            $response = handleAccountRequest($action, $param);
            break;
        case 'chat':
            require_once __DIR__ . '/api/chat.php';
            $response = handleChatRequest($action, $param);
            break;
        case 'meetings':
            require_once __DIR__ . '/api/meetings.php';
            $response = handleMeetingRequest($action, $param);
            break;
        case 'telecaller':
            require_once __DIR__ . '/api/telecaller.php';
            $response = handleTelecallerRequest($action, $param);
            break;
        case 'sales':
            require_once __DIR__ . '/api/sales.php';
            $response = handleSalesRequest($action, $param);
            break;
        case 'salary_deductions':
        case 'salary':
            require_once __DIR__ . '/api/salary_deductions.php';
            if ($endpoint === 'salary') {
                $response = handleSalaryReportRequest($action, $param);
            } else {
                $response = handleSalaryDeductionRequest($action, $param);
            }
            break;
        case 'roles':
            require_once __DIR__ . '/api/roles.php';
            $response = handleRoleRequest($action, $param);
            break;
        case 'cron':
            $cronScript = str_replace('.php', '', $action);
            $cronFile = __DIR__ . '/cron/' . basename($cronScript) . '.php';
            if (file_exists($cronFile)) {
                require_once $cronFile;
                exit;
            } else {
                $response = ['success' => false, 'message' => 'Cron script not found'];
            }
            break;
        case 'app_update':
        case 'app-update':
            // Public by design — see the note in api/app_update.php.
            require_once __DIR__ . '/api/app_update.php';
            $response = handleAppUpdateRequest($action, $param);
            break;
        case 'marketing':
            require_once __DIR__ . '/api/marketing.php';
            $response = handleMarketingRequest($action, $param);
            break;
        case 'face':
            require_once __DIR__ . '/api/face.php';
            $response = handleFaceRequest($action, $param);
            break;
        case 'help':
            require_once __DIR__ . '/api/help.php';
            $response = handleHelpRequest($action, $param);
            break;
        case 'downloads':
            require_once __DIR__ . '/api/downloads.php';
            $response = handleDownloadRequest($action, $param);
            break;
        case 'it':
            // The app calls it/dashboard and it/submit-work; the handler names
            // its actions with underscores.
            require_once __DIR__ . '/api/it_tasks.php';
            $response = handleITTaskRequest(str_replace('-', '_', $action), $param);
            break;
    }

    if (ob_get_level()) ob_clean();
    echo json_encode($response);
} catch (Throwable $e) {
    if (ob_get_level()) ob_clean();
    http_response_code(500);
    error_log("Route " . ($_GET['url'] ?? 'unknown') . " error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => userFacingError($e),
    ]);
}
