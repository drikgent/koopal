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

<series class="php">
    <?php
session_start();
if (!isset($_SESSION['user_id'])) {
header("Location: login.php");
exit();
}
require_once 'includes/db_connect.php'; // Include your database connection script

function format_chapter_number($number) {
    // Handle null or empty values
    if ($number === null || $number === '') {
        return '';
    }
    
    // Convert to float for comparison
    $num = (float)$number;
    
    // Check if it's chapter 0 (prologue)
    if ($num === 0.0) {
        return 'Prologue';
    }
    
    // If it's a whole number, remove decimal part
    if ($num == floor($num)) {
        return (string)floor($num);
    }
    
    // For decimal numbers (like 1.5), keep one decimal place
    return rtrim(rtrim(number_format($num, 1, '.', ''), '0'), '.');
}

$series_id = isset($_GET['id']) ? intval($_GET['id']) : 0; // Get the series ID from the URL

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

try {
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
    
        // Fetch all chapters, including decimals
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.id, c.chapter_number, c.release_date,
            CASE WHEN urc.id IS NOT NULL THEN 1 ELSE 0 END AS is_read
            FROM chapters c
            LEFT JOIN user_read_chapters urc 
            ON urc.chapter_id = c.id AND urc.user_id = ?
            WHERE c.series_id = ?
            ORDER BY CAST(c.chapter_number AS DECIMAL(10,5)) DESC
        ");
        $stmt->execute([$_SESSION['user_id'], $series_id]);
        $chapters = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Loop through chapters and count only the whole numbers
        foreach ($chapters as $chapter) {
            if (floor($chapter['chapter_number']) == $chapter['chapter_number']) {
                $whole_number_chapter_count++;
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
        header .container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
            padding: 10px 0;
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
        .hidden-chapter {
            display: none;
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
                height: 28px;
                width: 28px;
            }
            .site-title {
                font-size: 1.1em;
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
        .btn-read:last-child {
            margin-top: 5px;
            background: #28a745;
            color: #fff;
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
    </style>
</head>
<body>
<header>
    <div class="container">
        <div class="logo-title">
            <img src="logo.png" alt="Logo" class="site-logo">
            <h1 class="site-title">KooPal</h1>
        </div>
        <nav>
            <ul style="display: flex; align-items: center;">
                <li><a href="index.php">HOME</a></li>
                <li><a href="my_favorites.php">MY LIST</a></li>
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                    <li><a href="admin/index2.php">Admin</a></li>
                <?php endif; ?>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <li class="user-menu" style="margin-left: auto; position: relative;">
                        <button class="user-btn">
                            <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?>
                        </button>
                        <div class="logout-dropdown" style="display: none; position: absolute; right: 0; top: 100%; background: #fff; border: 1px solid #ddd; border-radius: 6px; min-width: 120px; z-index: 10;">
                            <a href="profile.php" style="display: block; padding: 10px 16px; color: #333; text-decoration: none;">Profile</a>
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
    <?php if (!empty($error_message)): ?>
        <p class="message error"><?php echo htmlspecialchars($error_message); ?></p>
    <?php elseif ($series_details): ?>
        <div class="series-header">
            <img src="<?php echo htmlspecialchars($series_details['cover_image'] ?? 'assets/covers/default_cover.jpg'); ?>" alt="<?php echo htmlspecialchars($series_details['title']); ?> Cover">
            <div class="series-info">
                <h2><?php echo htmlspecialchars($series_details['title']); ?></h2>
                <p><strong>Author:</strong> <?php echo htmlspecialchars($series_details['author'] ?? 'Unknown'); ?></p>
                <p>
                    <strong>Genre:</strong>
                    <?php if (!empty($genres)): ?>
                        <?php foreach ($genres as $genre_link): ?>
                            <a href="<?php echo htmlspecialchars($genre_link['url']); ?>" class="genre-tag">
                                <?php echo htmlspecialchars(ucwords($genre_link['name'])); ?>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        N/A
                    <?php endif; ?>
                </p>
                <p><strong>Status:</strong> <?php echo htmlspecialchars($series_details['status']); ?></p>
                <p><strong>Chapters:</strong> <?php echo $whole_number_chapter_count; ?></p>
                <p><strong>Description:</strong> <?php echo nl2br(htmlspecialchars($series_details['description'] ?? 'No description available.')); ?></p>
                <button 
    id="favorite-btn" 
    data-series-id="<?php echo $series_details['id']; ?>"
    data-is-favorite="<?php echo $is_favorite ? 'true' : 'false'; ?>"
    class="favorite-btn <?php echo $is_favorite ? 'favorited' : 'not-favorited'; ?>"
>
   
    <span class="text"><?php echo $is_favorite ? 'Remove from Favorites' : 'Add to Favorites'; ?></span>
</button>
                <?php if (!empty($chapters)): ?>
                    <div style="margin-top:20px;">
                        <?php 
                            $first_chapter = end($chapters); // Get the earliest chapter
                            $chapter_num = format_chapter_number($first_chapter['chapter_number']);
                            $button_text = $chapter_num === 'Prologue' ? 'Read Prologue' : 'Read Chapter ' . $chapter_num;
                        ?>
                        <a href="read.php?series_id=<?php echo htmlspecialchars($series_details['id']); ?>&chapter_id=<?php echo htmlspecialchars($first_chapter['id']); ?>"
                           class="btn-read"><?php echo htmlspecialchars($button_text); ?></a>

                        <a href="read.php?series_id=<?php echo htmlspecialchars($series_details['id']); ?>&chapter_id=<?php echo htmlspecialchars($chapters[0]['id']); ?>"
                           class="btn-read">Read Latest Chapter</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <h3>Chapters</h3>
        <?php if (empty($chapters)): ?>
            <p>No chapters available yet.</p>
        <?php else: ?>
            <div class="chapter-container">
                <ul class="chapter-list">
                    <?php foreach ($chapters as $index => $chapter): 
                        $chapter_num = format_chapter_number($chapter['chapter_number']);
                        $display_text = $chapter_num === 'Prologue' ? 'Prologue' : 'Chapter ' . $chapter_num;
                    ?>
                        <li class="<?php echo $chapter['is_read'] ? 'chapter-read' : ''; ?> <?php echo $index >= 150 ? 'hidden-chapter' : ''; ?>">
                            <a href="read.php?series_id=<?php echo htmlspecialchars($series_details['id']); ?>&chapter_id=<?php echo htmlspecialchars($chapter['id']); ?>">
                                <?php echo htmlspecialchars($display_text); ?>
                            </a>
                            <span style="color: #aaa; font-size: 0.8em; margin-left: 10px;">
                    (<?php echo date('M d, Y', strtotime($chapter['release_date'])); ?>)
                </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($chapters) > 150): ?>
                    <button id="seeMoreBtn" class="see-more-btn">See More</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
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

        const seeMoreBtn = document.getElementById('seeMoreBtn');
        if (seeMoreBtn) {
            seeMoreBtn.addEventListener('click', function() {
                document.querySelectorAll('.hidden-chapter').forEach(ch => {
                    ch.style.display = 'list-item';
                });
                seeMoreBtn.style.display = 'none';
            });
        }

        const favoriteBtn = document.getElementById('favorite-btn');

if (favoriteBtn) {
    // Function to update the button's look and text
    function updateFavoriteButtonUI(isFavorite) {
        const icon = favoriteBtn.querySelector('.icon');
        const text = favoriteBtn.querySelector('.text');
        
        favoriteBtn.classList.toggle('favorited', isFavorite);
        favoriteBtn.classList.toggle('not-favorited', !isFavorite);

        icon.textContent = isFavorite ? '💖' : '⭐';
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
    });
</script>
</body>
</html>
</series>

 new crawler backup 
<?php
set_time_limit(0);
error_reporting(E_ALL);
require_once '../includes/db_connect.php';

$message = "";
$message_type = "";
$debug = true;

// A more robust and configurable list of ignored image patterns
$ignored_image_patterns = [
    'logo.png',
    'manhuaus.jpg',
    'manhuaus.webp',
    'logo',
    'banner',
    'ads',
    'icon',
    'footer',
    'gravatar.com',
];

// Function to check if an image should be ignored
function is_ignored_image($url, $patterns) {
    foreach ($patterns as $pattern) {
        if (strpos($url, $pattern) !== false) {
            return true;
        }
    }
    return false;
}

// Fetch series list
$seriesList = $pdo->query("SELECT id, title FROM series ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $series_id = $_POST['series_id'] ?? null;
    $chapter_urls_input = $_POST['chapter_urls'] ?? '';
    $chapter_urls = array_filter(explode("\n", trim($chapter_urls_input)));

    if (empty($series_id) || empty($chapter_urls)) {
        $message = "❌ Series ID and at least one Chapter URL are required.";
        $message_type = "error";
    } else {
        $max_chapters_per_batch = 500;
        $total_chapters_added = 0;

        foreach ($chapter_urls as $initial_url) {
            $chapter_url = trim($initial_url);
            $chapters_added_from_start = 0;

            while ($chapter_url && $chapters_added_from_start < $max_chapters_per_batch) {
                if ($debug) echo "<p>Crawling URL: " . htmlspecialchars($chapter_url) . "</p>";

                // Fetch HTML
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $chapter_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
                $html = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if (empty($html) || $http_code >= 400) {
                    if ($debug) echo "<p>❌ Failed to fetch HTML or received HTTP error: $http_code</p>";
                    break;
                }

                if ($debug) {
                    file_put_contents("debug_chapter.html", $html);
                    echo "<p>✅ Saved HTML to debug_chapter.html</p>";
                }

                // --- Parse HTML with DOM ---
                $dom = new DOMDocument();
                libxml_use_internal_errors(true);
                $dom->loadHTML($html);
                libxml_clear_errors();
                $xpath = new DOMXPath($dom);

                // --- Multiple possible selectors for manga pages ---
                $possiblePaths = [
                    "//div[contains(@class,'reading-content')]//div[contains(@class,'page-break')]//img",
                    "//div[contains(@id,'chapter-content')]//img",
                    "//div[contains(@class,'reader-area')]//img",
                    "//div[contains(@class,'read-content')]//img",
                    "//div[contains(@class,'chapter-content')]//img",
                    "//article//img",
                ];

                $imageNodes = null;
                foreach ($possiblePaths as $path) {
                    $nodes = $xpath->query($path);
                    if ($nodes->length > 0) {
                        $imageNodes = $nodes;
                        if ($debug) echo "<p>✅ Using selector: <code>$path</code> (" . $nodes->length . " images found)</p>";
                        break;
                    }
                }

                // --- Collect image URLs ---
                $images = [];
                if ($imageNodes) {
                    foreach ($imageNodes as $node) {
                        if ($node instanceof DOMElement) {
                            // Extract image source safely (handles lazyload, newlines, srcset, etc.)
$img_src = $node->getAttribute('data-src')
    ?: $node->getAttribute('data-lazy-src')
    ?: $node->getAttribute('data-original')
    ?: $node->getAttribute('data-cfsrc')   // Cloudflare lazyload
    ?: $node->getAttribute('data-srcset')
    ?: $node->getAttribute('srcset')
    ?: $node->getAttribute('src');

// Clean up messy newlines or tabs inside attribute values
if (!empty($img_src)) {
    $img_src = trim(preg_replace('/\s+/', '', $img_src));
}

// If srcset has multiple URLs, grab the first one
if (strpos($img_src, ' ') !== false) {
    $img_src = trim(explode(' ', $img_src)[0]);
}

// Only add valid URLs
if (!empty($img_src) && filter_var($img_src, FILTER_VALIDATE_URL)) {
    $images[] = $img_src;
}



                            if (!empty($img_src) && !is_ignored_image($img_src, $ignored_image_patterns)) {
                                $images[] = $img_src;
                                if ($debug) echo "<p>Found page image: " . htmlspecialchars($img_src) . "</p>";
                            }
                        }
                    }
                }

                // --- Convert relative URLs to absolute ---
                foreach ($images as &$img_src) {
                    if (strpos($img_src, 'http') !== 0) {
                        $parsed_url = parse_url($chapter_url);
                        $base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
                        if ($img_src[0] !== '/') $img_src = '/' . $img_src;
                        $img_src = $base_url . $img_src;
                    }
                }

                // --- Stop if no images ---
                if (count($images) === 0) {
                    if ($debug) echo "<p>⚠️ No valid images found → stopping.</p>";
                    break;
                }

                // --- Detect Chapter Number ---
                $chapter_number = null;
                if (preg_match('/chapter[-_ ]?([0-9]+(?:[-_.][0-9]+)?)/i', $chapter_url, $chMatch)) {
                    $chapter_number = str_replace(['-', '_'], '.', $chMatch[1]);
                } elseif (preg_match('/prologue/i', $chapter_url)) {
                    $chapter_number = "0";
                }

                if ($chapter_number !== null && is_numeric($chapter_number)) {
                    $chapter_number = number_format((float)$chapter_number, 2, '.', '');
                } else {
                    $stmtLast = $pdo->prepare("SELECT MAX(CAST(chapter_number AS DECIMAL(10,2))) FROM chapters WHERE series_id = ?");
                    $stmtLast->execute([$series_id]);
                    $lastChapter = $stmtLast->fetchColumn();
                    $chapter_number = $lastChapter !== null ? number_format($lastChapter + 1, 2, '.', '') : "1.00";
                }

                $chapter_title = (floor($chapter_number) == $chapter_number)
                    ? "Chapter " . (int)$chapter_number
                    : "Chapter " . rtrim(rtrim(number_format($chapter_number, 2, '.', ''), '0'), '.');

                // --- Insert Chapter ---
                $stmt = $pdo->prepare("
                    INSERT INTO chapters (series_id, chapter_number, title, release_date)
                    VALUES (:series_id, :chapter_number, :title, NOW())
                    ON DUPLICATE KEY UPDATE title = VALUES(title), release_date = VALUES(release_date)
                ");
                $stmt->execute([
                    'series_id' => $series_id,
                    'chapter_number' => $chapter_number,
                    'title' => $chapter_title
                ]);

                $chapter_id = $pdo->lastInsertId();
                if (!$chapter_id) {
                    $stmt2 = $pdo->prepare("SELECT id FROM chapters WHERE series_id = ? AND chapter_number = ?");
                    $stmt2->execute([$series_id, $chapter_number]);
                    $chapter_id = $stmt2->fetchColumn();
                }

                // --- Save Pages ---
                $pdo->prepare("DELETE FROM pages WHERE chapter_id = ?")->execute([$chapter_id]);
                $stmtPage = $pdo->prepare("INSERT INTO pages (chapter_id, page_number, image_url) VALUES (?, ?, ?)");
                $page_number = 1;
                foreach ($images as $img) {
                    $stmtPage->execute([$chapter_id, $page_number, $img]);
                    $page_number++;
                }

                $chapters_added_from_start++;
                $total_chapters_added++;

                // --- Find Next Chapter Link ---
                $next_url = null;
                $next_link_node = $xpath->query("//a[contains(@class,'next_page') or contains(text(),'Next')]")->item(0);
                if ($next_link_node instanceof DOMElement) {
                    $next_url = $next_link_node->getAttribute('href');
                    if (!empty($next_url)) {
                        $parsed_url = parse_url($chapter_url);
                        $base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
                        if (strpos($next_url, 'http') !== 0) {
                            if ($next_url[0] !== '/') $next_url = '/' . $next_url;
                            $next_url = $base_url . $next_url;
                        }
                    }
                }

                if (!$next_url) {
                    if ($debug) echo "<p>⛔ No 'Next' link found → stopping.</p>";
                    break;
                }

                $chapter_url = $next_url;
            }
        }

        if ($total_chapters_added > 0) {
            $message = "✅ Imported/Updated $total_chapters_added chapter(s) successfully.";
            $message_type = "success";
        } else {
            $message = "⚠️ No chapters were imported. The URLs may be invalid or the page structure has changed.";
            $message_type = "error";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Chapter via Crawler - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body {
            background-image: url('../assets/bg3.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            opacity: 0.8;
        }
        .form-container {
            background-color: black;
            border-radius: 20px;
            padding: 20px;
        }
        .form-container label,
        .form-container select,
        .form-container textarea {
            display: block;
            width: 100%;
            margin-bottom: 15px;
        }
        .form-container button {
            background-color: #28a745;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1em;
        }
        .message.success { background-color: #d4edda; color: #155724; padding: 10px; border-radius: 5px; }
        .message.error { background-color: #f8d7da; color: #721c24; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>
<header>
    <div class="container">
        <div style="display: flex; align-items: center;">
            <img src="../admin/logo.png" alt="Logo"
                 style="height: 60px; width: 60px; margin-right: 15px; border-radius: 50%; object-fit: cover;">
            <h1>Admin</h1>
        </div>
        <nav>
            <ul>
                <li><a href="index2.php">Admin Home</a></li>
                <li><a href="../index.php">Back to Site</a></li>
            </ul>
        </nav>
    </div>
</header>

<main class="container">
    <div class="form-container">
        <?php if (!empty($message)): ?>
            <p class="message <?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </p>
        <?php endif; ?>
        <form action="upload_chapter_crawler.php" method="POST">
            <label for="series_id">Select Series:</label>
            <select id="series_id" name="series_id" required>
                <option value="">-- Select Series --</option>
                <?php
                foreach ($seriesList as $row) {
                    echo "<option value='" . htmlspecialchars($row['id']) . "'>" . htmlspecialchars($row['title']) . "</option>";
                }
                ?>
            </select>

            <label for="chapter_urls">Starting Chapter URL:</label>
            <textarea id="chapter_urls" name="chapter_urls" placeholder="https://example.com/series/chapter-1" rows="5" required></textarea>

            <button type="submit">Fetch & Upload</button>
        </form>
    </div>
</main>

<footer>
    <div class="container">
        <p>&copy; <?php echo date('Y'); ?> KooPal Admin.</p>
    </div>
</footer>
</body>
</html>

// admin button
<?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                    <li><a href="admin/index2.php">Admin</a></li>
                <?php endif; ?>