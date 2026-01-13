<?php
// profile.php - FINAL WORKING VERSION
ob_start();

// CORS Headers (exactly as you have them)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://dropx-frontend-seven.vercel.app');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Database connection
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Verify user exists
    $checkStmt = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
    $checkStmt->execute([$user_id]);
    if (!$checkStmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit();
    }
    
    // Handle request based on method
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'GET') {
        handleGetRequest($conn, $user_id);
    } elseif ($method === 'POST') {
        handlePostRequest($conn, $user_id);
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

// ============ GET REQUEST HANDLER ============
function handleGetRequest($conn, $user_id) {
    $action = $_GET['action'] ?? 'get_profile';
    
    switch ($action) {
        case 'get_profile':
            getProfile($conn, $user_id);
            break;
        case 'addresses':
            getAddresses($conn, $user_id);
            break;
        case 'orders':
            getOrders($conn, $user_id);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

// ============ POST REQUEST HANDLER ============
function handlePostRequest($conn, $user_id) {
    // Check if it's multipart/form-data (file upload)
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'multipart/form-data') !== false) {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'update_profile') {
            updateProfileWithAvatar($conn, $user_id, $_POST, $_FILES);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action for file upload']);
        }
        return;
    }
    
    // Otherwise handle JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        return;
    }
    
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'update_profile':
            updateProfile($conn, $user_id, $input);
            break;
        case 'change_password':
            changePassword($conn, $user_id, $input);
            break;
        case 'add_address':
            addAddress($conn, $user_id, $input);
            break;
        case 'delete_address':
            deleteAddress($conn, $user_id, $input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

// ============ PROFILE FUNCTIONS ============
function getProfile($conn, $user_id) {
    try {
        // Get user data
        $stmt = $conn->prepare("
            SELECT id, email, full_name, phone, address, avatar,
                   wallet_balance, member_level, member_points,
                   total_orders, rating, verified, join_date
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception('User not found');
        }
        
        // Handle avatar URL
        if (!empty($user['avatar'])) {
            $user['avatar'] = getAvatarUrl($user['avatar']);
        } else {
            $initials = substr($user['full_name'] ?? 'US', 0, 2);
            $user['avatar'] = "https://ui-avatars.com/api/?name=" . urlencode($initials) . "&background=random&size=150&bold=true";
        }
        
        // Get 5 most recent orders
        $orders = getRecentOrders($conn, $user_id, 5);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'user' => $user,
                'recent_orders' => $orders
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to get profile: ' . $e->getMessage()]);
    }
}

function getAvatarUrl($avatarPath) {
    $base_url = 'https://dropxbackend-production.up.railway.app';
    
    if (empty($avatarPath)) {
        return $base_url . '/uploads/avatars/default.png';
    }
    
    if (strpos($avatarPath, 'http') === 0) {
        return $avatarPath;
    }
    
    // Handle missing files gracefully
    $fullPath = dirname(dirname(__FILE__)) . '/' . ltrim($avatarPath, '/');
    if (!file_exists($fullPath)) {
        $initials = 'US';
        return "https://ui-avatars.com/api/?name=" . urlencode($initials) . "&background=random&size=150&bold=true";
    }
    
    return $base_url . '/' . ltrim($avatarPath, '/');
}

function getRecentOrders($conn, $user_id, $limit) {
    try {
        $stmt = $conn->prepare("
            SELECT o.id, o.order_number, o.total_amount, o.status, 
                   DATE_FORMAT(o.created_at, '%Y-%m-%d') as formatted_date,
                   r.name as restaurant_name
            FROM orders o
            LEFT JOIN restaurants r ON o.restaurant_id = r.id
            WHERE o.user_id = ?
            ORDER BY o.created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$user_id, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function updateProfile($conn, $user_id, $data) {
    try {
        // Validate required fields
        if (empty($data['full_name']) || empty($data['email'])) {
            throw new Exception('Full name and email are required');
        }
        
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format');
        }
        
        // Check if email is already used by another user
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $checkStmt->execute([trim($data['email']), $user_id]);
        if ($checkStmt->fetch()) {
            throw new Exception('Email already in use by another account');
        }
        
        // Update profile
        $updateStmt = $conn->prepare("
            UPDATE users SET 
                full_name = ?, 
                email = ?, 
                phone = ?, 
                address = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        $success = $updateStmt->execute([
            trim($data['full_name']),
            trim($data['email']),
            $data['phone'] ?? '',
            $data['address'] ?? '',
            $user_id
        ]);
        
        if ($success) {
            // Get updated user
            $userStmt = $conn->prepare("
                SELECT id, email, full_name, phone, address, avatar,
                       wallet_balance, member_level, member_points,
                       total_orders, rating, verified, join_date
                FROM users 
                WHERE id = ?
            ");
            $userStmt->execute([$user_id]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!empty($user['avatar'])) {
                $user['avatar'] = getAvatarUrl($user['avatar']);
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => ['user' => $user]
            ]);
        } else {
            throw new Exception('Failed to update database');
        }
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function updateProfileWithAvatar($conn, $user_id, $data, $files) {
    try {
        // Validate required fields
        if (empty($data['full_name']) || empty($data['email'])) {
            throw new Exception('Full name and email are required');
        }
        
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format');
        }
        
        // Check email
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $checkStmt->execute([trim($data['email']), $user_id]);
        if ($checkStmt->fetch()) {
            throw new Exception('Email already in use by another account');
        }
        
        // Handle avatar upload if provided
        $avatarPath = null;
        if (isset($files['avatar']) && $files['avatar']['error'] === UPLOAD_ERR_OK) {
            $avatarPath = uploadAvatar($files['avatar'], $user_id);
        }
        
        // Build update query
        $updateFields = ['full_name = ?', 'email = ?', 'updated_at = CURRENT_TIMESTAMP'];
        $params = [trim($data['full_name']), trim($data['email'])];
        
        if (isset($data['phone'])) {
            $updateFields[] = 'phone = ?';
            $params[] = $data['phone'];
        }
        
        if (isset($data['address'])) {
            $updateFields[] = 'address = ?';
            $params[] = $data['address'];
        }
        
        if ($avatarPath) {
            $updateFields[] = 'avatar = ?';
            $params[] = $avatarPath;
        }
        
        $params[] = $user_id; // For WHERE clause
        
        // Update user
        $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $updateStmt = $conn->prepare($sql);
        $success = $updateStmt->execute($params);
        
        if ($success) {
            // Get updated user
            $userStmt = $conn->prepare("
                SELECT id, email, full_name, phone, address, avatar,
                       wallet_balance, member_level, member_points,
                       total_orders, rating, verified, join_date
                FROM users 
                WHERE id = ?
            ");
            $userStmt->execute([$user_id]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!empty($user['avatar'])) {
                $user['avatar'] = getAvatarUrl($user['avatar']);
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => ['user' => $user]
            ]);
        } else {
            throw new Exception('Failed to update database');
        }
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function uploadAvatar($file, $user_id) {
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/jpg'];
    $maxSize = 2 * 1024 * 1024; // 2MB
    
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('Invalid file type. Allowed: JPG, PNG, GIF, WebP');
    }
    
    if ($file['size'] > $maxSize) {
        throw new Exception('File size exceeds 2MB limit');
    }
    
    $uploadDir = dirname(dirname(__FILE__)) . '/uploads/avatars/';
    
    // Create directory if it doesn't exist
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate unique filename
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'avatar_' . $user_id . '_' . time() . '.' . $ext;
    $filepath = $uploadDir . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to upload file');
    }
    
    return '/uploads/avatars/' . $filename;
}

function changePassword($conn, $user_id, $data) {
    try {
        // Validate
        if (empty($data['current_password']) || empty($data['new_password']) || empty($data['confirm_password'])) {
            throw new Exception('All password fields are required');
        }
        
        if ($data['new_password'] !== $data['confirm_password']) {
            throw new Exception('New passwords do not match');
        }
        
        if (strlen($data['new_password']) < 6) {
            throw new Exception('Password must be at least 6 characters');
        }
        
        // Get current password hash
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !password_verify($data['current_password'], $user['password'])) {
            throw new Exception('Current password is incorrect');
        }
        
        // Update password
        $newHash = password_hash($data['new_password'], PASSWORD_DEFAULT);
        $updateStmt = $conn->prepare("UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $success = $updateStmt->execute([$newHash, $user_id]);
        
        if ($success) {
            echo json_encode([
                'success' => true,
                'message' => 'Password changed successfully'
            ]);
        } else {
            throw new Exception('Failed to update password');
        }
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function getAddresses($conn, $user_id) {
    try {
        $stmt = $conn->prepare("
            SELECT id, title, address, city, state, zip_code,
                   is_default, instructions, address_type,
                   DATE_FORMAT(created_at, '%Y-%m-%d') as created_date
            FROM user_addresses 
            WHERE user_id = ? 
            ORDER BY is_default DESC, created_at DESC
        ");
        $stmt->execute([$user_id]);
        $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => ['addresses' => $addresses]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to get addresses: ' . $e->getMessage()]);
    }
}

function addAddress($conn, $user_id, $data) {
    try {
        if (empty($data['title']) || empty($data['address']) || empty($data['city'])) {
            throw new Exception('Title, address, and city are required');
        }
        
        // Check if this is the first address
        $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM user_addresses WHERE user_id = ?");
        $checkStmt->execute([$user_id]);
        $count = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'];
        $is_default = $count == 0 ? 1 : 0;
        
        // If setting as default, clear other defaults
        if (isset($data['is_default']) && $data['is_default']) {
            $clearStmt = $conn->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?");
            $clearStmt->execute([$user_id]);
            $is_default = 1;
        }
        
        // Insert new address
        $stmt = $conn->prepare("
            INSERT INTO user_addresses 
            (user_id, title, address, city, state, zip_code, 
             address_type, is_default, instructions, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        
        $success = $stmt->execute([
            $user_id,
            $data['title'],
            $data['address'],
            $data['city'],
            $data['state'] ?? '',
            $data['zip_code'] ?? '',
            $data['address_type'] ?? 'other',
            $is_default,
            $data['instructions'] ?? ''
        ]);
        
        if ($success) {
            $address_id = $conn->lastInsertId();
            echo json_encode([
                'success' => true,
                'message' => 'Address added successfully',
                'data' => ['address_id' => $address_id]
            ]);
        } else {
            throw new Exception('Failed to insert address');
        }
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function deleteAddress($conn, $user_id, $data) {
    try {
        if (empty($data['address_id'])) {
            throw new Exception('Address ID is required');
        }
        
        $address_id = $data['address_id'];
        
        // Check if exists and is default
        $checkStmt = $conn->prepare("SELECT is_default FROM user_addresses WHERE id = ? AND user_id = ?");
        $checkStmt->execute([$address_id, $user_id]);
        $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            throw new Exception('Address not found');
        }
        
        if ($result['is_default'] == 1) {
            throw new Exception('Cannot delete default address');
        }
        
        // Delete address
        $deleteStmt = $conn->prepare("DELETE FROM user_addresses WHERE id = ? AND user_id = ?");
        $success = $deleteStmt->execute([$address_id, $user_id]);
        
        if ($success) {
            echo json_encode([
                'success' => true,
                'message' => 'Address deleted successfully'
            ]);
        } else {
            throw new Exception('Failed to delete address');
        }
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function getOrders($conn, $user_id) {
    try {
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;
        
        // Get total count
        $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM orders WHERE user_id = ?");
        $countStmt->execute([$user_id]);
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Get orders
        $stmt = $conn->prepare("
            SELECT o.*, r.name as restaurant_name,
                   DATE_FORMAT(o.created_at, '%Y-%m-%d %H:%i') as formatted_date
            FROM orders o
            LEFT JOIN restaurants r ON o.restaurant_id = r.id
            WHERE o.user_id = ?
            ORDER BY o.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$user_id, $limit, $offset]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'orders' => $orders,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int)$total,
                    'pages' => ceil($total / $limit)
                ]
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to get orders: ' . $e->getMessage()]);
    }
}

// Clean output buffer
ob_end_flush();
?>