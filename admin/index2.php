<?php
session_start();
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
    header('Location: ../login.php');
    exit();
}
require_once '../includes/db_connect.php';

$admin_stats = [
    'series' => 0,
    'chapters' => 0,
    'users' => 0,
];

try {
    $admin_stats['series'] = (int) $pdo->query("SELECT COUNT(*) FROM series")->fetchColumn();
    $admin_stats['chapters'] = (int) $pdo->query("SELECT COUNT(*) FROM chapters")->fetchColumn();
    $admin_stats['users'] = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
} catch (\PDOException $e) {
    error_log('Admin dashboard stats error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --page-bg: #09111b;
            --panel-bg: rgba(9, 16, 27, 0.9);
            --panel-border: rgba(123, 195, 255, 0.14);
            --panel-shadow: 0 24px 70px rgba(0, 0, 0, 0.36);
            --text-main: #f6f8fb;
            --text-muted: rgba(214, 227, 243, 0.7);
            --accent: #1f8fff;
            --accent-strong: #58b8ff;
            --accent-soft: rgba(31, 143, 255, 0.16);
            --card-bg: rgba(255, 255, 255, 0.035);
        }
        body {
            background: var(--page-bg);
            background-image: url('../assets/bg3.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            position: relative;
            color: var(--text-main);
            font-family: "Trebuchet MS", "Segoe UI", sans-serif;
        }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background:
                linear-gradient(180deg, rgba(5, 10, 18, 0.84) 0%, rgba(6, 11, 19, 0.7) 30%, rgba(6, 11, 19, 0.88) 100%),
                radial-gradient(circle at top left, rgba(31, 143, 255, 0.18), transparent 30%),
                radial-gradient(circle at bottom right, rgba(37, 201, 135, 0.12), transparent 26%);
            pointer-events: none;
            z-index: 0;
        }
        header, main, footer {
            position: relative;
            z-index: 1;
        }
        header {
            background: rgba(6, 11, 19, 0.92);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(120, 177, 255, 0.18);
        }
        header .container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            padding: 18px 20px;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .brand h1 {
            margin: 0;
            font-size: clamp(2.4rem, 4vw, 3.4rem);
            letter-spacing: -0.05em;
            line-height: 1;
        }
        .brand img {
            box-shadow: 0 16px 34px rgba(0, 0, 0, 0.26);
            border: 2px solid rgba(255, 255, 255, 0.14);
        }
        header nav ul {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        header nav a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 18px;
            border-radius: 999px;
            border: 1px solid rgba(123, 195, 255, 0.16);
            background: rgba(255, 255, 255, 0.04);
            color: #edf6ff;
            text-decoration: none;
            font-weight: 800;
            letter-spacing: 0.03em;
            transition: transform 0.2s ease, background 0.2s ease, border-color 0.2s ease;
        }
        header nav a:hover {
            transform: translateY(-1px);
            background: rgba(31, 143, 255, 0.1);
            border-color: rgba(123, 195, 255, 0.26);
        }
        .dashboard-shell {
            max-width: 1220px;
            margin: 38px auto 32px;
            padding: 0 20px;
        }
        .dashboard-frame {
            background: var(--panel-bg);
            border: 1px solid var(--panel-border);
            box-shadow: var(--panel-shadow);
            backdrop-filter: blur(18px);
            border-radius: 32px;
            padding: 30px;
            position: relative;
            overflow: hidden;
        }
        .dashboard-frame::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                linear-gradient(135deg, rgba(31, 143, 255, 0.12), transparent 32%),
                radial-gradient(circle at bottom right, rgba(37, 201, 135, 0.12), transparent 28%);
            pointer-events: none;
        }
        .dashboard-frame::after {
            content: "";
            position: absolute;
            right: -56px;
            top: -56px;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(31, 143, 255, 0.16) 0%, transparent 70%);
        }
        .dashboard-head,
        .dashboard-stats,
        .action-grid {
            position: relative;
            z-index: 1;
        }
        .dashboard-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 22px;
        }
        .hero-title {
            margin: 0;
            font-size: clamp(2.4rem, 4vw, 4.1rem);
            line-height: 0.94;
            color: #ffffff;
            letter-spacing: -0.06em;
        }
        .panel-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 16px;
            border-radius: 999px;
            background: rgba(31, 143, 255, 0.12);
            border: 1px solid rgba(31, 143, 255, 0.18);
            color: #9dd7ff;
            font-size: 0.82rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }
        .stat-card {
            padding: 18px 20px;
            border-radius: 22px;
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
        }
        .stat-label {
            display: block;
            margin-bottom: 10px;
            color: var(--text-muted);
            font-size: 0.8rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .stat-value {
            display: block;
            font-size: clamp(1.8rem, 2.5vw, 2.5rem);
            font-weight: 900;
            letter-spacing: -0.05em;
        }
        .action-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }
        .action-card {
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            gap: 16px;
            min-height: 116px;
            padding: 22px 24px;
            border-radius: 24px;
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 18px 40px rgba(0, 0, 0, 0.18);
            text-decoration: none;
            color: inherit;
            transition: transform 0.22s ease, border-color 0.22s ease, box-shadow 0.22s ease, background 0.22s ease;
            position: relative;
            overflow: hidden;
        }
        .action-card::after {
            content: "";
            position: absolute;
            inset: auto 0 0 0;
            height: 3px;
            background: linear-gradient(90deg, rgba(31, 143, 255, 0), rgba(31, 143, 255, 0.95), rgba(31, 143, 255, 0));
            opacity: 0.7;
        }
        .action-card:hover {
            transform: translateY(-6px);
            border-color: rgba(31, 143, 255, 0.28);
            box-shadow: 0 28px 54px rgba(0, 0, 0, 0.26);
            background: rgba(255, 255, 255, 0.06);
        }
        .action-icon {
            width: 54px;
            height: 54px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 18px;
            background: rgba(31, 143, 255, 0.12);
            color: #8fd0ff;
            font-size: 1.3rem;
            border: 1px solid rgba(31, 143, 255, 0.14);
        }
        .action-arrow {
            width: 42px;
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            color: #cfe9ff;
            font-size: 0.95rem;
            flex-shrink: 0;
        }
        .action-card h3 {
            margin: 0;
            font-size: 1.28rem;
            color: #ffffff;
            text-align: left;
            letter-spacing: -0.03em;
            line-height: 1.2;
        }
        footer {
            background: transparent;
        }
        footer .container {
            color: rgba(214, 227, 243, 0.72);
        }
        @media (max-width: 960px) {
            .dashboard-stats,
            .action-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media (max-width: 640px) {
            .dashboard-shell {
                margin-top: 28px;
            }
            header .container,
            footer .container {
                width: 100%;
                max-width: 100%;
                padding-left: 14px;
                padding-right: 14px;
                box-sizing: border-box;
            }
            .brand h1 {
                font-size: 2.2rem;
            }
            .dashboard-frame {
                padding: 20px;
                border-radius: 24px;
            }
            .dashboard-head {
                flex-direction: column;
                align-items: flex-start;
            }
            .hero-title {
                font-size: 2.2rem;
            }
            .dashboard-stats,
            .action-grid {
                grid-template-columns: 1fr;
            }
            .action-card {
                grid-template-columns: 52px 1fr 40px;
                min-height: 96px;
                padding: 18px;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="brand">
                <img src="../admin/logo.png" alt="Logo" style="height: 60px; width: 60px; border-radius: 50%; object-fit: cover;">
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

    <main class="dashboard-shell">
        <section class="dashboard-frame">
            <div class="dashboard-head">
                <h2 class="hero-title"><?php echo htmlspecialchars($_SESSION['username'] ?? $_SESSION['admin_name'] ?? 'Admin'); ?></h2>
                <div class="panel-badge">Admin Panel</div>
            </div>

            <div class="dashboard-stats">
                <div class="stat-card">
                    <span class="stat-label">Series</span>
                    <span class="stat-value"><?php echo number_format($admin_stats['series']); ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Chapters</span>
                    <span class="stat-value"><?php echo number_format($admin_stats['chapters']); ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Users</span>
                    <span class="stat-value"><?php echo number_format($admin_stats['users']); ?></span>
                </div>
            </div>

            <div class="action-grid">
                <a class="action-card" href="manage_users.php">
                    <span class="action-icon"><i class="fa-solid fa-users-gear"></i></span>
                    <h3>Manage Users</h3>
                    <span class="action-arrow"><i class="fa-solid fa-arrow-right"></i></span>
                </a>

                <a class="action-card" href="review_library.php">
                    <span class="action-icon"><i class="fa-solid fa-book-open-reader"></i></span>
                    <h3>Review Library</h3>
                    <span class="action-arrow"><i class="fa-solid fa-arrow-right"></i></span>
                </a>

                <a class="action-card" href="upload_series.php">
                    <span class="action-icon"><i class="fa-solid fa-square-plus"></i></span>
                    <h3>Upload New Series</h3>
                    <span class="action-arrow"><i class="fa-solid fa-arrow-right"></i></span>
                </a>

                <a class="action-card" href="upload_chapter_pages.php">
                    <span class="action-icon"><i class="fa-solid fa-images"></i></span>
                    <h3>Upload Chapter Pages</h3>
                    <span class="action-arrow"><i class="fa-solid fa-arrow-right"></i></span>
                </a>

                <a class="action-card" href="upload_chapter_crawler.php">
                    <span class="action-icon"><i class="fa-solid fa-spider"></i></span>
                    <h3>Upload Chapter (Crawler)</h3>
                    <span class="action-arrow"><i class="fa-solid fa-arrow-right"></i></span>
                </a>

                <a class="action-card" href="update_series_status.php">
                    <span class="action-icon"><i class="fa-solid fa-pen-to-square"></i></span>
                    <h3>Update Series Status</h3>
                    <span class="action-arrow"><i class="fa-solid fa-arrow-right"></i></span>
                </a>
            </div>
        </section>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> KooPal Admin.</p>
        </div>
    </footer>
</body>
</html>
