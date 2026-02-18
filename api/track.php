<?php
/*********************************
 * ORDER TRACKING API - track.php
 * Handles all tracking-related endpoints for DropX
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Session-Token, Cookie");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * ERROR REPORTING FOR DEBUGGING
 *********************************/
error_reporting(E_ALL);
ini_set('display_errors', 1);

/*********************************
 * SESSION CONFIGURATION
 *********************************/
function initializeSession() {
    // Check if session token is in headers
    $sessionToken = null;
    
    // 1. Check X-Session-Token header
    if (isset($_SERVER['HTTP_X_SESSION_TOKEN'])) {
        $sessionToken = $_SERVER['HTTP_X_SESSION_TOKEN'];
    }
    
    // 2. Check Cookie header for PHPSESSID
    if (!$sessionToken && isset($_SERVER['HTTP_COOKIE'])) {
        $cookies = [];
        parse_str(str_replace('; ', '&', $_SERVER['HTTP_COOKIE']), $cookies);
        if (isset($cookies['PHPSESSID'])) {
            $sessionToken = $cookies['PHPSESSID'];
        }
    }
    
    if (!$sessionToken) {
        return false;
    }
    
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);
    
    session_id($sessionToken);
    session_start();
    
    return true;
}

/*********************************
 * AUTHENTICATION CHECK
 *********************************/
function checkAuthentication() {
    initializeSession();
    
    if (empty($_SESSION['user_id']) || empty($_SESSION['logged_in'])) {
        return false;
    }
    
    return $_SESSION['user_id'];
}

/*********************************
 * BASE URL CONFIGURATION
 *********************************/
