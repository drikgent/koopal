<?php
session_start();

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
    header('Location: ../login.php');
    exit();
}

require_once '../includes/db_connect.php';

$message = '';
$message_type = '';
$selected_series_id = '';

$status_options = ['Ongoing', 'Completed', 'Dropped', 'Hiatus'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_series_id = (string) ((int) ($_POST['series_id'] ?? 0));
    $status = trim($_POST['status'] ?? '');

    if ($selected_series_id === '0' || !in_array($status, $status_options, true)) {
        $message = 'Please choose a valid series and status.';
        $message_type = 'error';
    } else {
        try {
            $stmt = $pdo->prepare('UPDATE series SET status = ? WHERE id = ?');
            $stmt->execute([$status, (int) $selected_series_id]);

            if ($stmt->rowCount() > 0) {
                $message = 'Series status updated successfully.';
                $message_type = 'success';
            } else {
                $message = 'No changes were made. The selected series may already have that status.';
                $message_type = 'success';
            }
        } catch (\PDOException $e) {
            error_log('Error updating series status: ' . $e->getMessage());
            $message = 'Failed to update series status.';
            $message_type = 'error';
        }
    }
}

$series_list = $pdo->query('SELECT id, title, status FROM series ORDER BY title ASC')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Series Status</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body {
            background-image: url('../assets/bg3.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            position: relative;
            color: #f6f8fb;
            font-family: "Trebuchet MS", "Segoe UI", sans-serif;
        }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background:
                linear-gradient(180deg, rgba(4, 8, 16, 0.84) 0%, rgba(4, 8, 16, 0.58) 28%, rgba(4, 8, 16, 0.78) 100%),
                radial-gradient(circle at top center, rgba(73, 209, 125, 0.14), transparent 35%);
            pointer-events: none;
            z-index: 0;
        }
        header, main, footer {
            position: relative;
            z-index: 1;
        }
        header {
            background: rgba(3, 6, 12, 0.7);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid rgba(120, 177, 255, 0.3);
        }
        header .container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            padding: 18px 20px;
            margin: 0;
        }
        .admin-brand {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        header h1 {
            margin: 0;
        }
        header nav ul {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        header nav a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 16px;
            border-radius: 999px;
            text-decoration: none;
            color: #edf6ff;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(124, 195, 255, 0.16);
            font-weight: 700;
        }
        .page-shell {
            max-width: 1100px;
            margin: 42px auto 32px;
            padding: 0 20px;
        }
        .status-panel {
            background: rgba(10, 12, 18, 0.82);
            border: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow: 0 24px 70px rgba(0, 0, 0, 0.45);
            backdrop-filter: blur(18px);
            border-radius: 28px;
            padding: 28px;
        }
        .status-panel h2 {
            margin: 0 0 20px;
            font-size: 2rem;
        }
        .status-form {
            display: grid;
            grid-template-columns: 1.4fr 0.8fr auto;
            gap: 14px;
            align-items: end;
            margin-bottom: 26px;
        }
        .field-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 700;
        }
        .field-group select {
            width: 100%;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
        }
        .field-group select option {
            color: #111;
        }
        .status-form button {
            padding: 14px 20px;
            border: none;
            border-radius: 14px;
            background: linear-gradient(135deg, #49d17d 0%, #2bb463 100%);
            color: #fff;
            font-weight: 700;
            cursor: pointer;
        }
        .message {
            margin: 0 0 18px;
            padding: 14px 16px;
            border-radius: 16px;
            text-align: center;
            border: 1px solid transparent;
        }
        .message.success {
            background: rgba(42, 149, 89, 0.18);
            color: #d7ffe7;
            border-color: rgba(73, 209, 125, 0.25);
        }
        .message.error {
            background: rgba(185, 54, 86, 0.18);
            color: #ffdce6;
            border-color: rgba(255, 124, 156, 0.22);
        }
        .series-table {
            width: 100%;
            border-collapse: collapse;
            overflow: hidden;
            border-radius: 18px;
        }
        .series-table-wrap {
            max-height: 60vh;
            overflow-y: auto;
            overflow-x: auto;
            border-radius: 18px;
        }
        .series-table-wrap::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }
        .series-table-wrap::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.18);
            border-radius: 999px;
        }
        .series-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
        }
        .series-table th,
        .series-table td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .series-table th {
            color: #dfe8f5;
            background: rgba(255, 255, 255, 0.06);
        }
        .series-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.04);
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(73, 209, 125, 0.16);
            border: 1px solid rgba(73, 209, 125, 0.22);
            color: #d7ffe7;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        @media (max-width: 760px) {
            .status-form {
                grid-template-columns: 1fr;
            }
            .status-panel {
                padding: 22px;
            }
            .series-table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="admin-brand">
                <img src="../admin/logo.png" alt="Logo" style="height: 60px; width: 60px; margin-right: 15px; border-radius: 50%; object-fit: cover;">
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

    <main class="page-shell">
        <section class="status-panel">
            <h2>Update Series Status</h2>

            <?php if (!empty($message)): ?>
                <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <form class="status-form" action="update_series_status.php" method="POST">
                <div class="field-group">
                    <label for="series_id">Series</label>
                    <select id="series_id" name="series_id" required>
                        <option value="">-- Select Series --</option>
                        <?php foreach ($series_list as $series): ?>
                            <option value="<?php echo htmlspecialchars($series['id']); ?>" <?php echo $selected_series_id === (string) $series['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($series['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" required>
                        <?php foreach ($status_options as $status): ?>
                            <option value="<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($status); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit">Update Status</button>
            </form>

            <div class="series-table-wrap">
                <table class="series-table">
                    <thead>
                        <tr>
                            <th>Series</th>
                            <th>Current Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($series_list as $series): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($series['title']); ?></td>
                                <td><span class="status-badge"><?php echo htmlspecialchars($series['status'] ?: 'Unknown'); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> KooPal Admin.</p>
        </div>
    </footer>
</body>
</html>
