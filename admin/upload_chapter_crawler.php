<?php
set_time_limit(0);
ini_set('max_execution_time', '0');
error_reporting(E_ALL);

require_once '../includes/db_connect.php';

/* ================= CONFIG ================= */
$debug = false;

/* ================= FETCH SERIES ================= */
$seriesList = $pdo->query("
    SELECT id, title 
    FROM series 
    ORDER BY title ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* ================= HELPERS ================= */

function make_absolute_url($baseUrl, $url) {
    $url = trim($url);
    if ($url === '') return '';
    if (parse_url($url, PHP_URL_SCHEME)) return $url;

    $base = parse_url($baseUrl);
    $scheme = $base['scheme'];
    $host   = $base['host'];

    if (strpos($url, '/') === 0) {
        return "$scheme://$host$url";
    }

    $path = rtrim(dirname($base['path']), '/') . '/';
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
        'site_profile' => $siteProfile,
    ];
}

function build_image_url_candidates($url, $siteProfile) {
    $candidates = [$url];
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));

    if ($siteProfile === 'manhuaus' && strpos($host, 'img.manhuaus.com') !== false) {
        if (stripos($url, 'https://') === 0) {
            $candidates[] = 'http://' . substr($url, 8);
        } elseif (stripos($url, 'http://') === 0) {
            $candidates[] = 'https://' . substr($url, 7);
        }
    }

    return array_values(array_unique(array_filter($candidates)));
}

