<?php
/*********************************
 * CORS Configuration
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Session-Token, X-App-Version, X-Platform");
header("Access-Control-Max-Age: 3600");
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

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
 * AUTHENTICATION CHECK - SIMPLIFIED
 *********************************/
function checkAuthentication() {
    // Check for session token header first
    $sessionToken = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? null;
    
    if ($sessionToken) {
        session_id($sessionToken);
        session_start();
    }
    
    // Simple check - same as auth.php
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['logged_in'])) {
        return $_SESSION['user_id'];
    }
    return null;
}

/*********************************
 * BASE URL CONFIGURATION
 *********************************/
$baseUrl = "https://dropx12-production.up.railway.app";

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Get action from various sources
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? $_GET['action'] ?? '';

    // Check authentication first for all requests
    $userId = checkAuthentication();
    if (!$userId) {
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
        ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500, 'SERVER_ERROR');
}

/*********************************
 * GET ACTIONS HANDLER
 *********************************/
function handleGetActions($action, $input, $userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    switch ($action) {
        case 'get_orders':
            handleGetOrders($conn, $input, $userId);
            break;
        case 'get_order':
            $orderId = $input['order_id'] ?? $_GET['order_id'] ?? '';
            if ($orderId) {
                getOrderDetails($conn, $orderId, $userId);
            } else {
                ResponseHandler::error('Order ID required', 400);
            }
            break;
        case 'get_groups':
            getOrderGroupsList($conn, $userId);
            break;
        case 'get_group':
            $groupId = $input['group_id'] ?? $_GET['group_id'] ?? '';
            if ($groupId) {
                getOrderGroupDetails($conn, $groupId, $userId);
            } else {
                ResponseHandler::error('Group ID required', 400);
            }
            break;
        case 'latest_active':
            getLatestActiveOrder($conn, $userId);
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * POST ACTIONS HANDLER
 *********************************/
function handlePostActions($action, $input, $userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    switch ($action) {
        case 'create_order':
        case 'create_multi_merchant_order':
            createMultiMerchantOrder($conn, $input, $userId);
            break;
        case 'cancel_order':
            cancelOrder($conn, $input, $userId);
            break;
        case 'cancel_order_group':
            cancelOrderGroup($conn, $input, $userId);
            break;
        case 'reorder':
            reorder($conn, $input, $userId);
            break;
        case 'reorder_group':
            reorderGroup($conn, $input, $userId);
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * GET REQUESTS (Legacy)
 *********************************/
function handleGetRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $orderId = $_GET['id'] ?? null;
    $orderGroupId = $_GET['group_id'] ?? null;
    $view = $_GET['view'] ?? 'orders'; // 'orders', 'groups', or 'group_details'
    
    if ($orderGroupId && $view === 'group_details') {
        getOrderGroupDetails($conn, $orderGroupId, $userId);
    } elseif ($orderId) {
        getOrderDetails($conn, $orderId, $userId);
    } elseif ($view === 'groups') {
        getOrderGroupsList($conn, $userId);
    } else {
        getOrdersList($conn, $userId);
    }
}

/*********************************
 * POST REQUESTS (Legacy)
 *********************************/
function handlePostRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'create_order':
        case 'create_multi_merchant_order':
            createMultiMerchantOrder($conn, $input, $userId);
            break;
        case 'cancel_order':
            cancelOrder($conn, $input, $userId);
            break;
        case 'cancel_order_group':
            cancelOrderGroup($conn, $input, $userId);
            break;
        case 'reorder':
            reorder($conn, $input, $userId);
            break;
        case 'reorder_group':
            reorderGroup($conn, $input, $userId);
            break;
        case 'latest_active':
            getLatestActiveOrder($conn, $userId);
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * PUT REQUESTS
 *********************************/
function handlePutRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
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
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * GET ORDERS HANDLER (Mobile)
 *********************************/
function handleGetOrders($conn, $input, $userId) {
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
                o.order_group_id,
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

    // Format orders
    $formattedOrders = [];
    foreach ($orders as $order) {
        $formattedOrders[] = [
            'id' => $order['id'],
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
            'merchant_id' => $order['merchant_id'],
            'merchant_image' => formatImageUrl($order['merchant_image'], 'merchants'),
            'special_instructions' => $order['special_instructions'] ?? '',
            'updated_at' => $order['updated_at'],
            'order_group_id' => $order['order_group_id'],
            'is_multi_merchant' => !empty($order['order_group_id'])
        ];
    }

    ResponseHandler::success([
        'orders' => $formattedOrders,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => (int)$totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ]);
}

/*********************************
 * CREATE MULTI-MERCHANT ORDER
 *********************************/
function createMultiMerchantOrder($conn, $data, $userId) {
    // Validate required data
    $requiredFields = ['items', 'delivery_address'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            ResponseHandler::error("Missing required field: $field", 400);
        }
    }

    // Group items by merchant
    $itemsByMerchant = [];
    $items = $data['items'];
    
    if (!is_array($items) || empty($items)) {
        ResponseHandler::error('No items in order', 400);
    }

    // Validate and group items by merchant
    foreach ($items as $item) {
        if (empty($item['merchant_id']) || empty($item['name']) || empty($item['quantity']) || empty($item['price'])) {
            ResponseHandler::error('Invalid item data - each item must have merchant_id, name, quantity, and price', 400);
        }
        
        $merchantId = $item['merchant_id'];
        if (!isset($itemsByMerchant[$merchantId])) {
            $itemsByMerchant[$merchantId] = [];
        }
        $itemsByMerchant[$merchantId][] = $item;
    }

    // Validate each merchant exists and is open
    $merchantIds = array_keys($itemsByMerchant);
    $placeholders = implode(',', array_fill(0, count($merchantIds), '?'));
    
    $merchantStmt = $conn->prepare(
        "SELECT id, name, delivery_fee, is_open, minimum_order, 
                preparation_time_minutes, address
         FROM merchants 
         WHERE id IN ($placeholders) AND is_active = 1"
    );
    
    $merchantStmt->execute($merchantIds);
    
    $merchants = [];
    while ($row = $merchantStmt->fetch(PDO::FETCH_ASSOC)) {
        $merchants[$row['id']] = $row;
    }

    // Check if all merchants are valid and open
    foreach ($merchantIds as $merchantId) {
        if (!isset($merchants[$merchantId])) {
            ResponseHandler::error("Merchant with ID $merchantId not found or inactive", 404);
        }
        if (!$merchants[$merchantId]['is_open']) {
            ResponseHandler::error("Merchant {$merchants[$merchantId]['name']} is currently closed", 400);
        }
    }

    // Calculate totals per merchant and validate minimum orders
    $merchantTotals = [];
    $overallSubtotal = 0;
    $overallDeliveryFee = 0;
    
    foreach ($itemsByMerchant as $merchantId => $merchantItems) {
        $merchantSubtotal = 0;
        foreach ($merchantItems as $item) {
            $merchantSubtotal += $item['price'] * $item['quantity'];
        }
        
        // Check minimum order
        if ($merchantSubtotal < $merchants[$merchantId]['minimum_order']) {
            ResponseHandler::error(
                "Order for {$merchants[$merchantId]['name']} must be at least $" . 
                number_format($merchants[$merchantId]['minimum_order'], 2), 
                400
            );
        }
        
        $merchantDeliveryFee = $merchants[$merchantId]['delivery_fee'];
        
        $merchantTotals[$merchantId] = [
            'subtotal' => $merchantSubtotal,
            'delivery_fee' => $merchantDeliveryFee,
            'total' => $merchantSubtotal + $merchantDeliveryFee
        ];
        
        $overallSubtotal += $merchantSubtotal;
        $overallDeliveryFee += $merchantDeliveryFee;
    }

    $totalAmount = $overallSubtotal + $overallDeliveryFee;

    // Begin transaction
    $conn->beginTransaction();

    try {
        // Create order group if multiple merchants
        $orderGroupId = null;
        if (count($merchantIds) > 1) {
            $groupSql = "INSERT INTO order_groups (
                user_id, total_amount, status, created_at, updated_at
            ) VALUES (
                :user_id, :total_amount, 'pending', NOW(), NOW()
            )";

            $groupStmt = $conn->prepare($groupSql);
            $groupStmt->execute([
                ':user_id' => $userId,
                ':total_amount' => $totalAmount
            ]);

            $orderGroupId = $conn->lastInsertId();
        }

        // Create individual orders for each merchant
        $orderIds = [];
        $orderNumbers = [];

        foreach ($itemsByMerchant as $merchantId => $merchantItems) {
            // Generate unique order number
            $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

            // Create order
            $orderSql = "INSERT INTO orders (
                order_number, user_id, merchant_id, order_group_id, subtotal, 
                delivery_fee, total_amount, payment_method, delivery_address, 
                special_instructions, status, created_at, updated_at
            ) VALUES (
                :order_number, :user_id, :merchant_id, :order_group_id, :subtotal,
                :delivery_fee, :total_amount, :payment_method, :delivery_address,
                :special_instructions, 'pending', NOW(), NOW()
            )";

            $orderStmt = $conn->prepare($orderSql);
            $orderStmt->execute([
                ':order_number' => $orderNumber,
                ':user_id' => $userId,
                ':merchant_id' => $merchantId,
                ':order_group_id' => $orderGroupId,
                ':subtotal' => $merchantTotals[$merchantId]['subtotal'],
                ':delivery_fee' => $merchantTotals[$merchantId]['delivery_fee'],
                ':total_amount' => $merchantTotals[$merchantId]['total'],
                ':payment_method' => $data['payment_method'] ?? 'Cash on Delivery',
                ':delivery_address' => $data['delivery_address'],
                ':special_instructions' => $data['special_instructions'] ?? ''
            ]);

            $orderId = $conn->lastInsertId();
            $orderIds[] = $orderId;
            $orderNumbers[] = $orderNumber;

            // Create order items for this merchant
            $itemSql = "INSERT INTO order_items (
                order_id, item_name, quantity, unit_price, total_price, created_at
            ) VALUES (
                :order_id, :item_name, :quantity, :unit_price, :total_price, NOW()
            )";

            $itemStmt = $conn->prepare($itemSql);
            foreach ($merchantItems as $item) {
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
        }

        // Update user's total orders
        $updateUserSql = "UPDATE users SET total_orders = total_orders + :order_count WHERE id = :user_id";
        $updateUserStmt = $conn->prepare($updateUserSql);
        $updateUserStmt->execute([
            ':user_id' => $userId,
            ':order_count' => count($orderIds)
        ]);

        // Commit transaction
        $conn->commit();

        $response = [
            'order_ids' => $orderIds,
            'order_numbers' => $orderNumbers,
            'merchant_count' => count($merchants),
            'totals' => [
                'subtotal' => $overallSubtotal,
                'delivery_fee' => $overallDeliveryFee,
                'total' => $totalAmount
            ],
            'message' => count($merchantIds) > 1 
                ? 'Multi-merchant order created successfully' 
                : 'Order created successfully'
        ];

        if ($orderGroupId) {
            $response['order_group_id'] = $orderGroupId;
        }

        ResponseHandler::success($response, 201);

    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to create order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CANCEL ORDER
 *********************************/
function cancelOrder($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;
    $reason = trim($data['reason'] ?? '');

    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
    }

    // Check if order exists and belongs to user
    $checkStmt = $conn->prepare(
        "SELECT o.id, o.status, o.order_group_id
         FROM orders o
         WHERE o.id = :order_id AND o.user_id = :user_id"
    );
    $checkStmt->execute([
        ':order_id' => $orderId,
        ':user_id' => $userId
    ]);
    
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ResponseHandler::error('Order not found', 404);
    }

    // Check if order can be cancelled
    $cancellableStatuses = ['pending', 'confirmed'];
    if (!in_array($order['status'], $cancellableStatuses)) {
        ResponseHandler::error('Order cannot be cancelled at this stage', 400);
    }

    // Begin transaction
    $conn->beginTransaction();

    try {
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

        ResponseHandler::success([
            'order_id' => $orderId,
            'message' => 'Order cancelled successfully'
        ]);

    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to cancel order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CANCEL ORDER GROUP
 *********************************/
function cancelOrderGroup($conn, $data, $userId) {
    $orderGroupId = $data['order_group_id'] ?? null;
    $reason = trim($data['reason'] ?? '');

    if (!$orderGroupId) {
        ResponseHandler::error('Order group ID is required', 400);
    }

    // Check if order group exists and belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id, status FROM order_groups 
         WHERE id = :group_id AND user_id = :user_id"
    );
    $checkStmt->execute([
        ':group_id' => $orderGroupId,
        ':user_id' => $userId
    ]);
    
    $group = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        ResponseHandler::error('Order group not found', 404);
    }

    // Get all orders in this group
    $ordersStmt = $conn->prepare(
        "SELECT id, status FROM orders WHERE order_group_id = :group_id"
    );
    $ordersStmt->execute([':group_id' => $orderGroupId]);
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

    // Begin transaction
    $conn->beginTransaction();

    try {
        // Update order group status
        $updateGroupStmt = $conn->prepare(
            "UPDATE order_groups SET 
                status = 'cancelled',
                updated_at = NOW()
             WHERE id = :group_id"
        );
        
        $updateGroupStmt->execute([':group_id' => $orderGroupId]);

        // Cancel each order in the group
        foreach ($orders as $order) {
            // Check if order can be cancelled
            if (in_array($order['status'], ['pending', 'confirmed'])) {
                $updateOrderStmt = $conn->prepare(
                    "UPDATE orders SET 
                        status = 'cancelled',
                        cancellation_reason = :reason,
                        updated_at = NOW()
                     WHERE id = :order_id"
                );
                
                $updateOrderStmt->execute([
                    ':order_id' => $order['id'],
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
                    ':order_id' => $order['id'],
                    ':old_status' => $order['status'],
                    ':new_status' => 'cancelled',
                    ':changed_by' => 'user',
                    ':changed_by_id' => $userId,
                    ':reason' => $reason
                ]);
            }
        }

        $conn->commit();

        ResponseHandler::success([
            'order_group_id' => $orderGroupId,
            'message' => 'Order group cancelled successfully'
        ]);

    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to cancel order group: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * REORDER
 *********************************/
function reorder($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;

    if (!$orderId) {
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
        ResponseHandler::error('Order not found', 404);
    }

    // Check if merchant is still active
    $merchantStmt = $conn->prepare(
        "SELECT id, is_open, is_active FROM merchants WHERE id = ?"
    );
    $merchantStmt->execute([$items[0]['merchant_id']]);
    $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant || !$merchant['is_active']) {
        ResponseHandler::error('Merchant is no longer available', 400);
    }

    if (!$merchant['is_open']) {
        ResponseHandler::error('Merchant is currently closed', 400);
    }

    // Prepare reorder data
    $reorderData = [
        'items' => [],
        'delivery_address' => $items[0]['delivery_address'],
        'special_instructions' => $items[0]['special_instructions'],
        'payment_method' => $items[0]['payment_method']
    ];

    foreach ($items as $item) {
        $reorderData['items'][] = [
            'merchant_id' => $item['merchant_id'],
            'name' => $item['item_name'],
            'quantity' => (int)$item['quantity'],
            'price' => (float)$item['unit_price']
        ];
    }

    // Call createMultiMerchantOrder function
    createMultiMerchantOrder($conn, $reorderData, $userId);
}

/*********************************
 * REORDER GROUP
 *********************************/
function reorderGroup($conn, $data, $userId) {
    $orderGroupId = $data['order_group_id'] ?? null;

    if (!$orderGroupId) {
        ResponseHandler::error('Order group ID is required', 400);
    }

    // Get original order group details with all items
    $sql = "SELECT 
                o.merchant_id,
                o.delivery_address,
                o.special_instructions,
                o.payment_method,
                oi.item_name,
                oi.quantity,
                oi.unit_price
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            WHERE o.order_group_id = :group_id AND o.user_id = :user_id";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':group_id' => $orderGroupId,
        ':user_id' => $userId
    ]);
    
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        ResponseHandler::error('Order group not found', 404);
    }

    // Group items by merchant for the new order
    $itemsByMerchant = [];
    $deliveryAddress = '';
    $specialInstructions = '';
    $paymentMethod = '';

    foreach ($items as $item) {
        $merchantId = $item['merchant_id'];
        if (!isset($itemsByMerchant[$merchantId])) {
            $itemsByMerchant[$merchantId] = [];
        }
        
        $itemsByMerchant[$merchantId][] = [
            'merchant_id' => $merchantId,
            'name' => $item['item_name'],
            'quantity' => (int)$item['quantity'],
            'price' => (float)$item['unit_price']
        ];
        
        $deliveryAddress = $item['delivery_address'];
        $specialInstructions = $item['special_instructions'];
        $paymentMethod = $item['payment_method'];
    }

    // Prepare reorder data
    $reorderData = [
        'items' => [],
        'delivery_address' => $deliveryAddress,
        'special_instructions' => $specialInstructions,
        'payment_method' => $paymentMethod
    ];

    // Flatten items array
    foreach ($itemsByMerchant as $merchantItems) {
        foreach ($merchantItems as $item) {
            $reorderData['items'][] = $item;
        }
    }

    // Call createMultiMerchantOrder function
    createMultiMerchantOrder($conn, $reorderData, $userId);
}

