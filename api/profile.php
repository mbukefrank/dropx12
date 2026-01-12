<?php
// profile.php - Single file API for user profile management
// Enable CORS
header("Access-Control-Allow-Origin: https://dropx-frontend-seven.vercel.app");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    sendError('Authentication required', 401);
}

$userId = $_SESSION['user_id'];

// Database connection
$db = getDatabaseConnection();

try {
    // Handle different HTTP methods
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            handleGetRequest($userId, $db);
            break;
            
        case 'POST':
            handlePostRequest($userId, $db);
            break;
            
        case 'PUT':
            handlePutRequest($userId, $db);
            break;
            
        case 'DELETE':
            handleDeleteRequest($userId, $db);
            break;
            
        default:
            sendError('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    sendError('Server error: ' . $e->getMessage(), 500);
}

// ============ GET REQUESTS ============
function handleGetRequest($userId, $db) {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'orders':
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $status = $_GET['status'] ?? '';
            getUserOrders($userId, $page, $limit, $status, $db);
            break;
            
        case 'addresses':
            getUserAddresses($userId, $db);
            break;
            
        case 'get_profile':
            getUserProfile($userId, $db);
            break;
            
        default:
            getUserProfile($userId, $db);
    }
}

// ============ POST REQUESTS ============
function handlePostRequest($userId, $db) {
    // Check if it's a form data request (for profile update with avatar)
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (strpos($contentType, 'multipart/form-data') !== false) {
        // Handle multipart form data (profile update with avatar)
        updateUserProfile($userId, $_POST, $db);
    } else {
        // Handle JSON input for other actions
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            sendError('Invalid JSON input', 400);
        }
        
        $action = $input['action'] ?? '';
        
        switch ($action) {
            case 'add_address':
                addAddress($userId, $input, $db);
                break;
                
            case 'update_profile':
                updateUserProfile($userId, $input, $db);
                break;
                
            default:
                sendError('Invalid action', 400);
        }
    }
}

// ============ PUT REQUESTS ============
function handlePutRequest($userId, $db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        sendError('Invalid JSON input', 400);
    }
    
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'update_profile':
            updateUserProfile($userId, $input, $db);
            break;
            
        case 'set_default_address':
            setDefaultAddress($userId, $input, $db);
            break;
            
        default:
            sendError('Invalid action', 405);
    }
}

// ============ DELETE REQUESTS ============
function handleDeleteRequest($userId, $db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        sendError('Invalid JSON input', 400);
    }
    
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'delete_address':
            deleteAddress($userId, $input, $db);
            break;
            
        default:
            sendError('Invalid action', 400);
    }
}

// ============ PROFILE FUNCTIONS ============

