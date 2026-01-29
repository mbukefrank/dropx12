<?php
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
$baseUrl = "https://dropxbackend-production.up.railway.app";

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $path = $_SERVER['REQUEST_URI'];
    
    // Parse request
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = $_POST;
    }

    switch ($method) {
        case 'GET':
            handleGetRequest($path, $_GET);
            break;
        case 'POST':
            handlePostRequest($path, $input);
            break;
        case 'PUT':
            handlePutRequest($path, $input);
            break;
        case 'DELETE':
            handleDeleteRequest($path, $input);
            break;
        default:
            ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET REQUESTS
 *********************************/
function handleGetRequest($path, $queryParams) {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();

    // Check for cart endpoint
    if (strpos($path, '/cart') !== false) {
        $cartId = $queryParams['cart_id'] ?? null;
        
        if ($cartId) {
            getCartDetails($conn, $cartId, $baseUrl);
        } else {
            getCurrentCart($conn, $baseUrl);
        }
    } elseif (strpos($path, '/cart/items') !== false) {
        getCartItems($conn, $baseUrl);
    } else {
        ResponseHandler::error('Endpoint not found', 404);
    }
}

/*********************************
 * GET CURRENT CART
 *********************************/
function getCurrentCart($conn, $baseUrl) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    
    // First, get or create a cart for the user
    $cart = getOrCreateUserCart($conn, $userId);
    
    if (!$cart) {
        ResponseHandler::error('Failed to retrieve cart', 500);
    }
    
    // Get cart items
    $cartItems = getCartItemsByCartId($conn, $cart['id'], $baseUrl);
    
    // Calculate totals
    $totals = calculateCartTotals($conn, $cart['id'], $userId);
    
    // Get applicable promotions
    $promotions = getApplicablePromotions($conn, $userId, $totals['subtotal']);
    
    ResponseHandler::success([
        'cart' => [
            'id' => $cart['id'],
            'user_id' => $cart['user_id'],
            'created_at' => $cart['created_at'],
            'updated_at' => $cart['updated_at']
        ],
        'items' => $cartItems,
        'summary' => [
            'subtotal' => $totals['subtotal'],
            'delivery_fee' => $totals['delivery_fee'],
            'service_fee' => $totals['service_fee'],
            'tax_amount' => $totals['tax_amount'],
            'total_amount' => $totals['total_amount'],
            'item_count' => $totals['item_count'],
            'unique_item_count' => count($cartItems)
        ],
        'promotions' => $promotions,
        'is_eligible_for_checkout' => $totals['item_count'] > 0
    ]);
}

/*********************************
 * GET OR CREATE USER CART
 *********************************/
function getOrCreateUserCart($conn, $userId) {
    // Check for existing active cart
    $stmt = $conn->prepare(
        "SELECT id, user_id, created_at, updated_at 
         FROM carts 
         WHERE user_id = :user_id 
         AND status = 'active'
         ORDER BY created_at DESC 
         LIMIT 1"
    );
    
    $stmt->execute([':user_id' => $userId]);
    $cart = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($cart) {
        return $cart;
    }
    
    // Create new cart
    $insertStmt = $conn->prepare(
        "INSERT INTO carts (user_id, status, created_at, updated_at)
         VALUES (:user_id, 'active', NOW(), NOW())"
    );
    
    $insertStmt->execute([':user_id' => $userId]);
    $cartId = $conn->lastInsertId();
    
    return [
        'id' => $cartId,
        'user_id' => $userId,
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];
}

/*********************************
 * GET CART ITEMS BY CART ID
 *********************************/
