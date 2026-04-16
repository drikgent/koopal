<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
    header('Location: ../login.php');
    exit();
}

$message = '';
$message_type = 'success';
$search = trim($_GET['search'] ?? '');

function resolve_admin_asset_url($path) {
    $path = trim((string) $path);

    if ($path === '') {
        return '../assets/covers/default_cover.jpg';
    }

    if (preg_match('~^(https?:)?//~i', $path)) {
        return $path;
    }

    if (strpos($path, '../') === 0) {
        return $path;
    }

    if ($path[0] === '/') {
        return '..' . $path;
    }

    return '../' . ltrim($path, '/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $series_id = (int) ($_POST['series_id'] ?? 0);

    try {
        if ($action === 'update_series' && $series_id > 0) {
            $title = trim($_POST['title'] ?? '');
            $author = trim($_POST['author'] ?? '');
            $genre = trim($_POST['genre'] ?? '');
            $status = trim($_POST['status'] ?? '');

            if ($title === '' || $status === '') {
                $message = 'Title and status are required.';
                $message_type = 'error';
            } else {
                $stmt = $pdo->prepare('UPDATE series SET title = ?, author = ?, genre = ?, status = ? WHERE id = ?');
                $stmt->execute([$title, $author !== '' ? $author : null, $genre !== '' ? $genre : null, $status, $series_id]);
                $message = 'Series updated successfully.';
            }
        } elseif ($action === 'delete_series' && $series_id > 0) {
            $stmt = $pdo->prepare('DELETE FROM series WHERE id = ?');
            $stmt->execute([$series_id]);
            $message = 'Series deleted successfully.';
        }
    } catch (\PDOException $e) {
        error_log('Review library error: ' . $e->getMessage());
        $message = 'Unable to complete that library action right now.';
        $message_type = 'error';
    }
}

$series_list = [];

try {
    if ($search !== '') {
        $stmt = $pdo->prepare("
            SELECT s.id, s.title, s.author, s.genre, s.status, s.cover_image, s.views, COUNT(c.id) AS chapter_total
            FROM series s
            LEFT JOIN chapters c ON c.series_id = s.id
            WHERE s.title LIKE :search OR s.author LIKE :search OR s.genre LIKE :search
            GROUP BY s.id, s.title, s.author, s.genre, s.status, s.cover_image, s.views
            ORDER BY s.title ASC
        ");
        $stmt->execute([':search' => '%' . $search . '%']);
    } else {
        $stmt = $pdo->query("
            SELECT s.id, s.title, s.author, s.genre, s.status, s.cover_image, s.views, COUNT(c.id) AS chapter_total
            FROM series s
            LEFT JOIN chapters c ON c.series_id = s.id
            GROUP BY s.id, s.title, s.author, s.genre, s.status, s.cover_image, s.views
            ORDER BY s.title ASC
        ");
    }

    $series_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    error_log('Fetch review library error: ' . $e->getMessage());
    $message = 'Unable to load the library right now.';
    $message_type = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Library</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body { background: #09111b; color: #edf6ff; font-family: "Trebuchet MS", "Segoe UI", sans-serif; }
        header { background: rgba(4, 9, 16, 0.94); border-bottom: 1px solid rgba(87, 171, 255, 0.2); }
        header .container { display: flex; align-items: center; justify-content: space-between; width: 100%; max-width: 100%; box-sizing: border-box; padding: 18px 20px; margin: 0; }
        .admin-brand { display: flex; align-items: center; gap: 14px; }
        .admin-brand h1 { margin: 0; }
        header nav ul { display: flex; align-items: center; justify-content: flex-end; margin: 0; padding: 0; list-style: none; }
        header nav a { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 0 16px; border-radius: 999px; text-decoration: none; color: #edf6ff; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(124, 195, 255, 0.16); font-weight: 700; }
        .admin-shell { max-width: 1280px; margin: 34px auto 48px; padding: 0 20px; }
        .topbar { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin-bottom: 24px; }
        .topbar h1 { margin: 0; font-size: clamp(2rem, 3vw, 3rem); letter-spacing: -0.05em; }
        .topbar span { display: inline-block; margin-top: 8px; color: rgba(237, 246, 255, 0.72); }
        .top-actions { display: flex; gap: 12px; flex-wrap: wrap; }
        .top-link { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 0 16px; border-radius: 999px; text-decoration: none; color: #edf6ff; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(124, 195, 255, 0.16); font-weight: 700; }
        .panel { background: linear-gradient(180deg, rgba(10, 18, 29, 0.94) 0%, rgba(11, 21, 34, 0.88) 100%); border: 1px solid rgba(102, 192, 255, 0.16); border-radius: 24px; box-shadow: 0 24px 60px rgba(0, 0, 0, 0.3); padding: 24px; }
        .message { margin-bottom: 18px; padding: 12px 14px; border-radius: 14px; font-weight: 700; }
        .message.success { background: rgba(53, 171, 110, 0.16); border: 1px solid rgba(53, 171, 110, 0.22); color: #c9ffe0; }
        .message.error { background: rgba(255, 99, 132, 0.12); border: 1px solid rgba(255, 99, 132, 0.2); color: #ffd4de; }
        .search-row { display: flex; gap: 12px; align-items: center; margin-bottom: 20px; }
        .search-row input { flex: 1; min-height: 48px; padding: 0 16px; border-radius: 14px; border: 1px solid rgba(255, 255, 255, 0.12); background: rgba(255, 255, 255, 0.05); color: #fff; }
        .search-row button { min-height: 48px; padding: 0 18px; border: none; border-radius: 14px; background: linear-gradient(135deg, #0c84ff 0%, #20b0ff 100%); color: #fff; font-weight: 700; cursor: pointer; }
        .library-grid { display: grid; gap: 18px; }
        .library-card { display: grid; grid-template-columns: 110px minmax(0, 1fr); gap: 18px; padding: 18px; border-radius: 20px; background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.08); }
        .library-card img { width: 110px; height: 150px; object-fit: cover; border-radius: 18px; background: rgba(255, 255, 255, 0.05); }
        .series-head { display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 14px; flex-wrap: wrap; }
        .series-head strong { font-size: 1.15rem; }
        .series-stats { display: flex; gap: 10px; flex-wrap: wrap; }
        .stat-pill { display: inline-flex; align-items: center; justify-content: center; padding: 5px 10px; border-radius: 999px; background: rgba(88, 166, 255, 0.12); border: 1px solid rgba(88, 166, 255, 0.2); color: #9dd7ff; font-size: 0.75rem; font-weight: 700; }
        .series-form { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)) auto auto auto; gap: 12px; align-items: end; }
        .field { display: grid; gap: 8px; }
        .field label { font-size: 0.82rem; font-weight: 700; color: rgba(237, 246, 255, 0.72); text-transform: uppercase; letter-spacing: 0.06em; }
        .field input, .field select { min-height: 44px; padding: 0 14px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.12); background: rgba(255, 255, 255, 0.05); color: #fff; }
        .field select option { color: #000; }
        .action-btn, .delete-btn, .open-btn { min-height: 44px; padding: 0 16px; border-radius: 12px; font-weight: 800; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
        .action-btn { border: none; background: linear-gradient(135deg, #0c84ff 0%, #20b0ff 100%); color: #fff; }
        .open-btn { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.12); color: #fff; }
        .delete-btn { background: rgba(255, 77, 109, 0.14); color: #ffd4de; border: 1px solid rgba(255, 77, 109, 0.22); }
        .empty-state { padding: 18px; border-radius: 18px; background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.08); color: rgba(237, 246, 255, 0.75); }
        @media (max-width: 1100px) { .series-form { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 760px) { .topbar, .search-row, .library-card { display: grid; grid-template-columns: 1fr; } .series-form { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="admin-brand">
                <img src="../admin/logo.png" alt="Logo" style="height:60px; width:60px; border-radius:50%; object-fit:cover;">
                <h1>Admin</h1>
            </div>
            <nav>
                <ul>
                    <li><a href="index2.php">Admin Home</a></li>
                    <li><a href="../index.php">Back to Home</a></li>
                </ul>
            </nav>
        </div>
    </header>
    <main class="admin-shell">
        <div class="topbar">
            <div>
                <h1>Review Library</h1>
                <span>Update series details, jump into series pages, or delete entries you no longer need.</span>
            </div>
            <div class="top-actions">
                <a class="top-link" href="upload_series.php">Upload Series</a>
                <a class="top-link" href="update_series_status.php">Status Tool</a>
                <a class="top-link" href="index2.php">Back to Panel</a>
            </div>
        </div>
        <section class="panel">
            <?php if ($message !== ''): ?>
                <div class="message <?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <form class="search-row" method="get" action="review_library.php">
                <input type="text" name="search" placeholder="Search by title, author, or genre" value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit">Search</button>
            </form>
            <?php if (empty($series_list)): ?>
                <div class="empty-state">No series matched your search.</div>
            <?php else: ?>
                <div class="library-grid">
                    <?php foreach ($series_list as $series): ?>
                        <div class="library-card">
                            <img src="<?php echo htmlspecialchars(resolve_admin_asset_url($series['cover_image'] ?? '')); ?>" alt="<?php echo htmlspecialchars($series['title']); ?>">
                            <div>
                                <div class="series-head">
                                    <strong><?php echo htmlspecialchars($series['title']); ?></strong>
                                    <div class="series-stats">
                                        <span class="stat-pill"><?php echo (int) $series['chapter_total']; ?> Chapters</span>
                                        <span class="stat-pill"><?php echo number_format((int) $series['views']); ?> Views</span>
                                        <span class="stat-pill"><?php echo htmlspecialchars($series['status'] ?: 'Unknown'); ?></span>
                                    </div>
                                </div>
                                <form class="series-form" method="post" action="review_library.php<?php echo $search !== '' ? '?search=' . urlencode($search) : ''; ?>">
                                    <input type="hidden" name="action" value="update_series">
                                    <input type="hidden" name="series_id" value="<?php echo (int) $series['id']; ?>">
                                    <div class="field">
                                        <label for="title-<?php echo (int) $series['id']; ?>">Title</label>
                                        <input id="title-<?php echo (int) $series['id']; ?>" type="text" name="title" value="<?php echo htmlspecialchars($series['title']); ?>" required>
                                    </div>
                                    <div class="field">
                                        <label for="author-<?php echo (int) $series['id']; ?>">Author</label>
                                        <input id="author-<?php echo (int) $series['id']; ?>" type="text" name="author" value="<?php echo htmlspecialchars($series['author'] ?? ''); ?>">
                                    </div>
                                    <div class="field">
                                        <label for="genre-<?php echo (int) $series['id']; ?>">Genre</label>
                                        <input id="genre-<?php echo (int) $series['id']; ?>" type="text" name="genre" value="<?php echo htmlspecialchars($series['genre'] ?? ''); ?>">
                                    </div>
                                    <div class="field">
                                        <label for="status-<?php echo (int) $series['id']; ?>">Status</label>
                                        <select id="status-<?php echo (int) $series['id']; ?>" name="status" required>
                                            <?php $status_options = ['Ongoing', 'Completed', 'Dropped', 'Hiatus']; ?>
                                            <?php foreach ($status_options as $status_option): ?>
                                                <option value="<?php echo htmlspecialchars($status_option); ?>" <?php echo (($series['status'] ?? '') === $status_option) ? 'selected' : ''; ?>><?php echo htmlspecialchars($status_option); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button class="action-btn" type="submit">Save</button>
                                    <a class="open-btn" href="../series.php?id=<?php echo (int) $series['id']; ?>">Open</a>
                                    <a class="open-btn" href="upload_chapter_crawler.php?series_id=<?php echo (int) $series['id']; ?>">Add Chapter</a>
                                </form>
                                <form method="post" action="review_library.php<?php echo $search !== '' ? '?search=' . urlencode($search) : ''; ?>" onsubmit="return confirm('Delete this series from the library?');" style="margin-top:12px;">
                                    <input type="hidden" name="action" value="delete_series">
                                    <input type="hidden" name="series_id" value="<?php echo (int) $series['id']; ?>">
                                    <button class="delete-btn" type="submit">Delete Series</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
