<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'includes/db_connect.php';


$series_id = isset($_GET['series_id']) ? intval($_GET['series_id']) : 0;
$chapter_id = isset($_GET['chapter_id']) ? intval($_GET['chapter_id']) : 0;

if ($series_id === 0 || $chapter_id === 0) {
    header("Location: index.php"); // Redirect if invalid IDs
    exit();
}

$series_title = '';
$chapter_details = null;
$pages = [];
$prev_chapter_link = '';
$next_chapter_link = '';
$error_message = '';

function format_chapter_number($number) {
    if ($number === null || $number === '') return '';

    $num = (float)$number;

    if ($num == 0.0) return 'Prologue';

    if (floor($num) == $num) {
        return (string)intval($num);
    }

    return rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
}

function resolve_public_asset_url($path) {
    $path = trim((string) $path);
    if ($path === '') {
        return $path;
    }

    $path = str_replace('\\', '/', $path);
    $uploadsBaseUrl = rtrim((string) getenv('UPLOADS_BASE_URL'), '/');

    if (preg_match('~^(https?:)?//~i', $path)) {
        return $path;
    }

    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = rtrim($scriptDir, '/');
    if ($basePath === '/' || $basePath === '\\') {
        $basePath = '';
    }

    // Convert absolute filesystem paths (Windows/Linux) to public web paths.
    foreach (['/uploads/', '/assets/'] as $publicRoot) {
        $rootPos = stripos($path, $publicRoot);
        if ($rootPos !== false) {
            $publicPath = substr($path, $rootPos);
            if ($uploadsBaseUrl !== '' && strpos($publicPath, '/uploads/') === 0) {
                return $uploadsBaseUrl . $publicPath;
            }
            return $basePath . str_replace(' ', '%20', $publicPath);
        }
    }

    if (strpos($path, '/uploads/') === 0 || strpos($path, '/assets/') === 0) {
        if ($uploadsBaseUrl !== '' && strpos($path, '/uploads/') === 0) {
            return $uploadsBaseUrl . $path;
        }
        return $basePath . str_replace(' ', '%20', $path);
    }

    if (strpos($path, 'uploads/') === 0 || strpos($path, 'assets/') === 0) {
        if ($uploadsBaseUrl !== '' && strpos($path, 'uploads/') === 0) {
            return $uploadsBaseUrl . '/' . str_replace(' ', '%20', ltrim($path, '/'));
        }
        return ($basePath !== '' ? $basePath . '/' : '') . str_replace(' ', '%20', ltrim($path, '/'));
    }

    if (strpos($path, '/') === 0) {
        return $basePath . str_replace(' ', '%20', $path);
    }

    return ($basePath !== '' ? $basePath . '/' : '') . str_replace(' ', '%20', ltrim($path, '/'));
}

function resolve_chapter_image_url($path) {
    $resolved = resolve_public_asset_url($path);
    if (preg_match('~^https?://~i', $resolved)) {
        return 'image_proxy.php?url=' . rawurlencode($resolved);
    }
    return $resolved;
}

function is_valid_local_image_path($relativePath) {
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '') {
        return false;
    }

    if (preg_match('~^(https?:)?//~i', $relativePath)) {
        return true;
    }

    $fullPath = __DIR__ . '/' . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);
    if (!is_file($fullPath)) {
        return false;
    }

    return @getimagesize($fullPath) !== false;
}

