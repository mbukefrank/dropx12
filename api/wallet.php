<?php
/*********************************
 * DROPX WALLET API
 * Malawi Kwacha (MWK) Wallet System
 * Supports: Balance, Transactions, Top-ups, External Payments
 *********************************/

/*********************************
 * CORS Configuration
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * SESSION CONFIG
 *********************************/
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * BASE URL CONFIGURATION
 *********************************/
$baseUrl = "https://dropx12-production.up.railway.app";

/*********************************
 * CONSTANTS (check if already defined)
 *********************************/
if (!defined('CURRENCY')) define('CURRENCY', 'MWK');
if (!defined('CURRENCY_SYMBOL')) define('CURRENCY_SYMBOL', 'MK');

/*********************************
 * INITIALIZATION & HELPER FUNCTIONS
 *********************************/
function initDatabase() {
    $db = new Database();
    return $db->getConnection();
}

function getBaseUrl() {
    global $baseUrl;
    return $baseUrl;
}

/*********************************
 * AUTHENTICATION FUNCTION
 *********************************/
function authenticateUser($conn) {
    // Check session
    if (!empty($_SESSION['user_id'])) {
        return $_SESSION['user_id'];
    }

    // Check Bearer token
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (strpos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
        return authenticateWithToken($conn, $token);
    }

    // Check API key in query params
    $apiKey = $_GET['api_key'] ?? $_POST['api_key'] ?? null;
    if ($apiKey) {
        return authenticateWithToken($conn, $apiKey);
    }

    return null;
}

function authenticateWithToken($conn, $token) {
    $stmt = $conn->prepare(
        "SELECT id FROM users WHERE api_token = :token 
         AND (api_token_expiry IS NULL OR api_token_expiry > NOW())"
    );
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        return $user['id'];
    }
    
    return null;
}

/*********************************
 * WALLET FUNCTIONS
 *********************************/
