<?php
/*********************************
 * ORDER TRACKING API - track.php
 * Handles all tracking-related endpoints for DropX
 * Supports both single merchant and multi-merchant orders
 *********************************/

/*********************************
 * CORS Configuration
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
 * DROPX HUB CONFIGURATION
 * Central office where multi-merchant orders are consolidated
 *********************************/
define('DROPX_HUB', [
    'id' => 'hub_001',
    'name' => 'DropX Central Consolidation Hub',
    'address' => 'Area 3, Lilongwe, Malawi',
    'phone' => '+265 999 123 456',
    'latitude' => -13.962612,
    'longitude' => 33.774119,
    'operating_hours' => '24/7',
    'manager' => 'John Banda'
]);

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
        // Single order tracking
        case 'track_order':
            $orderIdentifier = $input['order_identifier'] ?? '';
            if (!$orderIdentifier) {
                ResponseHandler::error('Order identifier is required', 400);
            }
            getOrderTracking($conn, $orderIdentifier, $baseUrl, $userId);
            break;
            
        // Multi-merchant order group tracking
        case 'track_order_group':
            $orderGroupId = $input['order_group_id'] ?? '';
            if (!$orderGroupId) {
                ResponseHandler::error('Order group ID is required', 400);
            }
            trackOrderGroup($conn, $orderGroupId, $baseUrl, $userId);
            break;
            
        // Get all trackable orders
        case 'get_trackable':
            $limit = $input['limit'] ?? 50;
            $sortBy = $input['sort_by'] ?? 'created_at';
            $sortOrder = $input['sort_order'] ?? 'DESC';
            getTrackableOrders($conn, $userId, $limit, $sortBy, $sortOrder);
            break;
            
        // Get all trackable order groups
        case 'get_trackable_groups':
            $limit = $input['limit'] ?? 50;
            $sortBy = $input['sort_by'] ?? 'created_at';
            $sortOrder = $input['sort_order'] ?? 'DESC';
            getTrackableOrderGroups($conn, $userId, $limit, $sortBy, $sortOrder);
            break;
            
        // Get driver location for single order
        case 'driver_location':
            $orderId = $input['order_id'] ?? '';
            if (!$orderId) {
                ResponseHandler::error('Order ID is required', 400);
            }
            getDriverLocation($conn, $userId, $orderId);
            break;
            
        // Get all driver locations for a group
        case 'driver_location_group':
            $orderGroupId = $input['order_group_id'] ?? '';
            if (!$orderGroupId) {
                ResponseHandler::error('Order group ID is required', 400);
            }
            getGroupDriverLocations($conn, $userId, $orderGroupId);
            break;
            
        // Get real-time updates for single order
        case 'realtime_updates':
            $orderId = $input['order_id'] ?? '';
            $lastUpdate = $input['last_update'] ?? null;
            if (!$orderId) {
                ResponseHandler::error('Order ID is required', 400);
            }
            getRealTimeUpdates($conn, $userId, $orderId, $lastUpdate);
            break;
            
        // Get real-time updates for entire group
        case 'realtime_updates_group':
            $orderGroupId = $input['order_group_id'] ?? '';
            $lastUpdate = $input['last_update'] ?? null;
            if (!$orderGroupId) {
                ResponseHandler::error('Order group ID is required', 400);
            }
            getGroupRealTimeUpdates($conn, $userId, $orderGroupId, $lastUpdate);
            break;
            
        // Call driver (get contact info)
        case 'call_driver':
            $orderId = $input['order_id'] ?? '';
            if (!$orderId) {
                ResponseHandler::error('Order ID is required', 400);
            }
            getDriverContact($conn, $userId, $orderId);
            break;
            
        // Get all driver contacts for a group
        case 'call_driver_group':
            $orderGroupId = $input['order_group_id'] ?? '';
            if (!$orderGroupId) {
                ResponseHandler::error('Order group ID is required', 400);
            }
            getGroupDriverContacts($conn, $userId, $orderGroupId);
            break;
            
        // Share tracking link for single order
        case 'share_tracking':
            $orderId = $input['order_id'] ?? '';
            if (!$orderId) {
                ResponseHandler::error('Order ID is required', 400);
            }
            shareTracking($conn, $userId, $orderId);
            break;
            
        // Share tracking link for entire group
        case 'share_tracking_group':
            $orderGroupId = $input['order_group_id'] ?? '';
            if (!$orderGroupId) {
                ResponseHandler::error('Order group ID is required', 400);
            }
            shareGroupTracking($conn, $userId, $orderGroupId);
            break;
            
        // Get latest active order
        case 'latest_active':
            getLatestActiveOrder($conn, $userId);
            break;
            
        // Get latest active order group
        case 'latest_active_group':
            getLatestActiveOrderGroup($conn, $userId);
            break;
            
        // Get tracking status summary
        case 'tracking_summary':
            getTrackingSummary($conn, $userId);
            break;
            
        default:
            ResponseHandler::error('Invalid action: ' . $action, 400);
    }
}

