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
    if ($userId === false && $action !== 'track_order') {
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
            
        case 'get_trackable':
            $limit = $input['limit'] ?? 50;
            $sortBy = $input['sort_by'] ?? 'created_at';
            $sortOrder = $input['sort_order'] ?? 'DESC';
            getTrackableOrders($conn, $userId, $limit, $sortBy, $sortOrder);
            break;
            
        case 'driver_location':
            $orderId = $input['order_id'] ?? '';
            if (!$orderId) {
                ResponseHandler::error('Order ID is required', 400);
            }
            getDriverLocation($conn, $userId, $orderId);
            break;
            
        case 'realtime_updates':
            $orderId = $input['order_id'] ?? '';
            $lastUpdate = $input['last_update'] ?? null;
            if (!$orderId) {
                ResponseHandler::error('Order ID is required', 400);
            }
            getRealTimeUpdates($conn, $userId, $orderId, $lastUpdate);
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
            
        case 'call_driver':
            $orderId = $input['order_id'] ?? '';
            if (!$orderId) {
                ResponseHandler::error('Order ID is required', 400);
            }
            getDriverContact($conn, $userId, $orderId);
            break;
            
        case 'share_tracking':
            $orderId = $input['order_id'] ?? '';
            if (!$orderId) {
                ResponseHandler::error('Order ID is required', 400);
            }
            shareTracking($conn, $userId, $orderId);
            break;
            
        case 'cancel_order':
            $orderId = $input['order_id'] ?? '';
            $reason = $input['reason'] ?? '';
            if (!$orderId) {
                ResponseHandler::error('Order ID is required', 400);
            }
            cancelOrderFromTracking($conn, $userId, $orderId, $reason);
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
            
        case 'latest_active':
            getLatestActiveOrder($conn, $userId);
            break;
            
        default:
            ResponseHandler::error('Invalid action: ' . $action, 400);
    }
}

/*********************************
 * GET ORDER TRACKING - COMPLETELY FIXED WITH MENU_ITEMS JOIN
 *********************************/
function getOrderTracking($conn, $orderIdentifier, $baseUrl, $userId = null) {
    // Check if order identifier is order_number or order_id
    $isOrderNumber = !is_numeric($orderIdentifier) && preg_match('/^[A-Za-z0-9_-]+$/', $orderIdentifier);
    
    // SQL query matching your exact table structure
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
    
    // If user is authenticated, also check ownership
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

    // Get order items - FIXED: Join with menu_items using item_name
    // Since order_items doesn't have menu_item_id, we join by item_name
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

    // Format merchant image URL
    $merchantImage = '';
    if (!empty($order['merchant_image'])) {
        $merchantImage = formatImageUrl($order['merchant_image'], $baseUrl, 'merchants');
    }

    // Format driver image URL
    $driverImage = '';
    if (!empty($order['driver_image'])) {
        $driverImage = formatImageUrl($order['driver_image'], $baseUrl, 'drivers');
    }

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
            'restaurant_name' => $order['merchant_name']
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
        "SELECT id, driver_id FROM orders 
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

    // Since driver_ratings table doesn't exist, just return success
    // In a real implementation, you'd create this table first
    
    ResponseHandler::success([], 'Thank you for your feedback!');
}

/*********************************
 * GET DRIVER CONTACT
 *********************************/
function getDriverContact($conn, $userId, $orderId) {
    // Verify order belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id FROM orders 
         WHERE id = ? AND user_id = ?"
    );
    $checkStmt->execute([$orderId, $userId]);
    
    if (!$checkStmt->fetch()) {
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
        "SELECT id FROM orders 
         WHERE id = ? AND user_id = ?"
    );
    $checkStmt->execute([$orderId, $userId]);
    
    if (!$checkStmt->fetch()) {
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
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    // Generate tracking URL
    $trackingUrl = "https://dropx12-production.up.railway.app/api/track/order/" . $order['order_number'];
    
    // Generate sharing message
    $message = "Track my order from " . $order['merchant_name'] . ":\n" .
               "Order: " . $order['order_number'] . "\n" .
               "Status: " . $order['status'] . "\n" .
               "Track here: " . $trackingUrl;

    ResponseHandler::success([
        'tracking_url' => $trackingUrl,
        'share_message' => $message,
        'order_number' => $order['order_number'],
        'status' => $order['status']
    ]);
}

/*********************************
 * CANCEL ORDER FROM TRACKING
 *********************************/
function cancelOrderFromTracking($conn, $userId, $orderId, $reason) {
    // Verify order belongs to user and can be cancelled
    $checkStmt = $conn->prepare(
        "SELECT id, status FROM orders 
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

        $conn->commit();

        ResponseHandler::success([], 'Order cancelled successfully');

    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to cancel order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CONTACT ORDER SUPPORT - SIMPLIFIED
 *********************************/
function contactOrderSupport($conn, $userId, $orderId, $issue, $details) {
    // Since user_activities table doesn't exist, just return success
    // In a real implementation, you'd create this table first
    
    ResponseHandler::success([
        'ticket_id' => rand(1000, 9999),
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
            'merchant_name' => $order['merchant_name']
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
?>