$baseUrl = "https://dropx12-production.up.railway.app";

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * MAIN ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'POST') {
        handlePostRequest();
    } else {
        ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    error_log("Track API Error: " . $e->getMessage());
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * POST REQUESTS HANDLER
 *********************************/
function handlePostRequest() {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? '';
    
    $userId = checkAuthentication();
    if ($userId === false && !in_array($action, ['track_order', 'track_order_group'])) {
        ResponseHandler::error('Authentication required. Please login.', 401);
    }

    switch ($action) {
        case 'track_order':
            $orderIdentifier = $input['order_identifier'] ?? '';
            if (!$orderIdentifier) {
                ResponseHandler::error('Order identifier is required', 400);
            }
            getOrderTracking($conn, $orderIdentifier, $baseUrl, $userId);
            break;
            
        case 'track_order_group':
            $orderGroupId = $input['order_group_id'] ?? '';
            if (!$orderGroupId) {
                ResponseHandler::error('Order group ID is required', 400);
            }
            trackOrderGroup($conn, $orderGroupId, $baseUrl, $userId);
            break;
            
        case 'get_trackable':
            $limit = $input['limit'] ?? 50;
            $sortBy = $input['sort_by'] ?? 'created_at';
            $sortOrder = $input['sort_order'] ?? 'DESC';
            getTrackableOrders($conn, $userId, $limit, $sortBy, $sortOrder);
            break;
            
        case 'get_trackable_groups':
            $limit = $input['limit'] ?? 50;
            $sortBy = $input['sort_by'] ?? 'created_at';
            $sortOrder = $input['sort_order'] ?? 'DESC';
            getTrackableOrderGroups($conn, $userId, $limit, $sortBy, $sortOrder);
            break;
            
        case 'driver_location':
            $orderId = $input['order_id'] ?? '';
            if (!$orderId) {
                ResponseHandler::error('Order ID is required', 400);
            }
            getDriverLocation($conn, $userId, $orderId);
            break;
            
        case 'driver_location_group':
            $orderGroupId = $input['order_group_id'] ?? '';
            if (!$orderGroupId) {
                ResponseHandler::error('Order group ID is required', 400);
            }
            getGroupDriverLocations($conn, $userId, $orderGroupId);
            break;
            
        case 'realtime_updates':
            $orderId = $input['order_id'] ?? '';
            $lastUpdate = $input['last_update'] ?? null;
            if (!$orderId) {
                ResponseHandler::error('Order ID is required', 400);
            }
            getRealTimeUpdates($conn, $userId, $orderId, $lastUpdate);
            break;
            
        case 'realtime_updates_group':
            $orderGroupId = $input['order_group_id'] ?? '';
            $lastUpdate = $input['last_update'] ?? null;
            if (!$orderGroupId) {
                ResponseHandler::error('Order group ID is required', 400);
            }
            getGroupRealTimeUpdates($conn, $userId, $orderGroupId, $lastUpdate);
            break;
            
        case 'rate_delivery':
            $orderId = $input['order_id'] ?? '';
            $rating = floatval($input['rating'] ?? 0);
            $punctualityRating = intval($input['punctuality_rating'] ?? 0);
            $professionalismRating = intval($input['professionalism_rating'] ?? 0);
            $comment = $input['comment'] ?? '';
            
            if (!$orderId) {
                ResponseHandler::error('Order ID is required', 400);
            }
            if ($rating < 1 || $rating > 5) {
                ResponseHandler::error('Rating must be between 1 and 5', 400);
            }
            rateDelivery($conn, $userId, $orderId, $rating, $punctualityRating, $professionalismRating, $comment);
            break;
            
        case 'rate_group_deliveries':
            $orderGroupId = $input['order_group_id'] ?? '';
            $ratings = $input['ratings'] ?? [];
            
            if (!$orderGroupId) {
                ResponseHandler::error('Order group ID is required', 400);
            }
            if (empty($ratings)) {
                ResponseHandler::error('Ratings are required', 400);
            }
            rateGroupDeliveries($conn, $userId, $orderGroupId, $ratings);
            break;
            
        case 'call_driver':
            $orderId = $input['order_id'] ?? '';
            if (!$orderId) {
                ResponseHandler::error('Order ID is required', 400);
            }
            getDriverContact($conn, $userId, $orderId);
            break;
            
        case 'call_driver_group':
            $orderGroupId = $input['order_group_id'] ?? '';
            if (!$orderGroupId) {
                ResponseHandler::error('Order group ID is required', 400);
            }
            getGroupDriverContacts($conn, $userId, $orderGroupId);
            break;
            
        case 'share_tracking':
            $orderId = $input['order_id'] ?? '';
            if (!$orderId) {
                ResponseHandler::error('Order ID is required', 400);
            }
            shareTracking($conn, $userId, $orderId);
            break;
            
        case 'share_tracking_group':
            $orderGroupId = $input['order_group_id'] ?? '';
            if (!$orderGroupId) {
                ResponseHandler::error('Order group ID is required', 400);
            }
            shareGroupTracking($conn, $userId, $orderGroupId);
            break;
            
        case 'cancel_order':
            $orderId = $input['order_id'] ?? '';
            $reason = $input['reason'] ?? '';
            if (!$orderId) {
                ResponseHandler::error('Order ID is required', 400);
            }
            cancelOrderFromTracking($conn, $userId, $orderId, $reason);
            break;
            
        case 'cancel_order_group':
            $orderGroupId = $input['order_group_id'] ?? '';
            $reason = $input['reason'] ?? '';
            if (!$orderGroupId) {
                ResponseHandler::error('Order group ID is required', 400);
            }
            cancelOrderGroupFromTracking($conn, $userId, $orderGroupId, $reason);
            break;
            
        case 'contact_support':
            $orderId = $input['order_id'] ?? '';
            $issue = $input['issue'] ?? '';
            $details = $input['details'] ?? '';
            if (!$issue) {
                ResponseHandler::error('Issue description is required', 400);
            }
            contactOrderSupport($conn, $userId, $orderId, $issue, $details);
            break;
            
        case 'contact_support_group':
            $orderGroupId = $input['order_group_id'] ?? '';
            $issue = $input['issue'] ?? '';
            $details = $input['details'] ?? '';
            if (!$issue) {
                ResponseHandler::error('Issue description is required', 400);
            }
            contactGroupSupport($conn, $userId, $orderGroupId, $issue, $details);
            break;
            
        case 'latest_active':
            getLatestActiveOrder($conn, $userId);
            break;
            
        case 'latest_active_group':
            getLatestActiveOrderGroup($conn, $userId);
            break;
            
        default:
            ResponseHandler::error('Invalid action: ' . $action, 400);
    }
}

/*********************************
 * TRACK ORDER GROUP
 *********************************/
function trackOrderGroup($conn, $orderGroupId, $baseUrl, $userId = null) {
    // Get order group info
    $groupSql = "SELECT 
                    og.*,
                    u.full_name as user_name,
                    u.phone as user_phone,
                    u.email as user_email
                FROM order_groups og
                LEFT JOIN users u ON og.user_id = u.id
                WHERE og.id = :group_id";
    
    $params = [':group_id' => $orderGroupId];
    
    // If user is authenticated, check ownership
    if ($userId) {
        $groupSql .= " AND og.user_id = :user_id";
        $params[':user_id'] = $userId;
    }
    
    $groupStmt = $conn->prepare($groupSql);
    $groupStmt->execute($params);
    $group = $groupStmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        ResponseHandler::error('Order group not found', 404);
    }

    // Get all orders in this group with their tracking info
    $ordersSql = "SELECT 
                    o.id,
                    o.order_number,
                    o.user_id,
                    o.merchant_id,
                    o.driver_id,
                    o.subtotal,
                    o.delivery_fee,
                    o.total_amount,
                    o.payment_method,
                    o.delivery_address,
                    o.special_instructions,
                    o.status,
                    o.created_at,
                    o.updated_at,
                    o.cancellation_reason,
                    m.name as merchant_name,
                    m.address as merchant_address,
                    m.phone as merchant_phone,
                    m.image_url as merchant_image,
                    m.latitude as merchant_latitude,
                    m.longitude as merchant_longitude,
                    d.name as driver_name,
                    d.phone as driver_phone,
                    d.current_latitude,
                    d.current_longitude,
                    d.vehicle_type,
                    d.vehicle_number,
                    d.image_url as driver_image,
                    d.rating as driver_rating,
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
                    ) as estimated_delivery,
                    (
                        SELECT location_updates 
                        FROM order_tracking 
                        WHERE order_id = o.id 
                        ORDER BY created_at DESC 
                        LIMIT 1
                    ) as location_updates
                FROM orders o
                LEFT JOIN merchants m ON o.merchant_id = m.id
                LEFT JOIN drivers d ON o.driver_id = d.id
                WHERE o.order_group_id = :group_id
                ORDER BY o.created_at";

    $ordersStmt = $conn->prepare($ordersSql);
    $ordersStmt->execute([':group_id' => $orderGroupId]);
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all order items for this group
    $orderIds = array_column($orders, 'id');
    $itemsByOrder = [];
    
    if (!empty($orderIds)) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        
        $itemsSql = "SELECT 
                        oi.order_id,
                        oi.id,
                        oi.item_name as name,
                        oi.quantity,
                        oi.unit_price as price,
                        oi.total_price as total,
                        oi.created_at,
                        mi.image_url as item_image,
                        mi.description as item_description
                    FROM order_items oi
                    LEFT JOIN menu_items mi ON oi.item_name = mi.name AND mi.merchant_id = (
                        SELECT merchant_id FROM orders WHERE id = oi.order_id
                    )
                    WHERE oi.order_id IN ($placeholders)
                    ORDER BY oi.id";
        
        $itemsStmt = $conn->prepare($itemsSql);
        $itemsStmt->execute($orderIds);
        $allItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($allItems as $item) {
            $itemsByOrder[$item['order_id']][] = $item;
        }
    }

    // Format each order's tracking data
    $formattedOrders = [];
    $groupProgress = 0;
    $totalProgress = 0;
    $completedOrders = 0;
    
    foreach ($orders as $order) {
        // Format merchant image
        $merchantImage = formatImageUrl($order['merchant_image'], $baseUrl, 'merchants');
        
        // Format driver image
        $driverImage = formatImageUrl($order['driver_image'], $baseUrl, 'drivers');
        
        // Calculate progress for this order
        $orderProgress = calculateDeliveryProgress($order['status']);
        $totalProgress += $orderProgress;
        
        if ($orderProgress >= 1.0) {
            $completedOrders++;
        }
        
        // Parse location updates
        $locationUpdates = [];
        if (!empty($order['location_updates'])) {
            $locationUpdates = json_decode($order['location_updates'], true) ?: [];
        }
        
        // Get items for this order
        $orderItems = $itemsByOrder[$order['id']] ?? [];
        
        // Format delivery address
        $deliveryAddress = formatDeliveryAddress($order);
        
        // Build driver info
        $driverInfo = null;
        if ($order['driver_id']) {
            $driverInfo = [
                'id' => $order['driver_id'],
                'name' => $order['driver_name'] ?? 'Driver',
                'phone' => $order['driver_phone'] ?? '',
                'vehicle' => $order['vehicle_type'] ?? 'Motorcycle',
                'vehicle_number' => $order['vehicle_number'] ?? '',
                'rating' => floatval($order['driver_rating'] ?? 4.5),
                'image_url' => $driverImage,
                'latitude' => floatval($order['current_latitude'] ?? 0),
                'longitude' => floatval($order['current_longitude'] ?? 0)
            ];
        }
        
        // Build merchant info
        $merchantInfo = [
            'id' => $order['merchant_id'],
            'name' => $order['merchant_name'],
            'address' => $order['merchant_address'] ?? '',
            'phone' => $order['merchant_phone'] ?? '',
            'image_url' => $merchantImage,
            'latitude' => floatval($order['merchant_latitude'] ?? 0),
            'longitude' => floatval($order['merchant_longitude'] ?? 0)
        ];
        
        // Get available actions for this order
        $orderActions = getAvailableActions($order['status']);
        
        $formattedOrders[] = [
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'status' => $order['status'],
            'progress' => $orderProgress,
            'merchant' => $merchantInfo,
            'driver' => $driverInfo,
            'items' => $orderItems,
            'item_count' => count($orderItems),
            'total_items' => array_sum(array_column($orderItems, 'quantity')),
            'delivery_address' => $deliveryAddress ?: $order['delivery_address'],
            'estimated_delivery' => $order['estimated_delivery'],
            'location_updates' => $locationUpdates,
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at'],
            'payment_method' => $order['payment_method'],
            'amounts' => [
                'subtotal' => floatval($order['subtotal']),
                'delivery_fee' => floatval($order['delivery_fee']),
                'total' => floatval($order['total_amount'])
            ],
            'actions' => $orderActions
        ];
    }
    
    // Calculate overall group progress
    $orderCount = count($orders);
    $groupProgress = $orderCount > 0 ? $totalProgress / $orderCount : 0;
    
    // Determine overall group status
    $groupStatus = determineGroupStatus($orders);
    
    // Get available actions for the entire group
    $groupActions = getGroupAvailableActions($orders);
    
    // Build consolidated timeline
    $consolidatedTimeline = buildGroupStatusTimeline($orders);
    
    ResponseHandler::success([
        'group' => [
            'id' => $group['id'],
            'user' => [
                'name' => $group['user_name'] ?? '',
                'phone' => $group['user_phone'] ?? '',
                'email' => $group['user_email'] ?? ''
            ],
            'status' => $groupStatus,
            'original_status' => $group['status'],
            'progress' => $groupProgress,
            'order_count' => $orderCount,
            'completed_orders' => $completedOrders,
            'created_at' => $group['created_at'],
            'updated_at' => $group['updated_at'],
            'total_amount' => floatval($group['total_amount'])
        ],
        'orders' => $formattedOrders,
        'timeline' => $consolidatedTimeline,
        'actions' => $groupActions
    ]);
}