function getCartItemsByCartId($conn, $cartId, $baseUrl) {
    $stmt = $conn->prepare(
        "SELECT 
            ci.id,
            ci.cart_id,
            ci.item_id,
            mi.name as item_name,
            mi.description as item_description,
            mi.price,
            ci.quantity,
            ci.special_instructions,
            ci.created_at,
            ci.updated_at,
            m.id as merchant_id,
            m.name as merchant_name,
            m.category as merchant_category,
            m.image_url as merchant_image,
            mi.image_url as item_image,
            mi.category as item_category,
            CASE 
                WHEN m.category LIKE '%dropx%' OR m.name LIKE '%DropX%' THEN 1
                ELSE 0 
            END as is_dropx
        FROM cart_items ci
        LEFT JOIN menu_items mi ON ci.item_id = mi.id
        LEFT JOIN merchants m ON mi.merchant_id = m.id
        WHERE ci.cart_id = :cart_id
        AND ci.is_active = 1
        ORDER BY ci.created_at DESC"
    );
    
    $stmt->execute([':cart_id' => $cartId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return array_map(function($item) use ($baseUrl) {
        return formatCartItemData($item, $baseUrl);
    }, $items);
}

/*********************************
 * CALCULATE CART TOTALS
 *********************************/
function calculateCartTotals($conn, $cartId, $userId) {
    // Get subtotal from cart items
    $stmt = $conn->prepare(
        "SELECT 
            SUM(mi.price * ci.quantity) as subtotal,
            COUNT(ci.id) as item_count,
            SUM(ci.quantity) as total_quantity
        FROM cart_items ci
        LEFT JOIN menu_items mi ON ci.item_id = mi.id
        WHERE ci.cart_id = :cart_id
        AND ci.is_active = 1"
    );
    
    $stmt->execute([':cart_id' => $cartId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $subtotal = floatval($result['subtotal'] ?? 0);
    $itemCount = intval($result['item_count'] ?? 0);
    $totalQuantity = intval($result['total_quantity'] ?? 0);
    
    // Get user's default address for delivery fee calculation
    $addressStmt = $conn->prepare(
        "SELECT a.*, dz.delivery_fee, dz.min_delivery_amount
         FROM addresses a
         LEFT JOIN delivery_zones dz ON 1=1 -- This should use spatial query in production
         WHERE a.user_id = :user_id 
         AND a.is_default = 1
         LIMIT 1"
    );
    
    $addressStmt->execute([':user_id' => $userId]);
    $address = $addressStmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate delivery fee (simplified - should use spatial query)
    $deliveryFee = 2.99; // Default fee
    
    if ($address && !empty($address['delivery_fee'])) {
        $deliveryFee = floatval($address['delivery_fee']);
    }
    
    // Apply minimum delivery amount
    if ($subtotal > 0 && $address && !empty($address['min_delivery_amount'])) {
        $minAmount = floatval($address['min_delivery_amount']);
        if ($subtotal < $minAmount) {
            $deliveryFee += 2.00; // Additional fee for small orders
        }
    }
    
    // Calculate service fee (2% of subtotal, min $1.50)
    $serviceFee = max(1.50, $subtotal * 0.02);
    
    // Calculate tax (10% of subtotal + delivery + service)
    $taxableAmount = $subtotal + $deliveryFee + $serviceFee;
    $taxAmount = $taxableAmount * 0.10;
    
    // Total amount
    $totalAmount = $taxableAmount + $taxAmount;
    
    return [
        'subtotal' => round($subtotal, 2),
        'delivery_fee' => round($deliveryFee, 2),
        'service_fee' => round($serviceFee, 2),
        'tax_amount' => round($taxAmount, 2),
        'total_amount' => round($totalAmount, 2),
        'item_count' => $itemCount,
        'total_quantity' => $totalQuantity
    ];
}

/*********************************
 * GET APPLICABLE PROMOTIONS
 *********************************/
function getApplicablePromotions($conn, $userId, $subtotal) {
    $currentDate = date('Y-m-d');
    
    $stmt = $conn->prepare(
        "SELECT 
            p.id,
            p.code,
            p.name,
            p.description,
            p.discount_type,
            p.discount_value,
            p.min_order_amount,
            p.max_discount_amount,
            p.usage_limit,
            p.times_used,
            p.applicable_to,
            p.applicable_ids
        FROM promotions p
        WHERE p.is_active = 1
        AND p.valid_from <= :current_date
        AND p.valid_until >= :current_date
        AND (p.usage_limit IS NULL OR p.times_used < p.usage_limit)
        AND (p.min_order_amount IS NULL OR p.min_order_amount <= :subtotal)
        ORDER BY p.discount_value DESC"
    );
    
    $stmt->execute([
        ':current_date' => $currentDate,
        ':subtotal' => $subtotal
    ]);
    
    $promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Filter promotions that user hasn't exceeded usage limit
    $applicablePromotions = [];
    foreach ($promotions as $promotion) {
        // Check user's usage
        $usageStmt = $conn->prepare(
            "SELECT COUNT(*) as usage_count
             FROM promotion_usage
             WHERE promotion_id = :promotion_id
             AND user_id = :user_id"
        );
        
        $usageStmt->execute([
            ':promotion_id' => $promotion['id'],
            ':user_id' => $userId
        ]);
        
        $usage = $usageStmt->fetch(PDO::FETCH_ASSOC);
        $userUsageCount = intval($usage['usage_count'] ?? 0);
        
        // Check if user has personal usage limit (simplified)
        if ($promotion['usage_limit'] && $userUsageCount >= $promotion['usage_limit']) {
            continue;
        }
        
        // Calculate discount amount
        $discountAmount = 0;
        if ($promotion['discount_type'] === 'percentage') {
            $discountAmount = $subtotal * ($promotion['discount_value'] / 100);
            if ($promotion['max_discount_amount'] && $discountAmount > $promotion['max_discount_amount']) {
                $discountAmount = $promotion['max_discount_amount'];
            }
        } else {
            $discountAmount = $promotion['discount_value'];
        }
        
        $promotion['discount_amount'] = round($discountAmount, 2);
        $promotion['user_usage_count'] = $userUsageCount;
        $applicablePromotions[] = $promotion;
    }
    
    return $applicablePromotions;
}

/*********************************
 * POST REQUESTS
 *********************************/
function handlePostRequest($path, $data) {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();

    // Route based on path
    if (strpos($path, '/cart/items/add') !== false) {
        addItemToCart($conn, $data);
    } elseif (strpos($path, '/cart/apply-promotion') !== false) {
        applyPromotionToCart($conn, $data);
    } elseif (strpos($path, '/cart/clear') !== false) {
        clearCart($conn, $data);
    } elseif (strpos($path, '/cart/checkout') !== false) {
        prepareCheckout($conn, $data);
    } else {
        ResponseHandler::error('Endpoint not found', 404);
    }
}

/*********************************
 * ADD ITEM TO CART
 *********************************/
function addItemToCart($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $itemId = $data['item_id'] ?? null;
    $quantity = intval($data['quantity'] ?? 1);
    $specialInstructions = trim($data['special_instructions'] ?? '');
    
    if (!$itemId) {
        ResponseHandler::error('Item ID is required', 400);
    }
    
    if ($quantity < 1) {
        ResponseHandler::error('Quantity must be at least 1', 400);
    }
    
    // Check if item exists and is available
    $itemCheckStmt = $conn->prepare(
        "SELECT mi.id, mi.name, mi.price, mi.is_available, 
                m.id as merchant_id, m.name as merchant_name
         FROM menu_items mi
         LEFT JOIN merchants m ON mi.merchant_id = m.id
         WHERE mi.id = :item_id
         AND mi.is_available = 1
         AND m.is_active = 1"
    );
    
    $itemCheckStmt->execute([':item_id' => $itemId]);
    $item = $itemCheckStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        ResponseHandler::error('Item not available or merchant not active', 404);
    }
    
    // Get or create user cart
    $cart = getOrCreateUserCart($conn, $userId);
    
    // Check if item already exists in cart
    $existingStmt = $conn->prepare(
        "SELECT id, quantity, special_instructions 
         FROM cart_items 
         WHERE cart_id = :cart_id 
         AND item_id = :item_id 
         AND is_active = 1"
    );
    
    $existingStmt->execute([
        ':cart_id' => $cart['id'],
        ':item_id' => $itemId
    ]);
    
    $existingItem = $existingStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingItem) {
        // Update existing item
        $newQuantity = $existingItem['quantity'] + $quantity;
        
        $updateStmt = $conn->prepare(
            "UPDATE cart_items 
             SET quantity = :quantity, 
                 special_instructions = :instructions,
                 updated_at = NOW()
             WHERE id = :id"
        );
        
        $updateStmt->execute([
            ':quantity' => $newQuantity,
            ':instructions' => $specialInstructions ?: $existingItem['special_instructions'],
            ':id' => $existingItem['id']
        ]);
        
        $message = 'Item quantity updated in cart';
    } else {
        // Add new item to cart
        $insertStmt = $conn->prepare(
            "INSERT INTO cart_items 
                (cart_id, item_id, quantity, special_instructions, is_active, created_at, updated_at)
             VALUES (:cart_id, :item_id, :quantity, :instructions, 1, NOW(), NOW())"
        );
        
        $insertStmt->execute([
            ':cart_id' => $cart['id'],
            ':item_id' => $itemId,
            ':quantity' => $quantity,
            ':instructions' => $specialInstructions
        ]);
        
        $message = 'Item added to cart';
    }
    
    // Update cart timestamp
    $updateCartStmt = $conn->prepare(
        "UPDATE carts SET updated_at = NOW() WHERE id = :cart_id"
    );
    $updateCartStmt->execute([':cart_id' => $cart['id']]);
    
    // Log activity
    logUserActivity($conn, $userId, 'cart_update', "Added item $itemId to cart", [
        'item_id' => $itemId,
        'quantity' => $quantity,
        'merchant_id' => $item['merchant_id']
    ]);
    
    // Get updated cart summary
    $cartItems = getCartItemsByCartId($conn, $cart['id'], $GLOBALS['baseUrl']);
    $totals = calculateCartTotals($conn, $cart['id'], $userId);
    
    ResponseHandler::success([
        'message' => $message,
        'cart_item_count' => count($cartItems),
        'cart_total_quantity' => $totals['total_quantity'],
        'cart_subtotal' => $totals['subtotal']
    ], $message);
}

/*********************************
 * APPLY PROMOTION TO CART
 *********************************/
function applyPromotionToCart($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $promotionCode = trim($data['promotion_code'] ?? '');
    
    if (!$promotionCode) {
        ResponseHandler::error('Promotion code is required', 400);
    }
    
    // Get user's active cart
    $cart = getOrCreateUserCart($conn, $userId);
    $totals = calculateCartTotals($conn, $cart['id'], $userId);
    
    // Check promotion
    $currentDate = date('Y-m-d');
    
    $promoStmt = $conn->prepare(
        "SELECT * FROM promotions 
         WHERE code = :code 
         AND is_active = 1
         AND valid_from <= :current_date
         AND valid_until >= :current_date"
    );
    
    $promoStmt->execute([
        ':code' => $promotionCode,
        ':current_date' => $currentDate
    ]);
    
    $promotion = $promoStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$promotion) {
        ResponseHandler::error('Invalid or expired promotion code', 404);
    }
    
    // Check usage limit
    $usageStmt = $conn->prepare(
        "SELECT COUNT(*) as usage_count
         FROM promotion_usage
         WHERE promotion_id = :promotion_id
         AND user_id = :user_id"
    );
    
    $usageStmt->execute([
        ':promotion_id' => $promotion['id'],
        ':user_id' => $userId
    ]);
    
    $usage = $usageStmt->fetch(PDO::FETCH_ASSOC);
    $userUsageCount = intval($usage['usage_count'] ?? 0);
    
    if ($promotion['usage_limit'] && $userUsageCount >= $promotion['usage_limit']) {
        ResponseHandler::error('You have reached the usage limit for this promotion', 400);
    }
    
    // Check minimum order amount
    if ($promotion['min_order_amount'] && $totals['subtotal'] < $promotion['min_order_amount']) {
        $minAmount = number_format($promotion['min_order_amount'], 2);
        ResponseHandler::error("Minimum order amount of $$minAmount required for this promotion", 400);
    }
    
    // Calculate discount
    $discountAmount = 0;
    if ($promotion['discount_type'] === 'percentage') {
        $discountAmount = $totals['subtotal'] * ($promotion['discount_value'] / 100);
        if ($promotion['max_discount_amount'] && $discountAmount > $promotion['max_discount_amount']) {
            $discountAmount = $promotion['max_discount_amount'];
        }
    } else {
        $discountAmount = $promotion['discount_value'];
        if ($discountAmount > $totals['subtotal']) {
            $discountAmount = $totals['subtotal'];
        }
    }
    
    $discountAmount = round($discountAmount, 2);
    
    // Store applied promotion in cart (simplified - would need cart_promotions table)
    $updateStmt = $conn->prepare(
        "UPDATE carts 
         SET applied_promotion_id = :promotion_id,
             applied_discount = :discount_amount,
             updated_at = NOW()
         WHERE id = :cart_id"
    );
    
    $updateStmt->execute([
        ':promotion_id' => $promotion['id'],
        ':discount_amount' => $discountAmount,
        ':cart_id' => $cart['id']
    ]);
    
    // Log activity
    logUserActivity($conn, $userId, 'promotion_applied', "Applied promotion $promotionCode", [
        'promotion_id' => $promotion['id'],
        'discount_amount' => $discountAmount,
        'cart_id' => $cart['id']
    ]);
    
    // Calculate new total
    $newTotal = $totals['total_amount'] - $discountAmount;
    if ($newTotal < 0) $newTotal = 0;
    
    ResponseHandler::success([
        'promotion' => [
            'code' => $promotion['code'],
            'name' => $promotion['name'],
            'discount_amount' => $discountAmount,
            'discount_type' => $promotion['discount_type']
        ],
        'original_total' => $totals['total_amount'],
        'new_total' => round($newTotal, 2),
        'savings' => $discountAmount
    ], 'Promotion applied successfully');
}

/*********************************
 * CLEAR CART
 *********************************/
function clearCart($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $confirmation = $data['confirmation'] ?? false;
    
    if (!$confirmation) {
        ResponseHandler::error('Confirmation required', 400);
    }
    
    // Get user's active cart
    $cartStmt = $conn->prepare(
        "SELECT id FROM carts 
         WHERE user_id = :user_id 
         AND status = 'active'
         ORDER BY created_at DESC 
         LIMIT 1"
    );
    
    $cartStmt->execute([':user_id' => $userId]);
    $cart = $cartStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cart) {
        ResponseHandler::error('No active cart found', 404);
    }
    
    // Mark cart items as inactive (soft delete)
    $clearStmt = $conn->prepare(
        "UPDATE cart_items 
         SET is_active = 0, updated_at = NOW()
         WHERE cart_id = :cart_id 
         AND is_active = 1"
    );
    
    $clearStmt->execute([':cart_id' => $cart['id']]);
    $itemsCleared = $clearStmt->rowCount();
    
    // Update cart
    $updateCartStmt = $conn->prepare(
        "UPDATE carts 
         SET updated_at = NOW()
         WHERE id = :cart_id"
    );
    $updateCartStmt->execute([':cart_id' => $cart['id']]);
    
    // Log activity
    logUserActivity($conn, $userId, 'cart_cleared', "Cleared cart of $itemsCleared items", [
        'cart_id' => $cart['id'],
        'items_cleared' => $itemsCleared
    ]);
    
    ResponseHandler::success([
        'items_cleared' => $itemsCleared,
        'cart_id' => $cart['id']
    ], 'Cart cleared successfully');
}

