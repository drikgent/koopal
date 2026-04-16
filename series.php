<?php
session_start();
if (!isset($_SESSION['user_id'])) {
header("Location: login.php");
exit();
}
require_once 'includes/db_connect.php'; // Include your database connection script
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

function format_chapter_number($number) {
    if ($number === null || $number === '') return '';

    $num = (float)$number;

    // Treat 0 and 0.0 as Prologue
    if ($num == 0.0) return 'Prologue';

    // If whole number, show without decimals
    if (floor($num) == $num) {
        return (string)intval($num);
    }

    // Keep up to 2 decimals, remove trailing zeros
    return rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
}

function resolve_public_asset_url($path) {
    $path = trim((string) $path);
    if ($path === '') {
        return $path;
    }

    if (preg_match('~^(https?:)?//~i', $path)) {
        return $path;
    }

    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = rtrim($scriptDir, '/');
    if ($basePath === '/' || $basePath === '\\') {
        $basePath = '';
    }

    if (strpos($path, '/uploads/') === 0 || strpos($path, '/assets/') === 0) {
        return $basePath . $path;
    }

    if (strpos($path, 'uploads/') === 0 || strpos($path, 'assets/') === 0) {
        return ($basePath !== '' ? $basePath . '/' : '') . ltrim($path, '/');
    }

    if (strpos($path, '/') === 0) {
        return $basePath . $path;
    }

    return ($basePath !== '' ? $basePath . '/' : '') . ltrim($path, '/');
}

