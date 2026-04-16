<?php
session_start();
require_once 'includes/db_connect.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Fetch user info from database
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT username, email, profile_image FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle password change, username change, and profile image upload
    if (isset($_POST['change_username'])) {
        $new_username = trim($_POST['username']);
        if ($new_username) {
            $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
            $stmt->execute([$new_username, $user_id]);
            $_SESSION['username'] = $new_username;
            $message = "Username updated!";
        }
    }
    if (isset($_POST['change_password'])) {
        $new_password = $_POST['password'];
        if ($new_password) {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed, $user_id]);
            $message = "Password changed!";
        }
    }
    if (isset($_POST['upload_image']) && isset($_FILES['profile_image'])) {
        $file = $_FILES['profile_image'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp'];
            if (in_array($ext, $allowed)) {
                $filename = 'profile_' . $user_id . '_' . time() . '.' . $ext;
                $path = 'uploads/profiles/' . $filename;
                if (!is_dir('uploads/profiles')) mkdir('uploads/profiles', 0777, true);
                move_uploaded_file($file['tmp_name'], $path);
                $stmt = $pdo->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                $stmt->execute([$path, $user_id]);
                $message = "Profile image updated!";
            }
        }
    }
    // Refresh user info
    $stmt = $pdo->prepare("SELECT username, email, profile_image FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Profile</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            background: #09111b;
            color: #f6f8fb;
            font-family: "Trebuchet MS", "Segoe UI", sans-serif;
        }
        .profile-shell {
            max-width: 980px;
            margin: 36px auto 48px;
        }
        .profile-container {
            max-width: 860px;
            margin: 0 auto;
            padding: 30px;
            background: rgba(10, 12, 18, 0.92);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            box-shadow: 0 24px 60px rgba(0,0,0,0.32);
            display: grid;
            grid-template-columns: 280px minmax(0, 1fr);
            gap: 28px;
        }
        .profile-container img {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 18px;
            border: 4px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 16px 34px rgba(0, 0, 0, 0.28);
        }
        .profile-container label {
            font-weight: bold;
            margin-top: 0;
            margin-bottom: 8px;
            display: block;
            color: #eef4fb;
        }
        .profile-container input[type="text"],
        .profile-container input[type="password"],
        .profile-container input[type="file"] {
            width: 100%;
            padding: 13px 14px;
            margin-bottom: 12px;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(255, 255, 255, 0.06);
            color: #fff;
            box-sizing: border-box;
        }
        .profile-container button {
            margin-top: 2px;
            padding: 12px 16px;
            border: none;
            background: linear-gradient(135deg, #0f7cff 0%, #1aa4ff 100%);
            color: #fff;
            border-radius: 14px;
            cursor: pointer;
            font-weight: 700;
        }
        .profile-container .email {
            margin-bottom: 18px;
            color: #ffffffff;
            font-size: 1.05rem;
        }
        .profile-container .message {
            margin-bottom: 18px;
            color: #d7ffe7;
            background: rgba(42, 149, 89, 0.18);
            border: 1px solid rgba(73, 209, 125, 0.22);
            padding: 12px 14px;
            border-radius: 14px;
        }
        .profile-sidebar {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: flex-start;
        }
        .profile-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(26, 164, 255, 0.14);
            border: 1px solid rgba(26, 164, 255, 0.2);
            color: #a9dbff;
            font-size: 0.74rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 12px;
        }
        .profile-name {
            font-size: 2rem;
            line-height: 1.05;
            margin: 0 0 10px;
            color: #fff;
        }
        .profile-note {
            color: rgba(255,255,255,0.72);
            margin: 0;
            line-height: 1.6;
        }
        .profile-main form {
            display: grid;
            gap: 18px;
        }
        .profile-section {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 18px;
            padding: 18px;
        }
        .profile-section h3 {
            margin: 0 0 12px;
            font-size: 1.08rem;
            color: #fff;
        }
        .profile-section .email {
            margin-bottom: 0;
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

        @media (max-width: 780px) {
            .profile-shell {
                margin: 20px auto 34px;
            }
            .profile-container {
                grid-template-columns: 1fr;
                gap: 20px;
                padding: 20px;
                border-radius: 18px;
            }
            .profile-sidebar {
                align-items: center;
                text-align: center;
            }
            .profile-name {
                font-size: 1.7rem;
            }
            .profile-main form {
                gap: 14px;
            }
        }

        @media (max-width: 600px) {
            header .container,
            main.container,
            footer .container {
                width: 100%;
                max-width: 100%;
                padding: 0 14px !important;
                margin: 0 auto;
                box-sizing: border-box;
            }
            .profile-shell {
                width: 100%;
            }
            .profile-container {
                width: 100%;
                max-width: 100%;
                padding: 16px;
            }
            .profile-container img {
                width: 104px;
                height: 104px;
            }
            .profile-section {
                padding: 16px;
            }
            .profile-container button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div style="display: flex; align-items: center;">
                    <img src="logo.png" alt="Logo" style="height: 60px; width: 60px; margin-right: 15px; border-radius: 50%; object-fit: cover;">
                    <h1>Profile</h1>
                </div>
            <?php include 'includes/nav.php'; ?>
        </div>
    </header>
    <main class="container">
        <div class="profile-shell">
            <div class="profile-container">
                <aside class="profile-sidebar">
                    <div class="profile-badge">Account Center</div>
                    <img src="<?php echo htmlspecialchars($user['profile_image'] ?? 'assets/default_profile.jpg'); ?>" alt="Profile Image">
                    <h2 class="profile-name"><?php echo htmlspecialchars($user['username']); ?></h2>
                    <p class="profile-note">Manage your username, password, and profile photo in one place.</p>
                </aside>

                <div class="profile-main">
                    <?php if ($message): ?>
                        <div class="message"><?php echo htmlspecialchars($message); ?></div>
                    <?php endif; ?>

                    <form method="post" enctype="multipart/form-data">
                        <section class="profile-section">
                            <h3>Email</h3>
                            <div class="email"><?php echo htmlspecialchars($user['email']); ?></div>
                        </section>

                        <section class="profile-section">
                            <h3>Change Username</h3>
                            <label for="username">New Username</label>
                            <input type="text" name="username" id="username" value="<?php echo htmlspecialchars($user['username']); ?>">
                            <button type="submit" name="change_username">Update Username</button>
                        </section>

                        <section class="profile-section">
                            <h3>Change Password</h3>
                            <label for="password">New Password</label>
                            <input type="password" name="password" id="password" placeholder="New Password">
                            <button type="submit" name="change_password">Change Password</button>
                        </section>

                        <section class="profile-section">
                            <h3>Change Profile Image</h3>
                            <label for="profile_image">Upload New Image</label>
                            <input type="file" name="profile_image" id="profile_image" accept="image/*">
                            <button type="submit" name="upload_image">Upload Image</button>
                        </section>
                    </form>
                </div>
            </div>
        </div>
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