function download_image($url, $savePath, $refererUrl = null, &$failureReason = null) {
    $failureReason = 'Image download failed.';
    $strategy = build_image_download_strategy($url, $refererUrl);
    $refererCandidates = $strategy['referers'];
    $urlCandidates = build_image_url_candidates($url, $strategy['site_profile']);

    foreach ($urlCandidates as $candidateUrl) {
        foreach ($refererCandidates as $referer) {
            $attempts = $strategy['site_profile'] === 'manhuaus' ? 3 : 2;

            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                $responseHeaders = [];
                $origin = get_base_origin($referer);

                $ch = curl_init($candidateUrl);
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
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
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
                $curlError = curl_error($ch);
                $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if (!$data || $http !== 200) {
                    $failureReason = $curlError !== ''
                        ? "Request error: $curlError"
                        : ($http > 0 ? "Remote server returned HTTP $http." : 'Remote server returned an empty response.');
                    write_image_download_debug_log($refererUrl ?: '', $candidateUrl, $referer, $http, '', $failureReason . " Attempt $attempt/$attempts.");

                    if ($attempt < $attempts && ($http === 0 || $http >= 500)) {
                        usleep(350000 * $attempt);
                        continue;
                    }
                    break;
                }

                $contentType = strtolower($responseHeaders['content-type'] ?? '');
                if ($contentType !== '' && strpos($contentType, 'image/') !== 0) {
                    $failureReason = "Remote server returned non-image content type: $contentType.";
                    write_image_download_debug_log($refererUrl ?: '', $candidateUrl, $referer, $http, $contentType, $failureReason);
                    break;
                }

                if (@getimagesizefromstring($data) === false) {
                    $failureReason = 'Downloaded data is not a valid image file.';
                    write_image_download_debug_log($refererUrl ?: '', $candidateUrl, $referer, $http, $contentType, $failureReason);
                    break;
                }

                if (file_put_contents($savePath, $data) !== false) {
                    return true;
                }

                $failureReason = 'Could not write the image file to disk.';
                write_image_download_debug_log($refererUrl ?: '', $candidateUrl, $referer, $http, $contentType, $failureReason);
                break;
            }
        }
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

function infer_next_chapter_url($chapterUrl) {
    if (!preg_match('/chapter[-_ ]?([0-9]+(?:[._][0-9]+)?)/i', $chapterUrl, $matches)) {
        return null;
    }

    $currentToken = $matches[1];
    $normalized = str_replace('_', '.', $currentToken);
    $nextNumber = (float) $normalized + 1;

    if (floor($nextNumber) == $nextNumber) {
        $nextToken = (string) (int) $nextNumber;
    } else {
        $nextToken = rtrim(rtrim(number_format($nextNumber, 2, '.', ''), '0'), '.');
    }

    $replacement = str_replace($currentToken, $nextToken, $matches[0]);
    return preg_replace('/chapter[-_ ]?[0-9]+(?:[._][0-9]+)?/i', $replacement, $chapterUrl, 1);
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

    if (strpos($host, 'hivetoons') !== false) {
        return 'hivetoons';
    }

    if (strpos($host, 'toongod') !== false) {
        return 'toongod';
    }

    return 'generic';
}

function should_stop_after_single_chapter($chapterUrl) {
    return false;
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

function find_next_chapter_url(DOMXPath $xpath, $chapterUrl) {
    $siteProfile = get_crawler_site_profile($chapterUrl);
    $queries = [];

    if ($siteProfile === 'manhwaclan') {
        $queries = [
            "//a[normalize-space()='Next']",
            "//a[contains(@href, '/chapter-') and contains(translate(normalize-space(string(.)), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'next')]",
            "//link[@rel='next']",
        ];
    } elseif ($siteProfile === 'kunmanga') {
        $queries = [
            "//div[contains(@class,'nav-next')]//a",
            "//a[contains(@class,'btn-next-chap')]",
            "//li[contains(@class,'next')]//a",
            "//a[contains(@class,'next_page')]",
            "//a[contains(@class,'next') and contains(@href, 'chapter')]",
            "//link[@rel='next']",
        ];
    } elseif ($siteProfile === 'manhuaus') {
        $queries = [
            "//div[contains(@class,'nav-next')]//a",
            "//li[contains(@class,'next')]//a",
            "//a[contains(@class,'next_page')]",
            "//a[contains(@class,'btn-next-chap')]",
            "//a[contains(@href, '/chapter-') and contains(translate(normalize-space(string(.)), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'next')]",
            "//a[contains(@href, '/chapter/') and contains(translate(normalize-space(string(.)), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'next')]",
            "//a[contains(@rel, 'next')]",
            "//link[@rel='next']",
        ];
    } elseif ($siteProfile === 'toongod') {
        $queries = [
            "//div[contains(@class,'nav-next')]//a",
            "//li[contains(@class,'next')]//a",
            "//a[contains(@class,'next_page')]",
            "//a[contains(@class,'btn-next-chap')]",
            "//a[contains(@href, '/chapter-') and contains(translate(normalize-space(string(.)), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'next')]",
            "//a[contains(@href, '/chapter/') and contains(translate(normalize-space(string(.)), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'next')]",
            "//a[contains(@rel, 'next')]",
            "//link[@rel='next']",
        ];
    } else {
        $queries = [
            "//a[contains(@class,'next_page')]",
            "//a[contains(@class,'next') and contains(@href, 'chapter')]",
            "//a[contains(translate(normalize-space(string(.)), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'next')]",
            "//link[@rel='next']",
        ];
    }

    foreach ($queries as $query) {
        $nodes = $xpath->query($query);
        if (!$nodes || $nodes->length === 0) {
            continue;
        }

        foreach ($nodes as $node) {
            if (!($node instanceof DOMElement)) continue;

            $href = $node->getAttribute('href');
            if (!$href) continue;

            $nextUrl = make_absolute_url($chapterUrl, $href);
            if (!filter_var($nextUrl, FILTER_VALIDATE_URL)) continue;
            if ($nextUrl === $chapterUrl) continue;

            return $nextUrl;
        }
    }

    return infer_next_chapter_url($chapterUrl);
}

/* ================= POST HANDLER ================= */
$message = '';
$message_type = '';
$selected_series_id = '';
$submitted_chapter_url = '';
$manual_upload_url = '';
$manual_upload_note = '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['series_id'])) {
    $prefill_series_id = (int) $_GET['series_id'];
    if ($prefill_series_id > 0) {
        foreach ($seriesList as $series) {
            if ((int) $series['id'] === $prefill_series_id) {
                $selected_series_id = (string) $prefill_series_id;
                break;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $series_id = (int)$_POST['series_id'];
    $chapter_url = trim($_POST['chapter_url']);
    $selected_series_id = (string)$series_id;
    $submitted_chapter_url = $chapter_url;

    if (!$series_id || !$chapter_url) {
        $message = "Invalid input";
        $message_type = "error";
        goto output;
    }

    /* ===== Validate series ===== */
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM series WHERE id=?");
    $stmt->execute([$series_id]);
    if (!$stmt->fetchColumn()) {
        $message = "Series does not exist";
        $message_type = "error";
        goto output;
    }

    $currentUrl = $chapter_url;
    $visitedUrls = [];
    $totalChaptersAdded = 0;
    $maxChaptersPerRun = 500;

    while ($currentUrl && $totalChaptersAdded < $maxChaptersPerRun) {
        if (isset($visitedUrls[$currentUrl])) {
            break;
        }
        $visitedUrls[$currentUrl] = true;

        /* ===== Fetch HTML ===== */
        $fetchError = null;
        $html = fetch_remote_html($currentUrl, $fetchError);

        if (!$html) {
            if ($totalChaptersAdded > 0) {
                $message = "Imported $totalChaptersAdded chapter(s), then stopped at $currentUrl" . ($fetchError ? ": $fetchError" : '');
                $message_type = "success";
            } else {
                $message = "Failed to load chapter page" . ($fetchError ? ": $fetchError" : '');
                $message_type = "error";
            }
            goto output;
        }

        /* ===== Parse HTML ===== */
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $images = collect_chapter_images($xpath, $currentUrl, $html);
        if (!$images) {
            $blockReason = detect_crawler_block_reason($html, $currentUrl);
            write_crawler_debug_log($currentUrl, $html, $images);
            $manual_upload_url = 'upload_chapter_pages.php?series_id=' . urlencode((string) $series_id)
                . '&chapter_url=' . urlencode($currentUrl)
                . '&chapter_number=' . urlencode((string) extract_chapter_number($currentUrl));
            $manual_upload_note = 'Use manual upload instead. You can upload page images or a ZIP for this chapter.';
            if ($totalChaptersAdded > 0) {
                $message = "Imported $totalChaptersAdded chapter(s), then stopped because no images were found at $currentUrl";
                if ($blockReason !== '') {
                    $message .= " ($blockReason)";
                }
                $message_type = "success";
            } else {
                $message = $blockReason !== '' ? $blockReason : "No images found (site structure changed)";
                $message_type = "error";
            }
            goto output;
        }

        /* ===== Chapter Info ===== */
        $chapter_number = extract_chapter_number($currentUrl);
        $chapter_title  = build_chapter_title($chapter_number);

        /* ===== DB Transaction ===== */
        try {
            $pdo->beginTransaction();

            $pdo->prepare("
                INSERT INTO chapters (series_id, chapter_number, title, release_date)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE title=VALUES(title)
            ")->execute([$series_id, $chapter_number, $chapter_title]);

            $stmt = $pdo->prepare("
                SELECT id FROM chapters WHERE series_id=? AND chapter_number=?
            ");
            $stmt->execute([$series_id, $chapter_number]);
            $chapter_id = $stmt->fetchColumn();

            if (!$chapter_id) {
                throw new Exception("Failed to resolve chapter ID");
            }

            $uploadDir = __DIR__."/../uploads/series/$series_id/chapter/$chapter_id/";
            $publicDir = "uploads/series/$series_id/chapter/$chapter_id/";

            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            $pdo->prepare("DELETE FROM pages WHERE chapter_id=?")->execute([$chapter_id]);

            $stmt = $pdo->prepare("
                INSERT INTO pages (chapter_id, page_number, image_url)
                VALUES (?, ?, ?)
            ");

            $page = 1;
            foreach ($images as $img) {
                $ext = pathinfo(parse_url($img, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
                $filename = "page-$page.$ext";

                $downloadError = '';
                if (!download_image($img, $uploadDir.$filename, $currentUrl, $downloadError)) {
                    $manual_upload_url = 'upload_chapter_pages.php?series_id=' . urlencode((string) $series_id)
                        . '&chapter_url=' . urlencode($currentUrl)
                        . '&chapter_number=' . urlencode((string) $chapter_number)
                        . '&chapter_title=' . urlencode($chapter_title);
                    $manual_upload_note = 'Automatic image download was blocked by the source host. You can use manual upload for this chapter.';
                    throw new Exception("Failed to download page $page for $currentUrl" . ($downloadError !== '' ? ": $downloadError" : ''));
                }

                $stmt->execute([$chapter_id, $page, $publicDir.$filename]);
                $page++;
            }

            $pdo->commit();
            $totalChaptersAdded++;

            if (should_stop_after_single_chapter($currentUrl)) {
                break;
            }

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($totalChaptersAdded > 0) {
                $message = "Imported $totalChaptersAdded chapter(s), then stopped: " . $e->getMessage();
                $message_type = "success";
            } else {
                $message = $e->getMessage();
                $message_type = "error";
            }
            goto output;
        }

        $nextUrl = find_next_chapter_url($xpath, $currentUrl);
        if (!$nextUrl || isset($visitedUrls[$nextUrl])) {
            break;
        }

        $currentUrl = $nextUrl;
    }

    if ($totalChaptersAdded > 0) {
        $message = "Imported/updated $totalChaptersAdded chapter(s) successfully.";
        $message_type = "success";
    } else {
        $message = "No chapters were imported. The URL may be invalid or the page structure may have changed.";
        $message_type = "error";
    }
}

output:
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Chapter via Crawler - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        :root {
            --panel-bg: rgba(10, 12, 18, 0.82);
            --panel-border: rgba(255, 255, 255, 0.12);
            --panel-shadow: 0 24px 70px rgba(0, 0, 0, 0.45);
            --text-main: #f6f8fb;
            --text-muted: rgba(230, 236, 245, 0.72);
            --accent: #49d17d;
            --accent-strong: #2bb463;
            --accent-soft: rgba(73, 209, 125, 0.16);
            --field-bg: rgba(255, 255, 255, 0.1);
            --field-border: rgba(255, 255, 255, 0.14);
        }
        body {
            background-image: url('../assets/bg3.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            position: relative;
            color: var(--text-main);
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
            margin-left: 0;
        }
        header h1 {
            margin: 0;
            letter-spacing: 0.02em;
        }
        header nav {
            margin-left: auto;
            margin-right: 0;
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
            position: relative;
        }
        header nav a::after {
            content: "";
            position: absolute;
            left: 0;
            bottom: -6px;
            width: 100%;
            height: 2px;
            transform: scaleX(0);
            transform-origin: center;
            background: linear-gradient(90deg, transparent, var(--accent), transparent);
            transition: transform 0.2s ease;
        }
        header nav a:hover::after {
            transform: scaleX(1);
        }
        .page-shell {
            max-width: 1120px;
            margin: 48px auto;
            padding: 0 20px 32px;
        }
        .form-container {
            background: var(--panel-bg);
            border: 1px solid var(--panel-border);
            box-shadow: var(--panel-shadow);
            backdrop-filter: blur(18px);
        }
        .form-container {
            max-width: 1120px;
            margin: 0 auto;
            border-radius: 30px;
            padding: 30px;
            position: relative;
        }
        .form-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 22px;
        }
        .form-header h2 {
            margin: 0;
            font-size: 1.65rem;
            color: #ffffff;
        }
        .header-badge {
            flex-shrink: 0;
            padding: 10px 14px;
            border-radius: 999px;
            background: var(--accent-soft);
            color: #d4ffe0;
            border: 1px solid rgba(73, 209, 125, 0.25);
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 18px;
        }
        .field-group {
            display: flex;
            flex-direction: column;
        }
        .field-group.full-width {
            grid-column: 1 / -1;
        }
        .form-container label,
        .form-container select,
        .form-container textarea {
            display: block;
            width: 100%;
        }
        .form-container label {
            margin-bottom: 9px;
            color: #f4f7fb;
            font-size: 0.95rem;
            font-weight: 700;
            letter-spacing: 0.01em;
        }
        .form-container select,
        .form-container textarea {
            padding: 15px 16px;
            border-radius: 18px;
            border: 1px solid var(--field-border);
            background: var(--field-bg);
            color: #ffffff;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
            box-sizing: border-box;
        }
        .form-container select:focus,
        .form-container textarea:focus {
            border-color: rgba(73, 209, 125, 0.72);
            box-shadow: 0 0 0 4px rgba(73, 209, 125, 0.14);
            transform: translateY(-1px);
        }
        .form-container select option {
            color: #111;
        }
        .form-container textarea {
            min-height: 150px;
            resize: vertical;
        }
        .action-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            flex-wrap: wrap;
        }
        .form-container button {
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-strong) 100%);
            color: white;
            padding: 14px 26px;
            border: none;
            border-radius: 16px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            box-shadow: 0 16px 30px rgba(20, 109, 55, 0.3);
            transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
        }
        .form-container button:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 34px rgba(20, 109, 55, 0.38);
            filter: brightness(1.04);
        }
        .form-container button[disabled] {
            opacity: 0.7;
            cursor: wait;
        }
        .upload-status {
            display: none;
            margin: 0 0 15px;
            padding: 12px 14px;
            border-radius: 10px;
            background: rgba(40, 167, 69, 0.18);
            border: 1px solid rgba(40, 167, 69, 0.5);
            color: #d8ffd8;
            text-align: center;
        }
        .upload-status.active {
            display: block;
        }
        .loading-overlay {
            display: none;
            position: absolute;
            inset: 0;
            border-radius: 20px;
            background: rgba(0, 0, 0, 0.72);
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 14px;
            z-index: 20;
        }
        .loading-overlay.active {
            display: flex;
        }
        .spinner {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            border: 5px solid rgba(255, 255, 255, 0.2);
            border-top-color: #28a745;
            animation: spin 0.8s linear infinite;
        }
        .loading-text {
            text-align: center;
            color: white;
        }
        .loading-text strong {
            display: block;
            font-size: 1.1rem;
            margin-bottom: 6px;
        }
        .message {
            margin: 0 0 18px;
            padding: 14px 16px;
            border-radius: 16px;
            border: 1px solid transparent;
        }
        .message-body {
            display: flex;
            flex-direction: column;
            gap: 12px;
            text-align: left;
        }
        .message-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .message-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 16px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 700;
            letter-spacing: 0.01em;
            transition: transform 0.18s ease, filter 0.18s ease;
        }
        .message-link:hover {
            transform: translateY(-1px);
            filter: brightness(1.03);
        }
        .message-link.primary {
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-strong) 100%);
            color: #ffffff;
        }
        .message-link.secondary {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.14);
            color: #f6f8fb;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
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
        @media (max-width: 900px) {
            .page-shell {
                margin-top: 28px;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
            .form-header,
            .action-row {
                flex-direction: column;
                align-items: flex-start;
            }
            .form-container {
                padding: 22px;
                border-radius: 24px;
            }
        }
    </style>
</head>
<body>
<header>
    <div class="container">
        <div class="admin-brand">
            <img src="../admin/logo.png" alt="Logo"
                 style="height: 60px; width: 60px; margin-right: 15px; border-radius: 50%; object-fit: cover;">
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
    <div class="form-container">
        <div id="loadingOverlay" class="loading-overlay" aria-hidden="true">
            <div class="spinner" aria-hidden="true"></div>
            <div class="loading-text">
                <strong>Uploading chapters...</strong>
                <span>Please wait while the crawler imports pages and checks for newer chapters.</span>
            </div>
        </div>
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $message_type; ?>">
                <div class="message-body">
                    <div><?php echo htmlspecialchars($message); ?></div>
                    <?php if ($manual_upload_note !== ''): ?>
                        <div><?php echo htmlspecialchars($manual_upload_note); ?></div>
                    <?php endif; ?>
                    <?php if ($manual_upload_url !== ''): ?>
                        <div class="message-actions">
                            <a class="message-link primary" href="<?php echo htmlspecialchars($manual_upload_url); ?>">Open Manual Upload</a>
                            <a class="message-link secondary" href="upload_chapter_pages.php">Open Blank Manual Form</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <div id="uploadStatus" class="upload-status" role="status" aria-live="polite">
            Upload in progress. This can take a while if multiple chapters are being fetched.
        </div>
        <form id="crawlerForm" action="upload_chapter_crawler.php" method="POST">
            <div class="form-header">
                <div>
                    <h2>Upload Chapters</h2>
                </div>
                <div class="header-badge">Smart Crawl Enabled</div>
            </div>

            <div class="form-grid">
                <div class="field-group full-width">
                    <label for="series_id">Series</label>
                    <select id="series_id" name="series_id" required>
                        <option value="">-- Select Series --</option>
                        <?php
                        foreach ($seriesList as $series) {
                            $isSelected = $selected_series_id === (string)$series['id'] ? " selected" : "";
                            echo "<option value='" . htmlspecialchars($series['id']) . "'$isSelected>" . htmlspecialchars($series['title']) . "</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="field-group full-width">
                    <label for="chapter_url">Starting Chapter URL</label>
                    <textarea id="chapter_url" name="chapter_url" placeholder="https://example.com/series/chapter-1" rows="5" required><?php echo htmlspecialchars($submitted_chapter_url); ?></textarea>
                </div>
            </div>

            <div class="action-row">
                <button id="submitButton" type="submit">Fetch & Upload</button>
            </div>
        </form>
    </div>
</main>


<footer>
    <div class="container">
        <p>&copy; <?php echo date('Y'); ?> KooPal Admin.</p>
    </div>
</footer>
<script>
    const crawlerForm = document.getElementById('crawlerForm');
    const submitButton = document.getElementById('submitButton');
    const uploadStatus = document.getElementById('uploadStatus');
    const loadingOverlay = document.getElementById('loadingOverlay');

    if (crawlerForm && submitButton && uploadStatus && loadingOverlay) {
        crawlerForm.addEventListener('submit', function () {
            submitButton.disabled = true;
            submitButton.textContent = 'Uploading...';
            uploadStatus.classList.add('active');
            loadingOverlay.classList.add('active');
            loadingOverlay.setAttribute('aria-hidden', 'false');
        });
    }
</script>
</body>
</html>
