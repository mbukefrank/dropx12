<?php
/*********************************
 * CORS Configuration
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Session-Token, X-Device-ID, X-Platform, X-App-Version");
header("Content-Type: application/json; charset=UTF-8");

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * SESSION CONFIG - MATCHING FLUTTER
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
 * PARSE THE REQUEST URI
 *********************************/
$requestUri = $_SERVER['REQUEST_URI'];
$scriptName = $_SERVER['SCRIPT_NAME'];

// Extract the path part after the script name
$path = substr($requestUri, strlen($scriptName));
if ($path === false) {
    $path = '';
}

// Remove query string if present
$path = strtok($path, '?');

// Split into parts
$pathParts = explode('/', trim($path, '/'));

// The first part (if any) is the cart ID
$cartId = !empty($pathParts[0]) ? $pathParts[0] : null;

// Log for debugging
error_log("=== CART ROUTING DEBUG ===");
error_log("Request URI: " . $requestUri);
error_log("Script Name: " . $scriptName);
error_log("Path: " . $path);
error_log("Path Parts: " . json_encode($pathParts));
error_log("Extracted Cart ID: " . ($cartId ?? 'null'));
error_log("=====================");

/*********************************
 * AUTHENTICATION HELPER
 *********************************/
function checkAuthentication($conn) {
    error_log("=== AUTH CHECK START ===");
    error_log("Session ID: " . session_id());
    error_log("Session User ID: " . ($_SESSION['user_id'] ?? 'NOT SET'));
    
    if (!empty($_SESSION['user_id'])) {
        error_log("Auth Method: PHP Session");
        return $_SESSION['user_id'];
    }
    
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (strpos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
        error_log("Auth Method: Bearer Token - $token");
        
        $stmt = $conn->prepare(
            "SELECT id FROM users WHERE api_token = :token AND api_token_expiry > NOW()"
        );
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            error_log("Bearer Token Valid - User ID: " . $user['id']);
            return $user['id'];
        }
    }
    
    $sessionToken = $headers['X-Session-Token'] ?? '';
    if ($sessionToken) {
        error_log("Auth Method: X-Session-Token - $sessionToken");
        
        $stmt = $conn->prepare(
            "SELECT user_id FROM user_sessions 
             WHERE session_token = :token AND expires_at > NOW()"
        );
        $stmt->execute([':token' => $sessionToken]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $_SESSION['user_id'] = $result['user_id'];
            error_log("Session Token Valid - User ID: " . $result['user_id']);
            return $result['user_id'];
        }
        
        if (session_id() !== $sessionToken) {
            session_id($sessionToken);
            session_start();
            
            if (!empty($_SESSION['user_id'])) {
                error_log("Session Restored from Token - User ID: " . $_SESSION['user_id']);
                return $_SESSION['user_id'];
            }
        }
    }
    
    if (!empty($_COOKIE['PHPSESSID'])) {
        error_log("Auth Method: PHPSESSID Cookie");
        
        if (session_id() !== $_COOKIE['PHPSESSID']) {
            session_id($_COOKIE['PHPSESSID']);
            session_start();
            
            if (!empty($_SESSION['user_id'])) {
                error_log("Session Restored from Cookie - User ID: " . $_SESSION['user_id']);
                return $_SESSION['user_id'];
            }
        }
    }
    
    error_log("=== AUTH CHECK FAILED ===");
    return false;
}

/*********************************
 * BASE URL CONFIGURATION
 *********************************/
$baseUrl = "https://dropx12-production.up.railway.app";

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Parse query parameters
    $queryParams = [];
    parse_str(parse_url($requestUri, PHP_URL_QUERY) ?? '', $queryParams);

    if ($method === 'GET') {
        handleGetRequest($cartId, $queryParams);
    } elseif ($method === 'POST') {
        handlePostRequest();
    } elseif ($method === 'PUT') {
        handlePutRequest();
    } elseif ($method === 'DELETE') {
        handleDeleteRequest();
    } else {
        ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    error_log("Cart Router Error: " . $e->getMessage());
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET REQUESTS
 *********************************/
function handleGetRequest($cartId = null, $queryParams = []) {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }

    $userId = checkAuthentication($conn);
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    $endpoint = $queryParams['endpoint'] ?? '';
    
    // If we have a cart ID in the path, get cart details
    if ($cartId && is_numeric($cartId)) {
        getCartDetails($conn, $cartId, $baseUrl, $userId);
    } elseif ($endpoint === 'summary') {
        getCartSummary($conn, $userId, $baseUrl);
    } elseif ($endpoint === 'count') {
        getCartItemCount($conn, $userId);
    } elseif ($endpoint === 'items') {
        getCartItems($conn, $baseUrl, $userId);
    } elseif ($endpoint === 'validate') {
        validateCart($conn, $userId, $baseUrl);
    } elseif ($endpoint === 'eligible-promotions') {
        getEligiblePromotions($conn, $userId);
    } else {
        getCurrentCart($conn, $baseUrl, $userId);
    }
}

/*********************************
 * POST REQUESTS
 *********************************/
function handlePostRequest() {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input && !empty($_POST)) {
        $input = $_POST;
    }
    
    if (!$input) {
        ResponseHandler::error('No input data provided', 400);
    }
    
    $action = $input['action'] ?? '';

    $userId = checkAuthentication($conn);
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    switch ($action) {
        case 'add_item':
            addItemToCart($conn, $input, $userId, $baseUrl);
            break;
        case 'add_quick_order':
            addQuickOrderToCart($conn, $input, $userId, $baseUrl);
            break;
        case 'add_multiple':
            addMultipleItemsToCart($conn, $input, $userId, $baseUrl);
            break;
        case 'apply_promo':
            applyPromotionToCart($conn, $input, $userId);
            break;
        case 'remove_promo':
            removePromotionFromCart($conn, $input, $userId);
            break;
        case 'clear_cart':
            clearCart($conn, $input, $userId, $baseUrl);
            break;
        case 'prepare_checkout':
            prepareCheckout($conn, $input, $userId, $baseUrl);
            break;
        case 'merge_cart':
            mergeCart($conn, $input, $userId, $baseUrl);
            break;
        case 'estimate_delivery':
            estimateDelivery($conn, $input, $userId);
            break;
        case 'check_availability':
            checkItemAvailability($conn, $input, $userId);
            break;
        case 'move_to_wishlist':
            moveCartItemToWishlist($conn, $input, $userId);
            break;
        case 'save_for_later':
            saveItemForLater($conn, $input, $userId, $baseUrl);
            break;
        default:
            ResponseHandler::error('Invalid action: ' . $action, 400);
    }
}

/*********************************
 * PUT REQUESTS
 *********************************/
function handlePutRequest() {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        ResponseHandler::error('No input data provided', 400);
    }
    
    $action = $input['action'] ?? '';

    $userId = checkAuthentication($conn);
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    switch ($action) {
        case 'update_item':
            updateCartItem($conn, $input, $userId, $baseUrl);
            break;
        case 'update_quantity':
            updateCartItemQuantity($conn, $input, $userId, $baseUrl);
            break;
        case 'update_instructions':
            updateItemInstructions($conn, $input, $userId, $baseUrl);
            break;
        default:
            ResponseHandler::error('Invalid action: ' . $action, 400);
    }
}

/*********************************
 * DELETE REQUESTS
 *********************************/
function handleDeleteRequest() {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_GET;
    }
    
    $action = $input['action'] ?? '';

    $userId = checkAuthentication($conn);
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    switch ($action) {
        case 'remove_item':
            $cartItemId = $input['cart_item_id'] ?? null;
            if ($cartItemId) {
                removeCartItem($conn, $userId, $cartItemId, $baseUrl);
            } else {
                ResponseHandler::error('Cart item ID is required', 400);
            }
            break;
        case 'remove_multiple':
            $cartItemIds = $input['cart_item_ids'] ?? [];
            if (!empty($cartItemIds)) {
                removeMultipleCartItems($conn, $userId, $cartItemIds, $baseUrl);
            } else {
                ResponseHandler::error('Cart item IDs are required', 400);
            }
            break;
        case 'remove_by_merchant':
            $merchantId = $input['merchant_id'] ?? null;
            if ($merchantId) {
                removeItemsByMerchant($conn, $userId, $merchantId, $baseUrl);
            } else {
                ResponseHandler::error('Merchant ID is required', 400);
            }
            break;
        default:
            ResponseHandler::error('Invalid action: ' . $action, 400);
    }
}

/*********************************
 * FORMAT IMAGE URL WITH FALLBACK
 *********************************/
function formatImageUrl($imageUrl, $baseUrl, $type = 'menu_items') {
    if (empty($imageUrl)) {
        return rtrim($baseUrl, '/') . '/uploads/default.jpg';
    }
    
    if (strpos($imageUrl, 'http') === 0) {
        return $imageUrl;
    }
    
    // Remove any leading slashes
    $imageUrl = ltrim($imageUrl, '/');
    
    // Construct the full URL
    return rtrim($baseUrl, '/') . '/uploads/' . $type . '/' . $imageUrl;
}

/*********************************
 * GET OR CREATE USER CART
 *********************************/
