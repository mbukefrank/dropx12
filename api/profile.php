<?php
// profile.php - UPDATED FOR YOUR DATABASE STRUCTURE

// Start output buffering
ob_start();

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://dropx-frontend-seven.vercel.app');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Simple error handler
function sendError($message, $code = 500) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $message
    ]);
    exit;
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    sendError('Unauthorized. Please login.', 401);
}

$user_id = $_SESSION['user_id'];

try {
    // Try to include config/database.php
    $configPath = __DIR__ . '/../config/database.php';
    
    if (!file_exists($configPath)) {
        sendError('Database configuration not found at: ' . $configPath, 500);
    }
    
    require_once $configPath;
    
    // Check if Database class exists
    if (!class_exists('Database')) {
        sendError('Database class not found in config/database.php', 500);
    }
    
    $database = new Database();
    $conn = $database->getConnection();
    
    // Get the action
    $method = $_SERVER['REQUEST_METHOD'];
    $action = '';
    
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'get_profile';
    } else {
        // Check if form data
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'multipart/form-data') !== false) {
            $action = $_POST['action'] ?? '';
        } else {
            $input = json_decode(file_get_contents('php://input'), true);
            $action = $input['action'] ?? '';
        }
    }
    
    if (empty($action)) {
        sendError('No action specified', 400);
    }
    
    // Handle different actions
    switch ($action) {
        case 'get_profile':
            getProfile($user_id, $conn);
            break;
        case 'addresses':
            getUserAddresses($user_id, $conn);
            break;
        case 'add_address':
            if ($method !== 'POST') sendError('Method not allowed', 405);
            addAddress($user_id, $conn);
            break;
        case 'set_default_address':
            if ($method !== 'PUT') sendError('Method not allowed', 405);
            setDefaultAddress($user_id, $conn);
            break;
        case 'delete_address':
            if ($method !== 'DELETE') sendError('Method not allowed', 405);
            deleteAddress($user_id, $conn);
            break;
        case 'orders':
            if ($method !== 'GET') sendError('Method not allowed', 405);
            getUserOrders($user_id, $conn);
            break;
        case 'update_profile':
            if ($method !== 'POST') sendError('Method not allowed', 405);
            updateProfile($user_id, $conn);
            break;
        case 'change_password':
            if ($method !== 'POST') sendError('Method not allowed', 405);
            changePassword($user_id, $conn);
            break;
        default:
            sendError('Invalid action: ' . $action, 400);
    }
    
} catch (Exception $e) {
    sendError('Server error: ' . $e->getMessage(), 500);
}

// ============ PROFILE FUNCTIONS ============

