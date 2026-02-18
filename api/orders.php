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

    // Check authentication first for all requests
    $userId = checkAuthentication();
    if (!$userId) {
        ResponseHandler::error('Authentication required. Please login.', 401);
    }

    // Route authenticated requests
    if ($method === 'GET') {
        handleGetRequest($userId);
    } elseif ($method === 'POST') {
        handlePostRequest($userId);
    } elseif ($method === 'PUT') {
        handlePutRequest($userId);
    } else {
        ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET REQUESTS
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
 * GET ORDERS LIST - MATCHING FLUTTER EXPECTATIONS
 *********************************/
function getOrdersList($conn, $userId) {
    // Get query parameters
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $status = $_GET['status'] ?? 'all';
    $orderNumber = $_GET['order_number'] ?? '';
    $startDate = $_GET['start_date'] ?? '';
    $endDate = $_GET['end_date'] ?? '';
    $sortBy = $_GET['sort_by'] ?? 'created_at';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');

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

    // Validate sort options
    $allowedSortColumns = ['created_at', 'total_amount', 'order_number'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'created_at';
    $sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';

    // Get total count for pagination
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
                d.name as driver_name,
                d.phone as driver_phone,
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
                ) as items_data,
                (
                    SELECT status 
                    FROM order_tracking 
                    WHERE order_id = o.id 
                    ORDER BY created_at DESC 
                    LIMIT 1
                ) as tracking_status,
                (
                    SELECT estimated_delivery 
                    FROM order_tracking 
                    WHERE order_id = o.id 
                    ORDER BY created_at DESC 
                    LIMIT 1
                ) as estimated_delivery_time
            FROM orders o
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            $whereClause
            ORDER BY o.$sortBy $sortOrder
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

    // Get user info for customer details
    $userStmt = $conn->prepare(
        "SELECT full_name, phone, email FROM users WHERE id = :user_id"
    );
    $userStmt->execute([':user_id' => $userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    // Format orders data to match Flutter expectations
    $formattedOrders = [];
    foreach ($orders as $order) {
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

        // Format merchant image URL
        $merchantImage = '';
        if (!empty($order['merchant_image'])) {
            if (strpos($order['merchant_image'], 'http') === 0) {
                $merchantImage = $order['merchant_image'];
            } else {
                global $baseUrl;
                $merchantImage = rtrim($baseUrl ?? '', '/') . '/uploads/merchants/' . ltrim($order['merchant_image'], '/');
            }
        }

        // Build order object matching Flutter's expectations
        $formattedOrders[] = [
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'status' => $order['status'],
            'order_type' => 'Food Delivery',
            'customer_name' => $user['full_name'] ?? 'Customer',
            'customer_phone' => $user['phone'] ?? '',
            'delivery_address' => $order['delivery_address'],
            'total_amount' => (float)$order['total_amount'],
            'delivery_fee' => (float)$order['delivery_fee'],
            'subtotal' => (float)$order['subtotal'],
            'items' => $items,
            'item_count' => $itemCount,
            'created_at' => $order['created_at'],
            'estimated_delivery_time' => $order['estimated_delivery_time'],
            'payment_method' => $order['payment_method'] ?? 'cash',
            'payment_status' => $order['payment_status'] ?? 'pending',
            'merchant_name' => $order['merchant_name'] ?? 'DropX Store',
            'merchant_id' => $order['merchant_id'],
            'merchant_image' => $merchantImage,
            'driver_name' => $order['driver_name'] ?? '',
            'driver_phone' => $order['driver_phone'] ?? '',
            'special_instructions' => $order['special_instructions'] ?? '',
            'updated_at' => $order['updated_at'],
            'order_group_id' => $order['order_group_id']
        ];
    }

    // Return in the format Flutter expects
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
                og.*,
                COUNT(o.id) as order_count,
                GROUP_CONCAT(DISTINCT m.name SEPARATOR ', ') as merchant_names,
                MIN(o.created_at) as first_order_time,
                MAX(o.created_at) as last_order_time
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
        // Get order statuses for this group
        $statusStmt = $conn->prepare(
            "SELECT status, COUNT(*) as count 
             FROM orders 
             WHERE order_group_id = :group_id 
             GROUP BY status"
        );
        $statusStmt->execute([':group_id' => $group['id']]);
        $statuses = $statusStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Determine group status
        $groupStatus = $group['status'];
        if ($groupStatus === 'pending') {
            // Check if any orders are still pending
            if (isset($statuses['cancelled']) && count($statuses) === 1) {
                $groupStatus = 'cancelled';
            } elseif (isset($statuses['delivered']) && count($statuses) === 1) {
                $groupStatus = 'completed';
            } elseif (isset($statuses['pending']) || isset($statuses['confirmed']) || isset($statuses['preparing'])) {
                $groupStatus = 'in_progress';
            }
        }
        
        $formattedGroups[] = [
            'id' => $group['id'],
            'order_count' => (int)$group['order_count'],
            'merchant_names' => $group['merchant_names'],
            'total_amount' => (float)$group['total_amount'],
            'status' => $groupStatus,
            'created_at' => $group['created_at'],
            'updated_at' => $group['updated_at'],
            'first_order_time' => $group['first_order_time'],
            'last_order_time' => $group['last_order_time'],
            'order_statuses' => $statuses
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
    $groupSql = "SELECT 
                    og.*
                FROM order_groups og
                WHERE og.id = :group_id AND og.user_id = :user_id";
    
    $groupStmt = $conn->prepare($groupSql);
    $groupStmt->execute([
        ':group_id' => $groupId,
        ':user_id' => $userId
    ]);
    
    $group = $groupStmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        ResponseHandler::error('Order group not found', 404);
    }

    // Get all orders in this group with their details
    $ordersSql = "SELECT 
                    o.*,
                    m.name as merchant_name,
                    m.address as merchant_address,
                    m.phone as merchant_phone,
                    m.image_url as merchant_image,
                    d.name as driver_name,
                    d.phone as driver_phone,
                    d.image_url as driver_image,
                    (
                        SELECT estimated_delivery 
                        FROM order_tracking 
                        WHERE order_id = o.id 
                        ORDER BY created_at DESC 
                        LIMIT 1
                    ) as estimated_delivery,
                    (
                        SELECT status 
                        FROM order_tracking 
                        WHERE order_id = o.id 
                        ORDER BY created_at DESC 
                        LIMIT 1
                    ) as tracking_status
                FROM orders o
                LEFT JOIN merchants m ON o.merchant_id = m.id
                LEFT JOIN drivers d ON o.driver_id = d.id
                WHERE o.order_group_id = :group_id
                ORDER BY o.created_at";

    $ordersStmt = $conn->prepare($ordersSql);
    $ordersStmt->execute([':group_id' => $groupId]);
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get user info
    $userStmt = $conn->prepare(
        "SELECT full_name, phone, email FROM users WHERE id = :user_id"
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
        
        $itemCount = 0;
        foreach ($items as $item) {
            $itemCount += $item['quantity'];
        }
        
        // Format merchant image
        $merchantImage = formatImageUrl($order['merchant_image'], $GLOBALS['baseUrl'], 'merchants');
        
        // Format driver image
        $driverImage = formatImageUrl($order['driver_image'], $GLOBALS['baseUrl'], 'drivers');
        
        $formattedOrders[] = [
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'status' => $order['status'],
            'merchant' => [
                'id' => $order['merchant_id'],
                'name' => $order['merchant_name'],
                'address' => $order['merchant_address'],
                'phone' => $order['merchant_phone'],
                'image' => $merchantImage
            ],
            'driver' => $order['driver_name'] ? [
                'name' => $order['driver_name'],
                'phone' => $order['driver_phone'],
                'image' => $driverImage
            ] : null,
            'subtotal' => (float)$order['subtotal'],
            'delivery_fee' => (float)$order['delivery_fee'],
            'total_amount' => (float)$order['total_amount'],
            'items' => $items,
            'item_count' => $itemCount,
            'payment_method' => $order['payment_method'],
            'payment_status' => $order['payment_status'],
            'special_instructions' => $order['special_instructions'],
            'cancellation_reason' => $order['cancellation_reason'],
            'estimated_delivery' => $order['estimated_delivery'],
            'tracking_status' => $order['tracking_status'],
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at']
        ];
        
        $groupSubtotal += (float)$order['subtotal'];
        $groupDeliveryFee += (float)$order['delivery_fee'];
        $groupTotal += (float)$order['total_amount'];
    }
    
    // Determine overall group status
    $statuses = array_column($orders, 'status');
    $groupStatus = $group['status'];
    if ($groupStatus === 'pending') {
        if (count(array_unique($statuses)) === 1 && $statuses[0] === 'delivered') {
            $groupStatus = 'completed';
        } elseif (in_array('cancelled', $statuses) && !in_array('pending', $statuses) && !in_array('confirmed', $statuses)) {
            $groupStatus = 'cancelled';
        } elseif (in_array('pending', $statuses) || in_array('confirmed', $statuses) || in_array('preparing', $statuses)) {
            $groupStatus = 'in_progress';
        }
    }
    
    $response = [
        'group' => [
            'id' => $group['id'],
            'status' => $groupStatus,
            'original_status' => $group['status'],
            'created_at' => $group['created_at'],
            'updated_at' => $group['updated_at'],
            'customer' => [
                'name' => $user['full_name'] ?? '',
                'phone' => $user['phone'] ?? '',
                'email' => $user['email'] ?? ''
            ],
            'totals' => [
                'subtotal' => $groupSubtotal,
                'delivery_fee' => $groupDeliveryFee,
                'total' => $groupTotal
            ],
            'order_count' => count($formattedOrders)
        ],
        'orders' => $formattedOrders
    ];
    
    ResponseHandler::success($response);
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
                o.delivery_address,
                o.special_instructions,
                o.cancellation_reason,
                o.created_at,
                o.updated_at,
                o.order_group_id,
                u.full_name as customer_name,
                u.phone as customer_phone,
                u.email as customer_email,
                m.name as restaurant_name,
                m.address as merchant_address,
                m.phone as merchant_phone,
                m.image_url as merchant_image,
                d.name as driver_name,
                d.phone as driver_phone,
                d.email as driver_email,
                d.image_url as driver_image,
                ot.estimated_delivery,
                ot.location_updates,
                qo.title as quick_order_title,
                qo.image_url as quick_order_image
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.id
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            LEFT JOIN order_tracking ot ON o.id = ot.order_id
            LEFT JOIN quick_orders qo ON o.quick_order_id = qo.id
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

    // Get order items
    $itemsSql = "SELECT 
                    id,
                    item_name as name,
                    quantity,
                    unit_price as price,
                    total_price as total,
                    created_at
                FROM order_items
                WHERE order_id = :order_id
                ORDER BY id";
    
    $itemsStmt = $conn->prepare($itemsSql);
    $itemsStmt->execute([':order_id' => $orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get order tracking history
    $trackingSql = "SELECT 
                        status,
                        estimated_delivery,
                        location_updates,
                        created_at,
                        updated_at
                    FROM order_tracking
                    WHERE order_id = :order_id
                    ORDER BY created_at DESC";
    
    $trackingStmt = $conn->prepare($trackingSql);
    $trackingStmt->execute([':order_id' => $orderId]);
    $tracking = $trackingStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get order status history
    $statusSql = "SELECT 
                    old_status,
                    new_status,
                    changed_by,
                    changed_by_id,
                    reason,
                    notes,
                    created_at as timestamp
                FROM order_status_history
                WHERE order_id = :order_id
                ORDER BY created_at ASC";
    
    $statusStmt = $conn->prepare($statusSql);
    $statusStmt->execute([':order_id' => $orderId]);
    $statusHistory = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get sibling orders in same group if applicable
    $siblingOrders = [];
    if ($order['order_group_id']) {
        $siblingSql = "SELECT 
                            o.id,
                            o.order_number,
                            o.status,
                            m.name as merchant_name
                        FROM orders o
                        LEFT JOIN merchants m ON o.merchant_id = m.id
                        WHERE o.order_group_id = :group_id 
                        AND o.id != :order_id";
        
        $siblingStmt = $conn->prepare($siblingSql);
        $siblingStmt->execute([
            ':group_id' => $order['order_group_id'],
            ':order_id' => $orderId
        ]);
        $siblingOrders = $siblingStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Format the response
    $orderData = formatOrderDetailData($order);
    $orderData['items'] = array_map('formatOrderItemData', $items);
    $orderData['tracking_history'] = array_map('formatTrackingData', $tracking);
    $orderData['status_history'] = $statusHistory;
    $orderData['sibling_orders'] = $siblingOrders;

    ResponseHandler::success([
        'order' => $orderData
    ]);
}

/*********************************
 * POST REQUESTS - UPDATED WITH MULTI-MERCHANT SUPPORT
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
        case 'create_review':
            createOrderReview($conn, $input, $userId);
            break;
        case 'reorder':
            reorder($conn, $input, $userId);
            break;
        case 'reorder_group':
            reorderGroup($conn, $input, $userId);
            break;
        case 'track_order':
            trackOrder($conn, $input, $userId);
            break;
        case 'track_order_group':
            trackOrderGroup($conn, $input, $userId);
            break;
        case 'get_trackable':
            getTrackableOrders($conn, $input, $userId);
            break;
        case 'latest_active':
            getLatestActiveOrder($conn, $userId);
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
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
        "SELECT id, name, delivery_fee, is_open, minimum_order 
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
        // Create order group first
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

        // Create individual orders for each merchant
        $orderIds = [];
        $orderNumbers = [];

        foreach ($itemsByMerchant as $merchantId => $merchantItems) {
            // Generate unique order number for this merchant order
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

            // Create order tracking for this order
            $trackingSql = "INSERT INTO order_tracking (
                order_id, status, estimated_delivery, created_at
            ) VALUES (
                :order_id, :status, :estimated_delivery, NOW()
            )";

            $trackingStmt = $conn->prepare($trackingSql);
            $estimatedDelivery = date('Y-m-d H:i:s', strtotime('+45 minutes'));
            $trackingStmt->execute([
                ':order_id' => $orderId,
                ':status' => 'Order placed',
                ':estimated_delivery' => $estimatedDelivery
            ]);

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

        // Log user activity
        $activitySql = "INSERT INTO user_activities (
            user_id, activity_type, description, created_at
        ) VALUES (
            :user_id, 'order_group_created', :description, NOW()
        )";

        $activityStmt = $conn->prepare($activitySql);
        $merchantNames = array_column($merchants, 'name');
        $activityStmt->execute([
            ':user_id' => $userId,
            ':description' => "Created order group with " . count($merchants) . " merchants: " . implode(', ', $merchantNames)
        ]);

        // Commit transaction
        $conn->commit();

        ResponseHandler::success([
            'order_group_id' => $orderGroupId,
            'order_ids' => $orderIds,
            'order_numbers' => $orderNumbers,
            'merchants' => array_values($merchants),
            'merchant_count' => count($merchants),
            'totals' => [
                'subtotal' => $overallSubtotal,
                'delivery_fee' => $overallDeliveryFee,
                'total' => $totalAmount
            ],
            'message' => 'Order group created successfully'
        ], 201);

    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to create order group: ' . $e->getMessage(), 500);
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

    // Check if group can be cancelled
    if ($group['status'] !== 'pending') {
        ResponseHandler::error('Order group cannot be cancelled at this stage', 400);
    }

    // Get all orders in this group
    $ordersStmt = $conn->prepare(
        "SELECT id, status FROM orders WHERE order_group_id = :group_id"
    );
    $ordersStmt->execute([':group_id' => $orderGroupId]);
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

    // Check if any orders cannot be cancelled
    $cancellableStatuses = ['pending', 'confirmed'];
    foreach ($orders as $order) {
        if (!in_array($order['status'], $cancellableStatuses)) {
            ResponseHandler::error('Some orders in this group cannot be cancelled at this stage', 400);
        }
    }

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
            // Update order status
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

            // Update order tracking
            $trackingStmt = $conn->prepare(
                "UPDATE order_tracking SET 
                    status = 'Cancelled',
                    updated_at = NOW()
                 WHERE order_id = :order_id"
            );
            $trackingStmt->execute([':order_id' => $order['id']]);

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

        // Log user activity
        $activityStmt = $conn->prepare(
            "INSERT INTO user_activities (
                user_id, activity_type, description, created_at
            ) VALUES (
                :user_id, 'order_group_cancelled', :description, NOW()
            )"
        );
        
        $activityStmt->execute([
            ':user_id' => $userId,
            ':description' => "Cancelled order group #$orderGroupId with " . count($orders) . " orders"
        ]);

        $conn->commit();

        ResponseHandler::success([
            'order_group_id' => $orderGroupId,
            'cancelled_orders' => count($orders),
            'message' => 'Order group cancelled successfully'
        ]);

    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to cancel order group: ' . $e->getMessage(), 500);
    }
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
 * TRACK ORDER GROUP
 *********************************/
function trackOrderGroup($conn, $data, $userId) {
    $orderGroupId = $data['order_group_id'] ?? null;

    if (!$orderGroupId) {
        ResponseHandler::error('Order group ID is required', 400);
    }

    // Get all orders in this group with tracking info
    $sql = "SELECT 
                o.id,
                o.order_number,
                o.status,
                o.created_at,
                m.name as merchant_name,
                d.name as driver_name,
                d.phone as driver_phone,
                d.current_latitude as driver_lat,
                d.current_longitude as driver_lng,
                ot.estimated_delivery,
                ot.location_updates
            FROM orders o
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            LEFT JOIN order_tracking ot ON o.id = ot.order_id
            WHERE o.order_group_id = :group_id AND o.user_id = :user_id
            ORDER BY o.created_at";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':group_id' => $orderGroupId,
        ':user_id' => $userId
    ]);
    
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($orders)) {
        ResponseHandler::error('Order group not found', 404);
    }

    // Format tracking info for each order
    $trackingInfo = [];
    $overallStatus = 'pending';
    $statusPriority = [
        'pending' => 1,
        'confirmed' => 2,
        'preparing' => 3,
        'ready' => 4,
        'picked_up' => 5,
        'on_the_way' => 6,
        'arrived' => 7,
        'delivered' => 8,
        'cancelled' => 9
    ];

    foreach ($orders as $order) {
        // Parse location updates
        $locationUpdates = [];
        if (!empty($order['location_updates'])) {
            $updates = json_decode($order['location_updates'], true);
            if (is_array($updates)) {
                $locationUpdates = $updates;
            }
        }

        $trackingInfo[] = [
            'order_id' => $order['id'],
            'order_number' => $order['order_number'],
            'merchant_name' => $order['merchant_name'],
            'status' => $order['status'],
            'driver_name' => $order['driver_name'],
            'driver_phone' => $order['driver_phone'],
            'driver_location' => $order['driver_lat'] && $order['driver_lng'] 
                ? ['lat' => (float)$order['driver_lat'], 'lng' => (float)$order['driver_lng']]
                : null,
            'estimated_delivery' => $order['estimated_delivery'],
            'location_updates' => $locationUpdates,
            'order_placed_at' => $order['created_at']
        ];

        // Determine overall group status (lowest priority status that's not delivered/cancelled)
        $currentPriority = $statusPriority[$order['status']] ?? 0;
        if ($currentPriority < ($statusPriority[$overallStatus] ?? 100)) {
            if (!in_array($order['status'], ['delivered', 'cancelled'])) {
                $overallStatus = $order['status'];
            }
        }
    }

    ResponseHandler::success([
        'order_group_id' => $orderGroupId,
        'overall_status' => $overallStatus,
        'order_count' => count($orders),
        'tracking' => $trackingInfo
    ]);
}

/*********************************
 * GET TRACKABLE ORDERS - COMPLETELY FIXED
 *********************************/
function getTrackableOrders($conn, $data, $userId) {
    $limit = intval($data['limit'] ?? 50);
    $sortBy = $data['sort_by'] ?? 'created_at';
    $sortOrder = $data['sort_order'] ?? 'DESC';
    
    // Validate sort parameters
    $allowedSortColumns = ['created_at', 'order_number', 'total_amount', 'status'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'created_at';
    $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
    
    // Trackable statuses (orders that can be tracked)
    $trackableStatuses = ['confirmed', 'preparing', 'ready', 'picked_up', 'on_the_way', 'arrived'];
    
    // Create placeholders for the IN clause
    $placeholders = [];
    foreach ($trackableStatuses as $index => $status) {
        $placeholders[] = ":status_$index";
    }
    $placeholdersStr = implode(',', $placeholders);
    
    $sql = "SELECT 
                o.id,
                o.order_number,
                o.status,
                o.created_at,
                o.updated_at,
                o.total_amount,
                o.payment_method,
                o.order_group_id,
                m.name as merchant_name,
                m.image_url as merchant_image
            FROM orders o
            LEFT JOIN merchants m ON o.merchant_id = m.id
            WHERE o.user_id = :user_id 
            AND o.status IN ($placeholdersStr)
            ORDER BY o.$sortBy $sortOrder
            LIMIT :limit";
    
    $stmt = $conn->prepare($sql);
    
    // Bind user_id
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    
    // Bind each status
    foreach ($trackableStatuses as $index => $status) {
        $stmt->bindValue(":status_$index", $status, PDO::PARAM_STR);
    }
    
    // Bind limit
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format orders for response
    $formattedOrders = [];
    foreach ($orders as $order) {
        $merchantImage = '';
        if (!empty($order['merchant_image'])) {
            global $baseUrl;
            $merchantImage = formatImageUrl($order['merchant_image'], $baseUrl, 'merchants');
        }
        
        $formattedOrders[] = [
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'status' => $order['status'],
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at'],
            'total_amount' => floatval($order['total_amount']),
            'payment_method' => $order['payment_method'],
            'merchant_name' => $order['merchant_name'],
            'merchant_image' => $merchantImage,
            'restaurant_name' => $order['merchant_name'],
            'order_group_id' => $order['order_group_id']
        ];
    }
    
    ResponseHandler::success([
        'orders' => $formattedOrders
    ]);
}

/*********************************
 * GET LATEST ACTIVE ORDER - FIXED
 *********************************/
function getLatestActiveOrder($conn, $userId) {
    $activeStatuses = ['pending', 'confirmed', 'preparing', 'ready', 'picked_up', 'on_the_way', 'arrived'];
    
    // Create placeholders for the IN clause
    $placeholders = [];
    foreach ($activeStatuses as $index => $status) {
        $placeholders[] = ":status_$index";
    }
    $placeholdersStr = implode(',', $placeholders);
    
    $sql = "SELECT 
                o.id,
                o.order_number,
                o.status,
                o.created_at,
                o.updated_at,
                o.total_amount,
                o.order_group_id,
                m.name as merchant_name
            FROM orders o
            LEFT JOIN merchants m ON o.merchant_id = m.id
            WHERE o.user_id = :user_id 
            AND o.status IN ($placeholdersStr)
            ORDER BY o.created_at DESC
            LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    
    // Bind user_id
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    
    // Bind each status
    foreach ($activeStatuses as $index => $status) {
        $stmt->bindValue(":status_$index", $status, PDO::PARAM_STR);
    }
    
    $stmt->execute();
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
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at'],
            'total_amount' => floatval($order['total_amount']),
            'merchant_name' => $order['merchant_name'],
            'order_group_id' => $order['order_group_id']
        ]
    ]);
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
        "SELECT o.id, o.status, o.order_group_id, og.status as group_status
         FROM orders o
         LEFT JOIN order_groups og ON o.order_group_id = og.id
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

        // Update order tracking
        $trackingStmt = $conn->prepare(
            "UPDATE order_tracking SET 
                status = 'Cancelled',
                updated_at = NOW()
             WHERE order_id = :order_id"
        );
        $trackingStmt->execute([':order_id' => $orderId]);

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

        // Check if this was the only pending order in the group
        if ($order['order_group_id']) {
            $pendingStmt = $conn->prepare(
                "SELECT COUNT(*) as pending_count 
                 FROM orders 
                 WHERE order_group_id = :group_id 
                 AND status IN ('pending', 'confirmed', 'preparing')
                 AND id != :order_id"
            );
            $pendingStmt->execute([
                ':group_id' => $order['order_group_id'],
                ':order_id' => $orderId
            ]);
            $pendingCount = $pendingStmt->fetch(PDO::FETCH_ASSOC)['pending_count'];
            
            if ($pendingCount == 0 && $order['group_status'] == 'pending') {
                // Update group status to cancelled
                $updateGroupStmt = $conn->prepare(
                    "UPDATE order_groups SET 
                        status = 'cancelled',
                        updated_at = NOW()
                     WHERE id = :group_id"
                );
                $updateGroupStmt->execute([':group_id' => $order['order_group_id']]);
            }
        }

        // Log user activity
        $activityStmt = $conn->prepare(
            "INSERT INTO user_activities (
                user_id, activity_type, description, created_at
            ) VALUES (
                :user_id, 'order_cancelled', :description, NOW()
            )"
        );
        
        $activityStmt->execute([
            ':user_id' => $userId,
            ':description' => "Cancelled order #$orderId"
        ]);

        $conn->commit();

        ResponseHandler::success([
            'order_id' => $orderId,
            'order_group_id' => $order['order_group_id'],
            'message' => 'Order cancelled successfully'
        ]);

    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to cancel order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CREATE ORDER REVIEW
 *********************************/
function createOrderReview($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;
    $merchantId = $data['merchant_id'] ?? null;
    $rating = intval($data['rating'] ?? 0);
    $comment = trim($data['comment'] ?? '');
    $reviewType = $data['review_type'] ?? 'merchant';

    if (!$orderId || !$rating) {
        ResponseHandler::error('Order ID and rating are required', 400);
    }

    // Check if order exists and belongs to user
    $orderStmt = $conn->prepare(
        "SELECT id, merchant_id, status FROM orders 
         WHERE id = :order_id AND user_id = :user_id"
    );
    $orderStmt->execute([
        ':order_id' => $orderId,
        ':user_id' => $userId
    ]);
    
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ResponseHandler::error('Order not found', 404);
    }

    if ($order['status'] !== 'delivered') {
        ResponseHandler::error('Order must be delivered before reviewing', 400);
    }

    // Check if already reviewed
    $existingStmt = $conn->prepare(
        "SELECT id FROM user_reviews 
         WHERE user_id = :user_id AND order_id = :order_id"
    );
    $existingStmt->execute([
        ':user_id' => $userId,
        ':order_id' => $orderId
    ]);
    
    if ($existingStmt->fetch()) {
        ResponseHandler::error('You have already reviewed this order', 409);
    }

    // Create review
    $reviewSql = "INSERT INTO user_reviews (
        user_id, order_id, merchant_id, rating, comment, review_type, created_at
    ) VALUES (
        :user_id, :order_id, :merchant_id, :rating, :comment, :review_type, NOW()
    )";

    $reviewStmt = $conn->prepare($reviewSql);
    $reviewStmt->execute([
        ':user_id' => $userId,
        ':order_id' => $orderId,
        ':merchant_id' => $merchantId ?: $order['merchant_id'],
        ':rating' => $rating,
        ':comment' => $comment,
        ':review_type' => $reviewType
    ]);

    // Update merchant rating if review type is merchant
    if ($reviewType === 'merchant' && $merchantId) {
        updateMerchantRating($conn, $merchantId);
    }

    ResponseHandler::success([], 'Review submitted successfully');
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
                    o.*,
                    GROUP_CONCAT(
                        CONCAT(oi.item_name, '||', oi.quantity, '||', oi.unit_price, '||', o.merchant_id)
                        ORDER BY oi.id SEPARATOR ';;'
                    ) as items_data
                FROM orders o
                LEFT JOIN order_items oi ON o.id = oi.order_id
                LEFT JOIN merchants m ON o.merchant_id = m.id
                WHERE o.id = :order_id AND o.user_id = :user_id
                GROUP BY o.id";
    
    $orderStmt = $conn->prepare($orderSql);
    $orderStmt->execute([
        ':order_id' => $orderId,
        ':user_id' => $userId
    ]);
    
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ResponseHandler::error('Order not found', 404);
    }

    // Check if merchant is still active
    $merchantStmt = $conn->prepare(
        "SELECT id, is_open, is_active FROM merchants WHERE id = :id"
    );
    $merchantStmt->execute([':id' => $order['merchant_id']]);
    $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant || !$merchant['is_active']) {
        ResponseHandler::error('Merchant is no longer available', 400);
    }

    if (!$merchant['is_open']) {
        ResponseHandler::error('Merchant is currently closed', 400);
    }

    // Parse items data
    $items = [];
    if (!empty($order['items_data'])) {
        $itemStrings = explode(';;', $order['items_data']);
        foreach ($itemStrings as $itemString) {
            $parts = explode('||', $itemString);
            if (count($parts) === 4) {
                $items[] = [
                    'merchant_id' => $parts[3],
                    'name' => $parts[0],
                    'quantity' => (int)$parts[1],
                    'price' => (float)$parts[2]
                ];
            }
        }
    }

    // Prepare reorder data
    $reorderData = [
        'items' => $items,
        'delivery_address' => $order['delivery_address'],
        'special_instructions' => $order['special_instructions'],
        'payment_method' => $order['payment_method']
    ];

    // Call createMultiMerchantOrder function
    createMultiMerchantOrder($conn, $reorderData, $userId);
}

