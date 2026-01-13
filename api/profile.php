<?php
// profile.php - COMPLETE WORKING VERSION WITH AVATAR HANDLING
ob_start();

// ============ CORS HEADERS ============
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://dropx-frontend-seven.vercel.app');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Session-ID');
header('Access-Control-Max-Age: 86400');

// Handle OPTIONS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============ SESSION CONFIGURATION ============
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', true);

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
        'error' => true,
        'timestamp' => time()
    ]);
    exit;
}

// ============ CHECK AUTHENTICATION ============
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    handleError('Authentication required. Please login.', 401);
}

$user_id = $_SESSION['user_id'];

// ============ INCLUDE DATABASE ============
try {
    require_once __DIR__ . '/../config/database.php';
} catch (Exception $e) {
    handleError('Database configuration error', 500);
}

// ============ MAIN API CLASS ============
class ProfileAPI {
    private $conn;
    private $user_id;
    private $base_url = 'https://dropxbackend-production.up.railway.app';
    private $cache = [];
    private $avatarSizes = [
        'large' => '400x400',
        'medium' => '150x150',
        'small' => '50x50',
        'thumbnail' => '30x30'
    ];

    public function __construct() {
        try {
            $database = new Database();
            $this->conn = $database->getConnection();
            $this->user_id = $_SESSION['user_id'];
            
            if (!$this->validateUser()) {
                handleError('User not found', 404);
            }
        } catch (Exception $e) {
            handleError('Database connection failed', 500);
        }
    }

