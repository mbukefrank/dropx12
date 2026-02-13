<?php
/*********************************
 * CHECKOUT SCREEN - DROPX WALLET ONLY
 * Malawi Kwacha (MWK)
 * 4-Character Alphanumeric External Codes
 * Partners: TNM Mpamba, Airtel Money, Banks
 * NO CUSTOMER VERIFICATION - JUST CODE + AMOUNT
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Partner-Key, X-Partner-Reference");
header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 1);

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
 *********************************/
define('CURRENCY_CODE', 'MWK');
define('CURRENCY_SYMBOL', 'MK');
define('CURRENCY_DECIMALS', 2);

/*********************************
 * EXTERNAL PARTNER CONFIGURATION
 *********************************/
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

/*********************************
 * GENERATE 4-CHARACTER ALPHANUMERIC CODE
 * NO AMBIGUOUS CHARACTERS (NO 0,1,2,5,I,O,S,Z)
 * FORMAT: LETTER-NUMBER-LETTER-NUMBER (A3B7)
 *********************************/
function generatePaymentCode($conn) {
    $maxAttempts = 100;
    $attempts = 0;
    
    // Clean character sets - easy to read/type
    $letters = 'ABCDEFGHJKLMNPQRTUVWXY'; // 24 letters (no I,O,S,Z)
    $numbers = '346789'; // 6 numbers (no 0,1,2,5)
    
    while ($attempts < $maxAttempts) {
        // Pattern: Letter-Number-Letter-Number
        $code = $letters[random_int(0, strlen($letters) - 1)] .
                $numbers[random_int(0, strlen($numbers) - 1)] .
                $letters[random_int(0, strlen($letters) - 1)] .
                $numbers[random_int(0, strlen($numbers) - 1)];
        
        // Check if code already exists and is active
        $stmt = $conn->prepare(
            "SELECT id FROM external_payments 
             WHERE payment_code = :code 
             AND status IN ('pending', 'processing')
             AND expires_at > NOW()"
        );
        $stmt->execute([':code' => $code]);
        
        if (!$stmt->fetch()) {
            return $code; // Example: "A3B7", "X9K2", "P4M6"
        }
        
        $attempts++;
    }
    
    // Fallback: timestamp-based
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
 * AUTHENTICATION CHECK (FOR APP USERS)
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
 * AUTHENTICATE PARTNER (FOR EXTERNAL API CALLS)
 *********************************/
function authenticatePartner($conn) {
    $headers = getallheaders();
    $partnerKey = $headers['X-Partner-Key'] ?? '';
    
    if (empty($partnerKey)) {
        return false;
    }
    
    // Simple partner authentication
    $validPartners = [
        'tnm_mpamba_live_2026' => 'tnm_mpamba',
        'airtel_money_live_2026' => 'airtel_money',
        'nbs_bank_live_2026' => 'nbs_bank',
        'fdh_bank_live_2026' => 'fdh_bank',
        'standard_bank_live_2026' => 'standard_bank'
    ];
    
    return $validPartners[$partnerKey] ?? false;
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
 * CALCULATE CART TOTALS (MWK)
 *********************************/
function calculateCartTotals($conn, $cartId, $userId) {
    // Get subtotal
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
    
    // Get applied discount
    $cartStmt = $conn->prepare("SELECT applied_discount FROM carts WHERE id = :cart_id");
    $cartStmt->execute([':cart_id' => $cartId]);
    $cartData = $cartStmt->fetch(PDO::FETCH_ASSOC);
    $discount = floatval($cartData['applied_discount'] ?? 0);
    
    $adjustedSubtotal = max(0, $subtotal - $discount);
    
    // Delivery fee - standard in MWK
    $deliveryFee = 1500.00; // MK1,500
    
    // Service fee - 2% of adjusted subtotal
    $serviceFee = max(500.00, $adjustedSubtotal * 0.02); // Min MK500
    
    // Tax - 16.5% VAT (Malawi)
    $taxRate = 0.165;
    $taxableAmount = $adjustedSubtotal + $deliveryFee + $serviceFee;
    $taxAmount = $taxableAmount * $taxRate;
    
    // Total
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
    // Check if cart has items
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as count FROM cart_items 
         WHERE cart_id = :cart_id AND is_active = 1"
    );
    $stmt->execute([':cart_id' => $cartId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return intval($result['count'] ?? 0) > 0;
}

/*********************************
 * CREATE EXTERNAL PAYMENT CODE
 * SIMPLE - No customer verification
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
 * PROCESS DROPX WALLET PAYMENT
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
        
        // Record transaction
        $txnStmt = $conn->prepare(
            "INSERT INTO wallet_transactions 
                (user_id, amount, type, reference_type, reference_id, status, description)
             VALUES 
                (:user_id, :amount, 'debit', 'order', :cart_id, 'completed', 
                 'Payment for order #' || :cart_id)"
        );
        $txnStmt->execute([
            ':user_id' => $userId,
            ':amount' => $amount,
            ':cart_id' => $cartId
        ]);
        
        // Update cart status
        $cartStmt = $conn->prepare(
            "UPDATE carts SET status = 'completed' WHERE id = :cart_id"
        );
        $cartStmt->execute([':cart_id' => $cartId]);
        
        // Create order
        $orderStmt = $conn->prepare(
            "INSERT INTO orders 
                (user_id, cart_id, total_amount, payment_method, payment_status, status)
             VALUES 
                (:user_id, :cart_id, :amount, 'dropx_wallet', 'paid', 'confirmed')"
        );
        $orderStmt->execute([
            ':user_id' => $userId,
            ':cart_id' => $cartId,
            ':amount' => $amount
        ]);
        
        $orderId = $conn->lastInsertId();
        
        $conn->commit();
        
        return [
            'success' => true,
            'order_id' => $orderId,
            'new_balance' => $wallet['balance'] - $amount
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
     * ROUTE: GET /checkout
     * Load checkout screen for app users
     *********************************/
    if ($method === 'GET' && strpos($path, '/checkout') !== false) {
        $userId = authenticateUser($conn);
        if (!$userId) {
            ResponseHandler::error('Authentication required', 401);
        }
        
        // Get active cart
        $cart = getActiveCart($conn, $userId);
        if (!$cart) {
            ResponseHandler::error('No active cart found', 404);
        }
        
        // Validate cart has items
        if (!validateCart($conn, $cart['id'])) {
            ResponseHandler::error('Cart is empty', 400);
        }
        
        // Get cart items
        $items = getCartItems($conn, $cart['id']);
        
        // Calculate totals
        $totals = calculateCartTotals($conn, $cart['id'], $userId);
        
        // Get default address
        $address = getUserDefaultAddress($conn, $userId);
        
        // Check wallet balance
        $wallet = checkDropXWalletBalance($conn, $userId);
        
        // Format items
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
        
        // Return checkout data
        ResponseHandler::success([
            'success' => true,
            'data' => [
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
            ]
        ]);
    }
    
    /*********************************
     * ROUTE: POST /checkout (APP USER)
     * Actions: initiate, process_payment, generate_code
     *********************************/
    elseif ($method === 'POST' && strpos($path, '/checkout') !== false) {
        $userId = authenticateUser($conn);
        if (!$userId) {
            ResponseHandler::error('Authentication required', 401);
        }
        
        $action = $input['action'] ?? '';
        
        switch ($action) {
            
            /*********************************
             * ACTION: initiate_checkout
             * Start checkout process
             *********************************/
            case 'initiate':
                $cartId = $input['cart_id'] ?? null;
                
                if (!$cartId) {
                    $cart = getActiveCart($conn, $userId);
                    $cartId = $cart['id'] ?? null;
                }
                
                if (!$cartId) {
                    ResponseHandler::error('Cart not found', 404);
                }
                
                // Verify cart belongs to user
                $checkStmt = $conn->prepare(
                    "SELECT id FROM carts WHERE id = :cart_id AND user_id = :user_id"
                );
                $checkStmt->execute([':cart_id' => $cartId, ':user_id' => $userId]);
                
                if (!$checkStmt->fetch()) {
                    ResponseHandler::error('Cart not found', 404);
                }
                
                // Validate cart
                if (!validateCart($conn, $cartId)) {
                    ResponseHandler::error('Cart is empty', 400);
                }
                
                // Calculate totals
                $totals = calculateCartTotals($conn, $cartId, $userId);
                
                // Store in session
                $_SESSION['checkout_' . $cartId] = [
                    'cart_id' => $cartId,
                    'user_id' => $userId,
                    'amount' => $totals['total_amount'],
                    'initiated_at' => date('Y-m-d H:i:s')
                ];
                
                ResponseHandler::success([
                    'success' => true,
                    'message' => 'Checkout initiated',
                    'data' => [
                        'cart_id' => $cartId,
                        'amount' => $totals['total_amount'],
                        'amount_formatted' => 'MK' . number_format($totals['total_amount'], 2)
                    ]
                ]);
                break;
            
            /*********************************
             * ACTION: process_payment
             * Pay with DropX Wallet
             *********************************/
            case 'process_payment':
                $cartId = $input['cart_id'] ?? null;
                
                if (!$cartId) {
                    $cart = getActiveCart($conn, $userId);
                    $cartId = $cart['id'] ?? null;
                }
                
                if (!$cartId) {
                    ResponseHandler::error('Cart not found', 404);
                }
                
                // Verify checkout session
                $session = $_SESSION['checkout_' . $cartId] ?? null;
                if (!$session || $session['user_id'] != $userId) {
                    ResponseHandler::error('Please initiate checkout first', 400);
                }
                
                // Calculate totals
                $totals = calculateCartTotals($conn, $cartId, $userId);
                $amount = $totals['total_amount'];
                
                // Process payment
                $result = processWalletPayment($conn, $userId, $cartId, $amount);
                
                if ($result['success']) {
                    unset($_SESSION['checkout_' . $cartId]);
                    
                    ResponseHandler::success([
                        'success' => true,
                        'message' => 'Payment successful',
                        'data' => [
                            'order_id' => $result['order_id'],
                            'amount_paid' => $amount,
                            'amount_formatted' => 'MK' . number_format($amount, 2),
                            'new_balance' => $result['new_balance'],
                            'new_balance_formatted' => 'MK' . number_format($result['new_balance'], 2)
                        ]
                    ]);
                } else {
                    ResponseHandler::error($result['error'], 400);
                }
                break;
            
            /*********************************
             * ACTION: generate_code
             * Generate 4-character code for external payment
             * NO CUSTOMER VERIFICATION - JUST CODE + AMOUNT
             *********************************/
            case 'generate_code':
                $cartId = $input['cart_id'] ?? null;
                
                if (!$cartId) {
                    $cart = getActiveCart($conn, $userId);
                    $cartId = $cart['id'] ?? null;
                }
                
                if (!$cartId) {
                    ResponseHandler::error('Cart not found', 404);
                }
                
                // Calculate totals
                $totals = calculateCartTotals($conn, $cartId, $userId);
                $amount = $totals['total_amount'];
                
                // Check wallet balance
                $wallet = checkDropXWalletBalance($conn, $userId);
                
                // Generate code
                $externalPayment = createExternalPayment(
                    $conn, 
                    $userId, 
                    $cartId, 
                    $amount, 
                    $wallet['balance']
                );
                
                // Store in session
                $_SESSION['external_' . $externalPayment['payment_code']] = [
                    'payment_id' => $externalPayment['payment_id'],
                    'cart_id' => $cartId,
                    'amount' => $amount
                ];
                
                ResponseHandler::success([
                    'success' => true,
                    'message' => 'Payment code generated',
                    'data' => [
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
                    ]
                ]);
                break;
            
            default:
                ResponseHandler::error('Invalid action', 400);
        }
    }
    
    /*********************************
     * ROUTE: POST /external/verify
     * EXTERNAL API - NO CUSTOMER VERIFICATION
     * Partners: TNM Mpamba, Airtel Money, Banks
     * Returns: ONLY code + amount
     *********************************/
    elseif ($method === 'POST' && strpos($path, '/external/verify') !== false) {
        
        // Authenticate partner
        $partner = authenticatePartner($conn);
        if (!$partner) {
            ResponseHandler::error('Invalid partner credentials', 401);
        }
        
        // Get payment code
        $paymentCode = strtoupper(trim($input['payment_code'] ?? ''));
        
        if (empty($paymentCode) || strlen($paymentCode) != 4) {
            ResponseHandler::error('Valid 4-character code required', 400);
        }
        
        // Validate format
        if (!preg_match('/^[ABCDEFGHJKLMNPQRTUVWXY346789]{4}$/', $paymentCode)) {
            ResponseHandler::error('Invalid code format', 400);
        }
        
        // SIMPLE VERIFICATION - NO CUSTOMER DATA
        $stmt = $conn->prepare(
            "SELECT payment_code, amount 
             FROM external_payments 
             WHERE payment_code = :code 
             AND status = 'pending'
             AND expires_at > NOW()"
        );
        $stmt->execute([':code' => $paymentCode]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payment) {
            ResponseHandler::error('Invalid or expired code', 404);
        }
        
        // Update status to processing
        $updateStmt = $conn->prepare(
            "UPDATE external_payments 
             SET status = 'processing',
                 partner = :partner
             WHERE payment_code = :code"
        );
        $updateStmt->execute([
            ':partner' => $partner,
            ':code' => $paymentCode
        ]);
        
        // Return ONLY code and amount - NO CUSTOMER DATA
        ResponseHandler::success([
            'success' => true,
            'data' => [
                'payment_code' => $payment['payment_code'],
                'amount' => floatval($payment['amount']),
                'amount_formatted' => 'MK' . number_format($payment['amount'], 2),
                'currency' => 'MWK'
            ]
        ]);
    }
    
    /*********************************
     * ROUTE: POST /external/confirm
     * EXTERNAL API - NO CUSTOMER VERIFICATION
     * Partners confirm payment received
     *********************************/
    elseif ($method === 'POST' && strpos($path, '/external/confirm') !== false) {
        
        // Authenticate partner
        $partner = authenticatePartner($conn);
        if (!$partner) {
            ResponseHandler::error('Invalid partner credentials', 401);
        }
        
        // Get payment code and reference
        $paymentCode = strtoupper(trim($input['payment_code'] ?? ''));
        $partnerReference = $input['partner_reference'] ?? '';
        
        if (empty($paymentCode)) {
            ResponseHandler::error('Payment code required', 400);
        }
        
        try {
            $conn->beginTransaction();
            
            // Get payment record - ONLY what we need
            $stmt = $conn->prepare(
                "SELECT id, user_id, cart_id, amount 
                 FROM external_payments 
                 WHERE payment_code = :code 
                 AND status = 'processing'
                 FOR UPDATE"
            );
            $stmt->execute([':code' => $paymentCode]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$payment) {
                throw new Exception('Invalid payment code');
            }
            
            // Mark as completed
            $updateStmt = $conn->prepare(
                "UPDATE external_payments 
                 SET status = 'completed',
                     partner = :partner,
                     partner_reference = :reference,
                     completed_at = NOW()
                 WHERE id = :payment_id"
            );
            $updateStmt->execute([
                ':partner' => $partner,
                ':reference' => $partnerReference,
                ':payment_id' => $payment['id']
            ]);
            
            // Add funds to wallet
            $walletStmt = $conn->prepare(
                "UPDATE dropx_wallets 
                 SET balance = balance + :amount
                 WHERE user_id = :user_id"
            );
            $walletStmt->execute([
                ':amount' => $payment['amount'],
                ':user_id' => $payment['user_id']
            ]);
            
            // Record wallet credit
            $txnStmt = $conn->prepare(
                "INSERT INTO wallet_transactions 
                    (user_id, amount, type, reference_type, reference_id, 
                     partner, partner_reference, status, description)
                 VALUES 
                    (:user_id, :amount, 'credit', 'external_payment', :payment_id,
                     :partner, :reference, 'completed', 
                     'Wallet top-up via ' || :partner)"
            );
            $txnStmt->execute([
                ':user_id' => $payment['user_id'],
                ':amount' => $payment['amount'],
                ':payment_id' => $payment['id'],
                ':partner' => $partner,
                ':reference' => $partnerReference
            ]);
            
            $conn->commit();
            
            // Return success - NO CUSTOMER DATA
            ResponseHandler::success([
                'success' => true,
                'message' => 'Payment confirmed',
                'data' => [
                    'payment_code' => $paymentCode,
                    'amount' => $payment['amount'],
                    'partner_reference' => $partnerReference
                ]
            ]);
            
        } catch (Exception $e) {
            $conn->rollBack();
            ResponseHandler::error($e->getMessage(), 400);
        }
    }
    
    /*********************************
     * ROUTE: POST /external/status
     * EXTERNAL API - Check code status
     *********************************/
    elseif ($method === 'POST' && strpos($path, '/external/status') !== false) {
        
        $partner = authenticatePartner($conn);
        if (!$partner) {
            ResponseHandler::error('Invalid partner credentials', 401);
        }
        
        $paymentCode = strtoupper(trim($input['payment_code'] ?? ''));
        
        if (empty($paymentCode)) {
            ResponseHandler::error('Payment code required', 400);
        }
        
        $stmt = $conn->prepare(
            "SELECT payment_code, amount, status, expires_at
             FROM external_payments 
             WHERE payment_code = :code"
        );
        $stmt->execute([':code' => $paymentCode]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payment) {
            ResponseHandler::error('Code not found', 404);
        }
        
        ResponseHandler::success([
            'success' => true,
            'data' => [
                'payment_code' => $payment['payment_code'],
                'amount' => floatval($payment['amount']),
                'status' => $payment['status'],
                'expires_at' => $payment['expires_at'],
                'is_valid' => $payment['status'] === 'pending' && 
                             strtotime($payment['expires_at']) > time()
            ]
        ]);
    }
    
    else {
        ResponseHandler::error('Endpoint not found', 404);
    }
    
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}
?>