/*********************************
 * TRACK ORDER GROUP
 * Retrieves detailed tracking for a multi-merchant order group
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
                        oi.total_price as total
                    FROM order_items oi
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
            $locationUpdates = json_decode($order['location_updates'], true);
            if (!is_array($locationUpdates)) {
                $locationUpdates = [];
            }
        }
        
        // Get items for this order
        $orderItems = isset($itemsByOrder[$order['id']]) ? $itemsByOrder[$order['id']] : [];
        
        // Build driver info
        $driverInfo = null;
        if ($order['driver_id']) {
            $driverInfo = [
                'id' => $order['driver_id'],
                'name' => $order['driver_name'] ?? 'Driver',
                'phone' => $order['driver_phone'] ?? '',
                'vehicle' => $order['vehicle_type'] ?? 'Motorcycle',
                'vehicle_number' => $order['vehicle_number'] ?? '',
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
            'delivery_address' => $order['delivery_address'],
            'estimated_delivery' => $order['estimated_delivery'],
            'location_updates' => $locationUpdates,
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at'],
            'payment_method' => $order['payment_method'],
            'amounts' => [
                'subtotal' => floatval($order['subtotal']),
                'delivery_fee' => floatval($order['delivery_fee']),
                'total' => floatval($order['total_amount'])
            ]
        ];
    }
    
    // Calculate overall group progress
    $orderCount = count($orders);
    $groupProgress = $orderCount > 0 ? $totalProgress / $orderCount : 0;
    
    // Determine overall group status
    $groupStatus = determineGroupStatus($orders);
    
    // Build consolidated timeline
    $consolidatedTimeline = buildGroupStatusTimeline($orders);
    
    ResponseHandler::success([
        'group' => [
            'id' => $group['id'],
            'dropx_tracking_id' => $group['dropx_tracking_id'] ?? null,
            'tracking_url' => $group['dropx_tracking_id'] ? "https://dropx.com/track/" . $group['dropx_tracking_id'] : null,
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
        'timeline' => $consolidatedTimeline
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
    
    // Ensure limit is an integer
    $limit = min(max((int)$limit, 1), 100);
    
    // Trackable statuses for orders within groups
    $trackableStatuses = ['confirmed', 'preparing', 'ready', 'picked_up', 'on_the_way', 'arrived'];
    $statusPlaceholders = implode(',', array_fill(0, count($trackableStatuses), '?'));
    
    // Get groups with at least one trackable order
    $sql = "SELECT DISTINCT
                og.id,
                og.total_amount,
                og.status,
                og.created_at,
                og.updated_at,
                og.dropx_tracking_id,
                COUNT(o.id) as order_count,
                GROUP_CONCAT(DISTINCT m.name SEPARATOR ', ') as merchant_names,
                SUM(CASE 
                    WHEN o.status IN ($statusPlaceholders) 
                    THEN 1 ELSE 0 
                END) as trackable_orders
            FROM order_groups og
            JOIN orders o ON og.id = o.order_group_id
            LEFT JOIN merchants m ON o.merchant_id = m.id
            WHERE og.user_id = ?
            GROUP BY og.id
            HAVING trackable_orders > 0
            ORDER BY og.$sortBy $sortOrder
            LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    
    // Build parameters array
    $params = array_merge([$userId], $trackableStatuses, [$limit]);
    
    if (!$stmt->execute($params)) {
        $error = $stmt->errorInfo();
        error_log("SQL Error in getTrackableOrderGroups: " . print_r($error, true));
        ResponseHandler::error('Database error: ' . $error[2], 500);
    }
    
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedGroups = [];
    foreach ($groups as $group) {
        $formattedGroups[] = [
            'id' => $group['id'],
            'dropx_tracking_id' => $group['dropx_tracking_id'],
            'tracking_url' => $group['dropx_tracking_id'] ? "https://dropx.com/track/" . $group['dropx_tracking_id'] : null,
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
        global $baseUrl;
        $driverImage = formatImageUrl($driver['image_url'], $baseUrl, 'drivers');
        
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
    $sql = "SELECT 
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
            WHERE o.order_group_id = ?";
    
    $params = [$orderGroupId];
    
    if ($lastUpdateTime) {
        $sql .= " AND (o.updated_at > ? OR d.updated_at > ? OR ot.updated_at > ?)";
        $params[] = $lastUpdateTime;
        $params[] = $lastUpdateTime;
        $params[] = $lastUpdateTime;
    }
    
    $sql .= " ORDER BY o.id, ot.updated_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $updates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group updates by order
    $orderUpdates = [];
    foreach ($updates as $update) {
        if (!isset($orderUpdates[$update['id']])) {
            // Parse location updates
            $locationUpdates = [];
            if (!empty($update['location_updates'])) {
                $locationUpdates = json_decode($update['location_updates'], true);
                if (!is_array($locationUpdates)) {
                    $locationUpdates = [];
                }
            }
            
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
                    'location_updates' => $locationUpdates,
                    'updated_at' => $update['tracking_updated_at']
                ],
                'updated_at' => max(
                    $update['order_updated_at'] ?? '', 
                    $update['driver_updated_at'] ?? '', 
                    $update['tracking_updated_at'] ?? ''
                )
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
            og.dropx_tracking_id,
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

    // Generate tracking URL
    $trackingUrl = "https://dropx12-production.up.railway.app/api/track/group/" . $group['id'];
    if ($group['dropx_tracking_id']) {
        $trackingUrl = "https://dropx.com/track/" . $group['dropx_tracking_id'];
    }
    
    // Generate sharing message
    $message = "Track my multi-restaurant order from " . $group['merchant_names'] . ":\n" .
               "Total: MK" . number_format($group['total_amount'], 2) . "\n" .
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
 * GET LATEST ACTIVE ORDER GROUP
 *********************************/
