<?php
/**
 * Lightweight anonymous full-page cache.
 *
 * WordPress loads this file before plugins and the database-heavy request path.
 */

if (PHP_SAPI === 'cli' || empty($_SERVER['REQUEST_METHOD'])) {
    return;
}

$method = strtoupper($_SERVER['REQUEST_METHOD']);
if ($method !== 'GET' && $method !== 'HEAD') {
    return;
}

if (!empty($_SERVER['QUERY_STRING'])) {
    return;
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$excluded = '#/(wp-admin|wp-login\.php|wp-json|xmlrpc\.php|wp-cron\.php)(/|$)|/(feed|comments/feed)/?$#i';
if (!$uri || preg_match($excluded, $uri)) {
    return;
}

$cookie_header = $_SERVER['HTTP_COOKIE'] ?? '';
if (preg_match('/wordpress_logged_in|wp-postpass|comment_author|woocommerce_items_in_cart/i', $cookie_header)) {
    return;
}

$host = strtolower($_SERVER['HTTP_HOST'] ?? 'localhost');
$cache_key = hash('sha256', $host . '|' . $uri);
$cache_directory = __DIR__ . '/cache/ha-page-cache';
$cache_file = $cache_directory . '/' . $cache_key . '.html';
$cache_ttl = 3600;

if (is_file($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
    header('Content-Type: text/html; charset=UTF-8');
    header('X-HA-Cache: HIT');
    header('Cache-Control: public, max-age=300, stale-while-revalidate=60');
    if ($method === 'GET') {
        readfile($cache_file);
    }
    exit;
}

define('HA_PAGE_CACHE_FILE', $cache_file);
