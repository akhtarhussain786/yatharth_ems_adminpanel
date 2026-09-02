<?php
/**
 * Release information for the in-app updater.
 *
 * The app is distributed outside the Play Store, so it asks this endpoint on
 * launch whether a newer APK exists.
 *
 * This is deliberately the one unauthenticated endpoint in the API: a forced
 * update has to reach users who are logged out, or whose token has expired, and
 * an app too old to authenticate is exactly the app that most needs updating.
 * Only release metadata is exposed — nothing about employees or the company.
 */

function handleAppUpdateRequest($action, $param) {
    try {
        $db = (new Database())->getConnection();

        switch ($action) {
            case '':
            case 'check':
            case 'latest':
                return getActiveAppUpdate($db);
            default:
                return ['success' => false, 'message' => 'Invalid app update action'];
        }
    } catch (Exception $e) {
        error_log('AppUpdate Error: ' . $e->getMessage());
        // The app treats a failure as "no update" and carries on, so never 500.
        return ['success' => false, 'message' => 'Update information unavailable'];
    }
}

function getActiveAppUpdate($db) {
    $stmt = $db->query("SELECT * FROM app_updates WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    $row = $stmt ? $stmt->fetch() : null;

    if (!$row) {
        // Nothing published. A well-formed response beats an error here: the app
        // just sees that it is already current.
        return [
            'success'         => true,
            'update_available' => false,
            'latest_version'  => null,
            'minimum_version' => null,
            'apk_url'         => '',
            'force_update'    => false,
        ];
    }

    $size = (int) $row['apk_file_size'];

    return [
        'success'         => true,
        'update_available' => true,
        'latest_version'  => $row['latest_version'],
        'minimum_version' => $row['minimum_version'] ?: '0.0.0',
        'apk_url'         => appUpdateAbsoluteUrl($row['apk_url']),
        'apk_file_name'   => $row['apk_file_name'],
        'file_size_bytes' => $size,
        'file_size'       => formatApkSize($size),
        'sha256'          => $row['sha256'] ?: null,
        'update_title'    => $row['update_title'] ?: 'New Update Available',
        'update_message'  => $row['update_message'] ?: 'A new version of the app is available. Please update to get the latest features and improvements.',
        'force_update'    => (bool) $row['force_update'],
        'released_at'     => $row['updated_at'] ?? $row['created_at'],
    ];
}

/** Stored paths are relative to the backend root; the app needs an absolute URL. */
function appUpdateAbsoluteUrl($path) {
    $path = trim((string) $path);
    if ($path === '') return '';
    if (stripos($path, 'http://') === 0 || stripos($path, 'https://') === 0) return $path;
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

function formatApkSize($bytes) {
    $bytes = (int) $bytes;
    if ($bytes <= 0) return '';
    if ($bytes < 1048576) return round($bytes / 1024) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}
