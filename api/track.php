<?php
/*********************************
 * API CONFIGURATION
 *********************************/

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/tracking-error.log');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Session-Token, X-App-Version, X-Platform");
header("Access-Control-Max-Age: 3600");
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * REQUIRED FILES
 *********************************/
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * CONSTANTS
 *********************************/
define('ORDER_STATUSES', [
    'pending' => ['progress' => 0.1, 'label' => 'Order Placed', 'color' => '#FFA500'],
    'confirmed' => ['progress' => 0.2, 'label' => 'Confirmed', 'color' => '#4CAF50'],
    'preparing' => ['progress' => 0.4, 'label' => 'Preparing', 'color' => '#2196F3'],
    'ready' => ['progress' => 0.6, 'label' => 'Ready for Pickup', 'color' => '#9C27B0'],
    'picked_up' => ['progress' => 0.8, 'label' => 'Picked Up', 'color' => '#FF9800'],
    'on_the_way' => ['progress' => 0.9, 'label' => 'On The Way', 'color' => '#00BCD4'],
    'arrived' => ['progress' => 0.95, 'label' => 'Arrived', 'color' => '#8BC34A'],
    'delivered' => ['progress' => 1.0, 'label' => 'Delivered', 'color' => '#4CAF50'],
    'cancelled' => ['progress' => 0.0, 'label' => 'Cancelled', 'color' => '#F44336']
]);

define('DROPX_STATUSES', [
    'pending' => ['progress' => 0.1, 'label' => 'Order Received'],
    'assigned' => ['progress' => 0.2, 'label' => 'Driver Assigned'],
    'heading_to_pickup' => ['progress' => 0.3, 'label' => 'Heading to Pickup'],
    'arrived_at_pickup' => ['progress' => 0.4, 'label' => 'Arrived at Merchant'],
    'pickup_in_progress' => ['progress' => 0.5, 'label' => 'Picking Up'],
    'picked_up' => ['progress' => 0.6, 'label' => 'Picked Up'],
    'heading_to_delivery' => ['progress' => 0.7, 'label' => 'Heading to You'],
    'arrived' => ['progress' => 0.9, 'label' => 'Arrived'],
    'delivered' => ['progress' => 1.0, 'label' => 'Delivered']
]);

define('TRACKABLE_STATUSES', [
    'confirmed', 'preparing', 'ready', 'picked_up', 'on_the_way', 'arrived'
]);

/*********************************
 * SESSION/AUTH CONFIG
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

function checkAuthentication() {
    $sessionToken = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? $_GET['session_token'] ?? null;
    
    if ($sessionToken) {
        session_id($sessionToken);
        session_start();
    }
    
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['logged_in'])) {
        return $_SESSION['user_id'];
    }
    return null;
}

/*********************************
 * BASE URL
 *********************************/
$baseUrl = "https://dropx12-production.up.railway.app";

/*********************************
 * MAIN HANDLER
 *********************************/
try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? $_GET['action'] ?? '';
    
    if (empty($action)) {
        ResponseHandler::error('Action parameter required', 400, 'MISSING_ACTION');
    }

    $db = new Database();
    $conn = $db->getConnection();
    
    // ALL ENDPOINTS REQUIRE AUTHENTICATION
    $userId = checkAuthentication();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401, 'AUTH_REQUIRED');
    }
    
    switch ($action) {
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
            
        case 'summary':
            handleTrackingSummary($conn, $userId);
            break;
            
        case 'get_trackable':
            handleGetTrackableOrders($conn, $userId);
            break;
            
        case 'driver_contact':
            handleDriverContact($conn, $input, $userId);
            break;
            
        case 'share':
            handleShareTracking($conn, $input, $userId);
            break;
            
        default:
            ResponseHandler::error('Invalid action', 400, 'INVALID_ACTION');
    }
    
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    ResponseHandler::error('Database error occurred', 500, 'DB_ERROR');
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    ResponseHandler::error('Server error occurred', 500, 'SERVER_ERROR');
}

/*********************************
 * HANDLER FUNCTIONS
 *********************************/