/*********************************
 * GET LATEST ACTIVE ORDER
 *********************************/
function getLatestActiveOrder($conn, $userId) {
    $activeStatuses = ['pending', 'confirmed', 'preparing', 'ready'];
    
    $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));
    
    $sql = "SELECT 
                o.id,
                o.order_number,
                o.status,
                o.total_amount,
                o.created_at,
                m.name as merchant_name,
                m.image_url as merchant_image,
                o.order_group_id
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
        ResponseHandler::success(['order' => null, 'message' => 'No active orders']);
        return;
    }
    
    ResponseHandler::success([
        'order' => [
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'status' => $order['status'],
            'merchant_name' => $order['merchant_name'],
            'merchant_image' => formatImageUrl($order['merchant_image'], 'merchants'),
            'total_amount' => floatval($order['total_amount']),
            'created_at' => $order['created_at'],
            'order_group_id' => $order['order_group_id']
        ]
    ]);
}

/*********************************
 * UPDATE ORDER
 *********************************/
function updateOrder($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;

    if (!$orderId) {
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
        ResponseHandler::error('Order not found', 404);
    }

    // Check if order can be modified
    $modifiableStatuses = ['pending'];
    if (!in_array($order['status'], $modifiableStatuses)) {
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
        ResponseHandler::error('No fields to update', 400);
    }

    $updates[] = "updated_at = NOW()";
    $updateSql = "UPDATE orders SET " . implode(', ', $updates) . " WHERE id = :order_id";

    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute($params);

    ResponseHandler::success([], 'Order updated successfully');
}

