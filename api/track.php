<?php
/*********************************
 * ORDER TRACKING API - track.php
 * Handles all tracking-related endpoints for DropX
 * Matches Flutter app's expected actions exactly
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
 * SESSION CONFIGURATION
 *********************************/
function initializeSession() {
    // Check if session token is in headers
    $sessionToken = null;
    
    // 1. Check X-Session-Token header (Flutter sends this)
    if (isset($_SERVER['HTTP_X_SESSION_TOKEN'])) {
        $sessionToken = $_SERVER['HTTP_X_SESSION_TOKEN'];
    }
    
    // 2. Check Cookie header for PHPSESSID (Flutter also sends this)
    if (!$sessionToken && isset($_SERVER['HTTP_COOKIE'])) {
        $cookies = [];
        parse_str(str_replace('; ', '&', $_SERVER['HTTP_COOKIE']), $cookies);
        if (isset($cookies['PHPSESSID'])) {
            $sessionToken = $cookies['PHPSESSID'];
        }
    }
    
    // If no session token found, we'll handle authentication per endpoint
    if (!$sessionToken) {
        return false;
    }
    
    // Configure session exactly like auth.php
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);
    
    // Use the session token from headers
    session_id($sessionToken);
    session_start();
    
    return true;
}

/*********************************
 * AUTHENTICATION CHECK
 *********************************/
function checkAuthentication() {
    initializeSession();
    
    // Check if user is logged in
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
 * MAIN ROUTER - HANDLES ALL POST REQUESTS FROM FLUTTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Log the request for debugging
    error_log("Track API Request - Method: $method");
    
    // Flutter app makes POST requests to /api/track with action parameter
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
    
    // Log the input for debugging
    error_log("Track POST Input: " . json_encode($input));
    
    $action = $input['action'] ?? '';
    
    // Check authentication for all endpoints
    $userId = checkAuthentication();
    if ($userId === false && $action !== 'track_order') {
        ResponseHandler::error('Authentication required. Please login.', 401);
    }

    // Route based on action from Flutter
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
 * GET ORDER TRACKING - track_order
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
                o.delivery_address_id,
                o.special_instructions,
                o.status,
                o.scheduled_delivery_time,
                o.actual_delivery_time,
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
                d.vehicle_color,
                d.image_url as driver_image,
                d.rating as driver_rating,
                u.full_name as user_name,
                u.phone as user_phone,
                a.full_name as address_name,
                a.phone as address_phone,
                a.address_line1,
                a.address_line2,
                a.city,
                a.neighborhood,
                a.area,
                a.sector,
                a.latitude as address_latitude,
                a.longitude as address_longitude,
                a.landmark
            FROM orders o
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            LEFT JOIN users u ON o.user_id = u.id
            LEFT JOIN addresses a ON o.delivery_address_id = a.id
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

    // Get order status history
    $statusStmt = $conn->prepare(
        "SELECT 
            old_status,
            new_status,
            changed_by,
            changed_by_id,
            reason,
            notes,
            created_at as timestamp
        FROM order_status_history
        WHERE order_id = :order_id
        ORDER BY created_at ASC"
    );
    $statusStmt->execute([':order_id' => $order['id']]);
    $statusHistory = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get order items
    $itemsStmt = $conn->prepare(
        "SELECT 
            oi.id,
            oi.menu_item_id,
            oi.item_name as name,
            oi.quantity,
            oi.unit_price as price,
            oi.total_price as total,
            oi.special_instructions,
            oi.customizations,
            mi.image_url as item_image,
            mi.description as item_description
        FROM order_items oi
        LEFT JOIN menu_items mi ON oi.menu_item_id = mi.id
        WHERE oi.order_id = :order_id"
    );
    $itemsStmt->execute([':order_id' => $order['id']]);
    $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get driver rating if exists
    $ratingStmt = $conn->prepare(
        "SELECT 
            rating,
            punctuality_rating,
            professionalism_rating,
            comment,
            created_at
        FROM driver_ratings
        WHERE order_id = :order_id
        LIMIT 1"
    );
    $ratingStmt->execute([':order_id' => $order['id']]);
    $driverRating = $ratingStmt->fetch(PDO::FETCH_ASSOC);

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

    // Get location updates if any
    $locationStmt = $conn->prepare(
        "SELECT 
            latitude,
            longitude,
            address,
            created_at
        FROM driver_location_history
        WHERE order_id = :order_id
        ORDER BY created_at DESC
        LIMIT 10"
    );
    $locationStmt->execute([':order_id' => $order['id']]);
    $locationHistory = $locationStmt->fetchAll(PDO::FETCH_ASSOC);

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
    $estimatedDelivery = estimateDeliveryTime($order, $trackingInfo, $conn);

    // Build delivery status timeline
    $deliveryStatus = buildDeliveryStatusTimeline($statusHistory, $order, $trackingInfo);

    // Build driver info
    $driverInfo = null;
    if ($order['driver_id']) {
        $driverInfo = buildDriverInfo($order, $driverRating, $driverImage);
    }

    // Build delivery route
    $deliveryRoute = buildDeliveryRoute($order, $locationHistory);

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
            'delivery_route' => $deliveryRoute,
            'location_history' => $locationHistory,
            'last_updated' => $trackingInfo['updated_at'] ?? $order['updated_at']
        ],
        'actions' => $actions
    ]);
}