/*********************************
 * GET TRACKABLE ORDER GROUPS
 *********************************/
function getTrackableOrderGroups($conn, $userId, $limit, $sortBy, $sortOrder) {
    // Validate sort parameters
    $allowedSortColumns = ['created_at', 'total_amount'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'created_at';
    $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
    
    // Get groups with at least one trackable order
    $sql = "SELECT DISTINCT
                og.id,
                og.total_amount,
                og.status,
                og.created_at,
                og.updated_at,
                COUNT(o.id) as order_count,
                GROUP_CONCAT(DISTINCT m.name SEPARATOR ', ') as merchant_names,
                SUM(CASE 
                    WHEN o.status IN ('confirmed', 'preparing', 'ready', 'picked_up', 'on_the_way', 'arrived') 
                    THEN 1 ELSE 0 
                END) as trackable_orders
            FROM order_groups og
            JOIN orders o ON og.id = o.order_group_id
            LEFT JOIN merchants m ON o.merchant_id = m.id
            WHERE og.user_id = :user_id
            GROUP BY og.id
            HAVING trackable_orders > 0
            ORDER BY og.$sortBy $sortOrder
            LIMIT :limit";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedGroups = [];
    foreach ($groups as $group) {
        $formattedGroups[] = [
            'id' => $group['id'],
            'merchant_names' => $group['merchant_names'],
            'order_count' => intval($group['order_count']),
            'total_amount' => floatval($group['total_amount']),
            'status' => $group['status'],
            'created_at' => $group['created_at'],
            'updated_at' => $group['updated_at']
        ];
    }
    
    ResponseHandler::success([
        'groups' => $formattedGroups
    ]);
}

/*********************************
 * GET GROUP DRIVER LOCATIONS
 *********************************/
function getGroupDriverLocations($conn, $userId, $orderGroupId) {
    // Verify group belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id FROM order_groups 
         WHERE id = ? AND user_id = ?"
    );
    $checkStmt->execute([$orderGroupId, $userId]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Order group not found or not authorized', 403);
    }

    // Get all drivers for orders in this group
    $stmt = $conn->prepare(
        "SELECT 
            o.id as order_id,
            o.order_number,
            o.status,
            d.id as driver_id,
            d.name as driver_name,
            d.phone as driver_phone,
            d.current_latitude,
            d.current_longitude,
            d.vehicle_type,
            d.vehicle_number,
            d.image_url,
            d.rating,
            m.name as merchant_name
        FROM orders o
        LEFT JOIN drivers d ON o.driver_id = d.id
        LEFT JOIN merchants m ON o.merchant_id = m.id
        WHERE o.order_group_id = ? AND o.driver_id IS NOT NULL"
    );
    $stmt->execute([$orderGroupId]);
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedDrivers = [];
    foreach ($drivers as $driver) {
        $driverImage = formatImageUrl($driver['image_url'], $GLOBALS['baseUrl'], 'drivers');
        
        $formattedDrivers[] = [
            'order_id' => $driver['order_id'],
            'order_number' => $driver['order_number'],
            'merchant_name' => $driver['merchant_name'],
            'order_status' => $driver['status'],
            'driver' => [
                'id' => $driver['driver_id'],
                'name' => $driver['driver_name'] ?? 'Driver',
                'phone' => $driver['driver_phone'] ?? '',
                'vehicle' => $driver['vehicle_type'] ?? 'Motorcycle',
                'vehicle_number' => $driver['vehicle_number'] ?? '',
                'rating' => floatval($driver['rating'] ?? 4.5),
                'image_url' => $driverImage,
                'location' => [
                    'latitude' => floatval($driver['current_latitude'] ?? 0),
                    'longitude' => floatval($driver['current_longitude'] ?? 0)
                ]
            ]
        ];
    }

    ResponseHandler::success([
        'order_group_id' => $orderGroupId,
        'drivers' => $formattedDrivers
    ]);
}

/*********************************
 * GET GROUP REAL-TIME UPDATES
 *********************************/
function getGroupRealTimeUpdates($conn, $userId, $orderGroupId, $lastUpdate = null) {
    // Verify group belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id FROM order_groups 
         WHERE id = ? AND user_id = ?"
    );
    $checkStmt->execute([$orderGroupId, $userId]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Order group not found or not authorized', 403);
    }

    // Parse last update timestamp
    $lastUpdateTime = null;
    if ($lastUpdate) {
        try {
            $lastUpdateTime = date('Y-m-d H:i:s', strtotime($lastUpdate));
        } catch (Exception $e) {
            $lastUpdateTime = null;
        }
    }

    // Get all orders in group with their latest updates
    $stmt = $conn->prepare(
        "SELECT 
            o.id,
            o.order_number,
            o.status,
            o.updated_at as order_updated_at,
            d.name as driver_name,
            d.current_latitude,
            d.current_longitude,
            d.updated_at as driver_updated_at,
            ot.status as tracking_status,
            ot.location_updates,
            ot.estimated_delivery,
            ot.updated_at as tracking_updated_at
        FROM orders o
        LEFT JOIN drivers d ON o.driver_id = d.id
        LEFT JOIN order_tracking ot ON o.id = ot.order_id
        WHERE o.order_group_id = ?
        AND (? IS NULL OR 
            o.updated_at > ? OR 
            d.updated_at > ? OR 
            ot.updated_at > ?)
        ORDER BY o.id, ot.updated_at DESC";
    
    $stmt->execute([
        $orderGroupId, 
        $lastUpdateTime, 
        $lastUpdateTime,
        $lastUpdateTime,
        $lastUpdateTime
    ]);
    
    $updates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group updates by order
    $orderUpdates = [];
    foreach ($updates as $update) {
        if (!isset($orderUpdates[$update['id']])) {
            $orderUpdates[$update['id']] = [
                'order_id' => $update['id'],
                'order_number' => $update['order_number'],
                'status' => $update['status'],
                'driver_location' => [
                    'latitude' => floatval($update['current_latitude'] ?? 0),
                    'longitude' => floatval($update['current_longitude'] ?? 0),
                    'timestamp' => $update['driver_updated_at']
                ],
                'tracking' => [
                    'status' => $update['tracking_status'],
                    'estimated_delivery' => $update['estimated_delivery'],
                    'location_updates' => json_decode($update['location_updates'] ?? '[]', true),
                    'updated_at' => $update['tracking_updated_at']
                ],
                'updated_at' => max($update['order_updated_at'], $update['driver_updated_at'], $update['tracking_updated_at'])
            ];
        }
    }

    ResponseHandler::success([
        'success' => true,
        'has_updates' => !empty($orderUpdates),
        'order_updates' => array_values($orderUpdates),
        'server_timestamp' => date('Y-m-d H:i:s')
    ]);
}

