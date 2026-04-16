<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'includes/db_connect.php';

$series = [];
$message = '';
$selected_genre = isset($_GET['name']) ? trim($_GET['name']) : '';

try {
    if (empty($selected_genre)) {
        $message = "No genre selected.";
    } else {
        // Fetch series by genre
        $stmt = $pdo->prepare("
            SELECT s.id, s.title, s.cover_image, COUNT(c.id) AS chapter_count
            FROM series s
            LEFT JOIN chapters c ON s.id = c.series_id
            WHERE s.genre LIKE :genre_name
            GROUP BY s.id
            ORDER BY s.title ASC
        ");
        $stmt->bindValue(':genre_name', "%" . $selected_genre . "%", PDO::PARAM_STR);
        $stmt->execute();
        $series = $stmt->fetchAll();

        if (empty($series)) {
            $message = "No series found for the genre: " . htmlspecialchars($selected_genre);
        }
    }
} catch (\PDOException $e) {
    error_log("Error fetching genre series: " . $e->getMessage());
    $message = "Could not load series for this genre. Please try again later.";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Genre: <?php echo htmlspecialchars(ucwords($selected_genre)); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            background: #09111b;
            color: #f6f8fb;
        }
        .series-grid {
                display: grid;
                /* Desktop default is 5 columns, making for a cleaner layout */
                grid-template-columns: repeat(5, 1fr);
                gap: 20px;
                padding: 20px 0;
            }

            .series-card {
                border: 1px solid #ddd;
                border-radius: 8px;
                overflow: hidden;
                background-color: black;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                transition: transform 0.2s;
            }

            .series-card:hover {
                transform: translateY(-5px);
            }

            .series-card img {
                width: 100%;
                height: 280px;
                object-fit: cover;
            }

            .series-card-content {
                padding: 15px;
                text-align: center;
            }

            .series-card-content h3 {
                margin: 0 0 10px 0;
                font-size: 1.2em;
            }

            .series-card a {
                text-decoration: none;
                color: inherit;
            }

            .series-card p {
                color: #555;
                margin: 0;
                font-size: 0.9em;
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
    </style>
</head>
<body>
    <header>
        <div class="container">
            <a href="index.php" style="display: flex; align-items: center; text-decoration: none; color: inherit;">
                <img src="logo.png" alt="Logo" style="height: 60px; width: 60px; margin-right: 15px; border-radius: 50%; object-fit: cover;">
                <h1>KooPal</h1>
            </a>
            <nav>
                <ul style="display: flex; align-items: center;">
                    <li><a href="index.php">HOME</a></li>
                    <li class="genre-menu" style="position: relative;">
                    <a href="#">GENRES</a>
                    <div class="genre-dropdown" style="display: none; position: absolute; left: 0; top: 100%; background: #fff; border: 1px solid #ddd; border-radius: 6px; min-width: 150px; z-index: 10;">
                        <?php foreach ($genres as $genre): ?>
                            <a href="genre.php?name=<?php echo urlencode($genre); ?>" style="display: block; padding: 10px 16px; color: #333; text-decoration: none;">
                                <?php echo htmlspecialchars(ucwords($genre)); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </li>
                    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                        <li><a href="admin/index2.php">Admin</a></li>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <li class="user-menu" style="margin-left: auto; position: relative;">
                            <button class="user-btn">
                                <?php echo htmlspecialchars($_SESSION['username'] ?? $_SESSION['admin_name'] ?? 'User'); ?>
                            </button>
                            <div class="logout-dropdown" style="display: none; position: absolute; right: 0; top: 100%; background: #fff; border: 1px solid #ddd; border-radius: 6px; min-width: 120px; z-index: 10;">
                                <a href="profile.php" style="display: block; padding: 10px 16px;">Profile</a>
                                <a href="settings.php" style="display: block; padding: 10px 16px; color: #333; text-decoration: none;">Settings</a>
                                <a href="logout.php" style="display: block; padding: 10px 16px; color: #333; text-decoration: none;">Log out</a>
                            </div>
                        </li>
                    <?php else: ?>
                        <li><a href="login.php">Login</a></li>
                        <li><a href="register.php">Register</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>

    <main class="container">
        <h2>Genre: <?php echo htmlspecialchars(ucwords($selected_genre)); ?></h2>
        <?php if (!empty($message)): ?>
            <p class="message error"><?php echo htmlspecialchars($message); ?></p>
        <?php elseif (empty($series)): ?>
            <p>No series found for this genre.</p>
        <?php else: ?>
            <div class="series-grid">
                    <?php foreach ($series as $s): ?>
                        <div class="series-card">
                            <a href="series.php?id=<?php echo htmlspecialchars($s['id']);?>">
                                <img src="<?php echo htmlspecialchars($s['cover_image'] ?? 'assets/covers/default_cover.jpg'); ?>" alt="<?php echo htmlspecialchars($s['title']); ?> Cover">
                                <div class="series-card-content">
                                    <h3><?php echo htmlspecialchars($s['title']); ?></h3>
                                    <p><?php echo (int)$s['chapter_count']; ?> Chapters</p>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
        <?php endif; ?>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y');?> KooPal. All rights reserved</p>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
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
        });
    </script>
</body>
</html>
