<?php
/*********************************
 * DROPX WALLET API - SINGLE FILE BACKEND
 * Malawi Kwacha (MWK) Wallet System
 * Supports: Balance, Transactions, Top-ups, External Payments
 *********************************/

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * DATABASE CONFIGURATION
 *********************************/
define('DB_HOST', 'localhost');
define('DB_NAME', 'dropx_wallet');
define('DB_USER', 'root');
define('DB_USER', '');
define('DB_PASS', '');

define('CURRENCY', 'MWK');
define('CURRENCY_SYMBOL', 'MK');

/*********************************
 * DATABASE CONNECTION CLASS
 *********************************/
class Database {
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    private $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->exec("set names utf8");
        } catch(PDOException $e) {
            echo "Connection error: " . $e->getMessage();
        }
        return $this->conn;
    }
}

/*********************************
 * RESPONSE HANDLER
 *********************************/
class ResponseHandler {
    public static function success($data = null, $message = 'Success', $code = 200) {
        http_response_code($code);
        echo json_encode([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit();
    }

    public static function error($message = 'Error', $code = 400, $errors = null) {
        http_response_code($code);
        echo json_encode([
            'status' => 'error',
            'message' => $message,
            'errors' => $errors,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit();
    }
}

/*********************************
 * AUTHENTICATION CLASS
 *********************************/
class Auth {
    private $conn;
    private $user_id = null;

    public function __construct($conn) {
        $this->conn = $conn;
        $this->authenticate();
    }

    private function authenticate() {
        // Start session if not started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Check session
        if (!empty($_SESSION['user_id'])) {
            $this->user_id = $_SESSION['user_id'];
            return;
        }

        // Check Bearer token
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        
        if (strpos($authHeader, 'Bearer ') === 0) {
            $token = substr($authHeader, 7);
            $this->authenticateWithToken($token);
            return;
        }

        // Check API key
        $apiKey = $_GET['api_key'] ?? $_POST['api_key'] ?? null;
        if ($apiKey) {
            $this->authenticateWithToken($apiKey);
            return;
        }

        ResponseHandler::error('Authentication required', 401);
    }

    private function authenticateWithToken($token) {
        $stmt = $this->conn->prepare(
            "SELECT id FROM users WHERE api_token = :token 
             AND (api_token_expiry IS NULL OR api_token_expiry > NOW())"
        );
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $this->user_id = $user['id'];
            $_SESSION['user_id'] = $user['id'];
        } else {
            ResponseHandler::error('Invalid or expired token', 401);
        }
    }

    public function getUserId() {
        return $this->user_id;
    }
}

/*********************************
 * WALLET MODEL
 *********************************/
class Wallet {
    private $conn;
    private $table = 'wallets';

    public $id;
    public $user_id;
    public $balance;
    public $currency;
    public $is_active;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    public function getOrCreate($user_id) {
        // Check if wallet exists
        $stmt = $this->conn->prepare(
            "SELECT * FROM {$this->table} WHERE user_id = :user_id LIMIT 1"
        );
        $stmt->execute([':user_id' => $user_id]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($wallet) {
            return $wallet;
        }

        // Create new wallet
        $stmt = $this->conn->prepare(
            "INSERT INTO {$this->table} (user_id, balance, currency) 
             VALUES (:user_id, 0.00, 'MWK')"
        );
        $stmt->execute([':user_id' => $user_id]);
        
        return [
            'id' => $this->conn->lastInsertId(),
            'user_id' => $user_id,
            'balance' => 0.00,
            'currency' => 'MWK',
            'is_active' => 1
        ];
    }

    public function getBalance($user_id) {
        $stmt = $this->conn->prepare(
            "SELECT balance, currency FROM {$this->table} 
             WHERE user_id = :user_id AND is_active = 1"
        );
        $stmt->execute([':user_id' => $user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateBalance($wallet_id, $new_balance) {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table} SET balance = :balance WHERE id = :id"
        );
        return $stmt->execute([
            ':balance' => $new_balance,
            ':id' => $wallet_id
        ]);
    }
}

/*********************************
 * TRANSACTION MODEL
 *********************************/
class Transaction {
    private $conn;
    private $table = 'transactions';

    public function __construct($conn) {
        $this->conn = $conn;
    }

    public function create($data) {
        $stmt = $this->conn->prepare(
            "INSERT INTO {$this->table} 
             (user_id, wallet_id, transaction_type, amount, balance_before, 
              balance_after, description, reference_id, reference_type, status)
             VALUES 
             (:user_id, :wallet_id, :type, :amount, :balance_before, 
              :balance_after, :description, :reference_id, :reference_type, :status)"
        );

        return $stmt->execute([
            ':user_id' => $data['user_id'],
            ':wallet_id' => $data['wallet_id'],
            ':type' => $data['type'],
            ':amount' => $data['amount'],
            ':balance_before' => $data['balance_before'],
            ':balance_after' => $data['balance_after'],
            ':description' => $data['description'],
            ':reference_id' => $data['reference_id'] ?? null,
            ':reference_type' => $data['reference_type'] ?? null,
            ':status' => $data['status'] ?? 'completed'
        ]);
    }

    public function getUserTransactions($user_id, $limit = 50, $offset = 0) {
        $stmt = $this->conn->prepare(
            "SELECT t.*, w.currency 
             FROM {$this->table} t
             JOIN wallets w ON t.wallet_id = w.id
             WHERE t.user_id = :user_id
             ORDER BY t.created_at DESC
             LIMIT :limit OFFSET :offset"
        );
        
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRecentTransactions($user_id, $limit = 10) {
        return $this->getUserTransactions($user_id, $limit, 0);
    }
}

/*********************************
 * TOP-UP REQUEST MODEL
 *********************************/
class TopupRequest {
    private $conn;
    private $table = 'topup_requests';

    public function __construct($conn) {
        $this->conn = $conn;
    }

    public function create($user_id, $amount, $method, $reference) {
        $code = $this->generateReferenceCode();
        $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $stmt = $this->conn->prepare(
            "INSERT INTO {$this->table} 
             (user_id, amount, payment_method, reference_code, status, expires_at)
             VALUES 
             (:user_id, :amount, :method, :code, 'pending', :expires_at)"
        );

        $stmt->execute([
            ':user_id' => $user_id,
            ':amount' => $amount,
            ':method' => $method,
            ':code' => $code,
            ':expires_at' => $expires_at
        ]);

        return [
            'id' => $this->conn->lastInsertId(),
            'reference_code' => $code,
            'amount' => $amount,
            'expires_at' => $expires_at
        ];
    }

    private function generateReferenceCode() {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }
        return $code;
    }

    public function verifyAndComplete($reference_code) {
        try {
            $this->conn->beginTransaction();

            // Get the pending request
            $stmt = $this->conn->prepare(
                "SELECT * FROM {$this->table} 
                 WHERE reference_code = :code AND status = 'pending' 
                 AND expires_at > NOW() FOR UPDATE"
            );
            $stmt->execute([':code' => $reference_code]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                throw new Exception('Invalid or expired reference code');
            }

            // Get user's wallet
            $walletStmt = $this->conn->prepare(
                "SELECT id, balance FROM wallets WHERE user_id = :user_id FOR UPDATE"
            );
            $walletStmt->execute([':user_id' => $request['user_id']]);
            $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);

            // Update wallet balance
            $newBalance = $wallet['balance'] + $request['amount'];
            $updateStmt = $this->conn->prepare(
                "UPDATE wallets SET balance = :balance WHERE id = :id"
            );
            $updateStmt->execute([
                ':balance' => $newBalance,
                ':id' => $wallet['id']
            ]);

            // Create transaction record
            $transStmt = $this->conn->prepare(
                "INSERT INTO transactions 
                 (user_id, wallet_id, transaction_type, amount, balance_before, 
                  balance_after, description, reference_id, reference_type, status)
                 VALUES 
                 (:user_id, :wallet_id, 'credit', :amount, :balance_before, 
                  :balance_after, :description, :reference_id, 'topup', 'completed')"
            );
            
            $transStmt->execute([
                ':user_id' => $request['user_id'],
                ':wallet_id' => $wallet['id'],
                ':amount' => $request['amount'],
                ':balance_before' => $wallet['balance'],
                ':balance_after' => $newBalance,
                ':description' => 'Wallet top-up via ' . $request['payment_method'],
                ':reference_id' => $request['id']
            ]);

            // Update top-up request status
            $updateReqStmt = $this->conn->prepare(
                "UPDATE {$this->table} SET status = 'completed' WHERE id = :id"
            );
            $updateReqStmt->execute([':id' => $request['id']]);

            $this->conn->commit();

            return [
                'success' => true,
                'amount' => $request['amount'],
                'new_balance' => $newBalance,
                'transaction_id' => $this->conn->lastInsertId()
            ];

        } catch (Exception $e) {
            $this->conn->rollBack();
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

/*********************************
 * INITIALIZE DATABASE TABLES
 *********************************/
function initializeDatabase($conn) {
    $queries = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(50) UNIQUE NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            phone VARCHAR(20) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            api_token VARCHAR(64) UNIQUE,
            api_token_expiry DATETIME,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_phone (phone),
            INDEX idx_token (api_token)
        )",
        
        "CREATE TABLE IF NOT EXISTS wallets (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            balance DECIMAL(10,2) DEFAULT 0.00,
            currency VARCHAR(3) DEFAULT 'MWK',
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user (user_id),
            INDEX idx_active (is_active)
        )",
        
        "CREATE TABLE IF NOT EXISTS transactions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            wallet_id INT NOT NULL,
            transaction_type ENUM('credit', 'debit') NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            balance_before DECIMAL(10,2) NOT NULL,
            balance_after DECIMAL(10,2) NOT NULL,
            description VARCHAR(255),
            reference_id VARCHAR(100),
            reference_type ENUM('order', 'topup', 'refund', 'transfer') DEFAULT 'topup',
            status ENUM('pending', 'completed', 'failed', 'cancelled') DEFAULT 'completed',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE CASCADE,
            INDEX idx_user (user_id),
            INDEX idx_wallet (wallet_id),
            INDEX idx_type (transaction_type),
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        )",
        
        "CREATE TABLE IF NOT EXISTS topup_requests (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            payment_method ENUM('airtel_money', 'mpamba', 'bank_transfer') NOT NULL,
            reference_code VARCHAR(20) UNIQUE NOT NULL,
            status ENUM('pending', 'completed', 'expired', 'cancelled') DEFAULT 'pending',
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user (user_id),
            INDEX idx_code (reference_code),
            INDEX idx_status (status)
        )",
        
        "CREATE TABLE IF NOT EXISTS external_partners (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(50) NOT NULL,
            code VARCHAR(20) UNIQUE NOT NULL,
            min_amount DECIMAL(10,2) DEFAULT 100,
            max_amount DECIMAL(10,2) DEFAULT 500000,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        "INSERT IGNORE INTO external_partners (name, code, min_amount, max_amount) VALUES
            ('Airtel Money', 'AIRTEL', 100, 500000),
            ('TNM Mpamba', 'MPAMBA', 100, 500000),
            ('NBS Bank', 'NBS', 1000, 5000000),
            ('FDH Bank', 'FDH', 1000, 5000000),
            ('Standard Bank', 'STANDARD', 1000, 5000000)"
    ];

    foreach ($queries as $query) {
        try {
            $conn->exec($query);
        } catch (PDOException $e) {
            // Ignore if table already exists
        }
    }
}

/*********************************
 * CREATE TEST USER (FOR DEVELOPMENT)
 *********************************/
function createTestUser($conn) {
    // Check if test user exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = 'test@dropx.com'");
    $stmt->execute();
    
    if (!$stmt->fetch()) {
        $token = bin2hex(random_bytes(32));
        $password_hash = password_hash('password123', PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare(
            "INSERT INTO users (username, email, phone, password_hash, api_token, api_token_expiry) 
             VALUES ('testuser', 'test@dropx.com', '0999123456', :password, :token, DATE_ADD(NOW(), INTERVAL 30 DAY))"
        );
        
        $stmt->execute([
            ':password' => $password_hash,
            ':token' => $token
        ]);
        
        $userId = $conn->lastInsertId();
        
        // Create wallet for test user
        $walletStmt = $conn->prepare(
            "INSERT INTO wallets (user_id, balance) VALUES (:user_id, 2500.75)"
        );
        $walletStmt->execute([':user_id' => $userId]);
        $walletId = $conn->lastInsertId();
        
        // Create sample transactions
        $now = date('Y-m-d H:i:s');
        $transactions = [
            [
                'amount' => 1500.00,
                'desc' => 'Wallet Top-up via Airtel Money',
                'date' => date('Y-m-d H:i:s', strtotime('-2 hours'))
            ],
            [
                'amount' => 250.50,
                'desc' => 'Delivery Payment - Order #DRP-2341',
                'date' => date('Y-m-d H:i:s', strtotime('-1 day'))
            ],
            [
                'amount' => 1000.00,
                'desc' => 'Wallet Top-up via Bank Transfer',
                'date' => date('Y-m-d H:i:s', strtotime('-2 days'))
            ],
            [
                'amount' => 75.25,
                'desc' => 'Delivery Payment - Order #DRP-2339',
                'date' => date('Y-m-d H:i:s', strtotime('-3 days'))
            ]
        ];
        
        $balance = 2500.75;
        foreach ($transactions as $t) {
            $type = strpos($t['desc'], 'Top-up') !== false ? 'credit' : 'debit';
            $balanceBefore = $balance;
            
            if ($type === 'credit') {
                $balanceAfter = $balanceBefore + $t['amount'];
            } else {
                $balanceAfter = $balanceBefore - $t['amount'];
            }
            
            $txnStmt = $conn->prepare(
                "INSERT INTO transactions 
                 (user_id, wallet_id, transaction_type, amount, balance_before, balance_after, description, created_at, status)
                 VALUES 
                 (:user_id, :wallet_id, :type, :amount, :balance_before, :balance_after, :desc, :created_at, 'completed')"
            );
            
            $txnStmt->execute([
                ':user_id' => $userId,
                ':wallet_id' => $walletId,
                ':type' => $type,
                ':amount' => $t['amount'],
                ':balance_before' => $balanceBefore,
                ':balance_after' => $balanceAfter,
                ':desc' => $t['desc'],
                ':created_at' => $t['date']
            ]);
            
            $balance = $balanceAfter;
        }
        
        return $token;
    }
    
    return null;
}

/*********************************
 * MAIN API ROUTER
 *********************************/
try {
    // Initialize database connection
    $database = new Database();
    $conn = $database->getConnection();
    
    // Initialize tables
    initializeDatabase($conn);
    
    // Create test user (for development)
    $testToken = createTestUser($conn);
    
    // Get request method and parameters
    $method = $_SERVER['REQUEST_METHOD'];
    $endpoint = $_GET['endpoint'] ?? '';
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = $_POST;
    }

    // Public endpoints (no auth required)
    if ($method === 'GET' && $endpoint === 'test') {
        ResponseHandler::success([
            'message' => 'Wallet API is working',
            'test_token' => $testToken,
            'endpoints' => [
                'GET /wallet.php?endpoint=balance' => 'Get wallet balance',
                'GET /wallet.php?endpoint=transactions' => 'Get recent transactions',
                'POST /wallet.php?endpoint=topup' => 'Create top-up request',
                'POST /wallet.php?endpoint=verify' => 'Verify top-up payment',
                'GET /wallet.php?endpoint=partners' => 'Get external partners',
                'POST /wallet.php?endpoint=login' => 'Login (public)',
                'POST /wallet.php?endpoint=register' => 'Register (public)'
            ]
        ]);
    }

    // Login endpoint (public)
    if ($method === 'POST' && $endpoint === 'login') {
        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';
        
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = :email AND is_active = 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password_hash'])) {
            // Generate new token
            $token = bin2hex(random_bytes(32));
            $updateStmt = $conn->prepare(
                "UPDATE users SET api_token = :token, api_token_expiry = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = :id"
            );
            $updateStmt->execute([':token' => $token, ':id' => $user['id']]);
            
            ResponseHandler::success([
                'user_id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'token' => $token
            ], 'Login successful');
        } else {
            ResponseHandler::error('Invalid email or password', 401);
        }
    }

    // Register endpoint (public)
    if ($method === 'POST' && $endpoint === 'register') {
        $username = $input['username'] ?? '';
        $email = $input['email'] ?? '';
        $phone = $input['phone'] ?? '';
        $password = $input['password'] ?? '';
        
        // Validate input
        $errors = [];
        if (strlen($username) < 3) $errors[] = 'Username must be at least 3 characters';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email';
        if (!preg_match('/^[0-9]{10,12}$/', $phone)) $errors[] = 'Invalid phone number';
        if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters';
        
        if (!empty($errors)) {
            ResponseHandler::error('Validation failed', 400, $errors);
        }
        
        // Check if user exists
        $checkStmt = $conn->prepare(
            "SELECT id FROM users WHERE email = :email OR phone = :phone"
        );
        $checkStmt->execute([':email' => $email, ':phone' => $phone]);
        
        if ($checkStmt->fetch()) {
            ResponseHandler::error('Email or phone already registered', 409);
        }
        
        // Create user
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $token = bin2hex(random_bytes(32));
        
        $stmt = $conn->prepare(
            "INSERT INTO users (username, email, phone, password_hash, api_token, api_token_expiry) 
             VALUES (:username, :email, :phone, :password, :token, DATE_ADD(NOW(), INTERVAL 30 DAY))"
        );
        
        $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':phone' => $phone,
            ':password' => $password_hash,
            ':token' => $token
        ]);
        
