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

// Get search term and prepare for query
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
    $stmt_latest = $pdo->prepare("
        SELECT 
            s.id AS series_id,
            s.title AS series_title,
            s.cover_image,
            c.chapter_number,
            c.release_date,
            c.id AS chapter_id
        FROM chapters c
        JOIN series s ON c.series_id = s.id 
        ORDER BY c.release_date DESC
        LIMIT 12
    ");
    $stmt_latest->execute();
    $latest_updates = $stmt_latest->fetchAll(PDO::FETCH_ASSOC);

} catch (\PDOException $e) {
    error_log("Error fetching data: " . $e->getMessage());
    $message = "Could not load manhwa series. Please try again later.";
}

// Convert carousel series data to JSON for JavaScript
$carousel_json = json_encode($carousel_series);

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
 /* Add a gradient background that fades from top to bottom */
 background: linear-gradient(to bottom, #1a1a1a, #0d0d0d);
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
height: 220px; /* taller covers */
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

 font-size: 1.5em;

}

.hero-text p {

 display: none; /* Hide description on mobile */

}

.hero-image {

 padding-right: 0;

 margin-bottom: 15px;

}

.hero-image img {

 width: 120px;

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

/* Optional: Add a subtle hover effect for genre links */
.genre-dropdown a:hover {
  background: #f4f4f4;
}

.search-container {
  position: relative;
  margin-left: 60%;
  display: flex;
  align-items: center;
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
    padding: 0 20px;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.section-header h2 {
    color: #fff;
    margin: 0;
}

.view-all {
    color: #007bff;
    text-decoration: none;
    font-weight: 500;
}

.updates-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 15px;
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

.update-image {
    position: relative;
    aspect-ratio: 2/3;
}

.update-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.update-badge {
    position: absolute;
    bottom: 8px;
    right: 8px;
    background: rgba(0,0,0,0.8);
    color: #fff;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8em;
}

.update-info {
    padding: 10px;
}

.update-info h3 {
    color: #fff;
    font-size: 0.9em;
    margin: 0 0 5px 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.update-time {
    color: #666;
    font-size: 0.8em;
}

@media (max-width: 1200px) {
    .updates-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

@media (max-width: 768px) {
    .updates-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 480px) {
    .updates-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
        </style>
    </head>
    <body>
        <header>
    <div class="container">
        <div style="display: flex; align-items: center;">
            <img src="logo.png" alt="Logo" style="height: 60px; width: 60px; margin-right: 15px; border-radius: 50%; object-fit: cover;">
            <h1>KooPal</h1>
        </div>
        <nav>
            <ul style="display: flex; align-items: center;">
                <li><a href="index.php">HOME</a></li>
                <li><a href="my_favorites.php">FAVORITE</a></li>
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

                <li class="search-container">
                    <button id="search-toggle" class="search-btn"><i class="fa-solid fa-magnifying-glass"></i></button>
                    <form method="get" action="index.php" class="search-form">
                        <input type="text" name="search" placeholder="search series..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                    </form>
                </li>

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
            <?php if (!empty($message)): ?>
                <p class="message error"><?php echo htmlspecialchars($message); ?></p>
            <?php elseif (empty($series) && !empty($searchTerm)): ?>
                <p>No series found matching "<?php echo htmlspecialchars($searchTerm); ?>".</p>
            <?php elseif (empty($series) && empty($searchTerm)): ?>
                 <p>No series found.</p>
            <?php else: ?>

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

            <h2><?php echo !empty($searchTerm) ? 'Search Results for "' . htmlspecialchars($searchTerm) . '"' : 'All Series'; ?></h2>
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
            <!-- Add after hero-carousel section -->

<section class="latest-updates">
    <div class="section-header">
        <h2>Latest Updates</h2>
        <a href="latest.php" class="view-all">View All</a>
    </div>
    <div class="updates-grid">
        <?php foreach ($latest_updates as $update): ?>
            <div class="update-card">
                <!-- Force direct navigation to the chapter reader -->
                <a href="reader.php?chapter_id=<?php echo htmlspecialchars($update['chapter_id']); ?>"
                   onclick="event.preventDefault(); window.location.href=this.href;">
                    <div class="update-image">
                        <img src="<?php echo htmlspecialchars($update['cover_image']); ?>" 
                             alt="<?php echo htmlspecialchars($update['series_title']); ?>">
                        <div class="update-badge">
                            Ch. <?php echo htmlspecialchars($update['chapter_number']); ?>
                        </div>
                    </div>
                    <div class="update-info">
                        <h3><?php echo htmlspecialchars($update['series_title']); ?></h3>
                        <span class="update-time">
                            <?php echo time_elapsed_string($update['release_date']); ?>
                        </span>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</section>
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
                    if(!searchContainer.contains(e.target)) {
                        searchContainer.classList.remove('active');
                    }
                });
            });
        </script>
    </body>
</html>