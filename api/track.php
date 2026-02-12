<?php
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
function checkAuthentication($endpoint = null) {
    // Some endpoints don't require authentication
    $publicEndpoints = [
        '/order/', // Public tracking by order number
    ];
    
    // Check if this is a public endpoint
    $isPublic = false;
    if ($endpoint) {
        foreach ($publicEndpoints as $publicPath) {
            if (strpos($endpoint, $publicPath) === 0) {
                $isPublic = true;
                break;
            }
        }
    }
    
    if ($isPublic) {
        // Public endpoint - no authentication required
        return null;
    }
    
    // Private endpoint - require authentication
    initializeSession();
    
    // Check if user is logged in (same as auth.php)
    if (empty($_SESSION['user_id']) || empty($_SESSION['logged_in'])) {
        return false;
    }
    
    return $_SESSION['user_id'];
}

/*********************************
 * BASE URL CONFIGURATION
 *********************************/
// Update this with your actual backend URL
$baseUrl = "https://dropx12-production.up.railway.app";

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    
    // Remove the base path if needed (adjust based on your setup)
    $path = str_replace('/api/track', '', $path);
    $path = str_replace('/track.php', '', $path); // Also remove if accessed directly

    if ($method === 'GET') {
        handleGetRequest($path);
    } elseif ($method === 'POST') {
        handlePostRequest($path);
    } else {
        ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET REQUESTS
 *********************************/
function handleGetRequest($path) {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();

    // Route based on path pattern
    if (preg_match('/^\/order\/([A-Za-z0-9_-]+)$/', $path, $matches)) {
        $orderIdentifier = $matches[1];
        getOrderTracking($conn, $orderIdentifier, $baseUrl);
    } elseif ($path === '/driver-location' || strpos($path, '?action=driver-location') !== false) {
        // Check authentication for private endpoint
        $userId = checkAuthentication('/driver-location');
        if ($userId === false) {
            ResponseHandler::error('Authentication required. Please login.', 401);
        }
        getDriverLocation($conn, $userId);
    } elseif ($path === '/realtime-updates' || strpos($path, '?action=realtime-updates') !== false) {
        // Check authentication for private endpoint
        $userId = checkAuthentication('/realtime-updates');
        if ($userId === false) {
            ResponseHandler::error('Authentication required. Please login.', 401);
        }
        getRealTimeUpdates($conn, $userId);
    } elseif ($path === '/' || $path === '') {
        // Root endpoint - just return success
        ResponseHandler::success(['service' => 'DropX Tracking API', 'status' => 'active']);
    } else {
        ResponseHandler::error('Invalid endpoint', 404);
    }
}

/*********************************
 * GET ORDER TRACKING DETAILS
 *********************************/
function getOrderTracking($conn, $orderIdentifier, $baseUrl) {
    // Public endpoint - anyone can track with order number
    
    // Check if order identifier is order_number or order_id
    $isOrderNumber = preg_match('/^[A-Za-z0-9_-]+$/', $orderIdentifier);
    
    if ($isOrderNumber) {
        // Try to find by order_number first
        $orderStmt = $conn->prepare(
            "SELECT 
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
                a.latitude,
                a.longitude,
                a.landmark
            FROM orders o
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            LEFT JOIN users u ON o.user_id = u.id
            LEFT JOIN addresses a ON o.delivery_address_id = a.id
            WHERE o.order_number = :order_number
            LIMIT 1"
        );
        $orderStmt->execute([':order_number' => $orderIdentifier]);
    } else {
        // Try by order ID
        $orderStmt = $conn->prepare(
            "SELECT 
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
                a.latitude,
                a.longitude,
                a.landmark
            FROM orders o
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            LEFT JOIN users u ON o.user_id = u.id
            LEFT JOIN addresses a ON o.delivery_address_id = a.id
            WHERE o.id = :order_id
            LIMIT 1"
        );
        $orderStmt->execute([':order_id' => intval($orderIdentifier)]);
    }
    
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
            oi.item_name,
            oi.quantity,
            oi.price,
            oi.total,
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
    $deliveryProgress = calculateDeliveryProgress($order['status'], $order['created_at']);

    // Estimate delivery time from database or calculate
    $estimatedDelivery = estimateDeliveryTime($order, $trackingInfo, $conn);

    // Build delivery status timeline from database
    $deliveryStatus = buildDeliveryStatusTimeline($statusHistory, $order, $trackingInfo);

    // Build driver info from database
    $driverInfo = null;
    if ($order['driver_id']) {
        $driverInfo = buildDriverInfo($order, $driverRating, $driverImage);
    }

    // Build delivery route from database coordinates
    $deliveryRoute = buildDeliveryRoute($order, $locationHistory);

    // Build merchant info
    $merchantInfo = buildMerchantInfo($order, $merchantImage);

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
        'actions' => getAvailableActions($order['status'])
    ]);
}

