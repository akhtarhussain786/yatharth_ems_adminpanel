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
            $content = @file_get_contents($path);
            if (!$content) continue;
            $decoded = json_decode($content, true);
            if (is_array($decoded) && !empty($decoded['private_key']) && !empty($decoded['client_email'])) {
                // Verify that the private key is parseable
                $rawKey = $decoded['private_key'];
                $cleanKey = str_replace(['\n', "\r\n"], "\n", $rawKey);
                $pkey = @openssl_pkey_get_private($cleanKey);
                if (!$pkey) {
                    $body = str_replace(['-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----', "\r", "\n", "\\n", " "], '', $rawKey);
                    $pem = "-----BEGIN PRIVATE KEY-----\n" . chunk_split($body, 64, "\n") . "-----END PRIVATE KEY-----\n";
                    $pkey = @openssl_pkey_get_private($pem);
                }
                if ($pkey) {
                    return $decoded;
                }
            }
        }

        try {
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'fcm_service_account_json'");
            $stmt->execute();
            $row = $stmt->fetch();
            if (!empty($row['setting_value'])) {
                $decoded = json_decode($row['setting_value'], true);
                if (is_array($decoded) && !empty($decoded['private_key']) && !empty($decoded['client_email'])) {
                    return $decoded;
                }
            }
        } catch (Exception $e) {
            error_log('loadServiceAccount: ' . $e->getMessage());
        }

        error_log('FCM: no valid service-account credentials found.');
        return null;
    }

    /**
     * Dispatch FCM Push Notification (supports HTTP v1 & Legacy APIs)
     */
    public static function sendNotification($db, array $tokens, $title, $body, $data = []) {
        $serviceAccountJson = self::loadServiceAccount($db);

        // If Service Account JSON is available -> Use FCM HTTP v1 API
        if ($serviceAccountJson && !empty($serviceAccountJson['project_id']) && !empty($serviceAccountJson['private_key'])) {
            $v1Result = self::sendNotificationV1($serviceAccountJson, $tokens, $title, $body, $data);
            if (!empty($v1Result['success'])) {
                return $v1Result;
            }
            // If v1 failed specifically because of token/auth, log and attempt Legacy fallback if key exists
            error_log('FCM v1 failed: ' . ($v1Result['message'] ?? 'Unknown error'));
        }

        // Fallback to Legacy API Server Key if configured
        try {
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'fcm_server_key'");
            $stmt->execute();
            $row = $stmt->fetch();
            $serverKey = !empty($row['setting_value']) ? $row['setting_value'] : null;

            if ($serverKey) {
                return self::sendNotificationLegacy($serverKey, $tokens, $title, $body, $data);
            }
        } catch (Throwable $e) {
            // ignore db error on fallback
        }

        return $v1Result ?? [
            'success' => false,
            'message' => 'FCM Service Account JSON is missing or invalid. Please check backend/config/firebase_service_account.json.'
        ];
    }

    /**
     * Send via FCM HTTP v1 API (Google Recommended)
     */
    private static function sendNotificationV1($sa, array $tokens, $title, $body, $data = []) {
        $projectId = $sa['project_id'];
        $tokenRes = self::getGoogleAccessToken($sa);

        if (!$tokenRes['token']) {
            return [
                'success' => false, 
                'message' => 'Failed to generate Google OAuth2 token: ' . ($tokenRes['error'] ?? 'Unknown reason') . '. Please ensure a fresh Service Account JSON is uploaded.'
            ];
        }

        $accessToken = $tokenRes['token'];
        $url = "https://fcm.googleapis.com/v1/projects/$projectId/messages:send";
        $successCount = 0;
        $failureCount = 0;
        $lastError = '';

        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body  = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Send concurrently in parallel batches using curl_multi for ultra-fast dispatch (10x faster)
        $batchSize = 30;
        $chunks = array_chunk($tokens, $batchSize);

        foreach ($chunks as $chunk) {
            $mh = curl_multi_init();
            $handles = [];

            foreach ($chunk as $idx => $token) {
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
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);

                curl_multi_add_handle($mh, $ch);
                $handles[$idx] = $ch;
            }

            // Execute all handles concurrently
            $running = null;
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running > 0) {
                    curl_multi_select($mh, 0.05);
                }
            } while ($running > 0 && $status === CURLM_OK);

            // Collect results
            foreach ($handles as $ch) {
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $result = curl_multi_getcontent($ch);

                if ($httpCode === 200) {
                    $successCount++;
                } else {
                    $failureCount++;
                    $dec = json_decode($result, true);
                    $lastError = $dec['error']['message'] ?? ($result ?: "HTTP $httpCode");
                }

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }

            curl_multi_close($mh);
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
                'message' => "FCM Legacy API is disabled for this project. Please configure Firebase HTTP v1 Service Account JSON."
            ];
        }

        $decoded = json_decode($result, true);
        if (isset($decoded['failure']) && $decoded['failure'] > 0) {
            $firstError = $decoded['results'][0]['error'] ?? 'Unknown Error';
            return [
                'success' => false,
                'message' => "FCM delivery failed ($firstError). Please log in on the mobile app again to refresh token."
            ];
        }

        return [
            'success' => true,
            'message' => 'FCM Push Notification sent successfully to ' . count($tokens) . ' device(s).',
            'tokens_count' => count($tokens),
        ];
    }

    /**
     * Helper for URL-safe base64
     */
    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Generate OAuth2 Access Token from Service Account JSON with 50-minute caching & clock tolerance
     */
    private static function getGoogleAccessToken($sa) {
        $cacheKey = md5($sa['client_email'] ?? 'default');
        $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fcm_oauth_token_' . $cacheKey . '.json';

        // Check cache (valid if file exists and created within 3000 seconds / 50 mins)
        if (file_exists($cacheFile)) {
            $cached = @json_decode(file_get_contents($cacheFile), true);
            if (!empty($cached['access_token']) && !empty($cached['expires_at']) && $cached['expires_at'] > time()) {
                return ['token' => $cached['access_token'], 'error' => null];
            }
        }

        try {
            $rawKey = $sa['private_key'] ?? '';
            $cleanKey = str_replace(['\n', "\r\n"], "\n", $rawKey);
            $pkey = openssl_pkey_get_private($cleanKey);

            if (!$pkey) {
                $body = str_replace(['-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----', "\r", "\n", "\\n", " "], '', $rawKey);
                $pem = "-----BEGIN PRIVATE KEY-----\n" . chunk_split($body, 64, "\n") . "-----END PRIVATE KEY-----\n";
                $pkey = openssl_pkey_get_private($pem);
            }

            if (!$pkey) {
                $err = openssl_error_string() ?: 'Invalid private key format in JSON';
                error_log("OpenSSL Pkey Error: " . $err);
                return ['token' => null, 'error' => "OpenSSL error: $err"];
            }

            $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
            $now = time();
            // 60-second backward drift tolerance to avoid rejecting due to slight clock difference
            $claims = json_encode([
                'iss' => $sa['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => $now + 3540,
                'iat' => $now - 60
            ]);

            $b64Header = self::base64UrlEncode($header);
            $b64Claims = self::base64UrlEncode($claims);

            $signatureInput = $b64Header . "." . $b64Claims;
            $signature = '';

            if (!openssl_sign($signatureInput, $signature, $pkey, OPENSSL_ALGO_SHA256)) {
                $err = openssl_error_string() ?: 'Failed to sign JWT with private key';
                error_log("OpenSSL Sign Error: " . $err);
                return ['token' => null, 'error' => "Sign error: $err"];
            }

            $b64Sig = self::base64UrlEncode($signature);
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
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                return ['token' => null, 'error' => "cURL error: $curlErr"];
            }

            $json = json_decode($res, true);
            if (empty($json['access_token'])) {
                $googleMsg = $json['error_description'] ?? ($json['error'] ?? "HTTP $httpCode");
                error_log("Google OAuth2 Error ($httpCode): " . $res);
                return ['token' => null, 'error' => "Google OAuth ($googleMsg)"];
            }

            // Cache token for 50 minutes (3000s)
            @file_put_contents($cacheFile, json_encode([
                'access_token' => $json['access_token'],
                'expires_at' => time() + 3000
            ]), LOCK_EX);

            return ['token' => $json['access_token'], 'error' => null];
        } catch (Throwable $e) {
            error_log("OAuth2 JWT Error: " . $e->getMessage());
            return ['token' => null, 'error' => $e->getMessage()];
        }
    }
}