/*********************************
 * RATE GROUP DELIVERIES
 *********************************/
function rateGroupDeliveries($conn, $userId, $orderGroupId, $ratings) {
    // Verify group belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id FROM order_groups 
         WHERE id = ? AND user_id = ?"
    );
    $checkStmt->execute([$orderGroupId, $userId]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Order group not found or not authorized', 403);
    }

    // Validate all ratings
    foreach ($ratings as $rating) {
        if (empty($rating['order_id']) || empty($rating['rating'])) {
            ResponseHandler::error('Each rating must include order_id and rating', 400);
        }
        if ($rating['rating'] < 1 || $rating['rating'] > 5) {
            ResponseHandler::error('Rating must be between 1 and 5', 400);
        }
    }

    // In a real implementation, you'd insert these ratings into a driver_ratings table
    // For now, just return success
    
    ResponseHandler::success([
        'rated_orders' => count($ratings),
        'message' => 'Thank you for your feedback on all deliveries!'
    ]);
}

/*********************************
 * GET GROUP DRIVER CONTACTS
 *********************************/
function getGroupDriverContacts($conn, $userId, $orderGroupId) {
    // Verify group belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id FROM order_groups 
         WHERE id = ? AND user_id = ?"
    );
    $checkStmt->execute([$orderGroupId, $userId]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Order group not found or not authorized', 403);
    }

    // Get all drivers for this group
    $stmt = $conn->prepare(
        "SELECT 
            o.id as order_id,
            o.order_number,
            d.id as driver_id,
            d.name as driver_name,
            d.phone as driver_phone,
            m.name as merchant_name
        FROM orders o
        JOIN drivers d ON o.driver_id = d.id
        JOIN merchants m ON o.merchant_id = m.id
        WHERE o.order_group_id = ? AND o.driver_id IS NOT NULL"
    );
    $stmt->execute([$orderGroupId]);
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedDrivers = [];
    foreach ($drivers as $driver) {
        $formattedDrivers[] = [
            'order_id' => $driver['order_id'],
            'order_number' => $driver['order_number'],
            'merchant_name' => $driver['merchant_name'],
            'driver' => [
                'id' => $driver['driver_id'],
                'name' => $driver['driver_name'],
                'phone' => $driver['driver_phone'],
                'callable' => true
            ]
        ];
    }

    ResponseHandler::success([
        'order_group_id' => $orderGroupId,
        'drivers' => $formattedDrivers
    ]);
}

/*********************************
 * SHARE GROUP TRACKING
 *********************************/
