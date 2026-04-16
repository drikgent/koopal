<?php 
set_time_limit(0);
ini_set('max_execution_time', '0');
session_start();
require_once '../includes/db_connect.php';

$message = '';
$message_type = '';
$series_list = [];
$selected_series_id = '';
$prefill_chapter_url = '';
$prefill_chapter_number = '';
$prefill_chapter_title = '';

try {
    $stmt = $pdo->query("SELECT id, title FROM series ORDER BY title ASC");
    $series_list = $stmt->fetchAll();
}catch (\PDOException $e) {
    error_log("Error fetching series list: " . $e->getMessage());
    $message = "Error loading series list. Please try again later.";
    $message_type = "error";
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $selected_series_id = trim((string) ($_GET['series_id'] ?? ''));
    $prefill_chapter_url = trim((string) ($_GET['chapter_url'] ?? ''));
    $prefill_chapter_number = trim((string) ($_GET['chapter_number'] ?? ''));
    $prefill_chapter_title = trim((string) ($_GET['chapter_title'] ?? ''));
}

function make_absolute_url($baseUrl, $url) {
    $url = trim($url);
    if ($url === '') return '';
    if (parse_url($url, PHP_URL_SCHEME)) return $url;

    $base = parse_url($baseUrl);
    $scheme = $base['scheme'] ?? 'https';
    $host = $base['host'] ?? '';

    if ($host === '') {
        return $url;
    }

    if (strpos($url, '/') === 0) {
        return "$scheme://$host$url";
    }

    $path = rtrim(dirname($base['path'] ?? '/'), '/') . '/';
    return "$scheme://$host$path$url";
}

function get_base_origin($url) {
    $parts = parse_url($url);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }

    return $parts['scheme'] . '://' . $parts['host'] . '/';
}

function fetch_remote_html($url, &$error = null) {
    $error = null;
    $referer = get_base_origin($url) ?: $url;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_AUTOREFERER => true,
        CURLOPT_REFERER => $referer,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ],
    ]);

    $html = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($html !== false && trim($html) !== '') {
        return $html;
    }

    if ($curlError !== '') {
        $error = "Request error: $curlError";
    } elseif ($httpCode > 0) {
        $error = "HTTP $httpCode returned from chapter URL";
    } else {
        $error = "Empty response returned from chapter URL";
    }

    if (!ini_get('allow_url_fopen')) {
        return false;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Referer: ' . $referer,
            ]),
            'timeout' => 60,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $fallbackHtml = @file_get_contents($url, false, $context);
    if ($fallbackHtml !== false && trim($fallbackHtml) !== '') {
        $error = null;
        return $fallbackHtml;
    }

    return false;
}

function detect_crawler_block_reason($html, $url) {
    $html = strtolower((string) $html);
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));

    if (strpos($host, 'manhuaus') !== false) {
        foreach (['just a moment', 'cf-browser-verification', 'cf-chl', 'challenge-platform', '__cf$cv$params'] as $needle) {
            if (strpos($html, $needle) !== false) {
                return 'manhuaus blocked the crawler with anti-bot protection.';
            }
        }
        return '';
    }

    $signals = [
        'cloudflare' => ['just a moment', 'cf-browser-verification', 'cf-chl', 'challenge-platform', '__cf$cv$params'],
        'captcha' => ['captcha', 'verify you are human', 'g-recaptcha', 'hcaptcha'],
        'access_denied' => ['access denied', 'error code: 1020', 'request blocked'],
    ];

    foreach ($signals as $reason => $needles) {
        foreach ($needles as $needle) {
            if (strpos($html, $needle) !== false) {
                if (strpos($host, 'kunmanga') !== false) {
                    return 'kunmanga blocked the crawler with anti-bot protection.';
                }
                if (strpos($host, 'toongod') !== false) {
                    return 'toongod blocked the crawler with anti-bot protection.';
                }
                return 'The source site blocked automated access.';
            }
        }
    }

    return '';
}

function write_crawler_debug_log($url, $html, array $images) {
    $logPath = __DIR__ . '/debug_crawler.log';
    $snippet = trim(substr(preg_replace('/\s+/', ' ', strip_tags((string) $html)), 0, 800));
    $entry = '[' . date('Y-m-d H:i:s') . ']' . PHP_EOL
        . 'URL: ' . $url . PHP_EOL
        . 'Images found: ' . count($images) . PHP_EOL
        . 'Snippet: ' . $snippet . PHP_EOL
        . str_repeat('-', 80) . PHP_EOL;
    @file_put_contents($logPath, $entry, FILE_APPEND);
}