/*********************************
 * TRACK ORDER
 *********************************/
function trackOrder($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;

    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
    }

    // Get order tracking info
    $sql = "SELECT 
                o.order_number,
                o.status,
                o.created_at,
                o.order_group_id,
                m.name as merchant_name,
                d.name as driver_name,
                d.phone as driver_phone,
                d.current_latitude as driver_lat,
                d.current_longitude as driver_lng,
                d.image_url as driver_image,
                ot.estimated_delivery,
                ot.location_updates
            FROM orders o
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            LEFT JOIN order_tracking ot ON o.id = ot.order_id
            WHERE o.id = :order_id AND o.user_id = :user_id";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':order_id' => $orderId,
        ':user_id' => $userId
    ]);
    
    $trackingInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trackingInfo) {
        ResponseHandler::error('Order not found', 404);
    }

    // Parse location updates
    $locationUpdates = [];
    if (!empty($trackingInfo['location_updates'])) {
        $updates = json_decode($trackingInfo['location_updates'], true);
        if (is_array($updates)) {
            $locationUpdates = $updates;
        }
    }

    // Format driver image URL
    global $baseUrl;
    $driverImage = formatImageUrl($trackingInfo['driver_image'], $baseUrl, 'drivers');

    $response = [
        'order_number' => $trackingInfo['order_number'],
        'status' => $trackingInfo['status'],
        'merchant_name' => $trackingInfo['merchant_name'],
        'driver_name' => $trackingInfo['driver_name'],
        'driver_phone' => $trackingInfo['driver_phone'],
        'driver_image' => $driverImage,
        'driver_location' => $trackingInfo['driver_lat'] && $trackingInfo['driver_lng'] 
            ? ['lat' => (float)$trackingInfo['driver_lat'], 'lng' => (float)$trackingInfo['driver_lng']]
            : null,
        'estimated_delivery' => $trackingInfo['estimated_delivery'],
        'location_updates' => $locationUpdates,
        'order_placed_at' => $trackingInfo['created_at'],
        'order_group_id' => $trackingInfo['order_group_id']
    ];

    ResponseHandler::success([
        'tracking_info' => $response
    ]);
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

    // Build update query dynamically based on provided data
    $updatableFields = ['special_instructions', 'delivery_address'];
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

        // Check if all orders in group have same address and update if needed
        if ($order['order_group_id']) {
            $checkGroupStmt = $conn->prepare(
                "SELECT COUNT(*) as total, 
                        SUM(CASE WHEN delivery_address = :address THEN 1 ELSE 0 END) as matching
                 FROM orders 
                 WHERE order_group_id = :group_id"
            );
            $checkGroupStmt->execute([
                ':group_id' => $order['order_group_id'],
                ':address' => $newAddress
            ]);
            $groupCheck = $checkGroupStmt->fetch(PDO::FETCH_ASSOC);
            
            // If this is the only order or all orders have same address, no need to update others
            if ($groupCheck['total'] > 1 && $groupCheck['matching'] == 1) {
                // Other orders have different addresses, leave them as is
                // This allows different delivery addresses per merchant if needed
            }
        }

        // Log the change
        $logStmt = $conn->prepare(
            "INSERT INTO user_activities (
                user_id, activity_type, description, created_at
            ) VALUES (
                :user_id, 'address_changed', :description, NOW()
            )"
        );
        
        $logStmt->execute([
            ':user_id' => $userId,
            ':description' => "Changed delivery address for order #$orderId"
        ]);

        $conn->commit();

        ResponseHandler::success([
            'order_id' => $orderId,
            'order_group_id' => $order['order_group_id'],
            'new_address' => $newAddress
        ], 'Delivery address updated successfully');

    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to update address: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * FORMAT ORDER DETAIL DATA
 *********************************/
