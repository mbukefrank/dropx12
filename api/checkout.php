<?php
/*********************************
 * CHECKOUT SCREEN - DROPX WALLET ONLY
 * Malawi Kwacha (MWK)
 * 4-Character Alphanumeric External Codes
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Partner-Key, X-Partner-Reference");
header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 0); // Turn off display errors to prevent HTML in JSON

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * SESSION CONFIGURATION
 *********************************/
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * MALAWI KWACHA CONFIGURATION
 * CHECK IF CONSTANTS ALREADY DEFINED
 *********************************/
if (!defined('CURRENCY_CODE')) define('CURRENCY_CODE', 'MWK');
if (!defined('CURRENCY_SYMBOL')) define('CURRENCY_SYMBOL', 'MK');
if (!defined('CURRENCY_DECIMALS')) define('CURRENCY_DECIMALS', 2);

/*********************************
 * EXTERNAL PARTNER CONFIGURATION
 *********************************/
if (!defined('EXTERNAL_PARTNERS')) {
    define('EXTERNAL_PARTNERS', [
        'tnm_mpamba' => [
            'name' => 'TNM Mpamba',
            'code' => 'MPAMBA',
            'min_amount' => 100,
            'max_amount' => 500000
        ],
        'airtel_money' => [
            'name' => 'Airtel Money',
            'code' => 'AIRTEL',
            'min_amount' => 100,
            'max_amount' => 500000
        ],
        'nbs_bank' => [
            'name' => 'NBS Bank',
            'code' => 'NBS',
            'min_amount' => 1000,
            'max_amount' => 5000000
        ],
        'fdh_bank' => [
            'name' => 'FDH Bank',
            'code' => 'FDH',
            'min_amount' => 1000,
            'max_amount' => 5000000
        ],
        'standard_bank' => [
            'name' => 'Standard Bank',
            'code' => 'STANDARD',
            'min_amount' => 1000,
            'max_amount' => 5000000
        ]
    ]);
}

/*********************************
 * GENERATE 4-CHARACTER ALPHANUMERIC CODE
 *********************************/
function generatePaymentCode($conn) {
    $maxAttempts = 100;
    $attempts = 0;
    
    $letters = 'ABCDEFGHJKLMNPQRTUVWXY';
    $numbers = '346789';
    
    while ($attempts < $maxAttempts) {
        $code = $letters[random_int(0, strlen($letters) - 1)] .
                $numbers[random_int(0, strlen($numbers) - 1)] .
                $letters[random_int(0, strlen($letters) - 1)] .
                $numbers[random_int(0, strlen($numbers) - 1)];
        
        $stmt = $conn->prepare(
            "SELECT id FROM external_payments 
             WHERE payment_code = :code 
             AND status IN ('pending', 'processing')
             AND expires_at > NOW()"
        );
        $stmt->execute([':code' => $code]);
        
        if (!$stmt->fetch()) {
            return $code;
        }
        
        $attempts++;
    }
    
    return 'T' . $numbers[random_int(0, 5)] . 
                $letters[random_int(0, 23)] . 
                substr(strval(time()), -1);
}

/*********************************
 * CHECK DROPX WALLET BALANCE
 *********************************/
function checkDropXWalletBalance($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT balance, currency, is_active 
         FROM dropx_wallets 
         WHERE user_id = :user_id 
         AND is_active = 1
         LIMIT 1"
    );
    $stmt->execute([':user_id' => $userId]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$wallet) {
        return [
            'exists' => false,
            'balance' => 0,
            'currency' => 'MWK',
            'is_active' => false
        ];
    }
    
    return [
        'exists' => true,
        'balance' => floatval($wallet['balance']),
        'currency' => $wallet['currency'] ?? 'MWK',
        'is_active' => boolval($wallet['is_active'])
    ];
}

/*********************************
 * AUTHENTICATION CHECK
 *********************************/
function authenticateUser($conn) {
    if (!empty($_SESSION['user_id'])) {
        return $_SESSION['user_id'];
    }
    
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (strpos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
        $stmt = $conn->prepare(
            "SELECT id FROM users WHERE api_token = :token AND api_token_expiry > NOW()"
        );
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            return $user['id'];
        }
    }
    
    return false;
}

