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
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Session-Token, X-App-Version, X-Platform, X-Device-ID, X-Timestamp");
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
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? $_GET['action'] ?? '';

    $userId = checkAuthentication();
    if (!$userId) {
        ob_clean();
        ResponseHandler::error('Authentication required. Please login.', 401, 'AUTH_REQUIRED');
    }

    if ($method === 'GET') {
        if (!empty($action)) {
            handleGetActions($action, $input, $userId);
        } else {
            handleGetRequest($userId);
        }
    } elseif ($method === 'POST') {
        if (!empty($action)) {
            handlePostActions($action, $input, $userId);
        } else {
            handlePostRequest($userId);
        }
    } elseif ($method === 'PUT') {
        handlePutRequest($userId);
    } else {
        ob_clean();
        ResponseHandler::error('Method not allowed', 405);
    }

} catch (ErrorException $e) {
    ob_clean();
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500, 'SERVER_ERROR');
} catch (Exception $e) {
    ob_clean();
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500, 'SERVER_ERROR');
}

/*********************************
 * GET ACTIONS HANDLER
 *********************************/
function handleGetActions($action, $input, $userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        switch ($action) {
            case 'get_orders':
                handleGetOrders($conn, $input, $userId);
                break;
            case 'get_order':
                $orderId = $input['order_id'] ?? $_GET['order_id'] ?? '';
                if ($orderId) {
                    getOrderDetails($conn, $orderId, $userId);
                } else {
                    ob_clean();
                    ResponseHandler::error('Order ID required', 400);
                }
                break;
            case 'latest_active':
                getLatestActiveOrder($conn, $userId);
                break;
            case 'track_order':
                $orderId = $input['order_id'] ?? $_GET['order_id'] ?? '';
                if ($orderId) {
                    trackOrder($conn, $orderId, $userId);
                } else {
                    ob_clean();
                    ResponseHandler::error('Order ID required', 400);
                }
                break;
            default:
                ob_clean();
                ResponseHandler::error('Invalid action', 400);
        }
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Error in get action: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * POST ACTIONS HANDLER
 *********************************/
function handlePostActions($action, $input, $userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        switch ($action) {
            case 'create_order':
                createOrder($conn, $input, $userId);
                break;
            case 'create_from_cart':
                createOrderFromCart($conn, $input, $userId);
                break;
            case 'cancel_order':
                cancelOrder($conn, $input, $userId);
                break;
            case 'reorder':
                reorder($conn, $input, $userId);
                break;
            case 'rate_order':
                rateOrder($conn, $input, $userId);
                break;
            case 'quick_order':
                createQuickOrderFromItems($conn, $input, $userId);
                break;
            default:
                ob_clean();
                ResponseHandler::error('Invalid action', 400);
        }
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Error in post action: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET REQUESTS (Legacy)
 *********************************/
function handleGetRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        $orderId = $_GET['id'] ?? null;
        
        if ($orderId) {
            getOrderDetails($conn, $orderId, $userId);
        } else {
            getOrdersList($conn, $userId);
        }
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Error in get request: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * POST REQUESTS (Legacy)
 *********************************/
function handlePostRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $input = $_POST;
        }
        
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'create_order':
                createOrder($conn, $input, $userId);
                break;
            case 'create_from_cart':
                createOrderFromCart($conn, $input, $userId);
                break;
            case 'cancel_order':
                cancelOrder($conn, $input, $userId);
                break;
            case 'reorder':
                reorder($conn, $input, $userId);
                break;
            case 'latest_active':
                getLatestActiveOrder($conn, $userId);
                break;
            case 'track_order':
                $orderId = $input['order_id'] ?? '';
                if ($orderId) {
                    trackOrder($conn, $orderId, $userId);
                } else {
                    ob_clean();
                    ResponseHandler::error('Order ID required', 400);
                }
                break;
            default:
                ob_clean();
                ResponseHandler::error('Invalid action', 400);
        }
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Error in post request: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * PUT REQUESTS
 *********************************/
function handlePutRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            parse_str(file_get_contents('php://input'), $input);
        }
        
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'update_order':
                updateOrder($conn, $input, $userId);
                break;
            case 'update_delivery_address':
                updateDeliveryAddress($conn, $input, $userId);
                break;
            default:
                ob_clean();
                ResponseHandler::error('Invalid action', 400);
        }
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Error in put request: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET ORDERS HANDLER (Enhanced with Add-ons)
 *********************************/