function getLatestActiveOrderGroup($conn, $userId) {
    $activeStatuses = ['pending', 'confirmed', 'preparing', 'ready', 'picked_up', 'on_the_way', 'arrived'];
    $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));
    
    $sql = "SELECT 
                og.id,
                og.total_amount,
                og.status,
                og.created_at,
                og.updated_at,
                og.dropx_tracking_id,
                COUNT(o.id) as order_count,
                GROUP_CONCAT(DISTINCT m.name SEPARATOR ', ') as merchant_names
            FROM order_groups og
            JOIN orders o ON og.id = o.order_group_id
            JOIN merchants m ON o.merchant_id = m.id
            WHERE og.user_id = ?";
    
    $sql .= " AND o.status IN (" . implode(',', array_fill(0, count($activeStatuses), '?')) . ")";
    $sql .= " GROUP BY og.id ORDER BY og.created_at DESC LIMIT 1";
    
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
            'dropx_tracking_id' => $group['dropx_tracking_id'],
            'tracking_url' => $group['dropx_tracking_id'] ? "https://dropx.com/track/" . $group['dropx_tracking_id'] : null,
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
 * GET TRACKING SUMMARY
 * Provides a quick summary of all active tracking
 *********************************/
function getTrackingSummary($conn, $userId) {
    // Get single orders count
    $singleSql = "SELECT 
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN status IN ('confirmed', 'preparing', 'ready', 'picked_up', 'on_the_way', 'arrived') THEN 1 ELSE 0 END) as active_orders
                FROM orders
                WHERE user_id = ? AND order_group_id IS NULL";
    
    $singleStmt = $conn->prepare($singleSql);
    $singleStmt->execute([$userId]);
    $singleStats = $singleStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get group orders count
    $groupSql = "SELECT 
                    COUNT(DISTINCT og.id) as total_groups,
                    SUM(CASE WHEN o.status IN ('confirmed', 'preparing', 'ready', 'picked_up', 'on_the_way', 'arrived') THEN 1 ELSE 0 END) as active_orders_in_groups
                FROM order_groups og
                JOIN orders o ON og.id = o.order_group_id
                WHERE og.user_id = ?";
    
    $groupStmt = $conn->prepare($groupSql);
    $groupStmt->execute([$userId]);
    $groupStats = $groupStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get latest active order
    $latestOrderSql = "SELECT 
                        o.id,
                        o.order_number,
                        o.status,
                        o.created_at,
                        m.name as merchant_name
                    FROM orders o
                    LEFT JOIN merchants m ON o.merchant_id = m.id
                    WHERE o.user_id = ? 
                    AND o.status IN ('confirmed', 'preparing', 'ready', 'picked_up', 'on_the_way', 'arrived')
                    ORDER BY o.updated_at DESC
                    LIMIT 1";
    
    $latestOrderStmt = $conn->prepare($latestOrderSql);
    $latestOrderStmt->execute([$userId]);
    $latestOrder = $latestOrderStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get latest active group
    $latestGroupSql = "SELECT 
                        og.id,
                        og.dropx_tracking_id,
                        og.status,
                        og.created_at,
                        COUNT(o.id) as order_count,
                        GROUP_CONCAT(DISTINCT m.name SEPARATOR ', ') as merchant_names
                    FROM order_groups og
                    JOIN orders o ON og.id = o.order_group_id
                    JOIN merchants m ON o.merchant_id = m.id
                    WHERE og.user_id = ? 
                    AND o.status IN ('confirmed', 'preparing', 'ready', 'picked_up', 'on_the_way', 'arrived')
                    GROUP BY og.id
                    ORDER BY og.updated_at DESC
                    LIMIT 1";
    
    $latestGroupStmt = $conn->prepare($latestGroupSql);
    $latestGroupStmt->execute([$userId]);
    $latestGroup = $latestGroupStmt->fetch(PDO::FETCH_ASSOC);
    
    ResponseHandler::success([
        'summary' => [
            'total_orders' => intval($singleStats['total_orders'] ?? 0) + intval($groupStats['total_orders_in_groups'] ?? 0),
            'active_orders' => intval($singleStats['active_orders'] ?? 0) + intval($groupStats['active_orders_in_groups'] ?? 0),
            'active_groups' => intval($groupStats['total_groups'] ?? 0)
        ],
        'latest' => [
            'order' => $latestOrder ? [
                'id' => $latestOrder['id'],
                'order_number' => $latestOrder['order_number'],
                'status' => $latestOrder['status'],
                'merchant_name' => $latestOrder['merchant_name'],
                'created_at' => $latestOrder['created_at']
            ] : null,
            'group' => $latestGroup ? [
                'id' => $latestGroup['id'],
                'dropx_tracking_id' => $latestGroup['dropx_tracking_id'],
                'tracking_url' => $latestGroup['dropx_tracking_id'] ? "https://dropx.com/track/" . $latestGroup['dropx_tracking_id'] : null,
                'status' => $latestGroup['status'],
                'merchant_names' => $latestGroup['merchant_names'],
                'order_count' => intval($latestGroup['order_count']),
                'created_at' => $latestGroup['created_at']
            ] : null
        ]
    ]);
}

