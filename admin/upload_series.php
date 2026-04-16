<?php
require_once '../includes/db_connect.php'; // Correct path to db connection

$message = '';
$message_type = '';

// Check if the form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect form data
    $title = trim($_POST['title']);
    $author = trim($_POST['author']);
    $description = trim($_POST['description']);
    $genre = trim($_POST['genre']);
    $status = trim($_POST['status']);
    
    // Default cover image path if no file is uploaded
    $cover_image_path = 'assets/covers/default_cover.jpg'; 

    // Basic validation
    if (empty($title) || empty($status)) {
        $message = "Title and Status are required fields.";
        $message_type = "error";
    } else {
        // Handle file upload
        if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
            $file_tmp_name = $_FILES['cover_image']['tmp_name'];
            $file_ext = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (in_array($file_ext, $allowed_extensions)) {
                // Create a unique filename to prevent conflicts and security issues
                $new_file_name = uniqid('cover_', true) . '.' . $file_ext;
                $upload_dir = '../assets/covers/';
                $upload_path = $upload_dir . $new_file_name;

                // Create the directory if it doesn't exist
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                // Move the uploaded file to its permanent location
                if (move_uploaded_file($file_tmp_name, $upload_path)) {
                    $cover_image_path = 'assets/covers/' . $new_file_name;
                } else {
                    $message = "Failed to upload cover image.";
                    $message_type = "error";
                }
            } else {
                $message = "Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.";
                $message_type = "error";
            }
        }

        // Only insert into DB if there are no errors
        if ($message_type !== "error") {
            try {
                // Use a prepared statement to prevent SQL Injection
                $stmt = $pdo->prepare("INSERT INTO series (title, author, description, genre, status, cover_image) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $title, 
                    empty($author) ? null : $author, 
                    empty($description) ? null : $description, 
                    empty($genre) ? null : $genre, 
                    $status, 
                    $cover_image_path
                ]);
                $message = "Series '$title' added successfully!";
                $message_type = "success";
            } catch (\PDOException $e) {
                error_log("Database Error: " . $e->getMessage());
                $message = "An error occurred. Please try again.";
                $message_type = "error";
            }
        }
    }

    // After file upload attempt
    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] !== UPLOAD_ERR_OK) {
        $message .= " (Upload error code: " . $_FILES['cover_image']['error'] . ")";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload New Series</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --page-bg: #09111b;
            --panel-bg: rgba(9, 16, 27, 0.9);
            --panel-border: rgba(123, 195, 255, 0.14);
            --text-main: #f6f8fb;
            --accent: #1f8fff;
            --success: #2fc66f;
            --field-bg: rgba(255, 255, 255, 0.05);
        }
        body {
            background-color: var(--page-bg);
            background-image: url('../assets/bg3.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            color: var(--text-main);
            position: relative;
            font-family: "Trebuchet MS", "Segoe UI", sans-serif;
        }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background:
                linear-gradient(180deg, rgba(5, 10, 18, 0.86) 0%, rgba(6, 11, 19, 0.72) 30%, rgba(6, 11, 19, 0.9) 100%),
                radial-gradient(circle at top left, rgba(31, 143, 255, 0.16), transparent 28%),
                radial-gradient(circle at bottom right, rgba(37, 201, 135, 0.12), transparent 24%);
            z-index: 0;
            pointer-events: none;
        }
        header, main, footer {
            position: relative;
            z-index: 1;
        }
        header {
            background: rgba(6, 11, 19, 0.9);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(123, 195, 255, 0.16);
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
        .admin-brand {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .admin-brand h1 {
            margin: 0;
            font-size: clamp(2.3rem, 4vw, 3.2rem);
            line-height: 1;
            letter-spacing: -0.05em;
        }
        .admin-brand img {
            box-shadow: 0 16px 34px rgba(0, 0, 0, 0.26);
            border: 2px solid rgba(255, 255, 255, 0.14);
        }
        nav ul {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        nav a {
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
        nav a:hover {
            transform: translateY(-1px);
            background: rgba(31, 143, 255, 0.1);
            border-color: rgba(123, 195, 255, 0.26);
        }
        main.container {
            max-width: none;
            width: 100%;
            padding-top: 42px;
            padding-bottom: 42px;
        }
        .upload-shell {
            width: 90%;
            max-width: 90%;
            margin: 0 auto;
        }
        .form-container {
            border-radius: 30px;
            background: var(--panel-bg);
            border: 1px solid var(--panel-border);
            box-shadow: 0 24px 70px rgba(0, 0, 0, 0.36);
            backdrop-filter: blur(18px);
            padding: 36px;
            position: relative;
            overflow: hidden;
            min-height: 720px;
        }
        .form-container::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                linear-gradient(135deg, rgba(31, 143, 255, 0.12), transparent 34%),
                radial-gradient(circle at bottom right, rgba(37, 201, 135, 0.12), transparent 28%);
            pointer-events: none;
        }
        .form-head,
        .message,
        .upload-form {
            position: relative;
            z-index: 1;
        }
        .form-head {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 24px;
        }
        .form-icon {
            width: 58px;
            height: 58px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 20px;
            background: rgba(31, 143, 255, 0.12);
            border: 1px solid rgba(31, 143, 255, 0.16);
            color: #9dd7ff;
            font-size: 1.35rem;
            flex-shrink: 0;
        }
        #head {
            text-align: left;
            font-size: clamp(2rem, 3vw, 2.8rem);
            font-weight: 900;
            margin: 0;
            line-height: 1;
            letter-spacing: -0.05em;
        }
        .upload-form {
            display: grid;
            gap: 22px;
        }
        .field-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22px;
        }
        .field,
        .field-wide {
            display: grid;
            gap: 8px;
        }
        .field-wide {
            grid-column: 1 / -1;
        }
        .field label,
        .field-wide label {
            display: block;
            color: var(--text-main);
            font-size: 0.95rem;
            font-weight: 800;
            letter-spacing: 0.02em;
        }
        .form-container input,
        .form-container textarea,
        .form-container select {
            width: 100%;
            padding: 16px 18px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            background: var(--field-bg);
            color: #fff;
            box-sizing: border-box;
            outline: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
        }
        .form-container select {
            color: #f6f8fb;
        }
        .form-container select:focus,
        .form-container select:active {
            color: #111827;
        }
        .form-container select option {
            color: #111827;
        }
        .form-container textarea {
            min-height: 220px;
            resize: vertical;
        }
        .form-container input:focus,
        .form-container textarea:focus,
        .form-container select:focus {
            border-color: rgba(88, 184, 255, 0.76);
            box-shadow: 0 0 0 4px rgba(31, 143, 255, 0.14);
            transform: translateY(-1px);
        }
        .file-wrap {
            display: grid;
            gap: 10px;
        }
        .file-wrap input[type="file"] {
            padding: 16px;
        }
        .form-container button {
            min-height: 58px;
            padding: 0 28px;
            border: none;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--success) 0%, #49db85 100%);
            color: white;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 800;
            letter-spacing: 0.02em;
            justify-self: start;
            transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
        }
        .form-container button:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 30px rgba(47, 198, 111, 0.24);
            filter: brightness(1.03);
        }
        .message {
            margin-bottom: 18px;
            padding: 13px 15px;
            border-radius: 16px;
            font-weight: 700;
        }
        .message.success {
            background: rgba(53, 171, 110, 0.16);
            color: #cbffe1;
            border: 1px solid rgba(53, 171, 110, 0.24);
        }
        .message.error {
            background: rgba(255, 99, 132, 0.12);
            color: #ffd4de;
            border: 1px solid rgba(255, 99, 132, 0.22);
        }
        footer .container {
            color: rgba(214, 227, 243, 0.72);
        }
        @media (max-width: 700px) {
            .container {
                max-width: 100vw !important;
                width: 100vw !important;
                padding-left: 14px !important;
                padding-right: 14px !important;
                box-sizing: border-box;
            }
            header .container {
                flex-direction: column;
                align-items: flex-start;
            }
            nav ul {
                flex-wrap: wrap;
            }
            .upload-shell {
                max-width: 100%;
            }
            .form-container {
                width: 100%;
                padding: 24px;
                border-radius: 24px;
                min-height: auto;
            }
            .field-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            .form-container button {
                width: 100%;
                justify-self: stretch;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="admin-brand">
                        <img src="../admin/logo.png" style="height: 60px; width: 60px; border-radius: 50%; object-fit: cover;">
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

    <main class="container">
        <div class="upload-shell">
        <div class="form-container">
            <?php if (!empty($message)): ?>
                <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>
            <form action="upload_series.php" method="POST" enctype="multipart/form-data">
                <div class="form-head">
                    <span class="form-icon"><i class="fa-solid fa-square-plus"></i></span>
                    <label for="" id="head">Upload Series</label>
                </div>
                <div class="upload-form">
                    <div class="field-grid">
                        <div class="field-wide">
                            <label for="title">Series Title</label>
                            <input type="text" id="title" name="title" required>
                        </div>

                        <div class="field">
                            <label for="author">Author</label>
                            <input type="text" id="author" name="author">
                        </div>

                        <div class="field">
                            <label for="genre">Genre</label>
                            <input type="text" id="genre" name="genre">
                        </div>

                        <div class="field-wide">
                            <label for="description">Description</label>
                            <textarea id="description" name="description"></textarea>
                        </div>

                        <div class="field">
                            <label for="status">Status</label>
                            <select id="status" name="status" required>
                                <option value="Ongoing">Ongoing</option>
                                <option value="Completed">Completed</option>
                                <option value="Dropped">Dropped</option>
                                <option value="Hiatus">Hiatus</option>
                            </select>
                        </div>

                        <div class="field">
                            <label for="cover_image">Cover Image</label>
                            <div class="file-wrap">
                                <input type="file" id="cover_image" name="cover_image" accept="image/*">
                            </div>
                        </div>
                    </div>

                    <button type="submit">Add Series</button>
                </div>
            </form>
        </div>
        </div>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> KooPal Admin.</p>
        </div>
    </footer>
</body>
</html>
