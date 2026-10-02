<?php
// Soundboard access gate: a signed cookie, no server-side session storage.
// Pattern adapted from a sibling project's own findmy-map login (password
// hash + HMAC-signed cookie + a server-side gate that never trusts the UI) -
// simplified for this app's single-static-password, no-database model.

const TS_SOUNDBOARD_COOKIE  = 'ts_sb_auth';
const TS_SOUNDBOARD_MAX_AGE = 60 * 60 * 24 * 30; // 30 days

// HMAC key derived from the configured password itself, not the password
// value directly - if TS_SOUNDBOARD_PASSWORD is ever changed, every
// previously issued cookie stops verifying immediately (the key changed
// with it), which invalidates all outstanding sessions for free, without a
// separate secret/version file to manage on disk.
function ts_soundboard_key(string $password): string {
    return hash('sha256', 'ts-viewer-soundboard-v1:' . $password, true);
}

function ts_soundboard_make_cookie_value(string $password): string {
    $payload = base64_encode((string)time());
    $sig = base64_encode(hash_hmac('sha256', $payload, ts_soundboard_key($password), true));
    return $payload . '.' . $sig;
}

function ts_soundboard_verify_cookie_value(?string $cookie, string $password): bool {
    if ($cookie === null || $cookie === '') return false;
    $parts = explode('.', $cookie, 2);
    if (count($parts) !== 2) return false;
    [$payload, $sig] = $parts;
    $expectedSig = base64_encode(hash_hmac('sha256', $payload, ts_soundboard_key($password), true));
    // hash_equals(), not ===: a plain string comparison on the signature
    // would leak how many leading bytes matched via response timing.
    if (!hash_equals($expectedSig, $sig)) return false;
    $decoded = base64_decode($payload, true);
    if ($decoded === false) return false;
    $iat = (int)$decoded;
    if ($iat <= 0) return false;
    return (time() - $iat) <= TS_SOUNDBOARD_MAX_AGE;
}

// True only behind a reverse proxy that terminates TLS and forwards the
// original scheme (this app is only ever deployed that way - see README).
// $_SERVER['HTTPS'] alone stays unset for the plain-HTTP hop between the
// proxy and this container, so relying on just that would mean the cookie's
// Secure flag silently never gets set in production.
function ts_is_https_request(): bool {
    if (($_SERVER['HTTPS'] ?? '') !== '') return true;
    return strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function ts_soundboard_set_cookie(string $password): void {
    setcookie(TS_SOUNDBOARD_COOKIE, ts_soundboard_make_cookie_value($password), [
        'expires'  => time() + TS_SOUNDBOARD_MAX_AGE,
        'path'     => '/',
        'secure'   => ts_is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function ts_soundboard_clear_cookie(): void {
    setcookie(TS_SOUNDBOARD_COOKIE, '', ['expires' => 1, 'path' => '/']);
}

// Single choke point used by soundboard.php AND sound.php - both must call
// this before producing any real output (filenames, grid markup, audio
// bytes). An unset password means the feature is off, never "open".
function ts_soundboard_authenticated(array $config): bool {
    if (empty($config['sounds_password'])) return false;
    return ts_soundboard_verify_cookie_value($_COOKIE[TS_SOUNDBOARD_COOKIE] ?? null, $config['sounds_password']);
}

// ─── Login throttle ─────────────────────────────────────────────────────────
// Per-IP attempt limiting for soundboard_login.php - without this, a script
// (no browser, so it never sends Sec-Fetch-Site - see the check in
// soundboard_login.php) could try passwords against the public internet-
// facing login with no delay at all. File-based, same pattern as
// html/lib/ts_online_time.php (JSON + a lock file in the cache dir) rather
// than a database this project doesn't otherwise need.

const TS_LOGIN_THROTTLE_MAX_ATTEMPTS = 5;
const TS_LOGIN_THROTTLE_WINDOW       = 900; // 15 minutes

// Best-effort real client address: this app is only ever reachable through
// the reverse proxy (no published container port - see
// docker-compose.example.yml), which sets X-Forwarded-For; REMOTE_ADDR alone
// would just be the proxy's own address, bucketing every visitor together.
// This is abuse mitigation, not an auth boundary - forging this header at
// worst lets an attacker dodge their own bucket, it can't bypass the
// password check itself.
function ts_client_ip(): string {
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $ip = trim(explode(',', $xff)[0]);
        if ($ip !== '') return $ip;
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function ts_login_throttle_path(string $cacheDir): string {
    return rtrim($cacheDir, '/') . '/login_throttle.json';
}

function ts_login_throttle_read(string $cacheDir): array {
    $path = ts_login_throttle_path($cacheDir);
    if (!is_file($path)) return [];
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function ts_login_throttle_write(string $cacheDir, array $data): void {
    $path = ts_login_throttle_path($cacheDir);
    $json = json_encode($data);
    if ($json === false) return;
    // Atomic write, same reasoning as cache.php/ts_online_time.php: a temp
    // file first, then rename() so a reader never sees a half-written file.
    $tmp = $path . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, $json);
    rename($tmp, $path);
}

// Pure - true if $ip has fewer than the max attempts within the current
// window (or no record / an expired window at all).
function ts_login_throttle_is_allowed(array $data, string $ip, int $now): bool {
    $entry = $data[$ip] ?? null;
    if (!is_array($entry)) return true;
    if ($now - (int)($entry['first'] ?? 0) > TS_LOGIN_THROTTLE_WINDOW) return true;
    return (int)($entry['count'] ?? 0) < TS_LOGIN_THROTTLE_MAX_ATTEMPTS;
}

// Pure - records one failed attempt, starting a fresh window if the
// previous one (if any) already expired.
function ts_login_throttle_apply_failure(array $data, string $ip, int $now): array {
    $entry = $data[$ip] ?? null;
    if (!is_array($entry) || $now - (int)($entry['first'] ?? 0) > TS_LOGIN_THROTTLE_WINDOW) {
        $data[$ip] = ['first' => $now, 'count' => 1];
    } else {
        $data[$ip]['count'] = (int)($entry['count'] ?? 0) + 1;
    }
    return $data;
}

// Pure - a successful login clears this IP's record, so it isn't left
// sitting right at the limit for the rest of the window after getting in.
function ts_login_throttle_clear(array $data, string $ip): array {
    unset($data[$ip]);
    return $data;
}

// I/O wrapper used by soundboard_login.php: locked read-check-update-write
// in one go, so two near-simultaneous requests from the same IP can't both
// read the same pre-increment count and both slip through.
function ts_login_throttle_check_and_record(string $cacheDir, string $ip, bool $succeeded): bool {
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
    $lockFp = @fopen($cacheDir . '/login_throttle.lock', 'c');
    if ($lockFp === false) return true; // fail open - a locking issue here shouldn't block real logins

    flock($lockFp, LOCK_EX);
    $now = time();
    $data = ts_login_throttle_read($cacheDir);
    $allowed = ts_login_throttle_is_allowed($data, $ip, $now);
    if ($allowed) {
        $data = $succeeded ? ts_login_throttle_clear($data, $ip) : ts_login_throttle_apply_failure($data, $ip, $now);
        ts_login_throttle_write($cacheDir, $data);
    }
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    return $allowed;
}
