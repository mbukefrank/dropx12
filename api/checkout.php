<?php
/*********************************
 * CHECKOUT SCREEN - DROPX WALLET ONLY
 * Malawi Kwacha (MWK)
 * 4-Character Alphanumeric External Codes
 * WITH FULL MULTI-MERCHANT SUPPORT - FIXED PARAMETER BINDING
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Partner-Key, X-Partner-Reference");
header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 0);

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
 * GET CART ITEMS WITH MERCHANT DETAILS
 *********************************/
function getCartItemsWithMerchants($conn, $cartId) {
    $stmt = $conn->prepare(
        "SELECT 
            ci.id,
            ci.menu_item_id,
            ci.quantity,
            mi.name as item_name,
            mi.price,
            mi.merchant_id,
            m.id as merchant_id,
            m.name as merchant_name,
            m.delivery_fee as merchant_delivery_fee,
            m.minimum_order as merchant_minimum
         FROM cart_items ci
         JOIN menu_items mi ON ci.menu_item_id = mi.id
         JOIN merchants m ON mi.merchant_id = m.id
         WHERE ci.cart_id = :cart_id
         AND ci.is_active = 1
         ORDER BY m.id, mi.name"
    );
    $stmt->execute([':cart_id' => $cartId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*********************************
 * GROUP CART ITEMS BY MERCHANT
 *********************************/
function groupItemsByMerchant($items) {
    $merchants = [];
    $totalSubtotal = 0;
    
    foreach ($items as $item) {
        $merchantId = $item['merchant_id'];
        
        if (!isset($merchants[$merchantId])) {
            $merchants[$merchantId] = [
                'merchant_id' => $merchantId,
                'merchant_name' => $item['merchant_name'],
                'delivery_fee' => floatval($item['merchant_delivery_fee'] ?? 1500.00),
                'minimum_order' => floatval($item['merchant_minimum'] ?? 0),
                'items' => [],
                'subtotal' => 0,
                'item_count' => 0,
                'total_quantity' => 0
            ];
        }
        
        $itemTotal = floatval($item['price']) * intval($item['quantity']);
        
        $merchants[$merchantId]['items'][] = [
            'id' => $item['id'],
            'menu_item_id' => $item['menu_item_id'],
            'name' => $item['item_name'],
            'quantity' => intval($item['quantity']),
            'price' => floatval($item['price']),
            'total' => $itemTotal,
            'formatted_price' => 'MK' . number_format($item['price'], 2),
            'formatted_total' => 'MK' . number_format($itemTotal, 2)
        ];
        
        $merchants[$merchantId]['subtotal'] += $itemTotal;
        $merchants[$merchantId]['item_count']++;
        $merchants[$merchantId]['total_quantity'] += intval($item['quantity']);
        $totalSubtotal += $itemTotal;
    }
    
    return ['merchants' => $merchants, 'total_subtotal' => $totalSubtotal];
}

/*********************************
 * CALCULATE CART TOTALS WITH MERCHANT BREAKDOWN
 *********************************/
function calculateCartTotalsWithMerchants($conn, $cartId, $userId, $merchants, $totalSubtotal) {
    // Global calculations
    $discount = 0;
    
    // Get cart discount if any
    $cartStmt = $conn->prepare("SELECT applied_discount FROM carts WHERE id = :cart_id");
    $cartStmt->execute([':cart_id' => $cartId]);
    $cartData = $cartStmt->fetch(PDO::FETCH_ASSOC);
    $discount = floatval($cartData['applied_discount'] ?? 0);
    
    // Apply discount proportionally if it exists
    $adjustedSubtotal = max(0, $totalSubtotal - $discount);
    
    // Global fees
    $globalServiceFee = max(500.00, $adjustedSubtotal * 0.02);
    $globalTaxRate = 0.165;
    
    // Calculate per-merchant totals
    $merchantTotals = [];
    $totalDeliveryFees = 0;
    $totalServiceFees = 0;
    $totalTaxAmounts = 0;
    $totalAmount = 0;
    
    foreach ($merchants as $merchantId => $merchant) {
        // Calculate this merchant's share of discount (proportional to their subtotal)
        $merchantRatio = $merchant['subtotal'] / $totalSubtotal;
        $merchantDiscount = round($discount * $merchantRatio, 2);
        $merchantAdjustedSubtotal = $merchant['subtotal'] - $merchantDiscount;
        
        // Use merchant's delivery fee
        $deliveryFee = $merchant['delivery_fee'];
        
        // Calculate merchant's share of service fee
        $serviceFee = round($globalServiceFee * $merchantRatio, 2);
        
        // Calculate tax (applies to subtotal + delivery + service fee)
        $taxableAmount = $merchantAdjustedSubtotal + $deliveryFee + $serviceFee;
        $taxAmount = round($taxableAmount * $globalTaxRate, 2);
        
        // Merchant total
        $merchantTotal = $taxableAmount + $taxAmount;
        
        $merchantTotals[$merchantId] = [
            'merchant_id' => $merchantId,
            'merchant_name' => $merchant['merchant_name'],
            'subtotal' => round($merchant['subtotal'], 2),
            'subtotal_formatted' => 'MK' . number_format($merchant['subtotal'], 2),
            'discount' => $merchantDiscount,
            'discount_formatted' => 'MK' . number_format($merchantDiscount, 2),
            'adjusted_subtotal' => $merchantAdjustedSubtotal,
            'adjusted_subtotal_formatted' => 'MK' . number_format($merchantAdjustedSubtotal, 2),
            'delivery_fee' => $deliveryFee,
            'delivery_fee_formatted' => 'MK' . number_format($deliveryFee, 2),
            'service_fee' => $serviceFee,
            'service_fee_formatted' => 'MK' . number_format($serviceFee, 2),
            'tax_amount' => $taxAmount,
            'tax_amount_formatted' => 'MK' . number_format($taxAmount, 2),
            'total' => $merchantTotal,
            'total_formatted' => 'MK' . number_format($merchantTotal, 2),
            'item_count' => $merchant['item_count'],
            'total_quantity' => $merchant['total_quantity']
        ];
        
        $totalDeliveryFees += $deliveryFee;
        $totalServiceFees += $serviceFee;
        $totalTaxAmounts += $taxAmount;
        $totalAmount += $merchantTotal;
    }
    
    return [
        'merchant_totals' => $merchantTotals,
        'global' => [
            'subtotal' => round($totalSubtotal, 2),
            'subtotal_formatted' => 'MK' . number_format($totalSubtotal, 2),
            'discount' => round($discount, 2),
            'discount_formatted' => 'MK' . number_format($discount, 2),
            'adjusted_subtotal' => round($adjustedSubtotal, 2),
            'adjusted_subtotal_formatted' => 'MK' . number_format($adjustedSubtotal, 2),
            'total_delivery_fees' => round($totalDeliveryFees, 2),
            'total_delivery_fees_formatted' => 'MK' . number_format($totalDeliveryFees, 2),
            'total_service_fees' => round($totalServiceFees, 2),
            'total_service_fees_formatted' => 'MK' . number_format($totalServiceFees, 2),
            'total_tax' => round($totalTaxAmounts, 2),
            'total_tax_formatted' => 'MK' . number_format($totalTaxAmounts, 2),
            'total_amount' => round($totalAmount, 2),
            'total_amount_formatted' => 'MK' . number_format($totalAmount, 2),
            'tax_rate' => '16.5%',
            'item_count' => array_sum(array_column($merchantTotals, 'item_count')),
            'total_quantity' => array_sum(array_column($merchantTotals, 'total_quantity')),
            'currency' => 'MWK'
        ]
    ];
}

/*********************************
 * GET USER'S DEFAULT ADDRESS
 *********************************/
function getUserDefaultAddress($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT id, label, address_line1, city, neighborhood, latitude, longitude 
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
 * VALIDATE MERCHANT MINIMUM ORDERS
 *********************************/
function validateMerchantMinimums($merchants) {
    $violations = [];
    
    foreach ($merchants as $merchantId => $merchant) {
        if ($merchant['minimum_order'] > 0 && $merchant['subtotal'] < $merchant['minimum_order']) {
            $violations[] = [
                'merchant_id' => $merchantId,
                'merchant_name' => $merchant['merchant_name'],
                'minimum' => $merchant['minimum_order'],
                'minimum_formatted' => 'MK' . number_format($merchant['minimum_order'], 2),
                'current' => $merchant['subtotal'],
                'current_formatted' => 'MK' . number_format($merchant['subtotal'], 2),
                'shortfall' => $merchant['minimum_order'] - $merchant['subtotal'],
                'shortfall_formatted' => 'MK' . number_format($merchant['minimum_order'] - $merchant['subtotal'], 2)
            ];
        }
    }
    
    return $violations;
}

/*********************************
 * CREATE EXTERNAL PAYMENT CODE
 *********************************/
function createExternalPayment($conn, $userId, $cartId, $amount, $walletBalance) {
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
        'formatted_code' => implode(' ', str_split($paymentCode)),
        'expires_at' => $expiresAt,
        'amount' => $amount,
        'formatted_amount' => 'MK' . number_format($amount, 2)
    ];
}

/*********************************
 * CREATE ORDER GROUP
 *********************************/
function createOrderGroup($conn, $userId, $cartId, $totalAmount) {
    $stmt = $conn->prepare(
        "INSERT INTO order_groups (user_id, cart_id, total_amount, status, created_at)
         VALUES (:user_id, :cart_id, :total_amount, 'pending', NOW())"
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':cart_id' => $cartId,
        ':total_amount' => $totalAmount
    ]);
    
    return $conn->lastInsertId();
}

/*********************************
 * CREATE MERCHANT ORDER (FIXED)
 *********************************/
function createMerchantOrder($conn, $userId, $merchantId, $orderGroupId, $merchantTotals, $deliveryAddress, $paymentMethod = 'dropx_wallet') {
    // Generate unique order number
    $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT) . '-' . $merchantId;
    
    $stmt = $conn->prepare(
        "INSERT INTO orders 
            (order_number, user_id, merchant_id, order_group_id, 
             subtotal, discount_amount, delivery_fee, service_fee, tax_amount, total_amount,
             payment_method, payment_status, delivery_address, status, created_at, updated_at)
         VALUES 
            (:order_number, :user_id, :merchant_id, :order_group_id,
             :subtotal, :discount, :delivery_fee, :service_fee, :tax_amount, :total_amount,
             :payment_method, 'paid', :delivery_address, 'confirmed', NOW(), NOW())"
    );
    
    $stmt->execute([
        ':order_number' => $orderNumber,
        ':user_id' => $userId,
        ':merchant_id' => $merchantId,
        ':order_group_id' => $orderGroupId,
        ':subtotal' => $merchantTotals['subtotal'],
        ':discount' => $merchantTotals['discount'],
        ':delivery_fee' => $merchantTotals['delivery_fee'],
        ':service_fee' => $merchantTotals['service_fee'],
        ':tax_amount' => $merchantTotals['tax_amount'],
        ':total_amount' => $merchantTotals['total'],
        ':payment_method' => $paymentMethod,
        ':delivery_address' => $deliveryAddress
    ]);
    
    return [
        'order_id' => $conn->lastInsertId(),
        'order_number' => $orderNumber
    ];
}

/*********************************
 * ADD ORDER ITEMS (FIXED)
 *********************************/
function addOrderItems($conn, $orderId, $items) {
    $itemStmt = $conn->prepare(
        "INSERT INTO order_items 
            (order_id, menu_item_id, item_name, quantity, unit_price, total_price, created_at)
         VALUES 
            (:order_id, :menu_item_id, :item_name, :quantity, :unit_price, :total_price, NOW())"
    );
    
    foreach ($items as $item) {
        $itemTotal = $item['price'] * $item['quantity'];
        $itemStmt->execute([
            ':order_id' => $orderId,
            ':menu_item_id' => $item['menu_item_id'],
            ':item_name' => $item['name'],
            ':quantity' => $item['quantity'],
            ':unit_price' => $item['price'],
            ':total_price' => $itemTotal
        ]);
    }
}

/*********************************
 * CREATE ORDER TRACKING
 *********************************/
function createOrderTracking($conn, $orderId) {
    $estimatedDelivery = date('Y-m-d H:i:s', strtotime('+45 minutes'));
    
    $stmt = $conn->prepare(
        "INSERT INTO order_tracking 
            (order_id, status, estimated_delivery, created_at)
         VALUES 
            (:order_id, 'Order placed', :estimated_delivery, NOW())"
    );
    
    $stmt->execute([
        ':order_id' => $orderId,
        ':estimated_delivery' => $estimatedDelivery
    ]);
}

/*********************************
 * PROCESS DROPX WALLET PAYMENT - MULTI-MERCHANT (FIXED PARAMETER BINDING)
 *********************************/
function processWalletPayment($conn, $userId, $cartId, $merchants, $merchantTotals, $globalTotals) {
    try {
        $conn->beginTransaction();
        
        $totalAmount = $globalTotals['total_amount'];
        
        // Check wallet balance with row lock
        $walletStmt = $conn->prepare(
            "SELECT id, balance FROM dropx_wallets 
             WHERE user_id = :user_id AND is_active = 1
             FOR UPDATE"
        );
        $walletStmt->execute([':user_id' => $userId]);
        $wallet = $walletStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$wallet || $wallet['balance'] < $totalAmount) {
            throw new Exception('Insufficient wallet balance');
        }
        
        // Deduct from wallet
        $updateStmt = $conn->prepare(
            "UPDATE dropx_wallets 
             SET balance = balance - :amount
             WHERE id = :wallet_id"
        );
        $updateStmt->execute([
            ':amount' => $totalAmount,
            ':wallet_id' => $wallet['id']
        ]);
        
        // FIXED: Record wallet transaction - proper parameter binding
        $txnStmt = $conn->prepare(
            "INSERT INTO wallet_transactions 
                (user_id, amount, type, reference_type, reference_id, status, description)
             VALUES 
                (:user_id, :amount, 'debit', 'cart', :reference_id, 'completed', 
                 :description)"
        );
        
        $description = 'Payment for multi-merchant order from cart #' . $cartId;
        
        $txnStmt->execute([
            ':user_id' => $userId,
            ':amount' => $totalAmount,
            ':reference_id' => $cartId,
            ':description' => $description
        ]);
        
        // Get user's default address
        $address = getUserDefaultAddress($conn, $userId);
        $deliveryAddress = $address 
            ? $address['address_line1'] . ', ' . $address['city']
            : 'Default Address';
        
        // Create order group
        $orderGroupId = createOrderGroup($conn, $userId, $cartId, $totalAmount);
        
        // Create orders for each merchant
        $createdOrders = [];
        
        foreach ($merchantTotals as $merchantId => $totals) {
            // Get items for this merchant
            $merchantItems = $merchants[$merchantId]['items'];
            
            // Create order
            $orderData = createMerchantOrder(
                $conn, 
                $userId, 
                $merchantId, 
                $orderGroupId, 
                $totals, 
                $deliveryAddress
            );
            
            // Add order items
            addOrderItems($conn, $orderData['order_id'], $merchantItems);
            
            // Create tracking
            createOrderTracking($conn, $orderData['order_id']);
            
            $createdOrders[] = [
                'order_id' => $orderData['order_id'],
                'order_number' => $orderData['order_number'],
                'merchant_id' => $merchantId,
                'merchant_name' => $merchants[$merchantId]['merchant_name'],
                'total' => $totals['total'],
                'total_formatted' => $totals['total_formatted']
            ];
        }
        
        // Clear the cart
        $clearCartStmt = $conn->prepare(
            "UPDATE cart_items SET is_active = 0 WHERE cart_id = :cart_id"
        );
        $clearCartStmt->execute([':cart_id' => $cartId]);
        
        $updateCartStmt = $conn->prepare(
            "UPDATE carts SET status = 'completed' WHERE id = :cart_id"
        );
        $updateCartStmt->execute([':cart_id' => $cartId]);
        
        // Update order group status
        $updateGroupStmt = $conn->prepare(
            "UPDATE order_groups SET status = 'completed' WHERE id = :group_id"
        );
        $updateGroupStmt->execute([':group_id' => $orderGroupId]);
        
        $conn->commit();
        
        return [
            'success' => true,
            'order_group_id' => $orderGroupId,
            'orders' => $createdOrders,
            'total_amount' => $totalAmount,
            'total_amount_formatted' => 'MK' . number_format($totalAmount, 2),
            'new_balance' => $wallet['balance'] - $totalAmount,
            'new_balance_formatted' => 'MK' . number_format($wallet['balance'] - $totalAmount, 2)
        ];
        
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Multi-merchant payment error: " . $e->getMessage());
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
        
        // Get all cart items with merchant details
        $items = getCartItemsWithMerchants($conn, $cart['id']);
        
        // Group items by merchant
        $groupedData = groupItemsByMerchant($items);
        $merchants = $groupedData['merchants'];
        $totalSubtotal = $groupedData['total_subtotal'];
        
        // Validate merchant minimums
        $minimumViolations = validateMerchantMinimums($merchants);
        if (!empty($minimumViolations)) {
            ResponseHandler::error([
                'message' => 'Some merchants have minimum order requirements',
                'violations' => $minimumViolations
            ], 400);
        }
        
        // Calculate totals with merchant breakdown
        $totals = calculateCartTotalsWithMerchants($conn, $cart['id'], $userId, $merchants, $totalSubtotal);
        
        // Get user address and wallet
        $address = getUserDefaultAddress($conn, $userId);
        $wallet = checkDropXWalletBalance($conn, $userId);
        
        // Prepare response
        ResponseHandler::success([
            'cart_id' => $cart['id'],
            'merchants' => array_values($merchants),
            'merchant_totals' => array_values($totals['merchant_totals']),
            'totals' => $totals['global'],
            'delivery_address' => $address ? [
                'id' => $address['id'],
                'label' => $address['label'] ?? 'Home',
                'address' => $address['address_line1'] . ', ' . $address['city'],
                'neighborhood' => $address['neighborhood'] ?? '',
                'latitude' => $address['latitude'] ?? null,
                'longitude' => $address['longitude'] ?? null
            ] : null,
            'wallet' => [
                'exists' => $wallet['exists'],
                'balance' => $wallet['balance'],
                'balance_formatted' => 'MK' . number_format($wallet['balance'], 2),
                'is_active' => $wallet['is_active'],
                'sufficient' => $wallet['balance'] >= $totals['global']['total_amount'],
                'shortfall' => $wallet['balance'] >= $totals['global']['total_amount'] ? 0 : 
                              round($totals['global']['total_amount'] - $wallet['balance'], 2),
                'shortfall_formatted' => $wallet['balance'] >= $totals['global']['total_amount'] ? 'MK0.00' : 
                              'MK' . number_format($totals['global']['total_amount'] - $wallet['balance'], 2)
            ],
            'payment_options' => [
                'dropx_wallet' => [
                    'available' => $wallet['exists'] && $wallet['is_active'],
                    'can_pay' => $wallet['balance'] >= $totals['global']['total_amount']
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
                
                // Get items and calculate totals
                $items = getCartItemsWithMerchants($conn, $cartId);
                $groupedData = groupItemsByMerchant($items);
                $totals = calculateCartTotalsWithMerchants(
                    $conn, 
                    $cartId, 
                    $userId, 
                    $groupedData['merchants'], 
                    $groupedData['total_subtotal']
                );
                
                $_SESSION['checkout_' . $cartId] = [
                    'cart_id' => $cartId,
                    'user_id' => $userId,
                    'amount' => $totals['global']['total_amount'],
                    'merchant_data' => $groupedData['merchants'],
                    'merchant_totals' => $totals['merchant_totals'],
                    'global_totals' => $totals['global'],
                    'initiated_at' => date('Y-m-d H:i:s')
                ];
                
                ResponseHandler::success([
                    'cart_id' => $cartId,
                    'amount' => $totals['global']['total_amount'],
                    'amount_formatted' => 'MK' . number_format($totals['global']['total_amount'], 2),
                    'merchant_count' => count($groupedData['merchants'])
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
                
                // Process the multi-merchant payment
                $result = processWalletPayment(
                    $conn, 
                    $userId, 
                    $cartId, 
                    $session['merchant_data'],
                    $session['merchant_totals'],
                    $session['global_totals']
                );
                
                if ($result['success']) {
                    unset($_SESSION['checkout_' . $cartId]);
                    
                    ResponseHandler::success([
                        'order_group_id' => $result['order_group_id'],
                        'orders' => $result['orders'],
                        'total_amount' => $result['total_amount'],
                        'total_amount_formatted' => $result['total_amount_formatted'],
                        'new_balance' => $result['new_balance'],
                        'new_balance_formatted' => $result['new_balance_formatted']
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
                
                // Get items and calculate totals
                $items = getCartItemsWithMerchants($conn, $cartId);
                $groupedData = groupItemsByMerchant($items);
                $totals = calculateCartTotalsWithMerchants(
                    $conn, 
                    $cartId, 
                    $userId, 
                    $groupedData['merchants'], 
                    $groupedData['total_subtotal']
                );
                
                $amount = $totals['global']['total_amount'];
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
                    'amount' => $amount,
                    'merchant_count' => count($groupedData['merchants'])
                ];
                
                ResponseHandler::success([
                    'payment_code' => $externalPayment['payment_code'],
                    'formatted_code' => $externalPayment['formatted_code'],
                    'amount' => $amount,
                    'amount_formatted' => 'MK' . number_format($amount, 2),
                    'expires_at' => $externalPayment['expires_at'],
                    'expiry_minutes' => 30,
                    'merchant_count' => count($groupedData['merchants']),
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
                        '✅ Pay cash, we add funds to your wallet instantly',
                        '🛍️  This covers ' . count($groupedData['merchants']) . ' merchant(s) in one payment'
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