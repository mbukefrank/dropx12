<?php
// profile.php - CORRECTED VERSION (Like Wallet API)
// Remove any whitespace or output before headers

// Start output buffering to prevent any accidental output
ob_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============ CORS HEADERS ============
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://dropx-frontend-seven.vercel.app');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle OPTIONS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============ SESSION CONFIGURATION ============
// Important: Set session cookie parameters BEFORE starting session
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', true);

// Now start the session
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 86400,
        'cookie_secure' => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'None'
    ]);
}

// ============ ERROR HANDLING ============
function handleError($message, $code = 500) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'error' => true
    ]);
    exit;
}

// ============ CHECK AUTHENTICATION ============
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    handleError('Authentication required. Please login.', 401);
}

$user_id = $_SESSION['user_id'];

// ============ INCLUDE DATABASE ============
require_once __DIR__ . '/../config/database.php';

// ============ MAIN API CLASS ============
class ProfileAPI {
    private $conn;
    private $user_id;
    private $base_url = 'https://dropxbackend-production.up.railway.app';

    public function __construct() {
        try {
            $database = new Database();
            $this->conn = $database->getConnection();
            $this->user_id = $_SESSION['user_id'];
            
            // Validate user exists
            $stmt = $this->conn->prepare("SELECT id FROM users WHERE id = ?");
            $stmt->execute([$this->user_id]);
            if (!$stmt->fetch()) {
                handleError('User not found', 404);
            }
        } catch (Exception $e) {
            handleError('Database connection failed: ' . $e->getMessage(), 500);
        }
    }

    public function handleRequest() {
        try {
            $method = $_SERVER['REQUEST_METHOD'];
            
            // Handle different content types
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            $isMultipart = strpos($contentType, 'multipart/form-data') !== false;
            
            if ($method === 'GET') {
                $action = $_GET['action'] ?? 'get_profile';
                $this->handleGetRequest($action);
            } else if ($isMultipart) {
                // Handle form-data (file uploads)
                $action = $_POST['action'] ?? '';
                $this->handleMultipartRequest($action);
            } else {
                // Handle JSON requests
                $input = file_get_contents('php://input');
                $data = json_decode($input, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    handleError('Invalid JSON input', 400);
                }
                
                $action = $data['action'] ?? '';
                
                switch ($method) {
                    case 'POST':
                        $this->handlePostRequest($action, $data);
                        break;
                    case 'PUT':
                        $this->handlePutRequest($action, $data);
                        break;
                    case 'DELETE':
                        $this->handleDeleteRequest($action, $data);
                        break;
                    default:
                        handleError('Method not allowed', 405);
                }
            }
        } catch (Exception $e) {
            handleError('Request handling failed: ' . $e->getMessage(), 500);
        }
    }

    private function handleGetRequest($action) {
        switch ($action) {
            case 'get_profile':
                $this->getUserProfile();
                break;
            case 'addresses':
                $this->getUserAddresses();
                break;
            case 'orders':
                $this->getUserOrders();
                break;
            default:
                handleError('Invalid action: ' . htmlspecialchars($action), 400);
        }
    }

    private function handlePostRequest($action, $data) {
        switch ($action) {
            case 'update_profile':
                $this->updateProfile($data);
                break;
            case 'change_password':
                $this->changePassword($data);
                break;
            case 'add_address':
                $this->addAddress($data);
                break;
            default:
                handleError('Invalid action: ' . htmlspecialchars($action), 400);
        }
    }

    private function handlePutRequest($action, $data) {
        switch ($action) {
            case 'set_default_address':
                $this->setDefaultAddress($data);
                break;
            case 'update_address':
                $this->updateAddress($data);
                break;
            default:
                handleError('Invalid action: ' . htmlspecialchars($action), 400);
        }
    }

    private function handleDeleteRequest($action, $data) {
        switch ($action) {
            case 'delete_address':
                $this->deleteAddress($data);
                break;
            default:
                handleError('Invalid action: ' . htmlspecialchars($action), 400);
        }
    }