function formatOrderDetailData($order) {
    global $baseUrl;
    
    // Format merchant image URL
    $merchantImage = formatImageUrl($order['merchant_image'], $baseUrl, 'merchants');
    
    // Format driver image URL
    $driverImage = formatImageUrl($order['driver_image'], $baseUrl, 'drivers');
    
    // Format quick order image URL
    $quickOrderImage = formatImageUrl($order['quick_order_image'], $baseUrl, 'quick_orders');

    return [
        'id' => $order['id'],
        'order_number' => $order['order_number'],
        'status' => $order['status'],
        'customer_name' => $order['customer_name'],
        'customer_phone' => $order['customer_phone'],
        'customer_email' => $order['customer_email'],
        'delivery_address' => $order['delivery_address'],
        'total_amount' => (float)$order['total_amount'],
        'delivery_fee' => (float)$order['delivery_fee'],
        'subtotal' => (float)$order['subtotal'],
        'order_date' => $order['created_at'],
        'estimated_delivery' => $order['estimated_delivery'],
        'payment_method' => $order['payment_method'],
        'payment_status' => getPaymentStatus($order['status']),
        'restaurant_name' => $order['restaurant_name'],
        'merchant_address' => $order['merchant_address'],
        'merchant_phone' => $order['merchant_phone'],
        'merchant_image' => $merchantImage,
        'driver_name' => $order['driver_name'],
        'driver_phone' => $order['driver_phone'],
        'driver_email' => $order['driver_email'],
        'driver_image' => $driverImage,
        'special_instructions' => $order['special_instructions'],
        'cancellation_reason' => $order['cancellation_reason'],
        'quick_order_title' => $order['quick_order_title'],
        'quick_order_image' => $quickOrderImage,
        'created_at' => $order['created_at'],
        'updated_at' => $order['updated_at'],
        'order_group_id' => $order['order_group_id']
    ];
}

