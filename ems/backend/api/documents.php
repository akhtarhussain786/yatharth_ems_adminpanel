<?php
function autoMigrateDocumentsTable($db) {
    $cols = $db->query("SHOW COLUMNS FROM documents");
    $existing = [];
    while ($c = $cols->fetch()) $existing[] = $c['Field'];
    $needed = [
        'file_size' => 'INT DEFAULT NULL',
        'file_type' => 'VARCHAR(50) DEFAULT NULL',
        'expiry_date' => 'DATE DEFAULT NULL',
        'is_public' => 'TINYINT(1) DEFAULT 0',
        'status' => 'TINYINT(1) DEFAULT 1',
        'uploaded_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ];
    foreach ($needed as $col => $def) {
        if (!in_array($col, $existing)) {
            try {
                $db->exec("ALTER TABLE documents ADD COLUMN $col $def");
            } catch (Exception $e) {
                error_log("Documents migration $col: " . $e->getMessage());
            }
        }
    }
}

function handleDocumentRequest($action, $param) {
    $db = (new Database())->getConnection();
    autoMigrateDocumentsTable($db);
    $auth = AuthMiddleware::authenticate();
    $data = json_decode($GLOBALS['_RAW_INPUT'] ?? file_get_contents('php://input'), true) ?? $_POST;

    switch ($action) {
        case 'list':
            AuthMiddleware::checkRole(['super_admin', 'hr']);
            return getDocumentList($db, $data);
        case 'my':
            return getMyDocuments($db, $auth);
        case 'upload':
            return uploadDocument($db, $auth, $data);
        case 'delete':
            return deleteDocument($db, $auth, $param);
        default:
            return ['success' => false, 'message' => 'Invalid document action'];
    }
}

function getDocumentList($db, $data) {
    $categoryId = $data['category_id'] ?? '';
    $employeeId = $data['employee_id'] ?? '';

    $sql = "SELECT d.*, dc.name as category_name, e.first_name, e.last_name, e.employee_code
            FROM documents d
            LEFT JOIN document_categories dc ON dc.id = d.category_id
            LEFT JOIN employees e ON e.id = d.employee_id
            WHERE 1=1";
    $params = [];

    if ($categoryId) { $sql .= " AND d.category_id = ?"; $params[] = $categoryId; }
    if ($employeeId) { $sql .= " AND d.employee_id = ?"; $params[] = $employeeId; }

    $sql .= " ORDER BY d.uploaded_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['category'] = documentCategorySlug($row['category_name'] ?? '');
        $row['filename'] = $row['title'];
    }
    return ['success' => true, 'data' => $rows];
}

