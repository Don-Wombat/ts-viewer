<?php
require __DIR__ . '/config.php';
require __DIR__ . '/lib/auth.php';

$config = ts_load_config();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
header('Cache-Control: private, no-store');

if (empty($config['sounds_dir']) || empty($config['sounds_password'])) {
    http_response_code(404);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

// Defense in depth on top of the cookie's own SameSite=Lax (see lib/auth.php) -
// rejects a cross-site form submission outright when a browser sends this
// header; absent (older browsers) it's simply not checked, same as upstream.
$secFetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
if ($secFetchSite !== '' && !in_array($secFetchSite, ['same-origin', 'none'], true)) {
    http_response_code(403);
    exit;
}

// Sec-Fetch-Site only stops browser-originated cross-site submissions - a
// plain script/curl never sends it at all and sails past the check above
// untouched. Per-IP attempt limiting (see lib/auth.php) is what actually
// bounds a scripted password-guessing run against this public endpoint.
$ip = ts_client_ip();
$throttleData = ts_login_throttle_read($config['cache_dir']);
if (!ts_login_throttle_is_allowed($throttleData, $ip, time())) {
    header('Location: index.php?soundboard_error=throttled');
    exit;
}

$submitted = (string)($_POST['password'] ?? '');
// hash_equals(), not ===: constant-time, so a wrong guess can't be narrowed
// down via how quickly the comparison fails.
$ok = $submitted !== '' && hash_equals($config['sounds_password'], $submitted);

// Re-checks "allowed" under the lock (the read above is only for the fast
// common case) and records this attempt's outcome atomically, so two
// near-simultaneous requests from the same IP can't both slip through on a
// stale pre-increment read.
$allowed = ts_login_throttle_check_and_record($config['cache_dir'], $ip, $ok);

if ($allowed && $ok) {
    ts_soundboard_set_cookie($config['sounds_password']);
    header('Location: soundboard.php');
} elseif (!$allowed) {
    header('Location: index.php?soundboard_error=throttled');
} else {
    header('Location: index.php?soundboard_error=1');
}
exit;
