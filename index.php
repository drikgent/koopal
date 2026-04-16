<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'includes/db_connect.php';

function time_elapsed_string($datetime, $full = false) {
    date_default_timezone_set('Asia/Manila');
    $dbTz = defined('DB_TIMEZONE') ? DB_TIMEZONE : 'UTC';
    $outZone = new DateTimeZone($dbTz);

    // Normalize $datetime
    $ago = is_numeric($datetime)
        ? (new DateTime('@'.$datetime))->setTimezone($outZone)
        : new DateTime($datetime, $outZone);

    $now = new DateTime('now', $outZone);

    $future = $ago > $now;
    $diff = $future ? $ago->diff($now) : $now->diff($ago);

    $weeks = intdiv($diff->days, 7);
    $daysLeft = $diff->days - $weeks * 7;

    $units = [
        'y' => $diff->y,
        'm' => $diff->m,
        'w' => $weeks,
        'd' => $daysLeft,
        'h' => $diff->h,
        'i' => $diff->i,
        's' => $diff->s,
    ];

    $labels = ['y'=>'year','m'=>'month','w'=>'week','d'=>'day','h'=>'hour','i'=>'minute','s'=>'second'];
    $parts = [];
    foreach ($units as $k => $v) {
        if ($v) $parts[] = $v . ' ' . $labels[$k] . ($v > 1 ? 's' : '');
    }

    if (!$full) $parts = array_slice($parts, 0, 1);

    if (!$parts) return 'just now';
    return $future ? ('in ' . implode(', ', $parts)) : (implode(', ', $parts) . ' ago');
}

$series = [];
$message = '';
$carousel_series = [];
$genres = []; // Array to store fetched genres
$latest_updates = [];
$top_series = [];
$admin_metrics = [
    'total_users' => 0,
    'total_chapters' => 0,
    'recent_updates' => 0,
];

$history = []; // ✅ Always define it first to avoid "undefined variable"

// Fetch reading history only if the user is logged in
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT 
        s.id AS series_id,
        s.title,
        s.cover_image,
        c.id AS chapter_id,
        c.chapter_number,
        urc.read_at
    FROM user_read_chapters urc
    JOIN chapters c ON urc.chapter_id = c.id
    JOIN series s ON urc.series_id = s.id
    WHERE urc.user_id = ?
    ORDER BY urc.read_at DESC
");
$stmt->execute([$user_id]);
$continue_reading = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get search term and prepare for queryGROUP BY s.idORDER BY MAX(ur.id) DESCLIMIT 15
$searchTerm = $_GET['search'] ?? '';
$searchQuery = '';
$searchParams = [];

if (!empty($searchTerm)) {
    // Modify the WHERE clause to search for the title
    $searchQuery = " WHERE s.title LIKE :searchTerm ";
    $searchParams[':searchTerm'] = '%' . $searchTerm . '%';
}

// Pagination settings
$limit = 10; 
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;
$total_series_count = 0;
$total_pages = 1;

