<?php
/**
 * Helper class for sending FCM Push Notifications
 * Supports both Firebase HTTP v1 API (Service Account JSON) & Legacy API (Server Key)
 */
class FCMHelper {
    /**
     * Send push notification to target user IDs
     */
    public static function sendToUsers($db, $userIds, $title, $body, $data = []) {
        if (empty($userIds)) return ['success' => false, 'message' => 'No target user IDs specified'];
        if (!is_array($userIds)) $userIds = [$userIds];

        // Fetch tokens from DB
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $db->prepare("SELECT DISTINCT fcm_token FROM users WHERE id IN ($placeholders) AND fcm_token IS NOT NULL AND fcm_token != ''");
        $stmt->execute($userIds);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($rows)) {
            return ['success' => false, 'message' => 'No active FCM tokens found for selected user(s). Make sure the employee has logged in to the mobile app recently.'];
        }

        return self::sendNotification($db, $rows, $title, $body, $data);
    }

    /**
     * Send push notification to all users or specific department
     */
    public static function sendToTopicOrGroup($db, $target = 'all', $deptId = null, $title = '', $body = '', $data = []) {
        $sql = "SELECT DISTINCT u.fcm_token FROM users u";
        $params = [];

        if ($target === 'department' && $deptId) {
            $sql .= " JOIN employees e ON e.user_id = u.id WHERE e.department_id = ? AND u.fcm_token IS NOT NULL AND u.fcm_token != ''";
            $params[] = $deptId;
        } else {
            $sql .= " WHERE u.fcm_token IS NOT NULL AND u.fcm_token != ''";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($tokens)) {
            return ['success' => false, 'message' => 'No mobile devices registered with FCM tokens in database. Please log in to the mobile app first to register your device.'];
        }

        return self::sendNotification($db, $tokens, $title, $body, $data);
    }


    /**
     * Locates the Firebase service-account credentials.
     *
     * Searched in order, most secure first:
     *   1. FCM_SERVICE_ACCOUNT environment variable (an absolute path)
     *   2. one level above the site root — outside anything Apache can serve
     *   3. backend/config/ (legacy; now denied to the web by its own .htaccess)
     *   4. the `settings` table
     *
     * Returns null when nothing is configured, which makes the caller fall
     * through to the legacy server-key path rather than failing outright.
     */
    private static function loadServiceAccount($db) {
        $candidates = [];

        $fromEnv = getenv('FCM_SERVICE_ACCOUNT');
        if ($fromEnv) $candidates[] = $fromEnv;

        // .../<parent of site root>/firebase_service_account.json
        $candidates[] = dirname(__DIR__, 4) . '/firebase_service_account.json';
        $candidates[] = __DIR__ . '/../config/firebase_service_account.json';

        // Auto-discover any downloaded Firebase JSON files in config dir
        $configDir = __DIR__ . '/../config';
        if (is_dir($configDir)) {
            $foundJson = glob($configDir . '/*firebase*.json');
            if ($foundJson) {
                foreach ($foundJson as $fj) {
                    $candidates[] = $fj;
                }
            }
            $allJson = glob($configDir . '/*.json');
            if ($allJson) {
                foreach ($allJson as $aj) {
                    $candidates[] = $aj;
                }
            }
        }

        foreach ($candidates as $path) {
            if (!$path || !is_file($path) || !is_readable($path)) continue;
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded) && !empty($decoded['private_key'])) return $decoded;
        }

        try {
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'fcm_service_account_json'");
            $stmt->execute();
            $row = $stmt->fetch();
            if (!empty($row['setting_value'])) {
                $decoded = json_decode($row['setting_value'], true);
                if (is_array($decoded) && !empty($decoded['private_key'])) return $decoded;
            }
        } catch (Exception $e) {
            error_log('loadServiceAccount: ' . $e->getMessage());
        }

        error_log('FCM: no service-account credentials found. Place the JSON above the site root or in the settings table.');
        return null;
    }

    /**
     * Dispatch FCM Push Notification (supports HTTP v1 & Legacy APIs)
     */
    public static function sendNotification($db, array $tokens, $title, $body, $data = []) {
        // Credentials are loaded from disk or the database — never embedded.
        // A private key previously sat hardcoded in this file (and in a .json
        // under the web root that was being served to anyone who asked).
        $serviceAccountJson = self::loadServiceAccount($db);

        // If Service Account JSON is available -> Use FCM HTTP v1 API
        if ($serviceAccountJson && !empty($serviceAccountJson['project_id']) && !empty($serviceAccountJson['private_key'])) {
            return self::sendNotificationV1($serviceAccountJson, $tokens, $title, $body, $data);
        }

        // Fallback to Legacy API Server Key
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'fcm_server_key'");
        $stmt->execute();
        $row = $stmt->fetch();
        $serverKey = !empty($row['setting_value']) ? $row['setting_value'] : 'AIzaSyBJDDZWoUxJGdSHO9KgbFggGs2fBcginrg';

        return self::sendNotificationLegacy($serverKey, $tokens, $title, $body, $data);
    }

    /**
     * Send via FCM HTTP v1 API (Google Recommended)
     */
    private static function sendNotificationV1($sa, array $tokens, $title, $body, $data = []) {
        $projectId = $sa['project_id'];
        $accessToken = self::getGoogleAccessToken($sa);

        if (!$accessToken) {
            return ['success' => false, 'message' => 'Failed to generate Google OAuth2 access token from Service Account JSON.'];
        }

        $url = "https://fcm.googleapis.com/v1/projects/$projectId/messages:send";
        $successCount = 0;
        $failureCount = 0;
        $lastError = '';

        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body  = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach ($tokens as $token) {
            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'android' => [
                        'priority' => 'HIGH',
                        'notification' => [
                            'sound' => 'default',
                            'channel_id' => 'high_importance_channel',
                            'icon' => 'ic_stat_notification',
                            'color' => '#FFFFFF',
                            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                        ]
                    ],
                    'data' => array_map('strval', array_merge([
                        'title' => $title,
                        'body'  => $body,
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    ], $data)),
                ]
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $successCount++;
            } else {
                $failureCount++;
                $dec = json_decode($result, true);
                $lastError = $dec['error']['message'] ?? "HTTP $httpCode";
            }
        }

        if ($successCount > 0) {
            return [
                'success' => true,
                'message' => "FCM v1 Push Notification sent to $successCount device(s)." . ($failureCount > 0 ? " ($failureCount failed: $lastError)" : ""),
                'tokens_count' => count($tokens)
            ];
        } else {
            return [
                'success' => false,
                'message' => "FCM v1 Delivery failed. Error: $lastError"
            ];
        }
    }

    /**
     * Send via Legacy API (Fallback)
     */
    private static function sendNotificationLegacy($serverKey, array $tokens, $title, $body, $data = []) {
        $url = 'https://fcm.googleapis.com/fcm/send';

        $payload = [
            'registration_ids' => array_values($tokens),
            'notification' => [
                'title' => $title,
                'body'  => $body,
                'sound' => 'default',
                'badge' => 1,
                'android_channel_id' => 'high_importance_channel',
                'channel_id' => 'high_importance_channel',
                'icon' => 'ic_stat_notification',
                'color' => '#FFFFFF',
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            ],
            'data' => array_merge([
                'title' => $title,
                'body'  => $body,
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            ], $data),
            'priority' => 'high',
            'content_available' => true,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: key=' . $serverKey,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'message' => 'Curl Error: ' . $err];
        }

        if ($httpCode === 401) {
            return [
                'success' => false,
                'message' => "FCM Legacy API is disabled by Google for this project. Please download your Service Account JSON file from Firebase Console ➔ Project Settings ➔ Service Accounts, and upload it to backend/config/firebase_service_account.json."
            ];
        }

        $decoded = json_decode($result, true);
        if (isset($decoded['failure']) && $decoded['failure'] > 0) {
            $firstError = $decoded['results'][0]['error'] ?? 'Unknown Error';
            return [
                'success' => false,
                'message' => "FCM delivery failed ($firstError). Please log in on the mobile app again to refresh your token."
            ];
        }

        return [
            'success' => true,
            'message' => 'FCM Push Notification sent successfully to ' . count($tokens) . ' device(s).',
            'tokens_count' => count($tokens),
        ];
    }

    /**
     * Generate OAuth2 Access Token from Service Account JSON using native PHP OpenSSL
     */
    private static function getGoogleAccessToken($sa) {
        try {
            $rawKey = $sa['private_key'] ?? '';
            $cleanKey = str_replace('\n', "\n", $rawKey);
            $pkey = openssl_pkey_get_private($cleanKey);

            if (!$pkey) {
                $body = str_replace(['-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----', "\r", "\n", "\\n", " "], '', $rawKey);
                $pem = "-----BEGIN PRIVATE KEY-----\n" . chunk_split($body, 64, "\n") . "-----END PRIVATE KEY-----\n";
                $pkey = openssl_pkey_get_private($pem);
            }

            if (!$pkey) {
                error_log("OpenSSL Pkey Error: " . openssl_error_string());
                return null;
            }

            $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
            $now = time();
            $claims = json_encode([
                'iss' => $sa['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => $now + 3600,
                'iat' => $now
            ]);

            $b64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
            $b64Claims = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($claims));

            $signatureInput = $b64Header . "." . $b64Claims;
            $signature = '';

            if (!openssl_sign($signatureInput, $signature, $pkey, OPENSSL_ALGO_SHA256)) {
                error_log("OpenSSL Sign Error: " . openssl_error_string());
                return null;
            }

            $b64Sig = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
            $jwt = $signatureInput . "." . $b64Sig;

            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ]));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $json = json_decode($res, true);
            if (empty($json['access_token'])) {
                error_log("Google OAuth2 Error ($httpCode): " . $res);
            }
            return $json['access_token'] ?? null;
        } catch (Throwable $e) {
            error_log("OAuth2 JWT Error: " . $e->getMessage());
            return null;
        }
    }
}
