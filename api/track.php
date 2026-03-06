<?php
/*********************************
 * CORS Configuration
 *********************************/
// Start output buffering to prevent headers already sent error
ob_start();

// Turn off display_errors for production
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Session-Token, X-App-Version, X-Platform, X-Device-ID, X-Timestamp, X-User-ID");
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
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (strpos($errstr, 'Undefined array key') !== false) {
        return true;
    }
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

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
    $sessionToken = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? null;
    
    if ($sessionToken) {
        session_id($sessionToken);
        session_start();
    }
    
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['logged_in'])) {
        return $_SESSION['user_id'];
    }
    
    $userId = $_SERVER['HTTP_X_USER_ID'] ?? null;
    if ($userId) {
        return $userId;
    }
    
    return null;
}

/*********************************
 * CONSTANTS
 *********************************/
define('ORDER_STATUSES', [
    'pending' => ['progress' => 10, 'label' => 'Order Placed', 'color' => '#FFA500'],
    'confirmed' => ['progress' => 20, 'label' => 'Confirmed', 'color' => '#4CAF50'],
    'preparing' => ['progress' => 40, 'label' => 'Preparing', 'color' => '#2196F3'],
    'ready' => ['progress' => 60, 'label' => 'Ready for Pickup', 'color' => '#9C27B0'],
    'picked_up' => ['progress' => 80, 'label' => 'Picked Up', 'color' => '#FF9800'],
    'on_the_way' => ['progress' => 90, 'label' => 'On The Way', 'color' => '#00BCD4'],
    'arrived' => ['progress' => 95, 'label' => 'Arrived', 'color' => '#8BC34A'],
    'delivered' => ['progress' => 100, 'label' => 'Delivered', 'color' => '#4CAF50'],
    'cancelled' => ['progress' => 0, 'label' => 'Cancelled', 'color' => '#F44336']
]);

define('DROPX_STATUSES', [
    'pending' => ['progress' => 10, 'label' => 'Order Received'],
    'assigned' => ['progress' => 20, 'label' => 'Driver Assigned'],
    'heading_to_pickup' => ['progress' => 30, 'label' => 'Heading to Pickup'],
    'arrived_at_pickup' => ['progress' => 40, 'label' => 'Arrived at Merchant'],
    'pickup_in_progress' => ['progress' => 50, 'label' => 'Picking Up'],
    'picked_up' => ['progress' => 60, 'label' => 'Picked Up'],
    'heading_to_delivery' => ['progress' => 70, 'label' => 'Heading to You'],
    'arrived' => ['progress' => 90, 'label' => 'Arrived'],
    'delivered' => ['progress' => 100, 'label' => 'Delivered']
]);

define('TRACKABLE_STATUSES', [
    'confirmed', 'preparing', 'ready', 'picked_up', 'on_the_way', 'arrived'
]);

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? $_GET['action'] ?? '';

    error_log("Tracking API called with action: " . $action);

    $userId = checkAuthentication();
    if (!$userId) {
        ob_clean();
        ResponseHandler::error('Authentication required. Please login.', 401, 'AUTH_REQUIRED');
    }

    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        ob_clean();
        ResponseHandler::error('Database connection failed', 500, 'DB_CONNECTION_ERROR');
    }

    switch ($action) {
        case 'get_trackable':
            handleGetTrackableOrders($conn, $userId);
            break;
            
        case 'track':
            handleTrackOrder($conn, $input, $userId);
            break;
            
        case 'driver_location':
            handleDriverLocation($conn, $input, $userId);
            break;
            
        case 'realtime':
            handleRealTimeUpdates($conn, $input, $userId);
            break;
            
        case 'route':
            handleRouteInfo($conn, $input, $userId);
            break;
            
        case 'driver_contact':
            handleDriverContact($conn, $input, $userId);
            break;
            
        case 'share':
            handleShareTracking($conn, $input, $userId);
            break;
            
        default:
            ob_clean();
            ResponseHandler::error('Invalid action: ' . $action, 400, 'INVALID_ACTION');
    }
    
} catch (PDOException $e) {
    error_log("Database Error in tracking API: " . $e->getMessage());
    ob_clean();
    ResponseHandler::error('Database error occurred. Please try again.', 500, 'DB_ERROR');
} catch (Exception $e) {
    error_log("General Error in tracking API: " . $e->getMessage());
    ob_clean();
    ResponseHandler::error('Server error occurred. Please contact support.', 500, 'SERVER_ERROR');
}