    private function handleMultipartRequest($action) {
        switch ($action) {
            case 'update_profile':
                $this->updateProfileWithAvatar($_POST, $_FILES);
                break;
            default:
                handleError('Invalid action for multipart request', 400);
        }
    }

    // ============ PROFILE FUNCTIONS ============

    private function getUserProfile() {
        try {
            // Get user data
            $query = "SELECT 
                        id, email, full_name, phone, avatar,
                        wallet_balance, member_level, member_points,
                        total_orders, rating, verified,
                        DATE_FORMAT(created_at, '%Y-%m-%d') as join_date
                      FROM users 
                      WHERE id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            // Convert avatar path to full URL
            if (!empty($user['avatar'])) {
                if (!strpos($user['avatar'], 'http') === 0) {
                    $avatar_path = ltrim($user['avatar'], '/');
                    $user['avatar'] = $this->base_url . '/' . $avatar_path;
                }
            }
            
            // Get default address
            $addressQuery = "SELECT address, city 
                            FROM user_addresses 
                            WHERE user_id = ? AND is_default = 1 
                            LIMIT 1";
            $stmt = $this->conn->prepare($addressQuery);
            $stmt->execute([$this->user_id]);
            $address = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($address) {
                $user['address'] = $address['address'];
                if ($address['city']) {
                    $user['address'] .= ', ' . $address['city'];
                }
            } else {
                $user['address'] = '';
            }
            
            // Get recent orders
            $ordersQuery = "SELECT 
                            o.id, o.order_number, o.total_amount, o.status, 
                            DATE_FORMAT(o.created_at, '%Y-%m-%d') as formatted_date,
                            r.name as restaurant_name,
                            (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
                          FROM orders o
                          LEFT JOIN restaurants r ON o.restaurant_id = r.id
                          WHERE o.user_id = ?
                          ORDER BY o.created_at DESC 
                          LIMIT 5";
            
            $stmt = $this->conn->prepare($ordersQuery);
            $stmt->execute([$this->user_id]);
            $recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'message' => 'Profile retrieved successfully',
                'data' => [
                    'user' => $user,
                    'recent_orders' => $recent_orders
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to get profile: ' . $e->getMessage());
        }
    }

    private function updateProfile($data) {
        // This handles JSON updates (no file upload)
        $this->updateProfileData($data, null);
    }

    private function updateProfileWithAvatar($data, $files) {
        // This handles multipart/form-data with file upload
        $this->updateProfileData($data, $files);
    }

    private function updateProfileData($data, $files = null) {
        try {
            // Validate required fields
            if (empty($data['full_name'])) {
                throw new Exception('Full name is required');
            }
            
            if (empty($data['email'])) {
                throw new Exception('Email is required');
            }
            
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid email format');
            }
            
            // Check if email exists for another user
            $checkQuery = "SELECT id FROM users WHERE email = ? AND id != ?";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->execute([trim($data['email']), $this->user_id]);
            if ($stmt->fetch()) {
                throw new Exception('Email already in use by another account');
            }
            
            $this->conn->beginTransaction();
            
            try {
                // Handle avatar upload
                $avatar_url = null;
                if ($files && isset($files['avatar']) && $files['avatar']['error'] === UPLOAD_ERR_OK) {
                    $avatar_url = $this->uploadAvatar($files['avatar']);
                }
                
                // Build update query
                $updateFields = [];
                $params = [];
                
                $updateFields[] = "full_name = ?";
                $params[] = trim($data['full_name']);
                
                $updateFields[] = "email = ?";
                $params[] = trim($data['email']);
                
                if (isset($data['phone'])) {
                    $updateFields[] = "phone = ?";
                    $params[] = trim($data['phone']);
                }
                
                if ($avatar_url) {
                    $updateFields[] = "avatar = ?";
                    $params[] = $avatar_url;
                }
                
                $updateFields[] = "updated_at = CURRENT_TIMESTAMP";
                
                // Update user
                $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ?";
                $params[] = $this->user_id;
                
                $stmt = $this->conn->prepare($sql);
                $stmt->execute($params);
                
                // Update address if provided
                if (isset($data['address']) && !empty(trim($data['address']))) {
                    $this->updateUserAddress(trim($data['address']));
                }
                
                // Get updated user data
                $userQuery = "SELECT 
                                id, email, full_name, phone, avatar,
                                wallet_balance, total_orders, rating, verified,
                                DATE_FORMAT(created_at, '%Y-%m-%d') as join_date
                              FROM users 
                              WHERE id = ?";
                
                $stmt = $this->conn->prepare($userQuery);
                $stmt->execute([$this->user_id]);
                $updated_user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Convert avatar path to full URL
                if (!empty($updated_user['avatar'])) {
                    if (!strpos($updated_user['avatar'], 'http') === 0) {
                        $avatar_path = ltrim($updated_user['avatar'], '/');
                        $updated_user['avatar'] = $this->base_url . '/' . $avatar_path;
                    }
                }
                
                // Get default address
                $addrQuery = "SELECT address, city 
                             FROM user_addresses 
                             WHERE user_id = ? AND is_default = 1 
                             LIMIT 1";
                $stmt = $this->conn->prepare($addrQuery);
                $stmt->execute([$this->user_id]);
                $address = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($address) {
                    $updated_user['address'] = $address['address'];
                    if ($address['city']) {
                        $updated_user['address'] .= ', ' . $address['city'];
                    }
                } else {
                    $updated_user['address'] = '';
                }
                
                $this->conn->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Profile updated successfully',
                    'data' => [
                        'user' => $updated_user
                    ]
                ]);
                
            } catch (Exception $e) {
                $this->conn->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            throw new Exception('Profile update failed: ' . $e->getMessage());
        }
    }

