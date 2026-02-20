<?php
/*********************************
 * CORS Configuration
 *********************************/
// Start output buffering to prevent headers already sent error
ob_start();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Session-Token, X-App-Version, X-Platform, X-Device-ID, X-Timestamp");
header("Access-Control-Max-Age: 3600");
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * ERROR HANDLING
 *********************************/
// Set error handler to catch warnings and convert to exceptions
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

/*********************************
 * SESSION CONFIG - MUST MATCH auth.php EXACTLY
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

/*********************************
 * BASE URL CONFIGURATION
 *********************************/
$baseUrl = "https://dropx12-production.up.railway.app";

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * AUTHENTICATION CHECK
 *********************************/
function checkAuthentication() {
    // Check for session token header first (mobile app sends this)
    $sessionToken = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? null;
    
    if ($sessionToken) {
        session_id($sessionToken);
        session_start();
    }
    
    // Check both session and headers for user ID (mobile compatibility)
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['logged_in'])) {
        return $_SESSION['user_id'];
    }
    
    // Check for user ID in headers (alternative for mobile)
    $userId = $_SERVER['HTTP_X_USER_ID'] ?? null;
    if ($userId) {
        return $userId;
    }
    
    return null;
}

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Get input from various sources (mobile app sends JSON)
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? $_GET['action'] ?? '';

    // Check authentication first for all requests
    $userId = checkAuthentication();
    if (!$userId) {
        ob_clean();
        ResponseHandler::error('Authentication required. Please login.', 401, 'AUTH_REQUIRED');
    }

    // Route authenticated requests
    if ($method === 'GET') {
        if (!empty($action)) {
            handleGetActions($action, $input, $userId);
        } else {
            handleGetRequest($userId);
        }
    } elseif ($method === 'POST') {
        if (!empty($action)) {
            handlePostActions($action, $input, $userId);
        } else {
            handlePostRequest($userId);
        }
    } elseif ($method === 'PUT') {
        handlePutRequest($userId);
    } else {
        ob_clean();
        ResponseHandler::error('Method not allowed', 405);
    }

} catch (ErrorException $e) {
    // Handle PHP warnings/notices
    ob_clean();
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500, 'SERVER_ERROR');
} catch (Exception $e) {
    ob_clean();
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500, 'SERVER_ERROR');
}

/*********************************
 * GET ACTIONS HANDLER (Mobile)
 *********************************/
function handleGetActions($action, $input, $userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        switch ($action) {
            case 'get_orders':
                handleGetOrders($conn, $input, $userId);
                break;
            case 'get_order':
                $orderId = $input['order_id'] ?? $_GET['order_id'] ?? '';
                if ($orderId) {
                    getOrderDetails($conn, $orderId, $userId);
                } else {
                    ob_clean();
                    ResponseHandler::error('Order ID required', 400);
                }
                break;
            case 'latest_active':
                getLatestActiveOrder($conn, $userId);
                break;
            default:
                ob_clean();
                ResponseHandler::error('Invalid action', 400);
        }
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Error in get action: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * POST ACTIONS HANDLER (Mobile)
 *********************************/
function handlePostActions($action, $input, $userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        switch ($action) {
            case 'create_order':
                createOrder($conn, $input, $userId);
                break;
            case 'cancel_order':
                cancelOrder($conn, $input, $userId);
                break;
            case 'reorder':
                reorder($conn, $input, $userId);
                break;
            default:
                ob_clean();
                ResponseHandler::error('Invalid action', 400);
        }
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Error in post action: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET REQUESTS (Legacy - Mobile Compatible)
 *********************************/
function handleGetRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        $orderId = $_GET['id'] ?? null;
        
        if ($orderId) {
            getOrderDetails($conn, $orderId, $userId);
        } else {
            getOrdersList($conn, $userId);
        }
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Error in get request: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * POST REQUESTS (Legacy - Mobile Compatible)
 *********************************/
function handlePostRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $input = $_POST;
        }
        
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'create_order':
                createOrder($conn, $input, $userId);
                break;
            case 'cancel_order':
                cancelOrder($conn, $input, $userId);
                break;
            case 'reorder':
                reorder($conn, $input, $userId);
                break;
            case 'latest_active':
                getLatestActiveOrder($conn, $userId);
                break;
            default:
                ob_clean();
                ResponseHandler::error('Invalid action', 400);
        }
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Error in post request: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * PUT REQUESTS (Mobile)
 *********************************/
function handlePutRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            parse_str(file_get_contents('php://input'), $input);
        }
        
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'update_order':
                updateOrder($conn, $input, $userId);
                break;
            case 'update_delivery_address':
                updateDeliveryAddress($conn, $input, $userId);
                break;
            default:
                ob_clean();
                ResponseHandler::error('Invalid action', 400);
        }
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Error in put request: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET ORDERS HANDLER (Mobile Optimized)
 *********************************/
function handleGetOrders($conn, $input, $userId) {
    try {
        $page = max(1, intval($input['page'] ?? $_GET['page'] ?? 1));
        $limit = min(50, max(1, intval($input['limit'] ?? $_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $status = $input['status'] ?? $_GET['status'] ?? 'all';
        $orderNumber = $input['order_number'] ?? $_GET['order_number'] ?? '';
        $startDate = $input['start_date'] ?? $_GET['start_date'] ?? '';
        $endDate = $input['end_date'] ?? $_GET['end_date'] ?? '';

        // Build WHERE clause
        $whereConditions = ["o.user_id = :user_id"];
        $params = [':user_id' => $userId];

        if ($status !== 'all') {
            $whereConditions[] = "o.status = :status";
            $params[':status'] = $status;
        }

        if ($orderNumber) {
            $whereConditions[] = "o.order_number LIKE :order_number";
            $params[':order_number'] = "%$orderNumber%";
        }

        if ($startDate) {
            $whereConditions[] = "DATE(o.created_at) >= :start_date";
            $params[':start_date'] = $startDate;
        }

        if ($endDate) {
            $whereConditions[] = "DATE(o.created_at) <= :end_date";
            $params[':end_date'] = $endDate;
        }

        $whereClause = "WHERE " . implode(" AND ", $whereConditions);

        // Get total count
        $countSql = "SELECT COUNT(DISTINCT o.id) as total FROM orders o $whereClause";
        $countStmt = $conn->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Main query
        $sql = "SELECT 
                    o.id,
                    o.order_number,
                    o.status,
                    o.subtotal,
                    o.delivery_fee,
                    o.total_amount,
                    o.payment_method,
                    o.payment_status,
                    o.delivery_address,
                    o.special_instructions,
                    o.created_at,
                    o.updated_at,
                    o.merchant_id,
                    m.name as merchant_name,
                    m.image_url as merchant_image,
                    (
                        SELECT COUNT(*) 
                        FROM order_items oi 
                        WHERE oi.order_id = o.id
                    ) as item_count,
                    (
                        SELECT SUM(quantity) 
                        FROM order_items oi 
                        WHERE oi.order_id = o.id
                    ) as total_items
                FROM orders o
                LEFT JOIN merchants m ON o.merchant_id = m.id
                $whereClause
                ORDER BY o.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $conn->prepare($sql);
        
        // Bind all parameters
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get user info
        $userStmt = $conn->prepare(
            "SELECT full_name, phone FROM users WHERE id = :user_id"
        );
        $userStmt->execute([':user_id' => $userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        // Format orders for mobile app
        $formattedOrders = [];
        foreach ($orders as $order) {
            $formattedOrders[] = [
                'id' => (int)$order['id'],
                'order_number' => $order['order_number'],
                'status' => $order['status'],
                'customer_name' => $user['full_name'] ?? 'Customer',
                'customer_phone' => $user['phone'] ?? '',
                'delivery_address' => $order['delivery_address'],
                'total_amount' => (float)$order['total_amount'],
                'delivery_fee' => (float)$order['delivery_fee'],
                'subtotal' => (float)$order['subtotal'],
                'item_count' => (int)$order['item_count'],
                'total_items' => (int)$order['total_items'],
                'created_at' => $order['created_at'],
                'payment_method' => $order['payment_method'] ?? 'cash',
                'payment_status' => $order['payment_status'] ?? 'pending',
                'merchant_name' => $order['merchant_name'] ?? 'DropX Store',
                'merchant_id' => $order['merchant_id'] ? (int)$order['merchant_id'] : null,
                'merchant_image' => formatImageUrl($order['merchant_image'], 'merchants'),
                'special_instructions' => $order['special_instructions'] ?? '',
                'updated_at' => $order['updated_at']
            ];
        }

        ob_clean();
        ResponseHandler::success([
            'orders' => $formattedOrders,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total_items' => (int)$totalCount,
                'total_pages' => ceil($totalCount / $limit)
            ]
        ]);
        
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Failed to fetch orders: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CREATE ORDER (Single Merchant)
 *********************************/
function createOrder($conn, $data, $userId) {
    try {
        // Validate required data
        $requiredFields = ['merchant_id', 'items', 'delivery_address'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                ob_clean();
                ResponseHandler::error("Missing required field: $field", 400);
            }
        }

        $merchantId = $data['merchant_id'];
        $items = $data['items'];
        
        if (!is_array($items) || empty($items)) {
            ob_clean();
            ResponseHandler::error('No items in order', 400);
        }

        // Validate merchant exists and is open
        $merchantStmt = $conn->prepare(
            "SELECT id, name, delivery_fee, is_open, minimum_order, 
                    preparation_time_minutes, address
             FROM merchants 
             WHERE id = ? AND is_active = 1"
        );
        
        $merchantStmt->execute([$merchantId]);
        $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);

        if (!$merchant) {
            ob_clean();
            ResponseHandler::error("Merchant not found or inactive", 404);
        }
        
        if (!$merchant['is_open']) {
            ob_clean();
            ResponseHandler::error("Merchant {$merchant['name']} is currently closed", 400);
        }

        // Calculate subtotal
        $subtotal = 0;
        foreach ($items as $item) {
            if (empty($item['name']) || empty($item['quantity']) || empty($item['price'])) {
                ob_clean();
                ResponseHandler::error('Invalid item data - each item must have name, quantity, and price', 400);
            }
            $subtotal += $item['price'] * $item['quantity'];
        }

        // Check minimum order
        if ($subtotal < $merchant['minimum_order']) {
            ob_clean();
            ResponseHandler::error(
                "Order must be at least " . number_format($merchant['minimum_order'], 2), 
                400
            );
        }

        $deliveryFee = $merchant['delivery_fee'];
        $totalAmount = $subtotal + $deliveryFee;

        // Generate unique order number
        $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

        // Begin transaction
        $conn->beginTransaction();

        // Create order
        $orderSql = "INSERT INTO orders (
            order_number, user_id, merchant_id, subtotal, 
            delivery_fee, total_amount, payment_method, delivery_address, 
            special_instructions, status, created_at, updated_at
        ) VALUES (
            :order_number, :user_id, :merchant_id, :subtotal,
            :delivery_fee, :total_amount, :payment_method, :delivery_address,
            :special_instructions, 'pending', NOW(), NOW()
        )";

        $orderStmt = $conn->prepare($orderSql);
        $orderStmt->execute([
            ':order_number' => $orderNumber,
            ':user_id' => $userId,
            ':merchant_id' => $merchantId,
            ':subtotal' => $subtotal,
            ':delivery_fee' => $deliveryFee,
            ':total_amount' => $totalAmount,
            ':payment_method' => $data['payment_method'] ?? 'Cash on Delivery',
            ':delivery_address' => $data['delivery_address'],
            ':special_instructions' => $data['special_instructions'] ?? ''
        ]);

        $orderId = $conn->lastInsertId();

        // Create order items
        $itemSql = "INSERT INTO order_items (
            order_id, item_name, quantity, unit_price, total_price, created_at
        ) VALUES (
            :order_id, :item_name, :quantity, :unit_price, :total_price, NOW()
        )";

        $itemStmt = $conn->prepare($itemSql);
        foreach ($items as $item) {
            $itemTotal = $item['price'] * $item['quantity'];
            $itemStmt->execute([
                ':order_id' => $orderId,
                ':item_name' => $item['name'],
                ':quantity' => $item['quantity'],
                ':unit_price' => $item['price'],
                ':total_price' => $itemTotal
            ]);
        }

        // Add to order status history
        $historySql = "INSERT INTO order_status_history (
            order_id, old_status, new_status, changed_by, 
            changed_by_id, created_at
        ) VALUES (
            :order_id, :old_status, :new_status, :changed_by,
            :changed_by_id, NOW()
        )";

        $historyStmt = $conn->prepare($historySql);
        $historyStmt->execute([
            ':order_id' => $orderId,
            ':old_status' => '',
            ':new_status' => 'pending',
            ':changed_by' => 'user',
            ':changed_by_id' => $userId
        ]);

        // Update user's total orders
        $updateUserSql = "UPDATE users SET total_orders = total_orders + 1 WHERE id = :user_id";
        $updateUserStmt = $conn->prepare($updateUserSql);
        $updateUserStmt->execute([':user_id' => $userId]);

        // Commit transaction
        $conn->commit();

        ob_clean();
        ResponseHandler::success([
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'message' => 'Order created successfully'
        ], 201);

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        ob_clean();
        ResponseHandler::error('Failed to create order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CANCEL ORDER (Mobile)
 *********************************/
function cancelOrder($conn, $data, $userId) {
    try {
        $orderId = $data['order_id'] ?? null;
        $reason = trim($data['reason'] ?? '');

        if (!$orderId) {
            ob_clean();
            ResponseHandler::error('Order ID is required', 400);
        }

        // Check if order exists and belongs to user
        $checkStmt = $conn->prepare(
            "SELECT id, status FROM orders
             WHERE id = :order_id AND user_id = :user_id"
        );
        $checkStmt->execute([
            ':order_id' => $orderId,
            ':user_id' => $userId
        ]);
        
        $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            ob_clean();
            ResponseHandler::error('Order not found', 404);
        }

        // Check if order can be cancelled
        $cancellableStatuses = ['pending', 'confirmed'];
        if (!in_array($order['status'], $cancellableStatuses)) {
            ob_clean();
            ResponseHandler::error('Order cannot be cancelled at this stage', 400);
        }

        // Begin transaction
        $conn->beginTransaction();

        // Update order status
        $updateStmt = $conn->prepare(
            "UPDATE orders SET 
                status = 'cancelled',
                cancellation_reason = :reason,
                updated_at = NOW()
             WHERE id = :order_id"
        );
        
        $updateStmt->execute([
            ':order_id' => $orderId,
            ':reason' => $reason
        ]);

        // Add to order status history
        $historySql = "INSERT INTO order_status_history (
            order_id, old_status, new_status, changed_by, 
            changed_by_id, reason, created_at
        ) VALUES (
            :order_id, :old_status, :new_status, :changed_by,
            :changed_by_id, :reason, NOW()
        )";

        $historyStmt = $conn->prepare($historySql);
        $historyStmt->execute([
            ':order_id' => $orderId,
            ':old_status' => $order['status'],
            ':new_status' => 'cancelled',
            ':changed_by' => 'user',
            ':changed_by_id' => $userId,
            ':reason' => $reason
        ]);

        $conn->commit();

        ob_clean();
        ResponseHandler::success([
            'order_id' => (int)$orderId,
            'message' => 'Order cancelled successfully'
        ]);

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        ob_clean();
        ResponseHandler::error('Failed to cancel order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * REORDER (Mobile)
 *********************************/
function reorder($conn, $data, $userId) {
    try {
        $orderId = $data['order_id'] ?? null;

        if (!$orderId) {
            ob_clean();
            ResponseHandler::error('Order ID is required', 400);
        }

        // Get original order details
        $orderSql = "SELECT 
                        o.merchant_id,
                        o.delivery_address,
                        o.special_instructions,
                        o.payment_method,
                        oi.item_name,
                        oi.quantity,
                        oi.unit_price
                    FROM orders o
                    JOIN order_items oi ON o.id = oi.order_id
                    WHERE o.id = :order_id AND o.user_id = :user_id";
        
        $orderStmt = $conn->prepare($orderSql);
        $orderStmt->execute([
            ':order_id' => $orderId,
            ':user_id' => $userId
        ]);
        
        $items = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            ob_clean();
            ResponseHandler::error('Order not found', 404);
        }

        // Check if merchant is still active
        $merchantStmt = $conn->prepare(
            "SELECT id, is_open, is_active FROM merchants WHERE id = ?"
        );
        $merchantStmt->execute([$items[0]['merchant_id']]);
        $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);

        if (!$merchant || !$merchant['is_active']) {
            ob_clean();
            ResponseHandler::error('Merchant is no longer available', 400);
        }

        if (!$merchant['is_open']) {
            ob_clean();
            ResponseHandler::error('Merchant is currently closed', 400);
        }

        // Prepare reorder data
        $reorderData = [
            'merchant_id' => $items[0]['merchant_id'],
            'items' => [],
            'delivery_address' => $items[0]['delivery_address'],
            'special_instructions' => $items[0]['special_instructions'],
            'payment_method' => $items[0]['payment_method']
        ];

        foreach ($items as $item) {
            $reorderData['items'][] = [
                'name' => $item['item_name'],
                'quantity' => (int)$item['quantity'],
                'price' => (float)$item['unit_price']
            ];
        }

        // Call createOrder function
        createOrder($conn, $reorderData, $userId);
        
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Failed to reorder: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET LATEST ACTIVE ORDER (Mobile)
 *********************************/
function getLatestActiveOrder($conn, $userId) {
    try {
        $activeStatuses = ['pending', 'confirmed', 'preparing', 'ready'];
        
        $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));
        
        $sql = "SELECT 
                    o.id,
                    o.order_number,
                    o.status,
                    o.total_amount,
                    o.created_at,
                    o.merchant_id,
                    m.name as merchant_name,
                    m.image_url as merchant_image
                FROM orders o
                LEFT JOIN merchants m ON o.merchant_id = m.id
                WHERE o.user_id = ? 
                AND o.status IN ($placeholders)
                ORDER BY o.created_at DESC
                LIMIT 1";
        
        $params = array_merge([$userId], $activeStatuses);
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            ob_clean();
            ResponseHandler::success(['order' => null, 'message' => 'No active orders']);
            return;
        }
        
        ob_clean();
        ResponseHandler::success([
            'order' => [
                'id' => (int)$order['id'],
                'order_number' => $order['order_number'],
                'status' => $order['status'],
                'merchant_name' => $order['merchant_name'] ?? 'DropX Store',
                'merchant_image' => formatImageUrl($order['merchant_image'], 'merchants'),
                'total_amount' => floatval($order['total_amount']),
                'created_at' => $order['created_at']
            ]
        ]);
        
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Failed to get latest order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * UPDATE ORDER (Mobile)
 *********************************/
function updateOrder($conn, $data, $userId) {
    try {
        $orderId = $data['order_id'] ?? null;

        if (!$orderId) {
            ob_clean();
            ResponseHandler::error('Order ID is required', 400);
        }

        // Check if order exists and belongs to user
        $checkStmt = $conn->prepare(
            "SELECT id, status FROM orders
             WHERE id = :order_id AND user_id = :user_id"
        );
        $checkStmt->execute([
            ':order_id' => $orderId,
            ':user_id' => $userId
        ]);
        
        $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            ob_clean();
            ResponseHandler::error('Order not found', 404);
        }

        // Check if order can be modified
        $modifiableStatuses = ['pending'];
        if (!in_array($order['status'], $modifiableStatuses)) {
            ob_clean();
            ResponseHandler::error('Order cannot be modified at this stage', 400);
        }

        // Build update query dynamically
        $updatableFields = ['special_instructions'];
        $updates = [];
        $params = [':order_id' => $orderId];

        foreach ($updatableFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($updates)) {
            ob_clean();
            ResponseHandler::error('No fields to update', 400);
        }

        $updates[] = "updated_at = NOW()";
        $updateSql = "UPDATE orders SET " . implode(', ', $updates) . " WHERE id = :order_id";

        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute($params);

        ob_clean();
        ResponseHandler::success(['order_id' => (int)$orderId], 'Order updated successfully');
        
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Failed to update order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * UPDATE DELIVERY ADDRESS (Mobile)
 *********************************/
function updateDeliveryAddress($conn, $data, $userId) {
    try {
        $orderId = $data['order_id'] ?? null;
        $newAddress = trim($data['delivery_address'] ?? '');

        if (!$orderId || !$newAddress) {
            ob_clean();
            ResponseHandler::error('Order ID and new address are required', 400);
        }

        // Check if order exists and belongs to user
        $checkStmt = $conn->prepare(
            "SELECT id, status FROM orders
             WHERE id = :order_id AND user_id = :user_id"
        );
        $checkStmt->execute([
            ':order_id' => $orderId,
            ':user_id' => $userId
        ]);
        
        $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            ob_clean();
            ResponseHandler::error('Order not found', 404);
        }

        // Check if order can have address changed
        $addressChangeableStatuses = ['pending', 'confirmed'];
        if (!in_array($order['status'], $addressChangeableStatuses)) {
            ob_clean();
            ResponseHandler::error('Delivery address cannot be changed at this stage', 400);
        }

        // Begin transaction
        $conn->beginTransaction();

        // Update address for this order
        $updateStmt = $conn->prepare(
            "UPDATE orders SET 
                delivery_address = :address,
                updated_at = NOW()
             WHERE id = :order_id"
        );
        
        $updateStmt->execute([
            ':order_id' => $orderId,
            ':address' => $newAddress
        ]);

        $conn->commit();

        ob_clean();
        ResponseHandler::success([
            'order_id' => (int)$orderId,
            'new_address' => $newAddress
        ], 'Delivery address updated successfully');

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        ob_clean();
        ResponseHandler::error('Failed to update address: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET ORDER DETAILS (Mobile)
 *********************************/
function getOrderDetails($conn, $orderId, $userId) {
    try {
        // Get order details
        $sql = "SELECT 
                    o.id,
                    o.order_number,
                    o.status,
                    o.subtotal,
                    o.delivery_fee,
                    o.total_amount,
                    o.payment_method,
                    o.payment_status,
                    o.delivery_address,
                    o.special_instructions,
                    o.cancellation_reason,
                    o.created_at,
                    o.updated_at,
                    o.merchant_id,
                    u.full_name as customer_name,
                    u.phone as customer_phone,
                    m.name as merchant_name,
                    m.address as merchant_address,
                    m.phone as merchant_phone,
                    m.image_url as merchant_image,
                    (
                        SELECT GROUP_CONCAT(
                            CONCAT(
                                oi.id, '||', 
                                oi.item_name, '||', 
                                oi.quantity, '||', 
                                oi.unit_price
                            )
                            ORDER BY oi.id SEPARATOR ';;'
                        )
                        FROM order_items oi 
                        WHERE oi.order_id = o.id
                    ) as items_data
                FROM orders o
                LEFT JOIN users u ON o.user_id = u.id
                LEFT JOIN merchants m ON o.merchant_id = m.id
                WHERE o.id = :order_id AND o.user_id = :user_id";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':order_id' => $orderId,
            ':user_id' => $userId
        ]);
        
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            ob_clean();
            ResponseHandler::error('Order not found', 404);
        }

        // Parse items data
        $items = [];
        $itemCount = 0;
        
        if (!empty($order['items_data'])) {
            $itemStrings = explode(';;', $order['items_data']);
            foreach ($itemStrings as $itemString) {
                $parts = explode('||', $itemString);
                if (count($parts) === 4) {
                    $items[] = [
                        'id' => (int)$parts[0],
                        'name' => $parts[1],
                        'quantity' => (int)$parts[2],
                        'price' => (float)$parts[3],
                        'total' => (float)$parts[3] * (int)$parts[2]
                    ];
                    $itemCount += (int)$parts[2];
                }
            }
        }

        // Get status history
        $historyStmt = $conn->prepare(
            "SELECT old_status, new_status, reason, created_at as timestamp
             FROM order_status_history
             WHERE order_id = :order_id
             ORDER BY created_at ASC"
        );
        $historyStmt->execute([':order_id' => $orderId]);
        $statusHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

        // Build response
        $orderData = [
            'id' => (int)$order['id'],
            'order_number' => $order['order_number'],
            'status' => $order['status'],
            'customer_name' => $order['customer_name'] ?? '',
            'customer_phone' => $order['customer_phone'] ?? '',
            'delivery_address' => $order['delivery_address'],
            'total_amount' => (float)$order['total_amount'],
            'delivery_fee' => (float)$order['delivery_fee'],
            'subtotal' => (float)$order['subtotal'],
            'items' => $items,
            'item_count' => $itemCount,
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at'],
            'payment_method' => $order['payment_method'] ?? 'cash',
            'payment_status' => $order['payment_status'] ?? 'pending',
            'merchant' => [
                'id' => $order['merchant_id'] ? (int)$order['merchant_id'] : null,
                'name' => $order['merchant_name'] ?? 'DropX Store',
                'address' => $order['merchant_address'] ?? '',
                'phone' => $order['merchant_phone'] ?? '',
                'image' => formatImageUrl($order['merchant_image'], 'merchants')
            ],
            'special_instructions' => $order['special_instructions'] ?? '',
            'cancellation_reason' => $order['cancellation_reason'] ?? '',
            'status_history' => $statusHistory
        ];

        ob_clean();
        ResponseHandler::success(['order' => $orderData]);
        
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Failed to get order details: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET ORDERS LIST (Legacy - Mobile Compatible)
 *********************************/
function getOrdersList($conn, $userId) {
    try {
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $status = $_GET['status'] ?? 'all';
        $orderNumber = $_GET['order_number'] ?? '';

        // Build WHERE clause
        $whereConditions = ["o.user_id = :user_id"];
        $params = [':user_id' => $userId];

        if ($status !== 'all') {
            $whereConditions[] = "o.status = :status";
            $params[':status'] = $status;
        }

        if ($orderNumber) {
            $whereConditions[] = "o.order_number LIKE :order_number";
            $params[':order_number'] = "%$orderNumber%";
        }

        $whereClause = "WHERE " . implode(" AND ", $whereConditions);

        // Get total count
        $countSql = "SELECT COUNT(DISTINCT o.id) as total FROM orders o $whereClause";
        $countStmt = $conn->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Main query
        $sql = "SELECT 
                    o.id,
                    o.order_number,
                    o.status,
                    o.total_amount,
                    o.created_at,
                    o.merchant_id,
                    m.name as merchant_name,
                    m.image_url as merchant_image
                FROM orders o
                LEFT JOIN merchants m ON o.merchant_id = m.id
                $whereClause
                ORDER BY o.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $conn->prepare($sql);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format orders
        $formattedOrders = [];
        foreach ($orders as $order) {
            $formattedOrders[] = [
                'id' => (int)$order['id'],
                'order_number' => $order['order_number'],
                'status' => $order['status'],
                'total_amount' => (float)$order['total_amount'],
                'created_at' => $order['created_at'],
                'merchant_name' => $order['merchant_name'] ?? 'DropX Store',
                'merchant_image' => formatImageUrl($order['merchant_image'], 'merchants')
            ];
        }

        ob_clean();
        ResponseHandler::success([
            'orders' => $formattedOrders,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total_items' => (int)$totalCount,
                'total_pages' => ceil($totalCount / $limit)
            ]
        ]);
        
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Failed to get orders: ' . $e->getMessage(), 500);
    }
}

/**
 * Format image URL
 */
function formatImageUrl($path, $type = '') {
    global $baseUrl;
    
    if (empty($path)) {
        return '';
    }
    
    if (strpos($path, 'http') === 0) {
        return $path;
    }
    
    $folder = '';
    switch ($type) {
        case 'merchants':
            $folder = 'uploads/merchants';
            break;
        default:
            $folder = 'uploads';
    }
    
    return rtrim($baseUrl, '/') . '/' . $folder . '/' . ltrim($path, '/');
}
?>