/*********************************
 * GET DRIVER LOCATION (REALTIME)
 *********************************/
function getDriverLocation($conn, $userId) {
    $orderId = $_GET['order_id'] ?? null;
    
    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
    }

    // Verify the order belongs to the authenticated user
    $checkStmt = $conn->prepare(
        "SELECT id FROM orders 
         WHERE id = :order_id AND user_id = :user_id"
    );
    $checkStmt->execute([
        ':order_id' => $orderId,
        ':user_id' => $userId
    ]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Order not found or not authorized', 403);
    }

    $stmt = $conn->prepare(
        "SELECT 
            d.current_latitude,
            d.current_longitude,
            d.name,
            d.phone,
            d.vehicle_type,
            d.vehicle_number,
            o.status,
            o.updated_at
        FROM orders o
        JOIN drivers d ON o.driver_id = d.id
        WHERE o.id = :order_id
        LIMIT 1"
    );
    $stmt->execute([':order_id' => $orderId]);
    $driver = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$driver) {
        ResponseHandler::error('Driver not found for this order', 404);
    }

    // Get the latest location from history
    $historyStmt = $conn->prepare(
        "SELECT 
            latitude,
            longitude,
            address,
            created_at
        FROM driver_location_history
        WHERE order_id = :order_id
        ORDER BY created_at DESC
        LIMIT 1"
    );
    $historyStmt->execute([':order_id' => $orderId]);
    $history = $historyStmt->fetch(PDO::FETCH_ASSOC);

    ResponseHandler::success([
        'location' => [
            'latitude' => $driver['current_latitude'],
            'longitude' => $driver['current_longitude'],
            'address' => $history['address'] ?? '',
            'timestamp' => $driver['updated_at'],
            'status' => $driver['status'],
            'vehicle' => $driver['vehicle_type'],
            'vehicle_number' => $driver['vehicle_number']
        ]
    ]);
}

/*********************************
 * GET REAL-TIME UPDATES
 *********************************/