    private function uploadAvatar($file) {
        try {
            // Validate file
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/jpg'];
            $max_size = 5 * 1024 * 1024; // 5MB
            
            if (!in_array($file['type'], $allowed_types)) {
                throw new Exception('Invalid file type');
            }
            
            if ($file['size'] > $max_size) {
                throw new Exception('File size exceeds 5MB limit');
            }
            
            // Get current directory (api/)
            $current_dir = dirname(__FILE__);
            
            // Go up one level to project root
            $root_dir = dirname($current_dir);
            
            // Define upload directory path
            $upload_dir = $root_dir . '/uploads/avatars/';
            
            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                if (!mkdir($upload_dir, 0755, true)) {
                    throw new Exception('Failed to create upload directory');
                }
            }
            
            // Generate unique filename
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $filename = 'avatar_' . $this->user_id . '_' . time() . '.' . $ext;
            $filepath = $upload_dir . $filename;
            
            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                throw new Exception('Failed to upload file');
            }
            
            // Set proper permissions
            chmod($filepath, 0644);
            
            // Return relative path for database
            return '/uploads/avatars/' . $filename;
            
        } catch (Exception $e) {
            throw new Exception('Avatar upload failed: ' . $e->getMessage());
        }
    }

    private function updateUserAddress($address) {
        try {
            // Check if user has default address
            $checkQuery = "SELECT id FROM user_addresses 
                          WHERE user_id = ? AND is_default = 1 
                          LIMIT 1";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->execute([$this->user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                // Update existing
                $updateQuery = "UPDATE user_addresses 
                               SET address = ?, updated_at = CURRENT_TIMESTAMP 
                               WHERE id = ?";
                $stmt = $this->conn->prepare($updateQuery);
                $stmt->execute([$address, $result['id']]);
            } else {
                // Create new
                $insertQuery = "INSERT INTO user_addresses 
                               (user_id, title, address, city, address_type, is_default, created_at)
                               VALUES (?, 'Home', ?, '', 'home', 1, CURRENT_TIMESTAMP)";
                $stmt = $this->conn->prepare($insertQuery);
                $stmt->execute([$this->user_id, $address]);
            }
        } catch (Exception $e) {
            // Address update is not critical, continue
            error_log('Address update failed: ' . $e->getMessage());
        }
    }

    private function getUserAddresses() {
        try {
            $query = "SELECT 
                        id, title, address, city, state, zip_code,
                        latitude, longitude, is_default, instructions,
                        address_type,
                        DATE_FORMAT(created_at, '%Y-%m-%d') as created_date
                      FROM user_addresses 
                      WHERE user_id = ? 
                      ORDER BY is_default DESC, created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id]);
            $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'message' => 'Addresses retrieved successfully',
                'data' => [
                    'addresses' => $addresses
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to get addresses: ' . $e->getMessage());
        }
    }

    private function addAddress($data) {
        try {
            // Validate required fields
            if (empty($data['title']) || empty($data['address']) || empty($data['city'])) {
                throw new Exception('Title, address, and city are required');
            }
            
            // Check if first address
            $checkQuery = "SELECT COUNT(*) as count FROM user_addresses WHERE user_id = ?";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->execute([$this->user_id]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            $is_default = $count == 0 ? 1 : 0;
            
            // If setting as default, clear others
            if (isset($data['is_default']) && $data['is_default']) {
                $clearQuery = "UPDATE user_addresses SET is_default = 0 WHERE user_id = ?";
                $stmt = $this->conn->prepare($clearQuery);
                $stmt->execute([$this->user_id]);
                $is_default = 1;
            }
            
            $query = "INSERT INTO user_addresses 
                     (user_id, title, address, city, state, zip_code, 
                      address_type, is_default, instructions, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)";
            
            $state = $data['state'] ?? '';
            $zip_code = $data['zip_code'] ?? '';
            $address_type = $data['address_type'] ?? 'other';
            $instructions = $data['instructions'] ?? '';
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $this->user_id,
                $data['title'],
                $data['address'],
                $data['city'],
                $state,
                $zip_code,
                $address_type,
                $is_default,
                $instructions
            ]);
            
            $address_id = $this->conn->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'Address added successfully',
                'data' => [
                    'address_id' => $address_id
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to add address: ' . $e->getMessage());
        }
    }

    private function setDefaultAddress($data) {
        try {
            if (empty($data['address_id'])) {
                throw new Exception('Address ID is required');
            }
            
            $address_id = $data['address_id'];
            
            // Verify ownership
            $checkQuery = "SELECT id FROM user_addresses WHERE id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->execute([$address_id, $this->user_id]);
            if (!$stmt->fetch()) {
                throw new Exception('Address not found');
            }
            
            $this->conn->beginTransaction();
            
            try {
                // Clear all defaults
                $clearQuery = "UPDATE user_addresses SET is_default = 0 WHERE user_id = ?";
                $stmt = $this->conn->prepare($clearQuery);
                $stmt->execute([$this->user_id]);
                
                // Set new default
                $updateQuery = "UPDATE user_addresses SET is_default = 1 WHERE id = ? AND user_id = ?";
                $stmt = $this->conn->prepare($updateQuery);
                $stmt->execute([$address_id, $this->user_id]);
                
                $this->conn->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Default address updated successfully'
                ]);
                
            } catch (Exception $e) {
                $this->conn->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            throw new Exception('Failed to set default address: ' . $e->getMessage());
        }
    }

    private function updateAddress($data) {
        try {
            if (empty($data['address_id'])) {
                throw new Exception('Address ID is required');
            }
            
            $address_id = $data['address_id'];
            
            // Verify ownership
            $checkQuery = "SELECT id FROM user_addresses WHERE id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->execute([$address_id, $this->user_id]);
            if (!$stmt->fetch()) {
                throw new Exception('Address not found');
            }
            
            // Build update
            $updates = [];
            $params = [];
            
            if (isset($data['title'])) {
                $updates[] = "title = ?";
                $params[] = $data['title'];
            }
            
            if (isset($data['address'])) {
                $updates[] = "address = ?";
                $params[] = $data['address'];
            }
            
            if (isset($data['city'])) {
                $updates[] = "city = ?";
                $params[] = $data['city'];
            }
            
            if (isset($data['state'])) {
                $updates[] = "state = ?";
                $params[] = $data['state'];
            }
            
            if (isset($data['zip_code'])) {
                $updates[] = "zip_code = ?";
                $params[] = $data['zip_code'];
            }
            
            if (isset($data['address_type'])) {
                $updates[] = "address_type = ?";
                $params[] = $data['address_type'];
            }
            
            if (isset($data['instructions'])) {
                $updates[] = "instructions = ?";
                $params[] = $data['instructions'];
            }
            
            $updates[] = "updated_at = CURRENT_TIMESTAMP";
            
            $sql = "UPDATE user_addresses SET " . implode(", ", $updates) . " WHERE id = ? AND user_id = ?";
            $params[] = $address_id;
            $params[] = $this->user_id;
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            
            echo json_encode([
                'success' => true,
                'message' => 'Address updated successfully'
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to update address: ' . $e->getMessage());
        }
    }

    private function deleteAddress($data) {
        try {
            if (empty($data['address_id'])) {
                throw new Exception('Address ID is required');
            }
            
            $address_id = $data['address_id'];
            
            // Check if exists and is default
            $checkQuery = "SELECT is_default FROM user_addresses WHERE id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->execute([$address_id, $this->user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                throw new Exception('Address not found');
            }
            
            if ($result['is_default'] == 1) {
                throw new Exception('Cannot delete default address');
            }
            
            // Delete address
            $deleteQuery = "DELETE FROM user_addresses WHERE id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($deleteQuery);
            $stmt->execute([$address_id, $this->user_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Address deleted successfully'
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to delete address: ' . $e->getMessage());
        }
    }

    private function getUserOrders() {
        try {
            $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
            $status = $_GET['status'] ?? '';
            
            $offset = ($page - 1) * $limit;
            
            // Build query
            $where = "WHERE o.user_id = ?";
            $params = [$this->user_id];
            
            if (!empty($status)) {
                $where .= " AND o.status = ?";
                $params[] = $status;
            }
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM orders o $where";
            $countStmt = $this->conn->prepare($countSql);
            $countStmt->execute($params);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Get orders
            $sql = "SELECT 
                    o.*,
                    r.name as restaurant_name,
                    r.image as restaurant_image,
                    DATE_FORMAT(o.created_at, '%Y-%m-%d %H:%i') as formatted_date,
                    (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
                  FROM orders o
                  LEFT JOIN restaurants r ON o.restaurant_id = r.id
                  $where
                  ORDER BY o.created_at DESC
                  LIMIT ? OFFSET ?";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
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
            
        } catch (Exception $e) {
            throw new Exception('Failed to get orders: ' . $e->getMessage());
        }
    }

    private function changePassword($data) {
        try {
            $required = ['current_password', 'new_password', 'confirm_password'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("$field is required");
                }
            }
            
            if ($data['new_password'] !== $data['confirm_password']) {
                throw new Exception('New passwords do not match');
            }
            
            if (strlen($data['new_password']) < 6) {
                throw new Exception('Password must be at least 6 characters');
            }
            
            // Get current password hash
            $query = "SELECT password FROM users WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            // Verify current password
            if (!password_verify($data['current_password'], $user['password'])) {
                throw new Exception('Current password is incorrect');
            }
            
            // Hash new password
            $new_hash = password_hash($data['new_password'], PASSWORD_DEFAULT);
            
            // Update password
            $updateQuery = "UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            $stmt = $this->conn->prepare($updateQuery);
            $stmt->execute([$new_hash, $this->user_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Password changed successfully'
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to change password: ' . $e->getMessage());
        }
    }
}

// ============ MAIN EXECUTION ============
try {
    $api = new ProfileAPI();
    $api->handleRequest();
} catch (Exception $e) {
    handleError('Application error: ' . $e->getMessage(), 500);
}

// Clean output buffer
ob_end_flush();
?>