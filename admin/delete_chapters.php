<?php
session_start();
require_once '../includes/db_connect.php'; // adjust if needed

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

if (isset($_POST['delete']) && !empty($_POST['chapters'])) {
    $chapter_ids = $_POST['chapters'];
    $series_id = intval($_POST['series_id']);

    // Prepare delete query safely
    $placeholders = implode(',', array_fill(0, count($chapter_ids), '?'));
    $stmt = $pdo->prepare("DELETE FROM chapters WHERE id IN ($placeholders)");

    if ($stmt->execute($chapter_ids)) {
        $_SESSION['success'] = "Selected chapters deleted successfully.";
    } else {
        $_SESSION['error'] = "Error deleting chapters.";
    }

    header("Location: ../series.php?id=" . $series_id);
    exit;
} else {
    $_SESSION['error'] = "No chapters selected for deletion.";
    header("Location: ../index.php");
    exit;
}
?>
