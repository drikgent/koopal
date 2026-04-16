<?php
session_start();
header('Content-Type: application/json'); // Set header for JSON response

// 1. Check for valid user session
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit();
}

// 2. Include database connection
require_once 'includes/db_connect.php';

// 3. Get input data from the AJAX POST request
$user_id = $_SESSION['user_id'];
$series_id = isset($_POST['series_id']) ? intval($_POST['series_id']) : 0;
$action = isset($_POST['action']) ? $_POST['action'] : '';

// Basic validation
if ($series_id === 0 || !in_array($action, ['add', 'remove'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request parameters.']);
    exit();
}

$success = false;
$message = '';

try {
    if ($action === 'add') {
        // SQL to insert the favorite relationship
        // The IGNORE prevents errors if the user double-clicks or if a favorite already exists
        $sql = "INSERT IGNORE INTO Favorites (user_id, series_id) VALUES (?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $series_id]);

        $success = ($stmt->rowCount() > 0); // Check if a new row was inserted
        $message = $success ? 'Added to Favorites! 💖' : 'Already in Favorites.';

    } elseif ($action === 'remove') {
        // SQL to delete the favorite relationship
        $sql = "DELETE FROM Favorites WHERE user_id = ? AND series_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $series_id]);

        $success = ($stmt->rowCount() > 0); // Check if a row was deleted
        $message = $success ? 'Removed from Favorites! 🗑️' : 'Not found in Favorites.';
    }

} catch (\PDOException $e) {
    // Log the error for debugging
    error_log("Favorite Handler Error: " . $e->getMessage());
    $success = false;
    $message = "Database error. Please try again.";
}

// 4. Return the final JSON response to the JavaScript
echo json_encode(['success' => $success, 'message' => $message, 'action' => $action]);

exit();
?>