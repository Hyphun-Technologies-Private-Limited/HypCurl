<?php

/*
 * Router for `php -S` used by the test suite. Echoes requests back as JSON.
 *   /set-cookie?name=value  sets cookies
 *   /status/{code}          responds with that status
 *   /flaky?key=k&fail=n     503 for the first n calls per key, then 200
 *   /sleep/{seconds}
 *   anything else           echo
 * When used as a proxy, REQUEST_URI is absolute ("http://host/path") -> "proxied": true.
 */

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
header('Content-Type: application/json');

if ($path === '/set-cookie') {
    foreach ($_GET as $name => $value) {
        setcookie($name, $value, ['path' => '/']);
    }
    echo json_encode(['set' => $_GET]);
    return;
}
if (preg_match('#^/status/(\d+)$#', $path, $m)) {
    http_response_code((int) $m[1]);
    echo json_encode(['status' => (int) $m[1]]);
    return;
}
if ($path === '/flaky') {
    $file = sys_get_temp_dir() . '/hypcurl-flaky-' . preg_replace('/\W/', '', $_GET['key'] ?? 'x');
    $count = (int) @file_get_contents($file);
    file_put_contents($file, (string) ($count + 1));
    if ($count < (int) ($_GET['fail'] ?? 0)) {
        http_response_code(503);
    }
    echo json_encode(['attempt' => $count + 1]);
    return;
}
if (preg_match('#^/redirect/(\d+)$#', $path, $m)) {
    $n = (int) $m[1];
    header('Location: ' . ($n > 1 ? '/redirect/' . ($n - 1) : '/echo?redirected=1'), true, 302);
    echo json_encode(['redirecting' => $n]);
    return;
}
if ($path === '/redirect-to-file') {
    header('Location: file:///etc/passwd', true, 302);
    return;
}
if (preg_match('#^/sleep/(\d+)$#', $path, $m)) {
    sleep((int) $m[1]);
    echo json_encode(['slept' => (int) $m[1]]);
    return;
}

echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $path,
    'query' => $_GET,
    'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
    'cookies' => $_COOKIE,
    'body' => file_get_contents('php://input'),
    'post' => $_POST,
    'files' => array_map(fn ($f) => ['name' => $f['name'], 'size' => $f['size']], $_FILES),
    'proxied' => str_starts_with($uri, 'http://'),
]);