function shareGroupTracking($conn, $userId, $orderGroupId) {
    // Verify group belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id FROM order_groups 
         WHERE id = ? AND user_id = ?"
    );
    $checkStmt->execute([$orderGroupId, $userId]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Order group not found or not authorized', 403);
    }

    // Get group details for sharing
    $stmt = $conn->prepare(
        "SELECT 
            og.id,
            og.total_amount,
            GROUP_CONCAT(DISTINCT m.name SEPARATOR ', ') as merchant_names,
            COUNT(o.id) as order_count
        FROM order_groups og
        JOIN orders o ON og.id = o.order_group_id
        JOIN merchants m ON o.merchant_id = m.id
        WHERE og.id = ?
        GROUP BY og.id"
    );
    $stmt->execute([$orderGroupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    // Generate tracking URL for the group
    $trackingUrl = "https://dropx12-production.up.railway.app/api/track/group/" . $group['id'];
    
    // Generate sharing message
    $message = "Track my multi-restaurant order from " . $group['merchant_names'] . ":\n" .
               "Total: $" . number_format($group['total_amount'], 2) . "\n" .
               "Orders: " . $group['order_count'] . " different restaurants\n" .
               "Track all here: " . $trackingUrl;

    ResponseHandler::success([
        'order_group_id' => $group['id'],
        'tracking_url' => $trackingUrl,
        'share_message' => $message,
        'merchant_names' => $group['merchant_names'],
        'order_count' => intval($group['order_count']),
        'total_amount' => floatval($group['total_amount'])
    ]);
}

/*********************************
 * CANCEL ORDER GROUP FROM TRACKING
 *********************************/
function cancelOrderGroupFromTracking($conn, $userId, $orderGroupId, $reason) {
    // Verify group belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id, status FROM order_groups 
         WHERE id = ? AND user_id = ?"
    );
    $checkStmt->execute([$orderGroupId, $userId]);
    
    $group = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$group) {
        ResponseHandler::error('Order group not found or not authorized', 404);
    }

    // Check if group can be cancelled
    if ($group['status'] !== 'pending') {
        ResponseHandler::error('This order group cannot be cancelled at this stage', 400);
    }

    // Get all orders in this group
    $ordersStmt = $conn->prepare(
        "SELECT id, status FROM orders WHERE order_group_id = ?"
    );
    $ordersStmt->execute([$orderGroupId]);
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

    // Check if any orders cannot be cancelled
    $cancellableStatuses = ['pending', 'confirmed'];
    foreach ($orders as $order) {
        if (!in_array($order['status'], $cancellableStatuses)) {
            ResponseHandler::error('Some orders in this group cannot be cancelled at this stage', 400);
        }
    }

    $conn->beginTransaction();

    try {
        // Update group status
        $updateGroupStmt = $conn->prepare(
            "UPDATE order_groups 
             SET status = 'cancelled', 
                 updated_at = NOW()
             WHERE id = ?"
        );
        $updateGroupStmt->execute([$orderGroupId]);

        // Cancel each order
        foreach ($orders as $order) {
            // Update order status
            $updateOrderStmt = $conn->prepare(
                "UPDATE orders 
                 SET status = 'cancelled', 
                     cancellation_reason = ?,
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $updateOrderStmt->execute([$reason, $order['id']]);

            // Update tracking
            $trackingStmt = $conn->prepare(
                "INSERT INTO order_tracking 
                    (order_id, status, created_at, updated_at)
                 VALUES (?, 'cancelled', NOW(), NOW())
                 ON DUPLICATE KEY UPDATE 
                    status = 'cancelled',
                    updated_at = NOW()"
            );
            $trackingStmt->execute([$order['id']]);
        }

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
 * CONTACT GROUP SUPPORT
 *********************************/
function contactGroupSupport($conn, $userId, $orderGroupId, $issue, $details) {
    ResponseHandler::success([
        'ticket_id' => rand(1000, 9999),
        'order_group_id' => $orderGroupId,
        'message' => 'Support request received for your order group. We\'ll contact you shortly.'
    ]);
}

/*********************************
 * GET LATEST ACTIVE ORDER GROUP
 *********************************/
function getLatestActiveOrderGroup($conn, $userId) {
    $activeStatuses = ['pending', 'confirmed', 'preparing', 'ready', 'picked_up', 'on_the_way', 'arrived'];
    
    $sql = "SELECT 
                og.id,
                og.total_amount,
                og.status,
                og.created_at,
                og.updated_at,
                COUNT(o.id) as order_count,
                GROUP_CONCAT(DISTINCT m.name SEPARATOR ', ') as merchant_names
            FROM order_groups og
            JOIN orders o ON og.id = o.order_group_id
            JOIN merchants m ON o.merchant_id = m.id
            WHERE og.user_id = :user_id
            AND o.status IN (" . implode(',', array_fill(0, count($activeStatuses), '?')) . ")
            GROUP BY og.id
            ORDER BY og.created_at DESC
            LIMIT 1";
    
    $params = array_merge([$userId], $activeStatuses);
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$group) {
        ResponseHandler::success(['group' => null, 'message' => 'No active order groups']);
        return;
    }
    
    ResponseHandler::success([
        'group' => [
            'id' => $group['id'],
            'merchant_names' => $group['merchant_names'],
            'order_count' => intval($group['order_count']),
            'status' => $group['status'],
            'created_at' => $group['created_at'],
            'updated_at' => $group['updated_at'],
            'total_amount' => floatval($group['total_amount'])
        ]
    ]);
}

/*********************************
 * GET ORDER TRACKING - ORIGINAL FUNCTION (UPDATED)
 *********************************/
function getOrderTracking($conn, $orderIdentifier, $baseUrl, $userId = null) {
    // Check if order identifier is order_number or order_id
    $isOrderNumber = !is_numeric($orderIdentifier) && preg_match('/^[A-Za-z0-9_-]+$/', $orderIdentifier);
    
    $sql = "SELECT 
                o.id,
                o.order_number,
                o.user_id,
                o.merchant_id,
                o.driver_id,
                o.subtotal,
                o.delivery_fee,
                o.total_amount,
                o.payment_method,
                o.delivery_address,
                o.special_instructions,
                o.status,
                o.created_at,
                o.updated_at,
                o.cancellation_reason,
                o.order_group_id,
                m.name as merchant_name,
                m.address as merchant_address,
                m.phone as merchant_phone,
                m.image_url as merchant_image,
                m.latitude as merchant_latitude,
                m.longitude as merchant_longitude,
                d.name as driver_name,
                d.phone as driver_phone,
                d.current_latitude,
                d.current_longitude,
                d.vehicle_type,
                d.vehicle_number,
                d.image_url as driver_image,
                d.rating as driver_rating,
                u.full_name as user_name,
                u.phone as user_phone,
                u.email as user_email,
                a.full_name as address_name,
                a.phone as address_phone,
                a.address_line1,
                a.address_line2,
                a.city,
                a.neighborhood,
                a.area,
                a.sector,
                a.landmark,
                a.latitude as address_latitude,
                a.longitude as address_longitude
            FROM orders o
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            LEFT JOIN users u ON o.user_id = u.id
            LEFT JOIN addresses a ON u.default_address_id = a.id
            WHERE ";
    
    if ($isOrderNumber) {
        $sql .= "o.order_number = :identifier";
        $params = [':identifier' => $orderIdentifier];
    } else {
        $sql .= "o.id = :identifier";
        $params = [':identifier' => intval($orderIdentifier)];
    }
    
    if ($userId) {
        $sql .= " AND o.user_id = :user_id";
        $params[':user_id'] = $userId;
    }
    
    $sql .= " LIMIT 1";
    
    $orderStmt = $conn->prepare($sql);
    $orderStmt->execute($params);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ResponseHandler::error('Order not found', 404);
    }

    // Get order items
    $itemsStmt = $conn->prepare(
        "SELECT 
            oi.id,
            oi.item_name as name,
            oi.quantity,
            oi.unit_price as price,
            oi.total_price as total,
            oi.created_at,
            mi.image_url as item_image,
            mi.description as item_description,
            mi.category,
            mi.item_type,
            mi.unit_type,
            mi.unit_value
        FROM order_items oi
        LEFT JOIN menu_items mi ON oi.item_name = mi.name AND mi.merchant_id = :merchant_id
        WHERE oi.order_id = :order_id
        ORDER BY oi.id"
    );
    $itemsStmt->execute([
        ':order_id' => $order['id'],
        ':merchant_id' => $order['merchant_id']
    ]);
    $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get order tracking info
    $trackingStmt = $conn->prepare(
        "SELECT 
            id,
            status,
            estimated_delivery,
            location_updates,
            created_at,
            updated_at
        FROM order_tracking
        WHERE order_id = :order_id
        ORDER BY created_at DESC
        LIMIT 1"
    );
    $trackingStmt->execute([':order_id' => $order['id']]);
    $trackingInfo = $trackingStmt->fetch(PDO::FETCH_ASSOC);

    // Get sibling orders in same group if applicable
    $siblingOrders = [];
    if ($order['order_group_id']) {
        $siblingStmt = $conn->prepare(
            "SELECT 
                o.id,
                o.order_number,
                o.status,
                m.name as merchant_name,
                m.image_url as merchant_image
            FROM orders o
            LEFT JOIN merchants m ON o.merchant_id = m.id
            WHERE o.order_group_id = :group_id 
            AND o.id != :order_id
            ORDER BY o.created_at"
        );
        $siblingStmt->execute([
            ':group_id' => $order['order_group_id'],
            ':order_id' => $order['id']
        ]);
        $siblingOrders = $siblingStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format sibling orders
        foreach ($siblingOrders as &$sibling) {
            $sibling['merchant_image'] = formatImageUrl($sibling['merchant_image'], $baseUrl, 'merchants');
        }
    }

    // Format images
    $merchantImage = formatImageUrl($order['merchant_image'], $baseUrl, 'merchants');
    $driverImage = formatImageUrl($order['driver_image'], $baseUrl, 'drivers');

    // Format delivery address
    $deliveryAddress = formatDeliveryAddress($order);

    // Calculate delivery progress
    $deliveryProgress = calculateDeliveryProgress($order['status']);

    // Estimate delivery time
    $estimatedDelivery = estimateDeliveryTime($order, $trackingInfo);

    // Build delivery status timeline
    $deliveryStatus = buildDeliveryStatusTimeline($order, $trackingInfo);

    // Build driver info
    $driverInfo = null;
    if ($order['driver_id']) {
        $driverInfo = buildDriverInfo($order, $driverImage);
    }

    // Build merchant info
    $merchantInfo = buildMerchantInfo($order, $merchantImage);

    // Get available actions
    $actions = getAvailableActions($order['status']);

    ResponseHandler::success([
        'order' => formatOrderData($order, $merchantInfo, $deliveryAddress),
        'items' => $orderItems,
        'sibling_orders' => $siblingOrders,
        'order_group_id' => $order['order_group_id'],
        'tracking' => [
            'current_status' => $order['status'],
            'progress' => $deliveryProgress,
            'estimated_delivery' => $estimatedDelivery,
            'status_timeline' => $deliveryStatus,
            'driver' => $driverInfo,
            'last_updated' => $trackingInfo['updated_at'] ?? $order['updated_at']
        ],
        'actions' => $actions
    ]);
}

