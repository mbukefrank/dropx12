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
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Session-Token, X-App-Version, X-Platform, X-User-ID");
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
    // Check session token from header
    $sessionToken = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? $_GET['session_token'] ?? null;
    
    if ($sessionToken) {
        session_id($sessionToken);
        session_start();
    }
    
    // Check session
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['logged_in'])) {
        return $_SESSION['user_id'];
    }
    
    // Check user ID header (fallback for API calls)
    $userId = $_SERVER['HTTP_X_USER_ID'] ?? null;
    if ($userId) {
        return $userId;
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
    
    error_log("Processing action: $action for user: $userId");
    
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
            
        case 'get_order_details':
            handleGetOrderDetails($conn, $input, $userId);
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
    ResponseHandler::error('Server error occurred: ' . $e->getMessage(), 500, 'SERVER_ERROR');
}

/*********************************
 * HANDLER FUNCTIONS
 *********************************/

/**
 * Handle track order request
 * Returns complete tracking information for an order
 */
function handleTrackOrder($conn, $input, $userId) {
    $identifier = $input['tracking_id'] ?? $input['order_id'] ?? $input['order_number'] ?? '';
    
    if (empty($identifier)) {
        ResponseHandler::error('Tracking ID or Order ID required', 400, 'MISSING_ID');
    }

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

    // Calculate progress and status info
    $estimatedDelivery = $order['dropx_estimated_delivery_time'] ?? null;
    $progress = calculateTrackingProgress($order);
    $statusInfo = getStatusDisplayInfo($order['status'], $order['dropx_pickup_status']);

    // Check if order can be cancelled
    $cancellable = in_array($order['status'], ['pending', 'confirmed']);

    // Get merchant location
    $merchantLocation = null;
    if ($order['merchant_lat'] && $order['merchant_lng']) {
        $merchantLocation = [
            'lat' => floatval($order['merchant_lat']),
            'lng' => floatval($order['merchant_lng'])
        ];
    }

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
            'cancellable' => $cancellable
        ],
        'delivery' => [
            'address' => $order['delivery_address'],
            'instructions' => $order['special_instructions'] ?? '',
            'latitude' => 0, // You would get this from geocoding or a separate table
            'longitude' => 0
        ],
        'merchant' => [
            'id' => $order['merchant_id'],
            'name' => $order['merchant_name'] ?? 'Restaurant',
            'address' => $order['merchant_address'] ?? '',
            'phone' => $order['merchant_phone'] ?? '',
            'location' => $merchantLocation
        ],
        'driver' => $driver,
        'timeline' => $timeline,
        'items' => getOrderItems($conn, $order['id'])
    ];

    ResponseHandler::success($response, 'Tracking information retrieved');
}

/**
 * Handle get order details request
 * Returns complete order details with items
 */
function handleGetOrderDetails($conn, $input, $userId) {
    $orderId = $input['order_id'] ?? '';
    
    if (empty($orderId)) {
        ResponseHandler::error('Order ID required', 400, 'MISSING_ORDER_ID');
    }

    $order = findUserOrder($conn, $orderId, $userId);
    
    if (!$order) {
        ResponseHandler::error('Order not found or access denied', 404, 'ORDER_NOT_FOUND');
    }

    // Get order items with add-ons
    $items = getOrderItems($conn, $order['id']);

    // Get status history
    $statusHistory = getOrderStatusHistory($conn, $order['id']);

    // Calculate financial summary
    $subtotal = floatval($order['subtotal'] ?? 0);
    $deliveryFee = floatval($order['delivery_fee'] ?? 0);
    $tipAmount = floatval($order['tip_amount'] ?? 0);
    $discountAmount = floatval($order['discount_amount'] ?? 0);
    $totalAmount = floatval($order['total_amount'] ?? 0);

    $response = [
        'id' => intval($order['id']),
        'order_number' => $order['order_number'],
        'status' => $order['status'],
        'status_label' => ORDER_STATUSES[$order['status']]['label'] ?? $order['status'],
        'status_progress' => (ORDER_STATUSES[$order['status']]['progress'] ?? 0.1) * 100,
        
        'financial' => [
            'subtotal' => $subtotal,
            'delivery_fee' => $deliveryFee,
            'tip_amount' => $tipAmount,
            'discount_amount' => $discountAmount,
            'total_amount' => $totalAmount,
            'payment_method' => $order['payment_method'] ?? 'cash',
            'payment_status' => $order['payment_status'] ?? 'pending'
        ],
        
        'customer' => [
            'id' => intval($userId),
            'name' => $order['customer_name'] ?? 'Customer',
            'phone' => $order['customer_phone'] ?? '',
            'email' => $order['customer_email'] ?? ''
        ],
        
        'merchant' => [
            'id' => intval($order['merchant_id']),
            'name' => $order['merchant_name'] ?? 'Restaurant',
            'address' => $order['merchant_address'] ?? '',
            'phone' => $order['merchant_phone'] ?? '',
            'location' => [
                'lat' => $order['merchant_lat'] ? floatval($order['merchant_lat']) : null,
                'lng' => $order['merchant_lng'] ? floatval($order['merchant_lng']) : null
            ]
        ],
        
        'driver' => $order['driver_id'] ? [
            'id' => intval($order['driver_id']),
            'name' => $order['driver_name'] ?? '',
            'phone' => $order['driver_phone'] ?? ''
        ] : null,
        
        'delivery' => [
            'address' => $order['delivery_address'],
            'instructions' => $order['special_instructions'] ?? ''
        ],
        
        'items' => $items,
        'items_summary' => [
            'total_items' => array_sum(array_column($items, 'quantity')),
            'unique_items' => count($items),
            'total_addons' => array_sum(array_map(function($item) {
                return count($item['add_ons'] ?? []);
            }, $items))
        ],
        
        'timeline' => [
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at'],
            'status_history' => $statusHistory
        ],
        
        'actions' => [
            'can_cancel' => in_array($order['status'], ['pending', 'confirmed']),
            'can_reorder' => true
        ]
    ];

    ResponseHandler::success(['order' => $response], 'Order details retrieved');
}