function getOrCreateUserCart($conn, $userId) {
    // Check for existing active cart
    $stmt = $conn->prepare(
        "SELECT id, user_id, status, applied_promotion_id, applied_discount, 
                created_at, updated_at
         FROM carts 
         WHERE user_id = :user_id AND status = 'active'
         ORDER BY created_at DESC LIMIT 1"
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
    
    try {
        $insertStmt->execute([':user_id' => $userId]);
        $cartId = $conn->lastInsertId();
        
        return [
            'id' => $cartId,
            'user_id' => $userId,
            'status' => 'active',
            'applied_promotion_id' => null,
            'applied_discount' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    } catch (Exception $e) {
        error_log("Failed to create cart: " . $e->getMessage());
        return false;
    }
}

/*********************************
 * GET CURRENT CART
 *********************************/
function getCurrentCart($conn, $baseUrl, $userId) {
    $cart = getOrCreateUserCart($conn, $userId);
    
    if (!$cart) {
        ResponseHandler::error('Failed to retrieve cart', 500);
    }
    
    $cartItems = getCartItemsByUserId($conn, $userId, $baseUrl);
    $totals = calculateCartTotals($conn, $cart['id'], $userId);
    $eligiblePromotions = getEligiblePromotionsForCart($conn, $userId, $totals['subtotal']);
    $appliedPromotion = getAppliedPromotion($conn, $cart['id']);
    
    // Group items by merchant for better display
    $groupedItems = groupCartItemsByMerchant($cartItems);
    
    // Get unavailable items
    $unavailableItems = getUnavailableCartItems($conn, $cartItems);
    
    ResponseHandler::success([
        'cart' => [
            'id' => $cart['id'],
            'user_id' => $cart['user_id'],
            'status' => $cart['status'],
            'created_at' => $cart['created_at'],
            'updated_at' => $cart['updated_at']
        ],
        'items' => $cartItems,
        'grouped_by_merchant' => $groupedItems,
        'summary' => [
            'subtotal' => $totals['subtotal'],
            'discount_amount' => $totals['discount_amount'],
            'adjusted_subtotal' => $totals['adjusted_subtotal'],
            'delivery_fee' => $totals['delivery_fee'],
            'service_fee' => $totals['service_fee'],
            'tax_amount' => $totals['tax_amount'],
            'total_amount' => $totals['total_amount'],
            'item_count' => $totals['item_count'],
            'total_quantity' => $totals['total_quantity'],
            'merchant_count' => count($groupedItems)
        ],
        'promotions' => [
            'eligible' => $eligiblePromotions,
            'applied' => $appliedPromotion
        ],
        'warnings' => [
            'has_unavailable_items' => !empty($unavailableItems),
            'unavailable_items' => $unavailableItems
        ],
        'is_eligible_for_checkout' => $totals['item_count'] > 0 && empty($unavailableItems)
    ]);
}

/*********************************
 * GET CART DETAILS BY ID
 *********************************/
function getCartDetails($conn, $cartId, $baseUrl, $userId) {
    // Verify cart belongs to user
    $cartStmt = $conn->prepare(
        "SELECT id, user_id, status, applied_promotion_id, applied_discount,
                created_at, updated_at
         FROM carts
         WHERE id = :cart_id AND user_id = :user_id"
    );
    
    $cartStmt->execute([':cart_id' => $cartId, ':user_id' => $userId]);
    $cart = $cartStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cart) {
        ResponseHandler::error('Cart not found', 404);
    }
    
    $cartItems = getCartItemsByCartId($conn, $cartId, $baseUrl);
    $totals = calculateCartTotals($conn, $cartId, $userId);
    $appliedPromotion = getAppliedPromotion($conn, $cartId);
    
    // Group items by merchant
    $groupedItems = groupCartItemsByMerchant($cartItems);
    
    ResponseHandler::success([
        'cart' => $cart,
        'items' => $cartItems,
        'grouped_by_merchant' => $groupedItems,
        'summary' => [
            'subtotal' => $totals['subtotal'],
            'discount_amount' => $totals['discount_amount'],
            'adjusted_subtotal' => $totals['adjusted_subtotal'],
            'delivery_fee' => $totals['delivery_fee'],
            'service_fee' => $totals['service_fee'],
            'tax_amount' => $totals['tax_amount'],
            'total_amount' => $totals['total_amount'],
            'item_count' => $totals['item_count'],
            'total_quantity' => $totals['total_quantity'],
            'merchant_count' => count($groupedItems)
        ],
        'applied_promotion' => $appliedPromotion
    ]);
}

/*********************************
 * GET CART SUMMARY
 *********************************/
function getCartSummary($conn, $userId, $baseUrl) {
    $cart = getOrCreateUserCart($conn, $userId);
    
    if (!$cart) {
        ResponseHandler::success([
            'item_count' => 0,
            'total_quantity' => 0,
            'subtotal' => 0,
            'total_amount' => 0,
            'has_items' => false,
            'has_cart' => false
        ]);
    }
    
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(ci.id) as item_count,
            SUM(ci.quantity) as total_quantity,
            SUM(
                CASE 
                    WHEN mi.id IS NOT NULL THEN mi.price * ci.quantity
                    WHEN qo.id IS NOT NULL THEN COALESCE(qom.custom_price, qo.price) * ci.quantity
                    ELSE 0
                END
            ) as subtotal
         FROM cart_items ci
         LEFT JOIN menu_items mi ON ci.menu_item_id = mi.id
         LEFT JOIN quick_orders qo ON ci.quick_order_id = qo.id
         LEFT JOIN quick_order_merchants qom ON qo.id = qom.quick_order_id AND qom.merchant_id = ci.merchant_id
         WHERE ci.user_id = :user_id AND ci.is_active = 1"
    );
    
    $stmt->execute([':user_id' => $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $subtotal = floatval($result['subtotal'] ?? 0);
    $deliveryFee = calculateDeliveryFee($conn, $userId, [], $subtotal);
    $serviceFee = max(1.50, $subtotal * 0.02);
    $taxableAmount = $subtotal + $deliveryFee + $serviceFee;
    $totalAmount = $taxableAmount + ($taxableAmount * 0.10);
    
    ResponseHandler::success([
        'item_count' => intval($result['item_count'] ?? 0),
        'total_quantity' => intval($result['total_quantity'] ?? 0),
        'subtotal' => round($subtotal, 2),
        'delivery_fee' => round($deliveryFee, 2),
        'service_fee' => round($serviceFee, 2),
        'total_amount' => round($totalAmount, 2),
        'has_items' => ($result['item_count'] ?? 0) > 0,
        'has_cart' => true,
        'cart_id' => $cart['id']
    ]);
}

/*********************************
 * GET CART ITEM COUNT
 *********************************/
function getCartItemCount($conn, $userId) {
    $cart = getOrCreateUserCart($conn, $userId);
    
    if (!$cart) {
        ResponseHandler::success([
            'item_count' => 0,
            'total_quantity' => 0,
            'has_cart' => false
        ]);
    }
    
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(ci.id) as item_count, 
            SUM(ci.quantity) as total_quantity
         FROM cart_items ci
         WHERE ci.user_id = :user_id AND ci.is_active = 1"
    );
    
    $stmt->execute([':user_id' => $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    ResponseHandler::success([
        'item_count' => intval($result['item_count'] ?? 0),
        'total_quantity' => intval($result['total_quantity'] ?? 0),
        'has_cart' => true,
        'cart_id' => $cart['id']
    ]);
}

/*********************************
 * GET CART ITEMS
 *********************************/
function getCartItems($conn, $baseUrl, $userId) {
    $cartItems = getCartItemsByUserId($conn, $userId, $baseUrl);
    
    ResponseHandler::success([
        'items' => $cartItems,
        'item_count' => count($cartItems),
        'total_quantity' => array_sum(array_column($cartItems, 'quantity'))
    ]);
}

/*********************************
 * GET CART ITEMS BY USER ID
 *********************************/
function getCartItemsByUserId($conn, $userId, $baseUrl) {
    $stmt = $conn->prepare(
        "SELECT 
            ci.id,
            ci.user_id,
            ci.menu_item_id as item_id,
            ci.quick_order_id,
            ci.quick_order_item_id,
            ci.merchant_id,
            ci.quantity,
            ci.special_instructions,
            ci.selected_options,
            ci.variant_id,
            ci.variant_data,
            ci.variant_name,
            ci.is_active,
            ci.created_at,
            ci.updated_at,
            
            -- Menu item fields
            mi.name as item_name,
            mi.description as item_description,
            mi.price,
            mi.image_url as item_image,
            mi.category as item_category,
            mi.item_type,
            mi.unit_type,
            mi.unit_value,
            mi.max_quantity as item_max_quantity,
            mi.stock_quantity as item_stock_quantity,
            mi.has_variants as item_has_variants,
            mi.nutritional_info as item_nutritional_info,
            
            -- Quick order fields
            qo.id as quick_order_id_ref,
            qo.title as quick_order_title,
            qo.description as quick_order_description,
            qo.price as quick_order_price,
            qo.image_url as quick_order_image,
            qo.category as quick_order_category,
            qo.item_type as quick_order_item_type,
            qo.preparation_time as quick_order_preparation_time,
            qo.has_variants as quick_order_has_variants,
            qo.variant_type as quick_order_variant_type,
            qo.variants as quick_order_variants,
            qo.nutritional_info as quick_order_nutritional_info,
            
            -- Quick order merchant specific
            qom.custom_price,
            qom.custom_delivery_time,
            qom.priority,
            
            -- Merchant fields
            m.id as merchant_id_ref,
            m.name as merchant_name,
            m.category as merchant_category,
            m.image_url as merchant_image,
            m.logo_url as merchant_logo,
            m.rating as merchant_rating,
            m.is_open as merchant_is_open,
            m.delivery_fee as merchant_delivery_fee,
            m.min_order_amount as merchant_min_order,
            m.free_delivery_threshold,
            m.delivery_time as merchant_delivery_time,
            m.preparation_time as merchant_prep_time,
            m.business_type,
            m.cuisine_type,
            m.address as merchant_address,
            
            -- Determine source type
            CASE 
                WHEN qo.id IS NOT NULL THEN 'quick_order'
                ELSE 'menu_item'
            END as source_type
            
        FROM cart_items ci
        LEFT JOIN menu_items mi ON ci.menu_item_id = mi.id AND mi.is_available = 1
        LEFT JOIN quick_orders qo ON ci.quick_order_id = qo.id AND qo.is_available = 1
        LEFT JOIN merchants m ON (
            (mi.merchant_id = m.id) OR 
            (ci.merchant_id = m.id)
        )
        LEFT JOIN quick_order_merchants qom ON qo.id = qom.quick_order_id AND qom.merchant_id = m.id
        WHERE ci.user_id = :user_id
        AND ci.is_active = 1
        ORDER BY ci.created_at DESC"
    );
    
    $stmt->execute([':user_id' => $userId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return array_map(function($item) use ($baseUrl) {
        return formatCartItemData($item, $baseUrl);
    }, $items);
}

/*********************************
 * GET CART ITEMS BY CART ID
 *********************************/
function getCartItemsByCartId($conn, $cartId, $baseUrl) {
    $stmt = $conn->prepare(
        "SELECT 
            ci.*,
            mi.name as item_name,
            mi.description as item_description,
            mi.price,
            mi.image_url as item_image,
            mi.category as item_category,
            mi.unit_type,
            mi.unit_value,
            mi.has_variants as item_has_variants,
            qo.title as quick_order_title,
            qo.description as quick_order_description,
            qo.price as quick_order_price,
            qo.image_url as quick_order_image,
            m.name as merchant_name,
            m.image_url as merchant_image,
            m.rating as merchant_rating,
            CASE 
                WHEN qo.id IS NOT NULL THEN 'quick_order'
                ELSE 'menu_item'
            END as source_type
         FROM cart_items ci
         LEFT JOIN menu_items mi ON ci.menu_item_id = mi.id
         LEFT JOIN quick_orders qo ON ci.quick_order_id = qo.id
         LEFT JOIN merchants m ON mi.merchant_id = m.id OR ci.merchant_id = m.id
         WHERE ci.cart_id = :cart_id AND ci.is_active = 1
         ORDER BY ci.created_at DESC"
    );
    
    $stmt->execute([':cart_id' => $cartId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return array_map(function($item) use ($baseUrl) {
        return formatCartItemData($item, $baseUrl);
    }, $items);
}

/*********************************
 * GROUP CART ITEMS BY MERCHANT
 *********************************/
function groupCartItemsByMerchant($items) {
    $grouped = [];
    
    foreach ($items as $item) {
        $merchantId = $item['merchant_id'] ?? $item['merchant_id_ref'] ?? 0;
        
        if ($merchantId == 0) continue;
        
        if (!isset($grouped[$merchantId])) {
            $grouped[$merchantId] = [
                'merchant_id' => $merchantId,
                'merchant_name' => $item['merchant_name'] ?? 'Unknown Merchant',
                'merchant_image' => $item['merchant_image'] ?? null,
                'merchant_logo' => $item['merchant_logo'] ?? null,
                'merchant_rating' => floatval($item['merchant_rating'] ?? 0),
                'merchant_is_open' => boolval($item['merchant_is_open'] ?? true),
                'delivery_fee' => floatval($item['merchant_delivery_fee'] ?? 0),
                'min_order' => floatval($item['merchant_min_order'] ?? 0),
                'free_delivery_threshold' => floatval($item['free_delivery_threshold'] ?? 0),
                'business_type' => $item['business_type'] ?? 'restaurant',
                'cuisine_types' => json_decode($item['cuisine_type'] ?? '[]', true),
                'preparation_time' => $item['merchant_prep_time'] ?? '15-20 min',
                'items' => [],
                'subtotal' => 0,
                'item_count' => 0,
                'total_quantity' => 0
            ];
        }
        
        $grouped[$merchantId]['items'][] = $item;
        $grouped[$merchantId]['subtotal'] += $item['total'] ?? 0;
        $grouped[$merchantId]['item_count'] += 1;
        $grouped[$merchantId]['total_quantity'] += $item['quantity'] ?? 0;
    }
    
    return array_values($grouped);
}

/*********************************
 * FORMAT CART ITEM DATA
 *********************************/
function formatCartItemData($item, $baseUrl) {
    // Determine price based on source
    $price = 0;
    if ($item['source_type'] === 'quick_order') {
        $price = floatval($item['custom_price'] ?? $item['quick_order_price'] ?? 0);
    } else {
        $price = floatval($item['price'] ?? 0);
    }
    
    $quantity = intval($item['quantity'] ?? 1);
    $total = $price * $quantity;
    
    // Format images
    $itemImage = '';
    if ($item['source_type'] === 'quick_order') {
        $itemImage = formatImageUrl($item['quick_order_image'] ?? '', $baseUrl, 'quick-orders');
    } else {
        $itemImage = formatImageUrl($item['item_image'] ?? '', $baseUrl, 'menu_items');
    }
    
    $merchantImage = formatImageUrl($item['merchant_image'] ?? '', $baseUrl, 'merchants');
    $merchantLogo = formatImageUrl($item['merchant_logo'] ?? '', $baseUrl, 'merchants/logos');
    
    // Parse selected options
    $selectedOptions = null;
    if (!empty($item['selected_options'])) {
        $selectedOptions = is_string($item['selected_options']) 
            ? json_decode($item['selected_options'], true) 
            : $item['selected_options'];
    }
    
    // Parse variant data
    $variantData = null;
    if (!empty($item['variant_data'])) {
        $variantData = is_string($item['variant_data']) 
            ? json_decode($item['variant_data'], true) 
            : $item['variant_data'];
    }
    
    // Parse nutritional info
    $nutritionalInfo = null;
    $nutritionData = $item['item_nutritional_info'] ?? $item['quick_order_nutritional_info'] ?? null;
    if ($nutritionData) {
        $nutritionalInfo = is_string($nutritionData) ? json_decode($nutritionData, true) : $nutritionData;
    }
    
    return [
        'id' => intval($item['id']),
        'cart_id' => intval($item['cart_id'] ?? 0),
        'user_id' => intval($item['user_id']),
        
        // Item identification
        'item_id' => $item['item_id'] ? intval($item['item_id']) : null,
        'quick_order_id' => $item['quick_order_id'] ? intval($item['quick_order_id']) : null,
        'quick_order_item_id' => $item['quick_order_item_id'] ? intval($item['quick_order_item_id']) : null,
        'source_type' => $item['source_type'] ?? 'menu_item',
        
        // Basic info
        'name' => $item['item_name'] ?? $item['quick_order_title'] ?? 'Unknown Item',
        'description' => $item['item_description'] ?? $item['quick_order_description'] ?? '',
        'price' => round($price, 2),
        'quantity' => $quantity,
        'total' => round($total, 2),
        'formatted_price' => 'MK ' . number_format($price, 2),
        'formatted_total' => 'MK ' . number_format($total, 2),
        
        // Images
        'image_url' => $itemImage,
        
        // Category & Type
        'category' => $item['item_category'] ?? $item['quick_order_category'] ?? 'Uncategorized',
        'item_type' => $item['item_type'] ?? $item['quick_order_item_type'] ?? 'food',
        
        // Unit info
        'unit_type' => $item['unit_type'] ?? 'piece',
        'unit_value' => floatval($item['unit_value'] ?? 1),
        
        // Availability
        'max_quantity' => intval($item['item_max_quantity'] ?? 99),
        'stock_quantity' => $item['item_stock_quantity'] ?? null,
        'in_stock' => ($item['item_stock_quantity'] ?? 1) > 0,
        
        // Variants
        'has_variants' => boolval($item['item_has_variants'] ?? $item['quick_order_has_variants'] ?? false),
        'variant_type' => $item['variant_type'] ?? $item['quick_order_variant_type'] ?? null,
        'variant_id' => $item['variant_id'] ? intval($item['variant_id']) : null,
        'variant_data' => $variantData,
        'variant_name' => $item['variant_name'] ?? '',
        'selected_options' => $selectedOptions,
        
        // Nutritional info
        'nutritional_info' => $nutritionalInfo,
        
        // Preparation
        'preparation_time' => $item['preparation_time'] ?? $item['quick_order_preparation_time'] ?? $item['merchant_prep_time'] ?? '15-20 min',
        
        // Special instructions
        'special_instructions' => $item['special_instructions'] ?? '',
        
        // Merchant info
        'merchant_id' => intval($item['merchant_id'] ?? $item['merchant_id_ref'] ?? 0),
        'merchant_name' => $item['merchant_name'] ?? '',
        'merchant_category' => $item['merchant_category'] ?? '',
        'merchant_image' => $merchantImage,
        'merchant_logo' => $merchantLogo,
        'merchant_rating' => floatval($item['merchant_rating'] ?? 0),
        'merchant_is_open' => boolval($item['merchant_is_open'] ?? true),
        'business_type' => $item['business_type'] ?? 'restaurant',
        'cuisine_types' => json_decode($item['cuisine_type'] ?? '[]', true),
        'merchant_address' => $item['merchant_address'] ?? '',
        
        // Delivery info
        'delivery_fee' => floatval($item['merchant_delivery_fee'] ?? 0),
        'formatted_delivery_fee' => 'MK ' . number_format(floatval($item['merchant_delivery_fee'] ?? 0), 2),
        'min_order' => floatval($item['merchant_min_order'] ?? 0),
        'free_delivery_threshold' => floatval($item['free_delivery_threshold'] ?? 0),
        'delivery_time' => $item['merchant_delivery_time'] ?? '30-45 min',
        
        // Quick order specific
        'priority' => intval($item['priority'] ?? 0),
        'custom_price' => isset($item['custom_price']) ? floatval($item['custom_price']) : null,
        'custom_delivery_time' => $item['custom_delivery_time'] ?? null,
        
        // Flags
        'is_popular' => boolval($item['is_popular'] ?? false),
        'is_available' => boolval($item['is_available'] ?? true),
        
        // Timestamps
        'created_at' => $item['created_at'] ?? '',
        'updated_at' => $item['updated_at'] ?? ''
    ];
}

/*********************************
 * CALCULATE CART TOTALS
 *********************************/
function calculateCartTotals($conn, $cartId, $userId) {
    $stmt = $conn->prepare(
        "SELECT 
            SUM(
                CASE 
                    WHEN mi.id IS NOT NULL THEN mi.price * ci.quantity
                    WHEN qo.id IS NOT NULL THEN COALESCE(qom.custom_price, qo.price) * ci.quantity
                    ELSE 0
                END
            ) as subtotal,
            COUNT(ci.id) as item_count,
            SUM(ci.quantity) as total_quantity,
            GROUP_CONCAT(DISTINCT 
                CASE 
                    WHEN mi.merchant_id IS NOT NULL THEN mi.merchant_id
                    WHEN ci.merchant_id IS NOT NULL THEN ci.merchant_id
                    ELSE NULL
                END
            ) as merchant_ids
        FROM cart_items ci
        LEFT JOIN menu_items mi ON ci.menu_item_id = mi.id
        LEFT JOIN quick_orders qo ON ci.quick_order_id = qo.id
        LEFT JOIN quick_order_merchants qom ON qo.id = qom.quick_order_id AND qom.merchant_id = ci.merchant_id
        WHERE ci.user_id = :user_id
        AND ci.is_active = 1"
    );
    
    $stmt->execute([':user_id' => $userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $subtotal = floatval($result['subtotal'] ?? 0);
    $itemCount = intval($result['item_count'] ?? 0);
    $totalQuantity = intval($result['total_quantity'] ?? 0);
    $merchantIds = array_filter(explode(',', $result['merchant_ids'] ?? ''));
    
    // Get applied promotion discount
    $promoStmt = $conn->prepare("SELECT applied_discount FROM carts WHERE id = :cart_id");
    $promoStmt->execute([':cart_id' => $cartId]);
    $promoResult = $promoStmt->fetch(PDO::FETCH_ASSOC);
    $promotionDiscount = floatval($promoResult['applied_discount'] ?? 0);
    
    $adjustedSubtotal = max(0, $subtotal - $promotionDiscount);
    
    // Calculate delivery fees based on merchants
    $deliveryFee = calculateDeliveryFee($conn, $userId, $merchantIds, $adjustedSubtotal);
    
    // Service fee (2% of adjusted subtotal, minimum MK 1.50)
    $serviceFee = max(1.50, $adjustedSubtotal * 0.02);
    
    // Tax calculation (10% VAT)
    $taxableAmount = $adjustedSubtotal + $deliveryFee + $serviceFee;
    $taxAmount = $taxableAmount * 0.10;
    $totalAmount = $taxableAmount + $taxAmount;
    
    return [
        'subtotal' => round($subtotal, 2),
        'discount_amount' => round($promotionDiscount, 2),
        'adjusted_subtotal' => round($adjustedSubtotal, 2),
        'delivery_fee' => round($deliveryFee, 2),
        'service_fee' => round($serviceFee, 2),
        'tax_amount' => round($taxAmount, 2),
        'total_amount' => round($totalAmount, 2),
        'item_count' => $itemCount,
        'total_quantity' => $totalQuantity,
        'merchant_count' => count($merchantIds)
    ];
}

/*********************************
 * CALCULATE DELIVERY FEE
 *********************************/
function calculateDeliveryFee($conn, $userId, $merchantIds, $subtotal) {
    if (empty($merchantIds)) {
        return 2.99; // Default fee
    }
    
    // Get user's default address for distance calculation
    $addressStmt = $conn->prepare(
        "SELECT latitude, longitude FROM addresses 
         WHERE user_id = :user_id AND is_default = 1
         LIMIT 1"
    );
    
    $addressStmt->execute([':user_id' => $userId]);
    $address = $addressStmt->fetch(PDO::FETCH_ASSOC);
    
    $totalDeliveryFee = 0;
    $uniqueMerchants = array_unique($merchantIds);
    
    foreach ($uniqueMerchants as $merchantId) {
        if (empty($merchantId)) continue;
        
        $merchantStmt = $conn->prepare(
            "SELECT delivery_fee, min_order_amount, free_delivery_threshold,
                    latitude, longitude
             FROM merchants WHERE id = :id"
        );
        $merchantStmt->execute([':id' => $merchantId]);
        $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($merchant) {
            $merchantFee = floatval($merchant['delivery_fee'] ?? 2.99);
            
            // Check if free delivery threshold is met
            if ($merchant['free_delivery_threshold'] && $subtotal >= $merchant['free_delivery_threshold']) {
                $merchantFee = 0;
            }
            
            // Adjust fee based on distance if address available
            if ($address && $merchant['latitude'] && $merchant['longitude']) {
                $distance = calculateDistance(
                    $address['latitude'],
                    $address['longitude'],
                    $merchant['latitude'],
                    $merchant['longitude']
                );
                
                // Add distance-based surcharge if beyond 5km
                if ($distance > 5) {
                    $merchantFee += ($distance - 5) * 0.5; // MK 0.50 per km beyond 5km
                }
            }
            
            $totalDeliveryFee += $merchantFee;
        } else {
            $totalDeliveryFee += 2.99;
        }
    }
    
    return $totalDeliveryFee;
}

/*********************************
 * CALCULATE DISTANCE (Haversine Formula)
 *********************************/
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371; // km
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    
    return round($earthRadius * $c, 1);
}

/*********************************
 * GET UNAVAILABLE CART ITEMS
 *********************************/
function getUnavailableCartItems($conn, $cartItems) {
    $unavailableItems = [];
    
    foreach ($cartItems as $item) {
        if ($item['source_type'] === 'menu_item' && $item['item_id']) {
            $checkStmt = $conn->prepare(
                "SELECT is_available, stock_quantity 
                 FROM menu_items 
                 WHERE id = :item_id"
            );
            $checkStmt->execute([':item_id' => $item['item_id']]);
            $status = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$status || !$status['is_available'] || 
                ($status['stock_quantity'] !== null && $status['stock_quantity'] < $item['quantity'])) {
                $unavailableItems[] = $item;
            }
        } elseif ($item['source_type'] === 'quick_order' && $item['quick_order_id']) {
            $checkStmt = $conn->prepare(
                "SELECT is_available, stock_quantity 
                 FROM quick_orders 
                 WHERE id = :quick_order_id"
            );
            $checkStmt->execute([':quick_order_id' => $item['quick_order_id']]);
            $status = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$status || !$status['is_available'] ||
                ($status['stock_quantity'] !== null && $status['stock_quantity'] < $item['quantity'])) {
                $unavailableItems[] = $item;
            }
        }
    }
    
    return $unavailableItems;
}

/*********************************
 * GET ELIGIBLE PROMOTIONS FOR CART
 *********************************/
function getEligiblePromotionsForCart($conn, $userId, $subtotal) {
    $currentDate = date('Y-m-d H:i:s');
    
    $stmt = $conn->prepare(
        "SELECT p.* 
         FROM promotions p
         WHERE p.is_active = 1
         AND p.valid_from <= :current_date
         AND p.valid_until >= :current_date
         AND (p.usage_limit IS NULL OR p.times_used < p.usage_limit)
         AND (p.min_order_amount IS NULL OR p.min_order_amount <= :subtotal)
         ORDER BY p.discount_value DESC"
    );
    
    $stmt->execute([':current_date' => $currentDate, ':subtotal' => $subtotal]);
    $promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $eligiblePromotions = [];
    foreach ($promotions as $promotion) {
        // Check per-user limit
        if ($promotion['per_user_limit']) {
            $usageStmt = $conn->prepare(
                "SELECT COUNT(*) as usage_count
                 FROM promotion_usage
                 WHERE promotion_id = :promotion_id AND user_id = :user_id"
            );
            $usageStmt->execute([
                ':promotion_id' => $promotion['id'],
                ':user_id' => $userId
            ]);
            $usage = $usageStmt->fetch(PDO::FETCH_ASSOC);
            
            if (intval($usage['usage_count'] ?? 0) >= $promotion['per_user_limit']) {
                continue;
            }
        }
        
        $discountAmount = calculateDiscountAmount($promotion, $subtotal);
        
        $eligiblePromotions[] = [
            'id' => $promotion['id'],
            'code' => $promotion['code'],
            'name' => $promotion['name'],
            'description' => $promotion['description'],
            'discount_type' => $promotion['discount_type'],
            'discount_value' => floatval($promotion['discount_value']),
            'discount_amount' => round($discountAmount, 2),
            'min_order_amount' => floatval($promotion['min_order_amount'] ?? 0),
            'max_discount_amount' => floatval($promotion['max_discount_amount'] ?? 0),
            'valid_until' => $promotion['valid_until']
        ];
    }
    
    return $eligiblePromotions;
}

/*********************************
 * GET ELIGIBLE PROMOTIONS (ENDPOINT)
 *********************************/
function getEligiblePromotions($conn, $userId) {
    $cart = getOrCreateUserCart($conn, $userId);
    $totals = calculateCartTotals($conn, $cart['id'], $userId);
    $eligiblePromotions = getEligiblePromotionsForCart($conn, $userId, $totals['subtotal']);
    
    ResponseHandler::success([
        'promotions' => $eligiblePromotions,
        'count' => count($eligiblePromotions)
    ]);
}

/*********************************
 * CALCULATE DISCOUNT AMOUNT
 *********************************/
function calculateDiscountAmount($promotion, $subtotal) {
    $discountAmount = 0;
    
    if ($promotion['discount_type'] === 'percentage') {
        $discountAmount = $subtotal * ($promotion['discount_value'] / 100);
        if ($promotion['max_discount_amount'] && $discountAmount > $promotion['max_discount_amount']) {
            $discountAmount = $promotion['max_discount_amount'];
        }
    } elseif ($promotion['discount_type'] === 'fixed') {
        $discountAmount = min($promotion['discount_value'], $subtotal);
    } elseif ($promotion['discount_type'] === 'free_delivery') {
        $discountAmount = 0; // Will be calculated when applying
    }
    
    return $discountAmount;
}

/*********************************
 * GET APPLIED PROMOTION
 *********************************/
function getAppliedPromotion($conn, $cartId) {
    $stmt = $conn->prepare(
        "SELECT c.applied_promotion_id, c.applied_discount,
                p.code, p.name, p.description, p.discount_type, p.discount_value,
                p.max_discount_amount
         FROM carts c
         LEFT JOIN promotions p ON c.applied_promotion_id = p.id
         WHERE c.id = :cart_id"
    );
    
    $stmt->execute([':cart_id' => $cartId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result || !$result['applied_promotion_id']) {
        return null;
    }
    
    return [
        'promotion_id' => $result['applied_promotion_id'],
        'code' => $result['code'],
        'name' => $result['name'],
        'description' => $result['description'],
        'discount_type' => $result['discount_type'],
        'discount_value' => floatval($result['discount_value']),
        'applied_discount' => floatval($result['applied_discount']),
        'max_discount_amount' => floatval($result['max_discount_amount'] ?? 0)
    ];
}

/*********************************
 * ADD ITEM TO CART
 *********************************/
function addItemToCart($conn, $data, $userId, $baseUrl) {
    $menuItemId = $data['menu_item_id'] ?? null;
    $quantity = intval($data['quantity'] ?? 1);
    $specialInstructions = trim($data['special_instructions'] ?? '');
    $selectedOptions = $data['selected_options'] ?? null;
    
    if (!$menuItemId) {
        ResponseHandler::error('Menu item ID is required', 400);
    }
    
    if ($quantity < 1) {
        ResponseHandler::error('Quantity must be at least 1', 400);
    }
    
    // Get item details
    $itemStmt = $conn->prepare(
        "SELECT mi.*, m.id as merchant_id, m.name as merchant_name,
                m.is_open as merchant_is_open, m.delivery_fee,
                m.min_order_amount, m.free_delivery_threshold
         FROM menu_items mi
         LEFT JOIN merchants m ON mi.merchant_id = m.id
         WHERE mi.id = :item_id
         AND mi.is_available = 1"
    );
    
    $itemStmt->execute([':item_id' => $menuItemId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        ResponseHandler::error('Item not available', 404);
    }
    
    if (!$item['merchant_is_open']) {
        ResponseHandler::error('Merchant is currently closed', 400);
    }
    
    if ($item['max_quantity'] && $quantity > $item['max_quantity']) {
        ResponseHandler::error("Maximum quantity allowed is {$item['max_quantity']}", 400);
    }
    
    if ($item['stock_quantity'] !== null && $quantity > $item['stock_quantity']) {
        ResponseHandler::error("Only {$item['stock_quantity']} items available in stock", 400);
    }
    
    // Get or create cart
    $cart = getOrCreateUserCart($conn, $userId);
    
    // Check if item already exists
    $existingStmt = $conn->prepare(
        "SELECT id, quantity, special_instructions, selected_options
         FROM cart_items 
         WHERE user_id = :user_id 
         AND menu_item_id = :item_id
         AND is_active = 1"
    );
    
    $existingStmt->execute([':user_id' => $userId, ':item_id' => $menuItemId]);
    $existingItem = $existingStmt->fetch(PDO::FETCH_ASSOC);
    
    $conn->beginTransaction();
    
    try {
        if ($existingItem) {
            $newQuantity = $existingItem['quantity'] + $quantity;
            
            if ($item['max_quantity'] && $newQuantity > $item['max_quantity']) {
                throw new Exception("Cannot add more than {$item['max_quantity']} of this item");
            }
            
            if ($item['stock_quantity'] !== null && $newQuantity > $item['stock_quantity']) {
                throw new Exception("Only {$item['stock_quantity']} items available in stock");
            }
            
            $updateStmt = $conn->prepare(
                "UPDATE cart_items 
                 SET quantity = :quantity,
                     special_instructions = :instructions,
                     selected_options = :selected_options,
                     updated_at = NOW()
                 WHERE id = :id"
            );
            
            $updateStmt->execute([
                ':quantity' => $newQuantity,
                ':instructions' => $specialInstructions ?: $existingItem['special_instructions'],
                ':selected_options' => $selectedOptions ? json_encode($selectedOptions) : $existingItem['selected_options'],
                ':id' => $existingItem['id']
            ]);
            
            $cartItemId = $existingItem['id'];
            $message = 'Item quantity updated in cart';
        } else {
            $insertStmt = $conn->prepare(
                "INSERT INTO cart_items 
                    (user_id, menu_item_id, merchant_id, quantity, 
                     special_instructions, selected_options, is_active, 
                     created_at, updated_at)
                 VALUES 
                    (:user_id, :item_id, :merchant_id, :quantity,
                     :instructions, :selected_options, 1,
                     NOW(), NOW())"
            );
            
            $insertStmt->execute([
                ':user_id' => $userId,
                ':item_id' => $menuItemId,
                ':merchant_id' => $item['merchant_id'],
                ':quantity' => $quantity,
                ':instructions' => $specialInstructions,
                ':selected_options' => $selectedOptions ? json_encode($selectedOptions) : null
            ]);
            
            $cartItemId = $conn->lastInsertId();
            $message = 'Item added to cart';
        }
        
        // Update cart timestamp
        $updateCartStmt = $conn->prepare("UPDATE carts SET updated_at = NOW() WHERE id = :cart_id");
        $updateCartStmt->execute([':cart_id' => $cart['id']]);
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error($e->getMessage(), 400);
    }
    
    // Get updated cart data
    $cartItems = getCartItemsByUserId($conn, $userId, $baseUrl);
    $totals = calculateCartTotals($conn, $cart['id'], $userId);
    
    ResponseHandler::success([
        'cart_item_id' => $cartItemId,
        'cart_id' => $cart['id'],
        'item' => [
            'id' => $menuItemId,
            'name' => $item['name'],
            'quantity' => $quantity,
            'price' => floatval($item['price']),
            'total' => floatval($item['price'] * $quantity)
        ],
        'cart_summary' => [
            'item_count' => $totals['item_count'],
            'total_quantity' => $totals['total_quantity'],
            'subtotal' => $totals['subtotal'],
            'total_amount' => $totals['total_amount']
        ]
    ], $message);
}

/*********************************
 * ADD QUICK ORDER TO CART
 *********************************/
function addQuickOrderToCart($conn, $data, $userId, $baseUrl) {
    $quickOrderId = $data['quick_order_id'] ?? null;
    $merchantId = $data['merchant_id'] ?? null;
    $quantity = intval($data['quantity'] ?? 1);
    $selectedOptions = $data['selected_options'] ?? [];
    $variantId = $data['variant_id'] ?? null;
    
    if (!$quickOrderId || !$merchantId) {
        ResponseHandler::error('Quick order ID and merchant ID are required', 400);
    }
    
    if ($quantity < 1) {
        ResponseHandler::error('Quantity must be at least 1', 400);
    }
    
    // Get quick order details
    $qoStmt = $conn->prepare(
        "SELECT qo.*, qom.custom_price, qom.custom_delivery_time,
                m.id as merchant_id, m.name as merchant_name,
                m.is_open as merchant_is_open, m.delivery_fee,
                m.min_order_amount, m.free_delivery_threshold
         FROM quick_orders qo
         INNER JOIN quick_order_merchants qom ON qo.id = qom.quick_order_id
         LEFT JOIN merchants m ON qom.merchant_id = m.id
         WHERE qo.id = :quick_order_id
         AND qom.merchant_id = :merchant_id
         AND qom.is_active = 1
         AND qo.is_available = 1"
    );
    
    $qoStmt->execute([
        ':quick_order_id' => $quickOrderId,
        ':merchant_id' => $merchantId
    ]);
    
    $quickOrder = $qoStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quickOrder) {
        ResponseHandler::error('Quick order not available for this merchant', 404);
    }
    
    if (!$quickOrder['merchant_is_open']) {
        ResponseHandler::error('Merchant is currently closed', 400);
    }
    
    if ($quickOrder['max_quantity'] && $quantity > $quickOrder['max_quantity']) {
        ResponseHandler::error("Maximum quantity allowed is {$quickOrder['max_quantity']}", 400);
    }
    
    // Calculate final price
    $price = $quickOrder['custom_price'] ?? $quickOrder['price'];
    
    // Handle variant if specified
    $variantData = null;
    $variantName = '';
    if ($variantId) {
        $variantStmt = $conn->prepare(
            "SELECT variants_json FROM quick_order_items 
             WHERE quick_order_id = :quick_order_id"
        );
        $variantStmt->execute([':quick_order_id' => $quickOrderId]);
        
        while ($row = $variantStmt->fetch(PDO::FETCH_ASSOC)) {
            $variants = json_decode($row['variants_json'] ?? '[]', true);
            foreach ($variants as $variant) {
                if ($variant['id'] == $variantId) {
                    $variantData = $variant;
                    $variantName = ' (' . ($variant['name'] ?? '') . ')';
                    $price = $variant['price'] ?? $price;
                    break 2;
                }
            }
        }
    }
    
    // Get or create cart
    $cart = getOrCreateUserCart($conn, $userId);
    
    // Check if already in cart
    $existingStmt = $conn->prepare(
        "SELECT id, quantity FROM cart_items 
         WHERE user_id = :user_id 
         AND quick_order_id = :quick_order_id
         AND merchant_id = :merchant_id
         AND (variant_id = :variant_id OR (:variant_id IS NULL AND variant_id IS NULL))
         AND is_active = 1"
    );
    
    $existingStmt->execute([
        ':user_id' => $userId,
        ':quick_order_id' => $quickOrderId,
        ':merchant_id' => $merchantId,
        ':variant_id' => $variantId
    ]);
    
    $existingItem = $existingStmt->fetch(PDO::FETCH_ASSOC);
    
    $conn->beginTransaction();
    
    try {
        if ($existingItem) {
            $newQuantity = $existingItem['quantity'] + $quantity;
            
            if ($quickOrder['max_quantity'] && $newQuantity > $quickOrder['max_quantity']) {
                throw new Exception("Cannot add more than {$quickOrder['max_quantity']} of this item");
            }
            
            $updateStmt = $conn->prepare(
                "UPDATE cart_items 
                 SET quantity = :quantity,
                     selected_options = :selected_options,
                     updated_at = NOW()
                 WHERE id = :id"
            );
            
            $updateStmt->execute([
                ':quantity' => $newQuantity,
                ':selected_options' => json_encode($selectedOptions),
                ':id' => $existingItem['id']
            ]);
            
            $cartItemId = $existingItem['id'];
            $message = 'Quick order quantity updated';
        } else {
            $insertStmt = $conn->prepare(
                "INSERT INTO cart_items 
                    (user_id, quick_order_id, merchant_id, quantity,
                     selected_options, variant_id, variant_data, variant_name,
                     is_active, created_at, updated_at)
                 VALUES 
                    (:user_id, :quick_order_id, :merchant_id, :quantity,
                     :selected_options, :variant_id, :variant_data, :variant_name,
                     1, NOW(), NOW())"
            );
            
            $insertStmt->execute([
                ':user_id' => $userId,
                ':quick_order_id' => $quickOrderId,
                ':merchant_id' => $merchantId,
                ':quantity' => $quantity,
                ':selected_options' => json_encode($selectedOptions),
                ':variant_id' => $variantId,
                ':variant_data' => $variantData ? json_encode($variantData) : null,
                ':variant_name' => $variantName
            ]);
            
            $cartItemId = $conn->lastInsertId();
            $message = 'Quick order added to cart';
        }
        
        // Update cart timestamp
        $updateCartStmt = $conn->prepare("UPDATE carts SET updated_at = NOW() WHERE id = :cart_id");
        $updateCartStmt->execute([':cart_id' => $cart['id']]);
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error($e->getMessage(), 400);
    }
    
    // Get updated cart data
    $cartItems = getCartItemsByUserId($conn, $userId, $baseUrl);
    $totals = calculateCartTotals($conn, $cart['id'], $userId);
    
    ResponseHandler::success([
        'cart_item_id' => $cartItemId,
        'cart_id' => $cart['id'],
        'quick_order' => [
            'id' => $quickOrderId,
            'title' => $quickOrder['title'],
            'quantity' => $quantity,
            'price' => floatval($price),
            'total' => floatval($price * $quantity)
        ],
        'cart_summary' => [
            'item_count' => $totals['item_count'],
            'total_quantity' => $totals['total_quantity'],
            'subtotal' => $totals['subtotal'],
            'total_amount' => $totals['total_amount']
        ]
    ], $message);
}

/*********************************
 * ADD MULTIPLE ITEMS TO CART
 *********************************/
function addMultipleItemsToCart($conn, $data, $userId, $baseUrl) {
    $items = $data['items'] ?? [];
    
    if (empty($items)) {
        ResponseHandler::error('No items to add', 400);
    }
    
    $cart = getOrCreateUserCart($conn, $userId);
    $addedItems = [];
    $failedItems = [];
    
    $conn->beginTransaction();
    
    try {
        foreach ($items as $itemData) {
            $menuItemId = $itemData['menu_item_id'] ?? null;
            $quickOrderId = $itemData['quick_order_id'] ?? null;
            $quantity = intval($itemData['quantity'] ?? 1);
            
            if ($menuItemId) {
                // Check if menu item exists
                $checkStmt = $conn->prepare(
                    "SELECT id, name, price, max_quantity, stock_quantity 
                     FROM menu_items WHERE id = :item_id AND is_available = 1"
                );
                $checkStmt->execute([':item_id' => $menuItemId]);
                $item = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($item) {
                    // Check if already in cart
                    $existingStmt = $conn->prepare(
                        "SELECT id, quantity FROM cart_items 
                         WHERE user_id = :user_id AND menu_item_id = :item_id AND is_active = 1"
                    );
                    $existingStmt->execute([':user_id' => $userId, ':item_id' => $menuItemId]);
                    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        $newQuantity = $existing['quantity'] + $quantity;
                        $updateStmt = $conn->prepare(
                            "UPDATE cart_items SET quantity = :quantity, updated_at = NOW()
                             WHERE id = :id"
                        );
                        $updateStmt->execute([':quantity' => $newQuantity, ':id' => $existing['id']]);
                    } else {
                        $insertStmt = $conn->prepare(
                            "INSERT INTO cart_items (user_id, menu_item_id, quantity, is_active, created_at, updated_at)
                             VALUES (:user_id, :item_id, :quantity, 1, NOW(), NOW())"
                        );
                        $insertStmt->execute([
                            ':user_id' => $userId,
                            ':item_id' => $menuItemId,
                            ':quantity' => $quantity
                        ]);
                    }
                    
                    $addedItems[] = [
                        'type' => 'menu_item',
                        'id' => $menuItemId,
                        'name' => $item['name'],
                        'quantity' => $quantity
                    ];
                } else {
                    $failedItems[] = [
                        'type' => 'menu_item',
                        'id' => $menuItemId,
                        'reason' => 'Item not available'
                    ];
                }
            } elseif ($quickOrderId) {
                $merchantId = $itemData['merchant_id'] ?? null;
                if (!$merchantId) {
                    $failedItems[] = [
                        'type' => 'quick_order',
                        'id' => $quickOrderId,
                        'reason' => 'Merchant ID required'
                    ];
                    continue;
                }
                
                // Check if quick order exists
                $checkStmt = $conn->prepare(
                    "SELECT qo.id, qo.title, qo.price 
                     FROM quick_orders qo
                     INNER JOIN quick_order_merchants qom ON qo.id = qom.quick_order_id
                     WHERE qo.id = :quick_order_id 
                     AND qom.merchant_id = :merchant_id
                     AND qom.is_active = 1"
                );
                $checkStmt->execute([
                    ':quick_order_id' => $quickOrderId,
                    ':merchant_id' => $merchantId
                ]);
                $quickOrder = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($quickOrder) {
                    // Check if already in cart
                    $existingStmt = $conn->prepare(
                        "SELECT id, quantity FROM cart_items 
                         WHERE user_id = :user_id AND quick_order_id = :quick_order_id 
                         AND merchant_id = :merchant_id AND is_active = 1"
                    );
                    $existingStmt->execute([
                        ':user_id' => $userId,
                        ':quick_order_id' => $quickOrderId,
                        ':merchant_id' => $merchantId
                    ]);
                    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        $newQuantity = $existing['quantity'] + $quantity;
                        $updateStmt = $conn->prepare(
                            "UPDATE cart_items SET quantity = :quantity, updated_at = NOW()
                             WHERE id = :id"
                        );
                        $updateStmt->execute([':quantity' => $newQuantity, ':id' => $existing['id']]);
                    } else {
                        $insertStmt = $conn->prepare(
                            "INSERT INTO cart_items 
                                (user_id, quick_order_id, merchant_id, quantity, is_active, created_at, updated_at)
                             VALUES 
                                (:user_id, :quick_order_id, :merchant_id, :quantity, 1, NOW(), NOW())"
                        );
                        $insertStmt->execute([
                            ':user_id' => $userId,
                            ':quick_order_id' => $quickOrderId,
                            ':merchant_id' => $merchantId,
                            ':quantity' => $quantity
                        ]);
                    }
                    
                    $addedItems[] = [
                        'type' => 'quick_order',
                        'id' => $quickOrderId,
                        'merchant_id' => $merchantId,
                        'name' => $quickOrder['title'],
                        'quantity' => $quantity
                    ];
                } else {
                    $failedItems[] = [
                        'type' => 'quick_order',
                        'id' => $quickOrderId,
                        'reason' => 'Quick order not available'
                    ];
                }
            }
        }
        
        // Update cart timestamp
        $updateCartStmt = $conn->prepare("UPDATE carts SET updated_at = NOW() WHERE id = :cart_id");
        $updateCartStmt->execute([':cart_id' => $cart['id']]);
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to add items: ' . $e->getMessage(), 500);
    }
    
    // Get updated cart data
    $cartItems = getCartItemsByUserId($conn, $userId, $baseUrl);
    $totals = calculateCartTotals($conn, $cart['id'], $userId);
    
    ResponseHandler::success([
        'cart_id' => $cart['id'],
        'added_count' => count($addedItems),
        'failed_count' => count($failedItems),
        'added_items' => $addedItems,
        'failed_items' => $failedItems,
        'cart_summary' => [
            'item_count' => $totals['item_count'],
            'total_quantity' => $totals['total_quantity'],
            'subtotal' => $totals['subtotal'],
            'total_amount' => $totals['total_amount']
        ]
    ], count($addedItems) . ' items added to cart');
}

/*********************************
 * UPDATE CART ITEM
 *********************************/
function updateCartItem($conn, $data, $userId, $baseUrl) {
    $cartItemId = $data['cart_item_id'] ?? null;
    $quantity = isset($data['quantity']) ? intval($data['quantity']) : null;
    $specialInstructions = isset($data['special_instructions']) ? trim($data['special_instructions']) : null;
    $selectedOptions = isset($data['selected_options']) ? $data['selected_options'] : null;
    
    if (!$cartItemId) {
        ResponseHandler::error('Cart item ID is required', 400);
    }
    
    if ($quantity === null && $specialInstructions === null && $selectedOptions === null) {
        ResponseHandler::error('No update data provided', 400);
    }
    
    if ($quantity === 0) {
        removeCartItem($conn, $userId, $cartItemId, $baseUrl);
        return;
    }
    
    // Verify item belongs to user and get details
    $verifyStmt = $conn->prepare(
        "SELECT ci.id, ci.quantity, ci.menu_item_id, ci.quick_order_id,
                mi.max_quantity as menu_max_qty, mi.stock_quantity as menu_stock,
                qo.max_quantity as qo_max_qty, qo.stock_quantity as qo_stock
         FROM cart_items ci
         LEFT JOIN menu_items mi ON ci.menu_item_id = mi.id
         LEFT JOIN quick_orders qo ON ci.quick_order_id = qo.id
         WHERE ci.id = :cart_item_id
         AND ci.user_id = :user_id
         AND ci.is_active = 1"
    );
    
    $verifyStmt->execute([':cart_item_id' => $cartItemId, ':user_id' => $userId]);
    $cartItem = $verifyStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cartItem) {
        ResponseHandler::error('Cart item not found', 404);
    }
    
    // Validate quantity if being updated
    if ($quantity !== null) {
        if ($quantity < 1) {
            ResponseHandler::error('Quantity must be at least 1', 400);
        }
        
        // Check max quantity
        $maxQty = $cartItem['menu_max_qty'] ?? $cartItem['qo_max_qty'] ?? 99;
        if ($quantity > $maxQty) {
            ResponseHandler::error("Maximum quantity allowed is $maxQty", 400);
        }
        
        // Check stock
        $stock = $cartItem['menu_stock'] ?? $cartItem['qo_stock'] ?? null;
        if ($stock !== null && $quantity > $stock) {
            ResponseHandler::error("Only $stock items available in stock", 400);
        }
    }
    
    // Build update query
    $updates = [];
    $params = [':id' => $cartItemId];
    
    if ($quantity !== null) {
        $updates[] = 'quantity = :quantity';
        $params[':quantity'] = $quantity;
    }
    
    if ($specialInstructions !== null) {
        $updates[] = 'special_instructions = :instructions';
        $params[':instructions'] = $specialInstructions;
    }
    
    if ($selectedOptions !== null) {
        $updates[] = 'selected_options = :selected_options';
        $params[':selected_options'] = json_encode($selectedOptions);
    }
    
    if (empty($updates)) {
        ResponseHandler::error('No valid updates provided', 400);
    }
    
    $updates[] = 'updated_at = NOW()';
    $updateSql = "UPDATE cart_items SET " . implode(', ', $updates) . " WHERE id = :id";
    
    $conn->beginTransaction();
    
    try {
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute($params);
        
        // Get cart id
        $cartStmt = $conn->prepare("SELECT cart_id FROM cart_items WHERE id = :id");
        $cartStmt->execute([':id' => $cartItemId]);
        $cartId = $cartStmt->fetch(PDO::FETCH_ASSOC)['cart_id'];
        
        if ($cartId) {
            $cartUpdateStmt = $conn->prepare("UPDATE carts SET updated_at = NOW() WHERE id = :cart_id");
            $cartUpdateStmt->execute([':cart_id' => $cartId]);
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to update item: ' . $e->getMessage(), 500);
    }
    
    // Get updated cart data
    $cartItems = getCartItemsByUserId($conn, $userId, $baseUrl);
    $totals = calculateCartTotals($conn, $cartId, $userId);
    
    ResponseHandler::success([
        'cart_item_id' => $cartItemId,
        'cart_summary' => [
            'item_count' => $totals['item_count'],
            'total_quantity' => $totals['total_quantity'],
            'subtotal' => $totals['subtotal'],
            'total_amount' => $totals['total_amount']
        ]
    ], 'Cart item updated successfully');
}

/*********************************
 * UPDATE CART ITEM QUANTITY
 *********************************/
function updateCartItemQuantity($conn, $data, $userId, $baseUrl) {
    $cartItemId = $data['cart_item_id'] ?? null;
    $quantity = intval($data['quantity'] ?? 0);
    
    if (!$cartItemId) {
        ResponseHandler::error('Cart item ID is required', 400);
    }
    
    if ($quantity < 1) {
        removeCartItem($conn, $userId, $cartItemId, $baseUrl);
        return;
    }
    
    // Similar validation as updateCartItem but only for quantity
    $verifyStmt = $conn->prepare(
        "SELECT ci.id, mi.max_quantity as menu_max_qty, mi.stock_quantity as menu_stock,
                qo.max_quantity as qo_max_qty, qo.stock_quantity as qo_stock
         FROM cart_items ci
         LEFT JOIN menu_items mi ON ci.menu_item_id = mi.id
         LEFT JOIN quick_orders qo ON ci.quick_order_id = qo.id
         WHERE ci.id = :cart_item_id
         AND ci.user_id = :user_id
         AND ci.is_active = 1"
    );
    
    $verifyStmt->execute([':cart_item_id' => $cartItemId, ':user_id' => $userId]);
    $cartItem = $verifyStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cartItem) {
        ResponseHandler::error('Cart item not found', 404);
    }
    
    // Validate quantity
    $maxQty = $cartItem['menu_max_qty'] ?? $cartItem['qo_max_qty'] ?? 99;
    if ($quantity > $maxQty) {
        ResponseHandler::error("Maximum quantity allowed is $maxQty", 400);
    }
    
    $stock = $cartItem['menu_stock'] ?? $cartItem['qo_stock'] ?? null;
    if ($stock !== null && $quantity > $stock) {
        ResponseHandler::error("Only $stock items available in stock", 400);
    }
    
    $conn->beginTransaction();
    
    try {
        $updateStmt = $conn->prepare(
            "UPDATE cart_items 
             SET quantity = :quantity, updated_at = NOW()
             WHERE id = :id"
        );
        $updateStmt->execute([':quantity' => $quantity, ':id' => $cartItemId]);
        
        // Get cart id
        $cartStmt = $conn->prepare("SELECT cart_id FROM cart_items WHERE id = :id");
        $cartStmt->execute([':id' => $cartItemId]);
        $cartId = $cartStmt->fetch(PDO::FETCH_ASSOC)['cart_id'];
        
        if ($cartId) {
            $cartUpdateStmt = $conn->prepare("UPDATE carts SET updated_at = NOW() WHERE id = :cart_id");
            $cartUpdateStmt->execute([':cart_id' => $cartId]);
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to update quantity: ' . $e->getMessage(), 500);
    }
    
    // Get updated cart data
    $cartItems = getCartItemsByUserId($conn, $userId, $baseUrl);
    $totals = calculateCartTotals($conn, $cartId, $userId);
    
    ResponseHandler::success([
        'cart_item_id' => $cartItemId,
        'quantity' => $quantity,
        'cart_summary' => [
            'item_count' => $totals['item_count'],
            'total_quantity' => $totals['total_quantity'],
            'subtotal' => $totals['subtotal'],
            'total_amount' => $totals['total_amount']
        ]
    ], 'Quantity updated successfully');
}

/*********************************
 * UPDATE ITEM INSTRUCTIONS
 *********************************/
function updateItemInstructions($conn, $data, $userId, $baseUrl) {
    $cartItemId = $data['cart_item_id'] ?? null;
    $specialInstructions = trim($data['special_instructions'] ?? '');
    
    if (!$cartItemId) {
        ResponseHandler::error('Cart item ID is required', 400);
    }
    
    // Verify item belongs to user
    $verifyStmt = $conn->prepare(
        "SELECT id FROM cart_items 
         WHERE id = :cart_item_id AND user_id = :user_id AND is_active = 1"
    );
    
    $verifyStmt->execute([':cart_item_id' => $cartItemId, ':user_id' => $userId]);
    
    if (!$verifyStmt->fetch()) {
        ResponseHandler::error('Cart item not found', 404);
    }
    
    $updateStmt = $conn->prepare(
        "UPDATE cart_items 
         SET special_instructions = :instructions, updated_at = NOW()
         WHERE id = :id"
    );
    
    $updateStmt->execute([
        ':instructions' => $specialInstructions,
        ':id' => $cartItemId
    ]);
    
    ResponseHandler::success([], 'Instructions updated successfully');
}

/*********************************
 * REMOVE CART ITEM
 *********************************/
function removeCartItem($conn, $userId, $cartItemId, $baseUrl) {
    // Verify item belongs to user
    $verifyStmt = $conn->prepare(
        "SELECT ci.id, ci.cart_id, ci.menu_item_id, ci.quick_order_id,
                mi.name as item_name, qo.title as quick_order_title
         FROM cart_items ci
         LEFT JOIN menu_items mi ON ci.menu_item_id = mi.id
         LEFT JOIN quick_orders qo ON ci.quick_order_id = qo.id
         WHERE ci.id = :cart_item_id
         AND ci.user_id = :user_id
         AND ci.is_active = 1"
    );
    
    $verifyStmt->execute([':cart_item_id' => $cartItemId, ':user_id' => $userId]);
    $cartItem = $verifyStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cartItem) {
        ResponseHandler::error('Cart item not found', 404);
    }
    
    $itemName = $cartItem['item_name'] ?? $cartItem['quick_order_title'] ?? 'Item';
    
    $conn->beginTransaction();
    
    try {
        $deleteStmt = $conn->prepare(
            "UPDATE cart_items 
             SET is_active = 0, updated_at = NOW()
             WHERE id = :cart_item_id"
        );
        
        $deleteStmt->execute([':cart_item_id' => $cartItemId]);
        
        if ($cartItem['cart_id']) {
            $cartUpdateStmt = $conn->prepare("UPDATE carts SET updated_at = NOW() WHERE id = :cart_id");
            $cartUpdateStmt->execute([':cart_id' => $cartItem['cart_id']]);
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to remove item: ' . $e->getMessage(), 500);
    }
    
    // Get updated cart data
    $cartItems = getCartItemsByUserId($conn, $userId, $baseUrl);
    $totals = calculateCartTotals($conn, $cartItem['cart_id'], $userId);
    
    ResponseHandler::success([
        'cart_item_id' => $cartItemId,
        'item_name' => $itemName,
        'cart_summary' => [
            'item_count' => $totals['item_count'],
            'total_quantity' => $totals['total_quantity'],
            'subtotal' => $totals['subtotal'],
            'total_amount' => $totals['total_amount']
        ]
    ], "$itemName removed from cart");
}

/*********************************
 * REMOVE MULTIPLE CART ITEMS
 *********************************/
function removeMultipleCartItems($conn, $userId, $cartItemIds, $baseUrl) {
    if (empty($cartItemIds)) {
        ResponseHandler::error('No items to remove', 400);
    }
    
    $placeholders = implode(',', array_fill(0, count($cartItemIds), '?'));
    
    // Verify items belong to user
    $verifyStmt = $conn->prepare(
        "SELECT COUNT(*) as count
         FROM cart_items
         WHERE id IN ($placeholders)
         AND user_id = ?
         AND is_active = 1"
    );
    
    $params = array_merge($cartItemIds, [$userId]);
    $verifyStmt->execute($params);
    $count = $verifyStmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($count != count($cartItemIds)) {
        ResponseHandler::error('One or more items not found', 404);
    }
    
    $conn->beginTransaction();
    
    try {
        $deleteStmt = $conn->prepare(
            "UPDATE cart_items 
             SET is_active = 0, updated_at = NOW()
             WHERE id IN ($placeholders)
             AND user_id = ?"
        );
        
        $deleteStmt->execute($params);
        $removedCount = $deleteStmt->rowCount();
        
        // Get cart id (assuming all items belong to same cart)
        $cartStmt = $conn->prepare(
            "SELECT DISTINCT cart_id FROM cart_items WHERE id IN ($placeholders)"
        );
        $cartStmt->execute($cartItemIds);
        $cartId = $cartStmt->fetch(PDO::FETCH_ASSOC)['cart_id'];
        
        if ($cartId) {
            $cartUpdateStmt = $conn->prepare("UPDATE carts SET updated_at = NOW() WHERE id = :cart_id");
            $cartUpdateStmt->execute([':cart_id' => $cartId]);
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to remove items: ' . $e->getMessage(), 500);
    }
    
    // Get updated cart data
    $cartItems = getCartItemsByUserId($conn, $userId, $baseUrl);
    $totals = calculateCartTotals($conn, $cartId, $userId);
    
    ResponseHandler::success([
        'removed_count' => $removedCount,
        'cart_summary' => [
            'item_count' => $totals['item_count'],
            'total_quantity' => $totals['total_quantity'],
            'subtotal' => $totals['subtotal'],
            'total_amount' => $totals['total_amount']
        ]
    ], "$removedCount items removed from cart");
}

/*********************************
 * REMOVE ITEMS BY MERCHANT
 *********************************/
function removeItemsByMerchant($conn, $userId, $merchantId, $baseUrl) {
    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
    }
    
    $conn->beginTransaction();
    
    try {
        $deleteStmt = $conn->prepare(
            "UPDATE cart_items 
             SET is_active = 0, updated_at = NOW()
             WHERE user_id = :user_id 
             AND merchant_id = :merchant_id
             AND is_active = 1"
        );
        
        $deleteStmt->execute([
            ':user_id' => $userId,
            ':merchant_id' => $merchantId
        ]);
        
        $removedCount = $deleteStmt->rowCount();
        
        // Get cart
        $cart = getOrCreateUserCart($conn, $userId);
        $cartUpdateStmt = $conn->prepare("UPDATE carts SET updated_at = NOW() WHERE id = :cart_id");
        $cartUpdateStmt->execute([':cart_id' => $cart['id']]);
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to remove items: ' . $e->getMessage(), 500);
    }
    
    // Get updated cart data
    $cartItems = getCartItemsByUserId($conn, $userId, $baseUrl);
    $totals = calculateCartTotals($conn, $cart['id'], $userId);
    
    ResponseHandler::success([
        'removed_count' => $removedCount,
        'merchant_id' => $merchantId,
        'cart_summary' => [
            'item_count' => $totals['item_count'],
            'total_quantity' => $totals['total_quantity'],
            'subtotal' => $totals['subtotal'],
            'total_amount' => $totals['total_amount']
        ]
    ], "$removedCount items removed from cart");
}

/*********************************
 * APPLY PROMOTION TO CART
 *********************************/
function applyPromotionToCart($conn, $data, $userId) {
    $promoCode = trim($data['promo_code'] ?? '');
    
    if (!$promoCode) {
        ResponseHandler::error('Promotion code is required', 400);
    }
    
    $cart = getOrCreateUserCart($conn, $userId);
    $totals = calculateCartTotals($conn, $cart['id'], $userId);
    
    $currentDate = date('Y-m-d H:i:s');
    
    $promoStmt = $conn->prepare(
        "SELECT p.* FROM promotions p
         WHERE p.code = :code 
         AND p.is_active = 1
         AND p.valid_from <= :current_date
         AND p.valid_until >= :current_date"
    );
    
    $promoStmt->execute([':code' => $promoCode, ':current_date' => $currentDate]);
    $promotion = $promoStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$promotion) {
        ResponseHandler::error('Invalid or expired promotion code', 404);
    }
    
    // Check usage limits
    if ($promotion['usage_limit']) {
        $globalStmt = $conn->prepare(
            "SELECT COUNT(*) as usage_count
             FROM promotion_usage
             WHERE promotion_id = :promotion_id"
        );
        $globalStmt->execute([':promotion_id' => $promotion['id']]);
        $globalUsage = $globalStmt->fetch(PDO::FETCH_ASSOC);
        
        if (intval($globalUsage['usage_count'] ?? 0) >= $promotion['usage_limit']) {
            ResponseHandler::error('This promotion has reached its usage limit', 400);
        }
    }
    
    if ($promotion['per_user_limit']) {
        $userStmt = $conn->prepare(
            "SELECT COUNT(*) as usage_count
             FROM promotion_usage
             WHERE promotion_id = :promotion_id AND user_id = :user_id"
        );
        $userStmt->execute([
            ':promotion_id' => $promotion['id'],
            ':user_id' => $userId
        ]);
        $userUsage = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if (intval($userUsage['usage_count'] ?? 0) >= $promotion['per_user_limit']) {
            ResponseHandler::error('You have reached the usage limit for this promotion', 400);
        }
    }
    
    if ($promotion['min_order_amount'] && $totals['subtotal'] < $promotion['min_order_amount']) {
        $minAmount = number_format($promotion['min_order_amount'], 2);
        ResponseHandler::error("Minimum order amount of MK $minAmount required for this promotion", 400);
    }
    
    // Calculate discount
    $discountAmount = calculateDiscountAmount($promotion, $totals['subtotal']);
    
    // For free delivery promotion
    if ($promotion['discount_type'] === 'free_delivery') {
        $discountAmount = $totals['delivery_fee'];
    }
    
    $discountAmount = round($discountAmount, 2);
    
    $conn->beginTransaction();
    
    try {
        // Update cart
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
        
        // Record usage
        $usageStmt = $conn->prepare(
            "INSERT INTO promotion_usage (promotion_id, user_id, used_at)
             VALUES (:promotion_id, :user_id, NOW())"
        );
        $usageStmt->execute([
            ':promotion_id' => $promotion['id'],
            ':user_id' => $userId
        ]);
        
        // Update promotion times used
        $updatePromoStmt = $conn->prepare(
            "UPDATE promotions SET times_used = times_used + 1 WHERE id = :id"
        );
        $updatePromoStmt->execute([':id' => $promotion['id']]);
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to apply promotion: ' . $e->getMessage(), 500);
    }
    
    $newTotals = calculateCartTotals($conn, $cart['id'], $userId);
    
    ResponseHandler::success([
        'promotion' => [
            'id' => $promotion['id'],
            'code' => $promotion['code'],
            'name' => $promotion['name'],
            'description' => $promotion['description'],
            'discount_type' => $promotion['discount_type'],
            'discount_value' => floatval($promotion['discount_value']),
            'discount_amount' => $discountAmount
        ],
        'cart_totals' => $newTotals,
        'savings' => $discountAmount
    ], 'Promotion applied successfully');
}

/*********************************
 * REMOVE PROMOTION FROM CART
 *********************************/
function removePromotionFromCart($conn, $data, $userId) {
    $cart = getOrCreateUserCart($conn, $userId);
    
    $updateStmt = $conn->prepare(
        "UPDATE carts 
         SET applied_promotion_id = NULL,
             applied_discount = NULL,
             updated_at = NOW()
         WHERE id = :cart_id"
    );
    
    $updateStmt->execute([':cart_id' => $cart['id']]);
    
    $newTotals = calculateCartTotals($conn, $cart['id'], $userId);
    
    ResponseHandler::success([
        'cart_totals' => $newTotals
    ], 'Promotion removed successfully');
}

/*********************************
 * CLEAR CART
 *********************************/
function clearCart($conn, $data, $userId, $baseUrl) {
    $cart = getOrCreateUserCart($conn, $userId);
    
    $conn->beginTransaction();
    
    try {
        $clearStmt = $conn->prepare(
            "UPDATE cart_items 
             SET is_active = 0, updated_at = NOW()
             WHERE user_id = :user_id AND is_active = 1"
        );
        
        $clearStmt->execute([':user_id' => $userId]);
        $itemsCleared = $clearStmt->rowCount();
        
        $updateCartStmt = $conn->prepare(
            "UPDATE carts 
             SET applied_promotion_id = NULL,
                 applied_discount = NULL,
                 updated_at = NOW()
             WHERE id = :cart_id"
        );
        $updateCartStmt->execute([':cart_id' => $cart['id']]);
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to clear cart: ' . $e->getMessage(), 500);
    }
    
    ResponseHandler::success([
        'items_cleared' => $itemsCleared,
        'cart_id' => $cart['id']
    ], 'Cart cleared successfully');
}

/*********************************
 * VALIDATE CART
 *********************************/
function validateCart($conn, $userId, $baseUrl) {
    $cart = getOrCreateUserCart($conn, $userId);
    $cartItems = getCartItemsByUserId($conn, $userId, $baseUrl);
    
    if (empty($cartItems)) {
        ResponseHandler::error('Cart is empty', 400);
    }
    
    $issues = [];
    $warnings = [];
    $unavailableItems = [];
    
    foreach ($cartItems as $item) {
        if ($item['source_type'] === 'menu_item' && $item['item_id']) {
            $checkStmt = $conn->prepare(
                "SELECT mi.is_available, mi.stock_quantity,
                        m.is_active, m.is_open
                 FROM menu_items mi
                 LEFT JOIN merchants m ON mi.merchant_id = m.id
                 WHERE mi.id = :item_id"
            );
            
            $checkStmt->execute([':item_id' => $item['item_id']]);
            $status = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$status || !$status['is_available']) {
                $issues[] = "{$item['name']} is no longer available";
                $unavailableItems[] = $item;
            } elseif ($status['stock_quantity'] !== null && $item['quantity'] > $status['stock_quantity']) {
                $issues[] = "{$item['name']} only has {$status['stock_quantity']} items available";
                $unavailableItems[] = $item;
            } elseif (!$status['is_active']) {
                $issues[] = "{$item['merchant_name']} is no longer active";
            } elseif (!$status['is_open']) {
                $warnings[] = "{$item['merchant_name']} is currently closed";
            }
        } elseif ($item['source_type'] === 'quick_order' && $item['quick_order_id']) {
            $checkStmt = $conn->prepare(
                "SELECT qo.is_available, qo.stock_quantity,
                        qom.is_active, m.is_open
                 FROM quick_orders qo
                 INNER JOIN quick_order_merchants qom ON qo.id = qom.quick_order_id
                 LEFT JOIN merchants m ON qom.merchant_id = m.id
                 WHERE qo.id = :quick_order_id
                 AND qom.merchant_id = :merchant_id"
            );
            
            $checkStmt->execute([
                ':quick_order_id' => $item['quick_order_id'],
                ':merchant_id' => $item['merchant_id']
            ]);
            $status = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$status || !$status['is_available'] || !$status['is_active']) {
                $issues[] = "{$item['name']} is no longer available";
                $unavailableItems[] = $item;
            } elseif ($status['stock_quantity'] !== null && $item['quantity'] > $status['stock_quantity']) {
                $issues[] = "{$item['name']} only has {$status['stock_quantity']} available";
                $unavailableItems[] = $item;
            } elseif (!$status['is_open']) {
                $warnings[] = "{$item['merchant_name']} is currently closed";
            }
        }
    }
    
    // Check minimum order per merchant
    $groupedItems = groupCartItemsByMerchant($cartItems);
    foreach ($groupedItems as $group) {
        if ($group['min_order'] > 0 && $group['subtotal'] < $group['min_order']) {
            $warnings[] = "{$group['merchant_name']} requires minimum order of MK " . 
                         number_format($group['min_order'], 0);
        }
    }
    
    $response = [
        'is_valid' => empty($issues),
        'has_warnings' => !empty($warnings),
        'issues' => $issues,
        'warnings' => $warnings,
        'item_count' => count($cartItems),
        'unavailable_items' => $unavailableItems
    ];
    
    if (empty($issues)) {
        ResponseHandler::success($response, 'Cart is valid');
    } else {
        ResponseHandler::error('Cart validation failed', 400, $response);
    }
}

/*********************************
 * PREPARE CHECKOUT
 *********************************/
function prepareCheckout($conn, $data, $userId, $baseUrl) {
    $deliveryAddressId = $data['delivery_address_id'] ?? null;
    $specialInstructions = trim($data['special_instructions'] ?? '');
    $tipAmount = floatval($data['tip_amount'] ?? 0);
    $paymentMethod = $data['payment_method'] ?? 'cash';
    $scheduledTime = $data['scheduled_time'] ?? null;
    
    $cart = getOrCreateUserCart($conn, $userId);
    $cartItems = getCartItemsByUserId($conn, $userId, $baseUrl);
    
    if (empty($cartItems)) {
        ResponseHandler::error('Cannot checkout empty cart', 400);
    }
    
    // Validate cart first
    $validation = validateCartForCheckout($conn, $cartItems);
    if (!$validation['is_valid']) {
        ResponseHandler::error('Cart validation failed', 400, ['issues' => $validation['issues']]);
    }
    
    // Get delivery address
    $address = getDeliveryAddress($conn, $userId, $deliveryAddressId);
    if (!$address) {
        ResponseHandler::error('Please select a delivery address', 400);
    }
    
    // Calculate totals
    $totals = calculateCartTotals($conn, $cart['id'], $userId);
    $finalTotal = $totals['total_amount'] + $tipAmount;
    
    // Group items by merchant for delivery estimates
    $groupedItems = groupCartItemsByMerchant($cartItems);
    $deliveryEstimates = calculateDeliveryEstimates($conn, $groupedItems, $scheduledTime, $address);
    
    // Check if user has sufficient balance for selected payment method
    if ($paymentMethod === 'wallet') {
        $balanceStmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = :user_id");
        $balanceStmt->execute([':user_id' => $userId]);
        $balance = $balanceStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$balance || $balance['wallet_balance'] < $finalTotal) {
            ResponseHandler::error('Insufficient wallet balance', 400);
        }
    }
    
    $checkoutData = [
        'checkout_id' => uniqid('chk_'),
        'cart_id' => $cart['id'],
        'user_id' => $userId,
        'address' => formatAddressData($address),
        'payment_method' => $paymentMethod,
        'tip_amount' => round($tipAmount, 2),
        'scheduled_time' => $scheduledTime,
        'special_instructions' => $specialInstructions,
        'summary' => [
            'items_subtotal' => $totals['subtotal'],
            'discount_amount' => $totals['discount_amount'],
            'adjusted_subtotal' => $totals['adjusted_subtotal'],
            'delivery_fee' => $totals['delivery_fee'],
            'service_fee' => $totals['service_fee'],
            'tax_amount' => $totals['tax_amount'],
            'tip_amount' => round($tipAmount, 2),
            'total_amount' => round($finalTotal, 2)
        ],
        'items' => $cartItems,
        'grouped_by_merchant' => $groupedItems,
        'delivery_estimates' => $deliveryEstimates,
        'item_count' => $totals['item_count'],
        'total_quantity' => $totals['total_quantity'],
        'merchant_count' => count($groupedItems),
        'promotion' => getAppliedPromotion($conn, $cart['id'])
    ];
    
    // Store checkout data in session
    $checkoutSessionKey = 'checkout_' . $cart['id'];
    $_SESSION[$checkoutSessionKey] = $checkoutData;
    
    ResponseHandler::success($checkoutData, 'Checkout prepared successfully');
}

/*********************************
 * VALIDATE CART FOR CHECKOUT
 *********************************/
function validateCartForCheckout($conn, $cartItems) {
    $issues = [];
    $isValid = true;
    
    foreach ($cartItems as $item) {
        if ($item['source_type'] === 'menu_item' && $item['item_id']) {
            $stmt = $conn->prepare(
                "SELECT mi.is_available, mi.stock_quantity,
                        m.is_active, m.is_open
                 FROM menu_items mi
                 LEFT JOIN merchants m ON mi.merchant_id = m.id
                 WHERE mi.id = :item_id"
            );
            $stmt->execute([':item_id' => $item['item_id']]);
            $status = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$status) {
                $issues[] = "Item {$item['name']} not found";
                $isValid = false;
            } else {
                if (!$status['is_available']) {
                    $issues[] = "{$item['name']} is no longer available";
                    $isValid = false;
                }
                if ($status['stock_quantity'] !== null && $item['quantity'] > $status['stock_quantity']) {
                    $issues[] = "{$item['name']} only has {$status['stock_quantity']} items available";
                    $isValid = false;
                }
                if (!$status['is_active']) {
                    $issues[] = "{$item['merchant_name']} is no longer active";
                    $isValid = false;
                }
                if (!$status['is_open']) {
                    $issues[] = "{$item['merchant_name']} is currently closed";
                    $isValid = false;
                }
            }
        } elseif ($item['source_type'] === 'quick_order' && $item['quick_order_id']) {
            $stmt = $conn->prepare(
                "SELECT qo.is_available, qo.stock_quantity,
                        qom.is_active, m.is_open
                 FROM quick_orders qo
                 INNER JOIN quick_order_merchants qom ON qo.id = qom.quick_order_id
                 LEFT JOIN merchants m ON qom.merchant_id = m.id
                 WHERE qo.id = :quick_order_id AND qom.merchant_id = :merchant_id"
            );
            $stmt->execute([
                ':quick_order_id' => $item['quick_order_id'],
                ':merchant_id' => $item['merchant_id']
            ]);
            $status = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$status) {
                $issues[] = "Quick order {$item['name']} not found";
                $isValid = false;
            } else {
                if (!$status['is_available'] || !$status['is_active']) {
                    $issues[] = "{$item['name']} is no longer available";
                    $isValid = false;
                }
                if ($status['stock_quantity'] !== null && $item['quantity'] > $status['stock_quantity']) {
                    $issues[] = "{$item['name']} only has {$status['stock_quantity']} available";
                    $isValid = false;
                }
                if (!$status['is_open']) {
                    $issues[] = "{$item['merchant_name']} is currently closed";
                    $isValid = false;
                }
            }
        }
    }
    
    return ['is_valid' => $isValid, 'issues' => $issues];
}

