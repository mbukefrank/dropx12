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
        'lifetime' => 86400 * 30, // 30 days - matches Flutter
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']), // Auto-detect for Railway
        'httponly' => true,
        'samesite' => 'Lax' // Changed from 'None' for better compatibility
    ]);
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * AUTHENTICATION HELPER
 *********************************/
function checkAuthentication($conn) {
    // Start by logging current auth state for debugging
    error_log("=== AUTH CHECK START ===");
    error_log("Session ID: " . session_id());
    error_log("Session User ID: " . ($_SESSION['user_id'] ?? 'NOT SET'));
    
    // 1. PRIMARY: Check PHP Session (Flutter sends cookies)
    if (!empty($_SESSION['user_id'])) {
        error_log("Auth Method: PHP Session");
        return $_SESSION['user_id'];
    }
    
    // 2. SECONDARY: Check Authorization Bearer Token
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (strpos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
        error_log("Auth Method: Bearer Token - $token");
        
        // Check in users table for API token
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
    
    // 3. TERTIARY: Check X-Session-Token header (Flutter custom header)
    $sessionToken = $headers['X-Session-Token'] ?? '';
    if ($sessionToken) {
        error_log("Auth Method: X-Session-Token - $sessionToken");
        
        // Try to validate session token
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
        
        // Fallback: check if it's the PHP session token
        if (session_id() !== $sessionToken) {
            // Try to restore session from token
            session_id($sessionToken);
            session_start();
            
            if (!empty($_SESSION['user_id'])) {
                error_log("Session Restored from Token - User ID: " . $_SESSION['user_id']);
                return $_SESSION['user_id'];
            }
        }
    }
    
    // 4. FALLBACK: Check for PHPSESSID cookie directly
    if (!empty($_COOKIE['PHPSESSID'])) {
        error_log("Auth Method: PHPSESSID Cookie");
        
        if (session_id() !== $_COOKIE['PHPSESSID']) {
            // Restart session with cookie ID
            session_id($_COOKIE['PHPSESSID']);
            session_start();
            
            if (!empty($_SESSION['user_id'])) {
                error_log("Session Restored from Cookie - User ID: " . $_SESSION['user_id']);
                return $_SESSION['user_id'];
            }
        }
    }
    
    // 5. DEBUG: Log all headers for troubleshooting
    error_log("All Headers: " . json_encode($headers));
    error_log("All Cookies: " . json_encode($_COOKIE));
    
    error_log("=== AUTH CHECK FAILED ===");
    return false;
}

/*********************************
 * BASE URL CONFIGURATION
 *********************************/
$baseUrl = "https://dropxbackend-production.up.railway.app";

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        handleGetRequest();
    } elseif ($method === 'POST') {
        handlePostRequest();
    } else {
        ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET REQUESTS
 *********************************/
function handleGetRequest() {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }

    // For GET requests, authentication is OPTIONAL
    // Public endpoints don't require auth, but will provide user-specific data if authenticated
    $userId = checkAuthentication($conn);
    
    // Check for specific quick order ID
    $orderId = $_GET['id'] ?? null;
    
    if ($orderId) {
        getQuickOrderDetails($conn, $orderId, $baseUrl, $userId);
    } else {
        getQuickOrdersList($conn, $baseUrl, $userId);
    }
}

/*********************************
 * GET QUICK ORDERS LIST
 *********************************/
