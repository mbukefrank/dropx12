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
 * GET ORDERS LIST - WITH DROPX TRACKING
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

    // Main query with DropX tracking info
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
                og.dropx_tracking_id,
                og.dropx_pickup_status,
                og.dropx_estimated_pickup_time,
                og.dropx_estimated_delivery_time,
                og.current_location_lat,
                og.current_location_lng,
                (
                    SELECT status 
                    FROM group_tracking 
                    WHERE order_group_id = og.id 
                    ORDER BY created_at DESC 
                    LIMIT 1
                ) as dropx_tracking_status,
                (
                    SELECT message 
                    FROM group_tracking 
                    WHERE order_group_id = og.id 
                    ORDER BY created_at DESC 
                    LIMIT 1
                ) as dropx_tracking_message,
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
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            LEFT JOIN order_groups og ON o.order_group_id = og.id
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

    // Format orders data
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

        // Get other merchants in the same group if applicable
        $otherMerchants = [];
        if ($order['order_group_id']) {
            $merchantListSql = "SELECT DISTINCT 
                                    m.id,
                                    m.name,
                                    m.image_url,
                                    gp.pickup_status,
                                    gp.pickup_order
                                FROM orders o2
                                JOIN merchants m ON o2.merchant_id = m.id
                                LEFT JOIN group_pickups gp ON gp.order_group_id = o2.order_group_id AND gp.merchant_id = m.id
                                WHERE o2.order_group_id = :group_id
                                AND o2.merchant_id != :current_merchant_id
                                ORDER BY gp.pickup_order";
            
            $merchantListStmt = $conn->prepare($merchantListSql);
            $merchantListStmt->execute([
                ':group_id' => $order['order_group_id'],
                ':current_merchant_id' => $order['merchant_id']
            ]);
            $otherMerchantsData = $merchantListStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($otherMerchantsData as $m) {
                $merchantImg = '';
                if (!empty($m['image_url'])) {
                    if (strpos($m['image_url'], 'http') === 0) {
                        $merchantImg = $m['image_url'];
                    } else {
                        global $baseUrl;
                        $merchantImg = rtrim($baseUrl ?? '', '/') . '/uploads/merchants/' . ltrim($m['image_url'], '/');
                    }
                }
                
                $otherMerchants[] = [
                    'id' => $m['id'],
                    'name' => $m['name'],
                    'image' => $merchantImg,
                    'pickup_status' => $m['pickup_status'] ?? 'pending',
                    'pickup_order' => (int)$m['pickup_order']
                ];
            }
        }

        // Build order object
        $formattedOrder = [
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

        // Add DropX tracking info for group orders
        if ($order['order_group_id']) {
            $formattedOrder['is_multi_merchant'] = !empty($otherMerchants);
            $formattedOrder['merchant_count'] = count($otherMerchants) + 1;
            $formattedOrder['other_merchants'] = $otherMerchants;
            
            $formattedOrder['dropx_tracking'] = [
                'tracking_id' => $order['dropx_tracking_id'],
                'status' => $order['dropx_pickup_status'],
                'tracking_status' => $order['dropx_tracking_status'],
                'tracking_message' => $order['dropx_tracking_message'],
                'estimated_pickup' => $order['dropx_estimated_pickup_time'],
                'estimated_delivery' => $order['dropx_estimated_delivery_time'],
                'current_location' => ($order['current_location_lat'] && $order['current_location_lng']) ? [
                    'lat' => (float)$order['current_location_lat'],
                    'lng' => (float)$order['current_location_lng']
                ] : null,
                'tracking_url' => "https://dropx.com/track/" . $order['dropx_tracking_id']
            ];
        } else {
            $formattedOrder['is_multi_merchant'] = false;
            $formattedOrder['merchant_count'] = 1;
            $formattedOrder['other_merchants'] = [];
        }

        $formattedOrders[] = $formattedOrder;
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
        $whereConditions[] = "og.dropx_pickup_status = :status";
        $params[':status'] = $status;
    }
    
    $whereClause = "WHERE " . implode(" AND ", $whereConditions);
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM order_groups og $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get order groups with merchant and tracking info
    $sql = "SELECT 
                og.id,
                og.total_amount,
                og.status as group_status,
                og.created_at,
                og.updated_at,
                og.dropx_tracking_id,
                og.dropx_pickup_status,
                og.dropx_estimated_pickup_time,
                og.dropx_estimated_delivery_time,
                og.current_location_lat,
                og.current_location_lng,
                COUNT(DISTINCT o.id) as order_count,
                COUNT(DISTINCT o.merchant_id) as merchant_count,
                GROUP_CONCAT(DISTINCT m.name SEPARATOR '||') as merchant_names,
                GROUP_CONCAT(DISTINCT m.id SEPARATOR '||') as merchant_ids,
                GROUP_CONCAT(DISTINCT m.image_url SEPARATOR '||') as merchant_images,
                MIN(o.created_at) as first_order_time,
                MAX(o.created_at) as last_order_time,
                (
                    SELECT status 
                    FROM group_tracking 
                    WHERE order_group_id = og.id 
                    ORDER BY created_at DESC 
                    LIMIT 1
                ) as current_tracking_status,
                (
                    SELECT message 
                    FROM group_tracking 
                    WHERE order_group_id = og.id 
                    ORDER BY created_at DESC 
                    LIMIT 1
                ) as current_tracking_message,
                (
                    SELECT COUNT(*) 
                    FROM group_pickups 
                    WHERE order_group_id = og.id AND pickup_status = 'picked_up'
                ) as picked_up_count,
                (
                    SELECT COUNT(*) 
                    FROM group_pickups 
                    WHERE order_group_id = og.id
                ) as total_pickups
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
                $imageUrl = '';
                if (!empty($merchantImages[$i] ?? '')) {
                    global $baseUrl;
                    if (strpos($merchantImages[$i], 'http') === 0) {
                        $imageUrl = $merchantImages[$i];
                    } else {
                        $imageUrl = rtrim($baseUrl ?? '', '/') . '/uploads/merchants/' . ltrim($merchantImages[$i], '/');
                    }
                }
                
                $merchants[] = [
                    'id' => $merchantIds[$i] ?? '',
                    'name' => $merchantNames[$i],
                    'image' => $imageUrl
                ];
            }
        }
        
        // Calculate pickup progress
        $totalPickups = (int)$group['total_pickups'];
        $pickedUpCount = (int)$group['picked_up_count'];
        $pickupPercentage = $totalPickups > 0 ? round(($pickedUpCount / $totalPickups) * 100) : 0;
        
        // Map to user-friendly status
        $displayStatus = mapDropXStatusToDisplay(
            $group['dropx_pickup_status'], 
            $pickedUpCount, 
            $totalPickups
        );
        
        $formattedGroups[] = [
            'id' => $group['id'],
            'dropx_tracking_id' => $group['dropx_tracking_id'],
            'tracking_url' => "https://dropx.com/track/" . $group['dropx_tracking_id'],
            'order_count' => (int)$group['order_count'],
            'merchant_count' => (int)$group['merchant_count'],
            'merchants' => $merchants,
            'merchant_names' => implode(', ', $merchantNames),
            'total_amount' => (float)$group['total_amount'],
            'status' => $displayStatus,
            'dropx_status' => $group['dropx_pickup_status'],
            'tracking_status' => $group['current_tracking_status'],
            'tracking_message' => $group['current_tracking_message'],
            'pickup_progress' => [
                'total' => $totalPickups,
                'completed' => $pickedUpCount,
                'remaining' => $totalPickups - $pickedUpCount,
                'percentage' => $pickupPercentage
            ],
            'estimated_times' => [
                'pickup_start' => $group['dropx_estimated_pickup_time'],
                'delivery' => $group['dropx_estimated_delivery_time']
            ],
            'current_location' => ($group['current_location_lat'] && $group['current_location_lng']) ? [
                'lat' => (float)$group['current_location_lat'],
                'lng' => (float)$group['current_location_lng']
            ] : null,
            'created_at' => $group['created_at'],
            'updated_at' => $group['updated_at'],
            'first_order_time' => $group['first_order_time'],
            'last_order_time' => $group['last_order_time']
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
 * GET ORDER GROUP DETAILS - WITH DROPX TRACKING
 *********************************/
function getOrderGroupDetails($conn, $groupId, $userId) {
    // Get order group info with DropX tracking
    $groupSql = "SELECT 
                    og.*,
                    dc.name as courier_name,
                    dc.phone as courier_phone,
                    dc.vehicle_type,
                    dc.vehicle_number,
                    dc.current_latitude as courier_lat,
                    dc.current_longitude as courier_lng,
                    (
                        SELECT status 
                        FROM group_tracking 
                        WHERE order_group_id = og.id 
                        ORDER BY created_at DESC 
                        LIMIT 1
                    ) as current_tracking_status,
                    (
                        SELECT message 
                        FROM group_tracking 
                        WHERE order_group_id = og.id 
                        ORDER BY created_at DESC 
                        LIMIT 1
                    ) as current_tracking_message,
                    (
                        SELECT location_address 
                        FROM group_tracking 
                        WHERE order_group_id = og.id 
                        ORDER BY created_at DESC 
                        LIMIT 1
                    ) as current_location_address
                FROM order_groups og
                LEFT JOIN dropx_couriers dc ON og.dropx_courier_id = dc.id
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

    // Get pickup points for this group
    $pickupSql = "SELECT 
                    gp.*,
                    m.name as merchant_name,
                    m.address as merchant_address,
                    m.phone as merchant_phone,
                    m.image_url as merchant_image,
                    m.latitude,
                    m.longitude,
                    m.preparation_time_minutes
                FROM group_pickups gp
                JOIN merchants m ON gp.merchant_id = m.id
                WHERE gp.order_group_id = :group_id
                ORDER BY gp.pickup_order";

    $pickupStmt = $conn->prepare($pickupSql);
    $pickupStmt->execute([':group_id' => $groupId]);
    $pickups = $pickupStmt->fetchAll(PDO::FETCH_ASSOC);

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

    // Get tracking history
    $historySql = "SELECT 
                    status,
                    location_address,
                    location_lat,
                    location_lng,
                    estimated_delivery,
                    estimated_next_pickup,
                    message,
                    created_at
                FROM group_tracking
                WHERE order_group_id = :group_id
                ORDER BY created_at DESC
                LIMIT 50";

    $historyStmt = $conn->prepare($historySql);
    $historyStmt->execute([':group_id' => $groupId]);
    $trackingHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

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
    
    // Calculate pickup progress
    $totalPickups = count($pickups);
    $completedPickups = count(array_filter($pickups, function($p) {
        return $p['pickup_status'] === 'picked_up';
    }));
    
    // Determine overall group status
    $displayStatus = mapDropXStatusToDisplay(
        $group['dropx_pickup_status'],
        $completedPickups,
        $totalPickups
    );
    
    $response = [
        'group' => [
            'id' => $group['id'],
            'dropx_tracking_id' => $group['dropx_tracking_id'],
            'tracking_url' => "https://dropx.com/track/" . $group['dropx_tracking_id'],
            'status' => $displayStatus,
            'dropx_status' => $group['dropx_pickup_status'],
            'original_group_status' => $group['status'],
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
            'order_count' => count($formattedOrders),
            'merchant_count' => count($pickups),
            'courier' => $group['courier_name'] ? [
                'name' => $group['courier_name'],
                'phone' => $group['courier_phone'],
                'vehicle_type' => $group['vehicle_type'],
                'vehicle_number' => $group['vehicle_number'],
                'current_location' => ($group['courier_lat'] && $group['courier_lng']) ? [
                    'lat' => (float)$group['courier_lat'],
                    'lng' => (float)$group['courier_lng']
                ] : null
            ] : null,
            'pickup_progress' => [
                'total' => $totalPickups,
                'completed' => $completedPickups,
                'remaining' => $totalPickups - $completedPickups,
                'percentage' => $totalPickups > 0 ? round(($completedPickups / $totalPickups) * 100) : 0
            ],
            'estimated_times' => [
                'pickup_start' => $group['dropx_estimated_pickup_time'],
                'delivery' => $group['dropx_estimated_delivery_time']
            ],
            'current_tracking' => [
                'status' => $group['current_tracking_status'],
                'message' => $group['current_tracking_message'],
                'location' => $group['current_location_address'],
                'coordinates' => ($group['current_location_lat'] && $group['current_location_lng']) ? [
                    'lat' => (float)$group['current_location_lat'],
                    'lng' => (float)$group['current_location_lng']
                ] : null
            ]
        ],
        'pickups' => array_map(function($pickup) {
            $imageUrl = formatImageUrl($pickup['merchant_image'], $GLOBALS['baseUrl'], 'merchants');
            
            return [
                'id' => $pickup['id'],
                'merchant' => [
                    'id' => $pickup['merchant_id'],
                    'name' => $pickup['merchant_name'],
                    'address' => $pickup['merchant_address'],
                    'phone' => $pickup['merchant_phone'],
                    'image' => $imageUrl,
                    'location' => ($pickup['latitude'] && $pickup['longitude']) ? [
                        'lat' => (float)$pickup['latitude'],
                        'lng' => (float)$pickup['longitude']
                    ] : null,
                    'preparation_time' => (int)$pickup['preparation_time_minutes']
                ],
                'pickup_order' => (int)$pickup['pickup_order'],
                'pickup_status' => $pickup['pickup_status'],
                'actual_pickup_time' => $pickup['actual_pickup_time'],
                'notes' => $pickup['notes']
            ];
        }, $pickups),
        'orders' => $formattedOrders,
        'tracking_history' => array_map(function($history) {
            return [
                'status' => $history['status'],
                'location' => [
                    'address' => $history['location_address'],
                    'coordinates' => ($history['location_lat'] && $history['location_lng']) ? [
                        'lat' => (float)$history['location_lat'],
                        'lng' => (float)$history['location_lng']
                    ] : null
                ],
                'message' => $history['message'],
                'estimated_delivery' => $history['estimated_delivery'],
                'estimated_next_pickup' => $history['estimated_next_pickup'],
                'timestamp' => $history['created_at']
            ];
        }, $trackingHistory)
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
                qo.image_url as quick_order_image,
                og.dropx_tracking_id,
                og.dropx_pickup_status,
                og.dropx_estimated_delivery_time
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.id
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            LEFT JOIN order_tracking ot ON o.id = ot.order_id
            LEFT JOIN quick_orders qo ON o.quick_order_id = qo.id
            LEFT JOIN order_groups og ON o.order_group_id = og.id
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
    $groupMerchants = [];
    if ($order['order_group_id']) {
        $siblingSql = "SELECT 
                            o.id,
                            o.order_number,
                            o.status,
                            m.name as merchant_name,
                            m.id as merchant_id,
                            m.image_url as merchant_image,
                            gp.pickup_status,
                            gp.pickup_order
                        FROM orders o
                        LEFT JOIN merchants m ON o.merchant_id = m.id
                        LEFT JOIN group_pickups gp ON gp.order_group_id = o.order_group_id AND gp.merchant_id = m.id
                        WHERE o.order_group_id = :group_id 
                        AND o.id != :order_id
                        ORDER BY gp.pickup_order";
        
        $siblingStmt = $conn->prepare($siblingSql);
        $siblingStmt->execute([
            ':group_id' => $order['order_group_id'],
            ':order_id' => $orderId
        ]);
        $siblingOrders = $siblingStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get all merchants in group for the merchant list
        $merchantListSql = "SELECT DISTINCT 
                                m.id,
                                m.name,
                                m.image_url,
                                gp.pickup_status,
                                gp.pickup_order
                            FROM orders o2
                            JOIN merchants m ON o2.merchant_id = m.id
                            LEFT JOIN group_pickups gp ON gp.order_group_id = o2.order_group_id AND gp.merchant_id = m.id
                            WHERE o2.order_group_id = :group_id
                            ORDER BY gp.pickup_order";
        
        $merchantListStmt = $conn->prepare($merchantListSql);
        $merchantListStmt->execute([':group_id' => $order['order_group_id']]);
        $allMerchants = $merchantListStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($allMerchants as $m) {
            $imageUrl = formatImageUrl($m['image_url'], $GLOBALS['baseUrl'], 'merchants');
            $groupMerchants[] = [
                'id' => $m['id'],
                'name' => $m['name'],
                'image' => $imageUrl,
                'pickup_status' => $m['pickup_status'] ?? 'pending',
                'pickup_order' => (int)$m['pickup_order']
            ];
        }
    }

    // Format the response
    $orderData = formatOrderDetailData($order);
    $orderData['items'] = array_map('formatOrderItemData', $items);
    $orderData['tracking_history'] = array_map('formatTrackingData', $tracking);
    $orderData['status_history'] = $statusHistory;
    
    if ($order['order_group_id']) {
        $orderData['is_multi_merchant'] = true;
        $orderData['group_merchants'] = $groupMerchants;
        $orderData['sibling_orders'] = array_map(function($sibling) {
            $imageUrl = formatImageUrl($sibling['merchant_image'] ?? '', $GLOBALS['baseUrl'], 'merchants');
            return [
                'id' => $sibling['id'],
                'order_number' => $sibling['order_number'],
                'status' => $sibling['status'],
                'merchant_name' => $sibling['merchant_name'],
                'merchant_id' => $sibling['merchant_id'],
                'merchant_image' => $imageUrl,
                'pickup_status' => $sibling['pickup_status'] ?? 'pending'
            ];
        }, $siblingOrders);
        $orderData['dropx_tracking'] = [
            'tracking_id' => $order['dropx_tracking_id'],
            'status' => $order['dropx_pickup_status'],
            'estimated_delivery' => $order['dropx_estimated_delivery_time'],
            'tracking_url' => "https://dropx.com/track/" . $order['dropx_tracking_id']
        ];
    } else {
        $orderData['is_multi_merchant'] = false;
        $orderData['group_merchants'] = [];
        $orderData['sibling_orders'] = [];
    }

    ResponseHandler::success([
        'order' => $orderData
    ]);
}

/*********************************
 * POST REQUESTS - WITH DROPX MULTI-MERCHANT SUPPORT
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
 * CREATE MULTI-MERCHANT ORDER - WITH DROPX OFFICE TRACKING
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
                preparation_time_minutes, address, latitude, longitude,
                phone, image_url
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
        // Generate DropX tracking ID (e.g., DX-20260218-1234)
        $trackingId = 'DX-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Calculate estimated times based on merchant locations
        $estimatedPickup = calculateEstimatedPickupTime($conn, $merchants);
        $estimatedDelivery = date('Y-m-d H:i:s', strtotime($estimatedPickup . ' +' . (45 + (count($merchants) * 15)) . ' minutes'));

        // Create order group with DropX office tracking info
        $groupSql = "INSERT INTO order_groups (
            user_id, total_amount, status, 
            dropx_tracking_id, dropx_pickup_status,
            dropx_estimated_pickup_time, dropx_estimated_delivery_time,
            created_at, updated_at
        ) VALUES (
            :user_id, :total_amount, 'pending',
            :tracking_id, 'pending',
            :estimated_pickup, :estimated_delivery,
            NOW(), NOW()
        )";

        $groupStmt = $conn->prepare($groupSql);
        $groupStmt->execute([
            ':user_id' => $userId,
            ':total_amount' => $totalAmount,
            ':tracking_id' => $trackingId,
            ':estimated_pickup' => $estimatedPickup,
            ':estimated_delivery' => $estimatedDelivery
        ]);

        $orderGroupId = $conn->lastInsertId();

        // Optimize pickup route (in production, use actual distance calculation)
        $optimizedRoute = optimizePickupRoute($conn, $merchants, $data['delivery_address']);
        
        // Create pickup points in optimized route order
        createPickupPoints($conn, $orderGroupId, $optimizedRoute, $merchants);

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

        // Create initial group tracking entry
        $groupTrackingSql = "INSERT INTO group_tracking (
            order_group_id, status, location_address, 
            estimated_delivery, message, created_at
        ) VALUES (
            :group_id, 'order_placed', :address,
            :estimated_delivery, 'Order placed with DropX. Coordinating with merchants...', NOW()
        )";

        $groupTrackingStmt = $conn->prepare($groupTrackingSql);
        $groupTrackingStmt->execute([
            ':group_id' => $orderGroupId,
            ':address' => $data['delivery_address'],
            ':estimated_delivery' => $estimatedDelivery
        ]);

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
            ':description' => "Created order group #$trackingId with " . count($merchants) . " merchants: " . implode(', ', $merchantNames)
        ]);

        // Commit transaction
        $conn->commit();

        // Format merchant list for response
        $formattedMerchants = [];
        foreach ($optimizedRoute as $index => $merchantId) {
            $m = $merchants[$merchantId];
            $formattedMerchants[] = [
                'id' => $m['id'],
                'name' => $m['name'],
                'address' => $m['address'],
                'pickup_order' => $index + 1,
                'estimated_pickup_time' => date('Y-m-d H:i:s', strtotime($estimatedPickup . ' +' . ($index * 15) . ' minutes'))
            ];
        }

        ResponseHandler::success([
            'order_group_id' => $orderGroupId,
            'dropx_tracking_id' => $trackingId,
            'tracking_url' => "https://dropx.com/track/$trackingId",
            'order_ids' => $orderIds,
            'order_numbers' => $orderNumbers,
            'merchants' => $formattedMerchants,
            'merchant_count' => count($merchants),
            'totals' => [
                'subtotal' => $overallSubtotal,
                'delivery_fee' => $overallDeliveryFee,
                'total' => $totalAmount
            ],
            'estimated_times' => [
                'pickup_start' => $estimatedPickup,
                'delivery' => $estimatedDelivery
            ],
            'message' => 'Order group created successfully. DropX will coordinate pickup from all merchants.'
        ], 201);

    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to create order group: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * Helper: Calculate Estimated Pickup Time
 *********************************/
function calculateEstimatedPickupTime($conn, $merchants) {
    // Get max preparation time among merchants
    $maxPrepTime = 0;
    foreach ($merchants as $merchant) {
        $maxPrepTime = max($maxPrepTime, $merchant['preparation_time_minutes'] ?? 15);
    }
    
    // Add DropX coordination time (15 minutes) and routing time (5 minutes per merchant)
    $routingTime = count($merchants) * 5;
    $totalMinutes = $maxPrepTime + 15 + $routingTime;
    
    return date('Y-m-d H:i:s', strtotime("+$totalMinutes minutes"));
}

/*********************************
 * Helper: Optimize Pickup Route
 *********************************/
function optimizePickupRoute($conn, $merchants, $deliveryAddress) {
    $merchantIds = array_keys($merchants);
    
    // In production, use Google Maps Distance Matrix API for actual optimization
    // For now, return merchants in original order
    // You can implement simple sorting by proximity to delivery address
    
    // Simple proximity sort (if merchants have coordinates)
    if (!empty($merchants) && !empty($merchants[$merchantIds[0]]['latitude'])) {
        // This is a placeholder - implement actual distance calculation
        // For now, keep original order
        return $merchantIds;
    }
    
    return $merchantIds;
}

/*********************************
 * Helper: Create Pickup Points
 *********************************/
function createPickupPoints($conn, $orderGroupId, $merchantIds, $merchants) {
    $sql = "INSERT INTO group_pickups (
        order_group_id, merchant_id, pickup_order, pickup_status, created_at
    ) VALUES (
        :group_id, :merchant_id, :pickup_order, 'pending', NOW()
    )";
    
    $stmt = $conn->prepare($sql);
    
    foreach ($merchantIds as $index => $merchantId) {
        $stmt->execute([
            ':group_id' => $orderGroupId,
            ':merchant_id' => $merchantId,
            ':pickup_order' => $index + 1
        ]);
    }
}

/*********************************
 * Helper: Map DropX Status to Display
 *********************************/
function mapDropXStatusToDisplay($pickupStatus, $completedPickups, $totalPickups) {
    if ($pickupStatus === 'delivered') {
        return 'delivered';
    }
    
    if ($pickupStatus === 'on_delivery') {
        return 'on_the_way';
    }
    
    if ($pickupStatus === 'all_picked_up') {
        return 'ready_for_delivery';
    }
    
    if ($pickupStatus === 'pickup_in_progress') {
        if ($completedPickups === 0) {
            return 'heading_to_first_merchant';
        } elseif ($completedPickups < $totalPickups) {
            return 'picking_up_from_merchants';
        }
    }
    
    if ($pickupStatus === 'pending') {
        if ($completedPickups > 0) {
            return 'partial_pickup_completed';
        }
    }
    
    return 'order_placed';
}

/*********************************
 * Helper: Get DropX Status Message
 *********************************/
function getDropXStatusMessage($status, $completedPickups, $totalPickups) {
    $messages = [
        'pending' => 'DropX is coordinating with merchants and assigning a courier',
        'pickup_in_progress' => $completedPickups === 0 
            ? 'DropX courier is heading to the first merchant'
            : "DropX courier has picked up from $completedPickups of $totalPickups merchants",
        'all_picked_up' => 'All orders have been picked up and are on the way to you',
        'on_delivery' => 'Your order is on the way - courier is heading to your location',
        'delivered' => 'Your order has been delivered successfully'
    ];
    
    return $messages[$status] ?? 'Tracking your DropX order';
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
        "SELECT id, status, dropx_pickup_status FROM order_groups 
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

    // Check if group can be cancelled (only if not yet picked up)
    $cancellableStatuses = ['pending'];
    if (!in_array($group['dropx_pickup_status'], $cancellableStatuses)) {
        ResponseHandler::error('Order group cannot be cancelled because pickup has already started', 400);
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
                dropx_pickup_status = 'cancelled',
                updated_at = NOW()
             WHERE id = :group_id"
        );
        
        $updateGroupStmt->execute([':group_id' => $orderGroupId]);

        // Add group tracking entry
        $trackingSql = "INSERT INTO group_tracking (
            order_group_id, status, message, created_at
        ) VALUES (
            :group_id, 'cancelled', :message, NOW()
        )";
        
        $trackingStmt = $conn->prepare($trackingSql);
        $trackingStmt->execute([
            ':group_id' => $orderGroupId,
            ':message' => "Order cancelled: " . ($reason ?: 'No reason provided')
        ]);

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

        // Update pickup points
        $updatePickupsStmt = $conn->prepare(
            "UPDATE group_pickups SET 
                pickup_status = 'cancelled',
                notes = :reason
             WHERE order_group_id = :group_id"
        );
        $updatePickupsStmt->execute([
            ':group_id' => $orderGroupId,
            ':reason' => $reason
        ]);

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
            ':description' => "Cancelled order group #" . $group['id'] . " with " . count($orders) . " orders"
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
 * GET LATEST ACTIVE ORDER
 *********************************/
function getLatestActiveOrder($conn, $userId) {
    $activeStatuses = ['pending', 'pickup_in_progress', 'all_picked_up', 'on_delivery'];
    
    // Create placeholders for the IN clause
    $placeholders = [];
    foreach ($activeStatuses as $index => $status) {
        $placeholders[] = ":status_$index";
    }
    $placeholdersStr = implode(',', $placeholders);
    
    $sql = "SELECT 
                og.id as group_id,
                og.dropx_tracking_id,
                og.dropx_pickup_status,
                og.dropx_estimated_delivery_time,
                og.total_amount,
                COUNT(DISTINCT o.merchant_id) as merchant_count,
                GROUP_CONCAT(DISTINCT m.name SEPARATOR ', ') as merchant_names,
                (
                    SELECT message 
                    FROM group_tracking 
                    WHERE order_group_id = og.id 
                    ORDER BY created_at DESC 
                    LIMIT 1
                ) as tracking_message
            FROM order_groups og
            LEFT JOIN orders o ON og.id = o.order_group_id
            LEFT JOIN merchants m ON o.merchant_id = m.id
            WHERE og.user_id = :user_id 
            AND og.dropx_pickup_status IN ($placeholdersStr)
            GROUP BY og.id
            ORDER BY og.created_at DESC
            LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    
    // Bind user_id
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    
    // Bind each status
    foreach ($activeStatuses as $index => $status) {
        $stmt->bindValue(":status_$index", $status, PDO::PARAM_STR);
    }
    
    $stmt->execute();
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$group) {
        ResponseHandler::success(['order' => null, 'message' => 'No active orders']);
        return;
    }
    
    ResponseHandler::success([
        'order' => [
            'group_id' => $group['group_id'],
            'dropx_tracking_id' => $group['dropx_tracking_id'],
            'tracking_url' => "https://dropx.com/track/" . $group['dropx_tracking_id'],
            'status' => $group['dropx_pickup_status'],
            'tracking_message' => $group['tracking_message'],
            'merchant_count' => (int)$group['merchant_count'],
            'merchant_names' => $group['merchant_names'],
            'total_amount' => floatval($group['total_amount']),
            'estimated_delivery' => $group['dropx_estimated_delivery_time']
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
        "SELECT o.id, o.status, o.order_group_id, og.status as group_status,
                og.dropx_pickup_status
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

    // Check if group pickup hasn't started
    if ($order['order_group_id'] && $order['dropx_pickup_status'] !== 'pending') {
        ResponseHandler::error('Cannot cancel individual order after pickup has started', 400);
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

        // Update pickup point status if in group
        if ($order['order_group_id']) {
            $updatePickupStmt = $conn->prepare(
                "UPDATE group_pickups SET 
                    pickup_status = 'cancelled',
                    notes = :reason
                 WHERE order_group_id = :group_id AND merchant_id = (
                     SELECT merchant_id FROM orders WHERE id = :order_id
                 )"
            );
            $updatePickupStmt->execute([
                ':group_id' => $order['order_group_id'],
                ':order_id' => $orderId,
                ':reason' => $reason
            ]);
        }

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
                        dropx_pickup_status = 'cancelled',
                        updated_at = NOW()
                     WHERE id = :group_id"
                );
                $updateGroupStmt->execute([':group_id' => $order['order_group_id']]);
                
                // Add group tracking entry
                $groupTrackingSql = "INSERT INTO group_tracking (
                    order_group_id, status, message, created_at
                ) VALUES (
                    :group_id, 'cancelled', 'All orders in group cancelled', NOW()
                )";
                $groupTrackingStmt = $conn->prepare($groupTrackingSql);
                $groupTrackingStmt->execute([':group_id' => $order['order_group_id']]);
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
        "SELECT o.id, o.status, o.order_group_id, og.dropx_pickup_status
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

    // Check if order can be modified
    $modifiableStatuses = ['pending'];
    if (!in_array($order['status'], $modifiableStatuses)) {
        ResponseHandler::error('Order cannot be modified at this stage', 400);
    }

    // Check if group pickup hasn't started
    if ($order['order_group_id'] && $order['dropx_pickup_status'] !== 'pending') {
        ResponseHandler::error('Cannot modify order after pickup has started', 400);
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
        "SELECT o.id, o.status, o.order_group_id, og.dropx_pickup_status
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

    // Check if order can have address changed
    $addressChangeableStatuses = ['pending', 'confirmed'];
    if (!in_array($order['status'], $addressChangeableStatuses)) {
        ResponseHandler::error('Delivery address cannot be changed at this stage', 400);
    }

    // Check if group pickup hasn't started
    if ($order['order_group_id'] && $order['dropx_pickup_status'] !== 'pending') {
        ResponseHandler::error('Cannot change address after pickup has started', 400);
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

        // Check if all orders in group have same address and update group if needed
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
            
            // If this is the only order or all orders have same address, update group location
            if ($groupCheck['total'] == $groupCheck['matching']) {
                // Update group tracking with new address
                $groupTrackingSql = "INSERT INTO group_tracking (
                    order_group_id, status, location_address, message, created_at
                ) VALUES (
                    :group_id, 'address_updated', :address, 
                    'Delivery address updated', NOW()
                )";
                $groupTrackingStmt = $conn->prepare($groupTrackingSql);
                $groupTrackingStmt->execute([
                    ':group_id' => $order['order_group_id'],
                    ':address' => $newAddress
                ]);
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
?>