/*********************************
 * GET DELIVERY ADDRESS
 *********************************/
function getDeliveryAddress($conn, $userId, $addressId = null) {
    if ($addressId) {
        $stmt = $conn->prepare(
            "SELECT * FROM addresses 
             WHERE id = :address_id AND user_id = :user_id"
        );
        $stmt->execute([':address_id' => $addressId, ':user_id' => $userId]);
        $address = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($address) {
            return $address;
        }
    }
    
    $defaultStmt = $conn->prepare(
        "SELECT * FROM addresses 
         WHERE user_id = :user_id AND is_default = 1
         LIMIT 1"
    );
    $defaultStmt->execute([':user_id' => $userId]);
    
    return $defaultStmt->fetch(PDO::FETCH_ASSOC);
}

/*********************************
 * FORMAT ADDRESS DATA
 *********************************/
function formatAddressData($address) {
    return [
        'id' => $address['id'],
        'label' => $address['label'] ?? '',
        'full_name' => $address['full_name'] ?? '',
        'phone' => $address['phone'] ?? '',
        'address_line1' => $address['address_line1'] ?? '',
        'address_line2' => $address['address_line2'] ?? '',
        'city' => $address['city'] ?? '',
        'state' => $address['state'] ?? '',
        'postal_code' => $address['postal_code'] ?? '',
        'neighborhood' => $address['neighborhood'] ?? '',
        'landmark' => $address['landmark'] ?? '',
        'latitude' => floatval($address['latitude'] ?? 0),
        'longitude' => floatval($address['longitude'] ?? 0),
        'delivery_instructions' => $address['delivery_instructions'] ?? '',
        'is_default' => boolval($address['is_default'] ?? false),
        'formatted_address' => trim(
            ($address['address_line1'] ?? '') . ' ' . 
            ($address['address_line2'] ?? '') . ', ' . 
            ($address['city'] ?? '')
        )
    ];
}

