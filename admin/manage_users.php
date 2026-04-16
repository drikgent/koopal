<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
    header('Location: ../login.php');
    exit();
}

function resolve_admin_asset_url($path, $default = '../assets/default_profile.jpg') {
    $path = trim((string) $path);

    if ($path === '') {
        return $default;
    }

    if (preg_match('~^(https?:)?//~i', $path)) {
        return $path;
    }

    if (strpos($path, '../') === 0) {
        return $path;
    }

    if ($path[0] === '/') {
        return '..' . $path;
    }

    return '../' . ltrim($path, '/');
}

$message = '';
$message_type = 'success';
$search = trim($_GET['search'] ?? '');
$current_admin_id = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $target_user_id = (int) ($_POST['user_id'] ?? 0);

    if ($target_user_id <= 0) {
        $message = 'Invalid user selected.';
        $message_type = 'error';
    } else {
        try {
            if ($action === 'update_user') {
                $username = trim($_POST['username'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $role = trim($_POST['role'] ?? 'user');

                if ($username === '' || $email === '' || !in_array($role, ['user', 'admin'], true)) {
                    $message = 'Please provide a valid username, email, and role.';
                    $message_type = 'error';
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?');
                    $stmt->execute([$username, $email, $role, $target_user_id]);
                    $message = 'User updated successfully.';
                }
            } elseif ($action === 'delete_user') {
                if ($target_user_id === $current_admin_id) {
                    $message = 'You cannot delete your own admin account.';
                    $message_type = 'error';
                } else {
                    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
                    $stmt->execute([$target_user_id]);
                    $message = 'User deleted successfully.';
                }
            }
        } catch (\PDOException $e) {
            error_log('Manage users error: ' . $e->getMessage());
            $message = 'Unable to complete that user action right now.';
            $message_type = 'error';
        }
    }
}

$users = [];

try {
    if ($search !== '') {
        $stmt = $pdo->prepare("
            SELECT id, username, email, role, profile_image
            FROM users
            WHERE username LIKE :search OR email LIKE :search
            ORDER BY role DESC, username ASC
        ");
        $stmt->execute([':search' => '%' . $search . '%']);
    } else {
        $stmt = $pdo->query("
            SELECT id, username, email, role, profile_image
            FROM users
            ORDER BY role DESC, username ASC
        ");
    }

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    error_log('Fetch users error: ' . $e->getMessage());
    $message = 'Unable to load users right now.';
    $message_type = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body { background: #09111b; color: #edf6ff; font-family: "Trebuchet MS", "Segoe UI", sans-serif; }
        header { background: rgba(4, 9, 16, 0.94); border-bottom: 1px solid rgba(87, 171, 255, 0.2); }
        header .container { display: flex; align-items: center; justify-content: space-between; width: 100%; max-width: 100%; box-sizing: border-box; padding: 18px 20px; margin: 0; }
        .admin-brand { display: flex; align-items: center; gap: 14px; }
        .admin-brand h1 { margin: 0; }
        header nav ul { display: flex; align-items: center; justify-content: flex-end; margin: 0; padding: 0; list-style: none; }
        header nav a { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 0 16px; border-radius: 999px; text-decoration: none; color: #edf6ff; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(124, 195, 255, 0.16); font-weight: 700; }
        .admin-shell { max-width: 1220px; margin: 34px auto 48px; padding: 0 20px; }
        .admin-topbar { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin-bottom: 24px; }
        .admin-title h1 { margin: 0; font-size: clamp(2rem, 3vw, 3rem); letter-spacing: -0.05em; }
        .admin-title span { display: inline-block; margin-top: 8px; color: rgba(237, 246, 255, 0.7); }
        .admin-actions { display: flex; gap: 12px; flex-wrap: wrap; }
        .admin-link { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 0 16px; border-radius: 999px; text-decoration: none; color: #edf6ff; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(124, 195, 255, 0.16); font-weight: 700; }
        .admin-panel { background: linear-gradient(180deg, rgba(10, 18, 29, 0.94) 0%, rgba(11, 21, 34, 0.88) 100%); border: 1px solid rgba(102, 192, 255, 0.16); border-radius: 24px; box-shadow: 0 24px 60px rgba(0, 0, 0, 0.3); padding: 24px; }
        .search-row { display: flex; gap: 12px; align-items: center; margin-bottom: 20px; }
        .search-row input { flex: 1; min-height: 48px; padding: 0 16px; border-radius: 14px; border: 1px solid rgba(255, 255, 255, 0.12); background: rgba(255, 255, 255, 0.05); color: #fff; }
        .search-row button { min-height: 48px; padding: 0 18px; border: none; border-radius: 14px; background: linear-gradient(135deg, #0c84ff 0%, #20b0ff 100%); color: #fff; font-weight: 700; cursor: pointer; }
        .message { margin-bottom: 18px; padding: 12px 14px; border-radius: 14px; font-weight: 700; }
        .message.success { background: rgba(53, 171, 110, 0.16); border: 1px solid rgba(53, 171, 110, 0.22); color: #c9ffe0; }
        .message.error { background: rgba(255, 99, 132, 0.12); border: 1px solid rgba(255, 99, 132, 0.2); color: #ffd4de; }
        .users-grid { display: grid; gap: 16px; }
        .user-card { display: grid; grid-template-columns: 92px minmax(0, 1fr); gap: 18px; padding: 18px; border-radius: 20px; background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.08); }
        .user-avatar { width: 92px; height: 92px; border-radius: 22px; object-fit: cover; border: 1px solid rgba(255, 255, 255, 0.08); background: rgba(255, 255, 255, 0.05); }
        .user-card form { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)) auto; gap: 12px; align-items: end; }
        .field { display: grid; gap: 8px; }
        .field label { font-size: 0.82rem; font-weight: 700; color: rgba(237, 246, 255, 0.72); text-transform: uppercase; letter-spacing: 0.06em; }
        .field input, .field select { min-height: 44px; padding: 0 14px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.12); background: rgba(255, 255, 255, 0.05); color: #fff; }
        .field select option { color: #000; }
        .user-meta { display: flex; gap: 10px; align-items: center; margin-bottom: 14px; flex-wrap: wrap; }
        .user-name { font-size: 1.2rem; font-weight: 800; }
        .badge { display: inline-flex; align-items: center; justify-content: center; padding: 5px 10px; border-radius: 999px; background: rgba(88, 166, 255, 0.12); border: 1px solid rgba(88, 166, 255, 0.2); color: #9dd7ff; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .save-btn, .delete-btn { min-height: 44px; padding: 0 16px; border: none; border-radius: 12px; cursor: pointer; font-weight: 800; }
        .save-btn { background: linear-gradient(135deg, #0c84ff 0%, #20b0ff 100%); color: #fff; }
        .delete-btn { background: rgba(255, 77, 109, 0.14); color: #ffd4de; border: 1px solid rgba(255, 77, 109, 0.22); }
        .empty-state { padding: 18px; border-radius: 18px; background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.08); color: rgba(237, 246, 255, 0.75); }
        @media (max-width: 980px) { .user-card form { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 720px) { .admin-topbar, .search-row, .user-card { display: grid; grid-template-columns: 1fr; } .user-card { justify-items: start; } .user-card form { grid-template-columns: 1fr; width: 100%; } }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="admin-brand">
                <img src="../admin/logo.png" alt="Logo" style="height:60px; width:60px; border-radius:50%; object-fit:cover;">
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
    <main class="admin-shell">
        <div class="admin-topbar">
            <div class="admin-title">
                <h1>Account Center</h1>
                <span>Review user profiles, update access levels, and manage accounts.</span>
            </div>
            <div class="admin-actions">
                <a class="admin-link" href="review_library.php">Review Library</a>
                <a class="admin-link" href="index2.php">Back to Panel</a>
            </div>
        </div>
        <section class="admin-panel">
            <?php if ($message !== ''): ?>
                <div class="message <?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <form class="search-row" method="get" action="manage_users.php">
                <input type="text" name="search" placeholder="Search by username or email" value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit">Search</button>
            </form>
            <?php if (empty($users)): ?>
                <div class="empty-state">No users matched your search.</div>
            <?php else: ?>
                <div class="users-grid">
                    <?php foreach ($users as $user): ?>
                        <div class="user-card">
                            <img class="user-avatar" src="<?php echo htmlspecialchars(resolve_admin_asset_url($user['profile_image'] ?? '', '../assets/default_profile.jpg')); ?>" alt="<?php echo htmlspecialchars($user['username']); ?>">
                            <div>
                                <div class="user-meta">
                                    <span class="user-name"><?php echo htmlspecialchars($user['username']); ?></span>
                                    <span class="badge"><?php echo htmlspecialchars($user['role'] ?: 'user'); ?></span>
                                    <?php if ((int) $user['id'] === $current_admin_id): ?><span class="badge">Current Admin</span><?php endif; ?>
                                </div>
                                <form method="post" action="manage_users.php<?php echo $search !== '' ? '?search=' . urlencode($search) : ''; ?>">
                                    <input type="hidden" name="action" value="update_user">
                                    <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                                    <div class="field">
                                        <label for="username-<?php echo (int) $user['id']; ?>">Username</label>
                                        <input id="username-<?php echo (int) $user['id']; ?>" type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                    </div>
                                    <div class="field">
                                        <label for="email-<?php echo (int) $user['id']; ?>">Email</label>
                                        <input id="email-<?php echo (int) $user['id']; ?>" type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                    </div>
                                    <div class="field">
                                        <label for="role-<?php echo (int) $user['id']; ?>">Role</label>
                                        <select id="role-<?php echo (int) $user['id']; ?>" name="role" required>
                                            <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>User</option>
                                            <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                        </select>
                                    </div>
                                    <button class="save-btn" type="submit">Save</button>
                                </form>
                                <?php if ((int) $user['id'] !== $current_admin_id): ?>
                                    <form method="post" action="manage_users.php<?php echo $search !== '' ? '?search=' . urlencode($search) : ''; ?>" onsubmit="return confirm('Delete this user account?');" style="margin-top:12px;">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                                        <button class="delete-btn" type="submit">Delete User</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
