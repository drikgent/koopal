<?php
session_start();
require_once 'includes/db_connect.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($username) || empty($email) || empty($password)) {
        $message = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format.";
    } elseif (strlen($password) < 6) {
        $message = "Password must be at least 6 characters long.";
    } else {
        try {
            // Check if username or email already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetchColumn() > 0) {
                $message = "Username or email is already taken.";
            } else {
                // Hash the password securely
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                // Insert the new user into the database
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
                $stmt->execute([$username, $email, $password_hash]);

                $message = "Registration successful! You can now log in.";
                header("Location: login.php?success=1");
                exit();
            }
        } catch (\PDOException $e) {
            error_log("Registration Error: " . $e->getMessage());
            $message = "An error occurred. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            background: #09111b;
        }
        .auth-form {
            max-width: 400px;
            margin: 50px auto;
            padding: 20px;
            background-color: #000000ff;
            border-radius: 30px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .auth-form h2 { text-align: center; }
        .auth-form input {
            width: 95%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #000000ff;
            border-radius: 10px;
        }
        .auth-form button {
            width: 100%;
            padding: 10px;
            background-color: black;
            color: #fff;
            border: none;
            border-radius: 15px;
            cursor: pointer;
        }

        .auth-form button:hover {
            background-color: whitesmoke;
            color: black;
            transition: 0.2s ease-in-out;
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
                <ul>
                    
                    
                </ul>
            </nav>
        </div>
    </header>
    <main class="container">
        <div class="auth-form">
            <h2>Create an Account</h2>
            <?php if (!empty($message)): ?>
                <p class="message error"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>
            <form action="register.php" method="POST">
                <input type="text" name="username" placeholder="Username" required>
                <input type="email" name="email" placeholder="Email" required>
                <input type="password" name="password" placeholder="Password" required>
                <button type="submit">Register</button>
            </form>
            <p>already have an account?<a href="login.php">Login</a></p>
        </div>
    </main>
    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> KooPal. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