function getProfile($user_id, $conn) {
    try {
        // Get user data - USING YOUR ACTUAL TABLE STRUCTURE
        $stmt = $conn->prepare("
            SELECT 
                id, email, full_name, phone, avatar,
                wallet_balance, member_level, member_points,
                total_orders, rating, verified,
                DATE_FORMAT(created_at, '%Y-%m-%d') as join_date
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            sendError('User not found', 404);
        }

        // Get default address from user_addresses table
        $addressStmt = $conn->prepare("
            SELECT address, city 
            FROM user_addresses 
            WHERE user_id = ? AND is_default = 1 
            LIMIT 1
        ");
        $addressStmt->execute([$user_id]);
        $addressResult = $addressStmt->fetch(PDO::FETCH_ASSOC);
        
        // Add address to user data
        if ($addressResult) {
            $user['address'] = $addressResult['address'];
            if ($addressResult['city']) {
                $user['address'] .= ', ' . $addressResult['city'];
            }
        } else {
            $user['address'] = '';
        }

        // Get recent orders (5 most recent)
        $ordersStmt = $conn->prepare("
            SELECT 
                o.id, o.order_number, o.total_amount, o.status, 
                DATE_FORMAT(o.created_at, '%Y-%m-%d') as formatted_date,
                r.name as restaurant_name,
                (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
            FROM orders o
            LEFT JOIN restaurants r ON o.restaurant_id = r.id
            WHERE o.user_id = ?
            ORDER BY o.created_at DESC 
            LIMIT 5
        ");
        $ordersStmt->execute([$user_id]);
        $recentOrders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

        // Clear output buffer and send response
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Profile retrieved successfully',
            'data' => [
                'user' => $user,
                'recent_orders' => $recentOrders
            ]
        ]);
        exit;
        
    } catch (Exception $e) {
        sendError('Failed to get profile: ' . $e->getMessage(), 500);
    }
}

function updateProfile($user_id, $conn) {
    try {
        // Check content type
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isFormData = strpos($contentType, 'multipart/form-data') !== false;
        
        if ($isFormData) {
            $data = $_POST;
            $files = $_FILES;
        } else {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            if (!$data) {
                sendError('Invalid JSON data', 400);
            }
            $files = [];
        }
        
        // Validate required fields
        if (empty($data['full_name'])) {
            sendError('Full name is required', 400);
        }
        
        if (empty($data['email'])) {
            sendError('Email is required', 400);
        }
        
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            sendError('Invalid email format', 400);
        }
        
        // Check if email already exists (for other users)
        $emailCheck = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $emailCheck->execute([$data['email'], $user_id]);
        if ($emailCheck->fetch()) {
            sendError('Email already in use by another account', 400);
        }
        
        // Start transaction
        $conn->beginTransaction();
        
        try {
            // Handle avatar upload if present
            $avatarPath = null;
            if ($isFormData && isset($files['avatar']) && $files['avatar']['error'] === UPLOAD_ERR_OK) {
                $avatarPath = handleAvatarUpload($user_id, $files['avatar']);
            }
            
            // Build update query
            $updateFields = [];
            $updateParams = [];
            
            $updateFields[] = "full_name = ?";
            $updateParams[] = trim($data['full_name']);
            
            $updateFields[] = "email = ?";
            $updateParams[] = trim($data['email']);
            
            if (isset($data['phone'])) {
                $updateFields[] = "phone = ?";
                $updateParams[] = trim($data['phone']);
            }
            
            if ($avatarPath) {
                $updateFields[] = "avatar = ?";
                $updateParams[] = $avatarPath;
            }
            
            // Update user
            $updateFields[] = "updated_at = CURRENT_TIMESTAMP";
            
            $query = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $updateParams[] = $user_id;
            
            $stmt = $conn->prepare($query);
            $stmt->execute($updateParams);
            
            // Update address if provided
            if (isset($data['address']) && !empty(trim($data['address']))) {
                updateUserAddress($user_id, trim($data['address']), $conn);
            }
            
            // Get updated user
            $userQuery = $conn->prepare("
                SELECT 
                    id, email, full_name, phone, avatar,
                    wallet_balance, member_level, member_points,
                    total_orders, rating, verified,
                    DATE_FORMAT(created_at, '%Y-%m-%d') as join_date
                FROM users 
                WHERE id = ?
            ");
            $userQuery->execute([$user_id]);
            $updatedUser = $userQuery->fetch(PDO::FETCH_ASSOC);
            
            // Get default address
            $addressQuery = $conn->prepare("
                SELECT address, city 
                FROM user_addresses 
                WHERE user_id = ? AND is_default = 1 
                LIMIT 1
            ");
            $addressQuery->execute([$user_id]);
            $addressResult = $addressQuery->fetch(PDO::FETCH_ASSOC);
            
            if ($addressResult) {
                $updatedUser['address'] = $addressResult['address'];
                if ($addressResult['city']) {
                    $updatedUser['address'] .= ', ' . $addressResult['city'];
                }
            } else {
                $updatedUser['address'] = '';
            }
            
            $conn->commit();
            
            ob_end_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => [
                    'user' => $updatedUser
                ]
            ]);
            exit;
            
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
        
    } catch (Exception $e) {
        sendError('Profile update failed: ' . $e->getMessage(), 500);
    }
}

function handleAvatarUpload($user_id, $file) {
    // Validate file
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('Invalid file type. Allowed: JPEG, PNG, GIF, WebP');
    }
    
    if ($file['size'] > $maxSize) {
        throw new Exception('File size exceeds 5MB limit');
    }
    
    // Create uploads directory
    $uploadDir = __DIR__ . '/../../uploads/avatars/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Generate unique filename
    $fileExt = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = 'avatar_' . $user_id . '_' . time() . '.' . $fileExt;
    $filePath = $uploadDir . $fileName;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        throw new Exception('Failed to upload file');
    }
    
    return '/uploads/avatars/' . $fileName;
}

