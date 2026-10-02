<?php
// ─── Online-time leaderboard storage ───────────────────────────────────────────
// Read/write for the accumulator that cli/track_online_time.php maintains and
// render.php's leaderboard reads. Split out from both so the merge logic
// (the only part with real behavior to get wrong) is testable without a
// live/mock TS server - see bin/selftest_parser.php.

function ts_online_time_path(string $cacheDir): string {
    return rtrim($cacheDir, '/') . '/online_time.json';
}

function ts_online_time_read(string $cacheDir): array {
    $path = ts_online_time_path($cacheDir);
    if (!is_file($path)) return [];
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function ts_online_time_write(string $cacheDir, array $data): bool {
    $path = ts_online_time_path($cacheDir);
    $json = json_encode($data);
    if ($json === false) {
        error_log('ts-viewer: online-time json_encode() failed: ' . json_last_error_msg());
        return false;
    }
    // Atomic write, same reasoning as cache.php: a temp file first, then
    // rename() so a reader never sees a half-written file.
    $tmp = $path . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, $json);
    rename($tmp, $path);
    return true;
}

// Known duplicate client_database_ids that are actually the same person
// connecting under a different TeamSpeak identity (e.g. mobile vs. desktop
// client) - keyed by the id to fold away, valued by the id to keep. Empty by
// default; add a pair here (find the ids via `clientdbinfo` in ServerQuery,
// or the nickname/seconds already in cache_dir/online_time.json) if you spot
// the same person showing up twice on the leaderboard. Applied on every
// write (self-heals the stored file within one tracker interval, no manual
// data edit needed) and defensively on every read for the leaderboard
// display.
const TS_ONLINE_TIME_ALIASES = [
];

// Folds every aliased-away id's accumulated seconds into its target id and
// removes the now-empty source entry. Pure function, idempotent - safe to
// call on every read/write regardless of whether the data still contains
// the old id. $aliases defaults to the real table above; tests pass a
// synthetic one instead of needing a real seeded duplicate to exercise this.
function ts_online_time_merge_aliases(array $data, array $aliases = TS_ONLINE_TIME_ALIASES): array {
    foreach ($aliases as $from => $to) {
        if (!isset($data[$from]) || !is_array($data[$from])) continue;
        if (!isset($data[$to]) || !is_array($data[$to])) {
            $data[$to] = ['nickname' => '', 'seconds' => 0];
        }
        $data[$to]['seconds'] = (int)($data[$to]['seconds'] ?? 0) + (int)($data[$from]['seconds'] ?? 0);
        if ($data[$to]['nickname'] === '') $data[$to]['nickname'] = $data[$from]['nickname'] ?? '';
        unset($data[$from]);
    }
    return $data;
}

// Adds one interval's worth of seconds to every client in $clients (already
// filtered to real clients, client_type=0), keyed by client_database_id -
// stable across reconnects and nickname changes, already present in a plain
// "clientlist" response (no extra ServerQuery flags needed). Pure function:
// takes the previous accumulator state, returns the new one, no I/O.
//
// This is inherently approximate poll-based tracking: a client connected
// only briefly between two polls is never counted, and one online for just
// a few seconds of an interval is still credited the full interval. That's
// the standard tradeoff for a lightweight tracker with no event stream.
function ts_online_time_apply(array $data, array $clients, int $interval, array $aliases = TS_ONLINE_TIME_ALIASES): array {
    $data = ts_online_time_merge_aliases($data, $aliases);
    foreach ($clients as $c) {
        $cldbid = $c['client_database_id'] ?? null;
        if ($cldbid === null || $cldbid === '') continue;
        $cldbid = (string)($aliases[$cldbid] ?? $cldbid);
        if (!isset($data[$cldbid]) || !is_array($data[$cldbid])) {
            $data[$cldbid] = ['nickname' => '', 'seconds' => 0];
        }
        // Nickname always overwritten with the latest observed one, so a
        // rename doesn't leave the leaderboard showing a stale name.
        $data[$cldbid]['nickname'] = $c['client_nickname'] ?? $data[$cldbid]['nickname'];
        $data[$cldbid]['seconds'] = (int)($data[$cldbid]['seconds'] ?? 0) + $interval;
    }
    return $data;
}

// Sorted (descending) top-N entries for display, each ['nickname' =>, 'seconds' =>].
function ts_online_time_top(array $data, int $limit, array $aliases = TS_ONLINE_TIME_ALIASES): array {
    $data = ts_online_time_merge_aliases($data, $aliases);
    $entries = [];
    foreach ($data as $entry) {
        if (!is_array($entry)) continue;
        $entries[] = ['nickname' => (string)($entry['nickname'] ?? '?'), 'seconds' => (int)($entry['seconds'] ?? 0)];
    }
    usort($entries, fn($a, $b) => $b['seconds'] <=> $a['seconds']);
    return array_slice($entries, 0, $limit);
}