function getMyDocuments($db, $auth) {
    $eid = $auth['employee_id'];
    $stmt = $db->prepare("
        SELECT d.*, dc.name as category_name
        FROM documents d
        LEFT JOIN document_categories dc ON dc.id = d.category_id
        WHERE d.employee_id = ? OR d.is_public = 1
        ORDER BY d.uploaded_at DESC
    ");
    $stmt->execute([$eid]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['category'] = documentCategorySlug($row['category_name'] ?? '');
        $row['filename'] = $row['title'];
    }
    return ['success' => true, 'data' => $rows];
}

function documentCategorySlug($categoryName) {
    $slugMap = [
        'id_proof' => 'id_proof',
        'ID Proof' => 'id_proof',
        'Id Proof' => 'id_proof',
        'Certificate' => 'certificate',
        'HR Document' => 'hr',
        'HR' => 'hr',
        'Other' => 'other',
    ];
    return $slugMap[$categoryName] ?? strtolower(str_replace(' ', '_', $categoryName));
}

function getDocumentCategoryId($db, $category) {
    if (!$category) return null;
    if (is_numeric($category)) return (int)$category;

    // Map slug -> category name
    $nameMap = [
        'id_proof' => 'ID Proof',
        'certificate' => 'Certificate',
        'hr' => 'HR Document',
        'other' => 'Other',
    ];
    $name = $nameMap[$category] ?? ucwords(str_replace('_', ' ', $category));

    $stmt = $db->prepare("SELECT id FROM document_categories WHERE name = ? LIMIT 1");
    $stmt->execute([$name]);
    $existing = $stmt->fetchColumn();
    if ($existing) return (int)$existing;

    $stmt = $db->prepare("INSERT INTO document_categories (name) VALUES (?)");
    $stmt->execute([$name]);
    return (int)$db->lastInsertId();
}

function uploadDocument($db, $auth, $data) {
    $employeeId = $data['employee_id'] ?? $_POST['employee_id'] ?? $auth['employee_id'];
    $categoryId = $data['category_id'] ?? $_POST['category_id'] ?? null;
    $categorySlug = $data['category'] ?? $_POST['category'] ?? null;
    if (!$categoryId && $categorySlug) {
        $categoryId = getDocumentCategoryId($db, $categorySlug);
    }
    $title = Validator::sanitize($data['title'] ?? $_POST['title'] ?? '');
    $expiryDate = $data['expiry_date'] ?? $_POST['expiry_date'] ?? null;
    $isPublic = (int)($data['is_public'] ?? $_POST['is_public'] ?? 0);

    // Debug logging
    error_log("Document upload request - POST keys: " . implode(',', array_keys($_POST)));
    error_log("Document upload request - FILES keys: " . implode(',', array_keys($_FILES)));
    error_log("Document upload request - JSON data keys: " . implode(',', array_keys($data)));

    $filePath = '';
    $fileType = '';
    $fileSize = 0;

    // Try file upload from multiple field names
    $uploadedFile = null;
    $fileFieldNames = ['photo', 'file', 'document', 'document_file', 'attachment', 'upload', 'image'];
    foreach ($fileFieldNames as $fieldName) {
        if (isset($_FILES[$fieldName]) && $_FILES[$fieldName]['error'] === UPLOAD_ERR_OK) {
            $uploadedFile = $_FILES[$fieldName];
            break;
        }
    }

    if ($uploadedFile) {
        $result = uploadFileDocument($uploadedFile, 'documents');
        if ($result) {
            $filePath = $result['path'];
            $fileType = $result['type'];
            $fileSize = $result['size'];
        } else {
            error_log("Document upload failed: extension not allowed - " . ($uploadedFile['name'] ?? ''));
        }
    }

    // Try base64 from multiple field names
    if (!$filePath) {
        $base64Fields = ['photo', 'file', 'document', 'document_file', 'attachment', 'file_base64', 'base64', 'image'];
        foreach ($base64Fields as $fieldName) {
            if (!empty($data[$fieldName])) {
                $result = uploadBase64Document($data[$fieldName], 'documents');
                if ($result) {
                    $filePath = $result['path'];
                    $fileType = $result['type'];
                    $fileSize = $result['size'];
                    break;
                }
            }
        }
    }

    // Try direct file path
    if (!$filePath && !empty($data['file_path'])) {
        $filePath = $data['file_path'];
        $fileType = $data['file_type'] ?? '';
        $fileSize = (int)($data['file_size'] ?? 0);
    }

    if (!$filePath) {
        error_log("Document upload failed: no file found in any source");
        return ['success' => false, 'message' => 'File is required', 'debug' => ['post' => array_keys($_POST), 'files' => array_keys($_FILES), 'json' => array_keys($data)]];
    }

    // Auto-generate title from filename if not provided
    if (!$title) {
        $fileForTitle = $_FILES['photo'] ?? $_FILES['file'] ?? null;
        if ($fileForTitle) {
            $title = pathinfo($fileForTitle['name'], PATHINFO_FILENAME);
        } elseif (!empty($data['file_name'])) {
            $title = pathinfo($data['file_name'], PATHINFO_FILENAME);
        } else {
            $title = 'Document ' . date('d-m-Y');
        }
    }

    $stmt = $db->prepare("
        INSERT INTO documents (employee_id, category_id, title, file_path, file_type, file_size, expiry_date, is_public)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$employeeId, $categoryId, $title, $filePath, $fileType, $fileSize, $expiryDate, $isPublic]);

    return ['success' => true, 'message' => 'Document uploaded', 'id' => $db->lastInsertId()];
}

