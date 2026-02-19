<?php
/*********************************
 * DROPX WALLET API
 * Malawi Kwacha (MWK) Wallet System
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
 * CONSTANTS
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
 * WALLET FUNCTIONS - Using dropx_wallets table
 *********************************/
function getOrCreateWallet($conn, $user_id) {
    // Check if wallet exists
    $stmt = $conn->prepare("SELECT * FROM dropx_wallets WHERE user_id = :user_id LIMIT 1");
    $stmt->execute([':user_id' => $user_id]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($wallet) {
        return $wallet;
    }

    // Create new wallet
    $stmt = $conn->prepare(
        "INSERT INTO dropx_wallets (user_id, balance, currency) VALUES (:user_id, 0.00, 'MWK')"
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
        "SELECT balance, currency FROM dropx_wallets WHERE user_id = :user_id AND is_active = 1"
    );
    $stmt->execute([':user_id' => $user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function updateWalletBalance($conn, $wallet_id, $new_balance) {
    $stmt = $conn->prepare(
        "UPDATE dropx_wallets SET balance = :balance WHERE id = :id"
    );
    return $stmt->execute([
        ':balance' => $new_balance,
        ':id' => $wallet_id
    ]);
}

/*********************************
 * TRANSACTION FUNCTIONS - Using wallet_transactions table
 *********************************/
function createTransaction($conn, $data) {
    $stmt = $conn->prepare(
        "INSERT INTO wallet_transactions 
         (user_id, amount, type, reference_type, reference_id, partner, partner_reference, status, description)
         VALUES 
         (:user_id, :amount, :type, :reference_type, :reference_id, :partner, :partner_reference, :status, :description)"
    );

    return $stmt->execute([
        ':user_id' => $data['user_id'],
        ':amount' => $data['amount'],
        ':type' => $data['type'],
        ':reference_type' => $data['reference_type'] ?? null,
        ':reference_id' => $data['reference_id'] ?? null,
        ':partner' => $data['partner'] ?? null,
        ':partner_reference' => $data['partner_reference'] ?? null,
        ':status' => $data['status'] ?? 'completed',
        ':description' => $data['description'] ?? ''
    ]);
}

function getUserTransactions($conn, $user_id, $limit = 50, $offset = 0) {
    $stmt = $conn->prepare(
        "SELECT * FROM wallet_transactions 
         WHERE user_id = :user_id
         ORDER BY created_at DESC
         LIMIT :limit OFFSET :offset"
    );
    
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*********************************
 * EXTERNAL PAYMENTS FUNCTIONS - Using external_payments table for top-ups
 *********************************/
function createTopupRequest($conn, $user_id, $amount, $partner, $wallet_balance) {
    // Generate payment code (4 digits)
    $payment_code = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    
    // Check if code exists
    while (paymentCodeExists($conn, $payment_code)) {
        $payment_code = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }
    
    $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $stmt = $conn->prepare(
        "INSERT INTO external_payments 
         (payment_code, user_id, amount, wallet_balance_at_request, status, partner, expires_at)
         VALUES 
         (:payment_code, :user_id, :amount, :wallet_balance, 'pending', :partner, :expires_at)"
    );

    $stmt->execute([
        ':payment_code' => $payment_code,
        ':user_id' => $user_id,
        ':amount' => $amount,
        ':wallet_balance' => $wallet_balance,
        ':partner' => $partner,
        ':expires_at' => $expires_at
    ]);

    return [
        'id' => $conn->lastInsertId(),
        'payment_code' => $payment_code,
        'amount' => $amount,
        'expires_at' => $expires_at
    ];
}

function paymentCodeExists($conn, $code) {
    $stmt = $conn->prepare("SELECT id FROM external_payments WHERE payment_code = :code");
    $stmt->execute([':code' => $code]);
    return $stmt->fetch() ? true : false;
}

function verifyAndCompleteTopup($conn, $payment_code) {
    try {
        $conn->beginTransaction();

        // Get the pending payment
        $stmt = $conn->prepare(
            "SELECT * FROM external_payments 
             WHERE payment_code = :code AND status = 'pending' 
             AND expires_at > NOW() FOR UPDATE"
        );
        $stmt->execute([':code' => $payment_code]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            throw new Exception('Invalid or expired payment code');
        }

        // Get user's wallet
        $walletStmt = $conn->prepare(
            "SELECT id, balance FROM dropx_wallets WHERE user_id = :user_id FOR UPDATE"
        );
        $walletStmt->execute([':user_id' => $payment['user_id']]);
        $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);

        if (!$wallet) {
            throw new Exception('Wallet not found');
        }

        // Update wallet balance
        $newBalance = $wallet['balance'] + $payment['amount'];
        $updateStmt = $conn->prepare(
            "UPDATE dropx_wallets SET balance = :balance WHERE id = :id"
        );
        $updateStmt->execute([
            ':balance' => $newBalance,
            ':id' => $wallet['id']
        ]);

        // Create transaction record
        $transactionData = [
            'user_id' => $payment['user_id'],
            'amount' => $payment['amount'],
            'type' => 'credit',
            'reference_type' => 'topup',
            'reference_id' => $payment['id'],
            'partner' => $payment['partner'],
            'partner_reference' => $payment_code,
            'status' => 'completed',
            'description' => 'Wallet top-up via ' . $payment['partner']
        ];
        createTransaction($conn, $transactionData);

        // Update payment status
        $updateStmt = $conn->prepare(
            "UPDATE external_payments SET status = 'completed', completed_at = NOW() WHERE id = :id"
        );
        $updateStmt->execute([':id' => $payment['id']]);

        $conn->commit();

        return [
            'success' => true,
            'amount' => $payment['amount'],
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

/*********************************
 * PAYMENT FUNCTIONS
 *********************************/
function processPayment($conn, $user_id, $amount, $order_id, $description, $partner = 'wallet') {
    try {
        $conn->beginTransaction();

        // Get wallet
        $walletStmt = $conn->prepare(
            "SELECT id, balance FROM dropx_wallets WHERE user_id = :user_id AND is_active = 1 FOR UPDATE"
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
        updateWalletBalance($conn, $wallet['id'], $newBalance);

        // Create transaction
        $transactionData = [
            'user_id' => $user_id,
            'amount' => $amount,
            'type' => 'debit',
            'reference_type' => 'order',
            'reference_id' => $order_id,
            'partner' => $partner,
            'status' => 'completed',
            'description' => $description
        ];
        createTransaction($conn, $transactionData);

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

/*********************************
 * GET WALLET STATS
 *********************************/
function getWalletStats($conn, $user_id) {
    // Get wallet balance
    $balance = getWalletBalance($conn, $user_id);
    
    // Get transaction stats
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total_transactions,
            SUM(CASE WHEN type = 'credit' THEN 1 ELSE 0 END) as total_credits,
            SUM(CASE WHEN type = 'debit' THEN 1 ELSE 0 END) as total_debits,
            SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) as total_credited,
            SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) as total_debited
         FROM wallet_transactions 
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
    // Force MK as the currency symbol regardless of what CURRENCY_SYMBOL is defined as
    $currency = 'MK'; // Hardcode it here to bypass any incorrect constant
    
    return [
        'id' => $t['id'],
        'amount' => floatval($t['amount']),
        'formatted_amount' => $currency . ' ' . number_format($t['amount'], 2),
        'description' => $t['description'],
        'type' => $t['type'],
        'status' => $t['status'],
        'date' => $t['created_at'],
        'formatted_date' => date('M d, Y • h:i A', strtotime($t['created_at'])),
        'partner' => $t['partner'],
        'reference' => $t['partner_reference']
    ];
}

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $requestUri = $_SERVER['REQUEST_URI'];
    
    // Parse URL
    $path = parse_url($requestUri, PHP_URL_PATH);
    $queryString = parse_url($requestUri, PHP_URL_QUERY);
    parse_str($queryString ?? '', $queryParams);
    
    // Initialize database
    $conn = initDatabase();
    $baseUrl = getBaseUrl();
    
    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }
    
    // Get endpoint from query string
    $endpoint = $_GET['endpoint'] ?? '';
    
    // Public test endpoint
    if ($endpoint === 'test') {
        ResponseHandler::success([
            'message' => 'Wallet API is working',
            'tables' => [
                'dropx_wallets',
                'wallet_transactions', 
                'external_payments'
            ]
        ]);
    }
    
    // Authenticate for protected endpoints
    $userId = authenticateUser($conn);
    
    if (!$userId && !in_array($endpoint, ['test', 'login', 'register'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    // Route endpoints
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
                }
                
                $transactions = getUserTransactions($conn, $userId, $limit, $offset);
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
            
        case 'topup':
            if ($method === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                if (!$input) $input = $_POST;
                
                $amount = floatval($input['amount'] ?? 0);
                $partner = $input['method'] ?? ''; // 'airtel_money', 'mpamba', 'bank_transfer'
                
                // Validate
                if ($amount < 100) {
                    ResponseHandler::error('Minimum top-up amount is MWK 100', 400);
                }
                if ($amount > 500000) {
                    ResponseHandler::error('Maximum top-up amount is MWK 500,000', 400);
                }
                
                $validMethods = ['airtel_money', 'mpamba', 'bank_transfer'];
                if (!in_array($partner, $validMethods)) {
                    ResponseHandler::error('Invalid payment method', 400);
                }
                
                // Get current wallet balance
                $wallet = getWalletBalance($conn, $userId);
                $wallet_balance = $wallet['balance'] ?? 0;
                
                $result = createTopupRequest($conn, $userId, $amount, $partner, $wallet_balance);
                
                // Get payment instructions
                $instructions = [
                    'airtel_money' => [
                        'Send to: 0999 000 000',
                        'Payment Code: ' . $result['payment_code'],
                        'Amount: MWK ' . number_format($amount, 2)
                    ],
                    'mpamba' => [
                        'Send to: 0888 111 111',
                        'Payment Code: ' . $result['payment_code'],
                        'Amount: MWK ' . number_format($amount, 2)
                    ],
                    'bank_transfer' => [
                        'Bank: NBS Bank',
                        'Account: 1234567890',
                        'Reference: DROPX-' . $result['payment_code'],
                        'Amount: MWK ' . number_format($amount, 2)
                    ]
                ];
                
                ResponseHandler::success([
                    'payment_code' => $result['payment_code'],
                    'amount' => $amount,
                    'formatted_amount' => CURRENCY_SYMBOL . ' ' . number_format($amount, 2),
                    'method' => $partner,
                    'expires_at' => $result['expires_at'],
                    'instructions' => $instructions[$partner]
                ], 'Top-up request created');
            }
            break;
            
        case 'verify':
            if ($method === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                if (!$input) $input = $_POST;
                
                $payment_code = $input['payment_code'] ?? $input['reference_code'] ?? '';
                
                if (empty($payment_code)) {
                    ResponseHandler::error('Payment code required', 400);
                }
                
                $result = verifyAndCompleteTopup($conn, $payment_code);
                
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