/*********************************
 * PREPARE CHECKOUT
 *********************************/
function prepareCheckout($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $addressId = $data['address_id'] ?? null;
    $paymentMethod = $data['payment_method'] ?? 'cash';
    $tipAmount = floatval($data['tip_amount'] ?? 0);
    $scheduleDelivery = $data['schedule_delivery'] ?? null;
    
    // Get user's active cart
    $cart = getOrCreateUserCart($conn, $userId);
    
    // Validate cart has items
    $cartItems = getCartItemsByCartId($conn, $cart['id'], $GLOBALS['baseUrl']);
    if (empty($cartItems)) {
        ResponseHandler::error('Cannot checkout empty cart', 400);
    }
    
    // Validate address
    $address = null;
    if ($addressId) {
        $addressStmt = $conn->prepare(
            "SELECT * FROM addresses 
             WHERE id = :address_id 
             AND user_id = :user_id"
        );
        
        $addressStmt->execute([
            ':address_id' => $addressId,
            ':user_id' => $userId
        ]);
        
        $address = $addressStmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$address) {
        // Try to get default address
        $defaultAddressStmt = $conn->prepare(
            "SELECT * FROM addresses 
             WHERE user_id = :user_id 
             AND is_default = 1
             LIMIT 1"
        );
        
        $defaultAddressStmt->execute([':user_id' => $userId]);
        $address = $defaultAddressStmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$address) {
        ResponseHandler::error('Please select a delivery address', 400);
    }
    
    // Calculate final totals with address-based delivery fee
    $totals = calculateCartTotals($conn, $cart['id'], $userId);
    
    // Apply stored promotion if any
    $appliedPromotion = null;
    $appliedDiscount = 0;
    
    $promoStmt = $conn->prepare(
        "SELECT applied_promotion_id, applied_discount 
         FROM carts 
         WHERE id = :cart_id"
    );
    
    $promoStmt->execute([':cart_id' => $cart['id']]);
    $cartPromo = $promoStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($cartPromo && $cartPromo['applied_promotion_id']) {
        $appliedDiscount = floatval($cartPromo['applied_discount'] ?? 0);
        $appliedPromotion = $cartPromo['applied_promotion_id'];
    }
    
    // Final total with promotion
    $finalSubtotal = $totals['subtotal'] - $appliedDiscount;
    if ($finalSubtotal < 0) $finalSubtotal = 0;
    
    $finalTotal = $finalSubtotal + $totals['delivery_fee'] + 
                  $totals['service_fee'] + $totals['tax_amount'] + $tipAmount;
    
    // Group items by merchant for order creation
    $merchantGroups = [];
    foreach ($cartItems as $item) {
        $merchantId = $item['merchant_id'];
        if (!isset($merchantGroups[$merchantId])) {
            $merchantGroups[$merchantId] = [
                'merchant_id' => $merchantId,
                'merchant_name' => $item['merchant_name'],
                'items' => [],
                'subtotal' => 0
            ];
        }
        $merchantGroups[$merchantId]['items'][] = $item;
        $merchantGroups[$merchantId]['subtotal'] += $item['total'];
    }
    
    // Check merchant availability and minimum orders
    $merchantIssues = [];
    foreach ($merchantGroups as $merchantId => $group) {
        $merchantStmt = $conn->prepare(
            "SELECT m.*, mdz.custom_delivery_fee
             FROM merchants m
             LEFT JOIN merchant_delivery_zones mdz ON m.id = mdz.merchant_id
             WHERE m.id = :merchant_id
             AND m.is_active = 1
             AND m.is_open = 1"
        );
        
        $merchantStmt->execute([':merchant_id' => $merchantId]);
        $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$merchant) {
            $merchantIssues[] = "$group[merchant_name] is currently unavailable";
        } elseif ($merchant['min_order'] && $group['subtotal'] < floatval($merchant['min_order'])) {
            $minOrder = number_format(floatval($merchant['min_order']), 2);
            $merchantIssues[] = "$group[merchant_name] requires minimum order of $$minOrder";
        }
    }
    
    if (!empty($merchantIssues)) {
        ResponseHandler::error('Checkout issues', 400, [
            'issues' => $merchantIssues
        ]);
    }
    
    // Prepare checkout response
    $checkoutData = [
        'cart_id' => $cart['id'],
        'address' => formatAddressData($address),
        'payment_method' => $paymentMethod,
        'tip_amount' => round($tipAmount, 2),
        'schedule_delivery' => $scheduleDelivery,
        'summary' => [
            'items_subtotal' => round($totals['subtotal'], 2),
            'promotion_discount' => round($appliedDiscount, 2),
            'adjusted_subtotal' => round($finalSubtotal, 2),
            'delivery_fee' => round($totals['delivery_fee'], 2),
            'service_fee' => round($totals['service_fee'], 2),
            'tax_amount' => round($totals['tax_amount'], 2),
            'tip_amount' => round($tipAmount, 2),
            'total_amount' => round($finalTotal, 2)
        ],
        'merchant_orders' => array_values($merchantGroups),
        'item_count' => $totals['item_count'],
        'total_quantity' => $totals['total_quantity']
    ];
    
    // Store checkout data in session for order creation
    $_SESSION['checkout_data'] = $checkoutData;
    
    ResponseHandler::success($checkoutData, 'Checkout prepared successfully');
}