/*********************************
 * CALCULATE DELIVERY ESTIMATES
 *********************************/
function calculateDeliveryEstimates($conn, $groupedItems, $scheduledTime = null, $address = null) {
    $estimates = [];
    $totalPrepTime = 0;
    $totalDeliveryTime = 0;
    
    foreach ($groupedItems as $group) {
        // Get merchant preparation time
        $merchantStmt = $conn->prepare(
            "SELECT preparation_time, latitude, longitude 
             FROM merchants WHERE id = :merchant_id"
        );
        $merchantStmt->execute([':merchant_id' => $group['merchant_id']]);
        $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);
        
        $merchantPrepTime = 15; // Default
        if ($merchant && $merchant['preparation_time']) {
            $merchantPrepTime = intval(preg_replace('/[^0-9]/', '', $merchant['preparation_time']));
        }
        
        // Base preparation time + additional time per item
        $prepTime = $merchantPrepTime + ($group['item_count'] * 2);
        $totalPrepTime = max($totalPrepTime, $prepTime);
        
        // Calculate delivery time based on distance
        $deliveryTime = 20; // Base delivery time
        if ($address && $merchant && $merchant['latitude'] && $merchant['longitude']) {
            $distance = calculateDistance(
                $address['latitude'],
                $address['longitude'],
                $merchant['latitude'],
                $merchant['longitude']
            );
            $deliveryTime = max(15, min(45, ceil($distance * 3))); // 3 mins per km
        }
        $totalDeliveryTime = max($totalDeliveryTime, $deliveryTime);
        
        $estimates[] = [
            'merchant_id' => $group['merchant_id'],
            'merchant_name' => $group['merchant_name'],
            'preparation_time' => $prepTime,
            'delivery_time' => $deliveryTime,
            'total_time' => $prepTime + $deliveryTime,
            'ready_by' => date('H:i', strtotime("+{$prepTime} minutes")),
            'delivered_by' => date('H:i', strtotime("+" . ($prepTime + $deliveryTime) . " minutes"))
        ];
    }
    
    $estimatedDelivery = date('H:i', strtotime("+" . ($totalPrepTime + $totalDeliveryTime) . " minutes"));
    
    // If scheduled time is set, adjust estimates
    if ($scheduledTime) {
        $scheduled = new DateTime($scheduledTime);
        $estimatedDelivery = $scheduled->format('H:i');
    }
    
    return [
        'per_merchant' => $estimates,
        'total_estimated_time' => $totalPrepTime + $totalDeliveryTime,
        'estimated_delivery' => $estimatedDelivery,
        'estimated_pickup' => date('H:i', strtotime("+{$totalPrepTime} minutes"))
    ];
}