/*********************************
 * HANDLE GET TRACKABLE ORDERS
 *********************************/
function handleGetTrackableOrders($conn, $userId) {
    try {
        error_log("Fetching trackable orders for user: " . $userId);
        
        // Get orders that are trackable
        $sql = "SELECT 
                    o.id,
                    o.order_number,
                    o.status,
                    o.dropx_pickup_status as dropx_status,
                    o.created_at,
                    o.updated_at,
                    o.total_amount,
                    o.delivery_fee,
                    o.delivery_address,
                    o.special_instructions,
                    o.payment_method,
                    o.payment_status,
                    o.cancellable,
                    
                    -- Merchant details
                    m.id as merchant_id,
                    m.name as merchant_name,
                    m.address as merchant_address,
                    m.phone as merchant_phone,
                    m.image_url as merchant_image,
                    m.latitude as merchant_lat,
                    m.longitude as merchant_lng,
                    
                    -- Driver details
                    d.id as driver_id,
                    d.name as driver_name,
                    d.phone as driver_phone,
                    d.vehicle_type,
                    d.vehicle_number,
                    
                    -- User details
                    u.full_name as customer_name,
                    u.phone as customer_phone,
                    u.email as customer_email
                    
                FROM orders o
                LEFT JOIN merchants m ON o.merchant_id = m.id
                LEFT JOIN drivers d ON o.driver_id = d.id
                LEFT JOIN users u ON o.user_id = u.id
                WHERE o.user_id = :user_id 
                AND o.status IN ('pending','confirmed','preparing','ready','picked_up','on_the_way','arrived')
                ORDER BY o.updated_at DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Found " . count($orders) . " trackable orders for user: " . $userId);
        
        $formattedOrders = [];
        foreach ($orders as $order) {
            // Get items preview for this order
            $previewItems = getOrderItemsPreview($conn, $order['id']);
            
            $statusInfo = getStatusDisplayInfo($order['status'], $order['dropx_status'] ?? null);
            
            // Calculate if order is cancellable
            $cancellable = in_array($order['status'], ['pending', 'confirmed']);
            
            $formattedOrders[] = [
                'id' => (string)$order['id'],
                'order_number' => $order['order_number'],
                'status' => $order['status'],
                'dropx_status' => $order['dropx_status'],
                'order_type' => 'Food Delivery',
                'customer_name' => $order['customer_name'] ?? 'Customer',
                'customer_phone' => $order['customer_phone'] ?? '',
                'delivery_address' => $order['delivery_address'] ?? '',
                'total_amount' => floatval($order['total_amount'] ?? 0),
                'delivery_fee' => floatval($order['delivery_fee'] ?? 0),
                'items' => $previewItems,
                'order_date' => $order['created_at'],
                'estimated_delivery' => calculateEstimatedDelivery($order),
                'estimated_pickup' => null,
                'payment_method' => $order['payment_method'] ?? 'cash',
                'payment_status' => $order['payment_status'] ?? 'pending',
                'restaurant_name' => $order['merchant_name'],
                'merchant_id' => (string)$order['merchant_id'],
                'driver_name' => $order['driver_name'],
                'driver_phone' => $order['driver_phone'],
                'special_instructions' => $order['special_instructions'],
                'cancellable' => $cancellable,
                
                // Additional fields for display
                'display_status' => $statusInfo['label'],
                'progress' => $statusInfo['progress'],
                'merchant_image' => formatImageUrl($order['merchant_image'], 'merchants'),
                'items_preview' => array_slice($previewItems, 0, 2),
                'created_at' => $order['created_at'],
                'updated_at' => $order['updated_at']
            ];
        }
        
        ob_clean();
        ResponseHandler::success([
            'trackable_orders' => $formattedOrders,
            'count' => count($formattedOrders)
        ]);
        
    } catch (Exception $e) {
        error_log("Error in handleGetTrackableOrders: " . $e->getMessage());
        ob_clean();
        ResponseHandler::error('Failed to fetch trackable orders: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * HANDLE TRACK ORDER
 *********************************/
function handleTrackOrder($conn, $input, $userId) {
    try {
        $identifier = $input['tracking_id'] ?? $input['order_id'] ?? $input['order_number'] ?? '';
        
        if (empty($identifier)) {
            ResponseHandler::error('Tracking ID or Order ID required', 400, 'MISSING_ID');
        }

        error_log("Tracking order with identifier: " . $identifier . " for user: " . $userId);

        $order = findUserOrder($conn, $identifier, $userId);
        
        if (!$order) {
            ResponseHandler::error('Order not found or access denied', 404, 'ORDER_NOT_FOUND');
        }

        // Get driver information
        $driver = null;
        if ($order['driver_id']) {
            $driver = getDriverTrackingInfo($conn, $order['driver_id']);
        }

        // Get timeline
        $timeline = getOrderTimeline($conn, $order['id']);

        // Get waypoints
        $waypoints = getOrderWaypoints($conn, $order);

        // Calculate progress and status info
        $progress = calculateTrackingProgress($order);
        $statusInfo = getStatusDisplayInfo($order['status'], $order['dropx_pickup_status'] ?? null);

        // Check if order can be cancelled
        $cancellable = in_array($order['status'], ['pending', 'confirmed']);

        // Calculate estimated delivery
        $estimatedDelivery = calculateEstimatedDelivery($order);
        $estimatedPickup = $order['dropx_estimated_pickup_time'] ?? null;

        $response = [
            'tracking' => [
                'id' => $order['order_number'],
                'order_number' => $order['order_number'],
                'order_id' => $order['id'],
                'status' => $order['status'],
                'dropx_status' => $order['dropx_pickup_status'] ?? null,
                'display_status' => $statusInfo['label'],
                'status_color' => $statusInfo['color'],
                'progress' => $progress,
                'estimated_delivery' => $estimatedDelivery,
                'estimated_pickup' => $estimatedPickup,
                'created_at' => $order['created_at'],
                'updated_at' => $order['updated_at'],
                'cancellable' => $cancellable
            ],
            'delivery' => [
                'address' => $order['delivery_address'] ?? '',
                'instructions' => $order['special_instructions'] ?? '',
                'latitude' => floatval($order['delivery_lat'] ?? 0),
                'longitude' => floatval($order['delivery_lng'] ?? 0)
            ],
            'merchant' => [
                'id' => (string)($order['merchant_id'] ?? ''),
                'name' => $order['merchant_name'] ?? 'Restaurant',
                'address' => $order['merchant_address'] ?? '',
                'phone' => $order['merchant_phone'] ?? '',
                'latitude' => $order['merchant_lat'] ? floatval($order['merchant_lat']) : null,
                'longitude' => $order['merchant_lng'] ? floatval($order['merchant_lng']) : null
            ],
            'driver' => $driver,
            'timeline' => $timeline,
            'waypoints' => $waypoints,
            'items' => getOrderItems($conn, $order['id'])
        ];

        ob_clean();
        ResponseHandler::success($response, 'Tracking information retrieved');
        
    } catch (Exception $e) {
        error_log("Error in handleTrackOrder: " . $e->getMessage());
        ob_clean();
        ResponseHandler::error('Failed to track order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * HANDLE DRIVER LOCATION
 *********************************/
function handleDriverLocation($conn, $input, $userId) {
    try {
        $identifier = $input['tracking_id'] ?? $input['order_id'] ?? '';
        
        if (empty($identifier)) {
            ResponseHandler::error('Tracking ID or Order ID required', 400, 'MISSING_ID');
        }
        
        $order = findUserOrder($conn, $identifier, $userId);
        
        if (!$order) {
            ResponseHandler::error('Order not found or access denied', 404, 'ORDER_NOT_FOUND');
        }
        
        if (!$order['driver_id']) {
            ResponseHandler::success([
                'has_driver' => false,
                'message' => 'No driver assigned yet',
                'estimated_pickup' => $order['dropx_estimated_pickup_time'] ?? null
            ]);
        }
        
        $stmt = $conn->prepare("
            SELECT 
                d.id,
                d.name,
                d.current_latitude,
                d.current_longitude,
                d.updated_at as location_updated_at,
                d.vehicle_type,
                d.vehicle_number,
                d.heading,
                d.speed
            FROM drivers d
            WHERE d.id = ?
        ");
        $stmt->execute([$order['driver_id']]);
        $driver = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$driver) {
            ResponseHandler::error('Driver information not available', 404, 'DRIVER_NOT_FOUND');
        }
        
        $eta = calculateETA($conn, $order['id']);
        
        ResponseHandler::success([
            'has_driver' => true,
            'driver' => [
                'id' => (string)$driver['id'],
                'name' => $driver['name'],
                'vehicle' => $driver['vehicle_type'],
                'vehicle_number' => $driver['vehicle_number']
            ],
            'location' => [
                'latitude' => floatval($driver['current_latitude']),
                'longitude' => floatval($driver['current_longitude']),
                'heading' => floatval($driver['heading'] ?? 0),
                'speed' => floatval($driver['speed'] ?? 0),
                'last_updated' => $driver['location_updated_at'],
                'age_seconds' => time() - strtotime($driver['location_updated_at'])
            ],
            'estimated_arrival' => $eta,
            'order_status' => $order['status']
        ]);
        
    } catch (Exception $e) {
        error_log("Error in handleDriverLocation: " . $e->getMessage());
        ob_clean();
        ResponseHandler::error('Failed to get driver location: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * HANDLE REAL-TIME UPDATES
 *********************************/
function handleRealTimeUpdates($conn, $input, $userId) {
    try {
        $identifier = $input['tracking_id'] ?? $input['order_id'] ?? '';
        $lastUpdate = $input['last_update'] ?? null;
        
        if (empty($identifier)) {
            ResponseHandler::error('Tracking ID or Order ID required', 400, 'MISSING_ID');
        }
        
        $order = findUserOrder($conn, $identifier, $userId);
        
        if (!$order) {
            ResponseHandler::error('Order not found or access denied', 404, 'ORDER_NOT_FOUND');
        }
        
        $updates = [];
        $hasUpdates = false;
        $currentTime = date('Y-m-d H:i:s');
        
        // Check for order status updates
        if (!$lastUpdate || strtotime($order['updated_at']) > strtotime($lastUpdate)) {
            $statusInfo = getStatusDisplayInfo($order['status'], $order['dropx_pickup_status'] ?? null);
            
            $updates['order'] = [
                'status' => $order['status'],
                'dropx_status' => $order['dropx_pickup_status'] ?? null,
                'display_status' => $statusInfo['label'],
                'progress' => calculateTrackingProgress($order),
                'updated_at' => $order['updated_at']
            ];
            $hasUpdates = true;
        }
        
        // Check for driver location updates
        if ($order['driver_id']) {
            $stmt = $conn->prepare("
                SELECT current_latitude, current_longitude, updated_at
                FROM drivers
                WHERE id = ? AND (? IS NULL OR updated_at > ?)
            ");
            $stmt->execute([$order['driver_id'], $lastUpdate, $lastUpdate]);
            $driverLocation = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($driverLocation) {
                $updates['driver_location'] = [
                    'latitude' => floatval($driverLocation['current_latitude']),
                    'longitude' => floatval($driverLocation['current_longitude']),
                    'updated_at' => $driverLocation['updated_at']
                ];
                $hasUpdates = true;
            }
        }
        
        // Check for tracking events
        $stmt = $conn->prepare("
            SELECT status, description, location, created_at as timestamp
            FROM order_tracking
            WHERE order_id = ?
            AND (? IS NULL OR created_at > ?)
            ORDER BY created_at DESC
        ");
        $stmt->execute([$order['id'], $lastUpdate, $lastUpdate]);
        $trackingUpdates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($trackingUpdates)) {
            $updates['tracking_events'] = array_map(function($event) {
                return [
                    'status' => $event['status'],
                    'message' => $event['description'] ?? "Status updated to {$event['status']}",
                    'timestamp' => $event['timestamp'],
                    'location' => $event['location']
                ];
            }, $trackingUpdates);
            $hasUpdates = true;
        }
        
        ResponseHandler::success([
            'has_updates' => $hasUpdates,
            'updates' => $updates,
            'server_time' => $currentTime
        ]);
        
    } catch (Exception $e) {
        error_log("Error in handleRealTimeUpdates: " . $e->getMessage());
        ob_clean();
        ResponseHandler::error('Failed to get real-time updates: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * HANDLE ROUTE INFORMATION
 *********************************/
function handleRouteInfo($conn, $input, $userId) {
    try {
        $identifier = $input['tracking_id'] ?? $input['order_id'] ?? '';
        
        if (empty($identifier)) {
            ResponseHandler::error('Tracking ID or Order ID required', 400, 'MISSING_ID');
        }
        
        $order = findUserOrder($conn, $identifier, $userId);
        
        if (!$order) {
            ResponseHandler::error('Order not found or access denied', 404, 'ORDER_NOT_FOUND');
        }
        
        $waypoints = getOrderWaypoints($conn, $order);
        
        // Get driver location
        $driverLocation = null;
        if ($order['driver_id']) {
            $stmt = $conn->prepare("
                SELECT current_latitude, current_longitude
                FROM drivers
                WHERE id = ?
            ");
            $stmt->execute([$order['driver_id']]);
            $driverData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($driverData && $driverData['current_latitude'] && $driverData['current_longitude']) {
                $driverLocation = [
                    'latitude' => floatval($driverData['current_latitude']),
                    'longitude' => floatval($driverData['current_longitude'])
                ];
            }
        }
        
        // Find next stop
        $nextStop = null;
        foreach ($waypoints as $wp) {
            if ($wp['status'] === 'pending') {
                $nextStop = $wp;
                break;
            }
        }
        
        // Calculate progress
        $completedCount = count(array_filter($waypoints, function($wp) { 
            return $wp['status'] === 'completed'; 
        }));
        
        ResponseHandler::success([
            'driver_location' => $driverLocation,
            'waypoints' => $waypoints,
            'next_stop' => $nextStop,
            'progress' => [
                'total' => count($waypoints),
                'completed' => $completedCount,
                'percentage' => count($waypoints) > 0 
                    ? ($completedCount / count($waypoints)) * 100
                    : 0
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error in handleRouteInfo: " . $e->getMessage());
        ob_clean();
        ResponseHandler::error('Failed to get route information: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * HANDLE DRIVER CONTACT
 *********************************/
function handleDriverContact($conn, $input, $userId) {
    try {
        $orderId = $input['order_id'] ?? '';
        
        if (empty($orderId)) {
            ResponseHandler::error('Order ID required', 400, 'MISSING_ORDER_ID');
        }
        
        $stmt = $conn->prepare("
            SELECT o.driver_id
            FROM orders o
            WHERE o.id = ? AND o.user_id = ?
        ");
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            ResponseHandler::error('Order not found', 404, 'ORDER_NOT_FOUND');
        }
        
        if (!$order['driver_id']) {
            ResponseHandler::error('No driver assigned yet', 404, 'NO_DRIVER');
        }
        
        $stmt = $conn->prepare("
            SELECT 
                id,
                name,
                phone,
                whatsapp_number,
                image_url,
                vehicle_type,
                vehicle_number
            FROM drivers
            WHERE id = ?
        ");
        $stmt->execute([$order['driver_id']]);
        $driver = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$driver) {
            ResponseHandler::error('Driver not found', 404, 'DRIVER_NOT_FOUND');
        }
        
        ResponseHandler::success([
            'driver' => [
                'id' => (string)$driver['id'],
                'name' => $driver['name'],
                'phone' => $driver['phone'],
                'whatsapp' => $driver['whatsapp_number'],
                'image' => formatImageUrl($driver['image_url']),
                'vehicle' => $driver['vehicle_type'],
                'vehicle_number' => $driver['vehicle_number']
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error in handleDriverContact: " . $e->getMessage());
        ob_clean();
        ResponseHandler::error('Failed to get driver contact: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * HANDLE SHARE TRACKING
 *********************************/
function handleShareTracking($conn, $input, $userId) {
    try {
        $orderId = $input['order_id'] ?? '';
        
        if (empty($orderId)) {
            ResponseHandler::error('Order ID required', 400, 'MISSING_ORDER_ID');
        }
        
        $stmt = $conn->prepare("
            SELECT 
                o.order_number,
                o.total_amount,
                m.name as merchant_name
            FROM orders o
            LEFT JOIN merchants m ON o.merchant_id = m.id
            WHERE o.id = ? AND o.user_id = ?
        ");
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            ResponseHandler::error('Order not found', 404, 'ORDER_NOT_FOUND');
        }
        
        $trackingId = $order['order_number'];
        $trackingUrl = "https://dropx.app/track/$trackingId";
        
        $message = "Track my order from {$order['merchant_name']} on DropX!\n";
        $message .= "Order #: {$order['order_number']}\n";
        $message .= "Total: MK" . number_format($order['total_amount'], 2) . "\n";
        $message .= $trackingUrl;
        
        ResponseHandler::success([
            'tracking_id' => $trackingId,
            'tracking_url' => $trackingUrl,
            'share_message' => $message,
            'deep_link' => "dropx://track/$trackingId",
            'qr_code_url' => "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($trackingUrl)
        ]);
        
    } catch (Exception $e) {
        error_log("Error in handleShareTracking: " . $e->getMessage());
        ob_clean();
        ResponseHandler::error('Failed to share tracking: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * HELPER FUNCTIONS
 *********************************/

/**
 * Find an order belonging to a user by various identifiers
 */
function findUserOrder($conn, $identifier, $userId) {
    $sql = "SELECT 
                o.*,
                u.full_name as customer_name,
                u.phone as customer_phone,
                u.email as customer_email,
                m.name as merchant_name,
                m.address as merchant_address,
                m.phone as merchant_phone,
                m.latitude as merchant_lat,
                m.longitude as merchant_lng,
                m.image_url as merchant_image,
                d.name as driver_name,
                d.phone as driver_phone,
                d.vehicle_type,
                d.vehicle_number,
                d.current_latitude,
                d.current_longitude
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.id
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            WHERE (o.order_number = :identifier OR o.id = :identifier2)
            AND o.user_id = :user_id
            LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':identifier' => $identifier,
        ':identifier2' => $identifier,
        ':user_id' => $userId
    ]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Try tracking ID if order not found
    if (!$order) {
        $sql = "SELECT 
                    o.*,
                    u.full_name as customer_name,
                    u.phone as customer_phone,
                    u.email as customer_email,
                    m.name as merchant_name,
                    m.address as merchant_address,
                    m.phone as merchant_phone,
                    m.latitude as merchant_lat,
                    m.longitude as merchant_lng,
                    d.name as driver_name,
                    d.phone as driver_phone
                FROM order_tracking ot
                JOIN orders o ON ot.order_id = o.id
                LEFT JOIN users u ON o.user_id = u.id
                LEFT JOIN merchants m ON o.merchant_id = m.id
                LEFT JOIN drivers d ON o.driver_id = d.id
                WHERE ot.id = :identifier AND o.user_id = :user_id
                LIMIT 1";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':identifier' => $identifier,
            ':user_id' => $userId
        ]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    return $order;
}

/**
 * Get order items with add-ons
 */
function getOrderItems($conn, $orderId) {
    $stmt = $conn->prepare("
        SELECT 
            id,
            item_name as name,
            description,
            quantity,
            price,
            total,
            add_ons_json,
            variant_data,
            special_instructions
        FROM order_items
        WHERE order_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedItems = [];
    foreach ($items as $item) {
        $addOns = [];
        $addOnsTotal = 0;
        
        if (!empty($item['add_ons_json']) && $item['add_ons_json'] != 'null') {
            $addOnsData = json_decode($item['add_ons_json'], true);
            if (is_array($addOnsData)) {
                foreach ($addOnsData as $addOn) {
                    $addOnPrice = floatval($addOn['price'] ?? 0);
                    $addOnQty = intval($addOn['quantity'] ?? 1);
                    $addOnTotal = $addOnPrice * $addOnQty;
                    $addOnsTotal += $addOnTotal;
                    
                    $addOns[] = [
                        'id' => $addOn['id'] ?? null,
                        'name' => $addOn['name'] ?? 'Add-on',
                        'price' => $addOnPrice,
                        'quantity' => $addOnQty,
                        'total' => $addOnTotal
                    ];
                }
            }
        }
        
        $variant = null;
        if (!empty($item['variant_data']) && $item['variant_data'] != 'null') {
            $variant = json_decode($item['variant_data'], true);
        }
        
        $formattedItems[] = [
            'id' => (string)$item['id'],
            'name' => $item['name'],
            'description' => $item['description'] ?? '',
            'quantity' => intval($item['quantity']),
            'price' => floatval($item['price']),
            'total' => floatval($item['total']),
            'add_ons' => $addOns,
            'add_ons_total' => $addOnsTotal,
            'variant' => $variant,
            'special_instructions' => $item['special_instructions'] ?? ''
        ];
    }
    
    return $formattedItems;
}

/**
 * Get order items preview (first 3 items)
 */
function getOrderItemsPreview($conn, $orderId) {
    $stmt = $conn->prepare("
        SELECT 
            id,
            item_name as name,
            quantity,
            add_ons_json
        FROM order_items
        WHERE order_id = ?
        ORDER BY id ASC
        LIMIT 3
    ");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $preview = [];
    foreach ($items as $item) {
        $hasAddOns = !empty($item['add_ons_json']) && $item['add_ons_json'] != 'null';
        $preview[] = [
            'id' => (string)$item['id'],
            'name' => $item['name'],
            'quantity' => intval($item['quantity']),
            'price' => 0,
            'special_instructions' => '',
            'has_addons' => $hasAddOns
        ];
    }
    
    return $preview;
}

/**
 * Get order timeline/status history
 */
function getOrderTimeline($conn, $orderId) {
    $timeline = [];
    
    $stmt = $conn->prepare("SELECT created_at, order_number FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Add order placed event
    $timeline[] = [
        'id' => 'order_placed',
        'tracking_id' => '',
        'order_id' => (string)$orderId,
        'status' => 'pending',
        'title' => 'Order Placed',
        'description' => "Order #{$order['order_number']} has been received",
        'timestamp' => $order['created_at'],
        'location' => null,
        'icon' => 'shopping_bag',
        'color' => '#FFA500',
        'isCurrent' => false
    ];
    
    // Get tracking history
    $stmt = $conn->prepare("
        SELECT status, description, location, created_at as timestamp
        FROM order_tracking
        WHERE order_id = ?
        ORDER BY created_at ASC
    ");
    $stmt->execute([$orderId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($history as $index => $event) {
        if ($event['status'] !== 'pending') {
            $statusInfo = ORDER_STATUSES[$event['status']] ?? ['label' => $event['status'], 'color' => '#999999'];
            
            $timeline[] = [
                'id' => 'status_' . $event['status'] . '_' . $index,
                'tracking_id' => '',
                'order_id' => (string)$orderId,
                'status' => $event['status'],
                'title' => $statusInfo['label'],
                'description' => $event['description'] ?? "Status updated to " . $statusInfo['label'],
                'timestamp' => $event['timestamp'],
                'location' => $event['location'],
                'icon' => getStatusIcon($event['status']),
                'color' => $statusInfo['color'],
                'isCurrent' => ($index === count($history) - 1)
            ];
        }
    }
    
    return $timeline;
}

/**
 * Get order waypoints for route
 */
function getOrderWaypoints($conn, $order) {
    $waypoints = [];
    $sequence = 1;
    
    // Add pickup waypoint if merchant has location
    if (!empty($order['merchant_lat']) && !empty($order['merchant_lng'])) {
        $waypoints[] = [
            'sequence' => $sequence++,
            'type' => 'pickup',
            'name' => $order['merchant_name'] ?? 'Pickup Location',
            'address' => $order['merchant_address'] ?? '',
            'latitude' => floatval($order['merchant_lat']),
            'longitude' => floatval($order['merchant_lng']),
            'status' => in_array($order['status'], ['delivered', 'picked_up', 'on_the_way', 'arrived']) ? 'completed' : 'pending',
            'estimated_arrival' => $order['dropx_estimated_pickup_time'] ?? null
        ];
    }
    
    // Add dropoff waypoint
    $waypoints[] = [
        'sequence' => $sequence++,
        'type' => 'dropoff',
        'name' => 'Delivery Location',
        'address' => $order['delivery_address'] ?? '',
        'latitude' => floatval($order['delivery_lat'] ?? 0),
        'longitude' => floatval($order['delivery_lng'] ?? 0),
        'status' => $order['status'] === 'delivered' ? 'completed' : 'pending',
        'estimated_arrival' => calculateEstimatedDelivery($order)
    ];
    
    return $waypoints;
}

/**
 * Get driver tracking information
 */
function getDriverTrackingInfo($conn, $driverId) {
    $stmt = $conn->prepare("
        SELECT 
            id,
            name,
            phone,
            image_url,
            vehicle_type,
            vehicle_number,
            current_latitude,
            current_longitude,
            updated_at as location_updated_at,
            heading,
            speed
        FROM drivers
        WHERE id = ?
    ");
    $stmt->execute([$driverId]);
    $driver = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$driver) {
        return null;
    }
    
    $location = null;
    if ($driver['current_latitude'] && $driver['current_longitude']) {
        $location = [
            'latitude' => floatval($driver['current_latitude']),
            'longitude' => floatval($driver['current_longitude']),
            'heading' => floatval($driver['heading'] ?? 0),
            'speed' => floatval($driver['speed'] ?? 0),
            'last_updated' => $driver['location_updated_at']
        ];
    }
    
    return [
        'id' => (string)$driver['id'],
        'name' => $driver['name'],
        'phone' => $driver['phone'],
        'image' => formatImageUrl($driver['image_url']),
        'vehicle' => $driver['vehicle_type'],
        'vehicle_number' => $driver['vehicle_number'],
        'location' => $location
    ];
}

/**
 * Calculate tracking progress percentage
 */
function calculateTrackingProgress($order) {
    if ($order['status'] === 'delivered') {
        return 100;
    }
    
    if ($order['status'] === 'cancelled') {
        return 0;
    }
    
    // Use dropx status if available and order is in trackable statuses
    if (!empty($order['dropx_pickup_status']) && in_array($order['status'], TRACKABLE_STATUSES)) {
        return DROPX_STATUSES[$order['dropx_pickup_status']]['progress'] ?? 10;
    }
    
    return ORDER_STATUSES[$order['status']]['progress'] ?? 10;
}

/**
 * Get display information for a status
 */
function getStatusDisplayInfo($orderStatus, $dropxStatus = null) {
    if ($dropxStatus && isset(DROPX_STATUSES[$dropxStatus]) && in_array($orderStatus, TRACKABLE_STATUSES)) {
        return [
            'label' => DROPX_STATUSES[$dropxStatus]['label'] ?? $orderStatus,
            'progress' => DROPX_STATUSES[$dropxStatus]['progress'] ?? 10,
            'color' => '#2196F3'
        ];
    }
    
    return [
        'label' => ORDER_STATUSES[$orderStatus]['label'] ?? $orderStatus,
        'progress' => ORDER_STATUSES[$orderStatus]['progress'] ?? 10,
        'color' => ORDER_STATUSES[$orderStatus]['color'] ?? '#999999'
    ];
}

/**
 * Calculate Estimated Time of Arrival
 */
function calculateETA($conn, $orderId) {
    $stmt = $conn->prepare("
        SELECT dropx_estimated_delivery_time
        FROM orders
        WHERE id = ?
    ");
    $stmt->execute([$orderId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result['dropx_estimated_delivery_time'] ?? null;
}

/**
 * Calculate estimated delivery time
 */
function calculateEstimatedDelivery($order) {
    if (!empty($order['dropx_estimated_delivery_time'])) {
        return $order['dropx_estimated_delivery_time'];
    }
    
    if (in_array($order['status'], ['delivered', 'cancelled'])) {
        return $order['updated_at'];
    }
    
    // Calculate based on preparation time
    $prepTime = intval($order['preparation_time'] ?? 30);
    $createdAt = new DateTime($order['created_at']);
    return $createdAt->modify("+{$prepTime} minutes")->format('Y-m-d H:i:s');
}

/**
 * Get icon name for a status
 */
function getStatusIcon($status) {
    $icons = [
        'pending' => 'shopping_bag',
        'confirmed' => 'check_circle',
        'preparing' => 'restaurant',
        'ready' => 'package',
        'picked_up' => 'motorcycle',
        'on_the_way' => 'directions',
        'arrived' => 'location_on',
        'delivered' => 'check_circle',
        'cancelled' => 'cancel',
        'assigned' => 'person',
        'heading_to_pickup' => 'navigation',
        'arrived_at_pickup' => 'store',
        'pickup_in_progress' => 'inventory',
        'heading_to_delivery' => 'directions'
    ];
    
    return $icons[$status] ?? 'circle';
}

/**
 * Format image URL with base URL
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
        case 'menu_items':
            $folder = 'uploads/menu_items';
            break;
        case 'quick_orders':
            $folder = 'uploads/quick_orders';
            break;
        default:
            $folder = 'uploads';
    }
    
    // Remove leading slash if present
    $path = ltrim($path, '/');
    
    return rtrim($baseUrl, '/') . '/' . $folder . '/' . $path;
}
?>