/*********************************
 * PUT REQUESTS - Update Cart Items
 *********************************/
function handlePutRequest($path, $data) {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();
    
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    
    if (strpos($path, '/cart/items/') !== false) {
        // Extract item ID from path
        $parts = explode('/', $path);
        $itemId = end($parts);
        
        if (is_numeric($itemId)) {
            updateCartItem($conn, $userId, $itemId, $data);
        } else {
            ResponseHandler::error('Invalid item ID', 400);
        }
    } else {
        ResponseHandler::error('Endpoint not found', 404);
    }
}

/*********************************
 * UPDATE CART ITEM
 *********************************/
function updateCartItem($conn, $userId, $cartItemId, $data) {
    $quantity = isset($data['quantity']) ? intval($data['quantity']) : null;
    $specialInstructions = isset($data['special_instructions']) ? 
                          trim($data['special_instructions']) : null;
    
    if ($quantity === null && $specialInstructions === null) {
        ResponseHandler::error('No update data provided', 400);
    }
    
    // Verify cart item belongs to user
    $verifyStmt = $conn->prepare(
        "SELECT ci.id, ci.quantity, ci.special_instructions
         FROM cart_items ci
         JOIN carts c ON ci.cart_id = c.id
         WHERE ci.id = :cart_item_id
         AND c.user_id = :user_id
         AND ci.is_active = 1"
    );
    
    $verifyStmt->execute([
        ':cart_item_id' => $cartItemId,
        ':user_id' => $userId
    ]);
    
    $cartItem = $verifyStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cartItem) {
        ResponseHandler::error('Cart item not found', 404);
    }
    
    // Prepare update
    $updates = [];
    $params = [':id' => $cartItemId];
    
    if ($quantity !== null) {
        if ($quantity < 1) {
            ResponseHandler::error('Quantity must be at least 1', 400);
        }
        $updates[] = 'quantity = :quantity';
        $params[':quantity'] = $quantity;
    }
    
    if ($specialInstructions !== null) {
        $updates[] = 'special_instructions = :instructions';
        $params[':instructions'] = $specialInstructions;
    }
    
    if (empty($updates)) {
        ResponseHandler::error('No valid updates provided', 400);
    }
    
    $updates[] = 'updated_at = NOW()';
    
    $updateSql = "UPDATE cart_items SET " . implode(', ', $updates) . " WHERE id = :id";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute($params);
    
    // Update cart timestamp
    $cartUpdateStmt = $conn->prepare(
        "UPDATE carts c
         JOIN cart_items ci ON c.id = ci.cart_id
         SET c.updated_at = NOW()
         WHERE ci.id = :cart_item_id"
    );
    $cartUpdateStmt->execute([':cart_item_id' => $cartItemId]);
    
    // Log activity
    $action = $quantity !== null ? 'updated_quantity' : 'updated_instructions';
    logUserActivity($conn, $userId, 'cart_item_updated', "Updated cart item $cartItemId", [
        'cart_item_id' => $cartItemId,
        'old_quantity' => $cartItem['quantity'],
        'new_quantity' => $quantity,
        'action' => $action
    ]);
    
    ResponseHandler::success([
        'cart_item_id' => $cartItemId,
        'updated_fields' => array_keys(array_filter([
            'quantity' => $quantity !== null,
            'special_instructions' => $specialInstructions !== null
        ]))
    ], 'Cart item updated successfully');
}