/*********************************
 * UPDATE DELIVERY ADDRESS
 *********************************/
function updateDeliveryAddress($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;
    $newAddress = trim($data['delivery_address'] ?? '');

    if (!$orderId || !$newAddress) {
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
        ResponseHandler::error('Order not found', 404);
    }

    // Check if order can have address changed
    $addressChangeableStatuses = ['pending', 'confirmed'];
    if (!in_array($order['status'], $addressChangeableStatuses)) {
        ResponseHandler::error('Delivery address cannot be changed at this stage', 400);
    }

    // Begin transaction
    $conn->beginTransaction();

    try {
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

        ResponseHandler::success([
            'order_id' => $orderId,
            'new_address' => $newAddress
        ], 'Delivery address updated successfully');

    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to update address: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET ORDER DETAILS
 *********************************/
function getOrderDetails($conn, $orderId, $userId) {
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
                o.order_group_id,
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
                    'id' => $parts[0],
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
        'id' => $order['id'],
        'order_number' => $order['order_number'],
        'status' => $order['status'],
        'customer_name' => $order['customer_name'],
        'customer_phone' => $order['customer_phone'],
        'delivery_address' => $order['delivery_address'],
        'total_amount' => (float)$order['total_amount'],
        'delivery_fee' => (float)$order['delivery_fee'],
        'subtotal' => (float)$order['subtotal'],
        'items' => $items,
        'item_count' => $itemCount,
        'created_at' => $order['created_at'],
        'updated_at' => $order['updated_at'],
        'payment_method' => $order['payment_method'],
        'payment_status' => $order['payment_status'],
        'merchant' => [
            'id' => $order['merchant_id'],
            'name' => $order['merchant_name'],
            'address' => $order['merchant_address'],
            'phone' => $order['merchant_phone'],
            'image' => formatImageUrl($order['merchant_image'], 'merchants')
        ],
        'special_instructions' => $order['special_instructions'],
        'cancellation_reason' => $order['cancellation_reason'],
        'status_history' => $statusHistory,
        'order_group_id' => $order['order_group_id']
    ];

    ResponseHandler::success(['order' => $orderData]);
}

/*********************************
 * GET ORDER GROUPS LIST
 *********************************/
function getOrderGroupsList($conn, $userId) {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $status = $_GET['status'] ?? 'all';
    
    // Build WHERE clause
    $whereConditions = ["og.user_id = :user_id"];
    $params = [':user_id' => $userId];
    
    if ($status !== 'all') {
        $whereConditions[] = "og.status = :status";
        $params[':status'] = $status;
    }
    
    $whereClause = "WHERE " . implode(" AND ", $whereConditions);
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM order_groups og $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get order groups
    $sql = "SELECT 
                og.id,
                og.total_amount,
                og.status,
                og.created_at,
                og.updated_at,
                COUNT(DISTINCT o.id) as order_count,
                COUNT(DISTINCT o.merchant_id) as merchant_count,
                GROUP_CONCAT(DISTINCT m.name SEPARATOR '||') as merchant_names,
                GROUP_CONCAT(DISTINCT m.id SEPARATOR '||') as merchant_ids,
                GROUP_CONCAT(DISTINCT m.image_url SEPARATOR '||') as merchant_images
            FROM order_groups og
            LEFT JOIN orders o ON og.id = o.order_group_id
            LEFT JOIN merchants m ON o.merchant_id = m.id
            $whereClause
            GROUP BY og.id
            ORDER BY og.created_at DESC
            LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    if ($status !== 'all') {
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format groups
    $formattedGroups = [];
    foreach ($groups as $group) {
        // Parse merchant data
        $merchantNames = explode('||', $group['merchant_names'] ?? '');
        $merchantIds = explode('||', $group['merchant_ids'] ?? '');
        $merchantImages = explode('||', $group['merchant_images'] ?? '');
        
        $merchants = [];
        for ($i = 0; $i < count($merchantNames); $i++) {
            if (!empty($merchantNames[$i])) {
                $merchants[] = [
                    'id' => $merchantIds[$i] ?? '',
                    'name' => $merchantNames[$i],
                    'image' => formatImageUrl($merchantImages[$i] ?? '', 'merchants')
                ];
            }
        }
        
        $formattedGroups[] = [
            'id' => $group['id'],
            'order_count' => (int)$group['order_count'],
            'merchant_count' => (int)$group['merchant_count'],
            'merchants' => $merchants,
            'merchant_names' => implode(', ', $merchantNames),
            'total_amount' => (float)$group['total_amount'],
            'status' => $group['status'],
            'created_at' => $group['created_at'],
            'updated_at' => $group['updated_at']
        ];
    }
    
    ResponseHandler::success([
        'groups' => $formattedGroups,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => (int)$totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ]);
}

/*********************************
 * GET ORDER GROUP DETAILS
 *********************************/
function getOrderGroupDetails($conn, $groupId, $userId) {
    // Get order group info
    $groupSql = "SELECT * FROM order_groups 
                 WHERE id = :group_id AND user_id = :user_id";
    
    $groupStmt = $conn->prepare($groupSql);
    $groupStmt->execute([
        ':group_id' => $groupId,
        ':user_id' => $userId
    ]);
    
    $group = $groupStmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        ResponseHandler::error('Order group not found', 404);
    }

    // Get all orders in this group
    $ordersSql = "SELECT 
                    o.*,
                    m.name as merchant_name,
                    m.address as merchant_address,
                    m.phone as merchant_phone,
                    m.image_url as merchant_image,
                    (
                        SELECT COUNT(*) 
                        FROM order_items 
                        WHERE order_id = o.id
                    ) as item_count
                FROM orders o
                LEFT JOIN merchants m ON o.merchant_id = m.id
                WHERE o.order_group_id = :group_id
                ORDER BY o.created_at";

    $ordersStmt = $conn->prepare($ordersSql);
    $ordersStmt->execute([':group_id' => $groupId]);
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get user info
    $userStmt = $conn->prepare(
        "SELECT full_name, phone FROM users WHERE id = :user_id"
    );
    $userStmt->execute([':user_id' => $userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    // Format each order
    $formattedOrders = [];
    $groupSubtotal = 0;
    $groupDeliveryFee = 0;
    $groupTotal = 0;
    
    foreach ($orders as $order) {
        // Get items for this order
        $itemsStmt = $conn->prepare(
            "SELECT 
                id,
                item_name as name,
                quantity,
                unit_price as price,
                total_price as total
             FROM order_items 
             WHERE order_id = :order_id"
        );
        $itemsStmt->execute([':order_id' => $order['id']]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $formattedOrders[] = [
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'status' => $order['status'],
            'merchant' => [
                'id' => $order['merchant_id'],
                'name' => $order['merchant_name'],
                'address' => $order['merchant_address'],
                'phone' => $order['merchant_phone'],
                'image' => formatImageUrl($order['merchant_image'], 'merchants')
            ],
            'subtotal' => (float)$order['subtotal'],
            'delivery_fee' => (float)$order['delivery_fee'],
            'total_amount' => (float)$order['total_amount'],
            'items' => $items,
            'item_count' => (int)$order['item_count'],
            'payment_method' => $order['payment_method'],
            'payment_status' => $order['payment_status'],
            'special_instructions' => $order['special_instructions'],
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at']
        ];
        
        $groupSubtotal += (float)$order['subtotal'];
        $groupDeliveryFee += (float)$order['delivery_fee'];
        $groupTotal += (float)$order['total_amount'];
    }
    
    $response = [
        'group' => [
            'id' => $group['id'],
            'status' => $group['status'],
            'created_at' => $group['created_at'],
            'updated_at' => $group['updated_at'],
            'customer' => [
                'name' => $user['full_name'] ?? '',
                'phone' => $user['phone'] ?? ''
            ],
            'totals' => [
                'subtotal' => $groupSubtotal,
                'delivery_fee' => $groupDeliveryFee,
                'total' => $groupTotal
            ],
            'order_count' => count($formattedOrders),
            'merchant_count' => count(array_unique(array_column($orders, 'merchant_id')))
        ],
        'orders' => $formattedOrders
    ];
    
    ResponseHandler::success($response);
}

/*********************************
 * GET ORDERS LIST (Legacy)
 *********************************/
function getOrdersList($conn, $userId) {
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
                o.order_group_id,
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
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'status' => $order['status'],
            'total_amount' => (float)$order['total_amount'],
            'created_at' => $order['created_at'],
            'merchant_name' => $order['merchant_name'] ?? 'DropX Store',
            'merchant_image' => formatImageUrl($order['merchant_image'], 'merchants'),
            'order_group_id' => $order['order_group_id'],
            'is_multi_merchant' => !empty($order['order_group_id'])
        ];
    }

    ResponseHandler::success([
        'orders' => $formattedOrders,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => (int)$totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ]);
}

/*********************************
 * HELPER FUNCTIONS
 *********************************/

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