/**
 * Handle driver location request
 * Returns real-time driver location information
 */
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

/**
 * Handle real-time updates request
 * Returns any updates since last_update timestamp
 */
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
    
    // Check for order status updates
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
        SELECT status, description, created_at
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

/**
 * Handle route information request
 * Returns waypoints and route progress
 */
function handleRouteInfo($conn, $input, $userId) {
    $identifier = $input['tracking_id'] ?? $input['order_id'] ?? '';
    
    if (empty($identifier)) {
        ResponseHandler::error('Tracking ID or Order ID required', 400, 'MISSING_ID');
    }
    
    $order = findUserOrder($conn, $identifier, $userId);
    
    if (!$order) {
        ResponseHandler::error('Order not found or access denied', 404, 'ORDER_NOT_FOUND');
    }
    
    $waypoints = [];
    
    // Add pickup waypoint
    if ($order['merchant_lat'] && $order['merchant_lng']) {
        $waypoints[] = [
            'sequence' => 1,
            'type' => 'pickup',
            'name' => $order['merchant_name'] ?? 'Pickup Location',
            'address' => $order['merchant_address'] ?? '',
            'location' => [
                'latitude' => floatval($order['merchant_lat']),
                'longitude' => floatval($order['merchant_lng'])
            ],
            'status' => in_array($order['status'], ['delivered', 'picked_up', 'on_the_way', 'arrived']) ? 'completed' : 'pending',
            'estimated_arrival' => $order['dropx_estimated_pickup_time'] ?? null
        ];
    }
    
    // Add dropoff waypoint
    $waypoints[] = [
        'sequence' => count($waypoints) + 1,
        'type' => 'dropoff',
        'name' => 'Delivery Location',
        'address' => $order['delivery_address'],
        'location' => [
            'latitude' => 0, // You would get this from geocoding
            'longitude' => 0
        ],
        'status' => $order['status'] === 'delivered' ? 'completed' : 'pending',
        'estimated_arrival' => $order['dropx_estimated_delivery_time'] ?? null
    ];
    
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
}

/**
 * Handle tracking summary request
 * Returns summary of all active orders for the user
 */
function handleTrackingSummary($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT 
            o.id,
            o.order_number,
            o.status,
            o.dropx_pickup_status,
            o.created_at,
            o.updated_at,
            m.name as merchant_name,
            m.image_url as merchant_image,
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
        
        $statusInfo = getStatusDisplayInfo($order['status'], $order['dropx_pickup_status']);
        
        $formattedOrders[] = [
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'tracking_id' => $order['order_number'],
            'tracking_url' => "https://dropx.app/track/" . $order['order_number'],
            'status' => $order['status'],
            'display_status' => $statusInfo['label'],
            'progress' => $statusInfo['progress'],
            'merchant' => [
                'name' => $order['merchant_name'],
                'image' => formatImageUrl($order['merchant_image'])
            ],
            'driver_name' => $order['driver_name'],
            'driver_location' => ($order['current_latitude'] && $order['current_longitude']) ? [
                'lat' => floatval($order['current_latitude']),
                'lng' => floatval($order['current_longitude'])
            ] : null,
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at']
        ];
    }
    
    ResponseHandler::success([
        'summary' => $counts,
        'active_orders' => $formattedOrders
    ]);
}

