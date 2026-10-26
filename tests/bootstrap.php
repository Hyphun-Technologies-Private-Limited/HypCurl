<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Start a local echo server for HTTP tests (no internet needed).
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);

$process = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/Fixtures/server.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
);
register_shutdown_function(static function () use ($process): void {
    proc_terminate($process);
});

for ($i = 0; $i < 50; $i++) {
    $socket = @fsockopen('127.0.0.1', $port);
    if ($socket) {
        fclose($socket);
        break;
    }
    usleep(100_000);
}

define('HYPCURL_TEST_SERVER', "http://127.0.0.1:$port");