function deleteDocument($db, $auth, $id) {
    if (!$id) return ['success' => false, 'message' => 'Document ID required'];

    $stmt = $db->prepare("SELECT file_path FROM documents WHERE id = ?");
    $stmt->execute([$id]);
    $doc = $stmt->fetch();

    if (!$doc) return ['success' => false, 'message' => 'Document not found'];

    $filePath = UPLOAD_PATH . str_replace('uploads/', '', $doc['file_path']);
    if (file_exists($filePath)) unlink($filePath);

    $stmt = $db->prepare("DELETE FROM documents WHERE id = ?");
    $stmt->execute([$id]);

    return ['success' => true, 'message' => 'Document deleted'];
}

function uploadFileDocument($file, $subdir) {
    $targetDir = UPLOAD_PATH . $subdir . '/';
    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0777, true)) {
            error_log("Document upload: cannot create dir $targetDir");
            return null;
        }
    }
    if (!is_writable($targetDir)) {
        error_log("Document upload: dir not writable $targetDir");
        return null;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv'];
    if (!in_array($ext, $allowed)) {
        error_log("Document upload: extension '$ext' not allowed");
        return null;
    }

    $filename = $subdir . '_' . time() . '_' . uniqid() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $targetDir . $filename)) {
        error_log("Document upload: move_uploaded_file failed for " . $file['tmp_name']);
        return null;
    }

    return [
        'path' => 'uploads/' . $subdir . '/' . $filename,
        'type' => $ext,
        'size' => $file['size'],
    ];
}

function uploadBase64Document($base64Data, $subdir) {
    $targetDir = UPLOAD_PATH . $subdir . '/';
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

    $ext = 'bin';

    // Case 1: Data URL with prefix (data:image/jpeg;base64,...)
    if (preg_match('/^data:(\w+\/\w+);base64,/', $base64Data, $type)) {
        $mimeType = $type[1];
        $extMap = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
            'application/pdf' => 'pdf', 'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'text/plain' => 'txt', 'application/octet-stream' => 'bin',
        ];
        $ext = $extMap[$mimeType] ?? 'bin';
        $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
    } else {
        // Case 2: Plain base64 without prefix - detect type from magic bytes
        $decoded = base64_decode($base64Data, true);
        if ($decoded === false) {
            error_log("Document upload: invalid base64 data");
            return null;
        }
        $mimeType = mime_content_type_from_buffer($decoded);
        $extMap = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
            'application/pdf' => 'pdf', 'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'text/plain' => 'txt', 'application/zip' => 'zip',
        ];
        $ext = $extMap[$mimeType] ?? 'bin';
    }

    $base64Data = base64_decode($base64Data, true);
    if ($base64Data === false) {
        error_log("Document upload: base64 decode failed");
        return null;
    }

    if (strlen($base64Data) == 0) {
        error_log("Document upload: empty file data");
        return null;
    }

    $filename = $subdir . '_' . time() . '_' . uniqid() . '.' . $ext;
    $bytes = file_put_contents($targetDir . $filename, $base64Data);
    if ($bytes === false) {
        error_log("Document upload: cannot write to " . $targetDir);
        return null;
    }

    return [
        'path' => 'uploads/' . $subdir . '/' . $filename,
        'type' => $ext,
        'size' => $bytes,
    ];
}

function mime_content_type_from_buffer($data) {
    $finfo = function_exists('finfo_open') ? @finfo_open(FILEINFO_MIME_TYPE) : false;
    if ($finfo) {
        $mime = finfo_buffer($finfo, $data);
        finfo_close($finfo);
        return $mime;
    }
    return 'application/octet-stream';
}
