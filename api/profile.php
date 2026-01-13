<?php
// profile.php - SIMPLIFIED WORKING VERSION
ob_start();

// CORS Headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Error function
function sendError($message, $code = 400) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $message
    ]);
    exit;
}

// Check auth
if (!isset($_SESSION['user_id'])) {
    sendError('Not authenticated', 401);
}

$user_id = $_SESSION['user_id'];

// Database
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Handle request
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    
    if ($method === 'GET') {
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
                sendError('Invalid action');
        }
    } else if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $input = $_POST;
        }
        
        switch ($input['action'] ?? '') {
            case 'update_profile':
                updateProfile($conn, $user_id, $input);
                break;
            case 'change_password':
                changePassword($conn, $user_id, $input);
                break;
            case 'add_address':
                addAddress($conn, $user_id, $input);
                break;
            default:
                sendError('Invalid action');
        }
    } else {
        sendError('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    sendError('Server error: ' . $e->getMessage(), 500);
}

// ============ FUNCTIONS ============

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
            sendError('User not found', 404);
        }
        
        // Process avatar URL
        if (!empty($user['avatar'])) {
            $user['avatar'] = getAvatarUrl($user['avatar']);
        } else {
            $initials = substr($user['full_name'] ?? 'US', 0, 2);
            $user['avatar'] = "https://ui-avatars.com/api/?name=" . urlencode($initials) . "&background=random&size=400&bold=true";
        }
        
        // Get recent orders
        $orders = getRecentOrders($conn, $user_id, 5);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'user' => $user,
                'recent_orders' => $orders
            ]
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to get profile: ' . $e->getMessage());
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
    
    // Check if file exists
    $fullPath = dirname(dirname(__FILE__)) . '/' . ltrim($avatarPath, '/');
    if (!file_exists($fullPath)) {
        $initials = 'US';
        return "https://ui-avatars.com/api/?name=" . urlencode($initials) . "&background=random&size=400&bold=true";
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
        // Validate
        if (empty($data['full_name'])) {
            sendError('Full name is required');
        }
        
        if (empty($data['email'])) {
            sendError('Email is required');
        }
        
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            sendError('Invalid email format');
        }
        
        // Check email
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([trim($data['email']), $user_id]);
        if ($stmt->fetch()) {
            sendError('Email already in use');
        }
        
        // Update user
        $stmt = $conn->prepare("
            UPDATE users SET 
                full_name = ?, 
                email = ?, 
                phone = ?, 
                address = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        $success = $stmt->execute([
            trim($data['full_name']),
            trim($data['email']),
            $data['phone'] ?? '',
            $data['address'] ?? '',
            $user_id
        ]);
        
        if ($success) {
            // Get updated user
            $stmt = $conn->prepare("
                SELECT id, email, full_name, phone, address, avatar,
                       wallet_balance, member_level, member_points,
                       total_orders, rating, verified, join_date
                FROM users 
                WHERE id = ?
            ");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!empty($user['avatar'])) {
                $user['avatar'] = getAvatarUrl($user['avatar']);
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => [
                    'user' => $user
                ]
            ]);
        } else {
            sendError('Failed to update profile');
        }
        
    } catch (Exception $e) {
        sendError('Update failed: ' . $e->getMessage());
    }
}

function changePassword($conn, $user_id, $data) {
    try {
        // Validate
        if (empty($data['current_password']) || empty($data['new_password']) || empty($data['confirm_password'])) {
            sendError('All password fields are required');
        }
        
        if ($data['new_password'] !== $data['confirm_password']) {
            sendError('New passwords do not match');
        }
        
        if (strlen($data['new_password']) < 6) {
            sendError('Password must be at least 6 characters');
        }
        
        // Get current password
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !password_verify($data['current_password'], $user['password'])) {
            sendError('Current password is incorrect');
        }
        
        // Update password
        $newHash = password_hash($data['new_password'], PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $success = $stmt->execute([$newHash, $user_id]);
        
        if ($success) {
            echo json_encode([
                'success' => true,
                'message' => 'Password changed successfully'
            ]);
        } else {
            sendError('Failed to change password');
        }
        
    } catch (Exception $e) {
        sendError('Password change failed: ' . $e->getMessage());
    }
}

function getAddresses($conn, $user_id) {
    try {
        $stmt = $conn->prepare("
            SELECT id, title, address, city, state, zip_code,
                   is_default, instructions, address_type
            FROM user_addresses 
            WHERE user_id = ? 
            ORDER BY is_default DESC, created_at DESC
        ");
        $stmt->execute([$user_id]);
        $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'addresses' => $addresses
            ]
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to get addresses: ' . $e->getMessage());
    }
}

function addAddress($conn, $user_id, $data) {
    try {
        if (empty($data['title']) || empty($data['address']) || empty($data['city'])) {
            sendError('Title, address, and city are required');
        }
        
        // Check if first address
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM user_addresses WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $is_default = $count == 0 ? 1 : 0;
        
        // Insert
        $stmt = $conn->prepare("
            INSERT INTO user_addresses 
            (user_id, title, address, city, state, zip_code, 
             address_type, is_default, instructions)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                'data' => [
                    'address_id' => $address_id
                ]
            ]);
        } else {
            sendError('Failed to add address');
        }
        
    } catch (Exception $e) {
        sendError('Failed to add address: ' . $e->getMessage());
    }
}

function getOrders($conn, $user_id) {
    try {
        $page = $_GET['page'] ?? 1;
        $limit = $_GET['limit'] ?? 10;
        $offset = ($page - 1) * $limit;
        
        // Get total
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM orders WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
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
                    'page' => (int)$page,
                    'limit' => (int)$limit,
                    'total' => (int)$total,
                    'pages' => ceil($total / $limit)
                ]
            ]
        ]);
        
    } catch (Exception $e) {
        sendError('Failed to get orders: ' . $e->getMessage());
    }
}

// Clean output
ob_end_flush();
?>