/*********************************
 * ESTIMATE DELIVERY
 *********************************/
function estimateDelivery($conn, $data, $userId) {
    $merchantIds = $data['merchant_ids'] ?? [];
    $latitude = $data['latitude'] ?? null;
    $longitude = $data['longitude'] ?? null;
    
    if (empty($merchantIds)) {
        ResponseHandler::error('Merchant IDs are required', 400);
    }
    
    $estimates = [];
    $totalPrepTime = 0;
    
    foreach ($merchantIds as $merchantId) {
        $stmt = $conn->prepare(
            "SELECT name, preparation_time, latitude, longitude 
             FROM merchants WHERE id = :id"
        );
        $stmt->execute([':id' => $merchantId]);
        $merchant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($merchant) {
            $prepTime = 15;
            if ($merchant['preparation_time']) {
                $prepTime = intval(preg_replace('/[^0-9]/', '', $merchant['preparation_time']));
            }
            $totalPrepTime = max($totalPrepTime, $prepTime);
            
            // Calculate delivery time based on distance
            $deliveryTime = 20; // Base delivery time
            if ($latitude && $longitude && $merchant['latitude'] && $merchant['longitude']) {
                $distance = calculateDistance(
                    $merchant['latitude'], $merchant['longitude'],
                    $latitude, $longitude
                );
                $deliveryTime = max(15, min(45, ceil($distance * 3))); // 3 mins per km
            }
            
            $estimates[] = [
                'merchant_id' => $merchantId,
                'merchant_name' => $merchant['name'],
                'preparation_time' => $prepTime,
                'delivery_time' => $deliveryTime,
                'total_time' => $prepTime + $deliveryTime
            ];
        }
    }
    
    ResponseHandler::success([
        'estimates' => $estimates,
        'total_estimated_time' => $totalPrepTime + 20,
        'estimated_delivery' => date('H:i', strtotime("+" . ($totalPrepTime + 20) . " minutes"))
    ]);
}