try {
    // 1. Fetch total series count for pagination (must include search filter)
    $count_stmt_sql = "SELECT COUNT(*) FROM series s" . $searchQuery;
    $count_stmt = $pdo->prepare($count_stmt_sql);
    $count_stmt->execute($searchParams);
    $total_series_count = $count_stmt->fetchColumn();
    $total_pages = ceil($total_series_count / $limit);

    // Ensure page number is valid
    if ($page < 1) $page = 1;
    if ($page > $total_pages && $total_pages > 0) $page = $total_pages;
    $offset = ($page - 1) * $limit;

    // 2. Fetch genres for the navigation filter (no change needed)
    $genres_stmt = $pdo->query("SELECT DISTINCT genre FROM series");
    $raw_genres = $genres_stmt->fetchAll(PDO::FETCH_COLUMN);

    // Process genres (assuming comma-separated list in the database)
    $unique_genres = [];
    foreach ($raw_genres as $genre_list) {
        $individual_genres = array_map('trim', explode(',', $genre_list));
        foreach ($individual_genres as $genre) {
            if (!empty($genre)) {
                $unique_genres[] = $genre;
            }
        }
    }
    // Remove duplicates and sort alphabetically
    $genres = array_unique($unique_genres);
    sort($genres);

    // 3. Fetch series for the main grid with pagination (include search filter)
    $stmt_sql = "
    SELECT s.id, s.title, s.cover_image,
            COUNT(CASE WHEN c.chapter_number = TRUNCATE(c.chapter_number, 0) THEN 1 ELSE NULL END) AS chapter_count,
            MAX(c.release_date) as latest_chapter_date
    FROM series s
    LEFT JOIN chapters c ON s.id = c.series_id
    " . $searchQuery . "
    GROUP BY s.id, s.title, s.cover_image
    ORDER BY s.title ASC
    LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($stmt_sql);
    
    // Bind all parameters
    foreach ($searchParams as $param => $value) {
        $stmt->bindValue($param, $value, PDO::PARAM_STR);
    }
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $series = $stmt->fetchAll();

    // 4. Fetch a limited number of series for the carousel (no change needed)
    $stmt_carousel = $pdo->prepare("
        SELECT id, title, description, cover_image
        FROM series
        ORDER BY id DESC
        LIMIT 5
    ");
    $stmt_carousel->execute();
    $carousel_series = $stmt_carousel->fetchAll(PDO::FETCH_ASSOC);

    // Fetch latest chapter updates
    $latest_updates = [];
    if ($page === 1 && empty($searchTerm)) {
        try {
            $stmt_latest = $pdo->query("
                SELECT 
                    s.id AS series_id,
                    s.title,
                    s.cover_image,
                    c.id AS chapter_id,
                    c.chapter_number,
                    c.title AS chapter_title,
                    c.release_date
                FROM chapters c
                JOIN series s ON c.series_id = s.id
                ORDER BY c.release_date DESC
                LIMIT 10
            ");
            $latest_updates = $stmt_latest->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("Error fetching latest updates: " . $e->getMessage());
        }
    }

        // Fetch Top 10 ranked series with ratings and views
    $top_series = [];
    try {
        $stmt = $pdo->query("
    SELECT
        s.id,
        s.title,
        s.cover_image,
        s.views,
        ROUND(COALESCE(AVG(sr.rating), 0), 1) AS avg_rating,
        COUNT(sr.id) AS rating_count
    FROM series s
    LEFT JOIN series_ratings sr ON sr.series_id = s.id
    GROUP BY s.id, s.title, s.cover_image, s.views
    ORDER BY avg_rating DESC, rating_count DESC, s.views DESC, s.title ASC
    LIMIT 10
");
$top_series = $stmt->fetchAll();

    } catch (\PDOException $e) {
        error_log('Error fetching top series: ' . $e->getMessage());
    }


} catch (\PDOException $e) {
    error_log("Error fetching data: " . $e->getMessage());
    $message = "Could not load manhwa series. Please try again later.";
}

// Convert carousel series data to JSON for JavaScript
$carousel_json = json_encode($carousel_series);
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

if ($is_admin) {
    try {
        $admin_metrics['total_users'] = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $admin_metrics['total_chapters'] = (int) $pdo->query("SELECT COUNT(*) FROM chapters")->fetchColumn();
        $admin_metrics['recent_updates'] = count($latest_updates);
    } catch (\PDOException $e) {
        error_log("Error fetching admin metrics: " . $e->getMessage());
    }
}

// Build the base URL for pagination links, preserving the search term
// Corrected Logic
$pagination_base_url = 'index.php?';
if (!empty($searchTerm)) {
    $pagination_base_url .= 'search=' . urlencode($searchTerm) . '&';
}
// After this block, the variable is either 'index.php?' OR 'index.php?search=term&'
// The loop below will correctly append 'page=X'

?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Manhwa</title>
        <link rel="stylesheet" href="assets/css/style.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
            /* ... (Keep your CSS styles here) ... */
body {

 background: #121212;
 }

header .container {
 display: flex;
 align-items: center;
 gap: 18px;
 width: 100%;
 max-width: 100%;
 padding-left: 24px;
 padding-right: 24px;
 box-sizing: border-box;
}

.brand-wrap {
 display: flex;
 align-items: center;
 gap: 15px;
 flex-shrink: 0;
}

nav {
 flex: 1;
 width: 100%;
 display: flex;
 justify-content: flex-end;
}

.main-nav {
 display: flex;
 align-items: center;
 justify-content: flex-end;
 gap: 16px;
 width: auto;
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
 flex: 0 0 auto;
}

.nav-links {
 flex: 0 0 auto;
 justify-content: flex-end;
}

.brand-wrap h1 {
 margin: 0;
 font-size: clamp(2.4rem, 4vw, 3.5rem);
 letter-spacing: -0.05em;
 line-height: 1;
}

.brand-wrap img {
 margin-right: 0 !important;
}

body.admin-view header {
 background: linear-gradient(180deg, #05070b 0%, #0a1018 100%);
 box-shadow: 0 10px 30px rgba(0, 0, 0, 0.28);
}

body.admin-view header .container {
 display: flex;
 align-items: center;
 gap: 18px;
 width: 100%;
 max-width: 100%;
 box-sizing: border-box;
}

body.admin-view .brand-wrap {
 display: flex;
 align-items: center;
 gap: 15px;
 flex-shrink: 0;
}

body.admin-view nav {
 flex: 1;
 width: 100%;
}

body.admin-view .main-nav {
 display: flex !important;
 align-items: center !important;
 justify-content: flex-start;
 gap: 16px;
 width: 100%;
 margin: 0;
 padding: 0;
}

body.admin-view .nav-links,
body.admin-view .nav-tools {
 display: flex;
 align-items: center;
 gap: 14px;
 list-style: none;
 margin: 0;
 padding: 0;
}

body.admin-view .nav-tools {
 margin-left: auto;
 justify-content: flex-end;
 flex: 1;
}

body.admin-view .hero-carousel,
body.admin-view .ranking-sidebar,
body.admin-view .continue-reading,
body.admin-view .series-card,
body.admin-view .update-card {
 border: 1px solid rgba(78, 194, 255, 0.14);
 box-shadow: 0 14px 34px rgba(0, 0, 0, 0.25);
}

body.admin-view .hero-carousel {
 box-shadow:
  inset 0 0 0 1000px rgba(0, 0, 0, 0.42),
  0 18px 42px rgba(0, 0, 0, 0.3);
}

body.admin-view {
 background: #09111b;
 color: #edf6ff;
}

body.admin-view .admin-shell {
 width: calc(100% - 32px);
 max-width: 1320px;
 margin: 0 auto;
 padding-top: 28px;
 display: grid;
 grid-template-columns: minmax(0, 1fr) 340px;
 gap: 16px;
 align-items: start;
  box-sizing: border-box;
}

body.admin-view .admin-shell > * {
 min-width: 0;
}

body.admin-view main.container {
 width: 100%;
 max-width: 100%;
 margin: 0 auto;
 padding-top: 0;
  box-sizing: border-box;
}

body.admin-view .admin-command {
 display: grid;
 grid-template-columns: minmax(0, 1.7fr) minmax(300px, 0.95fr);
 gap: 18px;
 margin: 6px 0 28px;
}

body.admin-view .admin-command-panel,
body.admin-view .admin-command-aside {
 position: relative;
 overflow: hidden;
 border-radius: 22px;
 border: 1px solid rgba(102, 192, 255, 0.16);
 background: linear-gradient(180deg, rgba(10, 18, 29, 0.95) 0%, rgba(11, 21, 34, 0.88) 100%);
 box-shadow: 0 22px 50px rgba(0, 0, 0, 0.32);
}

body.admin-view .admin-command-panel::before,
body.admin-view .admin-command-aside::before {
 content: "";
 position: absolute;
 inset: 0;
 background:
  linear-gradient(135deg, rgba(88, 166, 255, 0.14), transparent 36%),
  radial-gradient(circle at top right, rgba(0, 201, 255, 0.1), transparent 34%);
 pointer-events: none;
}

body.admin-view .admin-command-panel {
 padding: 28px;
}

body.admin-view .admin-command-aside {
 padding: 24px;
}

body.admin-view .admin-command-panel h2,
body.admin-view .admin-command-aside h3 {
 margin: 0;
 letter-spacing: -0.04em;
}

body.admin-view .admin-command-panel h2 {
 font-size: clamp(2rem, 3vw, 2.8rem);
 line-height: 1.05;
}

body.admin-view .admin-metric-grid {
 display: grid;
 grid-template-columns: repeat(3, minmax(0, 1fr));
 gap: 14px;
 margin-top: 22px;
}

body.admin-view .admin-metric-card {
 padding: 16px 18px;
 border-radius: 18px;
 background: rgba(8, 15, 25, 0.76);
 border: 1px solid rgba(129, 203, 255, 0.14);
 backdrop-filter: blur(8px);
}

body.admin-view .admin-metric-label,
body.admin-view .admin-metric-note {
 display: block;
}

body.admin-view .admin-metric-label {
 margin-bottom: 8px;
 color: rgba(237, 246, 255, 0.62);
 font-size: 0.78rem;
 font-weight: 700;
 letter-spacing: 0.08em;
 text-transform: uppercase;
}

body.admin-view .admin-metric-value {
 display: block;
 font-size: clamp(1.7rem, 2vw, 2.25rem);
 font-weight: 800;
 color: #ffffff;
}

body.admin-view .admin-metric-note {
 margin-top: 6px;
 color: rgba(177, 216, 247, 0.74);
 font-size: 0.84rem;
}

body.admin-view .admin-shortcuts {
 display: grid;
 gap: 12px;
 margin-top: 18px;
}

body.admin-view .admin-shortcut {
 display: flex;
 align-items: center;
 justify-content: space-between;
 gap: 12px;
 padding: 15px 16px;
 border-radius: 16px;
 background: rgba(255, 255, 255, 0.03);
 border: 1px solid rgba(130, 196, 255, 0.12);
 text-decoration: none;
 color: #edf6ff;
 transition: transform 0.18s ease, border-color 0.18s ease, background 0.18s ease;
}

body.admin-view .admin-shortcut:hover {
 transform: translateY(-2px);
 border-color: rgba(130, 196, 255, 0.28);
 background: rgba(88, 166, 255, 0.08);
}

body.admin-view .admin-shortcut-label {
 display: flex;
 align-items: center;
 gap: 12px;
 font-weight: 700;
}

body.admin-view .admin-shortcut i {
 width: 36px;
 height: 36px;
 display: inline-flex;
 align-items: center;
 justify-content: center;
 border-radius: 12px;
 background: rgba(88, 166, 255, 0.14);
 color: #84cbff;
}

body.admin-view .admin-shortcut span:last-child {
 color: #90d2ff;
 font-weight: 700;
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

 nav ul {
 display: flex;
 align-items: center;
 }

 .logout-link {
 margin-left: auto;
 }

 .logout-link:hover {
 background-color: red;
 transition: 0.1s;
 border-radius: 5px;
 }

 .series-actions {
 display: flex;
 justify-content: center;
 align-items: center;
 gap: 20px;
 margin: 30px 0;
 }

 .see-more-btn {
 display: none;
 padding: 10px 24px;
 font-size: 1em;
 background: #007bff;
 color: #fff;
 border: none;
 border-radius: 8px;
 cursor: pointer;
 }

 .pagination {
 display: flex;
 gap: 8px;
 }

 .pagination a {
 padding: 8px 14px;
 background: #eee;
 color: #333;
 border-radius: 6px;
 text-decoration: none;
 font-weight: bold;
 }

 .pagination a.active,
 .pagination a:hover {
 background: #007bff;
 color: #fff;
 }

 @media (max-width: 900px) {
 .series-grid {
grid-template-columns: repeat(3, 1fr);
 }
 }

 @media (max-width: 600px) {
 .series-grid {
grid-template-columns: repeat(2, minmax(160px, 1fr)); /* 2 bigger cards */
gap: 15px; /* more space between cards */
 }
 .series-card img {
height: 220px;
 /* taller covers */
 }
 .series-card-content h3 {
font-size: 1em; /* keep title readable */
 }
 .hero-text p {
display: none; /* Hide description on mobile */
 }
 #read-now-link {
display: none;
 }
 /* Hide series 7 through 10 on mobile to limit to 6 per page */
 .series-card:nth-child(n+11) {
display: none;
 }
 }
 @media (min-width: 600px) {
 .see-more-btn {
display: none;
 }
 .pagination {
display: flex;
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
 display: inline-flex !important;
 align-items: center;
 justify-content: center;
 padding: 9px 14px !important;
 border-radius: 10px;
 background: linear-gradient(135deg, rgba(13, 123, 255, 0.22) 0%, rgba(25, 161, 255, 0.16) 100%);
 border: 1px solid rgba(78, 194, 255, 0.22);
 color: #dff3ff !important;
 text-decoration: none !important;
 font-weight: 700;
 }

 .admin-quick-link:hover {
 background: linear-gradient(135deg, rgba(13, 123, 255, 0.3) 0%, rgba(25, 161, 255, 0.24) 100%);
 }

 .logout-dropdown a {
 display: flex;
 align-items: center;
 justify-content: space-between;
 gap: 12px;
 padding: 12px 16px;
 color: #dfefff;
 font-weight: 700;
 text-decoration: none;
 background: transparent;
 border-radius: 12px;
 transition: background 0.18s ease, color 0.18s ease, transform 0.18s ease;
 }
 .logout-dropdown a:hover {
 background: rgba(88, 166, 255, 0.12);
 color: #ffffff;
 transform: translateX(2px);
 }

 .logout-dropdown {
 min-width: 180px !important;
 padding: 10px;
 background: rgba(10, 16, 26, 0.96) !important;
 border: 1px solid rgba(88, 166, 255, 0.16) !important;
 border-radius: 18px !important;
 box-shadow: 0 24px 42px rgba(0, 0, 0, 0.32);
 backdrop-filter: blur(16px);
 overflow: hidden;
 }

 /* --- NEW HERO CAROUSEL STYLES --- */
 .hero-carousel {
 margin-top: 20px;
 position: relative;
 width: 100%;
 height: 450px; /* Adjust as needed */
 overflow: hidden;
 color: white;
 /* Use a CSS variable for the background image */
 background-image: var(--cover-image-url, none);
 background-size: cover;
 background-position: center;
 transition: background-image 0.5s ease-in-out;
 border-radius: 12px;
 margin-bottom: 40px;
 /* Add a semi-transparent overlay to make text more readable */
 box-shadow: inset 0 0 0 1000px rgba(0, 0, 0, 0.4);
 }
 
 /* New pseudo-element for the blurred background image */
 .hero-carousel::before {
 content: '';
 position: absolute;
 top: 0;
 left: 0;
 width: 100%;
 height: 100%;
 background-size: cover;
 background-position: center;
 background-repeat: no-repeat;
 /* Apply the background image and the blur here */
 background-image: var(--cover-image-url);
 filter: blur(10px) brightness(60%);
 -webkit-filter: blur(10px) brightness(60%);
 transform: scale(1.1); /* To hide the edges after blurring */
 z-index: -1;
 transition: background-image 0.5s ease-in-out;
 }

 .hero-content {
 display: flex;
 align-items: center;
 justify-content: space-between;
 height: 100%;
 padding: 0 50px;
 }

 .hero-text {
 flex: 1;
 max-width: 50%;
 }

 .hero-text h2 {
 font-size: 2.5em;
 margin-bottom: 10px;
 }

 .hero-text p {
 font-size: 1em;
 line-height: 1.6;
 color: #ccc;
 max-height: 120px; /* limit description height */
 overflow: hidden;
 text-overflow: ellipsis;
 }
 
 .hero-image {
 flex: 1;
 display: flex;
 justify-content: flex-end;
 padding-right: 50px;
 }

 .hero-image img {
 width: 250px; /* size of the image */
 height: 350px;
 object-fit: cover;
 border-radius: 12px;
 box-shadow: 0 10px 20px rgba(0, 0, 0, 0.5);
 transform: rotate(5deg);
 transition: transform 0.5s ease-in-out;
 }

 .carousel-nav-btn {
 position: absolute;
 top: 50%;
 transform: translateY(-50%);
 background: rgba(0, 0, 0, 0.5);
 color: white;
 border: none;
 font-size: 2em;
 cursor: pointer;
 padding: 10px;
 border-radius: 50%;
 z-index: 10;
 transition: background 0.3s;
 }

 .carousel-nav-btn:hover {
 background: rgba(0, 0, 0, 0.8);
 }

 .carousel-nav-btn.prev {
 left: 10px;
 }

 .carousel-nav-btn.next {
 right: 10px;
 }

@media (max-width: 600px) {

.hero-carousel {

 height: 300px;

 margin-bottom: 20px;

 border-radius: 8px;

}

.hero-content {

 flex-direction: column-reverse;

 justify-content: center;

 align-items: center;

 padding: 20px;

 text-align: center;

}

.hero-text {

 max-width: 100%;

 text-align: center;

}

.hero-text h2 {

 font-size: 1.2em;

}

.hero-text p {

 display: none; /* Hide description on mobile */

}

.hero-image {

 padding-right: 10px;

 margin-bottom: 15px;

}

.hero-image img {

 width: 150px;
 height: 180px;

}

.carousel-nav-btn {

 display: none;

}

#read-now-link {

 display: none;

}

 }

.genre-menu:hover .genre-dropdown {
  display: block !important; /* Override the inline style */
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

/* Optional: Add a subtle hover effect for genre links */
.genre-dropdown a:hover {
  background: #f4f4f4;
}

.search-container {
  position: relative;
  margin-left: 0;
  display: flex;
  align-items: center;
}

body.admin-view .search-container {
  margin-left: 0;
}

.search-btn {
  background: #007bff;
  border: none;
  border-radius: 50%;
  width: 40px;
  height: 40px;
  cursor: pointer;
  color: white;
  font-size: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.3s;
}

.search-btn:hover {
  background: #555;
}

.search-form {
  position: absolute;
  top: 50%;
  right: 45px; /* push it just left of the search icon */
  transform: translateY(-50%) scaleX(0);
  transform-origin: right center; /* collapses leftwards */
  opacity: 0;
  transition: all 0.3s ease;
  pointer-events: none;
}

.search-form input {
  margin-right: 30px;
  padding: 6px 12px;
  border: 1px solid #ccc;
  border-radius: 20px;
  font-size: 0.9em;
  width: 180px;
}

.search-container.active .search-form {
transform: translateY(-50%) scaleX(1);
  opacity: 1;
  pointer-events: auto;
}

.mobile-menu-item {
  display: none;
}

@media (max-width: 600px) {
  .search-container {
  display: none;
  margin-left: 0; /* remove desktop margin */
  }

  .search-form {
  display: none;
  position: absolute;
  top: 100%;  /* appear directly under the button */
  right: 0;
  transform: scaleY(0);
  transform-origin: top center; /* slide down */
  width: 200px;   /* same input size */
  }

  .search-container.active .search-form {
  display: none;
  transform: scaleY(1);
  opacity: 1;
  pointer-events: auto;
  }

  .search-form input {
  display: none;
  width: 100%;  /* keep input full inside dropdown */
  margin-right: 0;
  }
}

/* Add inside your existing <style> tag */

.latest-updates {
    margin: 40px 0;
}

.section-heading {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
}

.section-heading h2,
.section-heading h3 {
    margin: 0;
}

.section-tag {
    display: none;
}

body.admin-view .section-tag {
    display: inline-flex;
    align-items: center;
    padding: 5px 10px;
    border-radius: 999px;
    background: rgba(78, 194, 255, 0.12);
    border: 1px solid rgba(78, 194, 255, 0.22);
    color: #97d8ff;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.updates-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 15px;
    margin-top: 20px;
}

.update-card {
    background: #1a1a1a;
    border-radius: 8px;
    overflow: hidden;
    transition: transform 0.2s;
}

.update-card:hover {
    transform: translateY(-5px);
}

.update-card img {
    width: 100%;
    height: 200px;
    object-fit: cover;
}

.update-info {
    padding: 12px;
}

.update-info h3 {
    font-size: 0.9em;
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.update-info p {
    color: #007bff;
    margin: 5px 0;
    font-size: 0.8em;
}

.update-time {
    color: #666;
    font-size: 0.75em;
}

@media (max-width: 900px) {
 .main-nav {
flex-wrap: wrap;
 }

 .nav-tools {
margin-left: 0;
justify-content: flex-start;
flex: 1 1 100%;
 }

    .updates-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 600px) {
    .updates-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

 .right-rail {
    position: static;
    top: auto;
    right: auto;
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 20px;
  }

/* -- ranking --*/
.ranking-sidebar {
    position: static;
    width: 100%;
    background-color: #1a1a1a;
    border-radius: 10px;
    padding: 18px 18px 16px;
    color: white;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}

.ranking-sidebar .section-heading {
    margin-bottom: 14px;
    padding-bottom: 10px;
    border-bottom: 2px solid #007bff;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}

.ranking-sidebar h3 {
    font-size: 0.95rem;
    margin: 0;
    line-height: 1.2;
}

.ranking-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: grid;
    gap: 12px;
}

/* Each list item stays neatly aligned even with wrapping titles */
.rank-item {
  display: flex;
  flex-direction: column;
  align-items: flex-start; /* top-align when title wraps */
  gap: 4px;
  padding-bottom: 12px;
  border-bottom: 1px solid #333;
}

.rank-item:last-child {
  border-bottom: none;
  padding-bottom: 0;
}

/* Left side: number + title */
.rank-left {
  display: flex;
  flex-direction: row;
  align-items: flex-start;
  flex: 1; /* take all available space */
  min-width: 0; /* important for text wrapping in flexbox */
  gap: 10px;
}

/* Rank number */
.rank-num {
  font-weight: bold;
  color: #007bff;
  min-width: 20px;
  text-align: right;
  margin-right: 0;
  padding-top: 4px;
}

.rank-cover {
  width: 42px;
  height: 56px;
  object-fit: cover;
  border-radius: 10px;
  flex-shrink: 0;
  border: 1px solid rgba(255, 255, 255, 0.08);
}

.rank-meta {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 0;
  flex: 1;
}

/* Title */
.ranking-list a {
    font-weight: bold;
  color: white;
  text-decoration: none;
  transition: color 0.2s;
  word-break: break-word;
  white-space: normal;
  display: block;
  flex: 1;
}

.ranking-list a:hover {
  color: #007bff;
}

/* Right side: view count */
.rank-views {
  font-size: 0.9em;
  color: #aaa;
  padding-left: 72px;
  line-height: 1.3;
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.rank-views span {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.rank-rating {
  color: #ffd166;
  font-weight: 700;
}

.rank-rating-count {
  color: #7f91a5;
}

@media (max-width: 1200px) {
    body.admin-view .admin-shell {
        width: min(1120px, calc(100% - 32px));
        margin: 0 auto;
        grid-template-columns: 1fr;
        gap: 18px;
        padding-top: 20px;
    }

    body.admin-view main.container {
        max-width: 1120px;
        margin: 0 auto;
    }

    .right-rail {
        display: none;
    }
}

.continue-reading {
    position: static;
    width: 100%;
    background-color: #1a1a1a;
    border-radius: 10px;
    padding: 15px;
    color: white;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    display: flex;
    flex-direction: column;
}

.continue-reading .section-heading {
    background-color: #1a1a1a;
    margin: 0;
    padding: 15px;
    border-bottom: 2px solid #333;
    border-radius: 10px 10px 0 0;
}

.continue-reading h3 {
    margin: 0;
}

body.admin-view .ranking-sidebar,
body.admin-view .continue-reading {
    background: linear-gradient(180deg, rgba(20, 20, 24, 0.96) 0%, rgba(15, 17, 24, 0.96) 100%);
    border-radius: 20px;
}

body.admin-view .ranking-sidebar .section-heading,
body.admin-view .continue-reading .section-heading {
    border-bottom-color: #1ea1ff;
    color: #f4fbff;
}

body.admin-view .rank-num,
body.admin-view .continue-info a,
body.admin-view .update-info p,
body.admin-view .pagination a.active,
body.admin-view .pagination a:hover,
body.admin-view .user-btn,
body.admin-view .search-btn {
    background: #118fff;
    color: #fff;
}

body.admin-view .pagination a {
    background: #1d2430;
    color: #dbe8f6;
    border: 1px solid rgba(78, 194, 255, 0.12);
}

body.admin-view .continue-info p,
body.admin-view .update-time,
body.admin-view .rank-views {
    color: #9aa8b8;
}

body.admin-view .continue-item {
    border-bottom-color: rgba(255, 255, 255, 0.08);
}

/* Inside continue list */
.continue-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 15px 0 0;
    overflow-y: auto;
    max-height: 320px;
    min-height: 0;
}

.continue-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    border-bottom: 1px solid #ddd;
    padding: 10px 0;
}

.continue-item img {
    width: 60px;
    height: 80px;
    object-fit: cover;
    border-radius: 5px;
}

.continue-info h4 {
    margin: 0;
    font-size: 16px;
    line-height: 1.35;
}

.continue-info p {
    margin: 4px 0;
    color: #555;
    font-size: 14px;
}

.continue-info a {
    text-decoration: none;
    color: #007bff;
}

.continue-info {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
    min-width: 0;
    flex: 1;
}

.continue-info h4,
.continue-info p {
    max-width: 100%;
    word-break: break-word;
}

body.admin-view .continue-info h4 {
    color: #ffffff;
}

body.admin-view .continue-info a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 7px 14px;
    border-radius: 999px;
    background: linear-gradient(135deg, #0f7cff 0%, #1aa4ff 100%);
    color: #ffffff;
    font-weight: 700;
    text-decoration: none;
    box-shadow: 0 10px 22px rgba(17, 143, 255, 0.22);
}

body.admin-view .continue-info a:hover {
    filter: brightness(1.06);
}

body.admin-view .hero-carousel {
    border-radius: 22px;
    min-height: 470px;
    border: 1px solid rgba(102, 192, 255, 0.16);
}

body.admin-view .hero-content {
    padding: 0 54px;
}

body.admin-view .hero-text h2 {
    font-size: clamp(2.6rem, 4vw, 3.4rem);
    line-height: 1.05;
    letter-spacing: -0.05em;
}

body.admin-view .hero-text p {
    color: rgba(234, 246, 255, 0.84);
    max-width: 60ch;
}

body.admin-view #read-now-link.user-btn {
    border-radius: 14px;
    padding: 12px 18px;
    background: linear-gradient(135deg, #0c84ff 0%, #20b0ff 100%);
    box-shadow: 0 16px 28px rgba(12, 132, 255, 0.28);
}

body.admin-view .section-heading {
    margin-bottom: 20px;
}

body.admin-view .section-heading h2,
body.admin-view .section-heading h3 {
    font-size: clamp(1.45rem, 2vw, 2rem);
    letter-spacing: -0.04em;
}

body.admin-view .updates-grid,
body.admin-view .series-grid {
    gap: 18px;
}

body.admin-view .series-card,
body.admin-view .update-card {
    border-radius: 18px;
    background: linear-gradient(180deg, rgba(13, 20, 31, 0.96) 0%, rgba(10, 15, 23, 0.96) 100%);
    overflow: hidden;
}

body.admin-view .series-card img,
body.admin-view .update-card img {
    height: 300px;
}

body.admin-view .series-card-content,
body.admin-view .update-info {
    padding: 16px;
}

body.admin-view .series-card-content h3,
body.admin-view .update-info h3 {
    line-height: 1.35;
}

body.admin-view .series-card::after,
body.admin-view .update-card::after {
    content: "";
    display: block;
    height: 3px;
    background: linear-gradient(90deg, rgba(88, 166, 255, 0), rgba(88, 166, 255, 0.92), rgba(88, 166, 255, 0));
    opacity: 0.7;
}

/* Update scrollbar to only show on the list */
.continue-list::-webkit-scrollbar {
    width: 8px;
}

.continue-list::-webkit-scrollbar-thumb {
    background-color: #444;
    border-radius: 4px;
}

@media (max-width: 1200px) {
    .continue-reading {
        display: none;
    }

    body.admin-view .admin-command {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 600px) {
  body.admin-view .admin-command-panel,
  body.admin-view .admin-command-aside {
    padding: 18px;
    border-radius: 18px;
  }

  body.admin-view .admin-metric-grid {
    grid-template-columns: 1fr;
  }

  body.admin-view .hero-carousel {
    min-height: 320px;
    border-radius: 18px;
  }

  body.admin-view .hero-content {
    padding: 18px;
  }

  header, footer {
  width: 100vw; /* ensures full viewport width */
  max-width: 100%;
  margin: 0;
  padding: 10px 0;
  box-sizing: border-box;
  }

  header .container, footer .container, main.container {
    padding: 0 8px;
    width: 100%;
    max-width: 100%;
    padding: 0 10px;
    margin: 0 auto;
  box-sizing: border-box;
  
  }
  h1 {
    font-size: 1.2em;
  }

  body, html {
  overflow-x: hidden;
  margin: 0;
  padding: 0;
  }
  
  img, nav, ul, li, div {
  max-width: 100%;
  margin-right: -20px;
  box-sizing: border-box;
}
}

@media (max-width: 768px) {
  body,
  html {
    overflow-x: hidden;
  }

  header {
    position: sticky;
    top: 0;
    z-index: 50;
    background: rgba(4, 7, 14, 0.92);
    backdrop-filter: blur(14px);
    border-bottom: 1px solid rgba(78, 194, 255, 0.35);
    box-shadow: 0 14px 30px rgba(0, 0, 0, 0.24);
  }

  header .container,
  footer .container,
  main.container {
    width: 100%;
    max-width: 100%;
    padding: 0 14px !important;
    margin: 0 auto;
    box-sizing: border-box;
  }

  header .container {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 14px !important;
  }

  img,
  nav,
  ul,
  li,
  div {
    margin-right: 0 !important;
  }

  .brand-wrap {
    width: auto;
    justify-content: flex-start;
    gap: 10px !important;
    margin: 0;
  }

  .brand-wrap img {
    width: 48px !important;
    height: 48px !important;
    margin-right: 0 !important;
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.24);
    border: 2px solid rgba(255, 255, 255, 0.22);
  }

  .brand-wrap h1 {
    margin: 0;
    font-size: clamp(1.5rem, 7vw, 1.9rem);
    letter-spacing: -0.03em;
    line-height: 1;
    color: #fff;
  }

  nav,
  .main-nav {
    width: auto;
  }

  .main-nav {
    display: flex;
    position: relative;
    flex-direction: row;
    align-items: center !important;
    gap: 10px;
  }

  .nav-links {
    position: absolute;
    top: calc(100% + 12px);
    right: 0;
    display: none !important;
    grid-template-columns: 1fr;
    gap: 10px;
    width: min(240px, calc(100vw - 28px));
    margin: 0;
    padding: 14px;
    list-style: none;
    background: rgba(10, 14, 22, 0.96);
    border: 1px solid rgba(78, 194, 255, 0.2);
    border-radius: 20px;
    box-shadow: 0 20px 38px rgba(0, 0, 0, 0.32);
    backdrop-filter: blur(16px);
  }

  .nav-links li {
    width: 100%;
    list-style: none;
  }

  .main-nav.mobile-open .nav-links {
    display: grid !important;
  }

  .nav-links li a,
  .genre-trigger {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    min-height: 46px;
    padding: 0 14px;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.08);
    font-size: 0.92rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    color: #fff;
    text-decoration: none;
  }

  .nav-tools {
    width: auto;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    flex-wrap: nowrap;
    gap: 8px;
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .nav-tools > li,
  .user-menu {
    display: flex;
    justify-content: flex-end;
  }

  .user-btn,
  .admin-quick-link {
    min-height: 42px;
    border-radius: 999px !important;
  }

  .user-btn {
    padding: 0 16px;
    font-size: 0.92rem;
    background: rgba(13, 123, 255, 0.18);
    border: 1px solid rgba(78, 194, 255, 0.24);
    color: #fff;
    box-shadow: none;
  }

  .user-btn.admin-user-btn {
    min-width: 0;
  }

  .admin-quick-link {
    min-width: 148px;
  }

  .search-container,
  .admin-quick-link {
    display: none !important;
  }

  .mobile-menu-item {
    display: flex;
  }

  .mobile-menu-toggle {
    width: 42px;
    height: 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(78, 194, 255, 0.24);
    border-radius: 14px;
    background: rgba(13, 123, 255, 0.18);
    color: #fff;
    font-size: 1.15rem;
    cursor: pointer;
  }

  .genre-menu .genre-dropdown {
    position: static !important;
    display: none !important;
    margin-top: 8px;
    background: rgba(255, 255, 255, 0.96) !important;
    border-radius: 14px !important;
    min-width: 100%;
    transform: none;
    box-shadow: none;
    max-height: 220px;
    overflow-y: auto;
    overscroll-behavior: contain;
  }

  .genre-menu.mobile-open .genre-dropdown {
    display: block !important;
  }

  .genre-menu .genre-dropdown a {
    padding: 12px 14px !important;
    line-height: 1.25;
  }

  .genre-menu .genre-dropdown::-webkit-scrollbar {
    width: 8px;
  }

  .genre-menu .genre-dropdown::-webkit-scrollbar-thumb {
    background: rgba(32, 41, 58, 0.28);
    border-radius: 999px;
  }

  body.admin-view header .container {
    flex-wrap: wrap;
    gap: 10px;
    padding: 12px 14px 14px;
  }

  body.admin-view .brand-wrap {
    width: 100%;
    justify-content: center;
    gap: 10px;
  }

  body.admin-view .brand-wrap h1 {
    font-size: 2rem;
    letter-spacing: 0.01em;
  }

  body.admin-view .brand-wrap img {
    width: 54px;
    height: 54px;
    margin-right: 0 !important;
  }

  body.admin-view nav,
  body.admin-view .main-nav {
    width: 100%;
  }

  body.admin-view .main-nav {
    flex-direction: column;
    align-items: stretch !important;
    gap: 10px;
  }

  body.admin-view .nav-links,
  body.admin-view .nav-tools {
    width: 100%;
    justify-content: center;
    flex-wrap: wrap;
    gap: 8px;
  }

  body.admin-view .nav-tools {
    margin-left: 0;
    flex: 0 0 auto;
  }

  body.admin-view .nav-links li a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 96px;
    min-height: 40px;
    padding: 0 14px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    font-size: 0.95rem;
    letter-spacing: 0.02em;
  }

  .admin-quick-link,
  .user-btn.admin-user-btn {
    min-height: 42px;
  }

  .admin-quick-link {
    min-width: 132px;
    border-radius: 999px !important;
  }

  .user-btn.admin-user-btn {
    min-width: 160px;
    justify-content: center;
    border-radius: 999px;
  }

  .hero-carousel {
    height: auto;
    min-height: 0;
    margin: 16px 0 24px;
    border-radius: 18px;
    overflow: hidden;
  }

  .hero-content {
    flex-direction: column-reverse;
    align-items: flex-start;
    justify-content: flex-start;
    gap: 18px;
    padding: 22px 18px;
    text-align: left;
  }

  .hero-text,
  .hero-image {
    max-width: 100%;
    width: 100%;
    padding-right: 0;
  }

  .hero-text h2 {
    font-size: 2rem;
    line-height: 1.05;
    margin-bottom: 12px;
  }

  .hero-text p {
    display: none;
    font-size: 0.98rem;
    line-height: 1.55;
    max-height: none;
  }

  .hero-image {
    display: flex;
    justify-content: center;
    margin-bottom: 0;
  }

  .hero-image img {
    width: min(62vw, 220px);
    height: auto;
    max-height: 280px;
    transform: rotate(0deg);
  }

  .carousel-nav-btn {
    display: none;
  }

  #read-now-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 132px;
    min-height: 42px;
  }

  .section-heading {
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 14px;
  }

  .section-heading h2,
  .section-heading h3 {
    font-size: 1.8rem;
  }

  .latest-updates {
    margin: 26px 0 22px;
  }

  .updates-grid,
  .series-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
  }

  .updates-grid .series-card:nth-child(n+5) {
    display: none;
  }

  .series-card,
  .update-card {
    border-radius: 14px;
  }

  .series-card img,
  .update-card img {
    height: 190px;
  }

  .series-card-content,
  .update-info {
    padding: 10px;
  }

  .series-card-content h3,
  .update-info h3 {
    font-size: 0.98rem;
    line-height: 1.35;
    white-space: normal;
    overflow: hidden;
    text-overflow: unset;
  }

  .ranking-sidebar,
  .continue-reading {
    position: static;
    top: auto;
    right: auto;
    width: 100%;
    max-height: none;
    margin: 18px 0 0;
    padding: 14px;
    display: block;
  }

  .continue-reading {
    display: none;
  }

  .continue-reading .section-heading {
    position: static;
    padding: 0 0 12px;
    border-radius: 0;
    background: transparent;
  }

  .continue-list {
    max-height: none;
    padding: 10px 0 0;
    overflow: visible;
  }

  .continue-item {
    align-items: flex-start;
    gap: 12px;
  }

  .continue-item img {
    width: 54px;
    height: 72px;
  }

  .continue-info h4 {
    font-size: 1rem;
    line-height: 1.4;
  }

  .continue-info a {
    min-height: 38px;
  }

  .pagination {
    flex-wrap: wrap;
    justify-content: center;
  }
}

</style>
    </head>
    <body class="<?php echo $is_admin ? 'admin-view' : ''; ?>">
        <header>
    <div class="container">
        <a href="index.php" class="brand-wrap" style="display: flex; align-items: center; text-decoration: none; color: inherit;">
            <img src="logo.png" alt="Logo" style="height: 60px; width: 60px; margin-right: 15px; border-radius: 50%; object-fit: cover;">
            <h1>KooPal</h1>
        </a>
        <nav>
            <div class="main-nav">
                <ul class="nav-links">
                    <li><a href="index.php">HOME</a></li>
                    <li><a href="my_favorites.php">FAVORITE</a></li>
                    <li class="genre-menu" style="position: relative;">
                        <button type="button" class="genre-trigger">GENRES <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="genre-dropdown" style="display: none; position: absolute; left: 0; top: 100%; background: #fff; border: 1px solid #ddd; border-radius: 6px; min-width: 150px; z-index: 10;">
                            <?php foreach ($genres as $genre): ?>
                                <a href="genre.php?name=<?php echo urlencode($genre); ?>" style="display: block; padding: 10px 16px; color: #333; text-decoration: none;">
                                    <?php echo htmlspecialchars(ucwords($genre)); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </li>
                </ul>

                <ul class="nav-tools">
                    <li class="search-container">
                        <button id="search-toggle" class="search-btn"><i class="fa-solid fa-magnifying-glass"></i></button>
                        <form method="get" action="index.php" class="search-form">
                            <input type="text" name="search" placeholder="search series..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                        </form>
                    </li>

                    <?php if (isset($_SESSION['user_id'])): ?>
                        <?php if ($is_admin): ?>
                            <li><a href="admin/index2.php" class="admin-quick-link">Admin Panel</a></li>
                        <?php endif; ?>
                        <li class="user-menu" style="position: relative;">
                            <button class="user-btn <?php echo $is_admin ? 'admin-user-btn' : ''; ?>">
                                <?php echo htmlspecialchars($_SESSION['username'] ?? $_SESSION['admin_name'] ?? 'User'); ?>
                                <?php if ($is_admin): ?>
                                    <span class="role-badge">Admin</span>
                                <?php endif; ?>
                            </button>
                            <div class="logout-dropdown" style="display: none; position: absolute; right: 0; top: calc(100% + 10px); z-index: 10;">
                                <a href="profile.php">Profile <i class="fa-solid fa-user"></i></a>
                                <a href="settings.php">Settings <i class="fa-solid fa-gear"></i></a>
                                <a href="logout.php">Log Out <i class="fa-solid fa-right-from-bracket"></i></a>
                                <?php if ($is_admin): ?>
                                    <a href="admin/index2.php">Admin <i class="fa-solid fa-shield-halved"></i></a>
                                <?php endif; ?>
                            </div>
                        </li>
                        <li class="mobile-menu-item">
                            <button type="button" class="mobile-menu-toggle" aria-label="Toggle navigation" aria-expanded="false">
                                <i class="fa-solid fa-bars"></i>
                            </button>
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

        <?php if ($is_admin): ?><div class="admin-shell"><?php endif; ?>
        <main class="container">
            <?php if (!empty($message)): ?>
                <p class="message error"><?php echo htmlspecialchars($message); ?></p>
            <?php elseif (empty($series) && !empty($searchTerm)): ?>
                <p>No series found matching "<?php echo htmlspecialchars($searchTerm); ?>".</p>
            <?php elseif (empty($series) && empty($searchTerm)): ?>
                 <p>No series found.</p>
            <?php else: ?>

            <?php if ($is_admin): ?>
                <section class="admin-command">
                    <div class="admin-command-panel">
                        <h2>Overview</h2>
                        <div class="admin-metric-grid">
                            <div class="admin-metric-card">
                                <span class="admin-metric-label">Library Size</span>
                                <span class="admin-metric-value"><?php echo number_format($total_series_count); ?></span>
                                <span class="admin-metric-note">Published series</span>
                            </div>
                            <div class="admin-metric-card">
                                <span class="admin-metric-label">Chapter Queue</span>
                                <span class="admin-metric-value"><?php echo number_format($admin_metrics['total_chapters']); ?></span>
                                <span class="admin-metric-note">Total uploaded chapters</span>
                            </div>
                            <div class="admin-metric-card">
                                <span class="admin-metric-label">Members</span>
                                <span class="admin-metric-value"><?php echo number_format($admin_metrics['total_users']); ?></span>
                                <span class="admin-metric-note"><?php echo number_format($admin_metrics['recent_updates']); ?> recent updates on deck</span>
                            </div>
                        </div>
                    </div>
                    <aside class="admin-command-aside">
                        <h3>Management Shortcuts</h3>
                        <div class="admin-shortcuts">
                            <a href="admin/index2.php" class="admin-shortcut">
                                <span class="admin-shortcut-label"><i class="fa-solid fa-table-columns"></i> Admin Panel</span>
                                <span>Open</span>
                            </a>
                            <a href="admin/manage_users.php" class="admin-shortcut">
                                <span class="admin-shortcut-label"><i class="fa-solid fa-user-shield"></i> Account Center</span>
                                <span>View</span>
                            </a>
                            <a href="admin/review_library.php" class="admin-shortcut">
                                <span class="admin-shortcut-label"><i class="fa-solid fa-book-open-reader"></i> Review Library</span>
                                <span>Browse</span>
                            </a>
                        </div>
                    </aside>
                </section>
            <?php endif; ?>

            <?php if (!empty($carousel_series) && empty($searchTerm)): ?>
                <div class="hero-carousel" id="hero-carousel">
                    <button class="carousel-nav-btn prev">&#10094;</button>
                    <div class="hero-content">
                        <div class="hero-text">
                            <h2 id="series-title"></h2>
                            <p id="series-description"></p>
                            <a id="read-now-link" href="#" class="user-btn">Read Now</a>
                        </div>
                        <div class="hero-image">
                            <img id="series-cover" src="" alt="Series Cover">
                        </div>
                    </div>
                    <button class="carousel-nav-btn next">&#10095;</button>
                </div>
            <?php endif; ?>

<?php if ($page === 1 && empty($searchTerm) && !empty($latest_updates)): ?>
    <!-- 🆕 Latest Updates -->
<section class="latest-updates">
  <div class="section-heading">
    <h2>Latest Updates</h2>
    <?php if ($is_admin): ?><span class="section-tag">Admin View</span><?php endif; ?>
  </div>
  <div class="updates-grid">
    <?php foreach ($latest_updates as $update):
        $cover = $update['cover_image'] ?? '';
        if (empty($cover)) {
            $coverUrl = 'assets/covers/default_cover.jpg';
        } elseif (preg_match('~^(https?:)?//|^/|^assets/|^uploads/~i', $cover)) {
            // already a full URL or absolute/path inside project
            $coverUrl = $cover;
        } else {
            // plain filename stored in DB -> prefix uploads/
            $coverUrl = 'uploads/' . ltrim($cover, '/');
        }
    ?>
      <div class="series-card">
        <a href="read.php?series_id=<?= htmlspecialchars($update['series_id']); ?>&chapter_id=<?= htmlspecialchars($update['chapter_id']); ?>">
          <img src="<?= htmlspecialchars($coverUrl); ?>" alt="<?= htmlspecialchars($update['title']); ?>">
          <div class="series-card-content">
            <h3><?= htmlspecialchars($update['title']); ?></h3>
            <p>Ch. <?= (int)$update['chapter_number']; ?> <?= !empty($update['chapter_title']) ? ' - ' . htmlspecialchars($update['chapter_title']) : '' ?></p>
            <small style="color:#aaa;"> <?= time_elapsed_string($update['release_date']); ?></small>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

            <div class="section-heading">
                <h2><?php echo !empty($searchTerm) ? 'Search Results for "' . htmlspecialchars($searchTerm) . '"' : 'All Series'; ?></h2>
                <?php if ($is_admin): ?><span class="section-tag">Library</span><?php endif; ?>
            </div>
                <div class="series-grid">
                    <?php foreach ($series as $s): ?>
                        <div class="series-card">
                            <a href="series.php?id=<?php echo htmlspecialchars($s['id']);?>">
                                <img src="<?php echo htmlspecialchars($s['cover_image'] ?? 'assets/covers/default_cover.jpg'); ?>" alt="<?php echo htmlspecialchars($s['title']); ?> Cover">
                                <div class="series-card-content">
    <h3><?php echo htmlspecialchars($s['title']); ?></h3>
    <p><?php echo (int)$s['chapter_count']; ?> Chapters</p>
    <?php if (!empty($s['latest_chapter_date'])): ?>
        <p style="font-size: 0.8em; color: #aaa; margin-top: 5px;">
            Updated: <?= htmlspecialchars(time_elapsed_string($s['latest_chapter_date'])); ?>
        </p>
    <?php endif; ?>
</div>

                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="series-actions">
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="<?php echo $pagination_base_url; ?>page=<?php echo $i; ?>" class="<?php echo ($i == $page) ? 'active' : ''; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            
        </main>
        <?php if ($is_admin): ?>
        <aside class="right-rail">
        <div class="ranking-sidebar">
            <div class="section-heading">
                <h3>Top Rated Series</h3>
                <?php if ($is_admin): ?><span class="section-tag">Ranking</span><?php endif; ?>
            </div>
            <ul class="ranking-list">
                <?php foreach ($top_series as $index => $series): ?>
                    <li class="rank-item">
                        <div class="rank-left">
                           <span class="rank-num"><?= $index + 1 ?>.</span>
                           <img class="rank-cover" src="<?= htmlspecialchars($series['cover_image'] ?? 'assets/covers/default_cover.jpg') ?>" alt="<?= htmlspecialchars($series['title']) ?>">
                           <div class="rank-meta">
                               <a href="series.php?id=<?= htmlspecialchars($series['id'])?>">
                                <?= htmlspecialchars($series['title']) ?> 
                               </a>
                           </div>
                        </div>
                        <div class="rank-views">
                            <?php if ((int) $series['rating_count'] > 0): ?>
                            <span class="rank-rating"><i class="fa-solid fa-star"></i> <?= number_format((float) $series['avg_rating'], 1) ?></span>
                            <span class="rank-rating-count">(<?= number_format((int) $series['rating_count']) ?> ratings)</span>
                            <?php else: ?>
                            <span class="rank-rating-count">No ratings yet</span>
                            <?php endif; ?>
                            <span>views: <?= number_format((int) $series['views'])?></span>
                        </div>
                    </li>
                    <?php endforeach; ?>
            </ul>
        </div>

       <div class="continue-reading">
    <div class="section-heading">
        <h3>Continue Reading</h3>
        <?php if ($is_admin): ?><span class="section-tag">Reading</span><?php endif; ?>
    </div>
    <?php if (!empty($continue_reading)): ?>
    <div class="continue-list">
        <?php foreach ($continue_reading as $cr): ?>
        <div class="continue-item">
            <img src="<?= htmlspecialchars($cr['cover_image']); ?>" alt="">
            <div class="continue-info">
                <h4><?= htmlspecialchars($cr['title']); ?></h4>
                <p>Ch. <?= rtrim(rtrim($cr['chapter_number'], '0'), '.') ?></p>
                <a href="read.php?series_id=<?= $cr['series_id'] ?>&chapter_id=<?= $cr['chapter_id'] ?>">Resume &rarr;</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</aside>
<?php endif; ?>
</div>

        <footer>
            <div class="container">
                <p>&copy; <?php echo date('Y');?> KooPal. All rights reserved</p>
            </div>
        </footer>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const mainNav = document.querySelector('.main-nav');
                const mobileMenuToggle = document.querySelector('.mobile-menu-toggle');
                const genreMenu = document.querySelector('.genre-menu');
                const genreTrigger = document.querySelector('.genre-trigger');
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
                        const isOpen = mainNav.classList.toggle('mobile-open');
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

                // NEW HERO CAROUSEL LOGIC
                const carouselData = <?php echo $carousel_json; ?>;
                const heroCarousel = document.getElementById('hero-carousel');
                const seriesTitle = document.getElementById('series-title');
                const seriesDescription = document.getElementById('series-description');
                const seriesCover = document.getElementById('series-cover');
                const readNowLink = document.getElementById('read-now-link');
                const prevBtn = document.querySelector('.carousel-nav-btn.prev');
                const nextBtn = document.querySelector('.carousel-nav-btn.next');

                let currentIndex = 0;
                let carouselInterval;

                function updateCarousel() {
                    if (carouselData.length === 0) return;

                    const series = carouselData[currentIndex];

                    // Update text content
                    seriesTitle.textContent = series.title;
                    seriesDescription.textContent = series.description;
                    
                    // Update the main cover image
                    const coverImage = series.cover_image || 'assets/covers/default_cover.jpg';
                    seriesCover.src = coverImage;
                    
                    // Update the pseudo-element's background image for the blur effect
                    heroCarousel.style.setProperty('--cover-image-url', `url('${coverImage}')`);

                    // Update the link
                    readNowLink.href = `series.php?id=${series.id}`;
                }

                function nextSlide() {
                    currentIndex = (currentIndex + 1) % carouselData.length;
                    updateCarousel();
                }

                function startAutoPlay() {
                    // Change slide every 5 seconds
                    carouselInterval = setInterval(nextSlide, 5000);
                }

                function stopAutoPlay() {
                    clearInterval(carouselInterval);
                }

                if (prevBtn && nextBtn) {
                    prevBtn.addEventListener('click', () => {
                        stopAutoPlay(); // Stop auto-play when a manual click occurs
                        currentIndex = (currentIndex - 1 + carouselData.length) % carouselData.length;
                        updateCarousel();
                        startAutoPlay(); // Restart auto-play after a brief delay
                    });

                    nextBtn.addEventListener('click', () => {
                        stopAutoPlay(); // Stop auto-play on manual click
                        nextSlide();
                        startAutoPlay(); // Restart
                    });
                }
                
                // Add event listeners to pause/resume auto-play on hover
                if (heroCarousel) {
                    heroCarousel.addEventListener('mouseenter', stopAutoPlay);
                    heroCarousel.addEventListener('mouseleave', startAutoPlay);

                    // Initial load and start auto-play only if the carousel is present
                    if (carouselData.length > 0) {
                        updateCarousel();
                        startAutoPlay();
                    }
                }

                const searchToggle = document.getElementById('search-toggle');
                const searchContainer = document.querySelector('.search-container');
                const searchInput = searchContainer.querySelector('input');

                searchToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    searchContainer.classList.toggle('active');
                    if(searchContainer.classList.contains('active')){
                        setTimeout(() => searchInput.focus(), 200)
                    }
                });

                document.addEventListener('click', function(e) {
                    if (mainNav && mobileMenuToggle && !mainNav.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                        mainNav.classList.remove('mobile-open');
                        mobileMenuToggle.setAttribute('aria-expanded', 'false');
                    }

                    if (genreMenu && !genreMenu.contains(e.target)) {
                        genreMenu.classList.remove('mobile-open');
                    }

                    if(!searchContainer.contains(e.target)) {
                        searchContainer.classList.remove('active');
                    }
                });

                window.addEventListener('resize', function() {
                    if (window.innerWidth > 768 && mainNav && mobileMenuToggle && genreMenu) {
                        mainNav.classList.remove('mobile-open');
                        genreMenu.classList.remove('mobile-open');
                        mobileMenuToggle.setAttribute('aria-expanded', 'false');
                    }
                });
            });
        </script>
    </body>
</html>
