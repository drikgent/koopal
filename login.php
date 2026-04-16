<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);


session_start();
require_once 'includes/db_connect.php';

$message = '';

if (isset($_GET['success'])) {
    $message = "Registration successful! Please log in.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier']); // Can be username or email
    $password = $_POST['password'];

    if (empty($identifier) || empty($password)) {
        $message = "Please enter both username/email and password.";
    } else {
        try {
            // Fetch the user by username or email
            $stmt = $pdo->prepare("SELECT id, username, password_hash, role FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$identifier, $identifier]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Password is correct, start a session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role']; // Store the user's role in the session

                header("Location: index.php"); // Redirect to the homepage
                exit();
            } else {
                $message = "Invalid username/email or password.";
            }
        } catch (\PDOException $e) {
            error_log("Login Error: " . $e->getMessage());
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
    <title>Login</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        :root {
            --panel-bg: rgba(7, 11, 18, 0.72);
            --panel-border: rgba(255, 255, 255, 0.14);
            --input-bg: rgba(255, 255, 255, 0.92);
            --text-main: #f7f9fc;
            --text-muted: rgba(247, 249, 252, 0.72);
            --accent: #58a6ff;
            --accent-strong: #1f6feb;
            --shadow: 0 28px 60px rgba(0, 0, 0, 0.45);
        }

        * {
            box-sizing: border-box;
        }

        body {
            background:
                linear-gradient(135deg, rgba(3, 6, 14, 0.76), rgba(5, 10, 22, 0.42)),
                url('assets/bg3.png') center/cover no-repeat fixed;
            position: relative;
            color: var(--text-main);
            overflow-x: hidden;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background:
                radial-gradient(circle at top left, rgba(88, 166, 255, 0.18), transparent 38%),
                radial-gradient(circle at bottom right, rgba(121, 40, 202, 0.12), transparent 32%);
            backdrop-filter: blur(2px);
            z-index: -2;
        }

        header,
        footer {
            background: rgba(0, 0, 0, 0.52);
            backdrop-filter: blur(10px);
        }

        header .container,
        footer .container {
            position: relative;
            z-index: 1;
        }

        header h1 {
            font-size: clamp(2.2rem, 4vw, 3.4rem);
            letter-spacing: -0.04em;
            font-weight: 800;
        }

        main.container {
            width: min(100%, 1200px);
            min-height: calc(100vh - 190px);
            display: grid;
            place-items: center;
            padding: 48px 20px 64px;
        }

        .auth-shell {
            width: 100%;
            display: flex;
            justify-content: center;
        }

        .auth-form {
            width: min(100%, 430px);
            margin: 0 auto;
            padding: 34px;
            background: var(--panel-bg);
            border: 1px solid var(--panel-border);
            border-radius: 28px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(18px);
        }

        .auth-form h2 {
            margin: 0 0 28px;
            text-align: left;
            font-size: clamp(2rem, 3vw, 2.45rem);
            line-height: 1.05;
            letter-spacing: -0.04em;
            font-weight: 800;
        }

        .message {
            margin: 0 0 18px;
            padding: 12px 14px;
            border-radius: 16px;
            font-size: 0.95rem;
            font-weight: 600;
            border: 1px solid transparent;
        }

        .message.error {
            background: rgba(248, 215, 218, 0.16);
            color: #ffd5da;
            border-color: rgba(255, 153, 168, 0.28);
        }

        .message.success {
            background: rgba(212, 237, 218, 0.14);
            color: #cbf5d5;
            border-color: rgba(117, 210, 142, 0.28);
        }

        .field-stack {
            display: grid;
            gap: 14px;
        }

        .auth-form input {
            width: 100%;
            padding: 15px 18px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            background: var(--input-bg);
            color: #101828;
            font-size: 1rem;
            outline: none;
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }

        .auth-form input::placeholder {
            color: #667085;
        }

        .auth-form input:focus {
            border-color: rgba(88, 166, 255, 0.8);
            box-shadow: 0 0 0 4px rgba(88, 166, 255, 0.18);
            transform: translateY(-1px);
        }

        .auth-form button {
            width: 100%;
            margin-top: 18px;
            padding: 14px 18px;
            background: linear-gradient(135deg, var(--accent), var(--accent-strong));
            color: #fff;
            border: none;
            border-radius: 16px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 800;
            letter-spacing: 0.02em;
            transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
        }

        .auth-form button:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 26px rgba(31, 111, 235, 0.28);
            filter: brightness(1.05);
        }

        .auth-link {
            margin-top: 18px;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.98rem;
        }

        .auth-link a {
            color: #ffffff;
            font-weight: 700;
            text-decoration: none;
            border-bottom: 1px solid rgba(255, 255, 255, 0.35);
            transition: color 0.18s ease, border-color 0.18s ease;
        }

        .auth-link a:hover {
            color: #9fd0ff;
            border-color: rgba(159, 208, 255, 0.7);
        }

        .footer-copy {
            display: inline-block;
            color: rgba(255, 255, 255, 0.82);
        }

        @media (max-width: 600px) {
            main.container {
                padding: 28px 16px 40px;
            }

            .auth-form {
                padding: 26px 20px;
                border-radius: 24px;
            }

            header h1 {
                font-size: 2.2rem;
            }
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
        <div class="auth-shell">
            <div class="auth-form">
                <h2>Log In to Your Account</h2>
                <?php if (!empty($message)): ?>
                    <div class="message <?php echo isset($_GET['success']) ? 'success' : 'error'; ?>"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>
                <form action="login.php" method="POST">
                    <div class="field-stack">
                        <input type="text" name="identifier" placeholder="Username or Email" required>
                        <input type="password" name="password" placeholder="Password" required>
                    </div>
                    <button type="submit">Log In</button>
                </form>
                <div class="auth-link">Don’t have an account? <a href="register.php">Register</a></div>
            </div>
        </div>
    </main>
    <footer>
        <div class="container">
            <div class="footer-copy">&copy; <?php echo date('Y'); ?> KooPal. All rights reserved.</div>
        </div>
    </footer>
</body>
</html>
