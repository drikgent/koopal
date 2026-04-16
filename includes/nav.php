
<nav>
    <ul style="display: flex; align-items: center;">
        <li><a href="index.php">HOME</a></li>
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
                    <a href="settings.php" style="display: block; padding: 10px 16px;">Settings</a>
                    <a href="logout.php" style="display: block; padding: 10px 16px;">Log out</a>
                </div>
            </li>
        <?php else: ?>
            <li><a href="login.php">Login</a></li>
            <li><a href="register.php">Register</a></li>
        <?php endif; ?>
    </ul>
</nav>