function ensure_series_comments_table_exists(PDO $pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS series_comments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            series_id INT NOT NULL,
            user_id INT NOT NULL,
            content TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_series_comments_series (series_id),
            INDEX idx_series_comments_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function ensure_series_ratings_table_exists(PDO $pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS series_ratings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            series_id INT NOT NULL,
            user_id INT NOT NULL,
            rating TINYINT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_series_user_rating (series_id, user_id),
            INDEX idx_series_ratings_series (series_id),
            INDEX idx_series_ratings_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function time_elapsed_short($datetime) {
    if (empty($datetime)) {
        return '';
    }

    $timestamp = strtotime((string) $datetime);
    if ($timestamp === false) {
        return '';
    }

    $diff = time() - $timestamp;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M d, Y', $timestamp);
}


$series_id = isset($_GET['id']) ? intval($_GET['id']) : 0; // Get the series ID from the URL

if (isset($_GET['id'])) {
    $series_id = (int)$_GET['id'];

    // Increment views count
    $updateViews = $pdo->prepare("UPDATE series SET views = views + 1 WHERE id = ?");
    $updateViews->execute([$series_id]);
}


if ($series_id === 0) {
// Redirect to the homepage if no valid ID is provided
header("Location: index.php");
exit();
}

$series_details = null;
$chapters = [];
$error_message = '';
$whole_number_chapter_count = 0; // NEW: Initialize a counter for whole numbers
$genres = []; // NEW: Initialize an array for genres
$nav_genres = [];
$comment_message = '';
$comment_message_type = 'success';
$comments = [];
$editing_comment_id = isset($_GET['edit_comment']) ? (int) $_GET['edit_comment'] : 0;
$edit_comment_content = '';
$user_rating = 0;
$average_rating = 0;
$rating_count = 0;

try {
    ensure_series_comments_table_exists($pdo);
    ensure_series_ratings_table_exists($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $rating_action = $_POST['rating_action'] ?? '';
        $comment_action = $_POST['comment_action'] ?? '';
        $comment_id = (int) ($_POST['comment_id'] ?? 0);
        $comment_content = trim($_POST['comment_content'] ?? '');
        $redirect_url = 'series.php?id=' . $series_id;

        if ($rating_action === 'rate_series') {
            $rating = (int) ($_POST['rating'] ?? 0);

            if ($rating < 1 || $rating > 5) {
                $comment_message = 'Please choose a rating from 1 to 5.';
                $comment_message_type = 'error';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO series_ratings (series_id, user_id, rating)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE rating = VALUES(rating), updated_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$series_id, (int) $_SESSION['user_id'], $rating]);
                header('Location: ' . $redirect_url . '#series-rating');
                exit();
            }
        } elseif ($comment_action === 'add_comment') {
            if ($comment_content === '') {
                $comment_message = 'Comment cannot be empty.';
                $comment_message_type = 'error';
            } else {
                $stmt = $pdo->prepare('INSERT INTO series_comments (series_id, user_id, content) VALUES (?, ?, ?)');
                $stmt->execute([$series_id, (int) $_SESSION['user_id'], $comment_content]);
                header('Location: ' . $redirect_url . '#series-comments');
                exit();
            }
        } elseif ($comment_action === 'update_comment' && $comment_id > 0) {
            $stmt = $pdo->prepare('SELECT user_id FROM series_comments WHERE id = ? AND series_id = ?');
            $stmt->execute([$comment_id, $series_id]);
            $target_comment = $stmt->fetch();

            if (!$target_comment) {
                $comment_message = 'Comment not found.';
                $comment_message_type = 'error';
            } elseif ((int) $target_comment['user_id'] !== (int) $_SESSION['user_id']) {
                $comment_message = 'You can only edit your own comment.';
                $comment_message_type = 'error';
            } elseif ($comment_content === '') {
                $comment_message = 'Comment cannot be empty.';
                $comment_message_type = 'error';
                $editing_comment_id = $comment_id;
                $edit_comment_content = $comment_content;
            } else {
                $stmt = $pdo->prepare('UPDATE series_comments SET content = ? WHERE id = ?');
                $stmt->execute([$comment_content, $comment_id]);
                header('Location: ' . $redirect_url . '#series-comments');
                exit();
            }
        } elseif ($comment_action === 'delete_comment' && $comment_id > 0) {
            $stmt = $pdo->prepare('SELECT user_id FROM series_comments WHERE id = ? AND series_id = ?');
            $stmt->execute([$comment_id, $series_id]);
            $target_comment = $stmt->fetch();

            if (!$target_comment) {
                $comment_message = 'Comment not found.';
                $comment_message_type = 'error';
            } elseif (!$is_admin && (int) $target_comment['user_id'] !== (int) $_SESSION['user_id']) {
                $comment_message = 'You can only delete your own comment.';
                $comment_message_type = 'error';
            } else {
                $stmt = $pdo->prepare('DELETE FROM series_comments WHERE id = ?');
                $stmt->execute([$comment_id]);
                header('Location: ' . $redirect_url . '#series-comments');
                exit();
            }
        }
    }

    // Fetch series details
    $stmt = $pdo->prepare("SELECT id, title, author, description, cover_image, genre, status FROM series WHERE id = ?");
    $stmt->execute([$series_id]);
    $series_details = $stmt->fetch();

    if (!$series_details) {
        $error_message = "Series not found.";
    } else {
        // Process the genre string into an array of links
        if (!empty($series_details['genre'])) {
            $raw_genres = explode(',', $series_details['genre']);
            foreach ($raw_genres as $genre) {
                $genre = trim($genre);
                if (!empty($genre)) {
                    $genres[] = [
                        'name' => $genre,
                        'url' => 'genre.php?name=' . urlencode($genre)
                    ];
                }
            }
        }
        try {
            $genres_stmt = $pdo->query("SELECT DISTINCT genre FROM series");
            $raw_nav_genres = $genres_stmt->fetchAll(PDO::FETCH_COLUMN);
            $unique_nav_genres = [];

            foreach ($raw_nav_genres as $genre_list) {
                $genre_items = array_map('trim', explode(',', $genre_list));
                foreach ($genre_items as $genre_item) {
                    if ($genre_item !== '') {
                        $unique_nav_genres[] = $genre_item;
                    }
                }
            }

            $nav_genres = array_values(array_unique($unique_nav_genres));
            sort($nav_genres);
        } catch (\PDOException $e) {
            error_log("Error fetching navigation genres: " . $e->getMessage());
        }

       $stmt = $pdo->prepare("
    SELECT 
        c.id, 
        c.chapter_number, 
        c.release_date,
        CASE WHEN EXISTS (
            SELECT 1 FROM user_read_chapter_events e
            WHERE e.user_id = ? 
            AND e.series_id = c.series_id
            AND e.chapter_id = c.id
        ) THEN 1 ELSE 0 END AS is_read
    FROM chapters c
    WHERE c.series_id = ?
    ORDER BY CAST(c.chapter_number AS DECIMAL(10,3)) DESC
");
$stmt->execute([$_SESSION['user_id'], $series_id]);
$chapters = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ✅ Remove duplicates by chapter_number (keep latest)
$unique_chapters = [];
foreach ($chapters as $chapter) {
    // Use string key to preserve decimals safely (no float precision issue)
    $key = (string)$chapter['chapter_number'];
    $unique_chapters[$key] = $chapter;
}


// ✅ Sort properly (handles decimals)
usort($unique_chapters, function($a, $b) {
    return floatval($b['chapter_number']) <=> floatval($a['chapter_number']);
});

$chapters = $unique_chapters;



        // Loop through chapters and count only the whole numbers
        foreach ($chapters as $chapter) {
            if (floor($chapter['chapter_number']) == $chapter['chapter_number']) {
                $whole_number_chapter_count++;
            }
        }

        $stmt = $pdo->prepare("
            SELECT COALESCE(AVG(rating), 0) AS average_rating, COUNT(*) AS rating_count
            FROM series_ratings
            WHERE series_id = ?
        ");
        $stmt->execute([$series_id]);
        $rating_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($rating_stats) {
            $average_rating = round((float) $rating_stats['average_rating'], 2);
            $rating_count = (int) $rating_stats['rating_count'];
        }

        $stmt = $pdo->prepare("
            SELECT rating
            FROM series_ratings
            WHERE series_id = ? AND user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$series_id, (int) $_SESSION['user_id']]);
        $user_rating = (int) ($stmt->fetchColumn() ?: 0);

        $stmt = $pdo->prepare("
            SELECT sc.id, sc.user_id, sc.content, sc.created_at, sc.updated_at, u.username, u.profile_image
            FROM series_comments sc
            INNER JOIN users u ON u.id = sc.user_id
            WHERE sc.series_id = ?
            ORDER BY sc.created_at DESC
        ");
        $stmt->execute([$series_id]);
        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($editing_comment_id > 0) {
            foreach ($comments as $comment) {
                if ((int) $comment['id'] === $editing_comment_id && (int) $comment['user_id'] === (int) $_SESSION['user_id']) {
                    if ($edit_comment_content === '') {
                        $edit_comment_content = $comment['content'];
                    }
                    break;
                }
            }
        }
    }

} catch (\PDOException $e) {
    error_log("Error fetching series details or chapters: " . $e->getMessage());
    $error_message = "Could not load series details. Please try again later.";
}

// series.php (Around Line 52, after the chapters loop)

// NEW: 1. Check if this series is favorited by the current user
$is_favorite = false;
$user_id = $_SESSION['user_id']; // Already checked for existence at the top of the file

try {
    $stmt = $pdo->prepare("SELECT 1 FROM Favorites WHERE user_id = ? AND series_id = ?");
    $stmt->execute([$user_id, $series_id]);
    
    // If a row is returned, the series is a favorite
    if ($stmt->fetch()) {
        $is_favorite = true;
    }
} catch (\PDOException $e) {
    // Log the error but don't stop the page load
    error_log("Error checking favorite status: " . $e->getMessage());
}

// ... rest of the original PHP code continues ...
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($series_details['title'] ?? 'Series'); ?> Manwha</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            background: #09111b;
            color: #f6f8fb;
        }
        header {
            position: sticky;
            top: 0;
            z-index: 50;
            background: rgba(7, 11, 18, 0.92);
            backdrop-filter: blur(18px);
        }
        header .container {
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 10px 0;
            width: 100%;
            max-width: 100%;
        }
        nav {
            flex: 1;
            width: 100%;
        }
        .main-nav {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 16px;
            width: 100%;
            margin: 0;
            padding: 0;
        }
        .nav-links,
        .nav-tools {
            display: flex;
            align-items: center;
            gap: 14px;
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .nav-tools {
            margin-left: auto;
            justify-content: flex-end;
            flex: 1;
        }
        .series-header {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
            flex-wrap: wrap;
            align-items: flex-start;
        }
        .series-header img {
            width: 100%;
            max-width: 250px;
            height: auto;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            object-fit: cover;
            margin-top: 10px;
            flex-shrink: 0;
        }
        .series-info {
            flex: 1;
            min-width: 300px;
        }
        .series-info h2 {
            margin-top: 0;
            font-size: 2.2em;
        }
        .series-info p {
            margin: 5px 0;
        }
        .chapter-container {
            margin-top: 20px;
        }
        .comments-shell {
            margin-top: 28px;
            padding: 22px;
            border-radius: 22px;
            background: linear-gradient(180deg, rgba(14, 20, 31, 0.95), rgba(10, 16, 26, 0.95));
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: 0 16px 30px rgba(0,0,0,0.2);
        }
        .comments-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
        }
        .comments-head h3 {
            margin: 0;
            font-size: 1.55rem;
        }
        .comment-count {
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(39, 150, 255, 0.12);
            border: 1px solid rgba(58, 160, 255, 0.24);
            color: #98d3ff;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .comment-form {
            display: grid;
            gap: 12px;
            margin-bottom: 20px;
        }
        .comment-form textarea {
            min-height: 120px;
            width: 100%;
            resize: vertical;
            border-radius: 16px;
            border: 1px solid rgba(255,255,255,0.12);
            background: rgba(255,255,255,0.05);
            color: #fff;
            padding: 14px 16px;
            font: inherit;
            box-sizing: border-box;
        }
        .comment-form-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .comment-submit,
        .comment-cancel,
        .comment-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 18px;
            border-radius: 12px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font: inherit;
            font-weight: 700;
        }
        .comment-submit {
            background: linear-gradient(135deg, #2b93ff, #2876ea);
            color: #fff;
        }
        .comment-cancel {
            background: rgba(255,255,255,0.08);
            color: #fff;
        }
        .comment-list {
            display: grid;
            gap: 14px;
        }
        .comment-card {
            padding: 18px;
            border-radius: 16px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.08);
        }
        .comment-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }
        .comment-user {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }
        .comment-avatar {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            object-fit: cover;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
        }
        .comment-user-meta {
            display: grid;
            gap: 2px;
        }
        .comment-username {
            font-weight: 800;
            color: #fff;
        }
        .comment-time {
            color: rgba(255,255,255,0.6);
            font-size: 0.9rem;
        }
        .comment-body {
            color: rgba(255,255,255,0.9);
            line-height: 1.7;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .comment-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .comment-action-btn {
            min-height: 36px;
            padding: 0 14px;
            background: rgba(255,255,255,0.07);
            color: #fff;
        }
        .comment-action-btn.delete {
            background: rgba(255, 88, 108, 0.14);
            color: #ffc0c9;
        }
        .comment-empty {
            text-align: center;
            padding: 20px 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.03);
            color: rgba(255,255,255,0.7);
        }
        .chapter-list{
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 10px;
            list-style: none;
            padding: 0;
        }
        .chapter-list li{
            background: #000000ff;
            padding: 12px;
            border: 1px solid black;
            border-radius: 6px;
            transition: background-color 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .chapter-list li:hover {
            background-color: #000000ff;
        }
        .chapter-list li a{
            text-decoration: none;
            color: white;
        }
        .chapter-read a {
            color: #007bff !important;
            font-weight: bold;
        }
        .chapter-list li.hidden-chapter {
            display: none !important;
        }
        .see-more-btn {
            display: block;
            margin: 20px auto;
            padding: 10px 20px;
            border: none;
            background: #007bff;
            color: white;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            transition: background 0.2s;
        }
        .see-more-btn:hover {
            background: #0056b3;
        }
        nav ul {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        nav ul .logout-link {
            margin-left: auto;
        }
        nav ul .logout-link:hover {
            background-color: red;
            transition: 0.1s;
            border-radius: 5px;
        }
        .logo-title {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: inherit;
        }
        .site-logo {
            height: 60px;
            width: 60px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
        }
        .site-title {
            font-size: 2.5em;
            margin: 0;
        }
        @media (max-width: 900px) {
            .site-logo {
                height: 40px;
                width: 40px;
            }
            .site-title {
                font-size: 1.7em;
            }
            .series-header img {
                max-width: 180px;
            }
        }
        @media (max-width: 600px) {
            .site-logo {
                height: 60px;
                width: 60px;
            }
            .site-title {
                font-size: 2.1em;
            }
            .series-header img {
                max-width: 100px;
            }
            .series-header {
                flex-direction: column;
                align-items: center;
                gap: 10px;
            }
            .series-info {
                min-width: unset;
                width: 100%;
            }

            .user-btn {
                margin-left: 40px;
            }
        }
        .user-btn {
            background: #007bff;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 8px 18px;
            cursor: pointer;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .user-btn:focus {
            outline: none;
        }
        .user-btn.admin-user-btn {
            background: linear-gradient(135deg, #0d7bff 0%, #19a1ff 100%);
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(13, 123, 255, 0.28);
            padding: 10px 16px;
        }
        .role-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 8px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.18);
            color: #eef8ff;
            font-size: 0.68rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 700;
        }
        .admin-quick-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 9px 14px;
            border-radius: 10px;
            background: linear-gradient(135deg, rgba(13, 123, 255, 0.22) 0%, rgba(25, 161, 255, 0.16) 100%);
            border: 1px solid rgba(78, 194, 255, 0.22);
            color: #dff3ff;
            text-decoration: none;
            font-weight: 700;
        }
        .admin-quick-link:hover {
            background: linear-gradient(135deg, rgba(13, 123, 255, 0.3) 0%, rgba(25, 161, 255, 0.24) 100%);
        }
        .mobile-menu-toggle {
            display: none;
            width: 44px;
            height: 44px;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            border: 1px solid rgba(109, 181, 255, 0.2);
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            font-size: 1.1rem;
            cursor: pointer;
        }
        .genre-trigger {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: transparent;
            border: none;
            color: inherit;
            font: inherit;
            cursor: pointer;
            padding: 0;
            text-transform: uppercase;
        }
        .genre-trigger i {
            font-size: 0.72em;
        }
        .genre-menu:hover .genre-dropdown {
            display: block !important;
        }
        @media (max-width: 600px) {
            .main-nav {
                flex-wrap: wrap;
            }
            .nav-tools {
                margin-left: 0;
                flex: 1 1 100%;
                justify-content: flex-start;
            }
        }
        .logout-dropdown a {
            color: black;
            font-weight: bold;
            background: transparent;
            transition: background 0.2s;
        }
        .logout-dropdown a:hover {
            background: #f4f4f4;
        }
        .btn-read {
            display: inline-block;
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
            transition: background 0.2s;
        }
        .btn-read:first-child {
            background: #007bff;
            color: #fff;
        }
        .btn-read-latest {
            margin-top: 5px;
            background: #28a745;
            color: #fff;
        }

        .btn-read-latest {
            display: inline-block;
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
            transition: background 0.2s;
        }

        .btn-read-latest:hover {
            opacity: 0.9;
        }

        .btn-read:hover {
            opacity: 0.9;
        }
        /* NEW: Styles for the genre tags */
        .genre-tag {
            display: inline-block;
            background-color: #333; /* Dark background for tags */
            color: #fff;
            padding: 4px 8px;
            margin-right: 5px; /* Spacing between tags */
            margin-bottom: 5px; /* Spacing for wrapping */
            border-radius: 12px; /* Pill shape */
            text-decoration: none; /* Remove underline */
            font-size: 0.9em;
            transition: background-color 0.2s;
        }
        .genre-tag:hover {
            background-color: #007bff; /* Highlight color on hover */
        }

.favorite-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: bold;
    transition: background-color 0.2s, color 0.2s, border-color 0.2s;
    margin-top: 15px; /* Spacing below the author/genre info */
    width: fit-content;
}
.favorite-btn .icon {
    font-size: 1.2em;
}