/*********************************
 * GET ORDER TRACKING - Single Order
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
                u.full_name as user_name,
                u.phone as user_phone,
                u.email as user_email
            FROM orders o
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            LEFT JOIN users u ON o.user_id = u.id
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
            oi.total_price as total
        FROM order_items oi
        WHERE oi.order_id = :order_id
        ORDER BY oi.id"
    );
    $itemsStmt->execute([':order_id' => $order['id']]);
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

    // Calculate delivery progress
    $deliveryProgress = calculateDeliveryProgress($order['status']);

    // Build delivery status timeline
    $deliveryStatus = buildDeliveryStatusTimeline($order, $trackingInfo);

    // Build driver info
    $driverInfo = null;
    if ($order['driver_id']) {
        $driverInfo = [
            'id' => $order['driver_id'],
            'name' => $order['driver_name'] ?? 'Driver',
            'phone' => $order['driver_phone'] ?? '',
            'vehicle' => $order['vehicle_type'] ?? 'Motorcycle',
            'vehicle_number' => $order['vehicle_number'] ?? '',
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

    ResponseHandler::success([
        'order' => [
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'status' => $order['status'],
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at'],
            'order_group_id' => $order['order_group_id'],
            'merchant' => $merchantInfo,
            'delivery_address' => $order['delivery_address'],
            'special_instructions' => $order['special_instructions'],
            'payment_method' => $order['payment_method'],
            'amounts' => [
                'subtotal' => floatval($order['subtotal']),
                'delivery_fee' => floatval($order['delivery_fee']),
                'total' => floatval($order['total_amount'])
            ]
        ],
        'items' => $orderItems,
        'sibling_orders' => $siblingOrders,
        'tracking' => [
            'current_status' => $order['status'],
            'progress' => $deliveryProgress,
            'estimated_delivery' => $trackingInfo['estimated_delivery'] ?? null,
            'status_timeline' => $deliveryStatus,
            'driver' => $driverInfo,
            'last_updated' => $trackingInfo['updated_at'] ?? $order['updated_at']
        ]
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
    
    // Ensure limit is an integer
    $limit = min(max((int)$limit, 1), 100);
    
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
    
    $stmt = $conn->prepare($sql);
    
    // Build parameters array
    $params = array_merge([$userId], $trackableStatuses, [$limit]);
    
    if (!$stmt->execute($params)) {
        $error = $stmt->errorInfo();
        error_log("SQL Error in getTrackableOrders: " . print_r($error, true));
        ResponseHandler::error('Database error: ' . $error[2], 500);
    }
    
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedOrders = [];
    foreach ($orders as $order) {
        global $baseUrl;
        $merchantImage = formatImageUrl($order['merchant_image'], $baseUrl, 'merchants');
        
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
            o.status,
            o.updated_at as order_updated_at
        FROM orders o
        LEFT JOIN drivers d ON o.driver_id = d.id
        WHERE o.id = ?"
    );
    $stmt->execute([$orderId]);
    $driver = $stmt->fetch(PDO::FETCH_ASSOC);

    // Format driver image URL
    global $baseUrl;
    $driverImage = formatImageUrl($driver['image_url'], $baseUrl, 'drivers');

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
    $trackingSql = "SELECT 
            status,
            location_updates,
            estimated_delivery,
            updated_at
        FROM order_tracking
        WHERE order_id = ?";
    
    $trackingParams = [$orderId];
    
    if ($lastUpdateTime) {
        $trackingSql .= " AND updated_at > ?";
        $trackingParams[] = $lastUpdateTime;
    }
    
    $trackingSql .= " ORDER BY updated_at DESC LIMIT 1";
    
    $trackingStmt = $conn->prepare($trackingSql);
    $trackingStmt->execute($trackingParams);
    $trackingUpdate = $trackingStmt->fetch(PDO::FETCH_ASSOC);

    // Get current driver location
    $driverSql = "SELECT 
            d.current_latitude,
            d.current_longitude,
            d.updated_at as timestamp
        FROM orders o
        LEFT JOIN drivers d ON o.driver_id = d.id
        WHERE o.id = ?";
    
    $driverParams = [$orderId];
    
    if ($lastUpdateTime) {
        $driverSql .= " AND d.updated_at > ?";
        $driverParams[] = $lastUpdateTime;
    }
    
    $driverStmt = $conn->prepare($driverSql);
    $driverStmt->execute($driverParams);
    $driverLocation = $driverStmt->fetch(PDO::FETCH_ASSOC);

    ResponseHandler::success([
        'success' => true,
        'has_updates' => !empty($trackingUpdate) || !empty($driverLocation),
        'tracking_update' => $trackingUpdate,
        'driver_location' => $driverLocation,
        'server_timestamp' => date('Y-m-d H:i:s')
    ]);
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
        'delivered' => 'Order delivered successfully'
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
        'delivered' => 'home'
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
        'delivered' => 'green'
    ];
    
    return $colors[strtolower($status)] ?? 'grey';
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
    
    // Otherwise, mixed status
    return 'mixed';
}
?>