function getQuickOrdersList($conn, $baseUrl, $userId = null) {
    // Get query parameters
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $category = $_GET['category'] ?? '';
    $search = $_GET['search'] ?? '';
    $sortBy = $_GET['sort_by'] ?? 'order_count';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');
    $isPopular = $_GET['is_popular'] ?? null;

    // Build WHERE clause
    $whereConditions = [];
    $params = [];

    if ($category && $category !== 'All') {
        $whereConditions[] = "qo.category = :category";
        $params[':category'] = $category;
    }

    if ($search) {
        $whereConditions[] = "(qo.title LIKE :search OR qo.description LIKE :search)";
        $params[':search'] = "%$search%";
    }

    if ($isPopular !== null) {
        $whereConditions[] = "qo.is_popular = :is_popular";
        $params[':is_popular'] = $isPopular === 'true' ? 1 : 0;
    }

    $whereClause = empty($whereConditions) ? "" : "WHERE " . implode(" AND ", $whereConditions);

    // Validate sort options
    $allowedSortColumns = ['order_count', 'title', 'created_at', 'rating'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'order_count';
    $sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';

    // Get total count for pagination
    $countSql = "SELECT COUNT(*) as total FROM quick_orders qo" . ($whereClause ? " $whereClause" : "");
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get quick orders
    $sql = "SELECT 
                qo.id,
                qo.title,
                qo.image_url,
                qo.color,
                qo.info,
                qo.is_popular,
                qo.category,
                qo.description,
                qo.delivery_time,
                COALESCE(
                    (SELECT qoi.price 
                     FROM quick_order_items qoi 
                     WHERE qoi.quick_order_id = qo.id 
                     AND qoi.is_default = 1 
                     LIMIT 1),
                    0.00
                ) as price,
                qo.order_count,
                qo.rating,
                qo.created_at,
                qo.updated_at
            FROM quick_orders qo
            $whereClause
            ORDER BY qo.is_popular DESC, qo.$sortBy $sortOrder
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    foreach ($params as $key => $value) {
        if ($key === ':limit' || $key === ':offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    $quickOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format quick order data
    $formattedOrders = array_map(function($q) use ($baseUrl) {
        return formatQuickOrderListData($q, $baseUrl);
    }, $quickOrders);

    $response = [
        'quick_orders' => $formattedOrders,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ];

    // Add user-specific data if authenticated
    if ($userId) {
        $response['user_authenticated'] = true;
        $response['user_id'] = $userId;
    } else {
        $response['user_authenticated'] = false;
    }

    ResponseHandler::success($response);
}

/*********************************
 * GET QUICK ORDER DETAILS
 *********************************/
function getQuickOrderDetails($conn, $orderId, $baseUrl, $userId = null) {
    $stmt = $conn->prepare(
        "SELECT 
            qo.id,
            qo.title,
            qo.image_url,
            qo.color,
            qo.info,
            qo.is_popular,
            qo.category,
            qo.description,
            qo.delivery_time,
            COALESCE(
                (SELECT qoi.price 
                 FROM quick_order_items qoi 
                 WHERE qoi.quick_order_id = qo.id 
                 AND qoi.is_default = 1 
                 LIMIT 1),
                0.00
            ) as price,
            qo.order_count,
            qo.rating,
            qo.created_at,
            qo.updated_at
        FROM quick_orders qo
        WHERE qo.id = :id"
    );
    
    $stmt->execute([':id' => $orderId]);
    $quickOrder = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quickOrder) {
        ResponseHandler::error('Quick order not found', 404);
    }

    // Get included items
    $itemsStmt = $conn->prepare(
        "SELECT 
            qoi.id,
            qoi.name,
            qoi.description,
            qoi.price,
            qoi.image_url,
            qoi.is_default,
            qoi.created_at
        FROM quick_order_items qoi
        WHERE qoi.quick_order_id = :quick_order_id
        ORDER BY qoi.is_default DESC, qoi.name"
    );
    
    $itemsStmt->execute([':quick_order_id' => $orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get available merchants for this quick order
    $merchantsStmt = $conn->prepare(
        "SELECT 
            m.id,
            m.name,
            m.category,
            m.rating,
            m.image_url,
            m.is_open,
            m.delivery_time,
            m.delivery_fee,
            m.distance
        FROM merchants m
        INNER JOIN quick_order_merchants qom ON m.id = qom.merchant_id
        WHERE qom.quick_order_id = :quick_order_id
        AND m.is_active = 1
        ORDER BY m.rating DESC, m.distance ASC
        LIMIT 5"
    );
    
    $merchantsStmt->execute([':quick_order_id' => $orderId]);
    $merchants = $merchantsStmt->fetchAll(PDO::FETCH_ASSOC);

    $orderData = formatQuickOrderDetailData($quickOrder, $baseUrl);
    $orderData['items'] = array_map(function($item) use ($baseUrl) {
        return formatQuickOrderItemData($item, $baseUrl);
    }, $items);
    $orderData['merchants'] = array_map(function($merchant) use ($baseUrl) {
        return formatQuickOrderMerchantData($merchant, $baseUrl);
    }, $merchants);

    $response = [
        'quick_order' => $orderData
    ];

    // Add user-specific data if authenticated
    if ($userId) {
        $response['user_authenticated'] = true;
        $response['user_id'] = $userId;
        
        // Check if user has favorited this quick order
        $favoriteStmt = $conn->prepare(
            "SELECT id FROM user_favorites 
             WHERE user_id = :user_id AND quick_order_id = :quick_order_id"
        );
        $favoriteStmt->execute([
            ':user_id' => $userId,
            ':quick_order_id' => $orderId
        ]);
        
        $response['quick_order']['is_favorited'] = $favoriteStmt->rowCount() > 0;
    } else {
        $response['user_authenticated'] = false;
        $response['quick_order']['is_favorited'] = false;
    }

    ResponseHandler::success($response);
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

    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input && !empty($_POST)) {
        $input = $_POST;
    }
    
    if (!$input) {
        ResponseHandler::error('No input data provided', 400);
    }
    
    $action = $input['action'] ?? '';

    // Check authentication for POST actions (all POST actions require auth)
    $userId = checkAuthentication($conn);
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    switch ($action) {
        case 'create_order':
            createQuickOrder($conn, $input, $userId);
            break;
        case 'get_order_history':
            getQuickOrderHistory($conn, $input, $baseUrl, $userId);
            break;
        case 'cancel_order':
            cancelQuickOrder($conn, $input, $userId);
            break;
        case 'rate_order':
            rateQuickOrder($conn, $input, $userId);
            break;
        case 'debug_auth':
            // Debug endpoint to check authentication
            debugAuth($conn);
            break;
        case 'toggle_favorite':
            toggleQuickOrderFavorite($conn, $input, $userId);
            break;
        default:
            ResponseHandler::error('Invalid action: ' . $action, 400);
    }
}

/*********************************
 * DEBUG AUTH ENDPOINT
 *********************************/
function debugAuth($conn) {
    $headers = getallheaders();
    
    ResponseHandler::success([
        'session_id' => session_id(),
        'session_user_id' => $_SESSION['user_id'] ?? null,
        'session_status' => session_status(),
        'session_name' => session_name(),
        'all_headers' => $headers,
        'all_cookies' => $_COOKIE,
        'session_data' => $_SESSION,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'
    ]);
}

/*********************************
 * TOGGLE QUICK ORDER FAVORITE
 *********************************/
function toggleQuickOrderFavorite($conn, $data, $userId) {
    $quickOrderId = $data['quick_order_id'] ?? null;
    
    if (!$quickOrderId) {
        ResponseHandler::error('Quick order ID is required', 400);
    }
    
    // Check if already favorited
    $checkStmt = $conn->prepare(
        "SELECT id FROM user_favorites 
         WHERE user_id = :user_id AND quick_order_id = :quick_order_id"
    );
    $checkStmt->execute([
        ':user_id' => $userId,
        ':quick_order_id' => $quickOrderId
    ]);
    
    if ($checkStmt->rowCount() > 0) {
        // Remove from favorites
        $deleteStmt = $conn->prepare(
            "DELETE FROM user_favorites 
             WHERE user_id = :user_id AND quick_order_id = :quick_order_id"
        );
        $deleteStmt->execute([
            ':user_id' => $userId,
            ':quick_order_id' => $quickOrderId
        ]);
        
        ResponseHandler::success([
            'is_favorited' => false,
            'message' => 'Removed from favorites'
        ]);
    } else {
        // Add to favorites
        $insertStmt = $conn->prepare(
            "INSERT INTO user_favorites (user_id, quick_order_id, created_at)
             VALUES (:user_id, :quick_order_id, NOW())"
        );
        $insertStmt->execute([
            ':user_id' => $userId,
            ':quick_order_id' => $quickOrderId
        ]);
        
        ResponseHandler::success([
            'is_favorited' => true,
            'message' => 'Added to favorites'
        ]);
    }
}

/*********************************
 * CREATE QUICK ORDER
 *********************************/
function createQuickOrder($conn, $data, $userId) {
    $quickOrderId = $data['quick_order_id'] ?? null;
    $merchantId = $data['merchant_id'] ?? null;
    $items = $data['items'] ?? [];
    $specialInstructions = trim($data['special_instructions'] ?? '');
    $deliveryAddress = trim($data['delivery_address'] ?? '');
    $paymentMethod = $data['payment_method'] ?? 'cash';
    
    if (!$quickOrderId) {
        ResponseHandler::error('Quick order ID is required', 400);
    }

    if (!$merchantId) {
        ResponseHandler::error('Merchant selection is required', 400);
    }

    if (empty($items)) {
        ResponseHandler::error('At least one item is required', 400);
    }

    if (!$deliveryAddress) {
        ResponseHandler::error('Delivery address is required', 400);
    }

    // Get quick order details
    $orderStmt = $conn->prepare(
        "SELECT 
            qo.title,
            COALESCE(
                (SELECT qoi.price 
                 FROM quick_order_items qoi 
                 WHERE qoi.quick_order_id = qo.id 
                 AND qoi.is_default = 1 
                 LIMIT 1),
                0.00
            ) as price,
            qo.delivery_time 
        FROM quick_orders qo 
        WHERE qo.id = :id"
    );
    $orderStmt->execute([':id' => $quickOrderId]);
    $quickOrder = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$quickOrder) {
        ResponseHandler::error('Quick order not found', 404);
    }

    // Get merchant details
    $merchantStmt = $conn->prepare(
        "SELECT name, delivery_fee FROM merchants WHERE id = :id AND is_active = 1"
    );
    $merchantStmt->execute([':id' => $merchantId]);
    $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not available', 404);
    }

    // Calculate total
    $subtotal = 0;
    $itemDetails = [];

    foreach ($items as $item) {
        $itemId = $item['id'] ?? null;
        $quantity = intval($item['quantity'] ?? 1);

        if ($itemId && $quantity > 0) {
            $itemStmt = $conn->prepare(
                "SELECT name, price FROM quick_order_items WHERE id = :id"
            );
            $itemStmt->execute([':id' => $itemId]);
            $itemData = $itemStmt->fetch(PDO::FETCH_ASSOC);

            if ($itemData) {
                $itemTotal = $itemData['price'] * $quantity;
                $subtotal += $itemTotal;
                
                $itemDetails[] = [
                    'name' => $itemData['name'],
                    'quantity' => $quantity,
                    'price' => $itemData['price'],
                    'total' => $itemTotal
                ];
            }
        }
    }

    if ($subtotal <= 0) {
        ResponseHandler::error('Invalid order total', 400);
    }

    $deliveryFee = floatval($merchant['delivery_fee'] ?? 0);
    $totalAmount = $subtotal + $deliveryFee;

    // Generate order number
    $orderNumber = 'QO-' . date('Ymd') . '-' . strtoupper(uniqid());

    // Start transaction
    $conn->beginTransaction();

    try {
        // Create order record
        $orderSql = "
            INSERT INTO orders (
                order_number, user_id, merchant_id, quick_order_id,
                subtotal, delivery_fee, total_amount, payment_method,
                delivery_address, special_instructions, status,
                created_at
            ) VALUES (
                :order_number, :user_id, :merchant_id, :quick_order_id,
                :subtotal, :delivery_fee, :total_amount, :payment_method,
                :delivery_address, :special_instructions, 'pending',
                NOW()
            )";

        $orderStmt = $conn->prepare($orderSql);
        $orderStmt->execute([
            ':order_number' => $orderNumber,
            ':user_id' => $userId,
            ':merchant_id' => $merchantId,
            ':quick_order_id' => $quickOrderId,
            ':subtotal' => $subtotal,
            ':delivery_fee' => $deliveryFee,
            ':total_amount' => $totalAmount,
            ':payment_method' => $paymentMethod,
            ':delivery_address' => $deliveryAddress,
            ':special_instructions' => $specialInstructions
        ]);

        $orderId = $conn->lastInsertId();

        // Insert order items
        $itemSql = "
            INSERT INTO order_items (
                order_id, item_name, quantity, price, total,
                created_at
            ) VALUES (
                :order_id, :item_name, :quantity, :price, :total,
                NOW()
            )";

        $itemStmt = $conn->prepare($itemSql);

        foreach ($itemDetails as $item) {
            $itemStmt->execute([
                ':order_id' => $orderId,
                ':item_name' => $item['name'],
                ':quantity' => $item['quantity'],
                ':price' => $item['price'],
                ':total' => $item['total']
            ]);
        }

        // Update quick order count
        $updateOrderStmt = $conn->prepare(
            "UPDATE quick_orders SET order_count = order_count + 1 WHERE id = :id"
        );
        $updateOrderStmt->execute([':id' => $quickOrderId]);

        // Commit transaction
        $conn->commit();

        // Get full order details
        $orderDetails = getOrderDetails($conn, $orderId);

        ResponseHandler::success([
            'order' => formatOrderData($orderDetails),
            'order_number' => $orderNumber,
            'estimated_delivery' => date('Y-m-d H:i:s', strtotime('+30 minutes'))
        ], 'Quick order created successfully');

    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Order creation error: " . $e->getMessage());
        ResponseHandler::error('Failed to create order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET QUICK ORDER HISTORY
 *********************************/
function getQuickOrderHistory($conn, $data, $baseUrl, $userId) {
    $page = max(1, intval($data['page'] ?? 1));
    $limit = min(50, max(1, intval($data['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $status = $data['status'] ?? '';

    // Build WHERE clause
    $whereConditions = ["o.user_id = :user_id", "o.quick_order_id IS NOT NULL"];
    $params = [':user_id' => $userId];

    if ($status) {
        $whereConditions[] = "o.status = :status";
        $params[':status'] = $status;
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM orders o $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get orders
    $sql = "SELECT 
                o.id,
                o.order_number,
                o.status,
                o.subtotal,
                o.delivery_fee,
                o.total_amount,
                o.payment_method,
                o.delivery_address,
                o.special_instructions,
                o.created_at,
                o.updated_at,
                qo.title as quick_order_title,
                qo.image_url as quick_order_image,
                m.name as merchant_name,
                m.image_url as merchant_image
            FROM orders o
            LEFT JOIN quick_orders qo ON o.quick_order_id = qo.id
            LEFT JOIN merchants m ON o.merchant_id = m.id
            $whereClause
            ORDER BY o.created_at DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    foreach ($params as $key => $value) {
        if ($key === ':limit' || $key === ':offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format orders
    $formattedOrders = array_map(function($order) use ($baseUrl) {
        return formatOrderHistoryData($order, $baseUrl);
    }, $orders);

    ResponseHandler::success([
        'orders' => $formattedOrders,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ]);
}

/*********************************
 * CANCEL QUICK ORDER
 *********************************/
function cancelQuickOrder($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;
    $reason = trim($data['reason'] ?? '');

    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
    }

    // Check if order exists and belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id, status FROM orders 
         WHERE id = :id AND user_id = :user_id AND quick_order_id IS NOT NULL"
    );
    $checkStmt->execute([
        ':id' => $orderId,
        ':user_id' => $userId
    ]);
    
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ResponseHandler::error('Order not found', 404);
    }

    // Check if order can be cancelled
    $allowedStatuses = ['pending', 'confirmed', 'preparing'];
    if (!in_array($order['status'], $allowedStatuses)) {
        ResponseHandler::error('Order cannot be cancelled at this stage', 400);
    }

    // Update order status
    $updateStmt = $conn->prepare(
        "UPDATE orders 
         SET status = 'cancelled', 
             cancellation_reason = :reason,
             updated_at = NOW()
         WHERE id = :id"
    );
    
    $updateStmt->execute([
        ':reason' => $reason,
        ':id' => $orderId
    ]);

    ResponseHandler::success([], 'Order cancelled successfully');
}

/*********************************
 * RATE QUICK ORDER
 *********************************/
function rateQuickOrder($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;
    $rating = intval($data['rating'] ?? 0);
    $comment = trim($data['comment'] ?? '');

    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
    }

    if ($rating < 1 || $rating > 5) {
        ResponseHandler::error('Rating must be between 1 and 5', 400);
    }

    // Check if order exists and is delivered
    $checkStmt = $conn->prepare(
        "SELECT o.id, o.quick_order_id 
         FROM orders o
         WHERE o.id = :id 
         AND o.user_id = :user_id 
         AND o.status = 'delivered'
         AND o.quick_order_id IS NOT NULL"
    );
    $checkStmt->execute([
        ':id' => $orderId,
        ':user_id' => $userId
    ]);
    
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ResponseHandler::error('Order not found or cannot be rated', 404);
    }

    // Check if already rated
    $existingStmt = $conn->prepare(
        "SELECT id FROM user_reviews 
         WHERE order_id = :order_id AND user_id = :user_id"
    );
    $existingStmt->execute([
        ':order_id' => $orderId,
        ':user_id' => $userId
    ]);
    
    if ($existingStmt->fetch()) {
        ResponseHandler::error('You have already rated this order', 409);
    }

    // Create review
    $stmt = $conn->prepare(
        "INSERT INTO user_reviews 
            (user_id, order_id, quick_order_id, rating, comment, review_type, created_at)
         VALUES (:user_id, :order_id, :quick_order_id, :rating, :comment, 'quick_order', NOW())"
    );
    
    $stmt->execute([
        ':user_id' => $userId,
        ':order_id' => $orderId,
        ':quick_order_id' => $order['quick_order_id'],
        ':rating' => $rating,
        ':comment' => $comment
    ]);

    // Update quick order rating
    updateQuickOrderRating($conn, $order['quick_order_id']);

    ResponseHandler::success([], 'Thank you for your rating!');
}

/*********************************
 * GET ORDER DETAILS
 *********************************/
function getOrderDetails($conn, $orderId) {
    $sql = "SELECT 
                o.id,
                o.order_number,
                o.status,
                o.subtotal,
                o.delivery_fee,
                o.total_amount,
                o.payment_method,
                o.delivery_address,
                o.special_instructions,
                o.created_at,
                o.updated_at,
                qo.title as quick_order_title,
                qo.image_url as quick_order_image,
                m.name as merchant_name,
                m.phone as merchant_phone,
                m.address as merchant_address
            FROM orders o
            LEFT JOIN quick_orders qo ON o.quick_order_id = qo.id
            LEFT JOIN merchants m ON o.merchant_id = m.id
            WHERE o.id = :id";

    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $orderId]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/*********************************
 * UPDATE QUICK ORDER RATING
 *********************************/
function updateQuickOrderRating($conn, $quickOrderId) {
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total_reviews,
            AVG(rating) as avg_rating
        FROM user_reviews
        WHERE quick_order_id = :quick_order_id
        AND review_type = 'quick_order'"
    );
    $stmt->execute([':quick_order_id' => $quickOrderId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $updateStmt = $conn->prepare(
        "UPDATE quick_orders 
         SET rating = :rating, 
             updated_at = NOW()
         WHERE id = :id"
    );
    
    $updateStmt->execute([
        ':rating' => $result['avg_rating'] ?? 0,
        ':id' => $quickOrderId
    ]);
}

/*********************************
 * FORMATTING FUNCTIONS (Keep existing)
 *********************************/
function formatQuickOrderListData($q, $baseUrl) {
    $imageUrl = '';
    if (!empty($q['image_url'])) {
        if (strpos($q['image_url'], 'http') === 0) {
            $imageUrl = $q['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/' . $q['image_url'];
        }
    }
    
    return [
        'id' => $q['id'] ?? null,
        'title' => $q['title'] ?? '',
        'image_url' => $imageUrl,
        'color' => $q['color'] ?? '#3A86FF',
        'info' => $q['info'] ?? '',
        'is_popular' => boolval($q['is_popular'] ?? false),
        'category' => $q['category'] ?? '',
        'description' => $q['description'] ?? '',
        'delivery_time' => $q['delivery_time'] ?? '',
        'price' => floatval($q['price'] ?? 0),
        'order_count' => intval($q['order_count'] ?? 0),
        'rating' => floatval($q['rating'] ?? 0),
        'created_at' => $q['created_at'] ?? '',
        'updated_at' => $q['updated_at'] ?? ''
    ];
}

function formatQuickOrderDetailData($q, $baseUrl) {
    $imageUrl = '';
    if (!empty($q['image_url'])) {
        if (strpos($q['image_url'], 'http') === 0) {
            $imageUrl = $q['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/' . $q['image_url'];
        }
    }
    
    return [
        'id' => $q['id'] ?? null,
        'title' => $q['title'] ?? '',
        'image_url' => $imageUrl,
        'color' => $q['color'] ?? '#3A86FF',
        'info' => $q['info'] ?? '',
        'is_popular' => boolval($q['is_popular'] ?? false),
        'category' => $q['category'] ?? '',
        'description' => $q['description'] ?? '',
        'delivery_time' => $q['delivery_time'] ?? '',
        'price' => floatval($q['price'] ?? 0),
        'order_count' => intval($q['order_count'] ?? 0),
        'rating' => floatval($q['rating'] ?? 0),
        'created_at' => $q['created_at'] ?? '',
        'updated_at' => $q['updated_at'] ?? ''
    ];
}

function formatQuickOrderItemData($item, $baseUrl) {
    $imageUrl = '';
    if (!empty($item['image_url'])) {
        if (strpos($item['image_url'], 'http') === 0) {
            $imageUrl = $item['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/' . $item['image_url'];
        }
    }
    
    return [
        'id' => $item['id'] ?? null,
        'name' => $item['name'] ?? '',
        'description' => $item['description'] ?? '',
        'price' => floatval($item['price'] ?? 0),
        'image_url' => $imageUrl,
        'is_default' => boolval($item['is_default'] ?? false),
        'created_at' => $item['created_at'] ?? ''
    ];
}

function formatQuickOrderMerchantData($merchant, $baseUrl) {
    $imageUrl = '';
    if (!empty($merchant['image_url'])) {
        if (strpos($merchant['image_url'], 'http') === 0) {
            $imageUrl = $merchant['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/' . $merchant['image_url'];
        }
    }
    
    return [
        'id' => $merchant['id'] ?? null,
        'name' => $merchant['name'] ?? '',
        'category' => $merchant['category'] ?? '',
        'rating' => floatval($merchant['rating'] ?? 0),
        'image_url' => $imageUrl,
        'is_open' => boolval($merchant['is_open'] ?? false),
        'delivery_time' => $merchant['delivery_time'] ?? '',
        'delivery_fee' => floatval($merchant['delivery_fee'] ?? 0),
        'distance' => $merchant['distance'] ?? ''
    ];
}

function formatOrderData($order) {
    global $baseUrl;
    
    $quickOrderImage = '';
    if (!empty($order['quick_order_image'])) {
        if (strpos($order['quick_order_image'], 'http') === 0) {
            $quickOrderImage = $order['quick_order_image'];
        } else {
            $quickOrderImage = rtrim($baseUrl, '/') . '/uploads/' . $order['quick_order_image'];
        }
    }
    
    $merchantImage = '';
    if (!empty($order['merchant_image'])) {
        if (strpos($order['merchant_image'], 'http') === 0) {
            $merchantImage = $order['merchant_image'];
        } else {
            $merchantImage = rtrim($baseUrl, '/') . '/uploads/' . $order['merchant_image'];
        }
    }
    
    return [
        'id' => $order['id'] ?? null,
        'order_number' => $order['order_number'] ?? '',
        'status' => $order['status'] ?? '',
        'subtotal' => floatval($order['subtotal'] ?? 0),
        'delivery_fee' => floatval($order['delivery_fee'] ?? 0),
        'total_amount' => floatval($order['total_amount'] ?? 0),
        'payment_method' => $order['payment_method'] ?? '',
        'delivery_address' => $order['delivery_address'] ?? '',
        'special_instructions' => $order['special_instructions'] ?? '',
        'quick_order_title' => $order['quick_order_title'] ?? '',
        'quick_order_image' => $quickOrderImage,
        'merchant_name' => $order['merchant_name'] ?? '',
        'merchant_phone' => $order['merchant_phone'] ?? '',
        'merchant_address' => $order['merchant_address'] ?? '',
        'merchant_image' => $merchantImage,
        'created_at' => $order['created_at'] ?? '',
        'updated_at' => $order['updated_at'] ?? ''
    ];
}

function formatOrderHistoryData($order, $baseUrl) {
    $quickOrderImage = '';
    if (!empty($order['quick_order_image'])) {
        if (strpos($order['quick_order_image'], 'http') === 0) {
            $quickOrderImage = $order['quick_order_image'];
        } else {
            $quickOrderImage = rtrim($baseUrl, '/') . '/uploads/' . $order['quick_order_image'];
        }
    }
    
    $merchantImage = '';
    if (!empty($order['merchant_image'])) {
        if (strpos($order['merchant_image'], 'http') === 0) {
            $merchantImage = $order['merchant_image'];
        } else {
            $merchantImage = rtrim($baseUrl, '/') . '/uploads/' . $order['merchant_image'];
        }
    }
    
    return [
        'id' => $order['id'] ?? null,
        'order_number' => $order['order_number'] ?? '',
        'status' => $order['status'] ?? '',
        'subtotal' => floatval($order['subtotal'] ?? 0),
        'delivery_fee' => floatval($order['delivery_fee'] ?? 0),
        'total_amount' => floatval($order['total_amount'] ?? 0),
        'payment_method' => $order['payment_method'] ?? '',
        'delivery_address' => $order['delivery_address'] ?? '',
        'special_instructions' => $order['special_instructions'] ?? '',
        'quick_order_title' => $order['quick_order_title'] ?? '',
        'quick_order_image' => $quickOrderImage,
        'merchant_name' => $order['merchant_name'] ?? '',
        'merchant_image' => $merchantImage,
        'created_at' => $order['created_at'] ?? '',
        'updated_at' => $order['updated_at'] ?? ''
    ];
}
?>