<?php
declare(strict_types=1);

/**
 * Sync pages.image_url from MySQL source DB to PostgreSQL (Supabase).
 *
 * Usage (PowerShell):
 *   php tools/sync_pages_image_urls.php
 *
 * Required env vars:
 *   SRC_DB_HOST, SRC_DB_PORT, SRC_DB_NAME, SRC_DB_USER, SRC_DB_PASS
 *   DST_DB_HOST, DST_DB_PORT, DST_DB_NAME, DST_DB_USER, DST_DB_PASS
 *
 * Optional:
 *   DST_DB_SSLMODE (default: require)
 *   BATCH_SIZE (default: 1000)
 */

function env_or_fail(string $key): string {
    $v = getenv($key);
    if ($v === false || $v === '') {
        fwrite(STDERR, "Missing required env var: {$key}\n");
        exit(1);
    }
    return $v;
}

function env_or_default(string $key, string $default): string {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

$srcHost = env_or_fail('SRC_DB_HOST');
$srcPort = env_or_default('SRC_DB_PORT', '3306');
$srcName = env_or_fail('SRC_DB_NAME');
$srcUser = env_or_fail('SRC_DB_USER');
$srcPass = env_or_default('SRC_DB_PASS', '');

$dstHost = env_or_fail('DST_DB_HOST');
$dstPort = env_or_default('DST_DB_PORT', '5432');
$dstName = env_or_fail('DST_DB_NAME');
$dstUser = env_or_fail('DST_DB_USER');
$dstPass = env_or_fail('DST_DB_PASS');
$dstSsl  = env_or_default('DST_DB_SSLMODE', 'require');
$batchSize = (int) env_or_default('BATCH_SIZE', '1000');
if ($batchSize < 1) {
    $batchSize = 1000;
}

$srcDsn = "mysql:host={$srcHost};port={$srcPort};dbname={$srcName};charset=utf8mb4";
$dstDsn = "pgsql:host={$dstHost};port={$dstPort};dbname={$dstName};sslmode={$dstSsl}";

$opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

$src = new PDO($srcDsn, $srcUser, $srcPass, $opts);
$dst = new PDO($dstDsn, $dstUser, $dstPass, $opts);
$dst->exec('SET search_path TO "manhwa_db", public');

// Read only rows that look like crawler URLs.
$countStmt = $src->query("SELECT COUNT(*) FROM pages WHERE image_url LIKE 'http%'");
$total = (int) $countStmt->fetchColumn();
echo "Source rows with http URLs: {$total}\n";
if ($total === 0) {
    echo "Nothing to sync.\n";
    exit(0);
}

$select = $src->prepare("
    SELECT id, chapter_id, page_number, image_url
    FROM pages
    WHERE image_url LIKE 'http%'
    ORDER BY id ASC
    LIMIT :limit OFFSET :offset
");

// Update destination by exact row id first; fallback to chapter_id + page_number.
$updateById = $dst->prepare("UPDATE pages SET image_url = :image_url WHERE id = :id");
$updateByChapterPage = $dst->prepare("
    UPDATE pages
    SET image_url = :image_url
    WHERE chapter_id = :chapter_id AND page_number = :page_number
");

$offset = 0;
$updatedById = 0;
$updatedByChapterPage = 0;
$missed = 0;

while ($offset < $total) {
    $select->bindValue(':limit', $batchSize, PDO::PARAM_INT);
    $select->bindValue(':offset', $offset, PDO::PARAM_INT);
    $select->execute();
    $rows = $select->fetchAll();
    if (!$rows) {
        break;
    }

    $dst->beginTransaction();
    try {
        foreach ($rows as $r) {
            $imageUrl = trim((string) ($r['image_url'] ?? ''));
            if ($imageUrl === '') {
                continue;
            }

            $updateById->execute([
                ':image_url' => $imageUrl,
                ':id' => (int) $r['id'],
            ]);

            if ($updateById->rowCount() > 0) {
                $updatedById++;
                continue;
            }

            $updateByChapterPage->execute([
                ':image_url' => $imageUrl,
                ':chapter_id' => (int) $r['chapter_id'],
                ':page_number' => (int) $r['page_number'],
            ]);

            if ($updateByChapterPage->rowCount() > 0) {
                $updatedByChapterPage++;
            } else {
                $missed++;
            }
        }
        $dst->commit();
    } catch (Throwable $e) {
        $dst->rollBack();
        throw $e;
    }

    $offset += count($rows);
    echo "Processed {$offset}/{$total}\n";
}

echo "Done.\n";
echo "Updated by id: {$updatedById}\n";
echo "Updated by chapter/page: {$updatedByChapterPage}\n";
echo "Missed: {$missed}\n";