/**
 * Handle get trackable orders request
 * Returns list of orders that can be tracked
 */
function handleGetTrackableOrders($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT 
            o.id,
            o.order_number,
            o.status,
            o.dropx_pickup_status,
            o.created_at,
            o.updated_at,
            o.total_amount,
            o.delivery_address,
            m.id as merchant_id,
            m.name as merchant_name,
            m.address as merchant_address,
            m.image_url as merchant_image
        FROM orders o
        LEFT JOIN merchants m ON o.merchant_id = m.id
        WHERE o.user_id = ? 
        AND o.status IN ('confirmed','preparing','ready','picked_up','on_the_way','arrived','delivered')
        ORDER BY o.updated_at DESC
    ");
    $stmt->execute([$userId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedOrders = array_map(function($order) {
        $statusInfo = getStatusDisplayInfo($order['status'], $order['dropx_pickup_status']);
        
        // Get preview items
        $previewItems = getOrderItemsPreview($conn, $order['id']);
        
        return [
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'tracking_id' => $order['order_number'],
            'tracking_url' => "https://dropx.app/track/" . $order['order_number'],
            'status' => $order['status'],
            'display_status' => $statusInfo['label'],
            'progress' => $statusInfo['progress'],
            'merchant_name' => $order['merchant_name'] ?? 'Restaurant',
            'merchant_address' => $order['merchant_address'] ?? '',
            'merchant_image' => formatImageUrl($order['merchant_image']),
            'delivery_address' => $order['delivery_address'],
            'total_amount' => floatval($order['total_amount']),
            'items_preview' => $previewItems,
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at']
        ];
    }, $orders);
    
    ResponseHandler::success([
        'trackable_orders' => $formattedOrders,
        'count' => count($formattedOrders)
    ]);
}

/**
 * Handle driver contact request
 * Returns driver contact information
 */
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
        ]
    ]);
}

/**
 * Handle share tracking request
 * Returns shareable tracking information
 */
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
                d.name as driver_name,
                d.phone as driver_phone,
                d.vehicle_type,
                d.vehicle_number
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
            item_name,
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
        
        if (!empty($item['add_ons_json'])) {
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
        if (!empty($item['variant_data'])) {
            $variant = json_decode($item['variant_data'], true);
        }
        
        $formattedItems[] = [
            'id' => intval($item['id']),
            'name' => $item['item_name'],
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
            item_name,
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
            'name' => $item['item_name'],
            'quantity' => intval($item['quantity']),
            'has_addons' => $hasAddOns
        ];
    }
    
    return $preview;
}

/**
 * Get order status history
 */
function getOrderStatusHistory($conn, $orderId) {
    $stmt = $conn->prepare("
        SELECT 
            old_status,
            new_status,
            reason,
            notes,
            created_at as timestamp,
            changed_by
        FROM order_status_history
        WHERE order_id = ?
        ORDER BY created_at ASC
    ");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        'order_id' => $orderId,
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
                'order_id' => $orderId,
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
        'id' => $driver['id'],
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
    if ($order['dropx_pickup_status'] && in_array($order['status'], TRACKABLE_STATUSES)) {
        return (DROPX_STATUSES[$order['dropx_pickup_status']]['progress'] ?? 0.1) * 100;
    }
    
    return (ORDER_STATUSES[$order['status']]['progress'] ?? 0.1) * 100;
}

/**
 * Get display information for a status
 */
function getStatusDisplayInfo($orderStatus, $dropxStatus = null) {
    if ($dropxStatus && isset(DROPX_STATUSES[$dropxStatus]) && in_array($orderStatus, TRACKABLE_STATUSES)) {
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

/**
 * Calculate Estimated Time of Arrival
 */
function calculateETA($conn, $orderId, $driver) {
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
function formatImageUrl($path) {
    global $baseUrl;
    
    if (empty($path)) {
        return '';
    }
    
    if (strpos($path, 'http') === 0) {
        return $path;
    }
    
    // Remove leading slash if present
    $path = ltrim($path, '/');
    
    return rtrim($baseUrl, '/') . '/uploads/' . $path;
}
?>