/*********************************
 * DELETE REQUESTS - Remove Cart Items
 *********************************/
function handleDeleteRequest($path, $data) {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();
    
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    
    if (strpos($path, '/cart/items/') !== false) {
        // Extract item ID from path
        $parts = explode('/', $path);
        $itemId = end($parts);
        
        if (is_numeric($itemId)) {
            removeCartItem($conn, $userId, $itemId);
        } else {
            ResponseHandler::error('Invalid item ID', 400);
        }
    } else {
        ResponseHandler::error('Endpoint not found', 404);
    }
}

/*********************************
 * REMOVE CART ITEM
 *********************************/
function removeCartItem($conn, $userId, $cartItemId) {
    // Verify cart item belongs to user
    $verifyStmt = $conn->prepare(
        "SELECT ci.id, ci.item_id, mi.name as item_name
         FROM cart_items ci
         JOIN carts c ON ci.cart_id = c.id
         JOIN menu_items mi ON ci.item_id = mi.id
         WHERE ci.id = :cart_item_id
         AND c.user_id = :user_id
         AND ci.is_active = 1"
    );
    
    $verifyStmt->execute([
        ':cart_item_id' => $cartItemId,
        ':user_id' => $userId
    ]);
    
    $cartItem = $verifyStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cartItem) {
        ResponseHandler::error('Cart item not found', 404);
    }
    
    // Soft delete the cart item
    $deleteStmt = $conn->prepare(
        "UPDATE cart_items 
         SET is_active = 0, updated_at = NOW()
         WHERE id = :cart_item_id"
    );
    
    $deleteStmt->execute([':cart_item_id' => $cartItemId]);
    
    // Update cart timestamp
    $cartUpdateStmt = $conn->prepare(
        "UPDATE carts c
         JOIN cart_items ci ON c.id = ci.cart_id
         SET c.updated_at = NOW()
         WHERE ci.id = :cart_item_id"
    );
    $cartUpdateStmt->execute([':cart_item_id' => $cartItemId]);
    
    // Log activity
    logUserActivity($conn, $userId, 'cart_item_removed', "Removed item from cart", [
        'cart_item_id' => $cartItemId,
        'item_id' => $cartItem['item_id'],
        'item_name' => $cartItem['item_name']
    ]);
    
    ResponseHandler::success([
        'cart_item_id' => $cartItemId,
        'item_name' => $cartItem['item_name']
    ], 'Item removed from cart');
}

