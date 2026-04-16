<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'includes/db_connect.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user_id = $_SESSION['user_id'];
$favorites = [];
$error_message = '';

$limit = 10;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}
$offset = ($page - 1) * $limit;
$total_series_count = 0;
$total_pages = 1;

try {
    $count_sql = "SELECT COUNT(*) FROM favorites WHERE user_id = :user_id";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $count_stmt->execute();
    $total_series_count = (int) $count_stmt->fetchColumn();
    $total_pages = max(1, (int) ceil($total_series_count / $limit));

    $sql = "
        SELECT
            s.id, s.title, s.author, s.cover_image, s.status,
            COUNT(c.id) AS chapter_count
        FROM series s
        JOIN favorites f ON s.id = f.series_id
        LEFT JOIN chapters c ON s.id = c.series_id
        WHERE f.user_id = :user_id
        GROUP BY s.id, s.title, s.author, s.cover_image, s.status
        ORDER BY s.title ASC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("PDO ERROR: " . $e->getMessage());
}

$pagination_base_url = 'my_favorites.php?';
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$username = $_SESSION['username'] ?? 'User';
$favorites_on_page = count($favorites);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Favorite Series | KooPal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        :root {
            --bg: #0b0f16;
            --panel: rgba(16, 23, 34, 0.88);
            --border: rgba(109, 181, 255, 0.2);
            --text: #f5f8ff;
            --muted: #96a7c2;
            --accent: #1683ff;
            --accent-2: #53b4ff;
            --shadow: 0 24px 48px rgba(0, 0, 0, 0.28);
        }

        body {
            background: #09111b;
            color: var(--text);
        }

        header {
            position: sticky;
            top: 0;
            z-index: 50;
            background: rgba(7, 11, 18, 0.86);
            backdrop-filter: blur(18px);
            border-bottom: 1px solid var(--border);
        }

        header .container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 14px 0;
        }

        .logo-title {
            display: flex;
            align-items: center;
            gap: 14px;
            text-decoration: none;
            color: inherit;
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
            color: var(--text);
            text-decoration: none;
            font-size: 0.92rem;
            font-weight: 800;
            letter-spacing: 0.03em;
            transition: 0.2s ease;
        }

        .nav-links a:hover,
        .nav-links a.active-link {
            background: rgba(255, 255, 255, 0.06);
            color: #fff;
        }

        .user-btn {
            background: linear-gradient(135deg, rgba(22, 131, 255, 0.92) 0%, rgba(51, 154, 255, 0.92) 100%);
            color: #fff;
            border: 1px solid rgba(115, 190, 255, 0.22);
            border-radius: 999px;
            padding: 10px 18px;
            cursor: pointer;
            font-weight: bold;
            box-shadow: 0 16px 32px rgba(10, 92, 182, 0.28);
            transition: transform 0.2s ease, filter 0.2s ease;
        }

        .user-btn:hover,
        .user-btn:focus {
            transform: translateY(-1px);
            filter: brightness(1.05);
        }

        .logout-dropdown a {
            display: block;
            padding: 10px 14px;
            color: #172030;
            font-weight: 700;
            text-decoration: none;
            transition: background 0.2s;
        }

        .logout-dropdown a:hover {
            background: #eef5ff;
        }

        .mobile-menu-toggle {
            display: none;
            width: 44px;
            height: 44px;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            border: 1px solid var(--border);
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            font-size: 1.1rem;
            cursor: pointer;
        }

        main.container {
            padding-top: 34px;
            padding-bottom: 42px;
        }

        .favorites-shell {
            display: grid;
            gap: 26px;
        }

        .favorites-hero {
            position: relative;
            overflow: hidden;
            padding: 28px;
            border-radius: 28px;
            background: linear-gradient(135deg, rgba(18, 30, 48, 0.96) 0%, rgba(13, 19, 29, 0.94) 100%);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }

        .favorites-hero::before {
            content: "";
            position: absolute;
            inset: -40% auto auto -10%;
            width: 280px;
            height: 280px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(61, 166, 255, 0.22) 0%, transparent 68%);
            pointer-events: none;
        }

        .favorites-hero::after {
            content: "";
            position: absolute;
            right: -70px;
            bottom: -100px;
            width: 260px;
            height: 260px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(95, 189, 255, 0.14) 0%, transparent 70%);
            pointer-events: none;
        }

        .hero-content {
            position: relative;
            z-index: 1;
        }

        .hero-copy h2 {
            margin: 0 0 10px;
            font-size: clamp(2rem, 4vw, 3rem);
            line-height: 0.98;
            letter-spacing: -0.045em;
        }

        .hero-copy p {
            margin: 0;
            max-width: 680px;
            color: var(--muted);
            font-size: 1rem;
        }

        .section-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }

        .section-bar h3 {
            margin: 0;
            font-size: 1.35rem;
            letter-spacing: -0.02em;
        }

        .section-note {
            color: var(--muted);
            font-size: 0.95rem;
        }

        .favorites-grid {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 22px;
        }

        .favorite-item {
            position: relative;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 22px;
            overflow: hidden;
            background: linear-gradient(180deg, rgba(17, 22, 32, 0.95) 0%, rgba(13, 18, 28, 0.98) 100%);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.24);
            transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
        }

        .favorite-item:hover {
            transform: translateY(-8px);
            box-shadow: 0 26px 46px rgba(0, 0, 0, 0.34);
            border-color: rgba(109, 181, 255, 0.28);
        }

        .favorite-item a {
            text-decoration: none;
            color: white;
            display: block;
        }

        .cover-wrap {
            position: relative;
            overflow: hidden;
            aspect-ratio: 0.75 / 1;
            background: #0f1520;
        }

        .favorite-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.35s ease;
        }

        .favorite-item:hover img {
            transform: scale(1.04);
        }

        .item-badge {
            position: absolute;
            top: 14px;
            left: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 11px;
            border-radius: 999px;
            background: rgba(7, 12, 18, 0.72);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #eef6ff;
            font-size: 0.76rem;
            font-weight: 800;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .item-content {
            padding: 16px 16px 18px;
            text-align: left;
            background: linear-gradient(180deg, rgba(20, 25, 36, 0.92) 0%, rgba(16, 21, 31, 0.98) 100%);
            color: white;
            min-height: 128px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 12px;
        }

        .item-content h3 {
            margin: 0;
            font-size: 1.18rem;
            font-weight: bold;
            color: white;
            line-height: 1.24;
            min-height: 2.48em;
            overflow: hidden;
        }

        .item-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .item-content p {
            font-size: 0.92rem;
            color: var(--muted);
            margin: 0;
        }

        .chapter-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #eef6ff;
            font-size: 0.82rem;
            font-weight: 700;
        }

        .no-favorites {
            text-align: center;
            padding: 64px 24px;
            color: #d4deee;
            background: linear-gradient(180deg, rgba(18, 25, 36, 0.94) 0%, rgba(13, 18, 28, 0.98) 100%);
            border: 1px solid var(--border);
            border-radius: 28px;
            box-shadow: var(--shadow);
        }

        .no-favorites h3 {
            margin: 0 0 12px;
            font-size: clamp(1.8rem, 4vw, 2.5rem);
            letter-spacing: -0.04em;
        }

        .no-favorites p {
            margin: 0 auto;
            max-width: 620px;
            color: var(--muted);
            font-size: 1rem;
            line-height: 1.7;
        }

        .no-favorites a {
            display: inline-block;
            margin-top: 22px;
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-2) 100%);
            color: white;
            padding: 12px 22px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 800;
            transition: transform 0.2s ease, filter 0.2s ease;
        }

        .no-favorites a:hover {
            transform: translateY(-1px);
            filter: brightness(1.05);
        }

        .pagination-container {
            display: flex;
            justify-content: center;
            margin: 34px 0 0;
        }

        .pagination {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .pagination a {
            min-width: 42px;
            min-height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 14px;
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            text-decoration: none;
            font-weight: bold;
            transition: 0.2s ease;
        }

        .pagination a.active {
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-2) 100%);
            pointer-events: none;
            border-color: transparent;
        }

        .pagination a:hover {
            background: rgba(255, 255, 255, 0.08);
        }

        footer {
            margin-top: auto;
            background: rgba(7, 10, 16, 0.92);
            border-top: 1px solid rgba(255, 255, 255, 0.06);
        }

        footer .container {
            padding-top: 24px;
            padding-bottom: 24px;
            color: var(--muted);
        }

        @media (max-width: 1200px) {
            .favorites-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 900px) {
            header .container {
                flex-wrap: wrap;
            }

            nav,
            .main-nav {
                width: 100%;
            }

            .main-nav {
                flex-wrap: wrap;
            }

            .favorites-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 600px) {
            header .container {
                padding: 12px 0;
            }

            .logo-title {
                gap: 10px;
            }

            .site-title {
                font-size: 1.9rem;
            }

            .site-logo {
                height: 48px;
                width: 48px;
            }

            .mobile-menu-toggle {
                display: inline-flex;
            }

            .main-nav {
                position: relative;
                justify-content: flex-end;
            }

            .nav-links {
                position: absolute;
                top: calc(100% + 12px);
                right: 0;
                width: min(240px, calc(100vw - 24px));
                display: none;
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                padding: 14px;
                border-radius: 20px;
                background: rgba(10, 15, 24, 0.97);
                border: 1px solid var(--border);
                box-shadow: var(--shadow);
            }

            .main-nav.mobile-open .nav-links {
                display: flex;
            }

            .nav-links li,
            .nav-links a {
                width: 100%;
            }

            .nav-links a {
                justify-content: flex-start;
                min-height: 46px;
                padding: 0 14px;
                background: rgba(255, 255, 255, 0.05);
                border: 1px solid rgba(255, 255, 255, 0.06);
            }

            .nav-tools {
                margin-left: 0;
            }

            .user-btn {
                padding: 9px 15px;
                font-size: 0.9rem;
            }

            main.container {
                padding-top: 24px;
            }

            .favorites-hero {
                padding: 22px 18px;
                border-radius: 24px;
            }

            .hero-copy p {
                font-size: 0.95rem;
            }

            .section-bar {
                flex-direction: column;
                align-items: flex-start;
            }

            .favorites-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 16px;
            }

            .item-content {
                min-height: 118px;
                padding: 14px;
            }

            .item-content h3 {
                font-size: 1rem;
            }

            .item-meta {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 420px) {
            .favorites-grid {
                gap: 14px;
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
                    <li><a href="my_favorites.php" class="active-link">MY LIST</a></li>
                    <?php if ($is_admin): ?>
                        <li><a href="admin/index2.php">ADMIN</a></li>
                    <?php endif; ?>
                </ul>
                <ul class="nav-tools">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <li class="user-menu" style="position: relative;">
                            <button class="user-btn"><?php echo htmlspecialchars($username); ?></button>
                            <div class="logout-dropdown" style="display: none; position: absolute; right: 0; top: calc(100% + 8px); background: #fff; border: 1px solid #d7e2f1; border-radius: 14px; min-width: 150px; z-index: 10; overflow: hidden; box-shadow: 0 20px 35px rgba(0, 0, 0, 0.18);">
                                <a href="profile.php">Profile</a>
                                <a href="settings.php">Settings</a>
                                <a href="logout.php">Log out</a>
                            </div>
                        </li>
                    <?php endif; ?>
                    <li>
                        <button type="button" class="mobile-menu-toggle" aria-label="Toggle navigation" aria-expanded="false">
                            <i class="fa-solid fa-bars"></i>
                        </button>
                    </li>
                </ul>
            </div>
        </nav>
    </div>
</header>

<main class="container">
    <div class="favorites-shell">
        <section class="favorites-hero">
            <div class="hero-content">
                <div class="hero-copy">
                    <h2>My Favorite Series</h2>
                </div>
            </div>
        </section>

        <?php if (!empty($error_message)): ?>
            <p class="message error"><?php echo htmlspecialchars($error_message); ?></p>
        <?php elseif (empty($favorites)): ?>
            <div class="no-favorites">
                <h3>Your shelf is still empty</h3>
                <p>You haven't added any favorites yet. Start exploring and tap the heart button on a series to keep your top reads here for quick access.</p>
                <a href="index.php">Browse Series</a>
            </div>
        <?php else: ?>
            <div class="section-bar">
                <h3>Saved Titles</h3>
                <span class="section-note">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
            </div>

            <ul class="favorites-grid">
                <?php foreach ($favorites as $item): ?>
                    <li class="favorite-item">
                        <a href="series.php?id=<?php echo htmlspecialchars($item['id']); ?>">
                            <div class="cover-wrap">
                                <img src="<?php echo htmlspecialchars($item['cover_image'] ?? 'assets/covers/default_cover.jpg'); ?>" alt="<?php echo htmlspecialchars($item['title']); ?> Cover">
                                <span class="item-badge">
                                    <i class="fa-solid fa-bookmark"></i>
                                    Favorite
                                </span>
                            </div>
                            <div class="item-content">
                                <h3><?php echo htmlspecialchars($item['title']); ?></h3>
                                <div class="item-meta">
                                    <p><?php echo htmlspecialchars($item['author'] ?: 'Unknown Author'); ?></p>
                                    <span class="chapter-pill">
                                        <i class="fa-solid fa-book-open"></i>
                                        <?php echo (int) $item['chapter_count']; ?>
                                    </span>
                                </div>
                            </div>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($total_pages > 1): ?>
                <div class="pagination-container">
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="<?php echo $pagination_base_url; ?>page=<?php echo $i; ?>" class="<?php echo ($i == $page) ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<footer>
    <div class="container">
        <p>&copy; <?php echo date('Y'); ?> KooPal. All rights reserved.</p>
    </div>
</footer>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const userBtn = document.querySelector('.user-btn');
    const dropdown = document.querySelector('.logout-dropdown');
    const mainNav = document.querySelector('.main-nav');
    const mobileMenuToggle = document.querySelector('.mobile-menu-toggle');

    if (userBtn && dropdown) {
        userBtn.addEventListener('click', e => {
            e.stopPropagation();
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        });
    }

    if (mobileMenuToggle && mainNav) {
        mobileMenuToggle.addEventListener('click', e => {
            e.stopPropagation();
            const isOpen = mainNav.classList.toggle('mobile-open');
            mobileMenuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    }

    document.addEventListener('click', e => {
        if (userBtn && dropdown && !userBtn.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.style.display = 'none';
        }

        if (mainNav && mobileMenuToggle && !mainNav.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
            mainNav.classList.remove('mobile-open');
            mobileMenuToggle.setAttribute('aria-expanded', 'false');
        }
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 600 && mainNav && mobileMenuToggle) {
            mainNav.classList.remove('mobile-open');
            mobileMenuToggle.setAttribute('aria-expanded', 'false');
        }
    });
});
</script>
</body>
</html>
