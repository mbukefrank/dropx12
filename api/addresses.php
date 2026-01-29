<?php
/*********************************
 * CORS Configuration
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

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

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        handleGetRequest();
    } elseif ($method === 'POST') {
        handlePostRequest();
    } elseif ($method === 'PUT') {
        handlePutRequest();
    } elseif ($method === 'DELETE') {
        handleDeleteRequest();
    } else {
        ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET REQUESTS
 *********************************/
function handleGetRequest() {
    $db = new Database();
    $conn = $db->getConnection();

    // Check for specific location ID
    $locationId = $_GET['id'] ?? null;
    
    if ($locationId) {
        getLocationDetails($conn, $locationId);
    } else {
        getLocationsList($conn);
    }
}

/*********************************
 * GET LOCATIONS LIST
 *********************************/
function getLocationsList($conn) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    
    // Get query parameters
    $type = $_GET['type'] ?? '';
    $isDefault = $_GET['is_default'] ?? null;
    $sortBy = $_GET['sort_by'] ?? 'last_used';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');

    // Build WHERE clause
    $whereConditions = ["user_id = :user_id"];
    $params = [':user_id' => $userId];

    if ($type && $type !== 'all') {
        $whereConditions[] = "location_type = :type";
        $params[':type'] = $type;
    }

    if ($isDefault !== null) {
        $whereConditions[] = "is_default = :is_default";
        $params[':is_default'] = $isDefault === 'true' ? 1 : 0;
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    // Validate sort options
    $allowedSortColumns = ['last_used', 'created_at', 'label', 'is_default'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'last_used';
    $sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';

    // Get locations from database
    $sql = "SELECT 
                id,
                user_id,
                label,
                full_name,
                phone,
                address_line1,
                address_line2,
                city,
                neighborhood,
                landmark,
                latitude,
                longitude,
                is_default,
                created_at,
                updated_at
            FROM addresses
            $whereClause
            ORDER BY is_default DESC, $sortBy $sortOrder";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$locations) {
        // No locations found - return empty array
        ResponseHandler::success([
            'locations' => [],
            'default_location_id' => null,
            'total_count' => 0
        ]);
        return;
    }

    // Get user's default address ID from users table
    $defaultStmt = $conn->prepare(
        "SELECT default_address_id FROM users WHERE id = :user_id"
    );
    $defaultStmt->execute([':user_id' => $userId]);
    $user = $defaultStmt->fetch(PDO::FETCH_ASSOC);
    $defaultAddressId = $user['default_address_id'] ?? null;

    // Format location data
    $formattedLocations = [];
    foreach ($locations as $loc) {
        $formattedLocations[] = formatLocationData($loc, $defaultAddressId);
    }

    // Determine current location (default or most recent)
    $currentLocation = null;
    if ($defaultAddressId) {
        // Find the default location
        foreach ($formattedLocations as $loc) {
            if ($loc['id'] == $defaultAddressId) {
                $currentLocation = $loc;
                break;
            }
        }
    }
    
    // If no default, use most recently used
    if (!$currentLocation && !empty($formattedLocations)) {
        usort($formattedLocations, function($a, $b) {
            $timeA = strtotime($a['last_used'] ?? $a['created_at']);
            $timeB = strtotime($b['last_used'] ?? $b['created_at']);
            return $timeB - $timeA;
        });
        $currentLocation = $formattedLocations[0];
    }

    ResponseHandler::success([
        'locations' => $formattedLocations,
        'current_location' => $currentLocation,
        'default_location_id' => $defaultAddressId,
        'total_count' => count($formattedLocations)
    ]);
}

/*********************************
 * GET LOCATION DETAILS
 *********************************/
function getLocationDetails($conn, $locationId) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];

    $stmt = $conn->prepare(
        "SELECT 
            id,
            user_id,
            label,
            full_name,
            phone,
            address_line1,
            address_line2,
            city,
            neighborhood,
            landmark,
            latitude,
            longitude,
            is_default,
            created_at,
            updated_at
        FROM addresses
        WHERE id = :id AND user_id = :user_id"
    );
    
    $stmt->execute([
        ':id' => $locationId,
        ':user_id' => $userId
    ]);
    $location = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$location) {
        ResponseHandler::error('Location not found', 404);
    }

    // Get user's default address ID to determine if this is current
    $defaultStmt = $conn->prepare(
        "SELECT default_address_id FROM users WHERE id = :user_id"
    );
    $defaultStmt->execute([':user_id' => $userId]);
    $user = $defaultStmt->fetch(PDO::FETCH_ASSOC);
    $defaultAddressId = $user['default_address_id'] ?? null;

    $formattedLocation = formatLocationData($location, $defaultAddressId);

    ResponseHandler::success([
        'location' => $formattedLocation
    ]);
}

