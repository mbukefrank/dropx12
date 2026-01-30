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
 * SESSION CONFIG
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

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * BASE URL CONFIGURATION
 *********************************/
// Update this with your actual backend URL
$baseUrl = "https://dropxbackend-production.up.railway.app";

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = str_replace('/api/track', '', $path); // Assuming this file is in /api/track/

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
        $orderId = $matches[1];
        getOrderTracking($conn, $orderId, $baseUrl);
    } elseif ($path === '/driver-location') {
        getDriverLocation($conn);
    } elseif ($path === '/realtime-updates') {
        getRealTimeUpdates($conn);
    } else {
        ResponseHandler::error('Invalid endpoint', 404);
    }
}

/*********************************
 * GET ORDER TRACKING DETAILS
 *********************************/
function getOrderTracking($conn, $orderIdentifier, $baseUrl) {
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
                o.delivery_address,
                o.special_instructions,
                o.status,
                o.scheduled_delivery_time,
                o.actual_delivery_time,
                o.created_at,
                o.updated_at,
                m.name as merchant_name,
                m.address as merchant_address,
                m.phone as merchant_phone,
                m.image_url as merchant_image,
                d.name as driver_name,
                d.phone as driver_phone,
                d.current_latitude,
                d.current_longitude,
                a.full_name as user_name,
                a.phone as user_phone,
                a.address_line1,
                a.address_line2,
                a.city,
                a.neighborhood,
                a.area,
                a.sector,
                a.latitude as user_latitude,
                a.longitude as user_longitude
            FROM orders o
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            LEFT JOIN addresses a ON o.delivery_address LIKE CONCAT('%', a.id, '%')
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
                o.delivery_address,
                o.special_instructions,
                o.status,
                o.scheduled_delivery_time,
                o.actual_delivery_time,
                o.created_at,
                o.updated_at,
                m.name as merchant_name,
                m.address as merchant_address,
                m.phone as merchant_phone,
                m.image_url as merchant_image,
                d.name as driver_name,
                d.phone as driver_phone,
                d.current_latitude,
                d.current_longitude,
                a.full_name as user_name,
                a.phone as user_phone,
                a.address_line1,
                a.address_line2,
                a.city,
                a.neighborhood,
                a.area,
                a.sector,
                a.latitude as user_latitude,
                a.longitude as user_longitude
            FROM orders o
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            LEFT JOIN addresses a ON o.delivery_address LIKE CONCAT('%', a.id, '%')
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
            item_name,
            quantity,
            price,
            total
        FROM order_items
        WHERE order_id = :order_id"
    );
    $itemsStmt->execute([':order_id' => $order['id']]);
    $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get driver rating if exists
    $ratingStmt = $conn->prepare(
        "SELECT 
            rating,
            punctuality_rating,
            professionalism_rating,
            comment
        FROM driver_ratings
        WHERE order_id = :order_id
        LIMIT 1"
    );
    $ratingStmt->execute([':order_id' => $order['id']]);
    $driverRating = $ratingStmt->fetch(PDO::FETCH_ASSOC);

    // Get order tracking info
    $trackingStmt = $conn->prepare(
        "SELECT 
            status,
            estimated_delivery,
            location_updates,
            created_at
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

    // Format delivery address
    $deliveryAddress = formatDeliveryAddress($order);

    // Calculate delivery progress
    $deliveryProgress = calculateDeliveryProgress($order['status'], $order['created_at']);

    // Estimate delivery time
    $estimatedDelivery = estimateDeliveryTime($order, $trackingInfo);

    // Build delivery status timeline
    $deliveryStatus = buildDeliveryStatusTimeline($statusHistory, $order, $trackingInfo);

    // Build driver info
    $driverInfo = null;
    if ($order['driver_id']) {
        $driverInfo = buildDriverInfo($order, $driverRating, $baseUrl);
    }

    // Build delivery route
    $deliveryRoute = buildDeliveryRoute($order);

    ResponseHandler::success([
        'order' => formatOrderData($order, $merchantImage, $deliveryAddress),
        'items' => $orderItems,
        'tracking' => [
            'current_status' => $order['status'],
            'progress' => $deliveryProgress,
            'estimated_delivery' => $estimatedDelivery,
            'status_timeline' => $deliveryStatus,
            'driver' => $driverInfo,
            'delivery_route' => $deliveryRoute
        ],
        'actions' => getAvailableActions($order['status'])
    ]);
}

/*********************************
 * GET DRIVER LOCATION (REALTIME)
 *********************************/
function getDriverLocation($conn) {
    $orderId = $_GET['order_id'] ?? null;
    
    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
    }

    $stmt = $conn->prepare(
        "SELECT 
            d.current_latitude,
            d.current_longitude,
            d.name,
            d.phone,
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

    ResponseHandler::success([
        'location' => [
            'latitude' => $driver['current_latitude'],
            'longitude' => $driver['current_longitude'],
            'timestamp' => $driver['updated_at'],
            'status' => $driver['status']
        ]
    ]);
}