/*********************************
 * GET USER'S ACTIVE CART
 *********************************/
function getActiveCart($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT id, user_id, status, applied_promotion_id, applied_discount 
         FROM carts 
         WHERE user_id = :user_id AND status = 'active'
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/*********************************
 * GET CART ITEMS
 *********************************/
function getCartItems($conn, $cartId) {
    $stmt = $conn->prepare(
        "SELECT 
            ci.id,
            ci.menu_item_id,
            ci.quantity,
            mi.name as item_name,
            mi.price,
            m.name as merchant_name
         FROM cart_items ci
         JOIN menu_items mi ON ci.menu_item_id = mi.id
         JOIN merchants m ON mi.merchant_id = m.id
         WHERE ci.cart_id = :cart_id
         AND ci.is_active = 1"
    );
    $stmt->execute([':cart_id' => $cartId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*********************************
 * CALCULATE CART TOTALS
 *********************************/
function calculateCartTotals($conn, $cartId, $userId) {
    $stmt = $conn->prepare(
        "SELECT SUM(mi.price * ci.quantity) as subtotal,
                COUNT(ci.id) as item_count,
                SUM(ci.quantity) as total_quantity
         FROM cart_items ci
         JOIN menu_items mi ON ci.menu_item_id = mi.id
         WHERE ci.cart_id = :cart_id AND ci.is_active = 1"
    );
    $stmt->execute([':cart_id' => $cartId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $subtotal = floatval($result['subtotal'] ?? 0);
    
    $cartStmt = $conn->prepare("SELECT applied_discount FROM carts WHERE id = :cart_id");
    $cartStmt->execute([':cart_id' => $cartId]);
    $cartData = $cartStmt->fetch(PDO::FETCH_ASSOC);
    $discount = floatval($cartData['applied_discount'] ?? 0);
    
    $adjustedSubtotal = max(0, $subtotal - $discount);
    $deliveryFee = 1500.00;
    $serviceFee = max(500.00, $adjustedSubtotal * 0.02);
    $taxRate = 0.165;
    $taxableAmount = $adjustedSubtotal + $deliveryFee + $serviceFee;
    $taxAmount = $taxableAmount * $taxRate;
    $totalAmount = $taxableAmount + $taxAmount;
    
    return [
        'subtotal' => round($subtotal, 2),
        'discount' => round($discount, 2),
        'adjusted_subtotal' => round($adjustedSubtotal, 2),
        'delivery_fee' => round($deliveryFee, 2),
        'service_fee' => round($serviceFee, 2),
        'tax_amount' => round($taxAmount, 2),
        'tax_rate' => '16.5%',
        'total_amount' => round($totalAmount, 2),
        'item_count' => intval($result['item_count'] ?? 0),
        'total_quantity' => intval($result['total_quantity'] ?? 0),
        'currency' => 'MWK',
        'formatted_total' => 'MK' . number_format($totalAmount, 2)
    ];
}

/*********************************
 * GET USER'S DEFAULT ADDRESS
 *********************************/
function getUserDefaultAddress($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT id, label, address_line1, city, neighborhood 
         FROM addresses 
         WHERE user_id = :user_id AND is_default = 1
         LIMIT 1"
    );
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/*********************************
 * VALIDATE CART FOR CHECKOUT
 *********************************/
function validateCart($conn, $cartId) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as count FROM cart_items 
         WHERE cart_id = :cart_id AND is_active = 1"
    );
    $stmt->execute([':cart_id' => $cartId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return intval($result['count'] ?? 0) > 0;
}

/*********************************
 * CREATE EXTERNAL PAYMENT CODE - FIXED
 *********************************/
function createExternalPayment($conn, $userId, $cartId, $amount, $walletBalance) {
    // Generate 4-character code
    $paymentCode = generatePaymentCode($conn);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));
    
    $stmt = $conn->prepare(
        "INSERT INTO external_payments 
            (payment_code, user_id, cart_id, amount, wallet_balance_at_request, 
             status, created_at, expires_at)
         VALUES 
            (:code, :user_id, :cart_id, :amount, :wallet_balance, 
             'pending', NOW(), :expires_at)"
    );
    
    $stmt->execute([
        ':code' => $paymentCode,
        ':user_id' => $userId,
        ':cart_id' => $cartId,
        ':amount' => $amount,
        ':wallet_balance' => $walletBalance,
        ':expires_at' => $expiresAt
    ]);
    
    return [
        'payment_id' => $conn->lastInsertId(),
        'payment_code' => $paymentCode,
        'formatted_code' => implode(' ', str_split($paymentCode)), // "A 3 B 7"
        'expires_at' => $expiresAt,
        'amount' => $amount,
        'formatted_amount' => 'MK' . number_format($amount, 2)
    ];
}
/*********************************
 * PROCESS DROPX WALLET PAYMENT - COMPLETELY FIXED
 *********************************/
function processWalletPayment($conn, $userId, $cartId, $amount) {
    try {
        $conn->beginTransaction();
        
        // Check wallet balance
        $walletStmt = $conn->prepare(
            "SELECT id, balance FROM dropx_wallets 
             WHERE user_id = :user_id AND is_active = 1
             FOR UPDATE"
        );
        $walletStmt->execute([':user_id' => $userId]);
        $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$wallet || $wallet['balance'] < $amount) {
            throw new Exception('Insufficient balance');
        }
        
        // Deduct from wallet
        $updateStmt = $conn->prepare(
            "UPDATE dropx_wallets 
             SET balance = balance - :amount
             WHERE id = :wallet_id"
        );
        $updateStmt->execute([
            ':amount' => $amount,
            ':wallet_id' => $wallet['id']
        ]);
        
        // FIXED: Insert into wallet_transactions with correct column names
        $txnStmt = $conn->prepare(
            "INSERT INTO wallet_transactions 
                (user_id, amount, type, reference_type, reference_id, status, description)
             VALUES 
                (:user_id, :amount, 'debit', 'order', :reference_id, 'completed', 
                 CONCAT('Payment for order #', :cart_id))"
        );
        $txnStmt->execute([
            ':user_id' => $userId,
            ':amount' => $amount,
            ':reference_id' => $cartId,  // Using reference_id, not cart_id
            ':cart_id' => $cartId
        ]);
        
        // Update cart status
        $cartStmt = $conn->prepare(
            "UPDATE carts SET status = 'completed' WHERE id = :cart_id"
        );
        $cartStmt->execute([':cart_id' => $cartId]);
        
        // FIXED: Insert into orders with correct column names from your structure
        $orderStmt = $conn->prepare(
            "INSERT INTO orders 
                (order_number, user_id, merchant_id, subtotal, delivery_fee, total_amount, 
                 payment_method, payment_status, delivery_address, status, created_at, updated_at)
             VALUES 
                (CONCAT('ORD', UNIX_TIMESTAMP()), :user_id, 1, :subtotal, 1500, :total_amount, 
                 'dropx_wallet', 'paid', 'Default Address', 'confirmed', NOW(), NOW())"
        );
        
        // Calculate subtotal (amount - delivery_fee - tax)
        // Since we don't have the breakdown here, we'll approximate
        $deliveryFee = 1500;
        $estimatedSubtotal = $amount - $deliveryFee - ($amount * 0.165); // Rough estimate
        
        $orderStmt->execute([
            ':user_id' => $userId,
            ':subtotal' => round($estimatedSubtotal, 2),
            ':total_amount' => $amount
        ]);
        
        $orderId = $conn->lastInsertId();
        
        $conn->commit();
        
        return [
            'success' => true,
            'order_id' => $orderId,
            'new_balance' => $wallet['balance'] - $amount,
            'new_balance_formatted' => 'MK' . number_format($wallet['balance'] - $amount, 2)
        ];
        
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Payment error: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}
/*********************************
 * MAIN ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $path = $_SERVER['REQUEST_URI'];
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = $_POST;
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }
    
    /*********************************
     * ROUTE: GET /checkout.php
     *********************************/
    if ($method === 'GET') {
        $userId = authenticateUser($conn);
        if (!$userId) {
            ResponseHandler::error('Authentication required', 401);
        }
        
        $cart = getActiveCart($conn, $userId);
        if (!$cart) {
            ResponseHandler::error('No active cart found', 404);
        }
        
        if (!validateCart($conn, $cart['id'])) {
            ResponseHandler::error('Cart is empty', 400);
        }
        
        $items = getCartItems($conn, $cart['id']);
        $totals = calculateCartTotals($conn, $cart['id'], $userId);
        $address = getUserDefaultAddress($conn, $userId);
        $wallet = checkDropXWalletBalance($conn, $userId);
        
        $formattedItems = array_map(function($item) {
            return [
                'name' => $item['item_name'],
                'quantity' => intval($item['quantity']),
                'price' => floatval($item['price']),
                'total' => floatval($item['price']) * intval($item['quantity']),
                'formatted_price' => 'MK' . number_format($item['price'], 2),
                'formatted_total' => 'MK' . number_format(floatval($item['price']) * intval($item['quantity']), 2),
                'merchant' => $item['merchant_name']
            ];
        }, $items);
        
        ResponseHandler::success([
            'cart_id' => $cart['id'],
            'items' => $formattedItems,
            'item_count' => $totals['item_count'],
            'totals' => [
                'subtotal' => $totals['subtotal'],
                'subtotal_formatted' => 'MK' . number_format($totals['subtotal'], 2),
                'delivery_fee' => $totals['delivery_fee'],
                'delivery_fee_formatted' => 'MK' . number_format($totals['delivery_fee'], 2),
                'service_fee' => $totals['service_fee'],
                'service_fee_formatted' => 'MK' . number_format($totals['service_fee'], 2),
                'tax' => $totals['tax_amount'],
                'tax_formatted' => 'MK' . number_format($totals['tax_amount'], 2),
                'tax_rate' => $totals['tax_rate'],
                'total' => $totals['total_amount'],
                'total_formatted' => $totals['formatted_total']
            ],
            'delivery_address' => $address ? [
                'label' => $address['label'] ?? 'Home',
                'address' => $address['address_line1'] . ', ' . $address['city'],
                'neighborhood' => $address['neighborhood'] ?? ''
            ] : null,
            'wallet' => [
                'exists' => $wallet['exists'],
                'balance' => $wallet['balance'],
                'balance_formatted' => 'MK' . number_format($wallet['balance'], 2),
                'is_active' => $wallet['is_active'],
                'sufficient' => $wallet['balance'] >= $totals['total_amount'],
                'shortfall' => $wallet['balance'] >= $totals['total_amount'] ? 0 : 
                              round($totals['total_amount'] - $wallet['balance'], 2),
                'shortfall_formatted' => $wallet['balance'] >= $totals['total_amount'] ? 'MK0.00' : 
                              'MK' . number_format($totals['total_amount'] - $wallet['balance'], 2)
            ],
            'payment_options' => [
                'dropx_wallet' => [
                    'available' => $wallet['exists'] && $wallet['is_active'],
                    'can_pay' => $wallet['balance'] >= $totals['total_amount']
                ],
                'external' => [
                    'available' => true,
                    'partners' => array_map(function($partner) {
                        return [
                            'name' => $partner['name'],
                            'code' => $partner['code'],
                            'min_amount' => $partner['min_amount'],
                            'max_amount' => $partner['max_amount']
                        ];
                    }, EXTERNAL_PARTNERS)
                ]
            ]
        ]);
    }
    
    /*********************************
     * ROUTE: POST /checkout.php
     *********************************/
    elseif ($method === 'POST') {
        $userId = authenticateUser($conn);
        if (!$userId) {
            ResponseHandler::error('Authentication required', 401);
        }
        
        $action = $input['action'] ?? '';
        
        switch ($action) {
            case 'initiate':
                $cartId = $input['cart_id'] ?? null;
                
                if (!$cartId) {
                    $cart = getActiveCart($conn, $userId);
                    $cartId = $cart['id'] ?? null;
                }
                
                if (!$cartId) {
                    ResponseHandler::error('Cart not found', 404);
                }
                
                $checkStmt = $conn->prepare(
                    "SELECT id FROM carts WHERE id = :cart_id AND user_id = :user_id"
                );
                $checkStmt->execute([':cart_id' => $cartId, ':user_id' => $userId]);
                
                if (!$checkStmt->fetch()) {
                    ResponseHandler::error('Cart not found', 404);
                }
                
                if (!validateCart($conn, $cartId)) {
                    ResponseHandler::error('Cart is empty', 400);
                }
                
                $totals = calculateCartTotals($conn, $cartId, $userId);
                
                $_SESSION['checkout_' . $cartId] = [
                    'cart_id' => $cartId,
                    'user_id' => $userId,
                    'amount' => $totals['total_amount'],
                    'initiated_at' => date('Y-m-d H:i:s')
                ];
                
                ResponseHandler::success([
                    'cart_id' => $cartId,
                    'amount' => $totals['total_amount'],
                    'amount_formatted' => 'MK' . number_format($totals['total_amount'], 2)
                ]);
                break;
            
            case 'process_payment':
                $cartId = $input['cart_id'] ?? null;
                
                if (!$cartId) {
                    $cart = getActiveCart($conn, $userId);
                    $cartId = $cart['id'] ?? null;
                }
                
                if (!$cartId) {
                    ResponseHandler::error('Cart not found', 404);
                }
                
                $session = $_SESSION['checkout_' . $cartId] ?? null;
                if (!$session || $session['user_id'] != $userId) {
                    ResponseHandler::error('Please initiate checkout first', 400);
                }
                
                $totals = calculateCartTotals($conn, $cartId, $userId);
                $amount = $totals['total_amount'];
                
                $result = processWalletPayment($conn, $userId, $cartId, $amount);
                
                if ($result['success']) {
                    unset($_SESSION['checkout_' . $cartId]);
                    
                    ResponseHandler::success([
                        'order_id' => $result['order_id'],
                        'amount_paid' => $amount,
                        'amount_formatted' => 'MK' . number_format($amount, 2),
                        'new_balance' => $result['new_balance'],
                        'new_balance_formatted' => 'MK' . number_format($result['new_balance'], 2)
                    ]);
                } else {
                    ResponseHandler::error($result['error'], 400);
                }
                break;
            
            case 'generate_code':
                $cartId = $input['cart_id'] ?? null;
                
                if (!$cartId) {
                    $cart = getActiveCart($conn, $userId);
                    $cartId = $cart['id'] ?? null;
                }
                
                if (!$cartId) {
                    ResponseHandler::error('Cart not found', 404);
                }
                
                $totals = calculateCartTotals($conn, $cartId, $userId);
                $amount = $totals['total_amount'];
                
                $wallet = checkDropXWalletBalance($conn, $userId);
                
                $externalPayment = createExternalPayment(
                    $conn, 
                    $userId, 
                    $cartId, 
                    $amount, 
                    $wallet['balance']
                );
                
                $_SESSION['external_' . $externalPayment['payment_code']] = [
                    'payment_id' => $externalPayment['payment_id'],
                    'cart_id' => $cartId,
                    'amount' => $amount
                ];
                
                ResponseHandler::success([
                    'payment_code' => $externalPayment['payment_code'],
                    'formatted_code' => $externalPayment['formatted_code'],
                    'amount' => $amount,
                    'amount_formatted' => 'MK' . number_format($amount, 2),
                    'expires_at' => $externalPayment['expires_at'],
                    'expiry_minutes' => 30,
                    'accepted_partners' => [
                        'TNM Mpamba',
                        'Airtel Money',
                        'NBS Bank',
                        'FDH Bank',
                        'Standard Bank'
                    ],
                    'instructions' => [
                        '🔑 Your code: ' . $externalPayment['payment_code'],
                        '💰 Amount: MK' . number_format($amount, 2),
                        '⏱️  Expires: ' . date('h:i A', strtotime($externalPayment['expires_at'])),
                        '📍 Show this code at any TNM Mpamba, Airtel Money, or Bank branch',
                        '✅ Pay cash, we add funds to your wallet instantly'
                    ]
                ]);
                break;
            
            default:
                ResponseHandler::error('Invalid action', 400);
        }
    }
    
    else {
        ResponseHandler::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}
?>