/*********************************
 * HELPER FUNCTIONS
 *********************************/

function formatCartItemData($item, $baseUrl) {
    $itemImage = '';
    if (!empty($item['item_image'])) {
        if (strpos($item['item_image'], 'http') === 0) {
            $itemImage = $item['item_image'];
        } else {
            $itemImage = rtrim($baseUrl, '/') . '/uploads/menu_items/' . $item['item_image'];
        }
    }
    
    $merchantImage = '';
    if (!empty($item['merchant_image'])) {
        if (strpos($item['merchant_image'], 'http') === 0) {
            $merchantImage = $item['merchant_image'];
        } else {
            $merchantImage = rtrim($baseUrl, '/') . '/uploads/merchants/' . $item['merchant_image'];
        }
    }
    
    $price = floatval($item['price'] ?? 0);
    $quantity = intval($item['quantity'] ?? 1);
    $total = $price * $quantity;
    
    return [
        'id' => $item['id'],
        'item_id' => $item['item_id'],
        'name' => $item['item_name'] ?? '',
        'description' => $item['item_description'] ?? '',
        'price' => $price,
        'quantity' => $quantity,
        'total' => $total,
        'special_instructions' => $item['special_instructions'] ?? '',
        'image_url' => $itemImage,
        'category' => $item['item_category'] ?? '',
        'merchant_id' => $item['merchant_id'],
        'merchant_name' => $item['merchant_name'] ?? '',
        'merchant_category' => $item['merchant_category'] ?? '',
        'merchant_image' => $merchantImage,
        'is_dropx' => boolval($item['is_dropx'] ?? false),
        'created_at' => $item['created_at'] ?? '',
        'updated_at' => $item['updated_at'] ?? ''
    ];
}

