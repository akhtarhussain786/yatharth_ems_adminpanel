<?php
/**
 * Shared files listed on the app's Downloads screen.
 *
 * download_screen.dart calls the bare `downloads` endpoint, which had no route
 * and no handler.
 */

function handleDownloadRequest($action, $param) {
    try {
        AuthMiddleware::authenticate();
        $db = (new Database())->getConnection();

        switch ($action) {
            case '':
            case 'list':
                return getDownloads($db);
            case 'categories':
                return getDownloadCategories($db);
            case 'track':
                return trackDownload($db, $param);
            default:
                return ['success' => false, 'message' => 'Invalid downloads action'];
        }
    } catch (Exception $e) {
        error_log('Downloads Error: ' . $e->getMessage());
        return ['success' => false, 'message' => userFacingError($e)];
    }
}

function getDownloads($db) {
    $categoryId = $_GET['category_id'] ?? null;

    $sql = "SELECT d.*, c.name AS category_name
            FROM downloads d
            LEFT JOIN download_categories c ON c.id = d.category_id
            WHERE d.status = 1";
    $params = [];

    if ($categoryId) {
        $sql .= " AND d.category_id = ?";
        $params[] = (int) $categoryId;
    }

    $sql .= " ORDER BY d.created_at DESC LIMIT 200";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $files = $stmt->fetchAll();

    // The app opens file_path directly, so hand back an absolute URL for any
    // path stored relative to the backend root.
    foreach ($files as &$file) {
        $path = $file['file_path'] ?? '';
        if ($path !== '' && stripos($path, 'http') !== 0) {
            $file['file_url'] = rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
        } else {
            $file['file_url'] = $path;
        }
    }
    unset($file);

    return ['success' => true, 'data' => $files];
}

function getDownloadCategories($db) {
    $rows = $db->query("SELECT * FROM download_categories WHERE status = 1 ORDER BY name ASC")->fetchAll();
    return ['success' => true, 'data' => $rows];
}

function trackDownload($db, $id) {
    $id = intval($id);
    if (!$id) return ['success' => false, 'message' => 'Download ID required'];

    $db->prepare("UPDATE downloads SET download_count = download_count + 1 WHERE id = ?")
       ->execute([$id]);

    return ['success' => true, 'message' => 'Recorded'];
}