function handleTrackOrder($conn, $input, $userId) {
    $identifier = $input['tracking_id'] ?? $input['order_id'] ?? $input['order_number'] ?? '';
    
    if (empty($identifier)) {
        ResponseHandler::error('Tracking ID or Order ID required', 400, 'MISSING_ID');
    }

    $order = findUserOrder($conn, $identifier, $userId);
    
    if (!$order) {
        ResponseHandler::error('Order not found or access denied', 404, 'ORDER_NOT_FOUND');
    }

    $driver = null;
    $timeline = getOrderTimeline($conn, $order['id']);

    if ($order['driver_id']) {
        $driver = getDriverTrackingInfo($conn, $order['driver_id']);
    }

    $estimatedDelivery = $order['dropx_estimated_delivery_time'] ?? null;
    $progress = calculateTrackingProgress($order);
    $statusInfo = getStatusDisplayInfo($order['status'], $order['dropx_pickup_status']);

    // Check if order can be cancelled (only pending or confirmed status)
    $cancellable = in_array($order['status'], ['pending', 'confirmed']);

    $response = [
        'tracking' => [
            'id' => $order['order_number'],
            'order_number' => $order['order_number'],
            'order_id' => $order['id'],
            'status' => $order['status'],
            'dropx_status' => $order['dropx_pickup_status'],
            'display_status' => $statusInfo['label'],
            'status_color' => $statusInfo['color'],
            'progress' => $progress,
            'estimated_delivery' => $estimatedDelivery,
            'estimated_pickup' => $order['dropx_estimated_pickup_time'],
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at'],
            'cancellable' => $cancellable  // Add this to inform UI if cancel button should show
        ],
        'delivery' => [
            'address' => $order['delivery_address'],
            'instructions' => $order['special_instructions']
        ],
        'merchant' => [
            'id' => $order['merchant_id'],
            'name' => $order['merchant_name'],
            'address' => $order['merchant_address'],
            'phone' => $order['merchant_phone']
            // REMOVED: 'image' => formatImageUrl($order['merchant_image'])
        ],
        'driver' => $driver,
        'timeline' => $timeline
    ];

    ResponseHandler::success($response, 'Tracking information retrieved');
}