function write_image_download_debug_log($pageUrl, $imageUrl, $referer, $httpCode, $contentType, $note) {
    $logPath = __DIR__ . '/debug_image_downloads.log';
    $entry = '[' . date('Y-m-d H:i:s') . ']' . PHP_EOL
        . 'Page URL: ' . $pageUrl . PHP_EOL
        . 'Image URL: ' . $imageUrl . PHP_EOL
        . 'Referer: ' . $referer . PHP_EOL
        . 'HTTP: ' . $httpCode . PHP_EOL
        . 'Content-Type: ' . $contentType . PHP_EOL
        . 'Note: ' . $note . PHP_EOL
        . str_repeat('-', 80) . PHP_EOL;
    @file_put_contents($logPath, $entry, FILE_APPEND);
}

function build_image_download_strategy($url, $refererUrl = null) {
    $siteProfile = get_crawler_site_profile($refererUrl ?: $url);
    $refererCandidates = [
        $refererUrl,
        get_base_origin($refererUrl ?: ''),
        get_base_origin($url),
        $url,
    ];
    $connectTimeout = 15;
    $timeout = 60;

    if ($siteProfile === 'manhuaus') {
        $refererCandidates = [
            $refererUrl,
            get_base_origin($refererUrl ?: ''),
            'https://manhuaus.com/',
        ];
        $connectTimeout = 8;
        $timeout = 20;
    }

    return [
        'referers' => array_values(array_filter(array_unique($refererCandidates))),
        'connect_timeout' => $connectTimeout,
        'timeout' => $timeout,
    ];
}

function download_image($url, $savePath, $refererUrl = null, &$failureReason = null) {
    $failureReason = 'Image download failed.';
    $strategy = build_image_download_strategy($url, $refererUrl);
    $refererCandidates = $strategy['referers'];

    foreach ($refererCandidates as $referer) {
        $responseHeaders = [];
        $origin = get_base_origin($referer);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $strategy['connect_timeout'],
            CURLOPT_TIMEOUT => $strategy['timeout'],
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_AUTOREFERER => true,
            CURLOPT_REFERER => $referer,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => array_values(array_filter([
                'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'Sec-Fetch-Dest: image',
                'Sec-Fetch-Mode: no-cors',
                'Sec-Fetch-Site: cross-site',
                $origin !== '' ? 'Origin: ' . rtrim($origin, '/') : null,
            ])),
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$responseHeaders) {
                $length = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $length;
            },
        ]);

        $data = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$data || $http !== 200) {
            $failureReason = $http > 0 ? "Remote server returned HTTP $http." : 'Remote server returned an empty response.';
            write_image_download_debug_log($refererUrl ?: '', $url, $referer, $http, '', $failureReason);
            continue;
        }

        $contentType = strtolower($responseHeaders['content-type'] ?? '');
        if ($contentType !== '' && strpos($contentType, 'image/') !== 0) {
            $failureReason = "Remote server returned non-image content type: $contentType.";
            write_image_download_debug_log($refererUrl ?: '', $url, $referer, $http, $contentType, $failureReason);
            continue;
        }

        if (@getimagesizefromstring($data) === false) {
            $failureReason = 'Downloaded data is not a valid image file.';
            write_image_download_debug_log($refererUrl ?: '', $url, $referer, $http, $contentType, $failureReason);
            continue;
        }

        if (file_put_contents($savePath, $data) !== false) {
            return true;
        }

        $failureReason = 'Could not write the image file to disk.';
        write_image_download_debug_log($refererUrl ?: '', $url, $referer, $http, $contentType, $failureReason);
    }

    return false;
}

function extract_chapter_number($url) {
    if (preg_match('/chapter[-_ ]?([0-9]+(?:[-_.][0-9]+)?)/i', $url, $m)) {
        return str_replace(['-', '_'], '.', $m[1]);
    }

    if (preg_match('/prologue/i', $url)) {
        return '0';
    }

    return '1';
}

function build_chapter_title($chapterNumber) {
    $numeric = (float) $chapterNumber;
    if ((float) floor($numeric) === $numeric) {
        return 'Chapter ' . (int) $numeric;
    }

    return 'Chapter ' . rtrim(rtrim(number_format($numeric, 2, '.', ''), '0'), '.');
}

function normalize_page_sort_name($name) {
    $name = strtolower((string) $name);
    return preg_replace_callback('/\d+/', function ($matches) {
        return str_pad($matches[0], 10, '0', STR_PAD_LEFT);
    }, $name);
}