/* Style when it is NOT favorited (Add) */
.favorite-btn.not-favorited {
    background: #495057; /* Dark gray background */
    color: #fff;
    border: 1px solid #495057;
}
.favorite-btn.not-favorited:hover {
    background: #6c757d; /* Lighter gray on hover */
}

/* Style when it IS favorited (Remove) */
.favorite-btn.favorited {
    background: #fff; /* White background */
    color: #dc3545; /* Red icon/text color */
    border: 1px solid #dc3545; /* Red border */
}
.favorite-btn.favorited:hover {
    background: #f8d7da; /* Light pink background on hover */
}

.add-chapter {
    background-color: black;
    display: inline-block;
    padding: 10px 20px;
    border-radius: 6px;
    text-decoration: none;
    font-weight: bold;
    transition: background 0.2s;
    color: #fff;
}

.add-chapter:hover {
            opacity: 0.9;
        }

        .delete-btn {
    background-color: #e74c3c;
    color: white;
    border: none;
    border-radius: 6px;
    padding: 8px 16px;
    cursor: pointer;
    margin-top: 10px;
}
        .delete-btn:hover {
    background-color: #c0392b;
}
.chapter-list input[type="checkbox"] {
    transform: scale(1.2);
    cursor: pointer;
}

        main.container {
            max-width: 1180px;
            padding-top: 34px;
            padding-bottom: 44px;
        }
        .series-page {
            display: grid;
            gap: 28px;
        }
        .series-hero {
            position: relative;
            display: grid;
            grid-template-columns: 270px minmax(0, 1fr);
            gap: 26px;
            padding: 26px;
            border-radius: 28px;
            background: linear-gradient(180deg, rgba(11, 19, 31, 0.96) 0%, rgba(10, 15, 23, 0.96) 100%);
            border: 1px solid rgba(88, 166, 255, 0.14);
            box-shadow: 0 24px 54px rgba(0, 0, 0, 0.28);
            overflow: hidden;
        }
        .series-hero::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                linear-gradient(135deg, rgba(88, 166, 255, 0.1), transparent 32%),
                radial-gradient(circle at bottom right, rgba(37, 201, 135, 0.12), transparent 24%);
            pointer-events: none;
        }
        .series-cover-wrap,
        .series-main {
            position: relative;
            z-index: 1;
        }
        .series-cover-wrap {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .series-cover-wrap img {
            width: 100%;
            max-width: none;
            height: 390px;
            object-fit: cover;
            border-radius: 22px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 18px 40px rgba(0, 0, 0, 0.28);
            margin-top: 0;
        }
        .series-main {
            min-width: 0;
        }
        .series-main h2 {
            margin: 0 0 16px;
            font-size: clamp(2.3rem, 4vw, 3.4rem);
            line-height: 0.96;
            letter-spacing: -0.06em;
        }
        .series-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 18px;
        }
        .summary-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #eef6ff;
            font-size: 0.88rem;
            font-weight: 700;
        }
        .series-meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }
        .meta-card {
            padding: 14px 16px;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.07);
        }
        .meta-label {
            display: block;
            margin-bottom: 8px;
            color: rgba(255, 255, 255, 0.62);
            font-size: 0.76rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .meta-value {
            display: block;
            font-size: 1.05rem;
            font-weight: 700;
            line-height: 1.5;
        }
        .meta-card.full-span {
            grid-column: 1 / -1;
        }
        .genre-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .description-card {
            margin-top: 2px;
        }
        .description-content {
            color: rgba(255, 255, 255, 0.84);
            line-height: 1.75;
            max-height: 8.8em;
            overflow: hidden;
            position: relative;
        }
        .description-content.is-expanded {
            max-height: none;
        }
        .description-content:not(.is-expanded)::after {
            content: "";
            position: absolute;
            inset: auto 0 0 0;
            height: 54px;
            background: linear-gradient(180deg, rgba(11, 19, 31, 0), rgba(11, 19, 31, 0.96));
        }
        .description-toggle {
            margin-top: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid rgba(88, 166, 255, 0.18);
            background: rgba(88, 166, 255, 0.08);
            color: #dfefff;
            font-weight: 700;
            cursor: pointer;
        }
        .series-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 18px;
        }
        .rating-panel {
            margin-top: 18px;
            padding: 16px 18px;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.07);
        }
        .rating-panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .rating-title {
            display: grid;
            gap: 2px;
        }
        .rating-label {
            font-size: 0.78rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(255,255,255,0.58);
            font-weight: 700;
        }
        .rating-value {
            font-size: 1.4rem;
            font-weight: 800;
            color: #fff;
        }
        .rating-value small {
            font-size: 0.95rem;
            color: rgba(255,255,255,0.66);
            font-weight: 600;
        }
        .rating-stars {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .rating-star-btn {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(255,255,255,0.04);
            color: rgba(255,255,255,0.45);
            font-size: 1.25rem;
            cursor: pointer;
            transition: transform 0.18s ease, border-color 0.18s ease, color 0.18s ease, background 0.18s ease;
        }
        .rating-star-btn:hover,
        .rating-star-btn.active {
            transform: translateY(-2px);
            border-color: rgba(255, 198, 76, 0.45);
            background: rgba(255, 197, 76, 0.12);
            color: #ffc64c;
        }
        .rating-note {
            margin-top: 10px;
            font-size: 0.92rem;
            color: rgba(255,255,255,0.66);
        }
        .favorite-btn,
        .btn-read,
        .btn-read-latest,
        .add-chapter {
            min-height: 50px;
            padding: 0 18px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-weight: 800;
            letter-spacing: 0.01em;
            transition: transform 0.18s ease, filter 0.18s ease, box-shadow 0.18s ease;
        }
        .favorite-btn:hover,
        .btn-read:hover,
        .btn-read-latest:hover,
        .add-chapter:hover {
            transform: translateY(-2px);
            filter: brightness(1.04);
        }
        .btn-read {
            background: linear-gradient(135deg, #1a84ff 0%, #42abff 100%);
            color: #fff;
            box-shadow: 0 16px 28px rgba(26, 132, 255, 0.24);
        }
        .btn-read-latest {
            margin-top: 0;
            background: linear-gradient(135deg, #27b25f 0%, #44d57d 100%);
            color: #fff;
            box-shadow: 0 16px 28px rgba(39, 178, 95, 0.22);
        }
        .add-chapter {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #fff;
        }
        .favorite-btn {
            margin-top: 0;
        }
        .chapter-section {
            padding: 24px;
            border-radius: 28px;
            background: linear-gradient(180deg, rgba(11, 19, 31, 0.94) 0%, rgba(10, 15, 23, 0.96) 100%);
            border: 1px solid rgba(88, 166, 255, 0.12);
            box-shadow: 0 24px 54px rgba(0, 0, 0, 0.24);
        }
        .chapter-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }
        .chapter-section-header h3 {
            margin: 0;
            font-size: 1.8rem;
            letter-spacing: -0.04em;
        }
        .chapter-tools {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }
        .chapter-tools input,
        .chapter-tools select {
            min-height: 44px;
            padding: 0 14px;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
        }
        .chapter-tools select option {
            color: #111;
        }
        .chapter-search {
            min-width: 220px;
        }
        .chapter-list {
            gap: 14px;
        }
        .chapter-card {
            position: relative;
            padding: 16px 16px 14px;
            border-radius: 18px;
            background: linear-gradient(180deg, rgba(17, 24, 36, 0.98) 0%, rgba(11, 16, 24, 0.98) 100%);
            border: 1px solid rgba(255, 255, 255, 0.06);
            transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
            flex-wrap: wrap;
            align-items: center;
        }
        .chapter-card:hover {
            transform: translateY(-3px);
            border-color: rgba(88, 166, 255, 0.18);
            box-shadow: 0 18px 34px rgba(0, 0, 0, 0.2);
        }
        .chapter-card a {
            font-size: 1.05rem;
            font-weight: 700;
        }
        .chapter-card .chapter-date {
            color: rgba(255, 255, 255, 0.54) !important;
            font-size: 0.8rem !important;
            margin-left: auto !important;
            width: auto !important;
        }
        .chapter-card.chapter-read {
            border-color: rgba(88, 166, 255, 0.16);
            background: linear-gradient(180deg, rgba(13, 23, 38, 0.98) 0%, rgba(10, 17, 28, 0.98) 100%);
        }
        .chapter-card.chapter-read a {
            color: #7cc0ff !important;
        }
        .latest-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 28px;
            padding: 0 10px;
            border-radius: 999px;
            background: rgba(37, 201, 135, 0.14);
            border: 1px solid rgba(37, 201, 135, 0.18);
            color: #caffdd;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .chapter-empty {
            padding: 24px;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.06);
            color: rgba(255, 255, 255, 0.7);
        }

        @media (max-width: 768px) {
            body,
            html {
                overflow-x: hidden;
            }

            header .container,
            main.container,
            footer .container {
                width: 100%;
                max-width: 100%;
                padding: 0 14px !important;
                margin: 0 auto;
                box-sizing: border-box;
            }

            header .container {
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                padding-top: 12px !important;
                padding-bottom: 12px !important;
            }

            .logo-title {
                width: auto;
                justify-content: flex-start;
                gap: 10px;
                min-width: 0;
                flex: 1 1 auto;
            }

            nav {
                width: auto;
                flex: 0 0 auto;
            }

            .main-nav {
                position: relative;
                flex-direction: row;
                align-items: center;
                justify-content: flex-end;
                flex-wrap: nowrap;
                gap: 8px;
                width: auto;
            }

            .nav-links {
                position: absolute;
                top: calc(100% + 12px);
                right: 0;
                display: none !important;
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                width: min(240px, calc(100vw - 28px));
                margin: 0;
                padding: 14px;
                list-style: none;
                background: rgba(10, 15, 24, 0.97);
                border: 1px solid rgba(109, 181, 255, 0.2);
                border-radius: 20px;
                box-shadow: 0 24px 48px rgba(0, 0, 0, 0.28);
                z-index: 80;
                box-sizing: border-box;
                overflow: hidden;
            }

            .main-nav.mobile-open .nav-links {
                display: flex !important;
            }

            .nav-tools {
                width: auto;
                justify-content: flex-end;
                flex-wrap: nowrap;
                gap: 8px;
            }

            .nav-tools {
                margin-left: 0;
                flex: 0 0 auto;
            }

            .site-title {
                font-size: 1.45rem;
                line-height: 1;
                white-space: nowrap;
            }

            .site-logo {
                width: 44px;
                height: 44px;
            }

            .nav-links li a,
            .user-btn,
            .admin-quick-link,
            .mobile-menu-toggle {
                min-height: 40px;
                font-size: 0.88rem;
            }

            .nav-links li,
            .nav-links li a {
                width: 100%;
                min-width: 0;
            }

            .nav-links li a,
            .genre-trigger {
                display: flex;
                align-items: center;
                justify-content: flex-start;
                padding: 0 14px;
                border-radius: 14px;
                background: rgba(255, 255, 255, 0.05);
                border: 1px solid rgba(255, 255, 255, 0.06);
                box-sizing: border-box;
                max-width: 100%;
            }

            .genre-menu {
                position: relative;
            }

            .genre-menu .genre-dropdown {
                position: static !important;
                display: none !important;
                margin-top: 8px;
                background: rgba(255, 255, 255, 0.96) !important;
                border-radius: 14px !important;
                min-width: 100%;
                max-height: 220px;
                overflow-y: auto;
                transform: none;
                box-shadow: none;
            }

            .genre-menu.mobile-open .genre-dropdown {
                display: block !important;
            }

            .genre-menu .genre-dropdown a {
                display: block;
                padding: 12px 14px !important;
                color: #333 !important;
                text-decoration: none;
            }

            .admin-quick-link,
            .user-btn.admin-user-btn {
                padding: 8px 12px;
            }

            .user-btn {
                max-width: 132px;
                padding: 8px 14px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                margin-left: 0 !important;
            }

            .admin-quick-link {
                display: none;
            }

            .mobile-menu-toggle {
                display: inline-flex;
                width: 40px;
                min-width: 40px;
                padding: 0;
            }

            .role-badge {
                font-size: 0.62rem;
                padding: 3px 7px;
            }

            .series-header {
                flex-direction: column;
                align-items: center;
                gap: 16px;
                margin-bottom: 24px;
            }

            .series-hero {
                grid-template-columns: 1fr;
                padding: 20px;
                border-radius: 22px;
            }

            .series-header img {
                max-width: min(64vw, 240px);
                margin-top: 0;
            }

            .series-info {
                width: 100%;
                min-width: 0;
            }

            .series-info h2 {
                font-size: 2rem;
                line-height: 1.05;
            }

            .series-info p {
                line-height: 1.6;
            }

            .series-meta {
                grid-template-columns: 1fr;
            }

            .favorite-btn {
                width: 100%;
                justify-content: center;
            }
            .rating-panel-head {
                flex-direction: column;
                align-items: flex-start;
            }
            .rating-stars {
                width: 100%;
                display: grid;
                grid-template-columns: repeat(5, minmax(0, 1fr));
            }
            .rating-star-btn {
                width: 100%;
            }

            .series-actions {
                display: grid;
                grid-template-columns: 1fr;
            }

            .btn-read,
            .btn-read-latest,
            .add-chapter,
            .description-toggle {
                width: 100%;
                box-sizing: border-box;
                text-align: center;
                margin-top: 0;
            }

            .chapter-section {
                padding: 18px;
                border-radius: 22px;
            }

            .chapter-section-header {
                align-items: flex-start;
            }

            .chapter-tools {
                width: 100%;
            }

            .chapter-search,
            .chapter-tools select {
                width: 100%;
                min-width: 0;
            }

            .chapter-list {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .chapter-list li {
                padding: 14px 12px;
                align-items: flex-start;
                flex-wrap: wrap;
            }

            .chapter-list li a {
                flex: 1 1 auto;
                min-width: 0;
                line-height: 1.45;
            }

            .chapter-list li span {
                width: auto;
                margin-left: 28px !important;
                margin-top: -2px;
            }

            .delete-btn,
            .see-more-btn {
                width: 100%;
                max-width: none;
            }
        }


    </style>
</head>
<body>
<header>
    <div class="container">
        <a href="index.php" class="logo-title">
            <img src="logo.png" alt="Logo" class="site-logo">
            <h1 class="site-title">KooPal</h1>
        </a>
        <nav>
            <div class="main-nav">
                <ul class="nav-links">
                    <li><a href="index.php">HOME</a></li>
                    <li><a href="my_favorites.php">MY LIST</a></li>
                    <li class="genre-menu" style="position: relative;">
                        <button type="button" class="genre-trigger">GENRES <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="genre-dropdown" style="display: none; position: absolute; left: 0; top: 100%; background: #fff; border: 1px solid #ddd; border-radius: 6px; min-width: 150px; z-index: 10;">
                            <?php foreach ($nav_genres as $nav_genre): ?>
                                <a href="genre.php?name=<?php echo urlencode($nav_genre); ?>" style="display: block; padding: 10px 16px; color: #333; text-decoration: none;">
                                    <?php echo htmlspecialchars(ucwords($nav_genre)); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </li>
                </ul>

                <ul class="nav-tools">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <?php if ($is_admin): ?>
                            <li><a href="admin/index2.php" class="admin-quick-link">ADMIN PANEL</a></li>
                        <?php endif; ?>
                        <li class="user-menu" style="position: relative;">
                            <button class="user-btn <?php echo $is_admin ? 'admin-user-btn' : ''; ?>">
                                <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?>
                                <?php if ($is_admin): ?>
                                    <span class="role-badge">ADMIN</span>
                                <?php endif; ?>
                            </button>
                            <div class="logout-dropdown" style="display: none; position: absolute; right: 0; top: 100%; background: #fff; border: 1px solid #ddd; border-radius: 6px; min-width: 120px; z-index: 10;">
                                <a href="profile.php" style="display: block; padding: 10px 16px; color: #333; text-decoration: none;">Profile</a>
                                <a href="settings.php" style="display: block; padding: 10px 16px; color: #333; text-decoration: none;">Settings</a>
                                <a href="logout.php" style="display: block; padding: 10px 16px; color: #333; text-decoration: none;">Log out</a>
                                <?php if ($is_admin): ?>
                                    <a href="admin/index2.php" style="display: block; padding: 10px 16px; color: #333; text-decoration: none;">Admin</a>
                                <?php endif; ?>
                            </div>
                        </li>
                        <li>
                            <button type="button" class="mobile-menu-toggle" aria-label="Toggle navigation" aria-expanded="false">&#9776;</button>
                        </li>
                    <?php else: ?>
                        <li><a href="login.php">Login</a></li>
                        <li><a href="register.php">Register</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </nav>
    </div>
</header>

<main class="container">
    <?php if (!empty($error_message)): ?>
        <p class="message error"><?php echo htmlspecialchars($error_message); ?></p>
    <?php elseif ($series_details): ?>
        <div class="series-page">
        <section class="series-hero series-header">
            <div class="series-cover-wrap">
                <img src="<?php echo htmlspecialchars($series_details['cover_image'] ?? 'assets/covers/default_cover.jpg'); ?>" alt="<?php echo htmlspecialchars($series_details['title']); ?> Cover">
                <div class="series-summary">
                    <span class="summary-chip"><?php echo htmlspecialchars($series_details['status'] ?: 'Unknown'); ?></span>
                    <span class="summary-chip"><?php echo $whole_number_chapter_count; ?> Chapters</span>
                    <span class="summary-chip"><?php echo number_format($average_rating, 1); ?> Rating</span>
                </div>
            </div>
            <div class="series-main series-info">
                <h2><?php echo htmlspecialchars($series_details['title']); ?></h2>

                <div class="series-meta">
                    <div class="meta-card">
                        <span class="meta-label">Author</span>
                        <span class="meta-value"><?php echo htmlspecialchars($series_details['author'] ?? 'Unknown'); ?></span>
                    </div>
                    <div class="meta-card">
                          <span class="meta-label">Status</span>
                          <span class="meta-value"><?php echo htmlspecialchars($series_details['status']); ?></span>
                      </div>
                    <div class="meta-card">
                          <span class="meta-label">Rating</span>
                          <span class="meta-value"><?php echo number_format($average_rating, 1); ?>/5</span>
                      </div>
                    <div class="meta-card full-span">
                          <span class="meta-label">Genre</span>
                        <div class="genre-row">
                            <?php if (!empty($genres)): ?>
                                <?php foreach ($genres as $genre_link): ?>
                                    <a href="<?php echo htmlspecialchars($genre_link['url']); ?>" class="genre-tag">
                                        <?php echo htmlspecialchars(ucwords($genre_link['name'])); ?>
                                    </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="meta-value">N/A</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="meta-card full-span description-card">
                        <span class="meta-label">Description</span>
                        <div id="seriesDescription" class="description-content"><?php echo nl2br(htmlspecialchars($series_details['description'] ?? 'No description available.')); ?></div>
                        <button id="descriptionToggle" type="button" class="description-toggle">Read More</button>
                    </div>
                </div>

                <div class="rating-panel" id="series-rating">
                    <div class="rating-panel-head">
                        <div class="rating-title">
                            <span class="rating-label">Community Rating</span>
                            <span class="rating-value"><?php echo number_format($average_rating, 1); ?>/5 <small><?php echo $rating_count; ?> vote<?php echo $rating_count === 1 ? '' : 's'; ?></small></span>
                        </div>
                        <form method="post" action="series.php?id=<?php echo $series_id; ?>#series-rating">
                            <input type="hidden" name="rating_action" value="rate_series">
                            <div class="rating-stars">
                                <?php for ($star = 1; $star <= 5; $star++): ?>
                                    <button type="submit" name="rating" value="<?php echo $star; ?>" class="rating-star-btn <?php echo $user_rating >= $star ? 'active' : ''; ?>" aria-label="Rate <?php echo $star; ?> star<?php echo $star === 1 ? '' : 's'; ?>">
                                        ★
                                    </button>
                                <?php endfor; ?>
                            </div>
                        </form>
                    </div>
                    <div class="rating-note">Your rating: <?php echo $user_rating > 0 ? $user_rating . '/5' : 'Not rated yet'; ?></div>
                </div>

                <div class="series-actions">
                    <button
                        id="favorite-btn"
                        data-series-id="<?php echo $series_details['id']; ?>"
                        data-is-favorite="<?php echo $is_favorite ? 'true' : 'false'; ?>"
                        class="favorite-btn <?php echo $is_favorite ? 'favorited' : 'not-favorited'; ?>"
                    >
                        <span class="text"><?php echo $is_favorite ? 'Remove from Favorites' : 'Add to Favorites'; ?></span>
                    </button>
                    <?php if (!empty($chapters)): ?>
                        <?php
                            $first_chapter = end($chapters);
                            $chapter_num = format_chapter_number($first_chapter['chapter_number']);
                            $button_text = $chapter_num === 'Prologue' ? 'Read Prologue' : 'Read Chapter ' . $chapter_num;
                        ?>
                        <a href="read.php?series_id=<?php echo htmlspecialchars($series_details['id']); ?>&chapter_id=<?php echo htmlspecialchars($chapters[0]['id']); ?>"
                           class="btn-read-latest">Read Latest Chapter</a>
                        <a href="read.php?series_id=<?php echo htmlspecialchars($series_details['id']); ?>&chapter_id=<?php echo htmlspecialchars($first_chapter['id']); ?>"
                           class="btn-read"><?php echo htmlspecialchars($button_text); ?></a>
                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                            <a href="admin/upload_chapter_crawler.php?series_id=<?php echo urlencode((string) $series_details['id']); ?>" class="add-chapter">Upload Chapter</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="chapter-section">
        <?php if (empty($chapters)): ?>
            <div class="chapter-section-header">
                <h3>Chapters</h3>
            </div>
            <div class="chapter-empty">No chapters available yet.</div>
        <?php else: ?>
            <div class="chapter-section-header">
                <h3>Chapters</h3>
                <div class="chapter-tools">
                    <input id="chapterSearch" class="chapter-search" type="text" placeholder="Search chapter">
                    <select id="chapterSort">
                        <option value="desc">Latest First</option>
                        <option value="asc">Oldest First</option>
                    </select>
                </div>
            </div>
            <div class="chapter-container">
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
<form action="admin/delete_chapters.php" method="post" id="deleteChaptersForm">
<?php endif; ?>

<ul class="chapter-list">
    <?php foreach ($chapters as $index => $chapter): 
        $chapter_num = format_chapter_number($chapter['chapter_number']);
        $display_text = $chapter_num === 'Prologue' ? 'Prologue' : 'Chapter ' . $chapter_num;
    ?>
        <li class="chapter-card <?php echo $chapter['is_read'] ? 'chapter-read' : ''; ?> <?php echo $index >= 100 ? 'hidden-chapter' : ''; ?>"
            data-title="<?php echo htmlspecialchars(strtolower($display_text), ENT_QUOTES); ?>"
            data-order="<?php echo (float) $chapter['chapter_number']; ?>"
            data-date="<?php echo htmlspecialchars($chapter['release_date']); ?>">
            
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <input type="checkbox" name="chapters[]" value="<?php echo htmlspecialchars($chapter['id']); ?>">
            <?php endif; ?>

            <a href="read.php?series_id=<?php echo htmlspecialchars($series_details['id']); ?>&chapter_id=<?php echo htmlspecialchars($chapter['id']); ?>">
                <?php echo htmlspecialchars($display_text); ?>
            </a>
            <?php if ($index === 0): ?>
                <span class="latest-chip">Latest</span>
            <?php endif; ?>
            <span class="chapter-date">
                <?php echo date('M d, Y', strtotime($chapter['release_date'])); ?>
            </span>
        </li>
    <?php endforeach; ?>
</ul>

<?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
    <input type="hidden" name="series_id" value="<?php echo htmlspecialchars($series_details['id']); ?>">
    <button type="submit" name="delete" class="delete-btn"
        onclick="return confirm('Are you sure you want to delete the selected chapters?');">
        Delete Selected Chapters
    </button>
</form>
<?php endif; ?>

<?php if (count($chapters) > 100): ?>
    <button id="seeMoreBtn" type="button" class="see-more-btn">See More</button>
<?php endif; ?>

            </div>
        <?php endif; ?>
        <section class="comments-shell" id="series-comments">
            <div class="comments-head">
                <h3>Comments</h3>
                <span class="comment-count">Comments <?php echo count($comments); ?></span>
            </div>

            <?php if ($comment_message !== ''): ?>
                <p class="message <?php echo $comment_message_type === 'error' ? 'error' : 'success'; ?>"><?php echo htmlspecialchars($comment_message); ?></p>
            <?php endif; ?>

            <form class="comment-form" method="post" action="series.php?id=<?php echo $series_id; ?>#series-comments">
                <?php if ($editing_comment_id > 0 && $edit_comment_content !== ''): ?>
                    <input type="hidden" name="comment_action" value="update_comment">
                    <input type="hidden" name="comment_id" value="<?php echo $editing_comment_id; ?>">
                <?php else: ?>
                    <input type="hidden" name="comment_action" value="add_comment">
                <?php endif; ?>
                <textarea name="comment_content" placeholder="Write a comment..."><?php echo htmlspecialchars($edit_comment_content); ?></textarea>
                <div class="comment-form-actions">
                    <button type="submit" class="comment-submit"><?php echo $editing_comment_id > 0 && $edit_comment_content !== '' ? 'Save Comment' : 'Post Comment'; ?></button>
                    <?php if ($editing_comment_id > 0): ?>
                        <a class="comment-cancel" href="series.php?id=<?php echo $series_id; ?>#series-comments">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="comment-list">
                <?php if (empty($comments)): ?>
                    <div class="comment-empty">No comments yet.</div>
                <?php else: ?>
                    <?php foreach ($comments as $comment): ?>
                        <?php
                            $can_edit = (int) $comment['user_id'] === (int) $_SESSION['user_id'];
                            $can_delete = $can_edit || $is_admin;
                            $avatar = resolve_public_asset_url($comment['profile_image'] ?? 'assets/default_profile.jpg');
                        ?>
                        <article class="comment-card">
                            <div class="comment-top">
                                <div class="comment-user">
                                    <img class="comment-avatar" src="<?php echo htmlspecialchars($avatar ?: 'assets/default_profile.jpg'); ?>" alt="<?php echo htmlspecialchars($comment['username']); ?>">
                                    <div class="comment-user-meta">
                                        <span class="comment-username"><?php echo htmlspecialchars($comment['username']); ?></span>
                                        <span class="comment-time">
                                            <?php echo htmlspecialchars(time_elapsed_short($comment['created_at'])); ?>
                                            <?php if (!empty($comment['updated_at']) && $comment['updated_at'] !== $comment['created_at']): ?>
                                                · Edited
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                                <?php if ($can_edit || $can_delete): ?>
                                    <div class="comment-actions">
                                        <?php if ($can_edit): ?>
                                            <a class="comment-action-btn" href="series.php?id=<?php echo $series_id; ?>&edit_comment=<?php echo (int) $comment['id']; ?>#series-comments">Edit</a>
                                        <?php endif; ?>
                                        <?php if ($can_delete): ?>
                                            <form method="post" action="series.php?id=<?php echo $series_id; ?>#series-comments" onsubmit="return confirm('Delete this comment?');">
                                                <input type="hidden" name="comment_action" value="delete_comment">
                                                <input type="hidden" name="comment_id" value="<?php echo (int) $comment['id']; ?>">
                                                <button type="submit" class="comment-action-btn delete">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="comment-body"><?php echo nl2br(htmlspecialchars($comment['content'])); ?></div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
        </section>
        </div>
    <?php else: ?>
        <p>Invalid series ID.</p>
    <?php endif; ?>
</main>

<footer>
    <div class="container">
        <p>&copy; <?php echo date('Y'); ?> KooPal. All rights reserved.</p>
    </div>
</footer>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var mainNav = document.querySelector('.main-nav');
        var mobileMenuToggle = document.querySelector('.mobile-menu-toggle');
        var genreMenu = document.querySelector('.genre-menu');
        var genreTrigger = document.querySelector('.genre-trigger');
        var userBtn = document.querySelector('.user-btn');
        var dropdown = document.querySelector('.logout-dropdown');
        if (userBtn && dropdown) {
            userBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
            });
            document.addEventListener('click', function() {
                dropdown.style.display = 'none';
            });
        }

        if (mobileMenuToggle && mainNav) {
            mobileMenuToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                var isOpen = mainNav.classList.toggle('mobile-open');
                mobileMenuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
        }

        if (genreTrigger && genreMenu) {
            genreTrigger.addEventListener('click', function(e) {
                if (window.innerWidth <= 768) {
                    e.preventDefault();
                    e.stopPropagation();
                    genreMenu.classList.toggle('mobile-open');
                }
            });
        }

        const seeMoreBtn = document.getElementById('seeMoreBtn');
        const chapterSearch = document.getElementById('chapterSearch');
        const chapterSort = document.getElementById('chapterSort');
        const chapterList = document.querySelector('.chapter-list');
        const descriptionToggle = document.getElementById('descriptionToggle');
        const seriesDescription = document.getElementById('seriesDescription');
        if (seeMoreBtn) {
            seeMoreBtn.addEventListener('click', function() {
                document.querySelectorAll('.hidden-chapter').forEach(ch => {
                    ch.classList.remove('hidden-chapter');
                });
                seeMoreBtn.style.display = 'none';
            });
        }

        if (descriptionToggle && seriesDescription) {
            const needsToggle = seriesDescription.scrollHeight > seriesDescription.clientHeight + 8;
            if (!needsToggle) {
                descriptionToggle.style.display = 'none';
            } else {
                descriptionToggle.addEventListener('click', function () {
                    const expanded = seriesDescription.classList.toggle('is-expanded');
                    descriptionToggle.textContent = expanded ? 'Show Less' : 'Read More';
                });
            }
        }

        function refreshChapterList() {
            if (!chapterList) return;
            const cards = Array.from(chapterList.querySelectorAll('.chapter-card'));
            const query = chapterSearch ? chapterSearch.value.trim().toLowerCase() : '';
            const sortValue = chapterSort ? chapterSort.value : 'desc';

            cards.sort((a, b) => {
                const aVal = parseFloat(a.dataset.order || '0');
                const bVal = parseFloat(b.dataset.order || '0');
                return sortValue === 'asc' ? aVal - bVal : bVal - aVal;
            });

            cards.forEach(card => chapterList.appendChild(card));

            let visibleCount = 0;
            cards.forEach(card => {
                const matches = query === '' || (card.dataset.title || '').includes(query);
                if (!matches) {
                    card.style.display = 'none';
                    return;
                }

                if (query === '' && visibleCount >= 100 && seeMoreBtn) {
                    card.style.display = '';
                    card.classList.add('hidden-chapter');
                } else {
                    card.style.display = '';
                    card.classList.remove('hidden-chapter');
                }
                visibleCount++;
            });

            if (seeMoreBtn) {
                seeMoreBtn.style.display = query === '' && visibleCount > 100 ? 'block' : 'none';
            }
        }

        if (chapterSearch) {
            chapterSearch.addEventListener('input', refreshChapterList);
        }

        if (chapterSort) {
            chapterSort.addEventListener('change', refreshChapterList);
        }

        refreshChapterList();

        const favoriteBtn = document.getElementById('favorite-btn');