function handleDriverLocation($conn, $input, $userId) {
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
            'estimated_pickup' => $order['dropx_estimated_pickup_time']
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
    
    $eta = calculateETA($conn, $order['id'], $driver);
    
    ResponseHandler::success([
        'has_driver' => true,
        'driver' => [
            'id' => $driver['id'],
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
}

function handleRealTimeUpdates($conn, $input, $userId) {
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
    
    if (!$lastUpdate || strtotime($order['updated_at']) > strtotime($lastUpdate)) {
        $statusInfo = getStatusDisplayInfo($order['status'], $order['dropx_pickup_status']);
        
        $updates['order'] = [
            'status' => $order['status'],
            'dropx_status' => $order['dropx_pickup_status'],
            'display_status' => $statusInfo['label'],
            'progress' => calculateTrackingProgress($order),
            'updated_at' => $order['updated_at']
        ];
        $hasUpdates = true;
    }
    
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
    
    $stmt = $conn->prepare("
        SELECT ot.*
        FROM order_tracking ot
        WHERE ot.order_id = ?
        AND (? IS NULL OR ot.created_at > ?)
        ORDER BY ot.created_at DESC
    ");
    $stmt->execute([$order['id'], $lastUpdate, $lastUpdate]);
    $trackingUpdates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($trackingUpdates)) {
        $updates['tracking_events'] = array_map(function($event) {
            return [
                'status' => $event['status'],
                'message' => "Status updated to {$event['status']}",
                'timestamp' => $event['created_at']
            ];
        }, $trackingUpdates);
        $hasUpdates = true;
    }
    
    ResponseHandler::success([
        'has_updates' => $hasUpdates,
        'updates' => $updates,
        'server_time' => $currentTime
    ]);
}

function handleRouteInfo($conn, $input, $userId) {
    $identifier = $input['tracking_id'] ?? $input['order_id'] ?? '';
    
    if (empty($identifier)) {
        ResponseHandler::error('Tracking ID or Order ID required', 400, 'MISSING_ID');
    }
    
    $order = findUserOrder($conn, $identifier, $userId);
    
    if (!$order) {
        ResponseHandler::error('Order not found or access denied', 404, 'ORDER_NOT_FOUND');
    }
    
    $waypoints = [
        [
            'sequence' => 1,
            'type' => 'pickup',
            'name' => $order['merchant_name'],
            'address' => $order['merchant_address'],
            'location' => [
                'latitude' => floatval($order['merchant_lat'] ?? 0),
                'longitude' => floatval($order['merchant_lng'] ?? 0)
            ],
            'status' => $order['status'] === 'delivered' ? 'completed' : 
                       (in_array($order['status'], ['picked_up', 'on_the_way', 'arrived']) ? 'completed' : 'pending'),
            'estimated_arrival' => $order['dropx_estimated_pickup_time']
        ],
        [
            'sequence' => 2,
            'type' => 'dropoff',
            'name' => 'Your Location',
            'address' => $order['delivery_address'],
            'location' => [
                'latitude' => 0,
                'longitude' => 0
            ],
            'status' => $order['status'] === 'delivered' ? 'completed' : 'pending',
            'estimated_arrival' => $order['dropx_estimated_delivery_time']
        ]
    ];
    
    $driverLocation = null;
    if ($order['driver_id']) {
        $stmt = $conn->prepare("
            SELECT current_latitude, current_longitude
            FROM drivers
            WHERE id = ?
        ");
        $stmt->execute([$order['driver_id']]);
        $driverLocation = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    $nextStop = null;
    foreach ($waypoints as $wp) {
        if ($wp['status'] === 'pending') {
            $nextStop = $wp;
            break;
        }
    }
    
    ResponseHandler::success([
        'driver_location' => $driverLocation ? [
            'latitude' => floatval($driverLocation['current_latitude']),
            'longitude' => floatval($driverLocation['current_longitude'])
        ] : null,
        'waypoints' => $waypoints,
        'next_stop' => $nextStop,
        'progress' => [
            'total' => count($waypoints),
            'completed' => count(array_filter($waypoints, function($wp) { 
                return $wp['status'] === 'completed'; 
            })),
            'percentage' => count($waypoints) > 0 
                ? (count(array_filter($waypoints, function($wp) { 
                    return $wp['status'] === 'completed'; 
                  })) / count($waypoints)) * 100
                : 0
        ]
    ]);
}

function handleTrackingSummary($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT 
            o.id,
            o.order_number,
            o.status,
            o.created_at,
            m.name as merchant_name,
            d.name as driver_name,
            d.current_latitude,
            d.current_longitude
        FROM orders o
        LEFT JOIN merchants m ON o.merchant_id = m.id
        LEFT JOIN drivers d ON o.driver_id = d.id
        WHERE o.user_id = ? 
        AND o.status IN ('confirmed','preparing','ready','picked_up','on_the_way','arrived')
        ORDER BY o.updated_at DESC
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $activeOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $counts = [
        'total_active' => count($activeOrders),
        'preparing' => 0,
        'picked_up' => 0,
        'on_the_way' => 0,
        'arrived' => 0
    ];
    
    $formattedOrders = [];
    foreach ($activeOrders as $order) {
        if (isset($counts[$order['status']])) {
            $counts[$order['status']]++;
        }
        
        $statusInfo = getStatusDisplayInfo($order['status'], null);
        
        $formattedOrders[] = [
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'tracking_id' => $order['order_number'],
            'tracking_url' => "https://dropx.app/track/" . $order['order_number'],
            'status' => $order['status'],
            'display_status' => $statusInfo['label'],
            'progress' => $statusInfo['progress'],
            'merchant_name' => $order['merchant_name'],
            'driver_name' => $order['driver_name'],
            'driver_location' => ($order['current_latitude'] && $order['current_longitude']) ? [
                'lat' => floatval($order['current_latitude']),
                'lng' => floatval($order['current_longitude'])
            ] : null,
            'created_at' => $order['created_at']
        ];
    }
    
    ResponseHandler::success([
        'summary' => $counts,
        'active_orders' => $formattedOrders
    ]);
}

function handleGetTrackableOrders($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT 
            o.id,
            o.order_number,
            o.status,
            o.created_at,
            o.updated_at,
            m.name as merchant_name
        FROM orders o
        LEFT JOIN merchants m ON o.merchant_id = m.id
        WHERE o.user_id = ? 
        AND o.status IN ('confirmed','preparing','ready','picked_up','on_the_way','arrived')
        ORDER BY o.updated_at DESC
    ");
    $stmt->execute([$userId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedOrders = array_map(function($order) {
        $statusInfo = getStatusDisplayInfo($order['status'], null);
        
        return [
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'tracking_id' => $order['order_number'],
            'tracking_url' => "https://dropx.app/track/" . $order['order_number'],
            'status' => $order['status'],
            'display_status' => $statusInfo['label'],
            'progress' => $statusInfo['progress'],
            'merchant_name' => $order['merchant_name'],
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at']
        ];
    }, $orders);
    
    ResponseHandler::success([
        'trackable_orders' => $formattedOrders,
        'count' => count($formattedOrders)
    ]);
}

function handleDriverContact($conn, $input, $userId) {
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
            // REMOVED: rating
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
            'id' => $driver['id'],
            'name' => $driver['name'],
            'phone' => $driver['phone'],
            'whatsapp' => $driver['whatsapp_number'],
            'image' => formatImageUrl($driver['image_url']),
            'vehicle' => $driver['vehicle_type'],
            'vehicle_number' => $driver['vehicle_number']
            // REMOVED: 'rating' => floatval($driver['rating'] ?? 0)
        ]
    ]);
}

function handleShareTracking($conn, $input, $userId) {
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
}

/*********************************
 * HELPER FUNCTIONS
 *********************************/

function findUserOrder($conn, $identifier, $userId) {
    $sql = "SELECT 
                o.*,
                m.name as merchant_name,
                m.address as merchant_address,
                m.phone as merchant_phone,
                m.latitude as merchant_lat,
                m.longitude as merchant_lng
            FROM orders o
            LEFT JOIN merchants m ON o.merchant_id = m.id
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
    
    if (!$order) {
        $sql = "SELECT 
                    o.*,
                    m.name as merchant_name,
                    m.address as merchant_address,
                    m.phone as merchant_phone
                FROM orders o
                LEFT JOIN merchants m ON o.merchant_id = m.id
                LEFT JOIN order_tracking ot ON o.id = ot.order_id
                WHERE (ot.id = :identifier OR ot.order_id = :identifier2)
                AND o.user_id = :user_id
                LIMIT 1";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':identifier' => $identifier,
            ':identifier2' => $identifier,
            ':user_id' => $userId
        ]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    return $order;
}

function getOrderTimeline($conn, $orderId) {
    $timeline = [];
    
    $stmt = $conn->prepare("SELECT created_at, order_number FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $timeline[] = [
        'id' => 'order_placed',
        'status' => 'pending',
        'title' => 'Order Placed',
        'description' => "Order #{$order['order_number']} has been received",
        'timestamp' => $order['created_at'],
        'icon' => 'shopping_bag'
    ];
    
    $stmt = $conn->prepare("
        SELECT status, created_at as timestamp
        FROM order_tracking
        WHERE order_id = ?
        ORDER BY created_at ASC
    ");
    $stmt->execute([$orderId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($history as $event) {
        if ($event['status'] !== 'pending') {
            $timeline[] = [
                'id' => 'status_' . $event['status'],
                'status' => $event['status'],
                'title' => ORDER_STATUSES[$event['status']]['label'] ?? $event['status'],
                'description' => 'Status updated to ' . (ORDER_STATUSES[$event['status']]['label'] ?? $event['status']),
                'timestamp' => $event['timestamp'],
                'icon' => getStatusIcon($event['status'])
            ];
        }
    }
    
    return $timeline;
}

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
            updated_at as location_updated_at
            // REMOVED: rating
        FROM drivers
        WHERE id = ?
    ");
    $stmt->execute([$driverId]);
    $driver = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$driver) {
        return null;
    }
    
    return [
        'id' => $driver['id'],
        'name' => $driver['name'],
        'phone' => $driver['phone'],
        'image' => formatImageUrl($driver['image_url']),
        'vehicle' => $driver['vehicle_type'],
        'vehicle_number' => $driver['vehicle_number'],
        'location' => ($driver['current_latitude'] && $driver['current_longitude']) ? [
            'latitude' => floatval($driver['current_latitude']),
            'longitude' => floatval($driver['current_longitude']),
            'last_updated' => $driver['location_updated_at']
        ] : null
        // REMOVED: 'rating' => floatval($driver['rating'] ?? 0)
    ];
}

function calculateTrackingProgress($order) {
    if ($order['status'] === 'delivered') {
        return 100;
    }
    
    if ($order['status'] === 'cancelled') {
        return 0;
    }
    
    return (ORDER_STATUSES[$order['status']]['progress'] ?? 0.1) * 100;
}

function getStatusDisplayInfo($orderStatus, $dropxStatus = null) {
    if ($dropxStatus && in_array($orderStatus, TRACKABLE_STATUSES)) {
        return [
            'label' => DROPX_STATUSES[$dropxStatus]['label'] ?? $orderStatus,
            'progress' => (DROPX_STATUSES[$dropxStatus]['progress'] ?? 0.1) * 100,
            'color' => '#2196F3'
        ];
    }
    
    return [
        'label' => ORDER_STATUSES[$orderStatus]['label'] ?? $orderStatus,
        'progress' => (ORDER_STATUSES[$orderStatus]['progress'] ?? 0.1) * 100,
        'color' => ORDER_STATUSES[$orderStatus]['color'] ?? '#999999'
    ];
}

function calculateETA($conn, $orderId, $driver) {
    $stmt = $conn->prepare("
        SELECT dropx_estimated_delivery_time
        FROM orders o
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result['dropx_estimated_delivery_time'] ?? null;
}

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

function formatImageUrl($path) {
    global $baseUrl;
    
    if (empty($path)) {
        return '';
    }
    
    if (strpos($path, 'http') === 0) {
        return $path;
    }
    
    return rtrim($baseUrl, '/') . '/uploads/' . ltrim($path, '/');
}
?>