/*********************************
 * POST REQUESTS
 *********************************/
function handlePostRequest() {
    $db = new Database();
    $conn = $db->getConnection();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'create_location':
            createLocation($conn, $input);
            break;
        case 'use_current_location':
            useCurrentLocation($conn, $input);
            break;
        case 'detect_location':
            detectLocation($conn, $input);
            break;
        case 'get_lilongwe_areas':
            getLilongweAreas($conn);
            break;
        case 'search_locations':
            searchLocations($conn, $input);
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * PUT REQUESTS
 *********************************/
function handlePutRequest() {
    $db = new Database();
    $conn = $db->getConnection();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        ResponseHandler::error('Invalid request data', 400);
    }
    
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'update_location':
            updateLocation($conn, $input);
            break;
        case 'set_default':
            setDefaultLocation($conn, $input);
            break;
        case 'update_last_used':
            updateLastUsed($conn, $input);
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * DELETE REQUESTS
 *********************************/
function handleDeleteRequest() {
    $db = new Database();
    $conn = $db->getConnection();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        ResponseHandler::error('Invalid request data', 400);
    }
    
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'delete_location':
            deleteLocation($conn, $input);
            break;
        case 'delete_multiple_locations':
            deleteMultipleLocations($conn, $input);
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * CREATE NEW LOCATION
 *********************************/
function createLocation($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    
    // Validate required fields based on database schema
    $required = ['label', 'full_name', 'phone', 'address_line1', 'city'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            ResponseHandler::error("$field is required", 400);
        }
    }

    $label = trim($data['label']);
    $fullName = trim($data['full_name']);
    $phone = trim($data['phone']);
    $addressLine1 = trim($data['address_line1']);
    $addressLine2 = trim($data['address_line2'] ?? '');
    $city = trim($data['city']);
    $neighborhood = trim($data['neighborhood'] ?? '');
    $landmark = trim($data['landmark'] ?? '');
    $latitude = isset($data['latitude']) ? floatval($data['latitude']) : null;
    $longitude = isset($data['longitude']) ? floatval($data['longitude']) : null;
    $isDefault = boolval($data['is_default'] ?? false);

    // Validate phone number
    if (!preg_match('/^\+?[0-9\s\-\(\)]{8,20}$/', $phone)) {
        ResponseHandler::error('Invalid phone number format', 400);
    }

    // Validate coordinates if provided
    if ($latitude !== null && ($latitude < -90 || $latitude > 90)) {
        ResponseHandler::error('Invalid latitude value', 400);
    }
    if ($longitude !== null && ($longitude < -180 || $longitude > 180)) {
        ResponseHandler::error('Invalid longitude value', 400);
    }

    // Start transaction
    $conn->beginTransaction();

    try {
        // If setting as default, remove default from other locations
        if ($isDefault) {
            $updateStmt = $conn->prepare(
                "UPDATE addresses 
                 SET is_default = 0 
                 WHERE user_id = :user_id AND is_default = 1"
            );
            $updateStmt->execute([':user_id' => $userId]);
        }

        // Create the new location
        $stmt = $conn->prepare(
            "INSERT INTO addresses 
                (user_id, label, full_name, phone, address_line1, address_line2, 
                 city, neighborhood, landmark, latitude, longitude, is_default, created_at)
             VALUES 
                (:user_id, :label, :full_name, :phone, :address_line1, :address_line2, 
                 :city, :neighborhood, :landmark, :latitude, :longitude, :is_default, NOW())"
        );
        
        $stmt->execute([
            ':user_id' => $userId,
            ':label' => $label,
            ':full_name' => $fullName,
            ':phone' => $phone,
            ':address_line1' => $addressLine1,
            ':address_line2' => $addressLine2,
            ':city' => $city,
            ':neighborhood' => $neighborhood,
            ':landmark' => $landmark,
            ':latitude' => $latitude,
            ':longitude' => $longitude,
            ':is_default' => $isDefault ? 1 : 0
        ]);

        $locationId = $conn->lastInsertId();

        // If no default exists and this isn't set as default, set it as default
        if (!$isDefault) {
            $checkDefaultStmt = $conn->prepare(
                "SELECT COUNT(*) as default_count 
                 FROM addresses 
                 WHERE user_id = :user_id AND is_default = 1"
            );
            $checkDefaultStmt->execute([':user_id' => $userId]);
            $defaultCount = $checkDefaultStmt->fetch(PDO::FETCH_ASSOC)['default_count'];

            if ($defaultCount == 0) {
                $setDefaultStmt = $conn->prepare(
                    "UPDATE addresses 
                     SET is_default = 1 
                     WHERE id = :id"
                );
                $setDefaultStmt->execute([':id' => $locationId]);
                $isDefault = true;
            }
        }

        // Update user's default address if needed
        if ($isDefault) {
            $updateUserStmt = $conn->prepare(
                "UPDATE users 
                 SET default_address_id = :address_id 
                 WHERE id = :user_id"
            );
            $updateUserStmt->execute([
                ':address_id' => $locationId,
                ':user_id' => $userId
            ]);
        }

        $conn->commit();

        // Get the created location from database
        $locationStmt = $conn->prepare(
            "SELECT * FROM addresses WHERE id = :id"
        );
        $locationStmt->execute([':id' => $locationId]);
        $location = $locationStmt->fetch(PDO::FETCH_ASSOC);

        // Get user's default address ID for formatting
        $defaultStmt = $conn->prepare(
            "SELECT default_address_id FROM users WHERE id = :user_id"
        );
        $defaultStmt->execute([':user_id' => $userId]);
        $user = $defaultStmt->fetch(PDO::FETCH_ASSOC);
        $defaultAddressId = $user['default_address_id'] ?? null;

        $formattedLocation = formatLocationData($location, $defaultAddressId);

        // Log activity
        logUserActivity($conn, $userId, 'location_created', "Created location: $label");

        ResponseHandler::success([
            'location' => $formattedLocation,
            'message' => 'Location created successfully'
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        ResponseHandler::error('Database error: ' . $e->getMessage(), 500);
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to create location: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * UPDATE LOCATION
 *********************************/
function updateLocation($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $locationId = $data['id'] ?? null;

    if (!$locationId) {
        ResponseHandler::error('Location ID is required', 400);
    }

    // Check if location exists and belongs to user
    $checkStmt = $conn->prepare(
        "SELECT * FROM addresses 
         WHERE id = :id AND user_id = :user_id"
    );
    $checkStmt->execute([
        ':id' => $locationId,
        ':user_id' => $userId
    ]);
    
    $currentLocation = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currentLocation) {
        ResponseHandler::error('Location not found', 404);
    }

    // Prepare update data
    $updateFields = [];
    $params = [':id' => $locationId];

    if (isset($data['label'])) {
        $updateFields[] = "label = :label";
        $params[':label'] = trim($data['label']);
    }

    if (isset($data['full_name'])) {
        $updateFields[] = "full_name = :full_name";
        $params[':full_name'] = trim($data['full_name']);
    }

    if (isset($data['phone'])) {
        $updateFields[] = "phone = :phone";
        $params[':phone'] = trim($data['phone']);
        
        // Validate phone number
        if (!preg_match('/^\+?[0-9\s\-\(\)]{8,20}$/', $params[':phone'])) {
            ResponseHandler::error('Invalid phone number format', 400);
        }
    }

    if (isset($data['address_line1'])) {
        $updateFields[] = "address_line1 = :address_line1";
        $params[':address_line1'] = trim($data['address_line1']);
    }

    if (isset($data['address_line2'])) {
        $updateFields[] = "address_line2 = :address_line2";
        $params[':address_line2'] = trim($data['address_line2']);
    }

    if (isset($data['city'])) {
        $updateFields[] = "city = :city";
        $params[':city'] = trim($data['city']);
    }

    if (isset($data['neighborhood'])) {
        $updateFields[] = "neighborhood = :neighborhood";
        $params[':neighborhood'] = trim($data['neighborhood']);
    }

    if (isset($data['landmark'])) {
        $updateFields[] = "landmark = :landmark";
        $params[':landmark'] = trim($data['landmark']);
    }

    if (isset($data['latitude'])) {
        $latitude = floatval($data['latitude']);
        if ($latitude < -90 || $latitude > 90) {
            ResponseHandler::error('Invalid latitude value', 400);
        }
        $updateFields[] = "latitude = :latitude";
        $params[':latitude'] = $latitude;
    }

    if (isset($data['longitude'])) {
        $longitude = floatval($data['longitude']);
        if ($longitude < -180 || $longitude > 180) {
            ResponseHandler::error('Invalid longitude value', 400);
        }
        $updateFields[] = "longitude = :longitude";
        $params[':longitude'] = $longitude;
    }

    if (empty($updateFields)) {
        ResponseHandler::error('No fields to update', 400);
    }

    // Start transaction for default location handling
    $conn->beginTransaction();

    try {
        // Handle default location change
        if (isset($data['is_default']) && boolval($data['is_default']) !== boolval($currentLocation['is_default'])) {
            if (boolval($data['is_default'])) {
                // Remove default from other locations
                $removeDefaultStmt = $conn->prepare(
                    "UPDATE addresses 
                     SET is_default = 0 
                     WHERE user_id = :user_id AND is_default = 1"
                );
                $removeDefaultStmt->execute([':user_id' => $userId]);

                $updateFields[] = "is_default = 1";

                // Update user's default address
                $updateUserStmt = $conn->prepare(
                    "UPDATE users 
                     SET default_address_id = :address_id 
                     WHERE id = :user_id"
                );
                $updateUserStmt->execute([
                    ':address_id' => $locationId,
                    ':user_id' => $userId
                ]);
            } else {
                $updateFields[] = "is_default = 0";
                
                // Clear user's default address if this was the default
                if ($currentLocation['is_default']) {
                    $clearUserStmt = $conn->prepare(
                        "UPDATE users 
                         SET default_address_id = NULL 
                         WHERE id = :user_id"
                    );
                    $clearUserStmt->execute([':user_id' => $userId]);
                }
            }
        }

        // Update the location
        $updateFields[] = "updated_at = NOW()";
        
        $sql = "UPDATE addresses SET " . implode(", ", $updateFields) . " WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        $conn->commit();

        // Get updated location from database
        $locationStmt = $conn->prepare(
            "SELECT * FROM addresses WHERE id = :id"
        );
        $locationStmt->execute([':id' => $locationId]);
        $location = $locationStmt->fetch(PDO::FETCH_ASSOC);

        // Get user's default address ID for formatting
        $defaultStmt = $conn->prepare(
            "SELECT default_address_id FROM users WHERE id = :user_id"
        );
        $defaultStmt->execute([':user_id' => $userId]);
        $user = $defaultStmt->fetch(PDO::FETCH_ASSOC);
        $defaultAddressId = $user['default_address_id'] ?? null;

        $formattedLocation = formatLocationData($location, $defaultAddressId);

        // Log activity
        logUserActivity($conn, $userId, 'location_updated', "Updated location: {$currentLocation['label']}");

        ResponseHandler::success([
            'location' => $formattedLocation,
            'message' => 'Location updated successfully'
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        ResponseHandler::error('Database error: ' . $e->getMessage(), 500);
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to update location: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * DELETE LOCATION
 *********************************/
function deleteLocation($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $locationId = $data['id'] ?? null;

    if (!$locationId) {
        ResponseHandler::error('Location ID is required', 400);
    }

    // Check if location exists and belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id, label, is_default FROM addresses 
         WHERE id = :id AND user_id = :user_id"
    );
    $checkStmt->execute([
        ':id' => $locationId,
        ':user_id' => $userId
    ]);
    
    $location = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$location) {
        ResponseHandler::error('Location not found', 404);
    }

    // Check if location is used in any orders
    $orderCheckStmt = $conn->prepare(
        "SELECT COUNT(*) as order_count FROM orders 
         WHERE user_id = :user_id AND delivery_address LIKE :address_pattern"
    );
    $orderCheckStmt->execute([
        ':user_id' => $userId,
        ':address_pattern' => '%' . $location['label'] . '%'
    ]);
    $orderCount = $orderCheckStmt->fetch(PDO::FETCH_ASSOC)['order_count'];

    if ($orderCount > 0) {
        ResponseHandler::error('Cannot delete location that has been used in orders', 400);
    }

    // Start transaction
    $conn->beginTransaction();

    try {
        // Delete the location
        $deleteStmt = $conn->prepare(
            "DELETE FROM addresses 
             WHERE id = :id AND user_id = :user_id"
        );
        $deleteStmt->execute([
            ':id' => $locationId,
            ':user_id' => $userId
        ]);

        // If deleting default location, set a new default
        if ($location['is_default']) {
            // Get another location to set as default
            $newDefaultStmt = $conn->prepare(
                "SELECT id FROM addresses 
                 WHERE user_id = :user_id 
                 ORDER BY created_at DESC 
                 LIMIT 1"
            );
            $newDefaultStmt->execute([':user_id' => $userId]);
            $newDefault = $newDefaultStmt->fetch(PDO::FETCH_ASSOC);

            if ($newDefault) {
                // Set new default
                $setDefaultStmt = $conn->prepare(
                    "UPDATE addresses 
                     SET is_default = 1 
                     WHERE id = :id"
                );
                $setDefaultStmt->execute([':id' => $newDefault['id']]);

                // Update user's default address
                $updateUserStmt = $conn->prepare(
                    "UPDATE users 
                     SET default_address_id = :address_id 
                     WHERE id = :user_id"
                );
                $updateUserStmt->execute([
                    ':address_id' => $newDefault['id'],
                    ':user_id' => $userId
                ]);
            } else {
                // No locations left, clear user's default address
                $clearUserStmt = $conn->prepare(
                    "UPDATE users 
                     SET default_address_id = NULL 
                     WHERE id = :user_id"
                );
                $clearUserStmt->execute([':user_id' => $userId]);
            }
        }

        $conn->commit();

        // Log activity
        logUserActivity($conn, $userId, 'location_deleted', "Deleted location: {$location['label']}");

        ResponseHandler::success([
            'message' => 'Location deleted successfully',
            'deleted_id' => $locationId
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        ResponseHandler::error('Database error: ' . $e->getMessage(), 500);
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to delete location: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * SET DEFAULT LOCATION
 *********************************/
function setDefaultLocation($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $locationId = $data['id'] ?? null;

    if (!$locationId) {
        ResponseHandler::error('Location ID is required', 400);
    }

    // Check if location exists and belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id, label FROM addresses 
         WHERE id = :id AND user_id = :user_id"
    );
    $checkStmt->execute([
        ':id' => $locationId,
        ':user_id' => $userId
    ]);
    
    $location = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$location) {
        ResponseHandler::error('Location not found', 404);
    }

    // Start transaction
    $conn->beginTransaction();

    try {
        // Remove default from all locations
        $removeDefaultStmt = $conn->prepare(
            "UPDATE addresses 
             SET is_default = 0 
             WHERE user_id = :user_id AND is_default = 1"
        );
        $removeDefaultStmt->execute([':user_id' => $userId]);

        // Set the specified location as default
        $setDefaultStmt = $conn->prepare(
            "UPDATE addresses 
             SET is_default = 1, 
                 updated_at = NOW()
             WHERE id = :id"
        );
        $setDefaultStmt->execute([':id' => $locationId]);

        // Update user's default address
        $updateUserStmt = $conn->prepare(
            "UPDATE users 
             SET default_address_id = :address_id 
             WHERE id = :user_id"
        );
        $updateUserStmt->execute([
            ':address_id' => $locationId,
            ':user_id' => $userId
        ]);

        $conn->commit();

        // Log activity
        logUserActivity($conn, $userId, 'location_set_default', "Set default location: {$location['label']}");

        ResponseHandler::success([
            'message' => 'Default location updated successfully',
            'default_location_id' => $locationId
        ]);

    } catch (PDOException $e) {
        $conn->rollBack();
        ResponseHandler::error('Database error: ' . $e->getMessage(), 500);
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to set default location: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * UPDATE LAST USED TIMESTAMP
 *********************************/
function updateLastUsed($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $locationId = $data['id'] ?? null;

    if (!$locationId) {
        ResponseHandler::error('Location ID is required', 400);
    }

    // Check if location exists and belongs to user
    $checkStmt = $conn->prepare(
        "SELECT id FROM addresses 
         WHERE id = :id AND user_id = :user_id"
    );
    $checkStmt->execute([
        ':id' => $locationId,
        ':user_id' => $userId
    ]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Location not found', 404);
    }

    try {
        // Update last used timestamp
        $stmt = $conn->prepare(
            "UPDATE addresses 
             SET updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([':id' => $locationId]);

        ResponseHandler::success([
            'message' => 'Last used timestamp updated',
            'location_id' => $locationId
        ]);

    } catch (PDOException $e) {
        ResponseHandler::error('Database error: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * USE CURRENT LOCATION (GPS)
 *********************************/
function useCurrentLocation($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $latitude = $data['latitude'] ?? null;
    $longitude = $data['longitude'] ?? null;

    if (!$latitude || !$longitude) {
        ResponseHandler::error('Latitude and longitude are required', 400);
    }

    // Validate coordinates
    if (!is_numeric($latitude) || $latitude < -90 || $latitude > 90) {
        ResponseHandler::error('Invalid latitude value', 400);
    }
    if (!is_numeric($longitude) || $longitude < -180 || $longitude > 180) {
        ResponseHandler::error('Invalid longitude value', 400);
    }

    // Store coordinates in session for later use
    $_SESSION['current_latitude'] = $latitude;
    $_SESSION['current_longitude'] = $longitude;

    // Check if we have a geocoding service configured
    // This would typically call an external API
    // For now, we'll just return the coordinates
    
    ResponseHandler::success([
        'message' => 'Current location coordinates saved',
        'coordinates' => [
            'latitude' => floatval($latitude),
            'longitude' => floatval($longitude)
        ]
    ]);
}

/*********************************
 * DETECT LOCATION (Address to Coordinates)
 *********************************/
function detectLocation($conn, $data) {
    $address = $data['address'] ?? '';

    if (!$address) {
        ResponseHandler::error('Address is required', 400);
    }

    // This would typically call a geocoding API
    // For now, we'll check if we have coordinates stored in session
    if (isset($_SESSION['current_latitude']) && isset($_SESSION['current_longitude'])) {
        ResponseHandler::success([
            'coordinates' => [
                'latitude' => $_SESSION['current_latitude'],
                'longitude' => $_SESSION['current_longitude']
            ],
            'message' => 'Using stored location coordinates'
        ]);
    } else {
        ResponseHandler::error('No location coordinates available', 404);
    }
}

/*********************************
 * GET LILONGWE AREAS FROM DATABASE
 *********************************/
function getLilongweAreas($conn) {
    // In a real implementation, you would have a table for areas/sectors
    // For now, we'll check if there's a configuration table or settings
    
    try {
        // Check if there's a cities/areas table
        $stmt = $conn->query("SHOW TABLES LIKE 'cities' OR SHOW TABLES LIKE 'areas'");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (in_array('areas', $tables)) {
            // Get areas from areas table
            $areasStmt = $conn->query(
                "SELECT name FROM areas WHERE city = 'Lilongwe' ORDER BY name"
            );
            $areas = $areasStmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Get sectors from sectors table if exists
            $sectorsStmt = $conn->query(
                "SELECT name FROM sectors ORDER BY name"
            );
            $sectors = $sectorsStmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            // Fallback: extract unique areas from existing addresses
            $areasStmt = $conn->query(
                "SELECT DISTINCT city FROM addresses 
                 WHERE city LIKE '%Lilongwe%' OR city LIKE 'Area%'
                 ORDER BY city"
            );
            $areas = $areasStmt->fetchAll(PDO::FETCH_COLUMN);
            
            $sectorsStmt = $conn->query(
                "SELECT DISTINCT neighborhood FROM addresses 
                 WHERE neighborhood LIKE 'Sector%' 
                 ORDER BY neighborhood"
            );
            $sectors = $sectorsStmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        // If no data found in database, return empty arrays
        if (empty($areas)) {
            $areas = [];
        }
        if (empty($sectors)) {
            $sectors = [];
        }
        
        ResponseHandler::success([
            'areas' => $areas,
            'sectors' => $sectors
        ]);
        
    } catch (Exception $e) {
        // Return empty arrays if there's an error
        ResponseHandler::success([
            'areas' => [],
            'sectors' => []
        ]);
    }
}

/*********************************
 * SEARCH LOCATIONS
 *********************************/
function searchLocations($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $query = trim($data['query'] ?? '');
    
    if (empty($query)) {
        ResponseHandler::error('Search query is required', 400);
    }
    
    $searchTerm = "%$query%";
    
    $sql = "SELECT 
                id,
                user_id,
                label,
                full_name,
                phone,
                address_line1,
                address_line2,
                city,
                neighborhood,
                landmark,
                latitude,
                longitude,
                is_default,
                created_at,
                updated_at
            FROM addresses
            WHERE user_id = :user_id 
            AND (
                label LIKE :query OR
                address_line1 LIKE :query OR
                address_line2 LIKE :query OR
                city LIKE :query OR
                neighborhood LIKE :query OR
                landmark LIKE :query
            )
            ORDER BY 
                CASE 
                    WHEN label LIKE :query_exact THEN 1
                    WHEN address_line1 LIKE :query_exact THEN 2
                    WHEN city LIKE :query_exact THEN 3
                    WHEN neighborhood LIKE :query_exact THEN 4
                    ELSE 5
                END,
                is_default DESC,
                created_at DESC
            LIMIT 20";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':user_id' => $userId,
        ':query' => $searchTerm,
        ':query_exact' => "$query%"
    ]);
    
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($locations)) {
        ResponseHandler::success([
            'locations' => [],
            'total_count' => 0
        ]);
        return;
    }
    
    // Get user's default address ID for formatting
    $defaultStmt = $conn->prepare(
        "SELECT default_address_id FROM users WHERE id = :user_id"
    );
    $defaultStmt->execute([':user_id' => $userId]);
    $user = $defaultStmt->fetch(PDO::FETCH_ASSOC);
    $defaultAddressId = $user['default_address_id'] ?? null;
    
    // Format location data
    $formattedLocations = [];
    foreach ($locations as $loc) {
        $formattedLocations[] = formatLocationData($loc, $defaultAddressId);
    }
    
    ResponseHandler::success([
        'locations' => $formattedLocations,
        'total_count' => count($formattedLocations)
    ]);
}

/*********************************
 * DELETE MULTIPLE LOCATIONS
 *********************************/
function deleteMultipleLocations($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $locationIds = $data['ids'] ?? [];
    
    if (!is_array($locationIds) || empty($locationIds)) {
        ResponseHandler::error('Location IDs array is required', 400);
    }
    
    // Convert all IDs to integers and filter out invalid ones
    $validIds = [];
    foreach ($locationIds as $id) {
        $intId = intval($id);
        if ($intId > 0) {
            $validIds[] = $intId;
        }
    }
    
    if (empty($validIds)) {
        ResponseHandler::error('No valid location IDs provided', 400);
    }
    
    // Check if any of these locations are default
    $placeholders = implode(',', array_fill(0, count($validIds), '?'));
    $checkDefaultStmt = $conn->prepare(
        "SELECT id FROM addresses 
         WHERE user_id = ? AND id IN ($placeholders) AND is_default = 1"
    );
    $checkDefaultStmt->execute(array_merge([$userId], $validIds));
    $defaultLocations = $checkDefaultStmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($defaultLocations)) {
        ResponseHandler::error('Cannot delete default locations', 400);
    }
    
    // Start transaction
    $conn->beginTransaction();
    
    try {
        // Delete the locations
        $deleteStmt = $conn->prepare(
            "DELETE FROM addresses 
             WHERE user_id = ? AND id IN ($placeholders)"
        );
        $deleteStmt->execute(array_merge([$userId], $validIds));
        
        $deletedCount = $deleteStmt->rowCount();
        
        $conn->commit();
        
        // Log activity
        logUserActivity($conn, $userId, 'locations_deleted', "Deleted $deletedCount locations");
        
        ResponseHandler::success([
            'message' => "$deletedCount locations deleted successfully",
            'deleted_count' => $deletedCount,
            'deleted_ids' => $validIds
        ]);
        
    } catch (PDOException $e) {
        $conn->rollBack();
        ResponseHandler::error('Database error: ' . $e->getMessage(), 500);
    } catch (Exception $e) {
        $conn->rollBack();
        ResponseHandler::error('Failed to delete locations: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * HELPER FUNCTIONS
 *********************************/

/*********************************
 * FORMAT LOCATION DATA
 *********************************/
function formatLocationData($loc, $defaultAddressId = null) {
    // Determine location type based on label or other criteria
    $type = determineLocationType($loc['label'], $loc['city'], $loc['neighborhood']);
    
    // Determine if this is the default location
    $isDefault = boolval($loc['is_default']);
    $isCurrent = ($loc['id'] == $defaultAddressId);
    
    // Generate display address
    $displayAddress = generateDisplayAddress($loc);
    $shortAddress = generateShortAddress($loc);
    
    // Get type info
    $typeInfo = getLocationTypeInfo($type);
    
    return [
        'id' => $loc['id'],
        'user_id' => $loc['user_id'],
        'name' => $loc['label'],
        'full_name' => $loc['full_name'] ?? '',
        'phone' => $loc['phone'] ?? '',
        'address' => $loc['address_line1'] ?? '',
        'apartment' => $loc['address_line2'] ?? '',
        'city' => $loc['city'] ?? '',
        'area' => $loc['city'] ?? '', // For compatibility with Flutter UI
        'sector' => $loc['neighborhood'] ?? '', // For compatibility with Flutter UI
        'neighborhood' => $loc['neighborhood'] ?? '',
        'landmark' => $loc['landmark'] ?? '',
        'type' => $type,
        'is_default' => $isDefault,
        'is_current' => $isCurrent,
        'latitude' => $loc['latitude'] ?? null,
        'longitude' => $loc['longitude'] ?? null,
        'created_at' => $loc['created_at'] ?? null,
        'updated_at' => $loc['updated_at'] ?? null,
        'display_address' => $displayAddress,
        'short_address' => $shortAddress,
        'type_icon' => $typeInfo['icon'],
        'type_color' => $typeInfo['color']
    ];
}

/*********************************
 * DETERMINE LOCATION TYPE
 *********************************/
function determineLocationType($label, $city, $neighborhood) {
    $label = strtolower($label);
    
    if (strpos($label, 'home') !== false || 
        strpos($label, 'house') !== false || 
        strpos($label, 'residence') !== false) {
        return 'home';
    }
    
    if (strpos($label, 'work') !== false || 
        strpos($label, 'office') !== false || 
        strpos($label, 'business') !== false) {
        return 'work';
    }
    
    // Check if it's in a commercial area
    if ($neighborhood && (
        strpos(strtolower($neighborhood), 'sector') !== false ||
        strpos(strtolower($city), 'area') !== false
    )) {
        // Could be work or other based on context
        return 'other';
    }
    
    return 'other';
}

/*********************************
 * GENERATE DISPLAY ADDRESS
 *********************************/
function generateDisplayAddress($loc) {
    $parts = [];
    
    if (!empty($loc['address_line1'])) {
        $parts[] = $loc['address_line1'];
    }
    
    if (!empty($loc['address_line2'])) {
        $parts[] = $loc['address_line2'];
    }
    
    if (!empty($loc['landmark'])) {
        $parts[] = 'Near ' . $loc['landmark'];
    }
    
    if (!empty($loc['neighborhood']) && !empty($loc['city'])) {
        $parts[] = $loc['neighborhood'] . ', ' . $loc['city'];
    } elseif (!empty($loc['city'])) {
        $parts[] = $loc['city'];
    }
    
    return implode(', ', array_filter($parts));
}

/*********************************
 * GENERATE SHORT ADDRESS
 *********************************/
function generateShortAddress($loc) {
    $parts = [];
    
    if (!empty($loc['landmark'])) {
        $parts[] = 'Near ' . $loc['landmark'];
    }
    
    if (!empty($loc['city'])) {
        $parts[] = $loc['city'];
    }
    
    if (!empty($loc['neighborhood'])) {
        $parts[] = $loc['neighborhood'];
    }
    
    return implode(', ', $parts);
}

/*********************************
 * GET LOCATION TYPE INFO
 *********************************/
function getLocationTypeInfo($type) {
    $types = [
        'home' => [
            'icon' => 'home',
            'color' => '#2196F3' // Blue
        ],
        'work' => [
            'icon' => 'work',
            'color' => '#4CAF50' // Green
        ],
        'other' => [
            'icon' => 'location_on',
            'color' => '#FF9800' // Orange
        ]
    ];

    return $types[$type] ?? $types['other'];
}

/*********************************
 * LOG USER ACTIVITY
 *********************************/
function logUserActivity($conn, $userId, $activityType, $description) {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO user_activities 
                (user_id, activity_type, description, ip_address, user_agent, created_at)
             VALUES 
                (:user_id, :activity_type, :description, :ip_address, :user_agent, NOW())"
        );
        
        $stmt->execute([
            ':user_id' => $userId,
            ':activity_type' => $activityType,
            ':description' => $description,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        // Silently fail logging - don't break the main functionality
        error_log('Failed to log user activity: ' . $e->getMessage());
    }
}


?>