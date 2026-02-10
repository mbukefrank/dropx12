<?php
class Database {
    // For Railway internal MySQL service
    private $host     = 'mysql';  // Service name, not public proxy
    private $port     = '3306';   // Standard MySQL port
    private $db_name  = 'railway';
    private $username = 'root';
    private $password = 'mOewPxIZVaXjRmryXRHPHQzLvYrdXEQo';
    public $conn;

    public function getConnection() {
        $this->conn = null;
        
        // Debug logging
        error_log("Connecting to MySQL at: {$this->host}:{$this->port}");
        
        try {
            $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->db_name};charset=utf8mb4";
            
            $this->conn = new PDO(
                $dsn,
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 5
                ]
            );
            
            error_log("✅ MySQL connected successfully");
            
        } catch(PDOException $exception) {
            error_log("❌ MySQL connection failed: " . $exception->getMessage());
            
            // Fallback to public proxy if internal fails
            $this->connectToPublicProxy();
        }
        
        return $this->conn;
    }
    
    private function connectToPublicProxy() {
        try {
            $this->host = 'hopper.proxy.rlwy.net';
            $this->port = '37295';
            
            $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->db_name};charset=utf8mb4";
            
            $this->conn = new PDO(
                $dsn,
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 10
                ]
            );
            
            error_log("✅ Connected via public proxy");
            
        } catch(PDOException $e) {
            echo json_encode([
                'success' => false,
                'message' => 'All database connections failed',
                'internal_error' => $exception->getMessage() ?? 'Unknown',
                'proxy_error' => $e->getMessage()
            ]);
            exit;
        }
    }
}
?>