function get_crawler_site_profile($chapterUrl) {
    $host = strtolower((string) parse_url($chapterUrl, PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host);

    if (strpos($host, 'manhwaclan') !== false) {
        return 'manhwaclan';
    }

    if (strpos($host, 'kunmanga') !== false) {
        return 'kunmanga';
    }

    if (strpos($host, 'manhuaus') !== false) {
        return 'manhuaus';
    }

    if (strpos($host, 'toongod') !== false) {
        return 'toongod';
    }

    return 'generic';
}

function should_skip_crawler_image($src) {
    $src = strtolower((string) $src);
    if ($src === '') {
        return true;
    }

    $host = strtolower((string) parse_url($src, PHP_URL_HOST));
    $path = strtolower((string) parse_url($src, PHP_URL_PATH));

    foreach (['gravatar.com', 'secure.gravatar.com'] as $blockedHost) {
        if ($host !== '' && strpos($host, $blockedHost) !== false) {
            return true;
        }
    }

    foreach (['/avatar', '/avatars/', '/logo', '/logos/', '/icon', '/icons/', '/emoji', '/emojis/', '/banner', '/banners/', '/ads/', '/comment'] as $blockedPath) {
        if ($path !== '' && strpos($path, $blockedPath) !== false) {
            return true;
        }
    }

    return false;
}

function normalize_crawler_image_candidate($chapterUrl, $candidate) {
    $candidate = trim((string) $candidate);
    if ($candidate === '') {
        return '';
    }

    $candidate = str_replace(['\/', '\\u002F', '&#x2F;'], '/', $candidate);
    $candidate = html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $candidate = make_absolute_url($chapterUrl, $candidate);

    if (!filter_var($candidate, FILTER_VALIDATE_URL)) {
        return '';
    }

    $query = parse_url($candidate, PHP_URL_QUERY);
    if ($query) {
        parse_str($query, $queryParams);
        foreach (['url', 'src', 'image', 'img', 'media'] as $key) {
            if (empty($queryParams[$key])) {
                continue;
            }

            $decoded = rawurldecode((string) $queryParams[$key]);
            $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $decoded = str_replace(['\/', '\\u002F', '&#x2F;'], '/', $decoded);
            $decoded = make_absolute_url($chapterUrl, $decoded);

            if (filter_var($decoded, FILTER_VALIDATE_URL)) {
                return $decoded;
            }
        }
    }

    return $candidate;
}

function is_probable_crawler_image_url($src) {
    $src = trim((string) $src);
    if ($src === '') {
        return false;
    }

    $path = strtolower((string) parse_url($src, PHP_URL_PATH));
    $query = strtolower((string) parse_url($src, PHP_URL_QUERY));

    if (preg_match('/\.(jpg|jpeg|png|webp|gif|avif|jfif)$/i', $path)) {
        return true;
    }

    if (strpos($path, '/wp-content/uploads/') !== false) {
        return true;
    }

    if (strpos($path, '/uploads/') !== false && preg_match('/\d/', $path)) {
        return true;
    }

    if (strpos($path, '/storage/media/') !== false || strpos($path, '/storage/comics/') !== false) {
        return true;
    }

    if ((strpos($path, '/_next/image') !== false || strpos($path, '/cdn-cgi/image') !== false)
        && preg_match('/(?:^|[?&])(url|src|image|img|media)=.*(?:jpe?g|png|webp|gif|avif|jfif)/i', $query)
    ) {
        return true;
    }

    return false;
}

function is_relevant_crawler_image_for_chapter($chapterUrl, $src) {
    $siteProfile = get_crawler_site_profile($chapterUrl);
    $host = strtolower((string) parse_url($src, PHP_URL_HOST));
    $path = strtolower((string) parse_url($src, PHP_URL_PATH));

    if ($siteProfile === 'manhuaus') {
        if ($host !== '' && strpos($host, 'img.manhuaus.com') !== false) {
            return preg_match('~/image\d{4}/~', $path) === 1;
        }

        if ($host !== '' && strpos($host, 'manhuaus.com') === false) {
            return false;
        }

        return strpos($path, '/wp-content/uploads/wp-manga/data/') !== false;
    }

    return true;
}

function collect_chapter_images(DOMXPath $xpath, $chapterUrl, $html = '') {
    $siteProfile = get_crawler_site_profile($chapterUrl);
    $queries = [];

    if ($siteProfile === 'manhwaclan') {
        $queries = [
            "//img[contains(@src, 'clancd.com') or contains(@data-src, 'clancd.com') or contains(@data-lazy-src, 'clancd.com')]",
            "//div[contains(@class,'reading-content')]//img",
            "//div[contains(@class,'text-left')]//img",
            "//div[contains(@class,'entry-content')]//img",
            "//figure//img",
            "//img[@data-src or @data-lazy-src or @data-original or @data-cfsrc or @src]",
        ];
    } elseif ($siteProfile === 'kunmanga') {
        $queries = [
            "//img[contains(@class,'wp-manga-chapter-img')]",
            "//div[contains(@class,'reading-content')]//img",
            "//div[contains(@class,'page-break')]//img",
            "//div[contains(@class,'text-left')]//img",
            "//div[contains(@class,'chapter-content')]//img",
            "//figure//img",
            "//img[@data-src or @data-lazy-src or @data-original or @data-cfsrc or @src]",
        ];
    } elseif ($siteProfile === 'manhuaus') {
        $queries = [
            "//img[contains(@class,'wp-manga-chapter-img')]",
            "//div[contains(@class,'reading-content')]//img[contains(@data-src, '/wp-content/uploads/WP-manga/data/') or contains(@src, '/wp-content/uploads/WP-manga/data/')]",
            "//div[contains(@class,'reading-content')]//img",
            "//div[contains(@class,'page-break')]//img",
            "//figure//img",
            "//img[contains(@data-src, '/wp-content/uploads/WP-manga/data/') or contains(@src, '/wp-content/uploads/WP-manga/data/')]",
            "//img[@data-src or @data-lazy-src or @data-original or @data-cfsrc or @src]",
        ];
    } elseif ($siteProfile === 'toongod') {
        $queries = [
            "//img[contains(@class,'wp-manga-chapter-img')]",
            "//div[contains(@class,'reading-content')]//img",
            "//div[contains(@class,'chapter-content')]//img",
            "//div[contains(@class,'entry-content')]//img",
            "//div[contains(@class,'page-break')]//img",
            "//div[contains(@class,'page-content-listing')]//img",
            "//div[contains(@class,'reading-area')]//img",
            "//figure//img",
            "//main//img[@data-src or @data-lazy-src or @data-original or @data-cfsrc or @src]",
            "//img[@data-src or @data-lazy-src or @data-original or @data-cfsrc or @src]",
        ];
    } else {
        $queries = [
            "//div[contains(@class,'reading-content')]//img",
            "//div[contains(@class,'chapter-content')]//img",
            "//div[contains(@class,'entry-content')]//img",
            "//div[contains(@class,'page-break')]//img",
            "//figure//img",
            "//main//img",
            "//img[@data-src or @data-lazy-src or @data-original or @data-cfsrc or @src]",
        ];
    }

    $images = [];

    foreach ($queries as $query) {
        $nodes = $xpath->query($query);
        if (!$nodes || $nodes->length === 0) {
            continue;
        }

        foreach ($nodes as $node) {
            if (!($node instanceof DOMElement)) continue;

            $src = $node->getAttribute('data-src')
                ?: $node->getAttribute('data-lazy-src')
                ?: $node->getAttribute('data-original')
                ?: $node->getAttribute('data-cfsrc')
                ?: $node->getAttribute('src');

            if (!$src) {
                $srcset = $node->getAttribute('data-srcset') ?: $node->getAttribute('srcset');
                if ($srcset) {
                    $firstSrcset = trim(explode(',', $srcset)[0]);
                    $src = trim(explode(' ', $firstSrcset)[0]);
                }
            }

            if (!$src) continue;

            $src = trim(preg_replace('/\s+/', '', $src));
            $src = normalize_crawler_image_candidate($chapterUrl, $src);
            if (!filter_var($src, FILTER_VALIDATE_URL)) continue;
            if (should_skip_crawler_image($src)) continue;
            if (!is_probable_crawler_image_url($src)) continue;
            if (!is_relevant_crawler_image_for_chapter($chapterUrl, $src)) continue;

            $images[] = $src;
        }

    }

    $images = array_values(array_unique($images));

    if (!empty($images) || trim((string) $html) === '') {
        return $images;
    }

    $rawHtml = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $fallbackMatches = [];

    if (preg_match_all('~(?:src|data-src|data-lazy-src|data-original|data-cfsrc|data-srcset)\s*=\s*["\']([^"\']+)["\']~i', $rawHtml, $attrMatches)) {
        foreach ($attrMatches[1] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            if (stripos($candidate, 'srcset') !== false || strpos($candidate, ',') !== false) {
                $candidate = trim(explode(',', $candidate)[0]);
                $candidate = trim(explode(' ', $candidate)[0]);
            }

            $fallbackMatches[] = $candidate;
        }
    }

    if (preg_match_all('~https?://[^"\'\s<>]+?\.(?:jpe?g|png|webp|gif)(?:\?[^"\'\s<>]*)?~i', $rawHtml, $absoluteMatches)) {
        $fallbackMatches = array_merge($fallbackMatches, $absoluteMatches[0]);
    }

    if (preg_match_all('~https?://[^"\'\s<>]+?(?:/_next/image|/cdn-cgi/image)[^"\'\s<>]+~i', $rawHtml, $optimizedMatches)) {
        $fallbackMatches = array_merge($fallbackMatches, $optimizedMatches[0]);
    }

    if ($siteProfile === 'manhwaclan' && preg_match_all('~https?://[^"\'\s<>]*clancd\.com/[^"\'\s<>]+?\.(?:jpe?g|png|webp|gif)~i', $rawHtml, $clanMatches)) {
        $fallbackMatches = array_merge($fallbackMatches, $clanMatches[0]);
    }

    if (preg_match_all('~(?:/wp-content/uploads/[^"\'\s<>]+?\.(?:jpe?g|png|webp|gif)(?:\?[^"\'\s<>]*)?)~i', $rawHtml, $uploadMatches)) {
        $fallbackMatches = array_merge($fallbackMatches, $uploadMatches[0]);
    }

    if ($siteProfile === 'manhuaus' && preg_match_all('~https?://[^"\'\s<>]*manhuaus\.com/wp-content/uploads/WP-manga/data/[^"\'\s<>]+?\.(?:jpe?g|png|webp|gif)~i', $rawHtml, $manhuausMatches)) {
        $fallbackMatches = array_merge($fallbackMatches, $manhuausMatches[0]);
    }

    if (preg_match_all('~https?://[^"\'\s<>]+?/storage/(?:media|comics)/[^"\'\s<>]+~i', $rawHtml, $storageMatches)) {
        $fallbackMatches = array_merge($fallbackMatches, $storageMatches[0]);
    }

    if ($siteProfile === 'toongod' && preg_match_all('~https?://[^"\'\s<>]*toongod[^"\'\s<>]+(?:image|chapter|wp-content|uploads)[^"\'\s<>]*~i', $rawHtml, $toongodMatches)) {
        $fallbackMatches = array_merge($fallbackMatches, $toongodMatches[0]);
    }

    foreach ($fallbackMatches as $candidate) {
        $candidate = normalize_crawler_image_candidate($chapterUrl, $candidate);
        if ($candidate === '') {
            continue;
        }

        if (!filter_var($candidate, FILTER_VALIDATE_URL)) {
            continue;
        }
        if (should_skip_crawler_image($candidate)) {
            continue;
        }
        if (!is_probable_crawler_image_url($candidate)) {
            continue;
        }
        if (!is_relevant_crawler_image_for_chapter($chapterUrl, $candidate)) {
            continue;
        }

        $images[] = $candidate;
    }

    return array_values(array_unique($images));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $series_id = isset($_POST['series_id']) ? intval($_POST['series_id']) : 0;
    $chapter_number_input = trim($_POST['chapter_number'] ?? '');
    $chapter_number = $chapter_number_input !== '' ? (float) $chapter_number_input : 0;
    $chapter_title = isset($_POST['chapter_title']) ? trim($_POST['chapter_title']) : '';
    $chapter_url = trim($_POST['chapter_url'] ?? '');
    $has_zip_upload = isset($_FILES['chapter_zip']) && (($_FILES['chapter_zip']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
    $has_image_upload = isset($_FILES['chapter_pages']) && !empty($_FILES['chapter_pages']['name'][0]);
    $selected_series_id = (string) $series_id;
    $prefill_chapter_url = $chapter_url;
    $prefill_chapter_number = $chapter_number_input;
    $prefill_chapter_title = $chapter_title;

    if ($series_id === 0) {
        $message = "Please select a valid series.";
        $message_type = "error";
    } elseif ($chapter_url === '' && $chapter_number <= 0) {
        $message = "Please enter a valid chapter number.";
        $message_type = "error";
    } elseif ($chapter_url === '' && !$has_image_upload && !$has_zip_upload) {
        $message = "Please paste a chapter URL, upload chapter images, or upload a ZIP file.";
        $message_type = "error";
    } elseif ($chapter_url !== '') {
        try {
            $fetchError = null;
            $html = fetch_remote_html($chapter_url, $fetchError);

            if (!$html) {
                $message = "Failed to load chapter page" . ($fetchError ? ": $fetchError" : '');
                $message_type = "error";
                goto output;
            }

            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $dom->loadHTML($html);
            libxml_clear_errors();
            $xpath = new DOMXPath($dom);

            $images = collect_chapter_images($xpath, $chapter_url, $html);
            if (!$images) {
                $blockReason = detect_crawler_block_reason($html, $chapter_url);
                write_crawler_debug_log($chapter_url, $html, $images);
                $message = $blockReason !== '' ? $blockReason : "No images found for that chapter URL.";
                $message_type = "error";
                goto output;
            }

            if ($chapter_number_input === '') {
                $chapter_number_input = extract_chapter_number($chapter_url);
            }
            $chapter_number = (float) $chapter_number_input;

            if ($chapter_number <= 0 && $chapter_number_input !== '0') {
                $message = "Could not determine a valid chapter number from the URL.";
                $message_type = "error";
                goto output;
            }

            if ($chapter_title === '') {
                $chapter_title = build_chapter_title($chapter_number_input);
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT id FROM chapters WHERE series_id = ? AND chapter_number = ?");
            $stmt->execute([$series_id, $chapter_number_input]);
            $existing_chapter_id = $stmt->fetchColumn();

            if ($existing_chapter_id) {
                $chapter_id = $existing_chapter_id;
                $stmt = $pdo->prepare("DELETE FROM pages WHERE chapter_id = ?");
                $stmt->execute([$chapter_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO chapters (series_id, chapter_number, title, release_date) VALUES (?, ?, ?, CURDATE())");
                $stmt->execute([$series_id, $chapter_number_input, $chapter_title]);
                $chapter_id = $pdo->lastInsertId();
            }

            if (!$existing_chapter_id) {
                $stmt = $pdo->prepare("SELECT id FROM chapters WHERE series_id = ? AND chapter_number = ?");
                $stmt->execute([$series_id, $chapter_number_input]);
                $chapter_id = $stmt->fetchColumn();
            }

            $upload_dir = __DIR__ . "/../uploads/series/$series_id/chapter/$chapter_id/";
            $public_dir = "uploads/series/$series_id/chapter/$chapter_id/";
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $stmt = $pdo->prepare("INSERT INTO pages (chapter_id, page_number, image_url) VALUES (?, ?, ?)");
            $uploaded_count = 0;

            foreach ($images as $index => $image_url) {
                $ext = pathinfo(parse_url($image_url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
                $filename = 'page-' . ($index + 1) . '.' . $ext;

                $downloadError = '';
                if (!download_image($image_url, $upload_dir . $filename, $chapter_url, $downloadError)) {
                    throw new Exception("Failed to download page " . ($index + 1) . ($downloadError !== '' ? ": " . $downloadError : '.'));
                }

                $stmt->execute([$chapter_id, $index + 1, $public_dir . $filename]);
                $uploaded_count++;
            }

            $pdo->commit();
            $message = "Successfully uploaded $uploaded_count pages.";
            $message_type = "success";
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Error importing single chapter via URL: " . $e->getMessage());
            $message = "Chapter import failed. Please try again.";
            $message_type = "error";
        }
    } elseif ($has_zip_upload) {
        if (!class_exists('ZipArchive')) {
            $message = "ZIP upload requires the PHP Zip extension. Enable php_zip in XAMPP first.";
            $message_type = "error";
            goto output;
        }

        try {
            $zip_error = (int) ($_FILES['chapter_zip']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($zip_error !== UPLOAD_ERR_OK) {
                throw new Exception("The ZIP file could not be uploaded.");
            }

            $zip_name = (string) ($_FILES['chapter_zip']['name'] ?? '');
            if (strtolower(pathinfo($zip_name, PATHINFO_EXTENSION)) !== 'zip') {
                throw new Exception("Please upload a valid .zip file.");
            }

            if ($chapter_title === '') {
                $chapter_title = build_chapter_title($chapter_number_input);
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT id FROM chapters WHERE series_id = ? AND chapter_number = ?");
            $stmt->execute([$series_id, $chapter_number_input]);
            $existing_chapter_id = $stmt->fetchColumn();

            if ($existing_chapter_id) {
                $chapter_id = $existing_chapter_id;
                $stmt = $pdo->prepare("DELETE FROM pages WHERE chapter_id = ?");
                $stmt->execute([$chapter_id]);
                $message = "Existing chapter updated. ";
            } else {
                $stmt = $pdo->prepare("INSERT INTO chapters (series_id, chapter_number, title, release_date) VALUES (?, ?, ?, CURDATE())");
                $stmt->execute([$series_id, $chapter_number_input, $chapter_title]);
                $chapter_id = $pdo->lastInsertId();
                $message = "New chapter added. ";
            }

            if (!$existing_chapter_id) {
                $stmt = $pdo->prepare("SELECT id FROM chapters WHERE series_id = ? AND chapter_number = ?");
                $stmt->execute([$series_id, $chapter_number_input]);
                $chapter_id = $stmt->fetchColumn();
            }

            $upload_dir = __DIR__ . "/../uploads/series/$series_id/chapter/$chapter_id/";
            $public_dir = "uploads/series/$series_id/chapter/$chapter_id/";
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $zip = new ZipArchive();
            if ($zip->open($_FILES['chapter_zip']['tmp_name']) !== true) {
                throw new Exception("Could not open the ZIP archive.");
            }

            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!$stat || !isset($stat['name'])) {
                    continue;
                }

                $entry_name = (string) $stat['name'];
                if (substr($entry_name, -1) === '/') {
                    continue;
                }

                $ext = strtolower(pathinfo($entry_name, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                    continue;
                }

                $entries[] = [
                    'index' => $i,
                    'name' => $entry_name,
                    'sort' => normalize_page_sort_name(basename($entry_name)),
                    'ext' => $ext,
                ];
            }

            if (empty($entries)) {
                $zip->close();
                throw new Exception("No image files were found inside the ZIP.");
            }

            usort($entries, function ($a, $b) {
                return strcmp($a['sort'], $b['sort']);
            });

            $stmt = $pdo->prepare("INSERT INTO pages (chapter_id, page_number, image_url) VALUES (?, ?, ?)");
            $uploaded_count = 0;

            foreach ($entries as $page_number => $entry) {
                $stream = $zip->getStream($entry['name']);
                if (!$stream) {
                    continue;
                }

                $contents = stream_get_contents($stream);
                fclose($stream);

                if ($contents === false || @getimagesizefromstring($contents) === false) {
                    continue;
                }

                $filename = 'page-' . ($page_number + 1) . '.' . $entry['ext'];
                if (file_put_contents($upload_dir . $filename, $contents) === false) {
                    continue;
                }

                $stmt->execute([$chapter_id, $page_number + 1, $public_dir . $filename]);
                $uploaded_count++;
            }

            $zip->close();

            if ($uploaded_count === 0) {
                throw new Exception("No valid image pages could be extracted from the ZIP.");
            }

            $pdo->commit();
            $message .= "Successfully uploaded $uploaded_count pages from ZIP.";
            $message_type = "success";
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Error uploading chapter ZIP: " . $e->getMessage());
            $message = $e->getMessage();
            $message_type = "error";
        }
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT id FROM chapters WHERE series_id = ? AND chapter_number = ?");
            $stmt->execute([$series_id, $chapter_number]);
            $existing_chapter_id = $stmt->fetchColumn();

            $chapter_id = null;
            if ($existing_chapter_id) {
                $chapter_id = $existing_chapter_id;
                $stmt = $pdo->prepare("DELETE FROM pages WHERE chapter_id = ?");
                $stmt->execute([$chapter_id]);
                $message = "Existing chapter updated. ";
            } else {
                $stmt = $pdo->prepare("INSERT INTO chapters (series_id, chapter_number, title, release_date) VALUES (?, ?, ?, CURDATE())");
                $stmt->execute([$series_id, $chapter_number, $chapter_title]);
                $chapter_id = $pdo->lastInsertId();
                $message = "New chapter added. ";
            }

            $uploaded_count = 0;
            $failed_uploads = [];

            $upload_dir = '../uploads/series_' . $series_id . '/chapter_' . $chapter_number . '/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            foreach ($_FILES['chapter_pages']['name'] as $key => $filename) {
                $file_tmp_name = $_FILES['chapter_pages']['tmp_name'][$key];
                $file_size = $_FILES['chapter_pages']['size'][$key];
                $file_error = $_FILES['chapter_pages']['error'][$key];
                $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $max_file_size = 10 * 1024 * 1024;

                if ($file_error !== UPLOAD_ERR_OK || !in_array($file_ext, $allowed_extensions) || $file_size > $max_file_size) {
                    $failed_uploads[] = $filename;
                    continue;
                }

                $new_file_name = uniqid('page_', true) . '.' . $file_ext;
                $upload_path = $upload_dir . $new_file_name;
                $image_url_db = 'uploads/series_' . $series_id . '/chapter_' . $chapter_number . '/' . $new_file_name;

                if (move_uploaded_file($file_tmp_name, $upload_path)) {
                    $stmt = $pdo->prepare("INSERT INTO pages (chapter_id, page_number, image_url) VALUES (?, ?, ?)");
                    $stmt->execute([$chapter_id, $key + 1, $image_url_db]);
                    $uploaded_count++;
                } else {
                    $failed_uploads[] = $filename;
                }
            }

            $pdo->commit();
            $message .= "Successfully uploaded $uploaded_count pages.";
            $message_type = "success";

            if (!empty($failed_uploads)) {
                $message .= " Some files failed to upload: " . implode(", ", $failed_uploads);
                $message_type = "error";
            }

        } catch (\PDOException $e) {
            $pdo->rollBack();
            error_log("Error uploading chapter pages: " . $e->getMessage());
            $message = "Database or file system error. Please try again.";
            $message_type = "error";
        }
    }
}

output:
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Chapter Pages - Admin</title>
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
            max-width: 1180px;
            padding-top: 42px;
            padding-bottom: 42px;
        }
        .upload-shell {
            max-width: 820px;
            margin: 0 auto;
        }
        .form-container {
            border-radius: 30px;
            background: var(--panel-bg);
            border: 1px solid var(--panel-border);
            box-shadow: 0 24px 70px rgba(0, 0, 0, 0.36);
            backdrop-filter: blur(18px);
            padding: 28px;
            position: relative;
            overflow: hidden;
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
        #headr {
            text-align: left;
            font-size: clamp(2rem, 3vw, 2.8rem);
            font-weight: 900;
            margin: 0;
            line-height: 1;
            letter-spacing: -0.05em;
        }
        .upload-form {
            display: grid;
            gap: 18px;
        }
        .field-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
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
        .form-container select {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            background: var(--field-bg);
            color: #fff;
            box-sizing: border-box;
            outline: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
        }
        .form-container input:focus,
        .form-container select:focus {
            border-color: rgba(88, 184, 255, 0.76);
            box-shadow: 0 0 0 4px rgba(31, 143, 255, 0.14);
            transform: translateY(-1px);
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
        .file-wrap {
            display: grid;
            gap: 10px;
        }
        .form-container input[type="file"] {
            padding: 12px;
        }
        .form-container button {
            min-height: 52px;
            padding: 0 22px;
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
                padding: 20px;
                border-radius: 24px;
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

            <form action="upload_chapter_pages.php" method="POST" enctype="multipart/form-data">
                <div class="form-head">
                    <span class="form-icon"><i class="fa-solid fa-images"></i></span>
                    <label for="" id="headr">Upload Chapter Pages</label>
                </div>
                <div class="upload-form">
                    <div class="field-grid">
                        <div class="field-wide">
                            <label for="series_id">Series</label>
                            <select id="series_id" name="series_id" required>
                                <option value="">Select Series</option>
                                <?php foreach ($series_list as $series_item): ?>
                                    <option value="<?php echo htmlspecialchars($series_item['id']); ?>"<?php echo $selected_series_id === (string) $series_item['id'] ? ' selected' : ''; ?>>
                                        <?php echo htmlspecialchars($series_item['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field-wide">
                            <label for="chapter_url">Chapter URL</label>
                            <input type="url" id="chapter_url" name="chapter_url" placeholder="https://example.com/chapter-101" value="<?php echo htmlspecialchars($prefill_chapter_url); ?>">
                        </div>

                        <div class="field">
                            <label for="chapter_number">Chapter Number</label>
                            <input type="number" step="0.01" id="chapter_number" name="chapter_number" value="<?php echo htmlspecialchars($prefill_chapter_number); ?>">
                        </div>

                        <div class="field">
                            <label for="chapter_title">Chapter Title</label>
                            <input type="text" id="chapter_title" name="chapter_title" value="<?php echo htmlspecialchars($prefill_chapter_title); ?>">
                        </div>

                        <div class="field-wide">
                            <label for="chapter_pages">Chapter Pages</label>
                            <div class="file-wrap">
                                <input type="file" id="chapter_pages" name="chapter_pages[]" accept="image/*" multiple>
                            </div>
                        </div>

                        <div class="field-wide">
                            <label for="chapter_zip">Chapter ZIP</label>
                            <div class="file-wrap">
                                <input type="file" id="chapter_zip" name="chapter_zip" accept=".zip,application/zip">
                            </div>
                        </div>
                    </div>

                    <button type="submit">Upload Chapter Pages</button>
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