/*********************************
 * GET TRACKABLE ORDERS
 *********************************/
function getTrackableOrders($conn, $userId, $limit, $sortBy, $sortOrder) {
    // Validate sort parameters
    $allowedSortColumns = ['created_at', 'order_number', 'total_amount', 'status'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'created_at';
    $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
    
    // Trackable statuses (orders that can be tracked)
    $trackableStatuses = ['confirmed', 'preparing', 'ready', 'picked_up', 'on_the_way', 'arrived'];
    
    // Create placeholders for the IN clause
    $placeholders = implode(',', array_fill(0, count($trackableStatuses), '?'));
    
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
            WHERE o.user_id = ? 
            AND o.status IN ($placeholders)
            ORDER BY o.$sortBy $sortOrder
            LIMIT ?";
    
    $params = array_merge([$userId], $trackableStatuses, [$limit]);
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
 * GET DRIVER LOCATION
 *********************************/
function getDriverLocation($conn, $userId, $orderId) {
    // Verify the order belongs to the user
    $checkStmt = $conn->prepare(
        "SELECT id, driver_id, order_group_id FROM orders 
         WHERE id = ? AND user_id = ?"
    );
    $checkStmt->execute([$orderId, $userId]);
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        ResponseHandler::error('Order not found or not authorized', 403);
    }

    if (!$order['driver_id']) {
        ResponseHandler::error('No driver assigned to this order yet', 404);
    }

    // Get driver location
    $stmt = $conn->prepare(
        "SELECT 
            d.current_latitude,
            d.current_longitude,
            d.name,
            d.phone,
            d.vehicle_type,
            d.vehicle_number,
            d.image_url,
            d.rating,
            o.status,
            o.updated_at as order_updated_at
        FROM orders o
        LEFT JOIN drivers d ON o.driver_id = d.id
        WHERE o.id = ?"
    );
    $stmt->execute([$orderId]);
    $driver = $stmt->fetch(PDO::FETCH_ASSOC);

    // Format driver image URL
    $driverImage = '';
    if (!empty($driver['image_url'])) {
        global $baseUrl;
        $driverImage = formatImageUrl($driver['image_url'], $baseUrl, 'drivers');
    }

    ResponseHandler::success([
        'order_id' => $orderId,
        'order_group_id' => $order['order_group_id'],
        'driver_location' => [
            'latitude' => floatval($driver['current_latitude'] ?? 0),
            'longitude' => floatval($driver['current_longitude'] ?? 0),
            'timestamp' => $driver['order_updated_at'] ?? date('Y-m-d H:i:s')
        ],
        'driver_info' => [
            'name' => $driver['name'] ?? 'Driver',
            'phone' => $driver['phone'] ?? '',
            'vehicle' => $driver['vehicle_type'] ?? 'Motorcycle',
            'vehicle_number' => $driver['vehicle_number'] ?? '',
            'rating' => floatval($driver['rating'] ?? 4.5),
            'image_url' => $driverImage
        ],
        'order_status' => $driver['status']
    ]);
}

/*********************************
 * GET REAL-TIME UPDATES
 *********************************/
function getRealTimeUpdates($conn, $userId, $orderId, $lastUpdate = null) {
    // Verify the order belongs to the user
    $checkStmt = $conn->prepare(
        "SELECT id FROM orders 
         WHERE id = ? AND user_id = ?"
    );
    $checkStmt->execute([$orderId, $userId]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Order not found or not authorized', 403);
    }

    // Parse last update timestamp
    $lastUpdateTime = null;
    if ($lastUpdate) {
        try {
            $lastUpdateTime = date('Y-m-d H:i:s', strtotime($lastUpdate));
        } catch (Exception $e) {
            $lastUpdateTime = null;
        }
    }

    // Check for tracking updates
    $trackingStmt = $conn->prepare(
        "SELECT 
            status,
            location_updates,
            estimated_delivery,
            updated_at
        FROM order_tracking
        WHERE order_id = ?
        AND (? IS NULL OR updated_at > ?)
        ORDER BY updated_at DESC
        LIMIT 1"
    );
    
    $trackingStmt->execute([$orderId, $lastUpdateTime, $lastUpdateTime]);
    $trackingUpdate = $trackingStmt->fetch(PDO::FETCH_ASSOC);

    // Get current driver location
    $driverStmt = $conn->prepare(
        "SELECT 
            d.current_latitude,
            d.current_longitude,
            d.updated_at as timestamp
        FROM orders o
        LEFT JOIN drivers d ON o.driver_id = d.id
        WHERE o.id = ?"
    );
    $driverStmt->execute([$orderId]);
    $driverLocation = $driverStmt->fetch(PDO::FETCH_ASSOC);

    ResponseHandler::success([
        'success' => true,
        'has_updates' => !empty($trackingUpdate),
        'tracking_update' => $trackingUpdate,
        'driver_location' => $driverLocation,
        'server_timestamp' => date('Y-m-d H:i:s')
    ]);
}

/*********************************
 * RATE DELIVERY - SIMPLIFIED
 *********************************/
function rateDelivery($conn, $userId, $orderId, $rating, $punctualityRating, $professionalismRating, $comment) {
    // Verify order belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id, driver_id FROM orders 
         WHERE id = ? AND user_id = ?"
    );
    $checkStmt->execute([$orderId, $userId]);
    
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        ResponseHandler::error('Order not found or not authorized', 404);
    }

    if (!$order['driver_id']) {
        ResponseHandler::error('No driver assigned to this order', 400);
    }

    ResponseHandler::success([], 'Thank you for your feedback!');
}

/*********************************
 * GET DRIVER CONTACT
 *********************************/
function getDriverContact($conn, $userId, $orderId) {
    // Verify order belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id, order_group_id FROM orders 
         WHERE id = ? AND user_id = ?"
    );
    $checkStmt->execute([$orderId, $userId]);
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        ResponseHandler::error('Order not found or not authorized', 403);
    }

    // Get driver phone number
    $stmt = $conn->prepare(
        "SELECT 
            d.phone,
            d.name,
            o.order_number
        FROM orders o
        JOIN drivers d ON o.driver_id = d.id
        WHERE o.id = ?"
    );
    $stmt->execute([$orderId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        ResponseHandler::error('Driver not available', 404);
    }

    ResponseHandler::success([
        'order_id' => $orderId,
        'order_group_id' => $order['order_group_id'],
        'driver' => [
            'name' => $result['name'],
            'phone' => $result['phone'],
            'callable' => true
        ]
    ]);
}