function getRealTimeUpdates($conn, $userId) {
    $orderId = $_GET['order_id'] ?? null;
    $lastUpdate = $_GET['last_update'] ?? null;
    
    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
    }

    // Verify the order belongs to the authenticated user
    $checkStmt = $conn->prepare(
        "SELECT id FROM orders 
         WHERE id = :order_id AND user_id = :user_id"
    );
    $checkStmt->execute([
        ':order_id' => $orderId,
        ':user_id' => $userId
    ]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Order not found or not authorized', 403);
    }

    // Check for new status updates
    $statusStmt = $conn->prepare(
        "SELECT 
            new_status,
            created_at,
            changed_by,
            notes
        FROM order_status_history
        WHERE order_id = :order_id
        AND (:last_update IS NULL OR created_at > :last_update)
        ORDER BY created_at DESC"
    );
    
    $statusStmt->execute([
        ':order_id' => $orderId,
        ':last_update' => $lastUpdate
    ]);
    $newStatuses = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

    // Check for tracking updates
    $trackingStmt = $conn->prepare(
        "SELECT 
            status,
            location_updates,
            estimated_delivery,
            updated_at
        FROM order_tracking
        WHERE order_id = :order_id
        AND (:last_update IS NULL OR updated_at > :last_update)
        ORDER BY updated_at DESC
        LIMIT 1"
    );
    
    $trackingStmt->execute([
        ':order_id' => $orderId,
        ':last_update' => $lastUpdate
    ]);
    $trackingUpdate = $trackingStmt->fetch(PDO::FETCH_ASSOC);

    // Get current driver location
    $driverStmt = $conn->prepare(
        "SELECT 
            d.current_latitude,
            d.current_longitude,
            o.updated_at
        FROM orders o
        LEFT JOIN drivers d ON o.driver_id = d.id
        WHERE o.id = :order_id"
    );
    $driverStmt->execute([':order_id' => $orderId]);
    $driverLocation = $driverStmt->fetch(PDO::FETCH_ASSOC);

    // Get new location history entries
    $locationStmt = $conn->prepare(
        "SELECT 
            latitude,
            longitude,
            address,
            created_at
        FROM driver_location_history
        WHERE order_id = :order_id
        AND (:last_update IS NULL OR created_at > :last_update)
        ORDER BY created_at DESC
        LIMIT 5"
    );
    
    $locationStmt->execute([
        ':order_id' => $orderId,
        ':last_update' => $lastUpdate
    ]);
    $newLocations = $locationStmt->fetchAll(PDO::FETCH_ASSOC);

    ResponseHandler::success([
        'has_updates' => !empty($newStatuses) || !empty($trackingUpdate) || !empty($newLocations),
        'status_updates' => $newStatuses,
        'tracking_update' => $trackingUpdate,
        'driver_location' => $driverLocation,
        'new_locations' => $newLocations,
        'server_timestamp' => date('Y-m-d H:i:s')
    ]);
}

/*********************************
 * POST REQUESTS
 *********************************/
function handlePostRequest($path) {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    // Check authentication for all POST endpoints (they're all private)
    $userId = checkAuthentication($path);
    if ($userId === false) {
        ResponseHandler::error('Authentication required. Please login.', 401);
    }
    
    // Clean the path for routing
    $cleanPath = strtok($path, '?');
    
    switch ($cleanPath) {
        case '/rate-delivery':
        case '/rate':
            rateDelivery($conn, $input, $userId);
            break;
        case '/call-driver':
        case '/call':
            callDriver($conn, $input, $userId);
            break;
        case '/share-tracking':
        case '/share':
            shareTracking($conn, $input, $userId);
            break;
        case '/cancel-order':
        case '/cancel':
            cancelOrder($conn, $input, $userId);
            break;
        case '/contact-support':
        case '/support':
            contactSupport($conn, $input, $userId);
            break;
        case '/update-preferences':
        case '/preferences':
            updateTrackingPreferences($conn, $input, $userId);
            break;
        case '/':
        case '':
            // Handle POST to root - maybe create a tracking event
            ResponseHandler::error('Specify an action', 400);
            break;
        default:
            ResponseHandler::error('Invalid endpoint', 404);
    }
}

/*********************************
 * RATE DELIVERY
 *********************************/
