<?php
require __DIR__ . '/config.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/sounds.php';

$config = ts_load_config();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
// Same reasoning as soundboard.php - this response is gated by the auth
// cookie, never something an intermediary should be allowed to cache.
header('Cache-Control: private, no-store');

// Same choke point as soundboard.php (see lib/auth.php) - this is the ONLY
// place a clip's bytes are ever served from, so this check being airtight is
// what actually keeps the soundboard private, not the page/button around it.
if (empty($config['sounds_dir']) || empty($config['sounds_password']) || !ts_soundboard_authenticated($config)) {
    http_response_code(403);
    exit;
}

$path = isset($_GET['f']) ? ts_sound_resolve($config['sounds_dir'], (string)$_GET['f']) : null;
if ($path === null) {
    http_response_code(404);
    exit;
}

$mime = [
    'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',
    'm4a' => 'audio/mp4',
][strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

$size = filesize($path);
$start = 0;
$end = $size - 1;
$statusCode = 200;

header('Accept-Ranges: bytes');
header('Content-Type: ' . $mime);
// Playback only, never a "Save As" prompt or a download the artifact sandbox
// (or a browser) would treat as a file save - and no filename to leak in a
// header either way.
header('Content-Disposition: inline');

// Range support isn't optional here: some browsers (notably iOS Safari)
// won't play an <audio> source at all from an endpoint that never answers a
// Range request with 206 - a plain 200/full-body response silently fails on
// those, with no console error to point at why.
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m)) {
    $rangeStart = $m[1] === '' ? null : (int)$m[1];
    $rangeEnd   = $m[2] === '' ? null : (int)$m[2];
    if ($rangeStart === null && $rangeEnd !== null) {
        // Suffix form ("bytes=-500"): last N bytes.
        $start = max(0, $size - $rangeEnd);
        $end = $size - 1;
    } elseif ($rangeStart !== null) {
        $start = $rangeStart;
        $end = $rangeEnd !== null ? min($rangeEnd, $size - 1) : $size - 1;
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    $statusCode = 206;
}

http_response_code($statusCode);
if ($statusCode === 206) {
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
header('Content-Length: ' . ($end - $start + 1));

$fh = fopen($path, 'rb');
if ($fh === false) {
    http_response_code(500);
    exit;
}
fseek($fh, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($fh)) {
    $chunk = fread($fh, min(8192, $remaining));
    if ($chunk === false || $chunk === '') break;
    echo $chunk;
    $remaining -= strlen($chunk);
}
fclose($fh);