function getUserProfile($userId, $db) {
    try {
        $stmt = $db->prepare("
            SELECT 
                u.id, u.email, u.full_name, u.phone, u.avatar,
                u.created_at, u.verified, u.role,
                (SELECT COUNT(*) FROM orders WHERE user_id = u.id) as total_orders,
                (SELECT COUNT(*) FROM addresses WHERE user_id = u.id) as total_addresses
            FROM users u 
            WHERE u.id = ?
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            sendError('User not found', 404);
        }
        
        $user = $result->fetch_assoc();
        
        // Get default address
        $address = getDefaultAddress($userId, $db);
        if ($address) {
            $user['address'] = $address['address'] ?? '';
        } else {
            $user['address'] = '';
        }
        
        // Get recent orders (limit to 5)
        $orders = getRecentOrders($userId, 5, $db);
        
        sendSuccess('Profile retrieved successfully', [
            'user' => $user,
            'recent_orders' => $orders
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to get profile: ' . $e->getMessage(), 500);
    }
}

function updateUserProfile($userId, $data, $db) {
    try {
        // Start transaction
        $db->begin_transaction();
        
        // Get current user data
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $currentUser = $stmt->get_result()->fetch_assoc();
        
        if (!$currentUser) {
            throw new Exception('User not found');
        }
        
        // Prepare update fields
        $updateFields = [];
        $updateValues = [];
        $types = '';
        
        // Handle regular fields
        if (isset($data['full_name']) && !empty($data['full_name'])) {
            $updateFields[] = "full_name = ?";
            $updateValues[] = trim($data['full_name']);
            $types .= "s";
        }
        
        if (isset($data['email']) && !empty($data['email'])) {
            $email = trim($data['email']);
            // Check if email already exists
            $checkStmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $checkStmt->bind_param("si", $email, $userId);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                throw new Exception('Email already exists');
            }
            
            $updateFields[] = "email = ?";
            $updateValues[] = $email;
            $types .= "s";
        }
        
        if (isset($data['phone'])) {
            $updateFields[] = "phone = ?";
            $updateValues[] = trim($data['phone']);
            $types .= "s";
        }
        
        // Handle address update
        if (isset($data['address']) && !empty($data['address'])) {
            updateUserAddress($userId, trim($data['address']), $db);
        }
        
        // Handle avatar upload if present
        $avatarUrl = $currentUser['avatar'];
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $avatarUrl = handleAvatarUpload($userId, $_FILES['avatar']);
            $updateFields[] = "avatar = ?";
            $updateValues[] = $avatarUrl;
            $types .= "s";
        }
        
        // If there are fields to update
        if (!empty($updateFields)) {
            $updateValues[] = $userId;
            $types .= "i";
            
            $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $updateStmt = $db->prepare($sql);
            $updateStmt->bind_param($types, ...$updateValues);
            $updateStmt->execute();
        }
        
        // Get updated user data
        $updatedUser = getUpdatedUserData($userId, $db);
        
        $db->commit();
        
        sendSuccess('Profile updated successfully', [
            'user' => $updatedUser
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        sendError('Failed to update profile: ' . $e->getMessage(), 500);
    }
}

function getUserOrders($userId, $page, $limit, $status, $db) {
    try {
        $offset = ($page - 1) * $limit;
        
        // Build query based on status filter
        $whereClause = "WHERE o.user_id = ?";
        $params = [$userId];
        $types = "i";
        
        if (!empty($status)) {
            $whereClause .= " AND o.status = ?";
            $params[] = $status;
            $types .= "s";
        }
        
        // Get total count for pagination
        $countStmt = $db->prepare("
            SELECT COUNT(*) as total 
            FROM orders o 
            $whereClause
        ");
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $totalResult = $countStmt->get_result()->fetch_assoc();
        $total = $totalResult['total'];
        
        // Get orders
        $stmt = $db->prepare("
            SELECT 
                o.*,
                r.name as restaurant_name,
                COUNT(oi.id) as item_count,
                DATE_FORMAT(o.created_at, '%Y-%m-%d %H:%i') as formatted_date
            FROM orders o
            LEFT JOIN restaurants r ON o.restaurant_id = r.id
            LEFT JOIN order_items oi ON o.id = oi.order_id
            $whereClause
            GROUP BY o.id
            ORDER BY o.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";
        
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $orders = [];
        while ($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }
        
        sendSuccess('Orders retrieved successfully', [
            'orders' => $orders,
            'pagination' => [
                'page' => (int)$page,
                'limit' => (int)$limit,
                'total' => (int)$total,
                'pages' => ceil($total / $limit)
            ]
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to get orders: ' . $e->getMessage(), 500);
    }
}

function getUserAddresses($userId, $db) {
    try {
        $stmt = $db->prepare("
            SELECT * FROM addresses 
            WHERE user_id = ? 
            ORDER BY is_default DESC, created_at DESC
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $addresses = [];
        while ($row = $result->fetch_assoc()) {
            $addresses[] = $row;
        }
        
        sendSuccess('Addresses retrieved successfully', [
            'addresses' => $addresses
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to get addresses: ' . $e->getMessage(), 500);
    }
}

function addAddress($userId, $data, $db) {
    try {
        $required = ['title', 'address', 'city'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                sendError("$field is required", 400);
            }
        }
        
        // Check if this is the first address (set as default)
        $checkStmt = $db->prepare("SELECT COUNT(*) as count FROM addresses WHERE user_id = ?");
        $checkStmt->bind_param("i", $userId);
        $checkStmt->execute();
        $countResult = $checkStmt->get_result()->fetch_assoc();
        $isDefault = $countResult['count'] == 0 ? 1 : 0;
        
        // If setting as default, update existing defaults
        if (isset($data['is_default']) && $data['is_default'] == 1) {
            $db->query("UPDATE addresses SET is_default = 0 WHERE user_id = $userId");
            $isDefault = 1;
        }
        
        $stmt = $db->prepare("
            INSERT INTO addresses (user_id, title, address, city, address_type, is_default, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $addressType = $data['address_type'] ?? 'other';
        $stmt->bind_param(
            "issssi", 
            $userId, 
            $data['title'], 
            $data['address'], 
            $data['city'], 
            $addressType,
            $isDefault
        );
        
        $stmt->execute();
        
        sendSuccess('Address added successfully', [
            'address_id' => $stmt->insert_id
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to add address: ' . $e->getMessage(), 500);
    }
}

function setDefaultAddress($userId, $data, $db) {
    try {
        if (empty($data['address_id'])) {
            sendError('Address ID is required', 400);
        }
        
        $addressId = $data['address_id'];
        
        // Verify address belongs to user
        $verifyStmt = $db->prepare("SELECT id FROM addresses WHERE id = ? AND user_id = ?");
        $verifyStmt->bind_param("ii", $addressId, $userId);
        $verifyStmt->execute();
        
        if ($verifyStmt->get_result()->num_rows === 0) {
            sendError('Address not found', 404);
        }
        
        // Start transaction
        $db->begin_transaction();
        
        // Reset all defaults
        $resetStmt = $db->prepare("UPDATE addresses SET is_default = 0 WHERE user_id = ?");
        $resetStmt->bind_param("i", $userId);
        $resetStmt->execute();
        
        // Set new default
        $updateStmt = $db->prepare("UPDATE addresses SET is_default = 1 WHERE id = ? AND user_id = ?");
        $updateStmt->bind_param("ii", $addressId, $userId);
        $updateStmt->execute();
        
        $db->commit();
        
        sendSuccess('Default address updated successfully');
        
    } catch (Exception $e) {
        $db->rollback();
        sendError('Failed to set default address: ' . $e->getMessage(), 500);
    }
}

function deleteAddress($userId, $data, $db) {
    try {
        if (empty($data['address_id'])) {
            sendError('Address ID is required', 400);
        }
        
        $addressId = $data['address_id'];
        
        // Check if address is default
        $checkStmt = $db->prepare("SELECT is_default FROM addresses WHERE id = ? AND user_id = ?");
        $checkStmt->bind_param("ii", $addressId, $userId);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows === 0) {
            sendError('Address not found', 404);
        }
        
        $address = $result->fetch_assoc();
        
        if ($address['is_default'] == 1) {
            sendError('Cannot delete default address. Set another address as default first.', 400);
        }
        
        // Delete address
        $deleteStmt = $db->prepare("DELETE FROM addresses WHERE id = ? AND user_id = ?");
        $deleteStmt->bind_param("ii", $addressId, $userId);
        $deleteStmt->execute();
        
        sendSuccess('Address deleted successfully');
        
    } catch (Exception $e) {
        sendError('Failed to delete address: ' . $e->getMessage(), 500);
    }
}

// ============ HELPER FUNCTIONS ============

function getDefaultAddress($userId, $db) {
    try {
        $stmt = $db->prepare("
            SELECT address FROM addresses 
            WHERE user_id = ? AND is_default = 1 
            LIMIT 1
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        return null;
    } catch (Exception $e) {
        return null;
    }
}

function getRecentOrders($userId, $limit, $db) {
    try {
        $stmt = $db->prepare("
            SELECT 
                o.id, o.order_number, o.total_amount, o.status, o.created_at,
                COUNT(oi.id) as item_count,
                DATE_FORMAT(o.created_at, '%Y-%m-%d') as formatted_date
            FROM orders o
            LEFT JOIN order_items oi ON o.id = oi.order_id
            WHERE o.user_id = ?
            GROUP BY o.id
            ORDER BY o.created_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("ii", $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $orders = [];
        while ($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }
        
        return $orders;
    } catch (Exception $e) {
        return [];
    }
}

function updateUserAddress($userId, $address, $db) {
    try {
        // Check if user has a default address
        $checkStmt = $db->prepare("
            SELECT id FROM addresses 
            WHERE user_id = ? AND is_default = 1
            LIMIT 1
        ");
        $checkStmt->bind_param("i", $userId);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows > 0) {
            // Update existing default address
            $addressRow = $result->fetch_assoc();
            $updateStmt = $db->prepare("
                UPDATE addresses SET address = ? WHERE id = ?
            ");
            $updateStmt->bind_param("si", $address, $addressRow['id']);
            $updateStmt->execute();
        } else {
            // Create new default address
            $insertStmt = $db->prepare("
                INSERT INTO addresses (user_id, title, address, city, address_type, is_default, created_at)
                VALUES (?, 'Home', ?, '', 'home', 1, NOW())
            ");
            $insertStmt->bind_param("is", $userId, $address);
            $insertStmt->execute();
        }
    } catch (Exception $e) {
        // Silently fail - address update is not critical
    }
}

function handleAvatarUpload($userId, $file) {
    // Validate file
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('Invalid file type. Allowed: JPEG, PNG, GIF, WebP');
    }
    
    if ($file['size'] > $maxSize) {
        throw new Exception('File size exceeds 5MB limit');
    }
    
    // Create uploads directory if it doesn't exist
    $uploadDir = __DIR__ . '/../../uploads/avatars/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Generate unique filename
    $fileExt = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = 'avatar_' . $userId . '_' . time() . '.' . $fileExt;
    $filePath = $uploadDir . $fileName;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        throw new Exception('Failed to upload file');
    }
    
    // Return relative path for database storage
    return '/uploads/avatars/' . $fileName;
}

function getUpdatedUserData($userId, $db) {
    $stmt = $db->prepare("
        SELECT 
            id, email, full_name, phone, avatar, verified, created_at,
            (SELECT COUNT(*) FROM orders WHERE user_id = u.id) as total_orders
        FROM users u
        WHERE u.id = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    
    // Get default address
    $address = getDefaultAddress($userId, $db);
    if ($address) {
        $user['address'] = $address['address'] ?? '';
    } else {
        $user['address'] = '';
    }
    
    return $user;
}

function getDatabaseConnection() {
    // Update these with your actual database credentials
    $host = 'localhost';
    $username = 'root';
    $password = '';
    $database = 'dropx_db';
    
    $conn = new mysqli($host, $username, $password, $database);
    
    if ($conn->connect_error) {
        sendError('Database connection failed: ' . $conn->connect_error, 500);
    }
    
    $conn->set_charset("utf8mb4");
    return $conn;
}

// ============ RESPONSE FUNCTIONS ============

function sendSuccess($message, $data = []) {
    $response = [
        'success' => true,
        'message' => $message,
        'data' => $data
    ];
    
    http_response_code(200);
    echo json_encode($response);
    exit();
}

function sendError($message, $statusCode = 400) {
    $response = [
        'success' => false,
        'message' => $message
    ];
    
    http_response_code($statusCode);
    echo json_encode($response);
    exit();
}
?>