function formatAddressData($address) {
    return [
        'id' => $address['id'],
        'label' => $address['label'] ?? '',
        'full_name' => $address['full_name'] ?? '',
        'phone' => $address['phone'] ?? '',
        'address_line1' => $address['address_line1'] ?? '',
        'address_line2' => $address['address_line2'] ?? '',
        'city' => $address['city'] ?? '',
        'neighborhood' => $address['neighborhood'] ?? '',
        'landmark' => $address['landmark'] ?? '',
        'latitude' => floatval($address['latitude'] ?? 0),
        'longitude' => floatval($address['longitude'] ?? 0),
        'is_default' => boolval($address['is_default'] ?? false)
    ];
}

function logUserActivity($conn, $userId, $activityType, $description, $metadata = []) {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO user_activities 
                (user_id, activity_type, description, ip_address, user_agent, metadata, created_at)
             VALUES (:user_id, :activity_type, :description, :ip_address, :user_agent, :metadata, NOW())"
        );
        
        $stmt->execute([
            ':user_id' => $userId,
            ':activity_type' => $activityType,
            ':description' => $description,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ':metadata' => json_encode($metadata)
        ]);
    } catch (Exception $e) {
        // Log error but don't fail the main request
        error_log('Failed to log user activity: ' . $e->getMessage());
    }
}

?>