/*********************************
 * CHECK ITEM AVAILABILITY
 *********************************/
function checkItemAvailability($conn, $data, $userId) {
    $items = $data['items'] ?? [];
    
    if (empty($items)) {
        ResponseHandler::error('Items to check are required', 400);
    }
    
    $availability = [];
    
    foreach ($items as $item) {
        $menuItemId = $item['menu_item_id'] ?? null;
        $quickOrderId = $item['quick_order_id'] ?? null;
        $quantity = intval($item['quantity'] ?? 1);
        
        if ($menuItemId) {
            $stmt = $conn->prepare(
                "SELECT mi.name, mi.is_available, mi.stock_quantity, mi.max_quantity,
                        m.is_open as merchant_open
                 FROM menu_items mi
                 LEFT JOIN merchants m ON mi.merchant_id = m.id
                 WHERE mi.id = :item_id"
            );
            $stmt->execute([':item_id' => $menuItemId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $isAvailable = $result['is_available'] && $result['merchant_open'];
                $stockIssue = $result['stock_quantity'] !== null && $quantity > $result['stock_quantity'];
                $maxIssue = $result['max_quantity'] && $quantity > $result['max_quantity'];
                
                $availability[] = [
                    'item_id' => $menuItemId,
                    'name' => $result['name'],
                    'type' => 'menu_item',
                    'is_available' => $isAvailable && !$stockIssue && !$maxIssue,
                    'available_quantity' => $result['stock_quantity'],
                    'max_quantity' => $result['max_quantity'],
                    'issues' => [
                        'not_available' => !$result['is_available'],
                        'merchant_closed' => !$result['merchant_open'],
                        'insufficient_stock' => $stockIssue,
                        'exceeds_max' => $maxIssue
                    ]
                ];
            }
        } elseif ($quickOrderId) {
            $stmt = $conn->prepare(
                "SELECT qo.title, qo.is_available, qo.stock_quantity, qo.max_quantity,
                        m.is_open as merchant_open
                 FROM quick_orders qo
                 LEFT JOIN quick_order_merchants qom ON qo.id = qom.quick_order_id
                 LEFT JOIN merchants m ON qom.merchant_id = m.id
                 WHERE qo.id = :quick_order_id"
            );
            $stmt->execute([':quick_order_id' => $quickOrderId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $isAvailable = $result['is_available'] && $result['merchant_open'];
                $stockIssue = $result['stock_quantity'] !== null && $quantity > $result['stock_quantity'];
                $maxIssue = $result['max_quantity'] && $quantity > $result['max_quantity'];
                
                $availability[] = [
                    'item_id' => $quickOrderId,
                    'name' => $result['title'],
                    'type' => 'quick_order',
                    'is_available' => $isAvailable && !$stockIssue && !$maxIssue,
                    'available_quantity' => $result['stock_quantity'],
                    'max_quantity' => $result['max_quantity'],
                    'issues' => [
                        'not_available' => !$result['is_available'],
                        'merchant_closed' => !$result['merchant_open'],
                        'insufficient_stock' => $stockIssue,
                        'exceeds_max' => $maxIssue
                    ]
                ];
            }
        }
    }
    
    ResponseHandler::success([
        'availability' => $availability,
        'all_available' => !in_array(false, array_column($availability, 'is_available'))
    ]);
}

/*********************************
 * MERGE CART
 *********************************/
function mergeCart($conn, $data, $userId, $baseUrl) {
    $guestItems = $data['guest_items'] ?? [];
    
    if (empty($guestItems)) {
        ResponseHandler::error('No guest items to merge', 400);
    }
    
    $cart = getOrCreateUserCart($conn, $userId);
    $mergedCount = 0;
    $skippedCount = 0;
    $mergedItems = [];
    
    $conn->beginTransaction();
    
    try {
        foreach ($guestItems as $guestItem) {
            $menuItemId = $guestItem['menu_item_id'] ?? null;
            $quickOrderId = $guestItem['quick_order_id'] ?? null;
            $merchantId = $guestItem['merchant_id'] ?? null;
            $quantity = intval($guestItem['quantity'] ?? 1);
            
            if ($menuItemId) {
                // Check if menu item exists
                $checkStmt = $conn->prepare(
                    "SELECT id, name FROM menu_items WHERE id = :item_id AND is_available = 1"
                );
                $checkStmt->execute([':item_id' => $menuItemId]);
                $item = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($item) {
                    // Check if already in cart
                    $existingStmt = $conn->prepare(
                        "SELECT id, quantity FROM cart_items 
                         WHERE user_id = :user_id AND menu_item_id = :item_id AND is_active = 1"
                    );
                    $existingStmt->execute([':user_id' => $userId, ':item_id' => $menuItemId]);
                    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        $newQuantity = $existing['quantity'] + $quantity;
                        $updateStmt = $conn->prepare(
                            "UPDATE cart_items 
                             SET quantity = :quantity, updated_at = NOW()
                             WHERE id = :id"
                        );
                        $updateStmt->execute([':quantity' => $newQuantity, ':id' => $existing['id']]);
                    } else {
                        $insertStmt = $conn->prepare(
                            "INSERT INTO cart_items 
                                (user_id, menu_item_id, quantity, is_active, created_at, updated_at)
                             VALUES 
                                (:user_id, :item_id, :quantity, 1, NOW(), NOW())"
                        );
                        $insertStmt->execute([
                            ':user_id' => $userId,
                            ':item_id' => $menuItemId,
                            ':quantity' => $quantity
                        ]);
                    }
                    
                    $mergedCount++;
                    $mergedItems[] = [
                        'type' => 'menu_item',
                        'id' => $menuItemId,
                        'name' => $item['name'],
                        'quantity' => $quantity
                    ];
                } else {
                    $skippedCount++;
                }
            } elseif ($quickOrderId && $merchantId) {
                // Check if quick order exists
                $checkStmt = $conn->prepare(
                    "SELECT qo.id, qo.title FROM quick_orders qo
                     INNER JOIN quick_order_merchants qom ON qo.id = qom.quick_order_id
                     WHERE qo.id = :quick_order_id 
                     AND qom.merchant_id = :merchant_id
                     AND qom.is_active = 1"
                );
                $checkStmt->execute([
                    ':quick_order_id' => $quickOrderId,
                    ':merchant_id' => $merchantId
                ]);
                $quickOrder = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($quickOrder) {
                    // Check if already in cart
                    $existingStmt = $conn->prepare(
                        "SELECT id, quantity FROM cart_items 
                         WHERE user_id = :user_id AND quick_order_id = :quick_order_id 
                         AND merchant_id = :merchant_id AND is_active = 1"
                    );
                    $existingStmt->execute([
                        ':user_id' => $userId,
                        ':quick_order_id' => $quickOrderId,
                        ':merchant_id' => $merchantId
                    ]);
                    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        $newQuantity = $existing['quantity'] + $quantity;
                        $updateStmt = $conn->prepare(
                            "UPDATE cart_items 
                             SET quantity = :quantity, updated_at = NOW()
                             WHERE id = :id"
                        );
                        $updateStmt->execute([':quantity' => $newQuantity, ':id' => $existing['id']]);
                    } else {
                        $insertStmt = $conn->prepare(
                            "INSERT INTO cart_items 
                                (user_id, quick_order_id, merchant_id, quantity, is_active, created_at, updated_at)
                             VALUES 
                                (:user_id, :quick_order_id, :merchant_id, :quantity, 1, NOW(), NOW())"
                        );
                        $insertStmt->execute([
                            ':user_id' => $userId,
                            ':quick_order_id' => $quickOrderId,
                            ':merchant_id' => $merchantId,
                            ':quantity' => $quantity
                        ]);
                    }
                    
                    $mergedCount++;
                    $mergedItems[] = [
                        'type' => 'quick_order',
                        'id' => $quickOrderId,
                        'merchant_id' => $merchantId,
                        'name' => $quickOrder['title'],
                        'quantity' => $quantity
                    ];
                } else {
                    $skippedCount++;
                }
            } else {
                $skippedCount++;
            }
        }
        
        // Update cart timestamp
        $updateCartStmt = $conn->prepare("UPDATE carts SET updated_at = NOW() WHERE id = :cart_id");
        $updateCartStmt->execute([':cart_id' => $cart['id']]);
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to merge cart: ' . $e->getMessage(), 500);
    }
    
    // Get updated cart data
    $cartItems = getCartItemsByUserId($conn, $userId, $baseUrl);
    $totals = calculateCartTotals($conn, $cart['id'], $userId);
    
    ResponseHandler::success([
        'merged_count' => $mergedCount,
        'skipped_count' => $skippedCount,
        'merged_items' => $mergedItems,
        'cart_summary' => [
            'item_count' => $totals['item_count'],
            'total_quantity' => $totals['total_quantity'],
            'subtotal' => $totals['subtotal'],
            'total_amount' => $totals['total_amount']
        ]
    ], 'Cart merged successfully');
}