    private function validateUser() {
        $cacheKey = 'user_exists_' . $this->user_id;
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        try {
            $stmt = $this->conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$this->user_id]);
            $exists = (bool)$stmt->fetch();
            $this->cache[$cacheKey] = $exists;
            return $exists;
        } catch (Exception $e) {
            return false;
        }
    }

    public function handleRequest() {
        try {
            $method = $_SERVER['REQUEST_METHOD'];
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            $isMultipart = strpos($contentType, 'multipart/form-data') !== false;
            
            if ($method === 'GET') {
                $action = $_GET['action'] ?? 'get_profile';
                $this->handleGetRequest($action);
            } else if ($isMultipart) {
                $action = $_POST['action'] ?? '';
                $this->handleMultipartRequest($action);
            } else {
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
                $light = isset($_GET['light']) && $_GET['light'] == '1';
                $this->getUserProfile($light);
                break;
            case 'addresses':
                $this->getUserAddresses();
                break;
            case 'orders':
                $this->getUserOrders();
                break;
            case 'get_avatar':
                $this->getAvatar();
                break;
            default:
                handleError('Invalid action', 400);
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
                handleError('Invalid action', 400);
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
                handleError('Invalid action', 400);
        }
    }

    private function handleDeleteRequest($action, $data) {
        switch ($action) {
            case 'delete_address':
                $this->deleteAddress($data);
                break;
            case 'delete_avatar':
                $this->deleteAvatar($data);
                break;
            default:
                handleError('Invalid action', 400);
        }
    }

    private function handleMultipartRequest($action) {
        switch ($action) {
            case 'update_profile':
                $this->updateProfileWithAvatar($_POST, $_FILES);
                break;
            case 'upload_avatar':
                $this->uploadAvatarOnly($_FILES);
                break;
            default:
                handleError('Invalid action for multipart request', 400);
        }
    }

    // ============ AVATAR FUNCTIONS ============
    private function getAvatar() {
        try {
            $size = $_GET['size'] ?? 'large';
            $user_id = $_GET['user_id'] ?? $this->user_id;
            
            $stmt = $this->conn->prepare("SELECT avatar, full_name FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            $avatar_url = $this->getAvatarUrl($user['avatar'], $size, $user['full_name']);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'avatar_url' => $avatar_url,
                    'size' => $size
                ]
            ]);
            
        } catch (Exception $e) {
            handleError($e->getMessage(), 400);
        }
    }

    private function getAvatarUrl($avatarPath, $size = 'large', $fullName = null) {
        if (empty($avatarPath) || strpos($avatarPath, 'ui-avatars.com') !== false) {
            // Generate default avatar
            $initials = 'US';
            if ($fullName) {
                $words = explode(' ', trim($fullName));
                $initials = '';
                foreach ($words as $word) {
                    if (!empty($word)) {
                        $initials .= strtoupper($word[0]);
                    }
                }
                $initials = substr($initials, 0, 2) ?: 'US';
            }
            
            $dimensions = $this->avatarSizes[$size] ?? '400x400';
            return "https://ui-avatars.com/api/?name=" . urlencode($initials) . 
                   "&background=random&size=" . $dimensions . 
                   "&bold=true&color=fff";
        }
        
        if (strpos($avatarPath, 'http') === 0) {
            return $avatarPath;
        }
        
        // Check if file exists
        $root_dir = dirname(dirname(__FILE__));
        $full_path = $root_dir . $avatarPath;
        
        if (!file_exists($full_path)) {
            // File doesn't exist, return default
            return $this->getAvatarUrl(null, $size, $fullName);
        }
        
        // For resized versions
        if ($size !== 'large') {
            $path_parts = pathinfo($full_path);
            $resized_path = $path_parts['dirname'] . '/' . 
                           $path_parts['filename'] . '_' . $size . '.' . 
                           $path_parts['extension'];
            
            if (file_exists($resized_path)) {
                $relative_path = str_replace($root_dir, '', $resized_path);
                return $this->base_url . $relative_path;
            }
        }
        
        return $this->base_url . $avatarPath;
    }

    private function uploadAvatarOnly($files) {
        try {
            if (!isset($files['avatar']) || $files['avatar']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('No valid avatar file uploaded');
            }
            
            $avatar_path = $this->processAvatarUpload($files['avatar']);
            
            // Update database
            $stmt = $this->conn->prepare("UPDATE users SET avatar = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$avatar_path, $this->user_id]);
            
            // Get user info for avatar URL generation
            $stmt = $this->conn->prepare("SELECT full_name FROM users WHERE id = ?");
            $stmt->execute([$this->user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $avatar_urls = [];
            foreach (array_keys($this->avatarSizes) as $size) {
                $avatar_urls[$size] = $this->getAvatarUrl($avatar_path, $size, $user['full_name'] ?? null);
            }
            
            // Clear cache
            $this->clearProfileCache();
            
            echo json_encode([
                'success' => true,
                'message' => 'Avatar uploaded successfully',
                'data' => [
                    'avatar_path' => $avatar_path,
                    'avatar_urls' => $avatar_urls
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Avatar upload failed: ' . $e->getMessage());
        }
    }

    private function processAvatarUpload($file) {
        try {
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            $max_size = 2 * 1024 * 1024; // 2MB
            
            // Check file type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mime_type, ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'])) {
                throw new Exception('Invalid file type. Allowed: JPG, PNG, GIF, WebP');
            }
            
            if ($file['size'] > $max_size) {
                throw new Exception('File size exceeds 2MB limit');
            }
            
            $root_dir = dirname(dirname(__FILE__));
            $upload_dir = $root_dir . '/uploads/avatars/';
            
            // Create directories
            if (!file_exists($upload_dir)) {
                if (!mkdir($upload_dir, 0755, true)) {
                    throw new Exception('Failed to create upload directory');
                }
            }
            
            // Generate unique filename
            $filename = 'avatar_' . $this->user_id . '_' . time();
            $original_ext = $this->getExtensionFromMime($mime_type);
            
            // Delete old avatar files first
            $this->deleteOldAvatarFiles();
            
            // Save original
            $original_path = $upload_dir . $filename . '.' . $original_ext;
            
            if (!move_uploaded_file($file['tmp_name'], $original_path)) {
                throw new Exception('Failed to upload file');
            }
            
            // Create resized versions
            $this->createAvatarResizes($original_path, $filename, $original_ext);
            
            // Return relative path
            $relative_path = '/uploads/avatars/' . $filename . '.' . $original_ext;
            
            return $relative_path;
            
        } catch (Exception $e) {
            throw new Exception('Avatar upload failed: ' . $e->getMessage());
        }
    }

    private function getExtensionFromMime($mime_type) {
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp'
        ];
        
        return $extensions[$mime_type] ?? 'jpg';
    }

    private function createAvatarResizes($original_path, $base_name, $ext) {
        try {
            // Get original image
            $image_info = getimagesize($original_path);
            $original_width = $image_info[0];
            $original_height = $image_info[1];
            $mime_type = $image_info['mime'];
            
            // Create image resource based on type
            switch ($mime_type) {
                case 'image/jpeg':
                    $source = imagecreatefromjpeg($original_path);
                    break;
                case 'image/png':
                    $source = imagecreatefrompng($original_path);
                    break;
                case 'image/gif':
                    $source = imagecreatefromgif($original_path);
                    break;
                case 'image/webp':
                    $source = imagecreatefromwebp($original_path);
                    break;
                default:
                    return; // Unsupported type
            }
            
            if (!$source) {
                return;
            }
            
            $upload_dir = dirname($original_path);
            
            foreach ($this->avatarSizes as $size_name => $dimensions) {
                if ($size_name === 'large') continue; // Skip large (original)
                
                list($width, $height) = explode('x', $dimensions);
                
                // Create new image
                $resized = imagecreatetruecolor($width, $height);
                
                // Handle transparency for PNG/GIF
                if ($mime_type === 'image/png' || $mime_type === 'image/gif') {
                    imagealphablending($resized, false);
                    imagesavealpha($resized, true);
                    $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
                    imagefilledrectangle($resized, 0, 0, $width, $height, $transparent);
                } else {
                    // Fill with white background for JPEG/WebP
                    $white = imagecolorallocate($resized, 255, 255, 255);
                    imagefill($resized, 0, 0, $white);
                }
                
                // Calculate aspect ratio preserving resize
                $source_ratio = $original_width / $original_height;
                $target_ratio = $width / $height;
                
                if ($source_ratio > $target_ratio) {
                    // Source is wider
                    $new_width = $width;
                    $new_height = $height;
                    $src_x = ($original_width - ($original_height * $target_ratio)) / 2;
                    $src_y = 0;
                    $src_width = $original_height * $target_ratio;
                    $src_height = $original_height;
                } else {
                    // Source is taller or equal
                    $new_width = $width;
                    $new_height = $height;
                    $src_x = 0;
                    $src_y = ($original_height - ($original_width / $target_ratio)) / 2;
                    $src_width = $original_width;
                    $src_height = $original_width / $target_ratio;
                }
                
                // Resize image
                imagecopyresampled(
                    $resized, $source,
                    0, 0, $src_x, $src_y,
                    $new_width, $new_height, $src_width, $src_height
                );
                
                // Save resized image
                $resized_path = $upload_dir . '/' . $base_name . '_' . $size_name . '.' . $ext;
                
                switch ($mime_type) {
                    case 'image/jpeg':
                        imagejpeg($resized, $resized_path, 90);
                        break;
                    case 'image/png':
                        imagepng($resized, $resized_path, 9);
                        break;
                    case 'image/gif':
                        imagegif($resized, $resized_path);
                        break;
                    case 'image/webp':
                        imagewebp($resized, $resized_path, 90);
                        break;
                }
                
                imagedestroy($resized);
            }
            
            imagedestroy($source);
            
        } catch (Exception $e) {
            // Log error but don't fail
            error_log("Avatar resize error: " . $e->getMessage());
        }
    }

    private function deleteOldAvatarFiles() {
        try {
            $stmt = $this->conn->prepare("SELECT avatar FROM users WHERE id = ?");
            $stmt->execute([$this->user_id]);
            $current_avatar = $stmt->fetchColumn();
            
            if (!$current_avatar || strpos($current_avatar, 'ui-avatars.com') !== false) {
                return; // Don't delete default avatars
            }
            
            $root_dir = dirname(dirname(__FILE__));
            $avatar_path = $root_dir . $current_avatar;
            
            if (file_exists($avatar_path)) {
                // Delete original and all resized versions
                $path_parts = pathinfo($avatar_path);
                $pattern = $path_parts['dirname'] . '/' . $path_parts['filename'] . '*.' . $path_parts['extension'];
                
                foreach (glob($pattern) as $file) {
                    @unlink($file);
                }
            }
        } catch (Exception $e) {
            // Silent fail
            error_log("Failed to delete old avatar: " . $e->getMessage());
        }
    }

    private function deleteAvatar($data) {
        try {
            // Get current avatar path
            $stmt = $this->conn->prepare("SELECT avatar FROM users WHERE id = ?");
            $stmt->execute([$this->user_id]);
            $current_avatar = $stmt->fetchColumn();
            
            if ($current_avatar && strpos($current_avatar, 'ui-avatars.com') === false) {
                // Delete files
                $root_dir = dirname(dirname(__FILE__));
                $avatar_path = $root_dir . $current_avatar;
                
                if (file_exists($avatar_path)) {
                    $path_parts = pathinfo($avatar_path);
                    $pattern = $path_parts['dirname'] . '/' . $path_parts['filename'] . '*.' . $path_parts['extension'];
                    
                    foreach (glob($pattern) as $file) {
                        @unlink($file);
                    }
                }
            }
            
            // Update database to NULL
            $stmt = $this->conn->prepare("UPDATE users SET avatar = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$this->user_id]);
            
            // Clear cache
            $this->clearProfileCache();
            
            echo json_encode([
                'success' => true,
                'message' => 'Avatar removed successfully'
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to delete avatar: ' . $e->getMessage());
        }
    }

    // ============ PROFILE FUNCTIONS ============
    private function getUserProfile($light = false) {
        $cacheKey = 'profile_' . $this->user_id . '_' . ($light ? 'light' : 'full');
        
        if (isset($this->cache[$cacheKey])) {
            echo json_encode($this->cache[$cacheKey]);
            return;
        }
        
        try {
            // Get user data - MATCHING DATABASE SCHEMA
            $query = "SELECT 
                        id, username, email, password, full_name, phone, address, avatar,
                        wallet_balance, member_level, member_points,
                        total_orders, rating, verified,
                        DATE_FORMAT(created_at, '%Y-%m-%d') as join_date,
                        created_at, updated_at
                      FROM users 
                      WHERE id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            // Remove sensitive data
            unset($user['password']);
            
            // Add avatar URLs for all sizes
            $user['avatar_urls'] = [];
            foreach (array_keys($this->avatarSizes) as $size) {
                $user['avatar_urls'][$size] = $this->getAvatarUrl($user['avatar'], $size, $user['full_name']);
            }
            
            // Set default avatar_url for backward compatibility
            $user['avatar_url'] = $user['avatar_urls']['large'];
            
            $response = [
                'success' => true,
                'message' => 'Profile retrieved successfully',
                'data' => [
                    'user' => $user
                ]
            ];
            
            if (!$light) {
                // Get recent orders (5 most recent)
                $recent_orders = $this->getRecentOrders(5);
                $response['data']['recent_orders'] = $recent_orders;
                
                // Get user addresses count
                $addressCount = $this->getUserAddressCount();
                $user['address_count'] = $addressCount;
            }
            
            $this->cache[$cacheKey] = $response;
            echo json_encode($response);
            
        } catch (Exception $e) {
            throw new Exception('Failed to get profile: ' . $e->getMessage());
        }
    }

    private function updateProfile($data) {
        $this->updateProfileData($data, null);
    }

    private function updateProfileWithAvatar($data, $files) {
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
            $checkQuery = "SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1";
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
                    $avatar_url = $this->processAvatarUpload($files['avatar']);
                }
                
                // Build update query - MATCHING DATABASE SCHEMA
                $updateFields = [];
                $params = [];
                
                // Fields that should be updated
                $fields = [
                    'full_name' => trim($data['full_name']),
                    'email' => trim($data['email'])
                ];
                
                // Optional fields
                if (isset($data['phone'])) {
                    $fields['phone'] = trim($data['phone']);
                }
                
                if (isset($data['address'])) {
                    $fields['address'] = trim($data['address']);
                }
                
                if ($avatar_url) {
                    $fields['avatar'] = $avatar_url;
                }
                
                // Add timestamp
                $fields['updated_at'] = 'CURRENT_TIMESTAMP';
                
                foreach ($fields as $field => $value) {
                    if ($value === 'CURRENT_TIMESTAMP') {
                        $updateFields[] = "$field = CURRENT_TIMESTAMP";
                    } else {
                        $updateFields[] = "$field = ?";
                        $params[] = $value;
                    }
                }
                
                // Update user
                $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ?";
                $params[] = $this->user_id;
                
                $stmt = $this->conn->prepare($sql);
                $stmt->execute($params);
                
                $this->conn->commit();
                
                // Clear cache
                $this->clearProfileCache();
                
                // Get updated user data
                $userQuery = "SELECT 
                                id, username, email, full_name, phone, address, avatar,
                                wallet_balance, member_level, member_points,
                                total_orders, rating, verified,
                                DATE_FORMAT(created_at, '%Y-%m-%d') as join_date,
                                created_at
                              FROM users 
                              WHERE id = ?";
                
                $stmt = $this->conn->prepare($userQuery);
                $stmt->execute([$this->user_id]);
                $updated_user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Add avatar URLs
                $updated_user['avatar_urls'] = [];
                foreach (array_keys($this->avatarSizes) as $size) {
                    $updated_user['avatar_urls'][$size] = $this->getAvatarUrl($updated_user['avatar'], $size, $updated_user['full_name']);
                }
                $updated_user['avatar_url'] = $updated_user['avatar_urls']['large'];
                
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

    private function clearProfileCache() {
        $cacheKeys = [
            'profile_' . $this->user_id . '_light',
            'profile_' . $this->user_id . '_full',
            'user_exists_' . $this->user_id
        ];
        
        foreach ($cacheKeys as $key) {
            unset($this->cache[$key]);
        }
    }

    private function getUserAddressCount() {
        try {
            $query = "SELECT COUNT(*) as count FROM user_addresses WHERE user_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['count'] ?? 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getRecentOrders($limit = 5) {
        try {
            $query = "SELECT 
                        o.id, o.order_number, o.total_amount, o.status, 
                        DATE_FORMAT(o.created_at, '%Y-%m-%d') as formatted_date,
                        r.name as restaurant_name,
                        COUNT(oi.id) as item_count
                      FROM orders o
                      LEFT JOIN restaurants r ON o.restaurant_id = r.id
                      LEFT JOIN order_items oi ON o.id = oi.order_id
                      WHERE o.user_id = ?
                      GROUP BY o.id
                      ORDER BY o.created_at DESC 
                      LIMIT ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
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
            $checkQuery = "SELECT id FROM user_addresses WHERE id = ? AND user_id = ? LIMIT 1";
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
            $checkQuery = "SELECT id FROM user_addresses WHERE id = ? AND user_id = ? LIMIT 1";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->execute([$address_id, $this->user_id]);
            if (!$stmt->fetch()) {
                throw new Exception('Address not found');
            }
            
            $updates = [];
            $params = [];
            
            $fields = [
                'title', 'address', 'city', 'state', 'zip_code', 
                'address_type', 'instructions'
            ];
            
            foreach ($fields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }
            
            if (empty($updates)) {
                throw new Exception('No fields to update');
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
            $checkQuery = "SELECT is_default FROM user_addresses WHERE id = ? AND user_id = ? LIMIT 1";
            $stmt = $this->conn->prepare($checkQuery);
            $stmt->execute([$address_id, $this->user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                throw new Exception('Address not found');
            }
            
            if ($result['is_default'] == 1) {
                throw new Exception('Cannot delete default address');
            }
            
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
                    COUNT(oi.id) as item_count
                  FROM orders o
                  LEFT JOIN restaurants r ON o.restaurant_id = r.id
                  LEFT JOIN order_items oi ON o.id = oi.order_id
                  $where
                  GROUP BY o.id
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
            $query = "SELECT password FROM users WHERE id = ? LIMIT 1";
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

// Clear output buffer
$output = ob_get_clean();
echo $output;
?>