        $userId = $conn->lastInsertId();
        
        // Create wallet
        $wallet = new Wallet($conn);
        $wallet->getOrCreate($userId);
        
        ResponseHandler::success([
            'user_id' => $userId,
            'username' => $username,
            'email' => $email,
            'token' => $token
        ], 'Registration successful', 201);
    }

    // Authenticate for protected endpoints
    $auth = new Auth($conn);
    $userId = $auth->getUserId();

    // Route protected endpoints
    switch ($endpoint) {
        case 'balance':
            if ($method === 'GET') {
                $wallet = new Wallet($conn);
                $balance = $wallet->getBalance($userId);
                
                if (!$balance) {
                    $wallet->getOrCreate($userId);
                    $balance = ['balance' => 0.00, 'currency' => 'MWK'];
                }
                
                ResponseHandler::success([
                    'balance' => floatval($balance['balance']),
                    'formatted_balance' => CURRENCY_SYMBOL . ' ' . number_format($balance['balance'], 2),
                    'currency' => $balance['currency'],
                    'wallet_id' => $wallet->id ?? null
                ]);
            }
            break;

        case 'transactions':
            if ($method === 'GET') {
                $type = $_GET['type'] ?? 'recent';
                $limit = intval($_GET['limit'] ?? 50);
                $offset = intval($_GET['offset'] ?? 0);
                
                $transaction = new Transaction($conn);
                
                if ($type === 'recent') {
                    $transactions = $transaction->getRecentTransactions($userId);
                } else {
                    $transactions = $transaction->getUserTransactions($userId, $limit, $offset);
                }
                
                // Format transactions for Flutter app
                $formatted = array_map(function($t) {
                    return [
                        'id' => $t['id'],
                        'amount' => floatval($t['amount']),
                        'formatted_amount' => CURRENCY_SYMBOL . ' ' . number_format($t['amount'], 2),
                        'description' => $t['description'],
                        'type' => $t['transaction_type'],
                        'status' => $t['status'],
                        'date' => $t['created_at'],
                        'formatted_date' => date('M d, Y • h:i A', strtotime($t['created_at'])),
                        'balance_after' => floatval($t['balance_after'])
                    ];
                }, $transactions);
                
                ResponseHandler::success([
                    'transactions' => $formatted,
                    'total' => count($formatted),
                    'type' => $type
                ]);
            }
            break;

        case 'topup':
            if ($method === 'POST') {
                $amount = floatval($input['amount'] ?? 0);
                $method = $input['method'] ?? '';
                
                // Validate
                if ($amount < 100) {
                    ResponseHandler::error('Minimum top-up amount is MWK 100', 400);
                }
                if ($amount > 500000) {
                    ResponseHandler::error('Maximum top-up amount is MWK 500,000', 400);
                }
                
                $validMethods = ['airtel_money', 'mpamba', 'bank_transfer'];
                if (!in_array($method, $validMethods)) {
                    ResponseHandler::error('Invalid payment method', 400);
                }
                
                // Generate reference
                $reference = 'REF-' . strtoupper(substr(md5(uniqid()), 0, 8));
                
                $topup = new TopupRequest($conn);
                $result = $topup->create($userId, $amount, $method, $reference);
                
                // Get payment instructions based on method
                $instructions = [
                    'airtel_money' => [
                        'Send to: 0999 000 000',
                        'Reference: ' . $result['reference_code'],
                        'Amount: MWK ' . number_format($amount, 2)
                    ],
                    'mpamba' => [
                        'Send to: 0888 111 111',
                        'Reference: ' . $result['reference_code'],
                        'Amount: MWK ' . number_format($amount, 2)
                    ],
                    'bank_transfer' => [
                        'Bank: NBS Bank',
                        'Account: 1234567890',
                        'Reference: DROPX-' . $result['reference_code'],
                        'Amount: MWK ' . number_format($amount, 2)
                    ]
                ];
                
                ResponseHandler::success([
                    'reference_code' => $result['reference_code'],
                    'amount' => $amount,
                    'formatted_amount' => CURRENCY_SYMBOL . ' ' . number_format($amount, 2),
                    'method' => $method,
                    'expires_at' => $result['expires_at'],
                    'expiry_minutes' => 1440,
                    'instructions' => $instructions[$method],
                    'note' => 'After making payment, your wallet will be updated within 5-10 minutes'
                ], 'Top-up request created');
            }
            break;

        case 'verify':
            if ($method === 'POST') {
                $reference_code = $input['reference_code'] ?? '';
                
                if (empty($reference_code)) {
                    ResponseHandler::error('Reference code required', 400);
                }
                
                $topup = new TopupRequest($conn);
                $result = $topup->verifyAndComplete($reference_code);
                
                if ($result['success']) {
                    ResponseHandler::success([
                        'amount' => $result['amount'],
                        'formatted_amount' => CURRENCY_SYMBOL . ' ' . number_format($result['amount'], 2),
                        'new_balance' => $result['new_balance'],
                        'formatted_balance' => CURRENCY_SYMBOL . ' ' . number_format($result['new_balance'], 2),
                        'transaction_id' => $result['transaction_id']
                    ], 'Payment verified and wallet updated');
                } else {
                    ResponseHandler::error($result['error'], 400);
                }
            }
            break;

        case 'partners':
            if ($method === 'GET') {
                $stmt = $conn->prepare("SELECT * FROM external_partners WHERE is_active = 1");
                $stmt->execute();
                $partners = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                ResponseHandler::success([
                    'partners' => $partners,
                    'note' => 'External partners for wallet top-up'
                ]);
            }
            break;

        case 'debit':
            if ($method === 'POST') {
                // For order payments
                $amount = floatval($input['amount'] ?? 0);
                $order_id = $input['order_id'] ?? '';
                $description = $input['description'] ?? 'Order payment';
                
                if ($amount <= 0) {
                    ResponseHandler::error('Invalid amount', 400);
                }
                
                try {
                    $conn->beginTransaction();
                    
                    // Get wallet
                    $walletStmt = $conn->prepare(
                        "SELECT id, balance FROM wallets WHERE user_id = :user_id AND is_active = 1 FOR UPDATE"
                    );
                    $walletStmt->execute([':user_id' => $userId]);
                    $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$wallet) {
                        throw new Exception('Wallet not found');
                    }
                    
                    if ($wallet['balance'] < $amount) {
                        throw new Exception('Insufficient balance');
                    }
                    
                    // Update balance
                    $newBalance = $wallet['balance'] - $amount;
                    $updateStmt = $conn->prepare(
                        "UPDATE wallets SET balance = :balance WHERE id = :id"
                    );
                    $updateStmt->execute([
                        ':balance' => $newBalance,
                        ':id' => $wallet['id']
                    ]);
                    
                    // Create transaction
                    $transStmt = $conn->prepare(
                        "INSERT INTO transactions 
                         (user_id, wallet_id, transaction_type, amount, balance_before, 
                          balance_after, description, reference_id, reference_type, status)
                         VALUES 
                         (:user_id, :wallet_id, 'debit', :amount, :balance_before, 
                          :balance_after, :description, :reference_id, 'order', 'completed')"
                    );
                    
                    $transStmt->execute([
                        ':user_id' => $userId,
                        ':wallet_id' => $wallet['id'],
                        ':amount' => $amount,
                        ':balance_before' => $wallet['balance'],
                        ':balance_after' => $newBalance,
                        ':description' => $description,
                        ':reference_id' => $order_id
                    ]);
                    
                    $conn->commit();
                    
                    ResponseHandler::success([
                        'transaction_id' => $conn->lastInsertId(),
                        'amount' => $amount,
                        'formatted_amount' => CURRENCY_SYMBOL . ' ' . number_format($amount, 2),
                        'new_balance' => $newBalance,
                        'formatted_balance' => CURRENCY_SYMBOL . ' ' . number_format($newBalance, 2)
                    ], 'Payment successful');
                    
                } catch (Exception $e) {
                    $conn->rollBack();
                    ResponseHandler::error($e->getMessage(), 400);
                }
            }
            break;

        case 'stats':
            if ($method === 'GET') {
                // Get wallet statistics
                $wallet = new Wallet($conn);
                $balance = $wallet->getBalance($userId);
                
                // Get transaction counts
                $stmt = $conn->prepare(
                    "SELECT 
                        COUNT(*) as total_transactions,
                        SUM(CASE WHEN transaction_type = 'credit' THEN 1 ELSE 0 END) as total_credits,
                        SUM(CASE WHEN transaction_type = 'debit' THEN 1 ELSE 0 END) as total_debits,
                        SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) as total_credited,
                        SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) as total_debited
                     FROM transactions 
                     WHERE user_id = :user_id"
                );
                $stmt->execute([':user_id' => $userId]);
                $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                
                ResponseHandler::success([
                    'balance' => floatval($balance['balance'] ?? 0),
                    'formatted_balance' => CURRENCY_SYMBOL . ' ' . number_format($balance['balance'] ?? 0, 2),
                    'total_transactions' => intval($stats['total_transactions'] ?? 0),
                    'total_credits' => intval($stats['total_credits'] ?? 0),
                    'total_debits' => intval($stats['total_debits'] ?? 0),
                    'total_credited' => floatval($stats['total_credited'] ?? 0),
                    'total_debited' => floatval($stats['total_debited'] ?? 0)
                ]);
            }
            break;

        default:
            ResponseHandler::error('Invalid endpoint', 404);
    }

} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}
?>