function rateDelivery($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;
    $rating = floatval($data['rating'] ?? 0);
    $punctualityRating = intval($data['punctuality_rating'] ?? 0);
    $professionalismRating = intval($data['professionalism_rating'] ?? 0);
    $comment = trim($data['comment'] ?? '');

    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
    }

    if ($rating < 1 || $rating > 5) {
        ResponseHandler::error('Rating must be between 1 and 5', 400);
    }

    // Verify order belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id, driver_id FROM orders 
         WHERE id = :order_id AND user_id = :user_id"
    );
    $checkStmt->execute([
        ':order_id' => $orderId,
        ':user_id' => $userId
    ]);
    
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
         WHERE order_id = :order_id"
    );
    $existingStmt->execute([':order_id' => $orderId]);
    
    if ($existingStmt->fetch()) {
        ResponseHandler::error('You have already rated this delivery', 409);
    }

    // Create rating
    $stmt = $conn->prepare(
        "INSERT INTO driver_ratings 
            (driver_id, user_id, order_id, rating, punctuality_rating, 
             professionalism_rating, comment, created_at)
         VALUES (:driver_id, :user_id, :order_id, :rating, :punctuality_rating,
                :professionalism_rating, :comment, NOW())"
    );
    
    $stmt->execute([
        ':driver_id' => $order['driver_id'],
        ':user_id' => $userId,
        ':order_id' => $orderId,
        ':rating' => $rating,
        ':punctuality_rating' => $punctualityRating,
        ':professionalism_rating' => $professionalismRating,
        ':comment' => $comment
    ]);

    // Update driver's average rating
    $updateDriverStmt = $conn->prepare(
        "UPDATE drivers 
         SET rating = (
             SELECT AVG(rating) 
             FROM driver_ratings 
             WHERE driver_id = :driver_id
         ),
         updated_at = NOW()
         WHERE id = :driver_id"
    );
    $updateDriverStmt->execute([':driver_id' => $order['driver_id']]);

    ResponseHandler::success([], 'Thank you for your feedback!');
}

/*********************************
 * CALL DRIVER
 *********************************/
function callDriver($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;
    
    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
    }

    // Verify order belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id FROM orders 
         WHERE id = :order_id AND user_id = :user_id"
    );
    $checkStmt->execute([
        ':order_id' => $orderId,
        ':user_id' => $userId
    ]);
    
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
        WHERE o.id = :order_id"
    );
    $stmt->execute([':order_id' => $orderId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        ResponseHandler::error('Driver not available', 404);
    }

    // Log the call attempt in database
    $logStmt = $conn->prepare(
        "INSERT INTO user_activities 
            (user_id, activity_type, description, ip_address, metadata, created_at)
         VALUES (:user_id, 'call_driver', 'Attempted to call driver for order', 
                :ip_address, :metadata, NOW())"
    );
    
    $logStmt->execute([
        ':user_id' => $userId,
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        ':metadata' => json_encode([
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
        ],
        'message' => 'Ready to call ' . $result['name']
    ]);
}

/*********************************
 * SHARE TRACKING
 *********************************/
function shareTracking($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;
    
    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
    }

    // Verify order belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id FROM orders 
         WHERE id = :order_id AND user_id = :user_id"
    );
    $checkStmt->execute([
        ':order_id' => $orderId,
        ':user_id' => $userId
    ]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Order not found or not authorized', 403);
    }

    // Get order details for sharing
    $stmt = $conn->prepare(
        "SELECT 
            o.order_number,
            o.status,
            m.name as merchant_name,
            o.created_at
        FROM orders o
        JOIN merchants m ON o.merchant_id = m.id
        WHERE o.id = :order_id"
    );
    $stmt->execute([':order_id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ResponseHandler::error('Order not found', 404);
    }

    // Generate tracking URL (use your actual domain)
    $trackingUrl = "https://dropxbackend-production.up.railway.app/api/track/order/" . $order['order_number'];
    
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
 * CANCEL ORDER
 *********************************/
function cancelOrder($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;
    $reason = trim($data['reason'] ?? '');
    
    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
    }

    // Verify order belongs to user and can be cancelled
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
                 cancellation_reason = :reason,
                 updated_at = NOW()
             WHERE id = :order_id"
        );
        $updateStmt->execute([
            ':order_id' => $orderId,
            ':reason' => $reason
        ]);

        // Add to status history
        $historyStmt = $conn->prepare(
            "INSERT INTO order_status_history 
                (order_id, old_status, new_status, changed_by, 
                 changed_by_id, reason, created_at)
             VALUES (:order_id, :old_status, 'cancelled', 'user',
                    :user_id, :reason, NOW())"
        );
        $historyStmt->execute([
            ':order_id' => $orderId,
            ':old_status' => $order['status'],
            ':user_id' => $userId,
            ':reason' => $reason
        ]);

        // Update tracking
        $trackingStmt = $conn->prepare(
            "INSERT INTO order_tracking 
                (order_id, status, created_at, updated_at)
             VALUES (:order_id, 'cancelled', NOW(), NOW())
             ON DUPLICATE KEY UPDATE 
                status = 'cancelled',
                updated_at = NOW()"
        );
        $trackingStmt->execute([':order_id' => $orderId]);

        $conn->commit();

        ResponseHandler::success([], 'Order cancelled successfully');

    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to cancel order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CONTACT SUPPORT
 *********************************/
