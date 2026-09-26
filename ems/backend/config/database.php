<?php
class Database {
    private $host = 'localhost';
    private $db_name = 'yatharth_ems_db';
    private $username = 'yatharth_yatharth_ems_db1';
    private $password = 'ems_db@1122';
    private $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            // 1. Live server credentials (Hostinger / cPanel)
            $this->conn = new PDO(
                "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            // 2. Fallback for Localhost XAMPP environment (user: root, pass: empty)
            try {
                $this->conn = new PDO(
                    "mysql:host=localhost;dbname={$this->db_name};charset=utf8mb4",
                    'root',
                    '',
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
            } catch (PDOException $e2) {
                // 3. Fallback for alternate local database name if needed
                try {
                    $this->conn = new PDO(
                        "mysql:host=localhost;dbname=ems_db;charset=utf8mb4",
                        'root',
                        '',
                        [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES => false,
                        ]
                    );
                } catch (PDOException $e3) {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
                    exit;
                }
            }
        }
        return $this->conn;
    }
}