/*********************************
 * GET TRACKABLE ORDERS - get_trackable
 *********************************/
function getTrackableOrders($conn, $userId, $limit, $sortBy, $sortOrder) {
    // Validate sort parameters
    $allowedSortColumns = ['created_at', 'order_number', 'total_amount', 'status'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'created_at';
    $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
    
    // Trackable statuses (orders that can be tracked)
    $trackableStatuses = ['confirmed', 'preparing', 'ready', 'picked_up', 'on_the_way', 'arrived'];
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
            'restaurant_name' => $order['merchant_name'] // For compatibility with Order.fromApiResponse
        ];
    }
    
    ResponseHandler::success([
        'orders' => $formattedOrders
    ]);
}

/*********************************
 * GET DRIVER LOCATION - driver_location
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
            d.vehicle_color,
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

    // Get the latest location from history
    $historyStmt = $conn->prepare(
        "SELECT 
            latitude,
            longitude,
            address,
            created_at
        FROM driver_location_history
        WHERE order_id = ?
        ORDER BY created_at DESC
        LIMIT 1"
    );
    $historyStmt->execute([$orderId]);
    $history = $historyStmt->fetch(PDO::FETCH_ASSOC);

    // Format driver image URL
    $driverImage = '';
    if (!empty($driver['image_url'])) {
        global $baseUrl;
        $driverImage = formatImageUrl($driver['image_url'], $baseUrl, 'drivers');
    }

    ResponseHandler::success([
        'driver_location' => [
            'latitude' => floatval($driver['current_latitude'] ?? $history['latitude'] ?? 0),
            'longitude' => floatval($driver['current_longitude'] ?? $history['longitude'] ?? 0),
            'address' => $history['address'] ?? '',
            'timestamp' => $history['created_at'] ?? $driver['order_updated_at'] ?? date('Y-m-d H:i:s')
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
 * GET REAL-TIME UPDATES - realtime_updates
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

    // Check for new status updates
    $statusStmt = $conn->prepare(
        "SELECT 
            new_status,
            old_status,
            created_at as timestamp,
            changed_by,
            notes as description
        FROM order_status_history
        WHERE order_id = ?
        AND (? IS NULL OR created_at > ?)
        ORDER BY created_at ASC"
    );
    
    $statusStmt->execute([$orderId, $lastUpdateTime, $lastUpdateTime]);
    $newStatuses = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

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

    // Get new location history entries
    $locationStmt = $conn->prepare(
        "SELECT 
            latitude,
            longitude,
            address,
            created_at as timestamp
        FROM driver_location_history
        WHERE order_id = ?
        AND (? IS NULL OR created_at > ?)
        ORDER BY created_at DESC
        LIMIT 5"
    );
    
    $locationStmt->execute([$orderId, $lastUpdateTime, $lastUpdateTime]);
    $newLocations = $locationStmt->fetchAll(PDO::FETCH_ASSOC);

    ResponseHandler::success([
        'success' => true,
        'has_updates' => !empty($newStatuses) || !empty($trackingUpdate) || !empty($newLocations),
        'status_updates' => $newStatuses,
        'tracking_update' => $trackingUpdate,
        'driver_location' => $driverLocation,
        'new_locations' => $newLocations,
        'server_timestamp' => date('Y-m-d H:i:s')
    ]);
}

/*********************************
 * RATE DELIVERY - rate_delivery
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

    // Check if already rated
    $existingStmt = $conn->prepare(
        "SELECT id FROM driver_ratings 
         WHERE order_id = ?"
    );
    $existingStmt->execute([$orderId]);
    
    if ($existingStmt->fetch()) {
        ResponseHandler::error('You have already rated this delivery', 409);
    }

    // Start transaction
    $conn->beginTransaction();

    try {
        // Create rating
        $stmt = $conn->prepare(
            "INSERT INTO driver_ratings 
                (driver_id, user_id, order_id, rating, punctuality_rating, 
                 professionalism_rating, comment, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        
        $stmt->execute([
            $order['driver_id'],
            $userId,
            $orderId,
            $rating,
            $punctualityRating,
            $professionalismRating,
            $comment
        ]);

        // Update driver's average rating
        $updateDriverStmt = $conn->prepare(
            "UPDATE drivers 
             SET rating = (
                 SELECT AVG(rating) 
                 FROM driver_ratings 
                 WHERE driver_id = ?
             ),
             updated_at = NOW()
             WHERE id = ?"
        );
        $updateDriverStmt->execute([$order['driver_id'], $order['driver_id']]);

        $conn->commit();

        ResponseHandler::success([], 'Thank you for your feedback!');
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to submit rating: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET DRIVER CONTACT - call_driver
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

    // Log the call attempt
    $logStmt = $conn->prepare(
        "INSERT INTO user_activities 
            (user_id, activity_type, description, ip_address, metadata, created_at)
         VALUES (?, 'call_driver', 'Attempted to call driver for order', 
                ?, ?, NOW())"
    );
    
    $logStmt->execute([
        $userId,
        $_SERVER['REMOTE_ADDR'] ?? '',
        json_encode([
            'order_id' => $orderId,
            'driver_phone' => $result['phone'],
            'driver_name' => $result['name']
        ])
    ]);

    ResponseHandler::success([
        'driver' => [
            'name' => $result['name'],
            'phone' => $result['phone'],
            'callable' => true
        ]
    ]);
}

/*********************************
 * SHARE TRACKING - share_tracking
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

    if (!$order) {
        ResponseHandler::error('Order not found', 404);
    }

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
 * CANCEL ORDER FROM TRACKING - cancel_order
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

    // Start transaction
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

        // Add to status history
        $historyStmt = $conn->prepare(
            "INSERT INTO order_status_history 
                (order_id, old_status, new_status, changed_by, 
                 changed_by_id, reason, created_at)
             VALUES (?, ?, 'cancelled', 'user', ?, ?, NOW())"
        );
        $historyStmt->execute([$orderId, $order['status'], $userId, $reason]);

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
 * CONTACT ORDER SUPPORT - contact_support
 *********************************/
