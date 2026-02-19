<?php
/*********************************
 * API CONFIGURATION
 *********************************/

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/tracking-error.log');

// Mobile-optimized headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Session-Token, X-App-Version, X-Platform");
header("Access-Control-Max-Age: 3600");
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Handle preflight requests
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
    // Get request data
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? $_GET['action'] ?? '';
    
    if (empty($action)) {
        ResponseHandler::error('Action parameter required', 400, 'MISSING_ACTION');
    }

    // Initialize database
    $db = new Database();
    $conn = $db->getConnection();
    
    // Route to appropriate handler
    switch ($action) {
        // Track an order (public - no auth required)
        case 'track':
            handleTrackOrder($conn, $input);
            break;
            
        // Get driver location (public tracking)
        case 'driver_location':
            handleDriverLocation($conn, $input);
            break;
            
        // Get real-time updates (public tracking)
        case 'realtime':
            handleRealTimeUpdates($conn, $input);
            break;
            
        // Get route information (public tracking)
        case 'route':
            handleRouteInfo($conn, $input);
            break;
            
        // Get tracking summary (requires auth)
        case 'summary':
            $userId = checkAuthentication();
            if (!$userId) {
                ResponseHandler::error('Authentication required', 401, 'AUTH_REQUIRED');
            }
            handleTrackingSummary($conn, $userId);
            break;
            
        // Get all trackable orders for user (requires auth)
        case 'get_trackable':
            $userId = checkAuthentication();
            if (!$userId) {
                ResponseHandler::error('Authentication required', 401, 'AUTH_REQUIRED');
            }
            handleGetTrackableOrders($conn, $userId);
            break;
            
        // Get driver contact (requires auth)
        case 'driver_contact':
            $userId = checkAuthentication();
            if (!$userId) {
                ResponseHandler::error('Authentication required', 401, 'AUTH_REQUIRED');
            }
            handleDriverContact($conn, $input, $userId);
            break;
            
        // Share tracking link (requires auth)
        case 'share':
            $userId = checkAuthentication();
            if (!$userId) {
                ResponseHandler::error('Authentication required', 401, 'AUTH_REQUIRED');
            }
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
 * TRACK ORDER HANDLER
 * Public - no auth required
 *********************************/
function handleTrackOrder($conn, $input) {
    $identifier = $input['tracking_id'] ?? $input['order_id'] ?? $input['order_number'] ?? '';
    
    if (empty($identifier)) {
        ResponseHandler::error('Tracking ID or Order ID required', 400, 'MISSING_ID');
    }

    // Try to find the order by various identifiers
    $order = findTrackableOrder($conn, $identifier);
    
    if (!$order) {
        ResponseHandler::error('Order not found', 404, 'ORDER_NOT_FOUND');
    }

    // Get all orders in the same group if this is a multi-merchant order
    $groupOrders = [];
    $pickupProgress = null;
    $driver = null;
    $timeline = [];
    $waypoints = [];

    if ($order['order_group_id']) {
        // Get all orders in the group
        $groupOrders = getGroupOrdersForTracking($conn, $order['order_group_id']);
        
        // Get pickup progress
        $pickupProgress = getPickupProgress($conn, $order['order_group_id']);
        
        // Build timeline from group tracking
        $timeline = getGroupTimeline($conn, $order['order_group_id']);
        
        // Get route waypoints
        $waypoints = getGroupWaypoints($conn, $order['order_group_id']);
    } else {
        // Single order - build timeline from order tracking
        $timeline = getOrderTimeline($conn, $order['id']);
    }

    // Get driver information if assigned
    if ($order['driver_id']) {
        $driver = getDriverTrackingInfo($conn, $order['driver_id']);
    }

    // Get estimated delivery times
    $estimatedDelivery = $order['dropx_estimated_delivery_time'] ?? $order['estimated_delivery'];
    
    // Calculate progress percentage
    $progress = calculateTrackingProgress($order, $pickupProgress);
    
    // Get status display info
    $statusInfo = getStatusDisplayInfo($order['status'], $order['dropx_pickup_status']);

    // Build response
    $response = [
        'tracking' => [
            'id' => $order['dropx_tracking_id'] ?? $order['order_number'],
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
            'is_multi_merchant' => !empty($order['order_group_id']),
            'merchant_count' => $order['merchant_count'] ?? 1,
            'pickup_progress' => $pickupProgress
        ],
        'delivery' => [
            'address' => $order['delivery_address'],
            'instructions' => $order['special_instructions']
        ],
        'merchant' => [
            'id' => $order['merchant_id'],
            'name' => $order['merchant_name'],
            'address' => $order['merchant_address'],
            'phone' => maskPhoneNumber($order['merchant_phone']),
            'image' => formatImageUrl($order['merchant_image'])
        ],
        'driver' => $driver,
        'timeline' => $timeline,
        'waypoints' => $waypoints
    ];

    // Add group merchants if multi-merchant
    if (!empty($groupOrders)) {
        $response['merchants'] = array_map(function($gOrder) {
            return [
                'id' => $gOrder['merchant_id'],
                'name' => $gOrder['merchant_name'],
                'address' => $gOrder['merchant_address'],
                'status' => $gOrder['status'],
                'pickup_status' => $gOrder['pickup_status'],
                'estimated_pickup' => $gOrder['estimated_pickup']
            ];
        }, $groupOrders);
    }

    ResponseHandler::success($response, 'Tracking information retrieved');
}

/*********************************
 * DRIVER LOCATION HANDLER
 * Public - no auth required
 *********************************/
function handleDriverLocation($conn, $input) {
    $identifier = $input['tracking_id'] ?? $input['order_id'] ?? '';
    
    if (empty($identifier)) {
        ResponseHandler::error('Tracking ID or Order ID required', 400, 'MISSING_ID');
    }
    
    // Find the order first
    $order = findTrackableOrder($conn, $identifier);
    
    if (!$order) {
        ResponseHandler::error('Order not found', 404, 'ORDER_NOT_FOUND');
    }
    
    if (!$order['driver_id']) {
        ResponseHandler::success([
            'has_driver' => false,
            'message' => 'No driver assigned yet',
            'estimated_pickup' => $order['dropx_estimated_pickup_time']
        ]);
    }
    
    // Get real-time driver location
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
    
    // Calculate ETA if possible
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

/*********************************
 * REAL-TIME UPDATES HANDLER
 * Public - no auth required
 * Returns only changes since last update
 *********************************/
function handleRealTimeUpdates($conn, $input) {
    $identifier = $input['tracking_id'] ?? $input['order_id'] ?? '';
    $lastUpdate = $input['last_update'] ?? null;
    
    if (empty($identifier)) {
        ResponseHandler::error('Tracking ID or Order ID required', 400, 'MISSING_ID');
    }
    
    // Find the order
    $order = findTrackableOrder($conn, $identifier);
    
    if (!$order) {
        ResponseHandler::error('Order not found', 404, 'ORDER_NOT_FOUND');
    }
    
    $updates = [];
    $hasUpdates = false;
    $currentTime = date('Y-m-d H:i:s');
    
    // Check for order status update
    if (!$lastUpdate || strtotime($order['updated_at']) > strtotime($lastUpdate)) {
        $statusInfo = getStatusDisplayInfo($order['status'], $order['dropx_pickup_status']);
        
        $updates['order'] = [
            'status' => $order['status'],
            'dropx_status' => $order['dropx_pickup_status'],
            'display_status' => $statusInfo['label'],
            'progress' => calculateTrackingProgress($order, null),
            'updated_at' => $order['updated_at']
        ];
        $hasUpdates = true;
    }
    
    // Check for driver location update
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
    
    // Check for group tracking updates
    if ($order['order_group_id']) {
        $stmt = $conn->prepare("
            SELECT status, message, location_address, created_at
            FROM group_tracking
            WHERE order_group_id = ?
            AND (? IS NULL OR created_at > ?)
            ORDER BY created_at DESC
        ");
        $stmt->execute([$order['order_group_id'], $lastUpdate, $lastUpdate]);
        $trackingUpdates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($trackingUpdates)) {
            $updates['tracking_events'] = array_map(function($event) {
                return [
                    'status' => $event['status'],
                    'message' => $event['message'],
                    'location' => $event['location_address'],
                    'timestamp' => $event['created_at']
                ];
            }, $trackingUpdates);
            $hasUpdates = true;
        }
    }
    
    // Check for pickup status updates in multi-merchant orders
    if ($order['order_group_id']) {
        $stmt = $conn->prepare("
            SELECT gp.merchant_id, m.name, gp.pickup_status, gp.actual_pickup_time
            FROM group_pickups gp
            JOIN merchants m ON gp.merchant_id = m.id
            WHERE gp.order_group_id = ?
            AND (? IS NULL OR gp.updated_at > ?)
        ");
        $stmt->execute([$order['order_group_id'], $lastUpdate, $lastUpdate]);
        $pickupUpdates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($pickupUpdates)) {
            $updates['pickup_updates'] = $pickupUpdates;
            $hasUpdates = true;
        }
    }
    
    ResponseHandler::success([
        'has_updates' => $hasUpdates,
        'updates' => $updates,
        'server_time' => $currentTime
    ]);
}

/*********************************
 * ROUTE INFORMATION HANDLER
 * Public - no auth required
 *********************************/
function handleRouteInfo($conn, $input) {
    $identifier = $input['tracking_id'] ?? $input['order_id'] ?? '';
    
    if (empty($identifier)) {
        ResponseHandler::error('Tracking ID or Order ID required', 400, 'MISSING_ID');
    }
    
    // Find the order
    $order = findTrackableOrder($conn, $identifier);
    
    if (!$order) {
        ResponseHandler::error('Order not found', 404, 'ORDER_NOT_FOUND');
    }
    
    $waypoints = [];
    
    if ($order['order_group_id']) {
        // Multi-merchant route
        $waypoints = getGroupWaypoints($conn, $order['order_group_id'], true);
    } else {
        // Single order route - just merchant and delivery
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
                    'latitude' => 0, // Would need delivery coordinates
                    'longitude' => 0
                ],
                'status' => $order['status'] === 'delivered' ? 'completed' : 'pending',
                'estimated_arrival' => $order['dropx_estimated_delivery_time']
            ]
        ];
    }
    
    // Get driver current location
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
    
    // Calculate next stop
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
            'completed' => count(array_filter($waypoints, fn($wp) => $wp['status'] === 'completed')),
            'percentage' => count($waypoints) > 0 
                ? (count(array_filter($waypoints, fn($wp) => $wp['status'] === 'completed')) / count($waypoints)) * 100
                : 0
        ]
    ]);
}