/*********************************
 * MOVE CART ITEM TO WISHLIST
 *********************************/
function moveCartItemToWishlist($conn, $data, $userId) {
    $cartItemId = $data['cart_item_id'] ?? null;
    
    if (!$cartItemId) {
        ResponseHandler::error('Cart item ID is required', 400);
    }
    
    // Get cart item details
    $itemStmt = $conn->prepare(
        "SELECT ci.menu_item_id, ci.quick_order_id, ci.merchant_id,
                mi.name as item_name, qo.title as quick_order_title
         FROM cart_items ci
         LEFT JOIN menu_items mi ON ci.menu_item_id = mi.id
         LEFT JOIN quick_orders qo ON ci.quick_order_id = qo.id
         WHERE ci.id = :cart_item_id
         AND ci.user_id = :user_id
         AND ci.is_active = 1"
    );
    
    $itemStmt->execute([':cart_item_id' => $cartItemId, ':user_id' => $userId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        ResponseHandler::error('Cart item not found', 404);
    }
    
    $itemName = $item['item_name'] ?? $item['quick_order_title'] ?? 'Item';
    
    $conn->beginTransaction();
    
    try {
        // Check if already in wishlist
        $wishlistCheck = $conn->prepare(
            "SELECT id FROM wishlist 
             WHERE user_id = :user_id 
             AND ((menu_item_id = :menu_item_id AND :menu_item_id IS NOT NULL)
                  OR (quick_order_id = :quick_order_id AND :quick_order_id IS NOT NULL))
             LIMIT 1"
        );
        $wishlistCheck->execute([
            ':user_id' => $userId,
            ':menu_item_id' => $item['menu_item_id'],
            ':quick_order_id' => $item['quick_order_id']
        ]);
        
        if (!$wishlistCheck->fetch()) {
            // Add to wishlist
            $insertStmt = $conn->prepare(
                "INSERT INTO wishlist (user_id, menu_item_id, quick_order_id, merchant_id, created_at)
                 VALUES (:user_id, :menu_item_id, :quick_order_id, :merchant_id, NOW())"
            );
            $insertStmt->execute([
                ':user_id' => $userId,
                ':menu_item_id' => $item['menu_item_id'],
                ':quick_order_id' => $item['quick_order_id'],
                ':merchant_id' => $item['merchant_id']
            ]);
        }
        
        // Remove from cart
        $deleteStmt = $conn->prepare(
            "UPDATE cart_items 
             SET is_active = 0, updated_at = NOW()
             WHERE id = :cart_item_id"
        );
        $deleteStmt->execute([':cart_item_id' => $cartItemId]);
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to move item to wishlist: ' . $e->getMessage(), 500);
    }
    
    ResponseHandler::success([], "$itemName moved to wishlist");
}

/*********************************
 * SAVE ITEM FOR LATER
 *********************************/
function saveItemForLater($conn, $data, $userId, $baseUrl) {
    $cartItemId = $data['cart_item_id'] ?? null;
    
    if (!$cartItemId) {
        ResponseHandler::error('Cart item ID is required', 400);
    }
    
    // Get cart item details
    $itemStmt = $conn->prepare(
        "SELECT ci.menu_item_id, ci.quick_order_id, ci.merchant_id, ci.quantity,
                mi.name as item_name, qo.title as quick_order_title
         FROM cart_items ci
         LEFT JOIN menu_items mi ON ci.menu_item_id = mi.id
         LEFT JOIN quick_orders qo ON ci.quick_order_id = qo.id
         WHERE ci.id = :cart_item_id
         AND ci.user_id = :user_id
         AND ci.is_active = 1"
    );
    
    $itemStmt->execute([':cart_item_id' => $cartItemId, ':user_id' => $userId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        ResponseHandler::error('Cart item not found', 404);
    }
    
    $itemName = $item['item_name'] ?? $item['quick_order_title'] ?? 'Item';
    
    $conn->beginTransaction();
    
    try {
        // Check if already saved for later
        $savedCheck = $conn->prepare(
            "SELECT id FROM saved_for_later 
             WHERE user_id = :user_id 
             AND ((menu_item_id = :menu_item_id AND :menu_item_id IS NOT NULL)
                  OR (quick_order_id = :quick_order_id AND :quick_order_id IS NOT NULL))
             LIMIT 1"
        );
        $savedCheck->execute([
            ':user_id' => $userId,
            ':menu_item_id' => $item['menu_item_id'],
            ':quick_order_id' => $item['quick_order_id']
        ]);
        
        if (!$savedCheck->fetch()) {
            // Add to saved for later
            $insertStmt = $conn->prepare(
                "INSERT INTO saved_for_later (user_id, menu_item_id, quick_order_id, merchant_id, quantity, created_at)
                 VALUES (:user_id, :menu_item_id, :quick_order_id, :merchant_id, :quantity, NOW())"
            );
            $insertStmt->execute([
                ':user_id' => $userId,
                ':menu_item_id' => $item['menu_item_id'],
                ':quick_order_id' => $item['quick_order_id'],
                ':merchant_id' => $item['merchant_id'],
                ':quantity' => $item['quantity']
            ]);
        }
        
        // Remove from cart
        $deleteStmt = $conn->prepare(
            "UPDATE cart_items 
             SET is_active = 0, updated_at = NOW()
             WHERE id = :cart_item_id"
        );
        $deleteStmt->execute([':cart_item_id' => $cartItemId]);
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to save item for later: ' . $e->getMessage(), 500);
    }
    
    ResponseHandler::success([], "$itemName saved for later");
}

/*********************************
 * DEBUG - LIST UPLOADED FILES (TEMPORARY)
 *********************************/
function debugListUploadedFiles() {
    $uploadDir = __DIR__ . '/../uploads/menu_items/';
    if (is_dir($uploadDir)) {
        $files = scandir($uploadDir);
        error_log("Files in uploads/menu_items/: " . json_encode(array_diff($files, ['.', '..'])));
    } else {
        error_log("Upload directory not found: " . $uploadDir);
    }
}

// Call this temporarily to see what files exist
debugListUploadedFiles();

?>