function updateUserAddress($user_id, $address, $conn) {
    // Check if user has a default address
    $checkQuery = $conn->prepare("SELECT id FROM user_addresses WHERE user_id = ? AND is_default = 1 LIMIT 1");
    $checkQuery->execute([$user_id]);
    $result = $checkQuery->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        // Update existing default address
        $updateQuery = $conn->prepare("UPDATE user_addresses SET address = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $updateQuery->execute([$address, $result['id']]);
    } else {
        // Create new default address
        $insertQuery = $conn->prepare("
            INSERT INTO user_addresses (user_id, title, address, city, address_type, is_default, created_at)
            VALUES (?, 'Home', ?, '', 'home', 1, CURRENT_TIMESTAMP)
        ");
        $insertQuery->execute([$user_id, $address]);
    }
}

function getUserAddresses($user_id, $conn) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                id, title, address, city, state, zip_code,
                latitude, longitude, is_default, instructions,
                address_type,
                DATE_FORMAT(created_at, '%Y-%m-%d') as created_date
            FROM user_addresses 
            WHERE user_id = ? 
            ORDER BY is_default DESC, created_at DESC
        ");
        $stmt->execute([$user_id]);
        $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Addresses retrieved successfully',
            'data' => [
                'addresses' => $addresses
            ]
        ]);
        exit;
        
    } catch (Exception $e) {
        sendError('Failed to get addresses: ' . $e->getMessage(), 500);
    }
}

function addAddress($user_id, $conn) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            sendError('Invalid request data', 400);
        }
        
        // Validate required fields
        $required = ['title', 'address', 'city'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                sendError("$field is required", 400);
            }
        }
        
        // Check if this is the first address
        $checkQuery = $conn->prepare("SELECT COUNT(*) as count FROM user_addresses WHERE user_id = ?");
        $checkQuery->execute([$user_id]);
        $countResult = $checkQuery->fetch(PDO::FETCH_ASSOC);
        $isDefault = $countResult['count'] == 0 ? 1 : 0;
        
        // If setting as default, update existing defaults
        if (isset($input['is_default']) && $input['is_default'] == 1) {
            $updateDefaults = $conn->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?");
            $updateDefaults->execute([$user_id]);
            $isDefault = 1;
        }
        
        $query = $conn->prepare("
            INSERT INTO user_addresses 
            (user_id, title, address, city, state, zip_code, 
             address_type, is_default, instructions, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        
        $addressType = $input['address_type'] ?? 'other';
        $state = $input['state'] ?? '';
        $zipCode = $input['zip_code'] ?? '';
        $instructions = $input['instructions'] ?? '';
        
        $query->execute([
            $user_id,
            $input['title'],
            $input['address'],
            $input['city'],
            $state,
            $zipCode,
            $addressType,
            $isDefault,
            $instructions
        ]);
        
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Address added successfully',
            'data' => [
                'address_id' => $conn->lastInsertId()
            ]
        ]);
        exit;
        
    } catch (Exception $e) {
        sendError('Failed to add address: ' . $e->getMessage(), 500);
    }
}