/*********************************
 * FORMAT ORDER ITEM DATA
 *********************************/
function formatOrderItemData($item) {
    return [
        'id' => $item['id'],
        'name' => $item['name'],
        'quantity' => (int)$item['quantity'],
        'price' => (float)$item['price'],
        'total' => (float)$item['total'],
        'created_at' => $item['created_at']
    ];
}

/*********************************
 * FORMAT TRACKING DATA
 *********************************/
function formatTrackingData($tracking) {
    return [
        'status' => $tracking['status'],
        'estimated_delivery' => $tracking['estimated_delivery'],
        'location_updates' => json_decode($tracking['location_updates'] ?? '[]', true),
        'created_at' => $tracking['created_at'],
        'updated_at' => $tracking['updated_at']
    ];
}

/*********************************
 * FORMAT IMAGE URL
 *********************************/
function formatImageUrl($imagePath, $baseUrl, $type = '') {
    if (empty($imagePath)) {
        return '';
    }
    
    // If it's already a full URL, use it as is
    if (strpos($imagePath, 'http') === 0) {
        return $imagePath;
    }
    
    // Otherwise, build the full URL
    $folder = '';
    switch ($type) {
        case 'merchants':
            $folder = 'uploads/merchants';
            break;
        case 'drivers':
            $folder = 'uploads/drivers';
            break;
        case 'quick_orders':
            $folder = 'uploads/quick_orders';
            break;
        default:
            $folder = 'uploads';
    }
    
    return rtrim($baseUrl, '/') . '/' . $folder . '/' . ltrim($imagePath, '/');
}

/*********************************
 * GET PAYMENT STATUS
 *********************************/
function getPaymentStatus($orderStatus) {
    $paidStatuses = ['confirmed', 'preparing', 'ready', 'picked_up', 'on_the_way', 'arrived', 'delivered'];
    
    if (in_array($orderStatus, $paidStatuses)) {
        return 'paid';
    }
    
    return $orderStatus === 'cancelled' ? 'refunded' : 'pending';
}

/*********************************
 * UPDATE MERCHANT RATING
 *********************************/
function updateMerchantRating($conn, $merchantId) {
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total_reviews,
            AVG(rating) as avg_rating
        FROM user_reviews
        WHERE merchant_id = :merchant_id AND review_type = 'merchant'"
    );
    $stmt->execute([':merchant_id' => $merchantId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $updateStmt = $conn->prepare(
        "UPDATE merchants 
         SET rating = :rating, 
             review_count = :review_count,
             updated_at = NOW()
         WHERE id = :id"
    );
    
    $updateStmt->execute([
        ':rating' => $result['avg_rating'] ?? 0,
        ':review_count' => $result['total_reviews'] ?? 0,
        ':id' => $merchantId
    ]);
}
?>