function getOrCreateWallet($conn, $user_id) {
    // Check if wallet exists
    $stmt = $conn->prepare("SELECT * FROM wallets WHERE user_id = :user_id LIMIT 1");
    $stmt->execute([':user_id' => $user_id]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($wallet) {
        return $wallet;
    }

    // Create new wallet
    $stmt = $conn->prepare(
        "INSERT INTO wallets (user_id, balance, currency) VALUES (:user_id, 0.00, 'MWK')"
    );
    $stmt->execute([':user_id' => $user_id]);
    
    return [
        'id' => $conn->lastInsertId(),
        'user_id' => $user_id,
        'balance' => 0.00,
        'currency' => 'MWK',
        'is_active' => 1
    ];
}

function getWalletBalance($conn, $user_id) {
    $stmt = $conn->prepare(
        "SELECT balance, currency FROM wallets WHERE user_id = :user_id AND is_active = 1"
    );
    $stmt->execute([':user_id' => $user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getUserTransactions($conn, $user_id, $limit = 50, $offset = 0) {
    $stmt = $conn->prepare(
        "SELECT t.*, w.currency 
         FROM transactions t
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

function createTopupRequest($conn, $user_id, $amount, $method) {
    // Generate reference code
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $characters[random_int(0, strlen($characters) - 1)];
    }
    
    $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $stmt = $conn->prepare(
        "INSERT INTO topup_requests 
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
        'id' => $conn->lastInsertId(),
        'reference_code' => $code,
        'amount' => $amount,
        'expires_at' => $expires_at
    ];
}

function verifyAndCompleteTopup($conn, $reference_code) {
    try {
        $conn->beginTransaction();

        // Get the pending request
        $stmt = $conn->prepare(
            "SELECT * FROM topup_requests 
             WHERE reference_code = :code AND status = 'pending' 
             AND expires_at > NOW() FOR UPDATE"
        );
        $stmt->execute([':code' => $reference_code]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            throw new Exception('Invalid or expired reference code');
        }

        // Get user's wallet
        $walletStmt = $conn->prepare(
            "SELECT id, balance FROM wallets WHERE user_id = :user_id FOR UPDATE"
        );
        $walletStmt->execute([':user_id' => $request['user_id']]);
        $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);

        // Update wallet balance
        $newBalance = $wallet['balance'] + $request['amount'];
        $updateStmt = $conn->prepare(
            "UPDATE wallets SET balance = :balance WHERE id = :id"
        );
        $updateStmt->execute([
            ':balance' => $newBalance,
            ':id' => $wallet['id']
        ]);

        // Create transaction record
        $transStmt = $conn->prepare(
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
        $updateReqStmt = $conn->prepare(
            "UPDATE topup_requests SET status = 'completed' WHERE id = :id"
        );
        $updateReqStmt->execute([':id' => $request['id']]);

        $conn->commit();

        return [
            'success' => true,
            'amount' => $request['amount'],
            'new_balance' => $newBalance,
            'transaction_id' => $conn->lastInsertId()
        ];

    } catch (Exception $e) {
        $conn->rollBack();
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

function processPayment($conn, $user_id, $amount, $order_id, $description) {
    try {
        $conn->beginTransaction();

        // Get wallet
        $walletStmt = $conn->prepare(
            "SELECT id, balance FROM wallets WHERE user_id = :user_id AND is_active = 1 FOR UPDATE"
        );
        $walletStmt->execute([':user_id' => $user_id]);
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
            ':user_id' => $user_id,
            ':wallet_id' => $wallet['id'],
            ':amount' => $amount,
            ':balance_before' => $wallet['balance'],
            ':balance_after' => $newBalance,
            ':description' => $description,
            ':reference_id' => $order_id
        ]);

        $conn->commit();

        return [
            'success' => true,
            'transaction_id' => $conn->lastInsertId(),
            'amount' => $amount,
            'new_balance' => $newBalance
        ];

    } catch (Exception $e) {
        $conn->rollBack();
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

function getExternalPartners($conn) {
    $stmt = $conn->prepare("SELECT * FROM external_partners WHERE is_active = 1");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getWalletStats($conn, $user_id) {
    // Get wallet balance
    $balance = getWalletBalance($conn, $user_id);
    
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
    $stmt->execute([':user_id' => $user_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return [
        'balance' => floatval($balance['balance'] ?? 0),
        'total_transactions' => intval($stats['total_transactions'] ?? 0),
        'total_credited' => floatval($stats['total_credited'] ?? 0),
        'total_debited' => floatval($stats['total_debited'] ?? 0)
    ];
}

/*********************************
 * FORMATTING FUNCTIONS
 *********************************/
function formatTransaction($t) {
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
}

/*********************************
 * ROUTER - EXACT PATTERN FROM merchants.php
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $requestUri = $_SERVER['REQUEST_URI'];
    
    // Remove query string if present
    $path = parse_url($requestUri, PHP_URL_PATH);
    $queryString = parse_url($requestUri, PHP_URL_QUERY);
    
    // Parse query parameters
    parse_str($queryString ?? '', $queryParams);
    
    error_log("=== WALLET ROUTER DEBUG ===");
    error_log("Full URI: " . $requestUri);
    error_log("Path: " . $path);
    error_log("Query String: " . ($queryString ?: 'none'));
    error_log("Method: " . $method);
    
    // Initialize database
    $conn = initDatabase();
    $baseUrl = getBaseUrl();
    
    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }
    
    // Get endpoint from query string (like in merchants.php)
    $endpoint = $_GET['endpoint'] ?? '';
    
    // Public endpoints (no auth required)
    if ($endpoint === 'test') {
        ResponseHandler::success([
            'message' => 'Wallet API is working',
            'endpoints' => [
                'GET ?endpoint=balance' => 'Get wallet balance',
                'GET ?endpoint=transactions' => 'Get recent transactions',
                'GET ?endpoint=stats' => 'Get wallet statistics',
                'GET ?endpoint=partners' => 'Get external partners',
                'POST ?endpoint=topup' => 'Create top-up request',
                'POST ?endpoint=verify' => 'Verify top-up payment',
                'POST ?endpoint=debit' => 'Process payment',
                'POST ?endpoint=login' => 'Login',
                'POST ?endpoint=register' => 'Register'
            ]
        ]);
    }
    
    // Login endpoint (public)
    if ($method === 'POST' && $endpoint === 'login') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) $input = $_POST;
        
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
            
            $_SESSION['user_id'] = $user['id'];
            
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
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) $input = $_POST;
        
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
        getOrCreateWallet($conn, $userId);
        
        ResponseHandler::success([
            'user_id' => $userId,
            'username' => $username,
            'email' => $email,
            'token' => $token
        ], 'Registration successful', 201);
    }
    
    // Authenticate for protected endpoints
    $userId = authenticateUser($conn);
    
    // If no user ID and not a public endpoint, return error
    if (!$userId && !in_array($endpoint, ['test', 'login', 'register'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    // Route protected endpoints based on $endpoint (like in merchants.php)
    switch ($endpoint) {
        case 'balance':
            if ($method === 'GET') {
                $wallet = getOrCreateWallet($conn, $userId);
                $balance = getWalletBalance($conn, $userId);
                
                ResponseHandler::success([
                    'balance' => floatval($balance['balance'] ?? 0),
                    'display_balance' => CURRENCY_SYMBOL . ' ' . number_format($balance['balance'] ?? 0, 2),
                    'currency' => CURRENCY,
                    'wallet_id' => $wallet['id']
                ]);
            }
            break;
            
        case 'transactions':
            if ($method === 'GET') {
                $type = $_GET['type'] ?? 'recent';
                $limit = intval($_GET['limit'] ?? 50);
                $offset = intval($_GET['offset'] ?? 0);
                
                if ($type === 'recent') {
                    $limit = min($limit, 10);
                    $transactions = getUserTransactions($userId, $limit, 0);
                } else {
                    $transactions = getUserTransactions($userId, $limit, $offset);
                }
                
                $formatted = array_map('formatTransaction', $transactions);
                
                ResponseHandler::success([
                    'transactions' => $formatted,
                    'total' => count($formatted),
                    'type' => $type
                ]);
            }
            break;
            
        case 'stats':
            if ($method === 'GET') {
                $stats = getWalletStats($conn, $userId);
                
                ResponseHandler::success([
                    'balance' => $stats['balance'],
                    'formatted_balance' => CURRENCY_SYMBOL . ' ' . number_format($stats['balance'], 2),
                    'total_transactions' => $stats['total_transactions'],
                    'total_credited' => $stats['total_credited'],
                    'total_debited' => $stats['total_debited'],
                    'formatted_credited' => CURRENCY_SYMBOL . ' ' . number_format($stats['total_credited'], 2),
                    'formatted_debited' => CURRENCY_SYMBOL . ' ' . number_format($stats['total_debited'], 2)
                ]);
            }
            break;
            
        case 'partners':
            if ($method === 'GET') {
                $partners = getExternalPartners($conn);
                
                ResponseHandler::success([
                    'partners' => $partners
                ]);
            }
            break;
            
        case 'topup':
            if ($method === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                if (!$input) $input = $_POST;
                
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
                
                $result = createTopupRequest($conn, $userId, $amount, $method);
                
                // Get payment instructions
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
                    'instructions' => $instructions[$method]
                ], 'Top-up request created');
            }
            break;
            
        case 'verify':
            if ($method === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                if (!$input) $input = $_POST;
                
                $reference_code = $input['reference_code'] ?? '';
                
                if (empty($reference_code)) {
                    ResponseHandler::error('Reference code required', 400);
                }
                
                $result = verifyAndCompleteTopup($conn, $reference_code);
                
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
            
        case 'debit':
            if ($method === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                if (!$input) $input = $_POST;
                
                $amount = floatval($input['amount'] ?? 0);
                $order_id = $input['order_id'] ?? '';
                $description = $input['description'] ?? 'Order payment';
                
                if ($amount <= 0) {
                    ResponseHandler::error('Invalid amount', 400);
                }
                
                if (empty($order_id)) {
                    ResponseHandler::error('Order ID required', 400);
                }
                
                $result = processPayment($conn, $userId, $amount, $order_id, $description);
                
                if ($result['success']) {
                    ResponseHandler::success([
                        'transaction_id' => $result['transaction_id'],
                        'amount' => $result['amount'],
                        'formatted_amount' => CURRENCY_SYMBOL . ' ' . number_format($result['amount'], 2),
                        'new_balance' => $result['new_balance'],
                        'formatted_balance' => CURRENCY_SYMBOL . ' ' . number_format($result['new_balance'], 2)
                    ], 'Payment successful');
                } else {
                    ResponseHandler::error($result['error'], 400);
                }
            }
            break;
            
        case 'health':
            ResponseHandler::success([
                'status' => 'healthy',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        default:
            if ($endpoint) {
                ResponseHandler::error('Invalid endpoint: ' . $endpoint, 404);
            } else {
                ResponseHandler::error('Endpoint parameter required. Use ?endpoint=...', 400);
            }
    }
    
} catch (Exception $e) {
    error_log("Wallet API Error: " . $e->getMessage());
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}
?>