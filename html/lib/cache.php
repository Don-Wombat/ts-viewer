<?php
// ─── Datei-Cache ────────────────────────────────────────────────────────────────
// Cache/Lock liegen in einem dedizierten, nicht world-writable Verzeichnis
// (0700, www-data) statt in /tmp - schuetzt vor Symlink-Angriffen.

function ts_read_cache(array $config): ?array {
    $file = $config['cache_file'];
    // is_link()-Check: niemals über einen (potenziell untergeschobenen) Symlink lesen.
    if (!file_exists($file) || is_link($file)) return null;
    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data) || !isset($data['updated'])) return null;
    $ttl = isset($data['error']) ? $config['error_ttl'] : $config['ttl'];
    if ((time() - $data['updated']) >= $ttl) return null;
    return $data;
}

function ts_write_cache(array $config, array $result): void {
    // Atomarer Write: erst in Temp-Datei schreiben, dann rename(). rename()
    // ersetzt einen evtl. vorhandenen Symlink an der Zielposition, statt ihm
    // zu folgen - schützt zusätzlich zu den Directory-Rechten vor Symlink-Angriffen.
    $tmp = $config['cache_file'] . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, json_encode($result));
    rename($tmp, $config['cache_file']);
}

// Liest aus dem Cache oder ruft $fetch() auf, wenn der Cache abgelaufen ist.
// Lock verhindert, dass bei gleichzeitigen Requests nach Cache-Ablauf mehrere
// Verbindungen parallel gegen den TS-Server laufen ("thundering herd").
function ts_get_cached_or_fetch(array $config, callable $fetch): array {
    $cached = ts_read_cache($config);
    if ($cached !== null) return $cached;

    if (!is_dir($config['cache_dir'])) @mkdir($config['cache_dir'], 0700, true);

    $lockFp = @fopen($config['lock_file'], 'c');
    if ($lockFp === false) return ['error' => 'Cache-Verzeichnis nicht beschreibbar.'];

    flock($lockFp, LOCK_EX);
    // Ein anderer Prozess könnte den Cache erneuert haben, während wir auf den Lock warteten.
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