/*********************************
 * SHARE TRACKING
 *********************************/
function shareTracking($conn, $userId, $orderId) {
    // Verify order belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id, order_group_id FROM orders 
         WHERE id = ? AND user_id = ?"
    );
    $checkStmt->execute([$orderId, $userId]);
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        ResponseHandler::error('Order not found or not authorized', 403);
    }

    // Get order details for sharing
    $stmt = $conn->prepare(
        "SELECT 
            o.order_number,
            o.status,
            m.name as merchant_name
        FROM orders o
        JOIN merchants m ON o.merchant_id = m.id
        WHERE o.id = ?"
    );
    $stmt->execute([$orderId]);
    $orderDetails = $stmt->fetch(PDO::FETCH_ASSOC);

    // Generate tracking URL
    $trackingUrl = "https://dropx12-production.up.railway.app/api/track/order/" . $orderDetails['order_number'];
    
    // Generate sharing message
    $message = "Track my order from " . $orderDetails['merchant_name'] . ":\n" .
               "Order: " . $orderDetails['order_number'] . "\n" .
               "Status: " . $orderDetails['status'] . "\n" .
               "Track here: " . $trackingUrl;

    ResponseHandler::success([
        'order_id' => $orderId,
        'order_group_id' => $order['order_group_id'],
        'tracking_url' => $trackingUrl,
        'share_message' => $message,
        'order_number' => $orderDetails['order_number'],
        'status' => $orderDetails['status']
    ]);
}

/*********************************
 * CANCEL ORDER FROM TRACKING
 *********************************/
function cancelOrderFromTracking($conn, $userId, $orderId, $reason) {
    // Verify order belongs to user and can be cancelled
    $checkStmt = $conn->prepare(
        "SELECT id, status, order_group_id FROM orders 
         WHERE id = ? AND user_id = ?"
    );
    $checkStmt->execute([$orderId, $userId]);
    
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        ResponseHandler::error('Order not found or not authorized', 404);
    }

    // Check if order can be cancelled
    $cancellableStatuses = ['pending', 'confirmed', 'preparing'];
    if (!in_array($order['status'], $cancellableStatuses)) {
        ResponseHandler::error('This order cannot be cancelled at this stage', 400);
    }

    $conn->beginTransaction();

    try {
        // Update order status
        $updateStmt = $conn->prepare(
            "UPDATE orders 
             SET status = 'cancelled', 
                 cancellation_reason = ?,
                 updated_at = NOW()
             WHERE id = ?"
        );
        $updateStmt->execute([$reason, $orderId]);

        // Update tracking
        $trackingStmt = $conn->prepare(
            "INSERT INTO order_tracking 
                (order_id, status, created_at, updated_at)
             VALUES (?, 'cancelled', NOW(), NOW())
             ON DUPLICATE KEY UPDATE 
                status = 'cancelled',
                updated_at = NOW()"
        );
        $trackingStmt->execute([$orderId]);

        // Check if group needs updating
        if ($order['order_group_id']) {
            $pendingStmt = $conn->prepare(
                "SELECT COUNT(*) as pending_count 
                 FROM orders 
                 WHERE order_group_id = ? 
                 AND status IN ('pending', 'confirmed', 'preparing', 'ready', 'picked_up', 'on_the_way', 'arrived')"
            );
            $pendingStmt->execute([$order['order_group_id']]);
            $pendingCount = $pendingStmt->fetch(PDO::FETCH_ASSOC)['pending_count'];
            
            if ($pendingCount == 0) {
                $updateGroupStmt = $conn->prepare(
                    "UPDATE order_groups 
                     SET status = 'cancelled', 
                         updated_at = NOW()
                     WHERE id = ?"
                );
                $updateGroupStmt->execute([$order['order_group_id']]);
            }
        }

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
 * CONTACT ORDER SUPPORT - SIMPLIFIED
 *********************************/
function contactOrderSupport($conn, $userId, $orderId, $issue, $details) {
    ResponseHandler::success([
        'ticket_id' => rand(1000, 9999),
        'order_id' => $orderId,
        'message' => 'Support request received. We\'ll contact you shortly.'
    ]);
}

/*********************************
 * GET LATEST ACTIVE ORDER
 *********************************/
function getLatestActiveOrder($conn, $userId) {
    $activeStatuses = ['pending', 'confirmed', 'preparing', 'ready', 'picked_up', 'on_the_way', 'arrived'];
    $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));
    
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
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at'],
            'total_amount' => floatval($order['total_amount']),
            'merchant_name' => $order['merchant_name'],
            'order_group_id' => $order['order_group_id']
        ]
    ]);
}

/*********************************
 * HELPER FUNCTIONS
 *********************************/

function formatImageUrl($imagePath, $baseUrl, $type = '') {
    if (empty($imagePath)) {
        return '';
    }
    
    // If it's already a full URL, use it as is
    if (strpos($imagePath, 'http') === 0) {
        return $imagePath;
    }
    
    $folder = '';
    switch ($type) {
        case 'merchants':
            $folder = 'uploads/merchants';
            break;
        case 'drivers':
            $folder = 'uploads/drivers';
            break;
        default:
            $folder = 'uploads';
    }
    
    return rtrim($baseUrl, '/') . '/' . $folder . '/' . ltrim($imagePath, '/');
}

function formatDeliveryAddress($order) {
    $addressParts = [];
    
    if (!empty($order['address_line1'])) {
        $addressParts[] = $order['address_line1'];
    }
    
    if (!empty($order['address_line2'])) {
        $addressParts[] = $order['address_line2'];
    }
    
    if (!empty($order['neighborhood'])) {
        $addressParts[] = $order['neighborhood'];
    }
    
    if (!empty($order['sector'])) {
        $addressParts[] = $order['sector'];
    }
    
    if (!empty($order['area'])) {
        $addressParts[] = $order['area'];
    }
    
    if (!empty($order['city'])) {
        $addressParts[] = $order['city'];
    }
    
    if (!empty($order['landmark'])) {
        $addressParts[] = 'Near ' . $order['landmark'];
    }
    
    return implode(', ', $addressParts);
}

function calculateDeliveryProgress($status) {
    $statusWeights = [
        'pending' => 0.1,
        'confirmed' => 0.2,
        'preparing' => 0.4,
        'ready' => 0.6,
        'picked_up' => 0.8,
        'on_the_way' => 0.9,
        'arrived' => 0.95,
        'delivered' => 1.0,
        'cancelled' => 0.0,
        'refunded' => 0.0
    ];
    
    return $statusWeights[strtolower($status)] ?? 0.1;
}

function estimateDeliveryTime($order, $trackingInfo) {
    // Try to get estimated delivery from tracking info
    if (!empty($trackingInfo['estimated_delivery'])) {
        return $trackingInfo['estimated_delivery'];
    }
    
    return date('Y-m-d H:i:s', time() + (45 * 60));
}

