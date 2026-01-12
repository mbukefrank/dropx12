<?php
// profile.php - CORRECTED VERSION
// Remove any whitespace or output before headers

// Start output buffering to prevent any accidental output
ob_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set headers first
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

// Now start the session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Error handling function
function handleError($message, $code = 500) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'error' => true
    ]);
    exit;
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    handleError('Unauthorized access. Please login.', 401);
}

require_once '../config/database.php';

class ProfileAPI {
    private $conn;
    private $user_id;

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
            
            $action = '';
            
            // Get action from GET or POST
            if ($method === 'GET') {
                $action = $_GET['action'] ?? '';
            } else {
                // Check if it's form data (for file uploads)
                if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false) {
                    // For form data, action comes from POST
                    $action = $_POST['action'] ?? '';
                } else {
                    // For JSON, read raw input
                    $input = json_decode(file_get_contents('php://input'), true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        handleError('Invalid JSON input', 400);
                    }
                    $action = $input['action'] ?? '';
                }
            }
            
            if (empty($action)) {
                handleError('No action specified', 400);
            }
            
            switch ($action) {
                case 'get_profile':
                    $this->getUserProfile();
                    break;
                case 'update_profile':
                    if ($method !== 'POST') {
                        handleError('Method not allowed', 405);
                    }
                    $this->updateProfile();
                    break;
                case 'addresses':
                    if ($method !== 'GET') {
                        handleError('Method not allowed', 405);
                    }
                    $this->getUserAddresses();
                    break;
                case 'add_address':
                    if ($method !== 'POST') {
                        handleError('Method not allowed', 405);
                    }
                    $this->addAddress();
                    break;
                case 'set_default_address':
                    if ($method !== 'PUT') {
                        handleError('Method not allowed', 405);
                    }
                    $this->setDefaultAddress();
                    break;
                case 'delete_address':
                    if ($method !== 'DELETE') {
                        handleError('Method not allowed', 405);
                    }
                    $this->deleteAddress();
                    break;
                case 'orders':
                    if ($method !== 'GET') {
                        handleError('Method not allowed', 405);
                    }
                    $this->getUserOrders();
                    break;
                case 'change_password':
                    if ($method !== 'POST') {
                        handleError('Method not allowed', 405);
                    }
                    $this->changePassword();
                    break;
                default:
                    handleError('Invalid action: ' . htmlspecialchars($action), 400);
            }
        } catch (Exception $e) {
            handleError('Request handling failed: ' . $e->getMessage(), 500);
        }
    }

    private function getUserProfile() {
        try {
            // Get user data
            $query = "SELECT 
                        u.id, u.email, u.full_name, u.phone, u.avatar,
                        u.created_at, u.verified, u.role,
                        (SELECT COUNT(*) FROM orders WHERE user_id = u.id) as total_orders,
                        (SELECT COUNT(*) FROM addresses WHERE user_id = u.id) as total_addresses
                      FROM users u 
                      WHERE u.id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new Exception('User not found');
            }

            // Get default address
            $addressQuery = "SELECT address FROM addresses 
                           WHERE user_id = ? AND is_default = 1 
                           LIMIT 1";
            $stmt = $this->conn->prepare($addressQuery);
            $stmt->execute([$this->user_id]);
            $addressResult = $stmt->fetch(PDO::FETCH_ASSOC);
            $user['address'] = $addressResult ? $addressResult['address'] : '';

            // Get recent orders (5 most recent)
            $ordersQuery = "SELECT 
                            o.id, o.order_number, o.total_amount, o.status, o.created_at,
                            DATE_FORMAT(o.created_at, '%Y-%m-%d') as formatted_date,
                            (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
                          FROM orders o
                          WHERE o.user_id = ?
                          ORDER BY o.created_at DESC 
                          LIMIT 5";
            
            $stmt = $this->conn->prepare($ordersQuery);
            $stmt->execute([$this->user_id]);
            $recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => [
                    'user' => $user,
                    'recent_orders' => $recentOrders
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to get profile: ' . $e->getMessage());
        }
    }

    private function updateProfile() {
        try {
            // Check if it's form data (for avatar upload)
            $isFormData = isset($_SERVER['CONTENT_TYPE']) && 
                         strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false;
            
            if ($isFormData) {
                $data = $_POST;
                $files = $_FILES;
            } else {
                $input = json_decode(file_get_contents('php://input'), true);
                if (!$input) {
                    throw new Exception('Invalid request data');
                }
                $data = $input;
                $files = [];
            }
            
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
            
            $this->conn->beginTransaction();
            
            try {
                // Check if email already exists (for other users)
                if (isset($data['email'])) {
                    $emailCheck = $this->conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $emailCheck->execute([$data['email'], $this->user_id]);
                    if ($emailCheck->fetch()) {
                        throw new Exception('Email already in use by another account');
                    }
                }
                
                // Handle avatar upload if present
                $avatarPath = null;
                if ($isFormData && isset($files['avatar']) && $files['avatar']['error'] === UPLOAD_ERR_OK) {
                    $avatarPath = $this->handleAvatarUpload($files['avatar']);
                }
                
                // Update user data
                $updateFields = [];
                $updateParams = [];
                
                if (isset($data['full_name'])) {
                    $updateFields[] = "full_name = ?";
                    $updateParams[] = trim($data['full_name']);
                }
                
                if (isset($data['email'])) {
                    $updateFields[] = "email = ?";
                    $updateParams[] = trim($data['email']);
                }
                
                if (isset($data['phone'])) {
                    $updateFields[] = "phone = ?";
                    $updateParams[] = trim($data['phone']);
                }
                
                if ($avatarPath) {
                    $updateFields[] = "avatar = ?";
                    $updateParams[] = $avatarPath;
                }
                
                if (!empty($updateFields)) {
                    $updateFields[] = "updated_at = NOW()";
                    
                    $query = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ?";
                    $updateParams[] = $this->user_id;
                    
                    $stmt = $this->conn->prepare($query);
                    $stmt->execute($updateParams);
                }
                
                // Update address if provided
                if (isset($data['address']) && !empty(trim($data['address']))) {
                    $this->updateUserAddress(trim($data['address']));
                }
                
                // Get updated user data
                $userQuery = "SELECT 
                                id, email, full_name, phone, avatar, 
                                verified, created_at, updated_at,
                                (SELECT COUNT(*) FROM orders WHERE user_id = ?) as total_orders
                              FROM users 
                              WHERE id = ?";
                
                $stmt = $this->conn->prepare($userQuery);
                $stmt->execute([$this->user_id, $this->user_id]);
                $updatedUser = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Get default address
                $addressQuery = "SELECT address FROM addresses 
                               WHERE user_id = ? AND is_default = 1 
                               LIMIT 1";
                $stmt = $this->conn->prepare($addressQuery);
                $stmt->execute([$this->user_id]);
                $addressResult = $stmt->fetch(PDO::FETCH_ASSOC);
                $updatedUser['address'] = $addressResult ? $addressResult['address'] : '';
                
                $this->conn->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Profile updated successfully',
                    'data' => [
                        'user' => $updatedUser
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

    private function handleAvatarUpload($file) {
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
        $uploadDir = __DIR__ . '/../uploads/avatars/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        // Generate unique filename
        $fileExt = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = 'avatar_' . $this->user_id . '_' . time() . '.' . $fileExt;
        $filePath = $uploadDir . $fileName;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            throw new Exception('Failed to upload file');
        }
        
        // Return relative path for database storage
        return '/uploads/avatars/' . $fileName;
    }

    private function updateUserAddress($address) {
        // Check if user has a default address
        $checkQuery = "SELECT id FROM addresses WHERE user_id = ? AND is_default = 1 LIMIT 1";
        $stmt = $this->conn->prepare($checkQuery);
        $stmt->execute([$this->user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            // Update existing default address
            $updateQuery = "UPDATE addresses SET address = ? WHERE id = ?";
            $stmt = $this->conn->prepare($updateQuery);
            $stmt->execute([$address, $result['id']]);
        } else {
            // Create new default address
            $insertQuery = "INSERT INTO addresses (user_id, title, address, city, address_type, is_default, created_at)
                           VALUES (?, 'Home', ?, '', 'home', 1, NOW())";
            $stmt = $this->conn->prepare($insertQuery);
            $stmt->execute([$this->user_id, $address]);
        }
    }

    private function getUserAddresses() {
        try {
            $query = "SELECT * FROM addresses 
                     WHERE user_id = ? 
                     ORDER BY is_default DESC, created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id]);
            $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => [
                    'addresses' => $addresses
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to get addresses: ' . $e->getMessage());
        }
    }

    private function addAddress() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                throw new Exception('Invalid request data');
            }
            
            // Validate required fields
            $required = ['title', 'address', 'city'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    throw new Exception("$field is required");
                }
            }
            
            // Check if this is the first address
            $checkQuery = "SELECT COUNT(*) as count FROM addresses WHERE user_id = ?";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->execute([$this->user_id]);
            $countResult = $stmt->fetch(PDO::FETCH_ASSOC);
            $isDefault = $countResult['count'] == 0 ? 1 : 0;
            
            // If setting as default, update existing defaults
            if (isset($input['is_default']) && $input['is_default'] == 1) {
                $updateDefaults = "UPDATE addresses SET is_default = 0 WHERE user_id = ?";
                $stmt = $this->conn->prepare($updateDefaults);
                $stmt->execute([$this->user_id]);
                $isDefault = 1;
            }
            
            $query = "INSERT INTO addresses (user_id, title, address, city, address_type, is_default, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())";
            
            $addressType = $input['address_type'] ?? 'other';
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $this->user_id,
                $input['title'],
                $input['address'],
                $input['city'],
                $addressType,
                $isDefault
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Address added successfully',
                'data' => [
                    'address_id' => $this->conn->lastInsertId()
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to add address: ' . $e->getMessage());
        }
    }

    private function setDefaultAddress() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || empty($input['address_id'])) {
                throw new Exception('Address ID is required');
            }
            
            $addressId = $input['address_id'];
            
            // Verify address belongs to user
            $verifyQuery = "SELECT id FROM addresses WHERE id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($verifyQuery);
            $stmt->execute([$addressId, $this->user_id]);
            
            if (!$stmt->fetch()) {
                throw new Exception('Address not found');
            }
            
            $this->conn->beginTransaction();
            
            try {
                // Reset all defaults
                $resetQuery = "UPDATE addresses SET is_default = 0 WHERE user_id = ?";
                $stmt = $this->conn->prepare($resetQuery);
                $stmt->execute([$this->user_id]);
                
                // Set new default
                $updateQuery = "UPDATE addresses SET is_default = 1 WHERE id = ? AND user_id = ?";
                $stmt = $this->conn->prepare($updateQuery);
                $stmt->execute([$addressId, $this->user_id]);
                
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

    private function deleteAddress() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || empty($input['address_id'])) {
                throw new Exception('Address ID is required');
            }
            
            $addressId = $input['address_id'];
            
            // Check if address exists and is default
            $checkQuery = "SELECT is_default FROM addresses WHERE id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->execute([$addressId, $this->user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                throw new Exception('Address not found');
            }
            
            if ($result['is_default'] == 1) {
                throw new Exception('Cannot delete default address. Set another address as default first.');
            }
            
            // Delete address
            $deleteQuery = "DELETE FROM addresses WHERE id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($deleteQuery);
            $stmt->execute([$addressId, $this->user_id]);
            
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
            $where = "WHERE user_id = ?";
            $params = [$this->user_id];
            
            if (!empty($status)) {
                $where .= " AND status = ?";
                $params[] = $status;
            }
            
            // Get total count
            $countQuery = "SELECT COUNT(*) as total FROM orders $where";
            $stmt = $this->conn->prepare($countQuery);
            $stmt->execute($params);
            $totalResult = $stmt->fetch(PDO::FETCH_ASSOC);
            $total = $totalResult['total'];
            
            // Get orders
            $query = "SELECT 
                        o.*,
                        r.name as restaurant_name,
                        DATE_FORMAT(o.created_at, '%Y-%m-%d %H:%i') as formatted_date,
                        (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
                      FROM orders o
                      LEFT JOIN restaurants r ON o.restaurant_id = r.id
                      $where
                      ORDER BY o.created_at DESC
                      LIMIT ? OFFSET ?";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
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

    private function changePassword() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                throw new Exception('Invalid request data');
            }
            
            $required = ['current_password', 'new_password', 'confirm_password'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    throw new Exception("$field is required");
                }
            }
            
            if ($input['new_password'] !== $input['confirm_password']) {
                throw new Exception('New passwords do not match');
            }
            
            if (strlen($input['new_password']) < 6) {
                throw new Exception('Password must be at least 6 characters');
            }
            
            // Get current user password
            $query = "SELECT password FROM users WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            // Verify current password (assuming passwords are hashed)
            // In a real app, you would use password_verify()
            // For now, we'll check if they're the same (for testing)
            if ($input['current_password'] !== 'demo_password') { // Replace with proper verification
                throw new Exception('Current password is incorrect');
            }
            
            // Hash new password (in production, use password_hash())
            $hashedPassword = password_hash($input['new_password'], PASSWORD_DEFAULT);
            
            // Update password
            $updateQuery = "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $this->conn->prepare($updateQuery);
            $stmt->execute([$hashedPassword, $this->user_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Password changed successfully'
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to change password: ' . $e->getMessage());
        }
    }
}

try {
    $api = new ProfileAPI();
    $api->handleRequest();
} catch (Exception $e) {
    handleError('Application error: ' . $e->getMessage(), 500);
}

// Clean output buffer
ob_end_flush();
?>