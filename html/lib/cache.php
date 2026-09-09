<?php
// ─── File cache ─────────────────────────────────────────────────────────────────
// Cache/lock live in a dedicated, non-world-writable directory (0700,
// www-data) instead of /tmp - protects against symlink attacks.

function ts_read_cache(array $config): ?array {
    $file = $config['cache_file'];
    // is_link() check: never read through a (potentially planted) symlink.
    if (!file_exists($file) || is_link($file)) return null;
    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data) || !isset($data['updated'])) return null;
    $ttl = isset($data['error']) ? $config['error_ttl'] : $config['ttl'];
    if ((time() - $data['updated']) >= $ttl) return null;
    return $data;
}

function ts_write_cache(array $config, array $result): void {
    // Atomic write: write to a temp file first, then rename(). rename()
    // replaces a symlink that might exist at the target instead of following
    // it - protects against symlink attacks in addition to the directory permissions.
    $tmp = $config['cache_file'] . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, json_encode($result));
    rename($tmp, $config['cache_file']);
}

// Reads from the cache, or calls $fetch() if the cache has expired. The lock
// prevents multiple connections from running against the TS server in
// parallel ("thundering herd") when several requests hit an expired cache at once.
function ts_get_cached_or_fetch(array $config, callable $fetch): array {
    $cached = ts_read_cache($config);
    if ($cached !== null) return $cached;

    if (!is_dir($config['cache_dir'])) @mkdir($config['cache_dir'], 0700, true);

    $lockFp = @fopen($config['lock_file'], 'c');
    if ($lockFp === false) return ['error' => ['key' => 'err_cache_dir']];

    flock($lockFp, LOCK_EX);
    // Another process might have refreshed the cache while we were waiting for the lock.
    $cached = ts_read_cache($config);
    if ($cached !== null) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        return $cached;
    }

    $result = $fetch();
    $result['updated'] = time();
    ts_write_cache($config, $result);

    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    return $result;
}