function setDefaultAddress($user_id, $conn) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || empty($input['address_id'])) {
            sendError('Address ID is required', 400);
        }
        
        $addressId = $input['address_id'];
        
        // Verify address belongs to user
        $verifyQuery = $conn->prepare("SELECT id FROM user_addresses WHERE id = ? AND user_id = ?");
        $verifyQuery->execute([$addressId, $user_id]);
        
        if (!$verifyQuery->fetch()) {
            sendError('Address not found', 404);
        }
        
        $conn->beginTransaction();
        
        try {
            // Reset all defaults
            $resetQuery = $conn->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?");
            $resetQuery->execute([$user_id]);
            
            // Set new default
            $updateQuery = $conn->prepare("UPDATE user_addresses SET is_default = 1 WHERE id = ? AND user_id = ?");
            $updateQuery->execute([$addressId, $user_id]);
            
            $conn->commit();
            
            ob_end_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Default address updated successfully'
            ]);
            exit;
            
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
        
    } catch (Exception $e) {
        sendError('Failed to set default address: ' . $e->getMessage(), 500);
    }
}

function deleteAddress($user_id, $conn) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || empty($input['address_id'])) {
            sendError('Address ID is required', 400);
        }
        
        $addressId = $input['address_id'];
        
        // Check if address exists and is default
        $checkQuery = $conn->prepare("SELECT is_default FROM user_addresses WHERE id = ? AND user_id = ?");
        $checkQuery->execute([$addressId, $user_id]);
        $result = $checkQuery->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            sendError('Address not found', 404);
        }
        
        if ($result['is_default'] == 1) {
            sendError('Cannot delete default address. Set another address as default first.', 400);
        }
        
        // Delete address
        $deleteQuery = $conn->prepare("DELETE FROM user_addresses WHERE id = ? AND user_id = ?");
        $deleteQuery->execute([$addressId, $user_id]);
        
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Address deleted successfully'
        ]);
        exit;
        
    } catch (Exception $e) {
        sendError('Failed to delete address: ' . $e->getMessage(), 500);
    }
}

function getUserOrders($user_id, $conn) {
    try {
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
        $status = $_GET['status'] ?? '';
        
        $offset = ($page - 1) * $limit;
        
        // Build query
        $where = "WHERE o.user_id = ?";
        $params = [$user_id];
        
        if (!empty($status)) {
            $where .= " AND o.status = ?";
            $params[] = $status;
        }
        
        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM orders $where";
        $stmt = $conn->prepare($countQuery);
        $stmt->execute($params);
        $totalResult = $stmt->fetch(PDO::FETCH_ASSOC);
        $total = $totalResult['total'];
        
        // Get orders
        $query = "
            SELECT 
                o.*,
                r.name as restaurant_name,
                r.image as restaurant_image,
                DATE_FORMAT(o.created_at, '%Y-%m-%d %H:%i') as formatted_date,
                (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
            FROM orders o
            LEFT JOIN restaurants r ON o.restaurant_id = r.id
            $where
            ORDER BY o.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Orders retrieved successfully',
            'data' => [
                'orders' => $orders,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]
        ]);
        exit;
        
    } catch (Exception $e) {
        sendError('Failed to get orders: ' . $e->getMessage(), 500);
    }
}

function changePassword($user_id, $conn) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            sendError('Invalid request data', 400);
        }
        
        $required = ['current_password', 'new_password', 'confirm_password'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                sendError("$field is required", 400);
            }
        }
        
        if ($input['new_password'] !== $input['confirm_password']) {
            sendError('New passwords do not match', 400);
        }
        
        if (strlen($input['new_password']) < 6) {
            sendError('Password must be at least 6 characters', 400);
        }
        
        // Get current user password
        $query = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $query->execute([$user_id]);
        $user = $query->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            sendError('User not found', 404);
        }
        
        // Verify current password
        if (!password_verify($input['current_password'], $user['password'])) {
            sendError('Current password is incorrect', 400);
        }
        
        // Hash new password
        $hashedPassword = password_hash($input['new_password'], PASSWORD_DEFAULT);
        
        // Update password
        $updateQuery = $conn->prepare("UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $updateQuery->execute([$hashedPassword, $user_id]);
        
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Password changed successfully'
        ]);
        exit;
        
    } catch (Exception $e) {
        sendError('Failed to change password: ' . $e->getMessage(), 500);
    }
}

// Clean output buffer
ob_end_flush();
?>