function buildDeliveryStatusTimeline($order, $trackingInfo) {
    $timeline = [];
    
    // Add order created
    $timeline[] = [
        'status' => 'order_placed',
        'description' => 'Order placed successfully',
        'timestamp' => $order['created_at'],
        'icon' => 'shopping_bag',
        'color' => 'green'
    ];
    
    // Add current status from tracking if available
    if (!empty($trackingInfo['status'])) {
        $timeline[] = [
            'status' => strtolower(str_replace(' ', '_', $trackingInfo['status'])),
            'description' => getStatusDescription($trackingInfo['status']),
            'timestamp' => $trackingInfo['created_at'] ?? $order['updated_at'],
            'icon' => getStatusIcon($trackingInfo['status']),
            'color' => getStatusColor($trackingInfo['status']),
            'is_current' => true
        ];
    }
    
    return $timeline;
}

function buildGroupStatusTimeline($orders) {
    $allEvents = [];
    
    foreach ($orders as $order) {
        $allEvents[] = [
            'order_id' => $order['id'],
            'order_number' => $order['order_number'],
            'merchant_name' => $order['merchant_name'],
            'status' => $order['status'],
            'timestamp' => $order['updated_at'],
            'description' => getStatusDescription($order['status'])
        ];
    }
    
    // Sort by timestamp
    usort($allEvents, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });
    
    return $allEvents;
}

function getStatusDescription($status) {
    $descriptions = [
        'pending' => 'Order has been placed',
        'confirmed' => 'Restaurant has accepted your order',
        'preparing' => 'Chef is preparing your meal',
        'ready' => 'Your order is ready for pickup',
        'picked_up' => 'Driver has picked up your order',
        'on_the_way' => 'Driver is on the way to you',
        'arrived' => 'Driver has arrived at your location',
        'delivered' => 'Order delivered successfully',
        'cancelled' => 'Order has been cancelled'
    ];
    
    return $descriptions[strtolower($status)] ?? 'Status updated';
}

function getStatusIcon($status) {
    $icons = [
        'pending' => 'shopping_bag',
        'confirmed' => 'check_circle',
        'preparing' => 'restaurant',
        'ready' => 'done_all',
        'picked_up' => 'local_shipping',
        'on_the_way' => 'directions_bike',
        'arrived' => 'location_on',
        'delivered' => 'home',
        'cancelled' => 'cancel'
    ];
    
    return $icons[strtolower($status)] ?? 'info';
}

function getStatusColor($status) {
    $colors = [
        'pending' => 'grey',
        'confirmed' => 'blue',
        'preparing' => 'orange',
        'ready' => 'green',
        'picked_up' => 'purple',
        'on_the_way' => 'indigo',
        'arrived' => 'teal',
        'delivered' => 'green',
        'cancelled' => 'red'
    ];
    
    return $colors[strtolower($status)] ?? 'grey';
}

function buildDriverInfo($order, $driverImage) {
    return [
        'id' => $order['driver_id'],
        'name' => $order['driver_name'] ?? 'Driver',
        'phone' => $order['driver_phone'] ?? '',
        'vehicle' => $order['vehicle_type'] ?? 'Motorcycle',
        'vehicle_number' => $order['vehicle_number'] ?? '',
        'rating' => floatval($order['driver_rating'] ?? 4.5),
        'image_url' => $driverImage,
        'latitude' => floatval($order['current_latitude'] ?? 0),
        'longitude' => floatval($order['current_longitude'] ?? 0),
        'is_available' => true
    ];
}

function buildMerchantInfo($order, $merchantImage) {
    return [
        'id' => $order['merchant_id'],
        'name' => $order['merchant_name'],
        'address' => $order['merchant_address'] ?? '',
        'phone' => $order['merchant_phone'] ?? '',
        'image_url' => $merchantImage,
        'latitude' => floatval($order['merchant_latitude'] ?? 0),
        'longitude' => floatval($order['merchant_longitude'] ?? 0)
    ];
}

function formatOrderData($order, $merchantInfo, $deliveryAddress) {
    return [
        'id' => $order['id'],
        'order_number' => $order['order_number'],
        'status' => $order['status'],
        'created_at' => $order['created_at'],
        'updated_at' => $order['updated_at'],
        'order_group_id' => $order['order_group_id'],
        'merchant' => $merchantInfo,
        'delivery_address' => $deliveryAddress ?: $order['delivery_address'],
        'special_instructions' => $order['special_instructions'],
        'payment_method' => $order['payment_method'],
        'amounts' => [
            'subtotal' => floatval($order['subtotal']),
            'delivery_fee' => floatval($order['delivery_fee']),
            'total' => floatval($order['total_amount'])
        ],
        'cancellation_reason' => $order['cancellation_reason']
    ];
}

function getAvailableActions($status) {
    $actions = [
        'share' => true,
        'refresh' => true,
        'contact_support' => true
    ];
    
    switch (strtolower($status)) {
        case 'pending':
        case 'confirmed':
        case 'preparing':
            $actions['cancel'] = true;
            break;
            
        case 'on_the_way':
        case 'arrived':
            $actions['call_driver'] = true;
            break;
            
        case 'delivered':
            $actions['rate'] = true;
            $actions['reorder'] = true;
            break;
            
        case 'cancelled':
            $actions['reorder'] = true;
            break;
    }
    
    return $actions;
}

function getGroupAvailableActions($orders) {
    $actions = [
        'share' => true,
        'refresh' => true,
        'contact_support' => true
    ];
    
    $canCancelAny = false;
    $canCallAnyDriver = false;
    $canRateAny = false;
    $canReorderAny = false;
    
    foreach ($orders as $order) {
        $orderActions = getAvailableActions($order['status']);
        
        if (!empty($orderActions['cancel'])) $canCancelAny = true;
        if (!empty($orderActions['call_driver'])) $canCallAnyDriver = true;
        if (!empty($orderActions['rate'])) $canRateAny = true;
        if (!empty($orderActions['reorder'])) $canReorderAny = true;
    }
    
    if ($canCancelAny) $actions['cancel_group'] = true;
    if ($canCallAnyDriver) $actions['view_all_drivers'] = true;
    if ($canRateAny) $actions['rate_all'] = true;
    if ($canReorderAny) $actions['reorder_group'] = true;
    
    return $actions;
}

function determineGroupStatus($orders) {
    $statuses = array_column($orders, 'status');
    
    if (empty($statuses)) {
        return 'pending';
    }
    
    // If any order is in progress, group is in progress
    $inProgressStatuses = ['confirmed', 'preparing', 'ready', 'picked_up', 'on_the_way', 'arrived'];
    foreach ($statuses as $status) {
        if (in_array($status, $inProgressStatuses)) {
            return 'in_progress';
        }
    }
    
    // If all orders are delivered
    $allDelivered = array_reduce($statuses, function($carry, $status) {
        return $carry && ($status === 'delivered');
    }, true);
    
    if ($allDelivered) {
        return 'completed';
    }
    
    // If all orders are cancelled
    $allCancelled = array_reduce($statuses, function($carry, $status) {
        return $carry && ($status === 'cancelled');
    }, true);
    
    if ($allCancelled) {
        return 'cancelled';
    }
    
    // Otherwise, mixed status
    return 'mixed';
}
?>