function ensure_chapter_comments_table_exists(PDO $pdo) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'pgsql') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS chapter_comments (
                id BIGSERIAL PRIMARY KEY,
                chapter_id BIGINT NOT NULL,
                series_id BIGINT NOT NULL,
                user_id BIGINT NOT NULL,
                content TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_chapter_comments_chapter ON chapter_comments (chapter_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_chapter_comments_series ON chapter_comments (series_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_chapter_comments_user ON chapter_comments (user_id)");
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS chapter_comments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                chapter_id INT NOT NULL,
                series_id INT NOT NULL,
                user_id INT NOT NULL,
                content TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_chapter_comments_chapter (chapter_id),
                INDEX idx_chapter_comments_series (series_id),
                INDEX idx_chapter_comments_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
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

$comment_message = '';
$comment_message_type = 'success';
$comments = [];
$editing_comment_id = isset($_GET['edit_comment']) ? (int) $_GET['edit_comment'] : 0;
$edit_comment_content = '';

try {
    ensure_chapter_comments_table_exists($pdo);
    $isPgsql = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $comment_action = $_POST['comment_action'] ?? '';
        $comment_id = (int) ($_POST['comment_id'] ?? 0);
        $comment_content = trim($_POST['comment_content'] ?? '');
        $redirect_url = 'read.php?series_id=' . $series_id . '&chapter_id=' . $chapter_id;

        if ($comment_action === 'add_comment') {
            if ($comment_content === '') {
                $comment_message = 'Comment cannot be empty.';
                $comment_message_type = 'error';
            } else {
                $stmt = $pdo->prepare('INSERT INTO chapter_comments (chapter_id, series_id, user_id, content) VALUES (?, ?, ?, ?)');
                $stmt->execute([$chapter_id, $series_id, (int) $_SESSION['user_id'], $comment_content]);
                header('Location: ' . $redirect_url . '#comments');
                exit();
            }
        } elseif ($comment_action === 'update_comment' && $comment_id > 0) {
            $stmt = $pdo->prepare('SELECT user_id FROM chapter_comments WHERE id = ? AND chapter_id = ? AND series_id = ?');
            $stmt->execute([$comment_id, $chapter_id, $series_id]);
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
                $stmt = $pdo->prepare('UPDATE chapter_comments SET content = ? WHERE id = ?');
                $stmt->execute([$comment_content, $comment_id]);
                header('Location: ' . $redirect_url . '#comments');
                exit();
            }
        } elseif ($comment_action === 'delete_comment' && $comment_id > 0) {
            $stmt = $pdo->prepare('SELECT user_id FROM chapter_comments WHERE id = ? AND chapter_id = ? AND series_id = ?');
            $stmt->execute([$comment_id, $chapter_id, $series_id]);
            $target_comment = $stmt->fetch();
            $is_admin = (($_SESSION['role'] ?? '') === 'admin');

            if (!$target_comment) {
                $comment_message = 'Comment not found.';
                $comment_message_type = 'error';
            } elseif (!$is_admin && (int) $target_comment['user_id'] !== (int) $_SESSION['user_id']) {
                $comment_message = 'You can only delete your own comment.';
                $comment_message_type = 'error';
            } else {
                $stmt = $pdo->prepare('DELETE FROM chapter_comments WHERE id = ?');
                $stmt->execute([$comment_id]);
                header('Location: ' . $redirect_url . '#comments');
                exit();
            }
        }
    }

    // Fetch series title
    $stmt = $pdo->prepare("SELECT title FROM series WHERE id = ?");
    $stmt->execute([$series_id]);
    $series_data = $stmt->fetch();
    if ($series_data) {
        $series_title = $series_data['title'];
    } else {
        $error_message = "Series not found.";
    }

    // Mark this chapter as read for the user
// ✅ Option B: Save both latest chapter AND history of every chapter read
if (isset($_SESSION['user_id']) && isset($_GET['chapter_id'])) {

    $user_id = $_SESSION['user_id'];
    $chapter_id_to_mark = intval($_GET['chapter_id']);

    // ✅ A) Save latest read chapter (Continue Reading)
        // Save latest read chapter (continue reading) without relying on UPSERT constraints.
    $stmt = $pdo->prepare("
        UPDATE user_read_chapters
        SET chapter_id = ?, read_at = CURRENT_TIMESTAMP
        WHERE user_id = ? AND series_id = ?
    ");
    $stmt->execute([$chapter_id_to_mark, $user_id, $series_id]);

    if ($stmt->rowCount() === 0) {
        $stmt = $pdo->prepare("
            INSERT INTO user_read_chapters (user_id, series_id, chapter_id, read_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([$user_id, $series_id, $chapter_id_to_mark]);
    }

    // Save chapter into read history (only once per user/series/chapter).
    $stmtHist = $pdo->prepare("
        INSERT INTO user_read_chapter_events (user_id, series_id, chapter_id, read_at)
        SELECT ?, ?, ?, CURRENT_TIMESTAMP
        WHERE NOT EXISTS (
            SELECT 1
            FROM user_read_chapter_events
            WHERE user_id = ? AND series_id = ? AND chapter_id = ?
        )
    ");
    $stmtHist->execute([$user_id, $series_id, $chapter_id_to_mark, $user_id, $series_id, $chapter_id_to_mark]);
}



    // Fetch chapter details
    $stmt = $pdo->prepare("SELECT id, chapter_number, title FROM chapters WHERE id = ? AND series_id = ?");
    $stmt->execute([$chapter_id, $series_id]);
    $chapter_details = $stmt->fetch();

    if (!$chapter_details) {
        $error_message = "Chapter not found or does not belong to this series.";
    } else {
        // Fetch pages for this chapter, ordered by page number
        $stmt = $pdo->prepare("SELECT image_url FROM pages WHERE chapter_id = ? ORDER BY page_number ASC");
        $stmt->execute([$chapter_id]);
        $pageRows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $pageRows = array_map(static fn($url) => trim((string) $url), $pageRows);
        $pageRows = array_values(array_filter($pageRows, static fn($url) => $url !== ''));
        $pages = array_map('resolve_chapter_image_url', $pageRows);

        // Determine previous and next chapter
        $stmt = $pdo->prepare(
            "SELECT id FROM chapters
             WHERE series_id = ? AND CAST(chapter_number AS DECIMAL(10,3)) < ?
             ORDER BY CAST(chapter_number AS DECIMAL(10,3)) DESC LIMIT 1"
        );
        $stmt->execute([$series_id, $chapter_details['chapter_number']]);
        $prev_chapter = $stmt->fetch();
        if ($prev_chapter) {
            $prev_chapter_link = "read.php?series_id=$series_id&chapter_id=" . $prev_chapter['id'];
        }

        $stmt = $pdo->prepare(
            "SELECT id FROM chapters
             WHERE series_id = ? AND CAST(chapter_number AS DECIMAL(10,3)) > ?
             ORDER BY CAST(chapter_number AS DECIMAL(10,3)) ASC LIMIT 1"
        );
        $stmt->execute([$series_id, $chapter_details['chapter_number']]);
        $next_chapter = $stmt->fetch();
        if ($next_chapter) {
            $next_chapter_link = "read.php?series_id=$series_id&chapter_id=" . $next_chapter['id'];
        }
    }

    // Fetch all chapters for dropdown
$stmt = $pdo->prepare("SELECT id, chapter_number, title FROM chapters WHERE series_id = ? ORDER BY CAST(chapter_number AS DECIMAL(10,3)) ASC");
$stmt->execute([$series_id]);
$all_chapters = $stmt->fetchAll();

if ($chapter_details) {
    $stmt = $pdo->prepare("
        SELECT cc.id, cc.user_id, cc.content, cc.created_at, cc.updated_at, u.username, u.profile_image
        FROM chapter_comments cc
        INNER JOIN users u ON u.id = cc.user_id
        WHERE cc.chapter_id = ? AND cc.series_id = ?
        ORDER BY cc.created_at DESC
    ");
    $stmt->execute([$chapter_id, $series_id]);
    $comments = $stmt->fetchAll();

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
    error_log("Error fetching chapter or pages: " . $e->getMessage());
    $error_message = "Could not load chapter. Please try again later.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Read <?php echo htmlspecialchars($series_title); ?> - Chapter <?php echo htmlspecialchars($chapter_details['chapter_number'] ?? ''); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            background: #09111b;
            color: #f6f8fb;
        }
        .reader-container {
            max-width: 900px;
            margin: 20px auto;
            background-color: #181818;
            padding: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.24);
            border-radius: 14px;
        }
        .reader-container img {
            max-width: 100%;
            height: auto;
            display: block; 
            margin: 0 auto 5px auto;
        }
        .chapter-nav {
    position: relative;
    display: flex;
    justify-content: space-between; /* Change to space-between */
    align-items: center;
    margin-top: 15px;
    padding: 10px 0;
}

.chapter-nav a,
.chapter-nav .disabled-btn { /* Changed class name to disabled-btn */
    padding: 6px 12px;
    font-size: 16px;
    border-radius: 5px;
    white-space: nowrap;
}

.chapter-nav a:hover {
    background-color: #0056b3;
}

.chapter-nav .prev-btn {
    color: white;
    padding: 5px;
    width: 90px;
    background-color: #007bff;
    border-radius: 10px;
    text-align: center;
    text-decoration: none;
    font-size: 20px;
    left: 0;  /* stick left */
}

.chapter-nav .next-btn {
    color: white;
    padding: 5px;
    width: 90px;
    background-color: #007bff;
    border-radius: 10px;
    text-align: center;
    text-decoration: none;
    font-size: 20px;
    right: 0; /* stick right */
}

.chapter-nav .disabled-btn {
    background-color: #555 !important;
    cursor: not-allowed !important;
    pointer-events: none;
}

/* New class for centering the dropdown */
.chapter-nav .chapter-select-form {
    position: absolute;
    left: 50%;
    transform: translateX(-50%);
    margin: 0;
    display: flex;
    align-items: center;
}

.chapter-nav.bottom {
    margin-top: 20px;
}
        .reader-header {
            margin: 18px 0 10px;
            color: #fff;
        }
        .reader-header h3 {
            margin: 10px 0 0;
            color: rgba(255,255,255,0.82);
            font-size: 1.2rem;
        }
        .comments-shell {
            max-width: 900px;
            margin: 18px auto 32px;
            padding: 24px;
            border-radius: 18px;
            background: linear-gradient(180deg, rgba(19, 27, 40, 0.96), rgba(12, 18, 29, 0.96));
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: 0 14px 34px rgba(0,0,0,0.22);
        }
        .comments-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
        }
        .comments-head h2 {
            margin: 0;
            font-size: 1.45rem;
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
            justify-content: space-between;
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

        .user-btn {
            background: #007bff;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 8px 18px;
            cursor: pointer;
            font-weight: bold;
        }

        .user-btn:focus {
            outline: none;
        }

        .logout-dropdown a {
                color: black; /* Change to your preferred color */
                font-weight: bold;
                background: transparent;
                transition: background 0.2s;
            }
        .logout-dropdown a:hover {
                background: #f4f4f4;
           }
        header .container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 14px 0;
            width: 100%;
            max-width: 100%;
        }
        .logo-title {
            display: flex;
            align-items: center;
            gap: 14px;
            text-decoration: none;
            color: inherit;
            min-width: 0;
        }
        .site-logo {
            height: 60px;
            width: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.25);
        }
        .site-title {
            font-size: clamp(2rem, 3.3vw, 3rem);
            margin: 0;
            line-height: 1;
            letter-spacing: -0.04em;
        }
        nav {
            flex: 1;
            width: 100%;
        }
        .main-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
        }
        .nav-links,
        .nav-tools {
            display: flex;
            align-items: center;
            gap: 15px;
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .nav-tools {
            margin-left: auto;
        }
        .nav-links a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 14px;
            border-radius: 999px;
            color: #fff;
            text-decoration: none;
            font-size: 0.92rem;
            font-weight: 800;
            letter-spacing: 0.03em;
            transition: 0.2s ease;
        }
        .nav-links a:hover {
            background: rgba(255, 255, 255, 0.06);
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

        .reader-header a{
            font-size: 40px;
            text-decoration: none;
            color: inherit;
            font-weight: bold;
            line-height: 1.05;
        } 

        .chapter-nav select {
    font-size: 1em;
    border: 1px solid #ddd;
    cursor: pointer;
    max-width: 100%;
}

.disabled-btn {
    background-color: #555 !important; /* A darker, dimmer color */
    cursor: not-allowed !important; /* Change the cursor to indicate it's not clickable */
    pointer-events: none; /* Disable all mouse events on the element */
}


    @media (max-width: 600px) {
    .chapter-nav a,
    .chapter-nav .disabled {
        font-size: 14px;
        padding: 5px 8px;
    }
    .chapter-nav select {
        font-size: 14px;
        max-width: 120px;
    }
}

select {
    padding: 10px;
    border-radius: 20px;
    width: 300px;
    background-color: black;
    color: #fff;
}
        .chapter-info {
            color: rgba(255,255,255,0.8);
            font-weight: 700;
            text-align: center;
        }
        .scroll-top-btn {
            position: fixed;
            right: 18px;
            bottom: 22px;
            width: 52px;
            height: 52px;
            border: none;
            border-radius: 50%;
            background: linear-gradient(135deg, #0f7cff 0%, #1aa4ff 100%);
            color: #fff;
            font-size: 1.5rem;
            font-weight: 700;
            box-shadow: 0 12px 28px rgba(17, 143, 255, 0.28);
            cursor: pointer;
            opacity: 0;
            visibility: hidden;
            transform: translateY(10px);
            transition: opacity 0.2s ease, transform 0.2s ease, visibility 0.2s ease;
            z-index: 1000;
        }
        .scroll-top-btn.visible {
            opacity: 0.5;
            visibility: visible;
            transform: translateY(0);
        }
        .scroll-top-btn:hover {
            filter: brightness(1.06);
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
            flex: 1 1 auto;
            min-width: 0;
            gap: 10px;
        }

        .site-logo {
            width: 44px;
            height: 44px;
        }

        .site-title {
            font-size: 1.45rem;
            line-height: 1;
            white-space: nowrap;
        }

        nav {
            width: auto;
            flex: 0 0 auto;
        }

        .main-nav {
            position: relative;
            width: auto;
            justify-content: flex-end;
            gap: 8px;
        }

        .nav-links {
            position: absolute;
            top: calc(100% + 12px);
            right: 0;
            display: none;
            flex-direction: column;
            align-items: stretch;
            gap: 10px;
            width: min(240px, calc(100vw - 28px));
            padding: 14px;
            margin: 0;
            background: rgba(10, 15, 24, 0.97);
            border: 1px solid rgba(109, 181, 255, 0.2);
            border-radius: 20px;
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.28);
        }

        .main-nav.mobile-open .nav-links {
            display: flex;
        }

        .nav-links li,
        .nav-links li a {
            width: 100%;
        }

        .nav-links li a {
            justify-content: flex-start;
            min-height: 46px;
            padding: 0 14px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 14px;
        }

        .nav-tools {
            width: auto;
            justify-content: flex-end;
            flex-wrap: nowrap;
            gap: 8px;
        }

        .user-btn {
            min-height: 42px;
            max-width: 132px;
            padding: 8px 14px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            margin-left: 0 !important;
        }

        .mobile-menu-toggle {
            display: inline-flex;
            width: 40px;
            min-width: 40px;
            padding: 0;
        }

        .reader-header {
            text-align: center;
            margin: 18px 0 14px;
        }

        .reader-header a {
            font-size: 2rem;
        }

        .reader-header h3 {
            font-size: 1rem;
        }

        .chapter-nav {
            position: static;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            align-items: stretch;
            margin-top: 12px;
            padding: 0;
        }

        .chapter-nav .chapter-select-form {
            position: static;
            left: auto;
            transform: none;
            grid-column: 1 / -1;
            width: 100%;
            order: -1;
            justify-content: center;
        }

        .chapter-nav .chapter-select-form form {
            width: 100%;
        }

        .chapter-nav select,
        select {
            width: 100%;
            max-width: 100%;
            border-radius: 14px;
            padding: 12px 14px;
        }

        .chapter-nav .prev-btn,
        .chapter-nav .next-btn,
        .chapter-nav .disabled-btn {
            width: 100%;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-sizing: border-box;
            font-size: 1rem;
        }

        .chapter-nav.bottom {
            grid-template-columns: 1fr 1fr;
        }

        .chapter-nav.bottom .chapter-info {
            grid-column: 1 / -1;
            order: -1;
            padding: 6px 0 2px;
        }

        .reader-container {
            margin: 14px auto 18px;
            padding: 6px;
            border-radius: 12px;
        }
        .comments-shell {
            margin: 14px auto 26px;
            padding: 18px;
            border-radius: 14px;
        }
        .comments-head {
            align-items: flex-start;
            flex-direction: column;
        }
        .comment-top {
            flex-direction: column;
            align-items: flex-start;
        }
        .comment-actions,
        .comment-form-actions {
            width: 100%;
        }
        .comment-submit,
        .comment-cancel,
        .comment-action-btn {
            width: 100%;
        }

        .reader-container img {
            border-radius: 8px;
            margin-bottom: 8px;
        }

        .scroll-top-btn {
            right: 14px;
            bottom: 16px;
            width: 48px;
            height: 48px;
            font-size: 1.3rem;
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
                    </ul>
                    <ul class="nav-tools">
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <li class="user-menu" style="position: relative;">
                                <button class="user-btn">
                                    <?php echo htmlspecialchars($_SESSION['username'] ?? $_SESSION['admin_name'] ?? 'User'); ?>
                                </button>
                                <div class="logout-dropdown" style="display: none; position: absolute; right: 0; top: 100%; background: #fff; border: 1px solid #ddd; border-radius: 6px; min-width: 120px; z-index: 10;">
                                    <a href="profile.php" style="display: block; padding: 10px 16px; color: #333; text-decoration: none;">Profile</a>
                                    <a href="settings.php" style="display: block; padding: 10px 16px; color: #333; text-decoration: none;">Settings</a>
                                    <a href="logout.php" style="display: block; padding: 10px 16px; color: #333; text-decoration: none;">Log out</a>
                                    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                                        <a href="admin/index2.php" style="display: block; padding: 10px 16px; color: #333; text-decoration: none;">Admin</a>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php else: ?>
                            <li><a href="login.php">Login</a></li>
                            <li><a href="register.php">Register</a></li>
                        <?php endif; ?>
                        <li>
                            <button type="button" class="mobile-menu-toggle" aria-label="Toggle navigation" aria-expanded="false">&#9776;</button>
                        </li>
                    </ul>
                </div>
            </nav>
        </div>
    </header>

    <main class="container">
        <?php if (!empty($error_message)): ?>
            <p class="message error"><?php echo htmlspecialchars($error_message); ?></p>
        <?php elseif ($chapter_details): ?>
            <div class="reader-header">
                <a href="series.php?id=<?php echo $series_id; ?>" style="text-decoration: none; color: inherit;">
             <?php echo htmlspecialchars($series_title); ?>
             </a>
                <?php 
    $chapter_num = format_chapter_number($chapter_details['chapter_number']);
?>
<h3><?php echo $chapter_num === 'Prologue' ? 'Prologue' : 'Chapter ' . htmlspecialchars($chapter_num); ?></h3>

            </div>

            <div class="chapter-nav">
    <?php if ($prev_chapter_link): ?>
        <a href="<?php echo htmlspecialchars($prev_chapter_link); ?>" class="prev-btn">Previous</a>
    <?php else: ?>
        <span class="disabled-btn prev-btn">Previous</span>
    <?php endif; ?>

    <div class="chapter-select-form">
        <form method="get" action="read.php">
            <input type="hidden" name="series_id" value="<?php echo $series_id; ?>">
            <select name="chapter_id" onchange="this.form.submit()">
                <?php foreach ($all_chapters as $ch): ?>
                    <option value="<?php echo $ch['id']; ?>" <?php if ($ch['id'] == $chapter_id) echo 'selected'; ?>>
                        Chapter <?php echo rtrim(rtrim($ch['chapter_number'], '0'), '.'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if ($next_chapter_link): ?>
        <a href="<?php echo htmlspecialchars($next_chapter_link); ?>" class="next-btn">Next</a>
    <?php else: ?>
        <span class="disabled-btn next-btn">Next</span>
    <?php endif; ?>
</div>


            <div class="reader-container">
                <?php if (empty($pages)): ?>
                    <p style="text-align: center;">No pages found for this chapter. Please check back later.</p>
                <?php else: ?>
                    <?php foreach ($pages as $page_url): ?>
                        <img src="<?php echo htmlspecialchars($page_url); ?>" alt="Page">
                    <?php endforeach; ?>
                    
                <?php endif; ?>
            </div>
            <div class="chapter-nav bottom">
    <?php if ($prev_chapter_link): ?>
        <a href="<?php echo htmlspecialchars($prev_chapter_link); ?>" class="prev-btn">Previous</a>
    <?php else: ?>
        <span class="disabled-btn prev-btn">Previous</span>
    <?php endif; ?>

    <?php 
    $chapter_num = format_chapter_number($chapter_details['chapter_number']);
?>
<span class="chapter-info">
    <?php echo $chapter_num === 'Prologue' ? 'Prologue' : 'Chapter ' . htmlspecialchars($chapter_num); ?>
</span>


    <?php if ($next_chapter_link): ?>
        <a href="<?php echo htmlspecialchars($next_chapter_link); ?>" class="next-btn">Next</a>
    <?php else: ?>
        <span class="disabled-btn next-btn">Next</span>
    <?php endif; ?>
</div>

            <section class="comments-shell" id="comments">
                <div class="comments-head">
                    <h2>Comments</h2>
                    <span class="comment-count">Comments <?php echo count($comments); ?></span>
                </div>

                <?php if ($comment_message !== ''): ?>
                    <p class="message <?php echo $comment_message_type === 'error' ? 'error' : 'success'; ?>"><?php echo htmlspecialchars($comment_message); ?></p>
                <?php endif; ?>

                <form class="comment-form" method="post" action="read.php?series_id=<?php echo $series_id; ?>&chapter_id=<?php echo $chapter_id; ?>#comments">
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
                            <a class="comment-cancel" href="read.php?series_id=<?php echo $series_id; ?>&chapter_id=<?php echo $chapter_id; ?>#comments">Cancel</a>
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
                                $can_delete = $can_edit || (($_SESSION['role'] ?? '') === 'admin');
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
                                                <a class="comment-action-btn" href="read.php?series_id=<?php echo $series_id; ?>&chapter_id=<?php echo $chapter_id; ?>&edit_comment=<?php echo (int) $comment['id']; ?>#comments">Edit</a>
                                            <?php endif; ?>
                                            <?php if ($can_delete): ?>
                                                <form method="post" action="read.php?series_id=<?php echo $series_id; ?>&chapter_id=<?php echo $chapter_id; ?>#comments" onsubmit="return confirm('Delete this comment?');">
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

        <?php endif; ?>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> KooPal. All rights reserved.</p>
        </div>
    </footer>
    <button id="scrollTopBtn" class="scroll-top-btn" type="button" aria-label="Scroll to top">↑</button>
    <script>
document.addEventListener('DOMContentLoaded', function() {
    var mainNav = document.querySelector('.main-nav');
    var mobileMenuToggle = document.querySelector('.mobile-menu-toggle');
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

    const scrollTopBtn = document.getElementById('scrollTopBtn');

    function toggleScrollTopButton() {
        if (!scrollTopBtn) return;
        if (window.innerWidth <= 768 || window.scrollY > 220) {
            scrollTopBtn.classList.add('visible');
        } else {
            scrollTopBtn.classList.remove('visible');
        }
    }

    if (scrollTopBtn) {
        window.addEventListener('scroll', toggleScrollTopButton, { passive: true });
        toggleScrollTopButton();

        scrollTopBtn.addEventListener('click', function (event) {
            event.preventDefault();
            const startY = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
            const duration = 650;
            const startTime = performance.now();

            function easeOutCubic(t) {
                return 1 - Math.pow(1 - t, 3);
            }

            function animateScroll(currentTime) {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);
                const easedProgress = easeOutCubic(progress);
                const nextY = Math.round(startY * (1 - easedProgress));

                window.scrollTo(0, nextY);
                document.documentElement.scrollTop = nextY;
                document.body.scrollTop = nextY;

                if (progress < 1) {
                    window.requestAnimationFrame(animateScroll);
                } else {
                    window.scrollTo(0, 0);
                    document.documentElement.scrollTop = 0;
                    document.body.scrollTop = 0;
                }
            }

            window.requestAnimationFrame(animateScroll);
        });
    }

    document.addEventListener('click', function(e) {
        if (mainNav && mobileMenuToggle && !mainNav.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
            mainNav.classList.remove('mobile-open');
            mobileMenuToggle.setAttribute('aria-expanded', 'false');
        }
    });

    window.addEventListener('resize', function() {
        if (window.innerWidth > 768 && mainNav && mobileMenuToggle) {
            mainNav.classList.remove('mobile-open');
            mobileMenuToggle.setAttribute('aria-expanded', 'false');
        }
    });
});
</script>
</body>
</html>