function contactSupport($conn, $data, $userId) {
    $orderId = $data['order_id'] ?? null;
    $issue = trim($data['issue'] ?? '');
    $details = trim($data['details'] ?? '');
    
    if (!$issue) {
        ResponseHandler::error('Please describe the issue', 400);
    }

    // Get order details for context
    $orderInfo = null;
    if ($orderId) {
        // Verify order belongs to user
        $checkStmt = $conn->prepare(
            "SELECT id, order_number, status FROM orders 
             WHERE id = :order_id AND user_id = :user_id"
        );
        $checkStmt->execute([
            ':order_id' => $orderId,
            ':user_id' => $userId
        ]);
        $orderInfo = $checkStmt->fetch(PDO::FETCH_ASSOC);
    }

    // Log support request to database
    $logStmt = $conn->prepare(
        "INSERT INTO user_activities 
            (user_id, activity_type, description, ip_address, metadata, created_at)
         VALUES (:user_id, 'contact_support', :description, 
                :ip_address, :metadata, NOW())"
    );
    
    $logStmt->execute([
        ':user_id' => $userId,
        ':description' => $issue,
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        ':metadata' => json_encode([
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
 * UPDATE TRACKING PREFERENCES
 *********************************/
function updateTrackingPreferences($conn, $data, $userId) {
    $pushNotifications = $data['push_notifications'] ?? null;
    $emailNotifications = $data['email_notifications'] ?? null;
    $smsNotifications = $data['sms_notifications'] ?? null;
    $orderUpdates = $data['order_updates'] ?? null;

    // Check if settings exist in database
    $checkStmt = $conn->prepare(
        "SELECT id FROM notification_settings WHERE user_id = :user_id"
    );
    $checkStmt->execute([':user_id' => $userId]);
    
    if ($checkStmt->fetch()) {
        // Update existing
        $updateFields = [];
        $params = [':user_id' => $userId];
        
        if ($pushNotifications !== null) {
            $updateFields[] = 'push_notifications = :push';
            $params[':push'] = $pushNotifications ? 1 : 0;
        }
        
        if ($emailNotifications !== null) {
            $updateFields[] = 'email_notifications = :email';
            $params[':email'] = $emailNotifications ? 1 : 0;
        }
        
        if ($smsNotifications !== null) {
            $updateFields[] = 'sms_notifications = :sms';
            $params[':sms'] = $smsNotifications ? 1 : 0;
        }
        
        if ($orderUpdates !== null) {
            $updateFields[] = 'order_updates = :order_updates';
            $params[':order_updates'] = $orderUpdates ? 1 : 0;
        }
        
        if (!empty($updateFields)) {
            $sql = "UPDATE notification_settings SET " . 
                   implode(', ', $updateFields) . 
                   ", updated_at = NOW() WHERE user_id = :user_id";
            
            $updateStmt = $conn->prepare($sql);
            $updateStmt->execute($params);
        }
    } else {
        // Create new in database
        $insertStmt = $conn->prepare(
            "INSERT INTO notification_settings 
                (user_id, push_notifications, email_notifications, 
                 sms_notifications, order_updates, created_at)
             VALUES (:user_id, :push, :email, :sms, :order_updates, NOW())"
        );
        
        $insertStmt->execute([
            ':user_id' => $userId,
            ':push' => $pushNotifications !== null ? ($pushNotifications ? 1 : 0) : 1,
            ':email' => $emailNotifications !== null ? ($emailNotifications ? 1 : 0) : 1,
            ':sms' => $smsNotifications !== null ? ($smsNotifications ? 1 : 0) : 0,
            ':order_updates' => $orderUpdates !== null ? ($orderUpdates ? 1 : 0) : 1
        ]);
    }

    ResponseHandler::success([], 'Tracking preferences updated');
}

/*********************************
 * HELPER FUNCTIONS - ALL DATA FROM DATABASE
 *********************************/

function formatImageUrl($imagePath, $baseUrl, $type = '') {
    if (empty($imagePath)) {
        return '';
    }
    
    // If it's already a full URL, use it as is
    if (strpos($imagePath, 'http') === 0) {
        return $imagePath;
    }
    
    // Otherwise, build the full URL from database path
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

function calculateDeliveryProgress($status, $createdAt) {
    // Get progress based on actual status from database
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
    
    // Calculate based on order creation time and merchant preparation time
    if (!empty($order['created_at'])) {
        $orderTime = strtotime($order['created_at']);
        
        // Get merchant average preparation time from database
        $prepStmt = $conn->prepare(
            "SELECT average_preparation_time FROM merchants 
             WHERE id = :merchant_id"
        );
        $prepStmt->execute([':merchant_id' => $order['merchant_id']]);
        $merchant = $prepStmt->fetch(PDO::FETCH_ASSOC);
        
        $prepTime = $merchant['average_preparation_time'] ?? 30; // Default 30 minutes
        $deliveryTime = $orderTime + (($prepTime + 15) * 60); // +15 minutes for delivery
        
        return date('Y-m-d H:i:s', $deliveryTime);
    }
    
    return date('Y-m-d H:i:s', time() + (45 * 60)); // Default 45 minutes
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
    
    // Add status history entries from database
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
    
    // Add current status if not already in timeline
    if (!empty($order['status'])) {
        $hasCurrent = false;
        foreach ($timeline as $entry) {
            if ($entry['status'] === strtolower(str_replace(' ', '_', $order['status']))) {
                $hasCurrent = true;
                break;
            }
        }
        
        if (!$hasCurrent) {
            $timeline[] = [
                'status' => strtolower(str_replace(' ', '_', $order['status'])),
                'description' => getStatusDescription($order['status']),
                'timestamp' => $order['updated_at'],
                'location' => $trackingInfo['location_updates'] ?? '',
                'icon' => getStatusIcon($order['status']),
                'color' => getStatusColor($order['status'])
            ];
        }
    }
    
    // Sort by timestamp
    usort($timeline, function($a, $b) {
        return strtotime($a['timestamp']) - strtotime($b['timestamp']);
    });
    
    return $timeline;
}

function getStatusDescription($status) {
    // Get descriptions from database or use defaults
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
        'rating' => $order['driver_rating'] ?? 4.5,
        'punctuality_rating' => $driverRating['punctuality_rating'] ?? 0,
        'professionalism_rating' => $driverRating['professionalism_rating'] ?? 0,
        'image_url' => $driverImage,
        'latitude' => $order['current_latitude'] ?? 0,
        'longitude' => $order['current_longitude'] ?? 0,
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
        'latitude' => $order['merchant_latitude'] ?? 0,
        'longitude' => $order['merchant_longitude'] ?? 0
    ];
}

function buildDeliveryRoute($order, $locationHistory) {
    $route = [];
    
    // Merchant location from database
    if (!empty($order['merchant_latitude']) && !empty($order['merchant_longitude'])) {
        $route[] = [
            'lat' => floatval($order['merchant_latitude']),
            'lng' => floatval($order['merchant_longitude']),
            'name' => $order['merchant_name'],
            'type' => 'merchant'
        ];
    }
    
    // Driver location history from database
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
    
    // Delivery address from database
    if (!empty($order['latitude']) && !empty($order['longitude'])) {
        $route[] = [
            'lat' => floatval($order['latitude']),
            'lng' => floatval($order['longitude']),
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