function handleGetOrders($conn, $input, $userId) {
    try {
        $page = max(1, intval($input['page'] ?? $_GET['page'] ?? 1));
        $limit = min(50, max(1, intval($input['limit'] ?? $_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $status = $input['status'] ?? $_GET['status'] ?? 'all';
        $orderNumber = $input['order_number'] ?? $_GET['order_number'] ?? '';
        $startDate = $input['start_date'] ?? $_GET['start_date'] ?? '';
        $endDate = $input['end_date'] ?? $_GET['end_date'] ?? '';

        $whereConditions = ["o.user_id = :user_id"];
        $params = [':user_id' => $userId];

        if ($status !== 'all') {
            $whereConditions[] = "o.status = :status";
            $params[':status'] = $status;
        }

        if ($orderNumber) {
            $whereConditions[] = "o.order_number LIKE :order_number";
            $params[':order_number'] = "%$orderNumber%";
        }

        if ($startDate) {
            $whereConditions[] = "DATE(o.created_at) >= :start_date";
            $params[':start_date'] = $startDate;
        }

        if ($endDate) {
            $whereConditions[] = "DATE(o.created_at) <= :end_date";
            $params[':end_date'] = $endDate;
        }

        $whereClause = "WHERE " . implode(" AND ", $whereConditions);

        $countSql = "SELECT COUNT(DISTINCT o.id) as total FROM orders o $whereClause";
        $countStmt = $conn->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

        $sql = "SELECT 
                    o.id,
                    o.order_number,
                    o.status,
                    o.subtotal,
                    o.delivery_fee,
                    o.tip_amount,
                    o.discount_amount,
                    o.total_amount,
                    o.payment_method,
                    o.payment_status,
                    o.delivery_address,
                    o.special_instructions,
                    o.created_at,
                    o.updated_at,
                    o.merchant_id,
                    o.cancellation_reason,
                    m.name as merchant_name,
                    m.image_url as merchant_image,
                    (
                        SELECT COUNT(*) 
                        FROM order_items oi 
                        WHERE oi.order_id = o.id
                    ) as item_count,
                    (
                        SELECT SUM(quantity) 
                        FROM order_items oi 
                        WHERE oi.order_id = o.id
                    ) as total_items,
                    (
                        SELECT new_status 
                        FROM order_status_history 
                        WHERE order_id = o.id 
                        ORDER BY created_at DESC 
                        LIMIT 1
                    ) as current_status,
                    (
                        SELECT GROUP_CONCAT(
                            CONCAT(
                                oi.id, '||', 
                                oi.item_name, '||', 
                                oi.quantity, '||', 
                                oi.price, '||',
                                oi.total, '||',
                                COALESCE(oi.variant_id, 0), '||',
                                COALESCE(oi.add_ons_json, '')
                            )
                            ORDER BY oi.id SEPARATOR ';;'
                        )
                        FROM order_items oi 
                        WHERE oi.order_id = o.id
                    ) as items_preview
                FROM orders o
                LEFT JOIN merchants m ON o.merchant_id = m.id
                $whereClause
                ORDER BY o.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $conn->prepare($sql);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $userStmt = $conn->prepare(
            "SELECT full_name, phone FROM users WHERE id = :user_id"
        );
        $userStmt->execute([':user_id' => $userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        $formattedOrders = [];
        foreach ($orders as $order) {
            // Parse items preview to show add-ons count
            $itemsPreview = [];
            $addOnsCount = 0;
            
            if (!empty($order['items_preview'])) {
                $itemStrings = explode(';;', $order['items_preview']);
                foreach ($itemStrings as $index => $itemString) {
                    if ($index >= 3) break; // Limit preview to 3 items
                    
                    $parts = explode('||', $itemString);
                    if (count($parts) >= 5) {
                        $previewItem = [
                            'name' => $parts[1],
                            'quantity' => (int)$parts[2],
                            'price' => (float)$parts[3]
                        ];
                        
                        // Check for add-ons
                        if (isset($parts[6]) && !empty($parts[6])) {
                            $addOns = json_decode($parts[6], true);
                            if (!empty($addOns)) {
                                $addOnsCount += count($addOns);
                                $previewItem['has_addons'] = true;
                                $previewItem['addons_count'] = count($addOns);
                            }
                        }
                        
                        $itemsPreview[] = $previewItem;
                    }
                }
            }

            $formattedOrders[] = [
                'id' => (int)$order['id'],
                'order_number' => $order['order_number'],
                'status' => $order['status'],
                'current_status' => $order['current_status'] ?? $order['status'],
                'customer_name' => $user['full_name'] ?? 'Customer',
                'customer_phone' => $user['phone'] ?? '',
                'delivery_address' => $order['delivery_address'],
                'total_amount' => (float)$order['total_amount'],
                'delivery_fee' => (float)$order['delivery_fee'],
                'subtotal' => (float)$order['subtotal'],
                'tip_amount' => (float)($order['tip_amount'] ?? 0),
                'discount_amount' => (float)($order['discount_amount'] ?? 0),
                'item_count' => (int)$order['item_count'],
                'total_items' => (int)$order['total_items'],
                'addons_count' => $addOnsCount,
                'has_addons' => $addOnsCount > 0,
                'items_preview' => $itemsPreview,
                'created_at' => $order['created_at'],
                'payment_method' => $order['payment_method'] ?? 'cash',
                'payment_status' => $order['payment_status'] ?? 'pending',
                'merchant_name' => $order['merchant_name'] ?? 'DropX Store',
                'merchant_id' => $order['merchant_id'] ? (int)$order['merchant_id'] : null,
                'merchant_image' => formatImageUrl($order['merchant_image'], 'merchants'),
                'special_instructions' => $order['special_instructions'] ?? '',
                'updated_at' => $order['updated_at'],
                'cancellation_reason' => $order['cancellation_reason'] ?? null,
                'can_cancel' => in_array($order['status'], ['pending', 'confirmed']),
                'can_reorder' => true
            ];
        }

        ob_clean();
        ResponseHandler::success([
            'orders' => $formattedOrders,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total_items' => (int)$totalCount,
                'total_pages' => ceil($totalCount / $limit)
            ]
        ]);
        
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Failed to fetch orders: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CREATE ORDER FROM CART (Enhanced Add-ons)
 *********************************/
function createOrderFromCart($conn, $data, $userId) {
    try {
        $cartId = $data['cart_id'] ?? null;
        $deliveryAddress = trim($data['delivery_address'] ?? '');
        $paymentMethod = $data['payment_method'] ?? 'Cash on Delivery';
        $specialInstructions = trim($data['special_instructions'] ?? '');
        $tipAmount = floatval($data['tip_amount'] ?? 0);

        if (!$cartId) {
            ob_clean();
            ResponseHandler::error('Cart ID is required', 400);
        }

        if (!$deliveryAddress) {
            ob_clean();
            ResponseHandler::error('Delivery address is required', 400);
        }

        // Get cart items with complete add-ons information
        $cartStmt = $conn->prepare(
            "SELECT 
                ci.*,
                m.id as merchant_id,
                m.name as merchant_name,
                m.delivery_fee,
                m.min_order_amount,
                m.is_open,
                m.is_active,
                m.preparation_time as merchant_prep_time,
                qo.title as quick_order_title,
                qo.id as quick_order_id,
                qo.image_url as quick_order_image
             FROM cart_items ci
             LEFT JOIN merchants m ON ci.merchant_id = m.id
             LEFT JOIN quick_orders qo ON ci.quick_order_id = qo.id
             WHERE ci.cart_id = :cart_id 
             AND ci.is_active = 1
             AND ci.is_saved_for_later = 0"
        );
        
        $cartStmt->execute([':cart_id' => $cartId]);
        $cartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($cartItems)) {
            ob_clean();
            ResponseHandler::error('Cart is empty', 400);
        }

        // Validate all items are from same merchant
        $merchantId = $cartItems[0]['merchant_id'];
        $merchantName = $cartItems[0]['merchant_name'];
        
        foreach ($cartItems as $item) {
            if ($item['merchant_id'] != $merchantId) {
                ob_clean();
                ResponseHandler::error('All items must be from the same merchant', 400);
            }
        }

        // Check merchant is open and active
        if (!$cartItems[0]['is_open'] || !$cartItems[0]['is_active']) {
            ob_clean();
            ResponseHandler::error("$merchantName is currently not available", 400);
        }

        // Calculate totals with enhanced add-ons tracking
        $subtotal = 0;
        $deliveryFee = floatval($cartItems[0]['delivery_fee'] ?? 0);
        $itemsWithAddOns = [];
        
        foreach ($cartItems as $item) {
            $price = floatval($item['price'] ?? 0);
            $quantity = intval($item['quantity'] ?? 1);
            
            // Get detailed add-ons for this item
            $addOnsStmt = $conn->prepare(
                "SELECT ca.*, qoa.category, qoa.is_per_item, qoa.max_quantity
                 FROM cart_addons ca
                 LEFT JOIN quick_order_addons qoa ON ca.addon_id = qoa.id
                 WHERE ca.cart_item_id = :item_id"
            );
            $addOnsStmt->execute([':item_id' => $item['id']]);
            $addOns = $addOnsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate add-ons total and prepare detailed add-ons data
            $addOnsTotal = 0;
            $addOnsData = [];
            foreach ($addOns as $addOn) {
                $addOnPrice = floatval($addOn['price'] ?? 0);
                $addOnQty = intval($addOn['quantity'] ?? 1);
                $addOnTotal = $addOnPrice * $addOnQty;
                $addOnsTotal += $addOnTotal;
                
                $addOnsData[] = [
                    'id' => $addOn['addon_id'],
                    'name' => $addOn['name'],
                    'price' => $addOnPrice,
                    'quantity' => $addOnQty,
                    'total' => $addOnTotal,
                    'category' => $addOn['category'] ?? 'addons',
                    'is_per_item' => (bool)($addOn['is_per_item'] ?? true),
                    'max_quantity' => (int)($addOn['max_quantity'] ?? 1)
                ];
            }
            
            $itemTotal = ($price * $quantity) + $addOnsTotal;
            $subtotal += $itemTotal;
            
            // Parse variant data if exists
            $variantData = null;
            if (!empty($item['variant_data'])) {
                if (is_string($item['variant_data'])) {
                    $variantData = json_decode($item['variant_data'], true);
                } else {
                    $variantData = $item['variant_data'];
                }
            }
            
            // Parse selected options
            $selectedOptions = null;
            if (!empty($item['selected_options'])) {
                if (is_string($item['selected_options'])) {
                    $selectedOptions = json_decode($item['selected_options'], true);
                } else {
                    $selectedOptions = $item['selected_options'];
                }
            }
            
            $itemsWithAddOns[] = [
                'cart_item' => $item,
                'add_ons' => $addOnsData,
                'add_ons_total' => $addOnsTotal,
                'item_total' => $itemTotal,
                'variant_data' => $variantData,
                'selected_options' => $selectedOptions
            ];
        }

        // Check minimum order
        $minOrder = floatval($cartItems[0]['min_order_amount'] ?? 0);
        if ($subtotal < $minOrder) {
            ob_clean();
            ResponseHandler::error("Minimum order amount is MK " . number_format($minOrder, 2), 400);
        }

        // Get any applied promotion
        $promoStmt = $conn->prepare(
            "SELECT discount_amount FROM cart_promotions WHERE cart_id = :cart_id"
        );
        $promoStmt->execute([':cart_id' => $cartId]);
        $promoResult = $promoStmt->fetch(PDO::FETCH_ASSOC);
        $discountAmount = floatval($promoResult['discount_amount'] ?? 0);

        $totalAmount = $subtotal + $deliveryFee + $tipAmount - $discountAmount;

        // Generate unique order number
        $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

        // Begin transaction
        $conn->beginTransaction();

        // Create order
        $orderSql = "INSERT INTO orders (
            order_number, user_id, merchant_id, subtotal, 
            delivery_fee, tip_amount, discount_amount, total_amount,
            payment_method, payment_status, delivery_address, 
            special_instructions, status, created_at, updated_at
        ) VALUES (
            :order_number, :user_id, :merchant_id, :subtotal,
            :delivery_fee, :tip_amount, :discount_amount, :total_amount,
            :payment_method, 'pending', :delivery_address,
            :special_instructions, 'pending', NOW(), NOW()
        )";

        $orderStmt = $conn->prepare($orderSql);
        $orderStmt->execute([
            ':order_number' => $orderNumber,
            ':user_id' => $userId,
            ':merchant_id' => $merchantId,
            ':subtotal' => $subtotal,
            ':delivery_fee' => $deliveryFee,
            ':tip_amount' => $tipAmount,
            ':discount_amount' => $discountAmount,
            ':total_amount' => $totalAmount,
            ':payment_method' => $paymentMethod,
            ':delivery_address' => $deliveryAddress,
            ':special_instructions' => $specialInstructions
        ]);

        $orderId = $conn->lastInsertId();

        // Create order items with enhanced add-ons
        $itemSql = "INSERT INTO order_items (
            order_id, quick_order_id, quick_order_item_id,
            item_name, description, quantity, price, total,
            variant_id, variant_data, selected_options,
            add_ons_json, special_instructions, image_url, created_at
        ) VALUES (
            :order_id, :quick_order_id, :quick_order_item_id,
            :item_name, :description, :quantity, :price, :total,
            :variant_id, :variant_data, :selected_options,
            :add_ons_json, :special_instructions, :image_url, NOW()
        )";

        $itemStmt = $conn->prepare($itemSql);

        foreach ($itemsWithAddOns as $itemData) {
            $item = $itemData['cart_item'];
            $addOnsData = $itemData['add_ons'];
            
            // Get image URL
            $imageUrl = $item['image_url'] ?? $item['quick_order_image'] ?? null;
            
            $itemStmt->execute([
                ':order_id' => $orderId,
                ':quick_order_id' => $item['quick_order_id'] ?? null,
                ':quick_order_item_id' => $item['quick_order_item_id'] ?? null,
                ':item_name' => $item['name'] ?? $item['quick_order_title'] ?? 'Item',
                ':description' => $item['description'] ?? '',
                ':quantity' => intval($item['quantity'] ?? 1),
                ':price' => floatval($item['price'] ?? 0),
                ':total' => $itemData['item_total'],
                ':variant_id' => $item['variant_id'] ?? null,
                ':variant_data' => $itemData['variant_data'] ? json_encode($itemData['variant_data']) : null,
                ':selected_options' => $itemData['selected_options'] ? json_encode($itemData['selected_options']) : null,
                ':add_ons_json' => !empty($addOnsData) ? json_encode($addOnsData) : null,
                ':special_instructions' => $item['special_instructions'] ?? '',
                ':image_url' => formatImageUrl($imageUrl, 'menu_items')
            ]);
        }

        // Add to order status history
        $historySql = "INSERT INTO order_status_history (
            order_id, old_status, new_status, changed_by, 
            changed_by_id, created_at
        ) VALUES (
            :order_id, :old_status, :new_status, :changed_by,
            :changed_by_id, NOW()
        )";

        $historyStmt = $conn->prepare($historySql);
        $historyStmt->execute([
            ':order_id' => $orderId,
            ':old_status' => '',
            ':new_status' => 'pending',
            ':changed_by' => 'user',
            ':changed_by_id' => $userId
        ]);

        // Create tracking record
        $trackingSql = "INSERT INTO order_tracking (
            order_id, status, created_at, updated_at
        ) VALUES (
            :order_id, 'pending', NOW(), NOW()
        )";
        
        $trackingStmt = $conn->prepare($trackingSql);
        $trackingStmt->execute([':order_id' => $orderId]);

        // Clear cart items
        $clearItemsStmt = $conn->prepare(
            "UPDATE cart_items SET is_active = 0 WHERE cart_id = :cart_id"
        );
        $clearItemsStmt->execute([':cart_id' => $cartId]);

        // Clear cart add-ons
        $clearAddOnsStmt = $conn->prepare(
            "DELETE ca FROM cart_addons ca
             INNER JOIN cart_items ci ON ca.cart_item_id = ci.id
             WHERE ci.cart_id = :cart_id"
        );
        $clearAddOnsStmt->execute([':cart_id' => $cartId]);

        // Clear cart promotions
        $clearPromoStmt = $conn->prepare(
            "DELETE FROM cart_promotions WHERE cart_id = :cart_id"
        );
        $clearPromoStmt->execute([':cart_id' => $cartId]);

        // Update user's total orders
        $updateUserSql = "UPDATE users SET total_orders = total_orders + 1 WHERE id = :user_id";
        $updateUserStmt = $conn->prepare($updateUserSql);
        $updateUserStmt->execute([':user_id' => $userId]);

        $conn->commit();

        // Get created order details to return
        getOrderDetails($conn, $orderId, $userId);

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        ob_clean();
        ResponseHandler::error('Failed to create order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CREATE QUICK ORDER FROM ITEMS (Enhanced Add-ons)
 *********************************/
function createQuickOrderFromItems($conn, $data, $userId) {
    try {
        $merchantId = $data['merchant_id'] ?? null;
        $items = $data['items'] ?? [];
        $deliveryAddress = trim($data['delivery_address'] ?? '');
        $paymentMethod = $data['payment_method'] ?? 'Cash on Delivery';
        $specialInstructions = trim($data['special_instructions'] ?? '');
        $tipAmount = floatval($data['tip_amount'] ?? 0);

        if (!$merchantId || empty($items)) {
            ob_clean();
            ResponseHandler::error('Merchant ID and items are required', 400);
        }

        if (!$deliveryAddress) {
            ob_clean();
            ResponseHandler::error('Delivery address is required', 400);
        }

        // Check merchant
        $merchantStmt = $conn->prepare(
            "SELECT id, name, delivery_fee, min_order_amount, is_open, is_active, 
                    preparation_time_minutes, image_url
             FROM merchants WHERE id = :id"
        );
        $merchantStmt->execute([':id' => $merchantId]);
        $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);

        if (!$merchant || !$merchant['is_active']) {
            ob_clean();
            ResponseHandler::error('Merchant not found', 404);
        }

        if (!$merchant['is_open']) {
            ob_clean();
            ResponseHandler::error("{$merchant['name']} is currently closed", 400);
        }

        // Calculate subtotal and validate items with enhanced add-ons
        $subtotal = 0;
        $validatedItems = [];
        $allAddOns = [];

        foreach ($items as $item) {
            $quickOrderItemId = $item['quick_order_item_id'] ?? null;
            $quantity = intval($item['quantity'] ?? 1);
            $variantId = $item['variant_id'] ?? null;
            $selectedAddOns = $item['selected_add_ons'] ?? [];
            $itemSpecialInstructions = $item['special_instructions'] ?? '';

            if (!$quickOrderItemId) {
                ob_clean();
                ResponseHandler::error('Quick order item ID is required for all items', 400);
            }

            // Get item details with add-ons
            $itemStmt = $conn->prepare(
                "SELECT qoi.*, qo.title as quick_order_title, qo.id as quick_order_id,
                        qo.has_variants, qo.image_url as quick_order_image,
                        qo.description as quick_order_description
                 FROM quick_order_items qoi
                 JOIN quick_orders qo ON qoi.quick_order_id = qo.id
                 WHERE qoi.id = :id AND qoi.is_available = 1"
            );
            $itemStmt->execute([':id' => $quickOrderItemId]);
            $dbItem = $itemStmt->fetch(PDO::FETCH_ASSOC);

            if (!$dbItem) {
                ob_clean();
                ResponseHandler::error('Item not available', 400);
            }

            $price = floatval($dbItem['price'] ?? 0);
            $itemTotal = $price * $quantity;

            // Handle variant pricing
            $selectedVariant = null;
            if ($variantId && !empty($dbItem['variants_json'])) {
                $variants = json_decode($dbItem['variants_json'], true) ?? [];
                foreach ($variants as $variant) {
                    if (($variant['id'] ?? null) == $variantId) {
                        $price = floatval($variant['price'] ?? $price);
                        $itemTotal = $price * $quantity;
                        $selectedVariant = $variant;
                        break;
                    }
                }
            }

            // Get available add-ons for this quick order
            $addOnsListStmt = $conn->prepare(
                "SELECT * FROM quick_order_addons 
                 WHERE quick_order_id = :quick_order_id 
                 AND is_available = 1
                 ORDER BY category, price"
            );
            $addOnsListStmt->execute([':quick_order_id' => $dbItem['quick_order_id']]);
            $availableAddOns = $addOnsListStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Create lookup for available add-ons
            $availableAddOnsMap = [];
            foreach ($availableAddOns as $addOn) {
                $availableAddOnsMap[$addOn['id']] = $addOn;
            }

            // Calculate add-ons total and validate
            $addOnsTotal = 0;
            $addOnsData = [];
            if (!empty($selectedAddOns)) {
                foreach ($selectedAddOns as $selected) {
                    $addOnId = $selected['id'] ?? null;
                    $addOnQty = intval($selected['quantity'] ?? 1);
                    
                    if (!$addOnId || !isset($availableAddOnsMap[$addOnId])) {
                        continue; // Skip invalid add-ons
                    }
                    
                    $addOn = $availableAddOnsMap[$addOnId];
                    
                    // Check max quantity
                    if ($addOnQty > ($addOn['max_quantity'] ?? 1)) {
                        ob_clean();
                        ResponseHandler::error("Maximum quantity for {$addOn['name']} is {$addOn['max_quantity']}", 400);
                    }
                    
                    $addOnPrice = floatval($addOn['price']);
                    
                    // If add-on is per item, multiply by item quantity
                    $finalQty = ($addOn['is_per_item'] ?? true) ? $addOnQty * $quantity : $addOnQty;
                    $addOnTotal = $addOnPrice * $finalQty;
                    $addOnsTotal += $addOnTotal;
                    
                    $addOnsData[] = [
                        'id' => $addOn['id'],
                        'name' => $addOn['name'],
                        'price' => $addOnPrice,
                        'quantity' => $finalQty,
                        'original_quantity' => $addOnQty,
                        'per_item_quantity' => $addOnQty,
                        'total' => $addOnTotal,
                        'category' => $addOn['category'] ?? 'addons',
                        'is_per_item' => (bool)($addOn['is_per_item'] ?? true),
                        'max_quantity' => (int)($addOn['max_quantity'] ?? 1),
                        'is_required' => (bool)($addOn['is_required'] ?? false)
                    ];
                    
                    $allAddOns[] = $addOnsData;
                }
            }

            // Check required add-ons
            foreach ($availableAddOns as $addOn) {
                if (($addOn['is_required'] ?? false)) {
                    $found = false;
                    foreach ($addOnsData as $selectedAddOn) {
                        if ($selectedAddOn['id'] == $addOn['id']) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        ob_clean();
                        ResponseHandler::error("{$addOn['name']} is required for {$dbItem['name']}", 400);
                    }
                }
            }

            $subtotal += $itemTotal + $addOnsTotal;

            $validatedItems[] = [
                'quick_order_id' => $dbItem['quick_order_id'],
                'quick_order_item_id' => $quickOrderItemId,
                'quick_order_title' => $dbItem['quick_order_title'],
                'quick_order_image' => $dbItem['quick_order_image'],
                'quick_order_description' => $dbItem['quick_order_description'],
                'item_name' => $dbItem['name'],
                'description' => $dbItem['description'],
                'price' => $price,
                'quantity' => $quantity,
                'item_total' => $itemTotal,
                'add_ons_total' => $addOnsTotal,
                'add_ons_data' => $addOnsData,
                'variant_id' => $variantId,
                'variant_data' => $selectedVariant,
                'has_variants' => $dbItem['has_variants'],
                'special_instructions' => $itemSpecialInstructions,
                'image_url' => $dbItem['image_url'] ?? null
            ];
        }

        // Check minimum order
        if ($subtotal < $merchant['min_order_amount']) {
            ob_clean();
            ResponseHandler::error("Minimum order amount is MK " . number_format($merchant['min_order_amount'], 2), 400);
        }

        $deliveryFee = floatval($merchant['delivery_fee']);
        $totalAmount = $subtotal + $deliveryFee + $tipAmount;

        // Generate order number
        $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

        // Begin transaction
        $conn->beginTransaction();

        // Create order
        $orderSql = "INSERT INTO orders (
            order_number, user_id, merchant_id, subtotal, 
            delivery_fee, tip_amount, discount_amount, total_amount,
            payment_method, payment_status, delivery_address, 
            special_instructions, status, created_at, updated_at
        ) VALUES (
            :order_number, :user_id, :merchant_id, :subtotal,
            :delivery_fee, :tip_amount, :discount_amount, :total_amount,
            :payment_method, 'pending', :delivery_address,
            :special_instructions, 'pending', NOW(), NOW()
        )";

        $orderStmt = $conn->prepare($orderSql);
        $orderStmt->execute([
            ':order_number' => $orderNumber,
            ':user_id' => $userId,
            ':merchant_id' => $merchantId,
            ':subtotal' => $subtotal,
            ':delivery_fee' => $deliveryFee,
            ':tip_amount' => $tipAmount,
            ':discount_amount' => 0,
            ':total_amount' => $totalAmount,
            ':payment_method' => $paymentMethod,
            ':delivery_address' => $deliveryAddress,
            ':special_instructions' => $specialInstructions
        ]);

        $orderId = $conn->lastInsertId();

        // Create order items with enhanced add-ons
        $itemSql = "INSERT INTO order_items (
            order_id, quick_order_id, quick_order_item_id,
            item_name, description, quantity, price, total,
            variant_id, variant_data, add_ons_json, 
            special_instructions, image_url, created_at
        ) VALUES (
            :order_id, :quick_order_id, :quick_order_item_id,
            :item_name, :description, :quantity, :price, :total,
            :variant_id, :variant_data, :add_ons_json,
            :special_instructions, :image_url, NOW()
        )";

        $itemStmt = $conn->prepare($itemSql);

        foreach ($validatedItems as $item) {
            // Use item-specific image or fallback to quick order image
            $imageUrl = $item['image_url'] ?? $item['quick_order_image'] ?? null;
            
            $itemStmt->execute([
                ':order_id' => $orderId,
                ':quick_order_id' => $item['quick_order_id'],
                ':quick_order_item_id' => $item['quick_order_item_id'],
                ':item_name' => $item['item_name'],
                ':description' => $item['description'] ?? '',
                ':quantity' => $item['quantity'],
                ':price' => $item['price'],
                ':total' => $item['item_total'] + $item['add_ons_total'],
                ':variant_id' => $item['variant_id'] ?? null,
                ':variant_data' => $item['variant_data'] ? json_encode($item['variant_data']) : null,
                ':add_ons_json' => !empty($item['add_ons_data']) ? json_encode($item['add_ons_data']) : null,
                ':special_instructions' => $item['special_instructions'] ?? '',
                ':image_url' => formatImageUrl($imageUrl, 'quick_orders')
            ]);
        }

        // Add to order status history
        $historySql = "INSERT INTO order_status_history (
            order_id, old_status, new_status, changed_by, 
            changed_by_id, created_at
        ) VALUES (
            :order_id, :old_status, :new_status, :changed_by,
            :changed_by_id, NOW()
        )";

        $historyStmt = $conn->prepare($historySql);
        $historyStmt->execute([
            ':order_id' => $orderId,
            ':old_status' => '',
            ':new_status' => 'pending',
            ':changed_by' => 'user',
            ':changed_by_id' => $userId
        ]);

        // Create tracking record
        $trackingSql = "INSERT INTO order_tracking (
            order_id, status, created_at, updated_at
        ) VALUES (
            :order_id, 'pending', NOW(), NOW()
        )";
        
        $trackingStmt = $conn->prepare($trackingSql);
        $trackingStmt->execute([':order_id' => $orderId]);

        // Update user's total orders
        $updateUserSql = "UPDATE users SET total_orders = total_orders + 1 WHERE id = :user_id";
        $updateUserStmt = $conn->prepare($updateUserSql);
        $updateUserStmt->execute([':user_id' => $userId]);

        $conn->commit();

        // Get created order details to return
        getOrderDetails($conn, $orderId, $userId);

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        ob_clean();
        ResponseHandler::error('Failed to create order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CREATE ORDER (Legacy - Single Item)
 *********************************/
function createOrder($conn, $data, $userId) {
    try {
        if (!is_array($data)) {
            ob_clean();
            ResponseHandler::error('Invalid request data', 400);
            return;
        }
        
        $requiredFields = [
            'merchant_id' => 'Merchant ID',
            'items' => 'Order items',
            'delivery_address' => 'Delivery address'
        ];
        
        $missingFields = [];
        foreach ($requiredFields as $field => $label) {
            if (!isset($data[$field])) {
                $missingFields[] = $label;
            } elseif (is_array($data[$field]) && empty($data[$field])) {
                $missingFields[] = $label . ' (cannot be empty)';
            } elseif (!is_array($data[$field]) && trim($data[$field]) === '') {
                $missingFields[] = $label . ' (cannot be empty)';
            }
        }
        
        if (!empty($missingFields)) {
            ob_clean();
            ResponseHandler::error("Missing required fields: " . implode(', ', $missingFields), 400);
            return;
        }

        $merchantId = $data['merchant_id'];
        $items = $data['items'];
        
        if (!is_array($items) || empty($items)) {
            ob_clean();
            ResponseHandler::error('Items must be a non-empty array', 400);
            return;
        }

        $merchantStmt = $conn->prepare(
            "SELECT id, name, delivery_fee, is_open, minimum_order, 
                    preparation_time_minutes, address, image_url
             FROM merchants 
             WHERE id = ? AND is_active = 1"
        );
        
        $merchantStmt->execute([$merchantId]);
        $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);

        if (!$merchant) {
            ob_clean();
            ResponseHandler::error("Merchant not found or inactive", 404);
            return;
        }
        
        if (!$merchant['is_open']) {
            ob_clean();
            ResponseHandler::error("Merchant {$merchant['name']} is currently closed", 400);
            return;
        }

        $subtotal = 0;
        $itemsWithDetails = [];
        foreach ($items as $item) {
            if (!isset($item['name']) || !isset($item['quantity']) || !isset($item['price'])) {
                ob_clean();
                ResponseHandler::error('Invalid item data - each item must have name, quantity, and price', 400);
                return;
            }
            
            if (empty($item['name']) || $item['quantity'] <= 0 || $item['price'] <= 0) {
                ob_clean();
                ResponseHandler::error('Invalid item values - name cannot be empty, quantity and price must be positive', 400);
                return;
            }
            
            // Handle add-ons if present
            $addOnsTotal = 0;
            $addOnsData = [];
            if (!empty($item['add_ons'])) {
                foreach ($item['add_ons'] as $addOn) {
                    $addOnPrice = floatval($addOn['price'] ?? 0);
                    $addOnQty = intval($addOn['quantity'] ?? 1);
                    $addOnTotal = $addOnPrice * $addOnQty;
                    $addOnsTotal += $addOnTotal;
                    
                    $addOnsData[] = [
                        'id' => $addOn['id'] ?? null,
                        'name' => $addOn['name'] ?? 'Add-on',
                        'price' => $addOnPrice,
                        'quantity' => $addOnQty,
                        'total' => $addOnTotal
                    ];
                }
            }
            
            $itemTotal = ($item['price'] * $item['quantity']) + $addOnsTotal;
            $subtotal += $itemTotal;
            
            $itemsWithDetails[] = [
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'item_total' => $itemTotal,
                'add_ons' => $addOnsData,
                'add_ons_total' => $addOnsTotal,
                'special_instructions' => $item['special_instructions'] ?? '',
                'image_url' => $item['image_url'] ?? null
            ];
        }

        if ($subtotal < $merchant['minimum_order']) {
            ob_clean();
            ResponseHandler::error(
                "Order must be at least " . number_format($merchant['minimum_order'], 2), 
                400
            );
            return;
        }

        $deliveryFee = $merchant['delivery_fee'];
        $tipAmount = floatval($data['tip_amount'] ?? 0);
        $discountAmount = floatval($data['discount_amount'] ?? 0);
        $totalAmount = $subtotal + $deliveryFee + $tipAmount - $discountAmount;

        $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

        $conn->beginTransaction();

        $orderSql = "INSERT INTO orders (
            order_number, user_id, merchant_id, subtotal, 
            delivery_fee, tip_amount, discount_amount, total_amount,
            payment_method, payment_status, delivery_address, 
            special_instructions, status, created_at, updated_at
        ) VALUES (
            :order_number, :user_id, :merchant_id, :subtotal,
            :delivery_fee, :tip_amount, :discount_amount, :total_amount,
            :payment_method, 'pending', :delivery_address,
            :special_instructions, 'pending', NOW(), NOW()
        )";

        $orderStmt = $conn->prepare($orderSql);
        $orderStmt->execute([
            ':order_number' => $orderNumber,
            ':user_id' => $userId,
            ':merchant_id' => $merchantId,
            ':subtotal' => $subtotal,
            ':delivery_fee' => $deliveryFee,
            ':tip_amount' => $tipAmount,
            ':discount_amount' => $discountAmount,
            ':total_amount' => $totalAmount,
            ':payment_method' => $data['payment_method'] ?? 'Cash on Delivery',
            ':delivery_address' => $data['delivery_address'],
            ':special_instructions' => $data['special_instructions'] ?? ''
        ]);

        $orderId = $conn->lastInsertId();

        $itemSql = "INSERT INTO order_items (
            order_id, item_name, quantity, price, total,
            add_ons_json, special_instructions, image_url, created_at
        ) VALUES (
            :order_id, :item_name, :quantity, :price, :total,
            :add_ons_json, :special_instructions, :image_url, NOW()
        )";

        $itemStmt = $conn->prepare($itemSql);
        foreach ($itemsWithDetails as $item) {
            $itemStmt->execute([
                ':order_id' => $orderId,
                ':item_name' => $item['name'],
                ':quantity' => $item['quantity'],
                ':price' => $item['price'],
                ':total' => $item['item_total'],
                ':add_ons_json' => !empty($item['add_ons']) ? json_encode($item['add_ons']) : null,
                ':special_instructions' => $item['special_instructions'],
                ':image_url' => formatImageUrl($item['image_url'] ?? null, 'menu_items')
            ]);
        }

        $historySql = "INSERT INTO order_status_history (
            order_id, old_status, new_status, changed_by, 
            changed_by_id, created_at
        ) VALUES (
            :order_id, :old_status, :new_status, :changed_by,
            :changed_by_id, NOW()
        )";

        $historyStmt = $conn->prepare($historySql);
        $historyStmt->execute([
            ':order_id' => $orderId,
            ':old_status' => '',
            ':new_status' => 'pending',
            ':changed_by' => 'user',
            ':changed_by_id' => $userId
        ]);

        // Create tracking record
        $trackingSql = "INSERT INTO order_tracking (
            order_id, status, created_at, updated_at
        ) VALUES (
            :order_id, 'pending', NOW(), NOW()
        )";
        
        $trackingStmt = $conn->prepare($trackingSql);
        $trackingStmt->execute([':order_id' => $orderId]);

        $updateUserSql = "UPDATE users SET total_orders = total_orders + 1 WHERE id = :user_id";
        $updateUserStmt = $conn->prepare($updateUserSql);
        $updateUserStmt->execute([':user_id' => $userId]);

        $conn->commit();

        // Get created order details to return
        getOrderDetails($conn, $orderId, $userId);

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        ob_clean();
        ResponseHandler::error('Failed to create order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CANCEL ORDER
 *********************************/
function cancelOrder($conn, $data, $userId) {
    try {
        $orderId = $data['order_id'] ?? null;
        $reason = trim($data['reason'] ?? '');

        if (!$orderId) {
            ob_clean();
            ResponseHandler::error('Order ID is required', 400);
            return;
        }

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
            ob_clean();
            ResponseHandler::error('Order not found', 404);
            return;
        }

        $cancellableStatuses = ['pending', 'confirmed'];
        if (!in_array($order['status'], $cancellableStatuses)) {
            ob_clean();
            ResponseHandler::error('Order cannot be cancelled at this stage', 400);
            return;
        }

        $conn->beginTransaction();

        $updateStmt = $conn->prepare(
            "UPDATE orders SET 
                status = 'cancelled',
                cancellation_reason = :reason,
                updated_at = NOW()
             WHERE id = :order_id"
        );
        
        $updateStmt->execute([
            ':order_id' => $orderId,
            ':reason' => $reason
        ]);

        // Update tracking
        $trackingStmt = $conn->prepare(
            "UPDATE order_tracking SET status = 'cancelled', updated_at = NOW()
             WHERE order_id = :order_id"
        );
        $trackingStmt->execute([':order_id' => $orderId]);

        $historySql = "INSERT INTO order_status_history (
            order_id, old_status, new_status, changed_by, 
            changed_by_id, reason, created_at
        ) VALUES (
            :order_id, :old_status, :new_status, :changed_by,
            :changed_by_id, :reason, NOW()
        )";

        $historyStmt = $conn->prepare($historySql);
        $historyStmt->execute([
            ':order_id' => $orderId,
            ':old_status' => $order['status'],
            ':new_status' => 'cancelled',
            ':changed_by' => 'user',
            ':changed_by_id' => $userId,
            ':reason' => $reason
        ]);

        $conn->commit();

        ob_clean();
        ResponseHandler::success([
            'order_id' => (int)$orderId,
            'message' => 'Order cancelled successfully'
        ]);

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        ob_clean();
        ResponseHandler::error('Failed to cancel order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * REORDER (Enhanced with Add-ons)
 *********************************/
function reorder($conn, $data, $userId) {
    try {
        $orderId = $data['order_id'] ?? null;

        if (!$orderId) {
            ob_clean();
            ResponseHandler::error('Order ID is required', 400);
            return;
        }

        // Get original order details with items and add-ons
        $orderSql = "SELECT 
                        o.merchant_id,
                        o.delivery_address,
                        o.special_instructions,
                        o.payment_method,
                        oi.quick_order_id,
                        oi.quick_order_item_id,
                        oi.item_name,
                        oi.quantity,
                        oi.price,
                        oi.variant_id,
                        oi.variant_data,
                        oi.add_ons_json,
                        oi.special_instructions as item_instructions,
                        oi.image_url
                    FROM orders o
                    JOIN order_items oi ON o.id = oi.order_id
                    WHERE o.id = :order_id AND o.user_id = :user_id";
        
        $orderStmt = $conn->prepare($orderSql);
        $orderStmt->execute([
            ':order_id' => $orderId,
            ':user_id' => $userId
        ]);
        
        $items = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            ob_clean();
            ResponseHandler::error('Order not found', 404);
            return;
        }

        // Check if merchant is still active
        $merchantStmt = $conn->prepare(
            "SELECT id, is_open, is_active, name FROM merchants WHERE id = ?"
        );
        $merchantStmt->execute([$items[0]['merchant_id']]);
        $merchant = $merchantStmt->fetch(PDO::FETCH_ASSOC);

        if (!$merchant || !$merchant['is_active']) {
            ob_clean();
            ResponseHandler::error('Merchant is no longer available', 400);
            return;
        }

        if (!$merchant['is_open']) {
            ob_clean();
            ResponseHandler::error("{$merchant['name']} is currently closed", 400);
            return;
        }

        // Prepare reorder data with add-ons
        $reorderItems = [];
        foreach ($items as $item) {
            $reorderItem = [
                'quick_order_item_id' => $item['quick_order_item_id'],
                'quantity' => (int)$item['quantity'],
                'price' => (float)$item['price'],
                'special_instructions' => $item['item_instructions'] ?? ''
            ];
            
            if ($item['quick_order_id']) {
                $reorderItem['quick_order_id'] = $item['quick_order_id'];
            }
            
            if ($item['variant_id']) {
                $reorderItem['variant_id'] = $item['variant_id'];
                if ($item['variant_data']) {
                    $reorderItem['variant_data'] = json_decode($item['variant_data'], true);
                }
            }
            
            // Include add-ons from original order
            if ($item['add_ons_json']) {
                $addOns = json_decode($item['add_ons_json'], true);
                // Convert to format expected by createQuickOrderFromItems
                $reorderItem['selected_add_ons'] = array_map(function($addOn) {
                    return [
                        'id' => $addOn['id'],
                        'quantity' => $addOn['original_quantity'] ?? $addOn['quantity'] ?? 1
                    ];
                }, $addOns);
            }
            
            $reorderItems[] = $reorderItem;
        }

        $reorderData = [
            'merchant_id' => $items[0]['merchant_id'],
            'items' => $reorderItems,
            'delivery_address' => $items[0]['delivery_address'],
            'special_instructions' => $items[0]['special_instructions'],
            'payment_method' => $items[0]['payment_method']
        ];

        // Check if any items are quick orders
        $hasQuickOrders = !empty(array_filter($items, function($item) {
            return !empty($item['quick_order_id']);
        }));

        if ($hasQuickOrders) {
            createQuickOrderFromItems($conn, $reorderData, $userId);
        } else {
            createOrder($conn, $reorderData, $userId);
        }
        
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Failed to reorder: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * RATE ORDER
 *********************************/
function rateOrder($conn, $data, $userId) {
    try {
        $orderId = $data['order_id'] ?? null;
        $rating = intval($data['rating'] ?? 0);
        $review = trim($data['review'] ?? '');
        $itemRatings = $data['item_ratings'] ?? [];

        if (!$orderId) {
            ob_clean();
            ResponseHandler::error('Order ID is required', 400);
        }

        if ($rating < 1 || $rating > 5) {
            ob_clean();
            ResponseHandler::error('Rating must be between 1 and 5', 400);
        }

        // Check if order exists and is delivered
        $checkStmt = $conn->prepare(
            "SELECT o.id, o.merchant_id, o.quick_order_id,
                    m.name as merchant_name
             FROM orders o
             LEFT JOIN merchants m ON o.merchant_id = m.id
             WHERE o.id = :order_id 
             AND o.user_id = :user_id 
             AND o.status = 'delivered'"
        );
        $checkStmt->execute([
            ':order_id' => $orderId,
            ':user_id' => $userId
        ]);
        
        $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            ob_clean();
            ResponseHandler::error('Order not found or cannot be rated', 404);
        }

        // Check if already rated
        $existingStmt = $conn->prepare(
            "SELECT id FROM order_ratings WHERE order_id = :order_id AND user_id = :user_id"
        );
        $existingStmt->execute([
            ':order_id' => $orderId,
            ':user_id' => $userId
        ]);
        
        if ($existingStmt->fetch()) {
            ob_clean();
            ResponseHandler::error('You have already rated this order', 409);
        }

        $conn->beginTransaction();

        // Insert rating
        $ratingStmt = $conn->prepare(
            "INSERT INTO order_ratings 
                (order_id, user_id, merchant_id, rating, review, created_at)
             VALUES (:order_id, :user_id, :merchant_id, :rating, :review, NOW())"
        );
        
        $ratingStmt->execute([
            ':order_id' => $orderId,
            ':user_id' => $userId,
            ':merchant_id' => $order['merchant_id'],
            ':rating' => $rating,
            ':review' => $review
        ]);

        // Insert item ratings if provided
        if (!empty($itemRatings)) {
            $itemRatingStmt = $conn->prepare(
                "INSERT INTO order_item_ratings
                    (order_id, order_item_id, rating, review, created_at)
                 VALUES (:order_id, :order_item_id, :rating, :review, NOW())"
            );
            
            foreach ($itemRatings as $itemRating) {
                $itemRatingStmt->execute([
                    ':order_id' => $orderId,
                    ':order_item_id' => $itemRating['order_item_id'],
                    ':rating' => $itemRating['rating'],
                    ':review' => $itemRating['review'] ?? ''
                ]);
            }
        }

        // Update merchant rating
        updateMerchantRating($conn, $order['merchant_id']);

        // Update quick order rating if applicable
        if ($order['quick_order_id']) {
            updateQuickOrderRating($conn, $order['quick_order_id']);
        }

        $conn->commit();

        ob_clean();
        ResponseHandler::success([], 'Thank you for your rating!');

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        ob_clean();
        ResponseHandler::error('Failed to submit rating: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * TRACK ORDER (Enhanced with Add-ons)
 *********************************/
function trackOrder($conn, $orderId, $userId) {
    try {
        // Get order details
        $orderStmt = $conn->prepare(
            "SELECT 
                o.id,
                o.order_number,
                o.status,
                o.created_at,
                o.updated_at,
                o.merchant_id,
                o.total_amount,
                o.delivery_address,
                m.name as merchant_name,
                m.phone as merchant_phone,
                m.address as merchant_address,
                m.image_url as merchant_image
             FROM orders o
             LEFT JOIN merchants m ON o.merchant_id = m.id
             WHERE o.id = :order_id AND o.user_id = :user_id"
        );
        
        $orderStmt->execute([
            ':order_id' => $orderId,
            ':user_id' => $userId
        ]);
        
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            ob_clean();
            ResponseHandler::error('Order not found', 404);
        }

        // Get tracking history
        $trackingStmt = $conn->prepare(
            "SELECT 
                status,
                location,
                description,
                created_at as timestamp
             FROM order_tracking
             WHERE order_id = :order_id
             ORDER BY created_at ASC"
        );
        
        $trackingStmt->execute([':order_id' => $orderId]);
        $tracking = $trackingStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get status history
        $historyStmt = $conn->prepare(
            "SELECT 
                old_status,
                new_status,
                reason,
                created_at as timestamp
             FROM order_status_history
             WHERE order_id = :order_id
             ORDER BY created_at ASC"
        );
        
        $historyStmt->execute([':order_id' => $orderId]);
        $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get items with add-ons
        $itemsStmt = $conn->prepare(
            "SELECT 
                id,
                item_name,
                description,
                quantity,
                price,
                total,
                add_ons_json,
                variant_data,
                image_url,
                special_instructions
             FROM order_items
             WHERE order_id = :order_id
             ORDER BY id ASC"
        );
        
        $itemsStmt->execute([':order_id' => $orderId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Format items with add-ons
        $formattedItems = [];
        $totalAddOnsCount = 0;
        
        foreach ($items as $item) {
            $addOns = null;
            $addOnsTotal = 0;
            
            if (!empty($item['add_ons_json'])) {
                $addOns = json_decode($item['add_ons_json'], true);
                foreach ($addOns as $addOn) {
                    $addOnsTotal += ($addOn['price'] * $addOn['quantity']);
                }
                $totalAddOnsCount += count($addOns);
            }
            
            $variantData = null;
            if (!empty($item['variant_data'])) {
                $variantData = json_decode($item['variant_data'], true);
            }
            
            $formattedItems[] = [
                'id' => (int)$item['id'],
                'name' => $item['item_name'],
                'description' => $item['description'] ?? '',
                'quantity' => (int)$item['quantity'],
                'price' => (float)$item['price'],
                'total' => (float)$item['total'],
                'add_ons' => $addOns,
                'add_ons_total' => $addOnsTotal,
                'has_addons' => !empty($addOns),
                'addons_count' => $addOns ? count($addOns) : 0,
                'variant' => $variantData,
                'image_url' => $item['image_url'] ?? '',
                'special_instructions' => $item['special_instructions'] ?? ''
            ];
        }

        // Estimate delivery time based on status
        $estimatedDelivery = null;
        $currentTime = new DateTime();
        
        if ($order['status'] === 'pending') {
            $estimatedDelivery = (clone $currentTime)->modify('+45 minutes')->format('Y-m-d H:i:s');
        } elseif ($order['status'] === 'confirmed') {
            $estimatedDelivery = (clone $currentTime)->modify('+30 minutes')->format('Y-m-d H:i:s');
        } elseif ($order['status'] === 'preparing') {
            $estimatedDelivery = (clone $currentTime)->modify('+20 minutes')->format('Y-m-d H:i:s');
        } elseif ($order['status'] === 'ready') {
            $estimatedDelivery = (clone $currentTime)->modify('+10 minutes')->format('Y-m-d H:i:s');
        } elseif ($order['status'] === 'delivered') {
            $estimatedDelivery = $order['updated_at'];
        }

        // Progress steps based on current status
        $statusProgress = [
            'pending' => 20,
            'confirmed' => 40,
            'preparing' => 60,
            'ready' => 80,
            'delivered' => 100,
            'cancelled' => 0
        ];

        $progress = $statusProgress[$order['status']] ?? 20;

        ob_clean();
        ResponseHandler::success([
            'order' => [
                'id' => $order['id'],
                'order_number' => $order['order_number'],
                'status' => $order['status'],
                'status_progress' => $progress,
                'created_at' => $order['created_at'],
                'estimated_delivery' => $estimatedDelivery,
                'total_amount' => (float)$order['total_amount'],
                'delivery_address' => $order['delivery_address'],
                'merchant' => [
                    'name' => $order['merchant_name'],
                    'phone' => $order['merchant_phone'],
                    'address' => $order['merchant_address'],
                    'image' => formatImageUrl($order['merchant_image'], 'merchants')
                ]
            ],
            'tracking' => $tracking,
            'status_history' => $history,
            'items' => $formattedItems,
            'total_items' => count($items),
            'total_addons' => $totalAddOnsCount,
            'has_addons' => $totalAddOnsCount > 0
        ]);

    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Failed to track order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET LATEST ACTIVE ORDER (Enhanced with Add-ons)
 *********************************/
function getLatestActiveOrder($conn, $userId) {
    try {
        $activeStatuses = ['pending', 'confirmed', 'preparing', 'ready'];
        
        $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));
        
        $sql = "SELECT 
                    o.id,
                    o.order_number,
                    o.status,
                    o.total_amount,
                    o.created_at,
                    o.updated_at,
                    o.merchant_id,
                    o.delivery_address,
                    o.special_instructions,
                    m.name as merchant_name,
                    m.image_url as merchant_image,
                    (
                        SELECT COUNT(*) 
                        FROM order_items oi 
                        WHERE oi.order_id = o.id
                    ) as item_count,
                    (
                        SELECT GROUP_CONCAT(
                            CONCAT(
                                oi.id, '||', 
                                oi.item_name, '||', 
                                oi.quantity, '||', 
                                oi.price, '||',
                                COALESCE(oi.add_ons_json, '')
                            )
                            ORDER BY oi.id SEPARATOR ';;'
                        )
                        FROM order_items oi 
                        WHERE oi.order_id = o.id
                    ) as items_preview
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
            ob_clean();
            ResponseHandler::success(['order' => null, 'message' => 'No active orders']);
            return;
        }

        // Get tracking progress
        $trackingStmt = $conn->prepare(
            "SELECT status, created_at 
             FROM order_tracking 
             WHERE order_id = :order_id 
             ORDER BY created_at DESC LIMIT 1"
        );
        $trackingStmt->execute([':order_id' => $order['id']]);
        $tracking = $trackingStmt->fetch(PDO::FETCH_ASSOC);

        // Parse items preview to count add-ons
        $addOnsCount = 0;
        $itemsPreview = [];
        
        if (!empty($order['items_preview'])) {
            $itemStrings = explode(';;', $order['items_preview']);
            foreach ($itemStrings as $index => $itemString) {
                if ($index >= 3) break; // Limit preview
                
                $parts = explode('||', $itemString);
                if (count($parts) >= 4) {
                    $hasAddOns = !empty($parts[4]);
                    if ($hasAddOns) {
                        $addOns = json_decode($parts[4], true);
                        $addOnsCount += count($addOns);
                    }
                    
                    $itemsPreview[] = [
                        'name' => $parts[1],
                        'quantity' => (int)$parts[2],
                        'has_addons' => $hasAddOns
                    ];
                }
            }
        }

        $statusProgress = [
            'pending' => 20,
            'confirmed' => 40,
            'preparing' => 60,
            'ready' => 80,
            'delivered' => 100
        ];
        
        ob_clean();
        ResponseHandler::success([
            'order' => [
                'id' => (int)$order['id'],
                'order_number' => $order['order_number'],
                'status' => $order['status'],
                'status_progress' => $statusProgress[$order['status']] ?? 20,
                'merchant_name' => $order['merchant_name'] ?? 'DropX Store',
                'merchant_image' => formatImageUrl($order['merchant_image'], 'merchants'),
                'total_amount' => floatval($order['total_amount']),
                'item_count' => intval($order['item_count'] ?? 0),
                'addons_count' => $addOnsCount,
                'has_addons' => $addOnsCount > 0,
                'items_preview' => $itemsPreview,
                'delivery_address' => $order['delivery_address'],
                'special_instructions' => $order['special_instructions'] ?? '',
                'created_at' => $order['created_at'],
                'updated_at' => $order['updated_at'],
                'tracking_status' => $tracking['status'] ?? $order['status'],
                'last_update' => $tracking['created_at'] ?? $order['updated_at'] ?? $order['created_at']
            ]
        ]);
        
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Failed to get latest order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * UPDATE ORDER
 *********************************/
function updateOrder($conn, $data, $userId) {
    try {
        $orderId = $data['order_id'] ?? null;

        if (!$orderId) {
            ob_clean();
            ResponseHandler::error('Order ID is required', 400);
            return;
        }

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
            ob_clean();
            ResponseHandler::error('Order not found', 404);
            return;
        }

        $modifiableStatuses = ['pending'];
        if (!in_array($order['status'], $modifiableStatuses)) {
            ob_clean();
            ResponseHandler::error('Order cannot be modified at this stage', 400);
            return;
        }

        $updatableFields = ['special_instructions'];
        $updates = [];
        $params = [':order_id' => $orderId];

        foreach ($updatableFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($updates)) {
            ob_clean();
            ResponseHandler::error('No fields to update', 400);
            return;
        }

        $updates[] = "updated_at = NOW()";
        $updateSql = "UPDATE orders SET " . implode(', ', $updates) . " WHERE id = :order_id";

        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute($params);

        ob_clean();
        ResponseHandler::success(['order_id' => (int)$orderId], 'Order updated successfully');
        
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Failed to update order: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * UPDATE DELIVERY ADDRESS
 *********************************/
function updateDeliveryAddress($conn, $data, $userId) {
    try {
        $orderId = $data['order_id'] ?? null;
        $newAddress = trim($data['delivery_address'] ?? '');

        if (!$orderId || !$newAddress) {
            ob_clean();
            ResponseHandler::error('Order ID and new address are required', 400);
            return;
        }

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
            ob_clean();
            ResponseHandler::error('Order not found', 404);
            return;
        }

        $addressChangeableStatuses = ['pending', 'confirmed'];
        if (!in_array($order['status'], $addressChangeableStatuses)) {
            ob_clean();
            ResponseHandler::error('Delivery address cannot be changed at this stage', 400);
            return;
        }

        $conn->beginTransaction();

        $updateStmt = $conn->prepare(
            "UPDATE orders SET 
                delivery_address = :address,
                updated_at = NOW()
             WHERE id = :order_id"
        );
        
        $updateStmt->execute([
            ':order_id' => $orderId,
            ':address' => $newAddress
        ]);

        // Add to history
        $historySql = "INSERT INTO order_status_history (
            order_id, old_status, new_status, changed_by, 
            changed_by_id, notes, created_at
        ) VALUES (
            :order_id, :old_status, :new_status, :changed_by,
            :changed_by_id, :notes, NOW()
        )";

        $historyStmt = $conn->prepare($historySql);
        $historyStmt->execute([
            ':order_id' => $orderId,
            ':old_status' => $order['status'],
            ':new_status' => $order['status'],
            ':changed_by' => 'user',
            ':changed_by_id' => $userId,
            ':notes' => "Delivery address updated to: $newAddress"
        ]);

        $conn->commit();

        ob_clean();
        ResponseHandler::success([
            'order_id' => (int)$orderId,
            'new_address' => $newAddress
        ], 'Delivery address updated successfully');

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        ob_clean();
        ResponseHandler::error('Failed to update address: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET ORDER DETAILS (Enhanced with Add-ons)
 *********************************/
function getOrderDetails($conn, $orderId, $userId) {
    global $baseUrl;
    
    try {
        $sql = "SELECT 
                    o.id,
                    o.order_number,
                    o.status,
                    o.subtotal,
                    o.delivery_fee,
                    o.tip_amount,
                    o.discount_amount,
                    o.total_amount,
                    o.payment_method,
                    o.payment_status,
                    o.delivery_address,
                    o.special_instructions,
                    o.cancellation_reason,
                    o.created_at,
                    o.updated_at,
                    o.merchant_id,
                    u.full_name as customer_name,
                    u.phone as customer_phone,
                    u.email as customer_email,
                    m.name as merchant_name,
                    m.address as merchant_address,
                    m.phone as merchant_phone,
                    m.email as merchant_email,
                    
                    m.latitude as merchant_lat,
                    m.longitude as merchant_lng,
                    (
                        SELECT GROUP_CONCAT(
                            CONCAT(
                                oi.id, '||', 
                                oi.item_name, '||', 
                                oi.quantity, '||', 
                                oi.price, '||',
                                oi.total, '||',
                                COALESCE(oi.variant_id, 0), '||',
                                COALESCE(oi.add_ons_json, ''), '||',
                                COALESCE(oi.variant_data, ''), '||',
                               
                                COALESCE(oi.special_instructions, '')
                            )
                            ORDER BY oi.id SEPARATOR ';;'
                        )
                        FROM order_items oi 
                        WHERE oi.order_id = o.id
                    ) as items_data
                FROM orders o
                LEFT JOIN users u ON o.user_id = u.id
                LEFT JOIN merchants m ON o.merchant_id = m.id
                WHERE o.id = :order_id AND o.user_id = :user_id";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':order_id' => $orderId,
            ':user_id' => $userId
        ]);
        
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            ob_clean();
            ResponseHandler::error('Order not found', 404);
            return;
        }

        $items = [];
        $itemCount = 0;
        $totalAddOnsCount = 0;
        
        if (!empty($order['items_data'])) {
            $itemStrings = explode(';;', $order['items_data']);
            foreach ($itemStrings as $itemString) {
                $parts = explode('||', $itemString);
                if (count($parts) >= 5) {
                    $item = [
                        'id' => (int)$parts[0],
                        'name' => $parts[1],
                        'quantity' => (int)$parts[2],
                        'price' => (float)$parts[3],
                        'total' => (float)$parts[4]
                    ];
                    
                    if (isset($parts[5]) && $parts[5] > 0) {
                        $item['variant_id'] = (int)$parts[5];
                    }
                    
                    // Add-ons at position 6
                    if (isset($parts[6]) && !empty($parts[6])) {
                        $addOns = json_decode($parts[6], true);
                        $item['add_ons'] = $addOns;
                        
                        // Calculate add-ons total
                        $addOnsTotal = 0;
                        foreach ($addOns as $addOn) {
                            $addOnsTotal += ($addOn['price'] * $addOn['quantity']);
                        }
                        $item['add_ons_total'] = $addOnsTotal;
                        $totalAddOnsCount += count($addOns);
                    }
                    
                    // Variant data at position 7
                    if (isset($parts[7]) && !empty($parts[7])) {
                        $item['variant_data'] = json_decode($parts[7], true);
                    }
                    
                    // Image URL at position 8
                    if (isset($parts[8]) && !empty($parts[8])) {
                        $item['image_url'] = $parts[8];
                    }
                    
                    // Special instructions at position 9
                    if (isset($parts[9]) && !empty($parts[9])) {
                        $item['special_instructions'] = $parts[9];
                    }
                    
                    $items[] = $item;
                    $itemCount += (int)$parts[2];
                }
            }
        }

        $historyStmt = $conn->prepare(
            "SELECT old_status, new_status, reason, notes, created_at as timestamp
             FROM order_status_history
             WHERE order_id = :order_id
             ORDER BY created_at ASC"
        );
        $historyStmt->execute([':order_id' => $orderId]);
        $statusHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

        $trackingStmt = $conn->prepare(
            "SELECT status, location, description, created_at as timestamp
             FROM order_tracking
             WHERE order_id = :order_id
             ORDER BY created_at ASC"
        );
        $trackingStmt->execute([':order_id' => $orderId]);
        $tracking = $trackingStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get rating if exists
        $ratingStmt = $conn->prepare(
            "SELECT rating, review, created_at 
             FROM order_ratings 
             WHERE order_id = :order_id AND user_id = :user_id"
        );
        $ratingStmt->execute([
            ':order_id' => $orderId,
            ':user_id' => $userId
        ]);
        $userRating = $ratingStmt->fetch(PDO::FETCH_ASSOC);

        $statusProgress = [
            'pending' => 20,
            'confirmed' => 40,
            'preparing' => 60,
            'ready' => 80,
            'delivered' => 100,
            'cancelled' => 0
        ];

        $orderData = [
            'id' => (int)$order['id'],
            'order_number' => $order['order_number'],
            'status' => $order['status'],
            'status_progress' => $statusProgress[$order['status']] ?? 20,
            'customer' => [
                'name' => $order['customer_name'] ?? '',
                'phone' => $order['customer_phone'] ?? '',
                'email' => $order['customer_email'] ?? ''
            ],
            'delivery_address' => $order['delivery_address'],
            'total_amount' => (float)$order['total_amount'],
            'delivery_fee' => (float)$order['delivery_fee'],
            'subtotal' => (float)$order['subtotal'],
            'tip_amount' => (float)($order['tip_amount'] ?? 0),
            'discount_amount' => (float)($order['discount_amount'] ?? 0),
            'items' => $items,
            'item_count' => $itemCount,
            'addons_count' => $totalAddOnsCount,
            'has_addons' => $totalAddOnsCount > 0,
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at'],
            'payment_method' => $order['payment_method'] ?? 'cash',
            'payment_status' => $order['payment_status'] ?? 'pending',
            'merchant' => [
                'id' => $order['merchant_id'] ? (int)$order['merchant_id'] : null,
                'name' => $order['merchant_name'] ?? 'DropX Store',
                'address' => $order['merchant_address'] ?? '',
                'phone' => $order['merchant_phone'] ?? '',
                'email' => $order['merchant_email'] ?? '',
                'image' => formatImageUrl($order['merchant_image'], 'merchants'),
                'location' => [
                    'lat' => $order['merchant_lat'] ? (float)$order['merchant_lat'] : null,
                    'lng' => $order['merchant_lng'] ? (float)$order['merchant_lng'] : null
                ]
            ],
            'special_instructions' => $order['special_instructions'] ?? '',
            'cancellation_reason' => $order['cancellation_reason'] ?? '',
            'status_history' => $statusHistory,
            'tracking' => $tracking,
            'user_rating' => $userRating,
            'can_cancel' => in_array($order['status'], ['pending', 'confirmed']),
            'can_reorder' => true,
            'can_rate' => $order['status'] === 'delivered' && !$userRating
        ];

        ob_clean();
        ResponseHandler::success(['order' => $orderData]);
        
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Failed to get order details: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * GET ORDERS LIST (Legacy - Enhanced with Add-ons)
 *********************************/
function getOrdersList($conn, $userId) {
    try {
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $status = $_GET['status'] ?? 'all';
        $orderNumber = $_GET['order_number'] ?? '';

        $whereConditions = ["o.user_id = :user_id"];
        $params = [':user_id' => $userId];

        if ($status !== 'all') {
            $whereConditions[] = "o.status = :status";
            $params[':status'] = $status;
        }

        if ($orderNumber) {
            $whereConditions[] = "o.order_number LIKE :order_number";
            $params[':order_number'] = "%$orderNumber%";
        }

        $whereClause = "WHERE " . implode(" AND ", $whereConditions);

        $countSql = "SELECT COUNT(DISTINCT o.id) as total FROM orders o $whereClause";
        $countStmt = $conn->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

        $sql = "SELECT 
                    o.id,
                    o.order_number,
                    o.status,
                    o.total_amount,
                    o.created_at,
                    o.merchant_id,
                    m.name as merchant_name,
                    m.image_url as merchant_image,
                    (
                        SELECT COUNT(*) 
                        FROM order_items oi 
                        WHERE oi.order_id = o.id
                    ) as item_count,
                    (
                        SELECT GROUP_CONCAT(
                            CONCAT(
                                oi.item_name, '||',
                                oi.quantity, '||',
                                COALESCE(oi.add_ons_json, '')
                            )
                            ORDER BY oi.id SEPARATOR ';;'
                        )
                        FROM order_items oi 
                        WHERE oi.order_id = o.id
                        LIMIT 2
                    ) as items_preview
                FROM orders o
                LEFT JOIN merchants m ON o.merchant_id = m.id
                $whereClause
                ORDER BY o.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $conn->prepare($sql);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formattedOrders = [];
        foreach ($orders as $order) {
            // Check for add-ons in preview
            $hasAddOns = false;
            $itemsPreview = [];
            
            if (!empty($order['items_preview'])) {
                $itemStrings = explode(';;', $order['items_preview']);
                foreach ($itemStrings as $itemString) {
                    $parts = explode('||', $itemString);
                    if (count($parts) >= 2) {
                        $itemHasAddOns = !empty($parts[2]);
                        if ($itemHasAddOns) {
                            $hasAddOns = true;
                        }
                        $itemsPreview[] = [
                            'name' => $parts[0],
                            'quantity' => (int)$parts[1],
                            'has_addons' => $itemHasAddOns
                        ];
                    }
                }
            }
            
            $formattedOrders[] = [
                'id' => (int)$order['id'],
                'order_number' => $order['order_number'],
                'status' => $order['status'],
                'total_amount' => (float)$order['total_amount'],
                'created_at' => $order['created_at'],
                'merchant_name' => $order['merchant_name'] ?? 'DropX Store',
                'merchant_image' => formatImageUrl($order['merchant_image'], 'merchants'),
                'item_count' => (int)$order['item_count'],
                'has_addons' => $hasAddOns,
                'items_preview' => $itemsPreview
            ];
        }

        ob_clean();
        ResponseHandler::success([
            'orders' => $formattedOrders,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total_items' => (int)$totalCount,
                'total_pages' => ceil($totalCount / $limit)
            ]
        ]);
        
    } catch (Exception $e) {
        ob_clean();
        ResponseHandler::error('Failed to get orders: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * HELPER FUNCTIONS
 *********************************/
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
    
    return rtrim($baseUrl, '/') . '/' . $folder . '/' . ltrim($path, '/');
}

function updateMerchantRating($conn, $merchantId) {
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total_reviews,
            AVG(rating) as avg_rating
         FROM order_ratings
         WHERE merchant_id = :merchant_id"
    );
    $stmt->execute([':merchant_id' => $merchantId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $updateStmt = $conn->prepare(
        "UPDATE merchants 
         SET rating = :rating, 
             review_count = :review_count,
             updated_at = NOW()
         WHERE id = :id"
    );
    
    $updateStmt->execute([
        ':rating' => $result['avg_rating'] ?? 0,
        ':review_count' => $result['total_reviews'] ?? 0,
        ':id' => $merchantId
    ]);
}

function updateQuickOrderRating($conn, $quickOrderId) {
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total_reviews,
            AVG(rating) as avg_rating
         FROM order_ratings
         WHERE quick_order_id = :quick_order_id"
    );
    $stmt->execute([':quick_order_id' => $quickOrderId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result['total_reviews'] > 0) {
        $updateStmt = $conn->prepare(
            "UPDATE quick_orders 
             SET average_rating = :rating, 
                 rating_count = :review_count,
                 updated_at = NOW()
             WHERE id = :id"
        );
        
        $updateStmt->execute([
            ':rating' => $result['avg_rating'] ?? 0,
            ':review_count' => $result['total_reviews'] ?? 0,
            ':id' => $quickOrderId
        ]);
    }
}
?>