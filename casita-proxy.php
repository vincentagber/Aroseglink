<?php
/**
 * Casita Public API Proxy & Parser
 * Aroseglink Services Limited
 * Handles server-side fetching, CORS bypass, and SSR Next.js JSON extraction
 */

// Suppress PHP notices and warnings from contaminating JSON output
error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (!isset($_GET['endpoint']) || empty(trim($_GET['endpoint']))) {
    echo json_encode(['status' => 'error', 'message' => 'No endpoint provided', 'building' => []]);
    exit;
}

$endpoint = trim($_GET['endpoint'], "/ \t\n\r\0\x0B");

// Security whitelist: only allow safe Casita routes
if (strpos($endpoint, 'api/') !== 0 && strpos($endpoint, 'student-accommodation/') !== 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid endpoint requested', 'building' => []]);
    exit;
}

$targetUrl = 'https://www.casita.com/' . $endpoint;

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $targetUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_ENCODING => '', // Accept gzip / deflate
    CURLOPT_HTTPHEADER => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,application/json;q=0.8,*/*;q=0.7',
        'Accept-Language: en-US,en;q=0.9',
        'Cache-Control: no-cache',
        'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36'
    ]
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

if ($curlError) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Network error connecting to Casita: ' . $curlError,
        'building' => []
    ]);
    exit;
}

if ($httpCode === 404) {
    echo json_encode([
        'status' => 'success',
        'code' => 404,
        'message' => 'Destination not found or no accommodation available in this area.',
        'building' => []
    ]);
    exit;
}

// 1. If Casita returned raw JSON (e.g. /api/countries or /api/cities)
$trimmed = trim($response);
if (substr($trimmed, 0, 1) === '{' || substr($trimmed, 0, 1) === '[') {
    $decoded = json_decode($trimmed, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        // Pass through direct JSON
        echo $trimmed;
        exit;
    }
}

// 2. If Casita returned SSR HTML (from Next.js search pages)
$buildings = [];

// Strategy A: Extract from Next.js __NEXT_DATA__
if (preg_match('/<script id=\"__NEXT_DATA__\"[^>]*>(.*?)<\/script>/s', $response, $matches)) {
    $nextData = json_decode($matches[1], true);
    if (isset($nextData['props']['pageProps']['buildings']['buildings']) && is_array($nextData['props']['pageProps']['buildings']['buildings'])) {
        $buildings = $nextData['props']['pageProps']['buildings']['buildings'];
    } elseif (isset($nextData['props']['pageProps']['buildings']) && is_array($nextData['props']['pageProps']['buildings'])) {
        $buildings = $nextData['props']['pageProps']['buildings'];
    }
}

// Strategy B: Fallback to Schema.org JSON-LD ItemList
if (empty($buildings) && preg_match_all('/<script type=\"application\/ld\+json\"[^>]*>(.*?)<\/script>/s', $response, $allMatches)) {
    foreach ($allMatches[1] as $block) {
        $data = json_decode($block, true);
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            foreach ($data['@graph'] as $item) {
                if (isset($item['@type']) && $item['@type'] === 'ItemList' && isset($item['itemListElement'])) {
                    foreach ($item['itemListElement'] as $elem) {
                        $it = $elem['item'] ?? [];
                        if (!empty($it['name'])) {
                            $facilities = [];
                            if (!empty($it['additionalProperty']) && is_array($it['additionalProperty'])) {
                                foreach ($it['additionalProperty'] as $prop) {
                                    $facilities[] = ['title' => $prop['value'] ?? $prop['name'] ?? ''];
                                }
                            }
                            
                            $curr = ($it['offers']['priceCurrency'] ?? '') === 'GBP' ? '£' : '$';
                            $buildings[] = [
                                'name' => $it['name'] ?? '',
                                'address' => $it['address']['streetAddress'] ?? $it['address']['addressLocality'] ?? '',
                                'min_price' => $it['offers']['lowPrice'] ?? null,
                                'currency_sign' => $curr,
                                'image_path_thumb' => !empty($it['image']) ? [$it['image']] : [],
                                'facilities' => $facilities,
                                'end_url' => $it['url'] ?? '',
                                'distance' => ''
                            ];
                        }
                    }
                    if (!empty($buildings)) {
                        break 2;
                    }
                }
            }
        }
    }
}

// Normalize building attributes for easy consumption by the frontend
$normalized = [];
foreach ($buildings as $b) {
    $imgList = [];
    if (!empty($b['image_path_thumb']) && is_array($b['image_path_thumb'])) {
        $imgList = $b['image_path_thumb'];
    } elseif (!empty($b['image'])) {
        $imgList = [$b['image']];
    }

    $facList = [];
    if (!empty($b['facilities']) && is_array($b['facilities'])) {
        foreach ($b['facilities'] as $f) {
            $title = $f['title'] ?? $f['facilities_name'] ?? '';
            if (!empty($title)) {
                $facList[] = $title;
            }
        }
    }

    $endUrl = $b['end_url'] ?? $b['relative_url'] ?? '';
    if (!empty($endUrl)) {
        if (strpos($endUrl, 'http') !== 0) {
            $endUrl = 'https://www.casita.com' . $endUrl;
        }
        if (strpos($endUrl, 'utm_source=') === false) {
            $endUrl .= (strpos($endUrl, '?') !== false ? '&' : '?') . 'utm_source=aro-7354';
        } else {
            $endUrl = preg_replace('/([?&])utm_source=[^&]*/', '$1utm_source=aro-7354', $endUrl);
        }
    }

    $price = $b['min_price'] ?? null;
    $curr = $b['currency_sign'] ?? '£';

    $normalized[] = [
        'id' => $b['id'] ?? null,
        'name' => $b['name'] ?? 'Student Residence',
        'address' => $b['address'] ?? '',
        'min_price' => $price,
        'currency_sign' => $curr,
        'image' => !empty($imgList[0]) ? $imgList[0] : 'assets/images/banner/04.jpg',
        'facilities' => array_slice($facList, 0, 4),
        'distance' => $b['distance'] ?? '',
        'url' => $endUrl
    ];
}

echo json_encode([
    'status' => 'success',
    'code' => 200,
    'total' => count($normalized),
    'building' => $normalized
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