if (favoriteBtn) {
    // Function to update the button's look and text
    function updateFavoriteButtonUI(isFavorite) {
        const text = favoriteBtn.querySelector('.text');
        
        favoriteBtn.classList.toggle('favorited', isFavorite);
        favoriteBtn.classList.toggle('not-favorited', !isFavorite);

        text.textContent = isFavorite ? 'Remove from Favorites' : 'Add to Favorites';
    }

    favoriteBtn.addEventListener('click', async () => {
        const seriesId = favoriteBtn.dataset.seriesId;
        let isFavorite = favoriteBtn.dataset.isFavorite === 'true';
        
        const action = isFavorite ? 'remove' : 'add'; 

        // 1. Prepare data for the AJAX request
        const formData = new FormData();
        formData.append('series_id', seriesId);
        formData.append('action', action);
        
        // 2. Send the request to your PHP handler
        try {
            // Note: This assumes you have created the 'favorite_handler.php' file
            const response = await fetch('favorite_handler.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                // 3. Toggle the state and update the UI on success
                isFavorite = !isFavorite;
                favoriteBtn.dataset.isFavorite = isFavorite;
                updateFavoriteButtonUI(isFavorite);
            }
            
            // Optional: Show a brief alert/toast notification
            // alert(result.message); 

        } catch (error) {
            console.error('Error during favorite request:', error);
            // alert('An unexpected network error occurred.');
        }
    });
}

        document.addEventListener('click', function(e) {
            if (mainNav && mobileMenuToggle && !mainNav.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                mainNav.classList.remove('mobile-open');
                mobileMenuToggle.setAttribute('aria-expanded', 'false');
            }

            if (genreMenu && !genreMenu.contains(e.target)) {
                genreMenu.classList.remove('mobile-open');
            }
        });

        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && mainNav && mobileMenuToggle) {
                mainNav.classList.remove('mobile-open');
                mobileMenuToggle.setAttribute('aria-expanded', 'false');
            }
            if (window.innerWidth > 768 && genreMenu) {
                genreMenu.classList.remove('mobile-open');
            }
        });
    });
</script>
</body>
</html>