/*********************************
 * GET REAL-TIME UPDATES
 *********************************/
function getRealTimeUpdates($conn) {
    $orderId = $_GET['order_id'] ?? null;
    $lastUpdate = $_GET['last_update'] ?? null;
    
    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
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

    ResponseHandler::success([
        'has_updates' => !empty($newStatuses) || !empty($trackingUpdate),
        'status_updates' => $newStatuses,
        'tracking_update' => $trackingUpdate,
        'driver_location' => $driverLocation,
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
    
    switch ($path) {
        case '/rate-delivery':
            rateDelivery($conn, $input);
            break;
        case '/call-driver':
            callDriver($conn, $input);
            break;
        case '/share-tracking':
            shareTracking($conn, $input);
            break;
        case '/cancel-order':
            cancelOrder($conn, $input);
            break;
        case '/contact-support':
            contactSupport($conn, $input);
            break;
        case '/update-preferences':
            updateTrackingPreferences($conn, $input);
            break;
        default:
            ResponseHandler::error('Invalid endpoint', 404);
    }
}

/*********************************
 * RATE DELIVERY
 *********************************/
function rateDelivery($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
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

    ResponseHandler::success([], 'Thank you for your feedback!');
}

/*********************************
 * CALL DRIVER
 *********************************/
function callDriver($conn, $data) {
    $orderId = $data['order_id'] ?? null;
    
    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
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

    // Log the call attempt
    $logStmt = $conn->prepare(
        "INSERT INTO user_activities 
            (user_id, activity_type, description, ip_address, metadata, created_at)
         VALUES (:user_id, 'call_driver', 'Attempted to call driver for order', 
                :ip_address, :metadata, NOW())"
    );
    
    $logStmt->execute([
        ':user_id' => $_SESSION['user_id'] ?? null,
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
function shareTracking($conn, $data) {
    $orderId = $data['order_id'] ?? null;
    
    if (!$orderId) {
        ResponseHandler::error('Order ID is required', 400);
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

    // Generate tracking URL (customize with your actual domain)
    $trackingUrl = "https://yourapp.com/track/" . $order['order_number'];
    
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
function cancelOrder($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
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
function contactSupport($conn, $data) {
    $orderId = $data['order_id'] ?? null;
    $issue = trim($data['issue'] ?? '');
    $details = trim($data['details'] ?? '');
    
    if (!$issue) {
        ResponseHandler::error('Please describe the issue', 400);
    }

    // Get order details for context
    $orderInfo = null;
    if ($orderId) {
        $stmt = $conn->prepare(
            "SELECT order_number, status FROM orders WHERE id = :order_id"
        );
        $stmt->execute([':order_id' => $orderId]);
        $orderInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Log support request
    $logStmt = $conn->prepare(
        "INSERT INTO user_activities 
            (user_id, activity_type, description, ip_address, metadata, created_at)
         VALUES (:user_id, 'contact_support', :description, 
                :ip_address, :metadata, NOW())"
    );
    
    $logStmt->execute([
        ':user_id' => $_SESSION['user_id'] ?? null,
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
function updateTrackingPreferences($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $pushNotifications = $data['push_notifications'] ?? null;
    $emailNotifications = $data['email_notifications'] ?? null;
    $smsNotifications = $data['sms_notifications'] ?? null;

    // Check if settings exist
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
        
        if (!empty($updateFields)) {
            $sql = "UPDATE notification_settings SET " . 
                   implode(', ', $updateFields) . 
                   ", updated_at = NOW() WHERE user_id = :user_id";
            
            $updateStmt = $conn->prepare($sql);
            $updateStmt->execute($params);
        }
    } else {
        // Create new
        $insertStmt = $conn->prepare(
            "INSERT INTO notification_settings 
                (user_id, push_notifications, email_notifications, 
                 sms_notifications, order_updates, created_at)
             VALUES (:user_id, :push, :email, :sms, 1, NOW())"
        );
        
        $insertStmt->execute([
            ':user_id' => $userId,
            ':push' => $pushNotifications !== null ? ($pushNotifications ? 1 : 0) : 1,
            ':email' => $emailNotifications !== null ? ($emailNotifications ? 1 : 0) : 1,
            ':sms' => $smsNotifications !== null ? ($smsNotifications ? 1 : 0) : 0
        ]);
    }

    ResponseHandler::success([], 'Tracking preferences updated');
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
            $folder = 'merchants';
            break;
        case 'drivers':
            $folder = 'drivers';
            break;
        case 'avatars':
            $folder = 'avatars';
            break;
        default:
            $folder = 'uploads';
    }
    
    return rtrim($baseUrl, '/') . '/' . $folder . '/' . $imagePath;
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
        $addressParts[] = 'Sector ' . $order['sector'];
    }
    
    if (!empty($order['area'])) {
        $addressParts[] = $order['area'];
    }
    
    if (!empty($order['city'])) {
        $addressParts[] = $order['city'];
    }
    
    return implode(', ', $addressParts);
}

function calculateDeliveryProgress($status, $createdAt) {
    $statusWeights = [
        'pending' => 0.1,
        'confirmed' => 0.2,
        'preparing' => 0.4,
        'ready' => 0.6,
        'picked_up' => 0.8,
        'on_the_way' => 0.9,
        'arrived' => 0.95,
        'delivered' => 1.0,
        'cancelled' => 0.0
    ];
    
    return $statusWeights[$status] ?? 0.1;
}

function estimateDeliveryTime($order, $trackingInfo) {
    $baseTime = 45; // Default 45 minutes
    
    if (!empty($trackingInfo['estimated_delivery'])) {
        return $trackingInfo['estimated_delivery'];
    }
    
    if (!empty($order['created_at'])) {
        $orderTime = strtotime($order['created_at']);
        $deliveryTime = date('Y-m-d H:i:s', $orderTime + ($baseTime * 60));
        return $deliveryTime;
    }
    
    return date('Y-m-d H:i:s', time() + ($baseTime * 60));
}

function buildDeliveryStatusTimeline($statusHistory, $order, $trackingInfo) {
    $timeline = [];
    $now = new DateTime();
    
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
        'pending' => 'hourglass_empty',
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

function buildDriverInfo($order, $driverRating, $baseUrl) {
    $driverImage = formatImageUrl('driver_placeholder.jpg', $baseUrl, 'drivers');
    
    // Get driver's vehicle info from additional table or default
    $vehicleInfo = [
        'type' => 'Motorcycle',
        'number' => 'LL 0000',
        'color' => 'Black'
    ];
    
    return [
        'id' => $order['driver_id'],
        'name' => $order['driver_name'] ?? 'Driver',
        'phone' => $order['driver_phone'] ?? '',
        'vehicle' => $vehicleInfo['type'],
        'vehicle_number' => $vehicleInfo['number'],
        'rating' => $driverRating['rating'] ?? 4.5,
        'punctuality_rating' => $driverRating['punctuality_rating'] ?? 0,
        'professionalism_rating' => $driverRating['professionalism_rating'] ?? 0,
        'image_url' => $driverImage,
        'latitude' => $order['current_latitude'] ?? 0,
        'longitude' => $order['current_longitude'] ?? 0
    ];
}

function buildDeliveryRoute($order) {
    // Default route points (Lilongwe coordinates)
    $route = [];
    
    // Merchant location
    if (!empty($order['merchant_address'])) {
        $route[] = [
            'lat' => -13.9626,
            'lng' => 33.7741,
            'name' => $order['merchant_name'],
            'type' => 'merchant'
        ];
    }
    
    // Driver current location
    if (!empty($order['current_latitude']) && !empty($order['current_longitude'])) {
        $route[] = [
            'lat' => floatval($order['current_latitude']),
            'lng' => floatval($order['current_longitude']),
            'name' => 'Driver Location',
            'type' => 'driver'
        ];
    }
    
    // User location
    if (!empty($order['user_latitude']) && !empty($order['user_longitude'])) {
        $route[] = [
            'lat' => floatval($order['user_latitude']),
            'lng' => floatval($order['user_longitude']),
            'name' => 'Delivery Address',
            'type' => 'destination'
        ];
    } else {
        // Fallback to approximate Lilongwe coordinates
        $route[] = [
            'lat' => -13.9660,
            'lng' => 33.7770,
            'name' => 'Delivery Address',
            'type' => 'destination'
        ];
    }
    
    return $route;
}

function formatOrderData($order, $merchantImage, $deliveryAddress) {
    return [
        'id' => $order['id'],
        'order_number' => $order['order_number'],
        'status' => $order['status'],
        'created_at' => $order['created_at'],
        'updated_at' => $order['updated_at'],
        'merchant' => [
            'id' => $order['merchant_id'],
            'name' => $order['merchant_name'],
            'address' => $order['merchant_address'],
            'phone' => $order['merchant_phone'],
            'image_url' => $merchantImage
        ],
        'delivery_address' => $deliveryAddress,
        'special_instructions' => $order['special_instructions'],
        'payment_method' => $order['payment_method'],
        'amounts' => [
            'subtotal' => floatval($order['subtotal']),
            'delivery_fee' => floatval($order['delivery_fee']),
            'total' => floatval($order['total_amount'])
        ],
        'scheduled_delivery' => $order['scheduled_delivery_time'],
        'actual_delivery' => $order['actual_delivery_time']
    ];
}

function getAvailableActions($status) {
    $actions = [
        'share' => true,
        'refresh' => true,
        'contact_support' => true
    ];
    
    switch ($status) {
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
    }
    
    return $actions;
}
?>