function contactOrderSupport($conn, $userId, $orderId, $issue, $details) {
    // Get order details for context
    $orderInfo = null;
    if ($orderId) {
        $checkStmt = $conn->prepare(
            "SELECT id, order_number, status FROM orders 
             WHERE id = ? AND user_id = ?"
        );
        $checkStmt->execute([$orderId, $userId]);
        $orderInfo = $checkStmt->fetch(PDO::FETCH_ASSOC);
    }

    // Log support request
    $logStmt = $conn->prepare(
        "INSERT INTO user_activities 
            (user_id, activity_type, description, ip_address, metadata, created_at)
         VALUES (?, 'contact_support', ?, ?, ?, NOW())"
    );
    
    $logStmt->execute([
        $userId,
        $issue,
        $_SERVER['REMOTE_ADDR'] ?? '',
        json_encode([
            'order_id' => $orderId,
            'order_info' => $orderInfo,
            'details' => $details
        ])
    ]);

    ResponseHandler::success([
        'ticket_id' => $conn->lastInsertId(),
        'message' => 'Support request received. We\'ll contact you shortly.'
    ]);
}

/*********************************
 * GET LATEST ACTIVE ORDER - latest_active
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
    
    // Otherwise, build the full URL
    $folder = '';
    switch ($type) {
        case 'merchants':
            $folder = 'uploads/merchants';
            break;
        case 'drivers':
            $folder = 'uploads/drivers';
            break;
        case 'menu_items':
            $folder = 'uploads/menu_items';
            break;
        default:
            $folder = 'uploads';
    }
    
    return rtrim($baseUrl, '/') . '/' . $folder . '/' . ltrim($imagePath, '/');
}

function formatDeliveryAddress($order) {
    $addressParts = [];
    
    if (!empty($order['address_name'])) {
        $addressParts[] = $order['address_name'];
    }
    
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

function estimateDeliveryTime($order, $trackingInfo, $conn) {
    // Try to get estimated delivery from tracking info
    if (!empty($trackingInfo['estimated_delivery'])) {
        return $trackingInfo['estimated_delivery'];
    }
    
    // Calculate based on order creation time
    if (!empty($order['created_at'])) {
        $orderTime = strtotime($order['created_at']);
        
        // Get merchant average preparation time
        $prepStmt = $conn->prepare(
            "SELECT average_preparation_time FROM merchants 
             WHERE id = ?"
        );
        $prepStmt->execute([$order['merchant_id']]);
        $merchant = $prepStmt->fetch(PDO::FETCH_ASSOC);
        
        $prepTime = $merchant['average_preparation_time'] ?? 30;
        $deliveryTime = $orderTime + (($prepTime + 15) * 60);
        
        return date('Y-m-d H:i:s', $deliveryTime);
    }
    
    return date('Y-m-d H:i:s', time() + (45 * 60));
}

function buildDeliveryStatusTimeline($statusHistory, $order, $trackingInfo) {
    $timeline = [];
    
    // Add order created
    $timeline[] = [
        'status' => 'order_placed',
        'description' => 'Order placed successfully',
        'timestamp' => $order['created_at'],
        'location' => $order['merchant_address'] ?? '',
        'icon' => 'shopping_bag',
        'color' => 'green'
    ];
    
    // Add status history entries
    foreach ($statusHistory as $history) {
        $timeline[] = [
            'status' => strtolower(str_replace(' ', '_', $history['new_status'])),
            'description' => getStatusDescription($history['new_status']),
            'timestamp' => $history['timestamp'],
            'location' => $history['notes'] ?? '',
            'icon' => getStatusIcon($history['new_status']),
            'color' => getStatusColor($history['new_status'])
        ];
    }
    
    // Sort by timestamp
    usort($timeline, function($a, $b) {
        return strtotime($a['timestamp']) - strtotime($b['timestamp']);
    });
    
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
        'cancelled' => 'Order has been cancelled',
        'refunded' => 'Order has been refunded'
    ];
    
    return $descriptions[strtolower($status)] ?? 'Status updated';
}

function getStatusIcon($status) {
    $icons = [
        'pending' => 'hourglass_empty',
        'confirmed' => 'check_circle',
        'preparing' => 'restaurant',
        'ready' => 'done_all',
        'picked_up' => 'local_shipping',
        'on_the_way' => 'directions_bike',
        'arrived' => 'location_on',
        'delivered' => 'home',
        'cancelled' => 'cancel',
        'refunded' => 'money_off'
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
        'cancelled' => 'red',
        'refunded' => 'amber'
    ];
    
    return $colors[strtolower($status)] ?? 'grey';
}

function buildDriverInfo($order, $driverRating, $driverImage) {
    return [
        'id' => $order['driver_id'],
        'name' => $order['driver_name'] ?? 'Driver',
        'phone' => $order['driver_phone'] ?? '',
        'vehicle' => $order['vehicle_type'] ?? 'Motorcycle',
        'vehicle_number' => $order['vehicle_number'] ?? '',
        'vehicle_color' => $order['vehicle_color'] ?? '',
        'rating' => floatval($order['driver_rating'] ?? 4.5),
        'punctuality_rating' => intval($driverRating['punctuality_rating'] ?? 0),
        'professionalism_rating' => intval($driverRating['professionalism_rating'] ?? 0),
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
        'address' => $order['merchant_address'],
        'phone' => $order['merchant_phone'],
        'image_url' => $merchantImage,
        'latitude' => floatval($order['merchant_latitude'] ?? 0),
        'longitude' => floatval($order['merchant_longitude'] ?? 0)
    ];
}

function buildDeliveryRoute($order, $locationHistory) {
    $route = [];
    
    // Merchant location
    if (!empty($order['merchant_latitude']) && !empty($order['merchant_longitude'])) {
        $route[] = [
            'lat' => floatval($order['merchant_latitude']),
            'lng' => floatval($order['merchant_longitude']),
            'name' => $order['merchant_name'] ?? 'Restaurant',
            'type' => 'merchant'
        ];
    }
    
    // Driver location history
    foreach ($locationHistory as $location) {
        if (!empty($location['latitude']) && !empty($location['longitude'])) {
            $route[] = [
                'lat' => floatval($location['latitude']),
                'lng' => floatval($location['longitude']),
                'name' => 'Driver Location',
                'type' => 'driver',
                'timestamp' => $location['created_at'],
                'address' => $location['address'] ?? ''
            ];
        }
    }
    
    // Current driver location
    if (!empty($order['current_latitude']) && !empty($order['current_longitude'])) {
        $route[] = [
            'lat' => floatval($order['current_latitude']),
            'lng' => floatval($order['current_longitude']),
            'name' => 'Current Driver Location',
            'type' => 'driver_current'
        ];
    }
    
    // Delivery address
    if (!empty($order['address_latitude']) && !empty($order['address_longitude'])) {
        $route[] = [
            'lat' => floatval($order['address_latitude']),
            'lng' => floatval($order['address_longitude']),
            'name' => 'Delivery Address',
            'type' => 'destination'
        ];
    }
    
    return $route;
}

function formatOrderData($order, $merchantInfo, $deliveryAddress) {
    return [
        'id' => $order['id'],
        'order_number' => $order['order_number'],
        'status' => $order['status'],
        'created_at' => $order['created_at'],
        'updated_at' => $order['updated_at'],
        'merchant' => $merchantInfo,
        'delivery_address' => $deliveryAddress,
        'special_instructions' => $order['special_instructions'],
        'payment_method' => $order['payment_method'],
        'amounts' => [
            'subtotal' => floatval($order['subtotal']),
            'delivery_fee' => floatval($order['delivery_fee']),
            'total' => floatval($order['total_amount'])
        ],
        'scheduled_delivery' => $order['scheduled_delivery_time'],
        'actual_delivery' => $order['actual_delivery_time'],
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
            $actions['modify'] = true;
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
        case 'refunded':
            $actions['reorder'] = true;
            break;
    }
    
    return $actions;
}
?>