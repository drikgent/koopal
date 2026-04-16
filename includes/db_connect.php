<?php
declare(strict_types=1);

function env_or_default(string $key, string $default): string {
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

$driver = strtolower(env_or_default('DB_DRIVER', 'pgsql'));
$dbTimezone = env_or_default('DB_TIMEZONE', 'Asia/Manila');
$safeDbTimezone = str_replace("'", "''", $dbTimezone);

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    if ($driver === 'pgsql') {
        $databaseUrl = getenv('DATABASE_URL');

        if ($databaseUrl !== false && $databaseUrl !== '') {
            $parts = parse_url($databaseUrl);
            if ($parts === false) {
                throw new RuntimeException('Invalid DATABASE_URL format.');
            }

            $host = $parts['host'] ?? env_or_default('DB_HOST', 'localhost');
            $port = (string)($parts['port'] ?? env_or_default('DB_PORT', '5432'));
            $dbname = ltrim($parts['path'] ?? '/' . env_or_default('DB_NAME', ''), '/');
            $user = $parts['user'] ?? env_or_default('DB_USER', '');
            $pass = $parts['pass'] ?? env_or_default('DB_PASS', '');

            parse_str($parts['query'] ?? '', $query);
            $sslmode = $query['sslmode'] ?? env_or_default('DB_SSLMODE', 'require');
        } else {
            $host = env_or_default('DB_HOST', 'localhost');
            $port = env_or_default('DB_PORT', '5432');
            $dbname = env_or_default('DB_NAME', '');
            $user = env_or_default('DB_USER', '');
            $pass = env_or_default('DB_PASS', '');
            $sslmode = env_or_default('DB_SSLMODE', 'require');
        }

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode}";
        $pdo = new PDO($dsn, $user, $pass, $options);
        $pdo->exec("SET TIME ZONE '{$safeDbTimezone}'");
    } else {
        $host = env_or_default('DB_HOST', 'localhost');
        $port = env_or_default('DB_PORT', '3306');
        $dbname = env_or_default('DB_NAME', 'manhwa_db');
        $user = env_or_default('DB_USER', 'root');
        $pass = env_or_default('DB_PASS', '');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, $options);
        $pdo->exec("SET time_zone = '+08:00'");
    }

    define('DB_TIMEZONE', $dbTimezone);
} catch (Throwable $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("Database connection failed. Please check environment variables and database status.");
}