/*********************************
 * TRACKING SUMMARY HANDLER
 * Requires authentication
 *********************************/
function handleTrackingSummary($conn, $userId) {
    // Get active tracking orders
    $stmt = $conn->prepare("
        SELECT 
            o.id,
            o.order_number,
            o.status,
            o.created_at,
            o.order_group_id,
            og.dropx_tracking_id,
            og.dropx_pickup_status,
            og.dropx_estimated_delivery_time,
            m.name as merchant_name,
            m.image_url as merchant_image,
            d.name as driver_name,
            d.current_latitude,
            d.current_longitude,
            COUNT(CASE WHEN o2.id IS NOT NULL THEN 1 END) as group_order_count
        FROM orders o
        LEFT JOIN merchants m ON o.merchant_id = m.id
        LEFT JOIN drivers d ON o.driver_id = d.id
        LEFT JOIN order_groups og ON o.order_group_id = og.id
        LEFT JOIN orders o2 ON o.order_group_id = o2.order_group_id AND o2.id != o.id
        WHERE o.user_id = ? 
        AND o.status IN ('confirmed','preparing','ready','picked_up','on_the_way','arrived')
        GROUP BY o.id
        ORDER BY o.updated_at DESC
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $activeOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count by status
    $counts = [
        'total_active' => count($activeOrders),
        'preparing' => 0,
        'picked_up' => 0,
        'on_the_way' => 0,
        'arrived' => 0
    ];
    
    $formattedOrders = [];
    foreach ($activeOrders as $order) {
        // Update counts
        if (isset($counts[$order['status']])) {
            $counts[$order['status']]++;
        }
        
        $isMultiMerchant = $order['group_order_count'] > 0;
        $statusInfo = getStatusDisplayInfo($order['status'], $order['dropx_pickup_status']);
        
        $formattedOrders[] = [
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'tracking_id' => $order['dropx_tracking_id'] ?? $order['order_number'],
            'tracking_url' => "https://dropx.app/track/" . ($order['dropx_tracking_id'] ?? $order['order_number']),
            'status' => $order['status'],
            'display_status' => $statusInfo['label'],
            'progress' => $statusInfo['progress'],
            'merchant_name' => $order['merchant_name'],
            'merchant_image' => formatImageUrl($order['merchant_image']),
            'driver_name' => $order['driver_name'],
            'driver_location' => ($order['current_latitude'] && $order['current_longitude']) ? [
                'lat' => floatval($order['current_latitude']),
                'lng' => floatval($order['current_longitude'])
            ] : null,
            'estimated_delivery' => $order['dropx_estimated_delivery_time'],
            'is_multi_merchant' => $isMultiMerchant,
            'created_at' => $order['created_at']
        ];
    }
    
    ResponseHandler::success([
        'summary' => $counts,
        'active_orders' => $formattedOrders
    ]);
}

/*********************************
 * GET TRACKABLE ORDERS HANDLER
 * Requires authentication
 *********************************/
function handleGetTrackableOrders($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT 
            o.id,
            o.order_number,
            o.status,
            o.created_at,
            o.updated_at,
            o.order_group_id,
            og.dropx_tracking_id,
            og.dropx_pickup_status,
            og.dropx_estimated_delivery_time,
            m.name as merchant_name,
            m.image_url as merchant_image,
            COUNT(CASE WHEN o2.id IS NOT NULL THEN 1 END) as group_order_count
        FROM orders o
        LEFT JOIN merchants m ON o.merchant_id = m.id
        LEFT JOIN order_groups og ON o.order_group_id = og.id
        LEFT JOIN orders o2 ON o.order_group_id = o2.order_group_id AND o2.id != o.id
        WHERE o.user_id = ? 
        AND o.status IN ('confirmed','preparing','ready','picked_up','on_the_way','arrived')
        GROUP BY o.id
        ORDER BY o.updated_at DESC
    ");
    $stmt->execute([$userId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedOrders = array_map(function($order) {
        $statusInfo = getStatusDisplayInfo($order['status'], $order['dropx_pickup_status']);
        
        return [
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'tracking_id' => $order['dropx_tracking_id'] ?? $order['order_number'],
            'tracking_url' => "https://dropx.app/track/" . ($order['dropx_tracking_id'] ?? $order['order_number']),
            'status' => $order['status'],
            'display_status' => $statusInfo['label'],
            'progress' => $statusInfo['progress'],
            'merchant_name' => $order['merchant_name'],
            'merchant_image' => formatImageUrl($order['merchant_image']),
            'estimated_delivery' => $order['dropx_estimated_delivery_time'],
            'is_multi_merchant' => $order['group_order_count'] > 0,
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at']
        ];
    }, $orders);
    
    ResponseHandler::success([
        'trackable_orders' => $formattedOrders,
        'count' => count($formattedOrders)
    ]);
}

/*********************************
 * DRIVER CONTACT HANDLER
 * Requires authentication
 *********************************/
function handleDriverContact($conn, $input, $userId) {
    $orderId = $input['order_id'] ?? '';
    
    if (empty($orderId)) {
        ResponseHandler::error('Order ID required', 400, 'MISSING_ORDER_ID');
    }
    
    // Verify order belongs to user and get driver
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
    
    // Get driver contact info
    $stmt = $conn->prepare("
        SELECT 
            id,
            name,
            phone,
            whatsapp_number,
            image_url,
            vehicle_type,
            vehicle_number,
            rating
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
            'phone' => $driver['phone'], // Full phone for authenticated users
            'whatsapp' => $driver['whatsapp_number'],
            'image' => formatImageUrl($driver['image_url']),
            'vehicle' => $driver['vehicle_type'],
            'vehicle_number' => $driver['vehicle_number'],
            'rating' => floatval($driver['rating'] ?? 0)
        ]
    ]);
}

/*********************************
 * SHARE TRACKING HANDLER
 * Requires authentication
 *********************************/
function handleShareTracking($conn, $input, $userId) {
    $orderId = $input['order_id'] ?? '';
    
    if (empty($orderId)) {
        ResponseHandler::error('Order ID required', 400, 'MISSING_ORDER_ID');
    }
    
    // Get order details
    $stmt = $conn->prepare("
        SELECT 
            o.order_number,
            o.total_amount,
            m.name as merchant_name,
            og.dropx_tracking_id
        FROM orders o
        LEFT JOIN merchants m ON o.merchant_id = m.id
        LEFT JOIN order_groups og ON o.order_group_id = og.id
        WHERE o.id = ? AND o.user_id = ?
    ");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        ResponseHandler::error('Order not found', 404, 'ORDER_NOT_FOUND');
    }
    
    $trackingId = $order['dropx_tracking_id'] ?? $order['order_number'];
    $trackingUrl = "https://dropx.app/track/$trackingId";
    
    // Generate share message
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

/**
 * Find trackable order by various identifiers
 */
function findTrackableOrder($conn, $identifier) {
    // Try to find by dropx_tracking_id first (from order_groups)
    $sql = "SELECT 
                o.*,
                m.name as merchant_name,
                m.address as merchant_address,
                m.phone as merchant_phone,
                m.image_url as merchant_image,
                m.latitude as merchant_lat,
                m.longitude as merchant_lng,
                og.dropx_tracking_id,
                og.dropx_pickup_status,
                og.dropx_estimated_pickup_time,
                og.dropx_estimated_delivery_time,
                og.current_location_lat,
                og.current_location_lng,
                (
                    SELECT COUNT(*) 
                    FROM orders o2 
                    WHERE o2.order_group_id = o.order_group_id
                ) as merchant_count
            FROM orders o
            LEFT JOIN merchants m ON o.merchant_id = m.id
            LEFT JOIN order_groups og ON o.order_group_id = og.id
            WHERE o.order_number = :identifier 
               OR o.id = :identifier 
               OR og.dropx_tracking_id = :identifier
            LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([':identifier' => $identifier]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get all orders in a group for tracking
 */
function getGroupOrdersForTracking($conn, $groupId) {
    $stmt = $conn->prepare("
        SELECT 
            o.id,
            o.order_number,
            o.status,
            o.merchant_id,
            m.name as merchant_name,
            m.address as merchant_address,
            gp.pickup_status,
            gp.actual_pickup_time,
            gp.estimated_pickup_time
        FROM orders o
        JOIN merchants m ON o.merchant_id = m.id
        LEFT JOIN group_pickups gp ON gp.order_group_id = o.order_group_id AND gp.merchant_id = m.id
        WHERE o.order_group_id = ?
        ORDER BY gp.pickup_order
    ");
    $stmt->execute([$groupId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get pickup progress for group order
 */
function getPickupProgress($conn, $groupId) {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN pickup_status = 'picked_up' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN pickup_status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN pickup_status = 'in_progress' THEN 1 ELSE 0 END) as in_progress
        FROM group_pickups
        WHERE order_group_id = ?
    ");
    $stmt->execute([$groupId]);
    $progress = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $total = intval($progress['total']);
    $completed = intval($progress['completed']);
    
    return [
        'total' => $total,
        'completed' => $completed,
        'remaining' => $total - $completed,
        'percentage' => $total > 0 ? round(($completed / $total) * 100) : 0,
        'details' => $progress
    ];
}

/**
 * Get group timeline from tracking history
 */
function getGroupTimeline($conn, $groupId) {
    $stmt = $conn->prepare("
        SELECT 
            status,
            message,
            location_address,
            created_at as timestamp
        FROM group_tracking
        WHERE order_group_id = ?
        ORDER BY created_at ASC
    ");
    $stmt->execute([$groupId]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $timeline = [];
    foreach ($events as $event) {
        $timeline[] = [
            'id' => 'event_' . count($timeline),
            'status' => $event['status'],
            'title' => DROPX_STATUSES[$event['status']]['label'] ?? $event['status'],
            'description' => $event['message'],
            'location' => $event['location_address'],
            'timestamp' => $event['timestamp'],
            'icon' => getStatusIcon($event['status'])
        ];
    }
    
    return $timeline;
}

/**
 * Get single order timeline
 */
function getOrderTimeline($conn, $orderId) {
    $timeline = [];
    
    // Get order creation
    $stmt = $conn->prepare("SELECT created_at FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $timeline[] = [
        'id' => 'order_placed',
        'status' => 'pending',
        'title' => 'Order Placed',
        'description' => 'Your order has been received',
        'timestamp' => $order['created_at'],
        'icon' => 'shopping_bag'
    ];
    
    // Get status history
    $stmt = $conn->prepare("
        SELECT new_status, reason, created_at as timestamp
        FROM order_status_history
        WHERE order_id = ?
        ORDER BY created_at ASC
    ");
    $stmt->execute([$orderId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($history as $event) {
        if ($event['new_status'] !== 'pending') { // Skip pending as we already added it
            $timeline[] = [
                'id' => 'status_' . $event['new_status'],
                'status' => $event['new_status'],
                'title' => ORDER_STATUSES[$event['new_status']]['label'] ?? $event['new_status'],
                'description' => $event['reason'] ?? 'Status updated',
                'timestamp' => $event['timestamp'],
                'icon' => getStatusIcon($event['new_status'])
            ];
        }
    }
    
    return $timeline;
}

/**
 * Get group route waypoints
 */
function getGroupWaypoints($conn, $groupId, $includeDetails = false) {
    $waypoints = [];
    
    // Get pickup points in order
    $stmt = $conn->prepare("
        SELECT 
            gp.pickup_order as sequence,
            'pickup' as type,
            m.name,
            m.address,
            m.latitude,
            m.longitude,
            gp.pickup_status as status,
            gp.estimated_pickup_time as estimated_arrival,
            gp.actual_pickup_time as actual_arrival
        FROM group_pickups gp
        JOIN merchants m ON gp.merchant_id = m.id
        WHERE gp.order_group_id = ?
        ORDER BY gp.pickup_order
    ");
    $stmt->execute([$groupId]);
    $pickups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($pickups as $pickup) {
        $waypoints[] = [
            'sequence' => intval($pickup['sequence']),
            'type' => 'pickup',
            'name' => $pickup['name'],
            'address' => $pickup['address'],
            'location' => [
                'latitude' => floatval($pickup['latitude'] ?? 0),
                'longitude' => floatval($pickup['longitude'] ?? 0)
            ],
            'status' => $pickup['status'] === 'picked_up' ? 'completed' : 
                       ($pickup['status'] === 'in_progress' ? 'in_progress' : 'pending'),
            'estimated_arrival' => $pickup['estimated_arrival'],
            'actual_arrival' => $pickup['actual_arrival']
        ];
    }
    
    // Get delivery point from any order in the group
    $stmt = $conn->prepare("
        SELECT delivery_address
        FROM orders
        WHERE order_group_id = ?
        LIMIT 1
    ");
    $stmt->execute([$groupId]);
    $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($delivery) {
        $waypoints[] = [
            'sequence' => count($waypoints) + 1,
            'type' => 'dropoff',
            'name' => 'Your Location',
            'address' => $delivery['delivery_address'],
            'location' => [
                'latitude' => 0,
                'longitude' => 0
            ],
            'status' => 'pending',
            'estimated_arrival' => null,
            'actual_arrival' => null
        ];
    }
    
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
            rating
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
        'phone' => maskPhoneNumber($driver['phone']),
        'image' => formatImageUrl($driver['image_url']),
        'vehicle' => $driver['vehicle_type'],
        'vehicle_number' => $driver['vehicle_number'],
        'location' => ($driver['current_latitude'] && $driver['current_longitude']) ? [
            'latitude' => floatval($driver['current_latitude']),
            'longitude' => floatval($driver['current_longitude']),
            'last_updated' => $driver['location_updated_at']
        ] : null,
        'rating' => floatval($driver['rating'] ?? 0)
    ];
}

/**
 * Calculate tracking progress percentage
 */
function calculateTrackingProgress($order, $pickupProgress = null) {
    if ($order['status'] === 'delivered') {
        return 100;
    }
    
    if ($order['status'] === 'cancelled') {
        return 0;
    }
    
    // If multi-merchant, use pickup progress
    if ($order['order_group_id'] && $pickupProgress) {
        return $pickupProgress['percentage'];
    }
    
    // Otherwise use status-based progress
    return (ORDER_STATUSES[$order['status']]['progress'] ?? 0.1) * 100;
}

/**
 * Get status display information
 */
function getStatusDisplayInfo($orderStatus, $dropxStatus = null) {
    // If we have DropX status and order is in tracking phase
    if ($dropxStatus && in_array($orderStatus, TRACKABLE_STATUSES)) {
        return [
            'label' => DROPX_STATUSES[$dropxStatus]['label'] ?? $orderStatus,
            'progress' => (DROPX_STATUSES[$dropxStatus]['progress'] ?? 0.1) * 100,
            'color' => '#2196F3' // Default blue for DropX
        ];
    }
    
    // Otherwise use order status
    return [
        'label' => ORDER_STATUSES[$orderStatus]['label'] ?? $orderStatus,
        'progress' => (ORDER_STATUSES[$orderStatus]['progress'] ?? 0.1) * 100,
        'color' => ORDER_STATUSES[$orderStatus]['color'] ?? '#999999'
    ];
}

/**
 * Calculate ETA based on driver location and route
 */
function calculateETA($conn, $orderId, $driver) {
    // This would use Google Maps Distance Matrix API in production
    // For now, return estimated delivery time from database
    $stmt = $conn->prepare("
        SELECT dropx_estimated_delivery_time
        FROM orders o
        LEFT JOIN order_groups og ON o.order_group_id = og.id
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result['dropx_estimated_delivery_time'] ?? null;
}

/**
 * Get icon for status
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
 * Format image URL
 */
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

/**
 * Mask phone number for privacy in public tracking
 */
function maskPhoneNumber($phone) {
    if (empty($phone) || strlen($phone) < 8) return $phone;
    return substr($phone, 0, 4) . '****' . substr($phone, -4);
}
?>