<?php
class Validator {
    public static function validate($data, $rules) {
        $errors = [];
        foreach ($rules as $field => $ruleSet) {
            $ruleList = explode('|', $ruleSet);
            foreach ($ruleList as $rule) {
                $params = [];
                if (strpos($rule, ':') !== false) {
                    list($rule, $paramStr) = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                }
                $value = $data[$field] ?? null;

                switch ($rule) {
                    case 'required':
                        if ($value === null || $value === '') {
                            $errors[$field][] = "$field is required";
                        }
                        break;
                    case 'email':
                        if ($value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $errors[$field][] = "$field must be a valid email";
                        }
                        break;
                    case 'numeric':
                        if ($value && !is_numeric($value)) {
                            $errors[$field][] = "$field must be numeric";
                        }
                        break;
                    case 'min':
                        if (is_string($value) && strlen($value) < (int)$params[0]) {
                            $errors[$field][] = "$field must be at least {$params[0]} characters";
                        }
                        break;
                    case 'max':
                        if (is_string($value) && strlen($value) > (int)$params[0]) {
                            $errors[$field][] = "$field must not exceed {$params[0]} characters";
                        }
                        break;
                }
            }
        }
        return $errors;
    }

    public static function validateLocation($lat, $lng, $officeLat, $officeLng, $radius) {
        $earthRadius = 6371000;
        $dLat = deg2rad($officeLat - $lat);
        $dLng = deg2rad($officeLng - $lng);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat)) * cos(deg2rad($officeLat)) *
             sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distance = $earthRadius * $c;

        return [
            'within_radius' => $distance <= $radius,
            'distance' => round($distance, 2),
        ];
    }

    public static function sanitize($input) {
        if (is_array($input)) {
            return array_map([self::class, 'sanitize'], $input);
        }
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }
}
