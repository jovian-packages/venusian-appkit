<?php

/*
| The fixtures over HTTP, for PHP's built-in server (php -S 127.0.0.1:port serve.php), with
| byte ranges, which the web engine's media player asks for and the built-in server alone does
| not answer.
*/

$file = __DIR__.'/'.basename((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (! is_file($file) || ! in_array(pathinfo($file, PATHINFO_EXTENSION), ['mp4', 'm4a'], true)) {
    http_response_code(404);

    return true;
}

$size = filesize($file);
[$start, $end] = [0, $size - 1];
if (preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'] ?? '', $range)) {
    $start = $range[1] === '' ? max(0, $size - (int) $range[2]) : (int) $range[1];
    $end = $range[1] !== '' && $range[2] !== '' ? min((int) $range[2], $size - 1) : $size - 1;
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$size}");
}

header('Content-Type: '.(str_ends_with($file, '.mp4') ? 'video/mp4' : 'audio/mp4'));
header('Accept-Ranges: bytes');
header('Content-Length: '.($end - $start + 1));
$handle = fopen($file, 'rb');
fseek($handle, $start);
echo fread($handle, $end - $start + 1);
fclose($handle);
